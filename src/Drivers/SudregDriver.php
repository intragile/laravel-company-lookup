<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * Croatian court register (Sudski registar) open data API, public part ("javni").
 * Free; needs OAuth2 client credentials issued on sudreg-data.gov.hr.
 *
 * The API filters subjects by name but returns no names in that list, so this driver
 * offers identifier lookups only; the register is also tightly rate limited.
 */
final class SudregDriver extends AbstractHttpDriver
{
    public const string KEY = 'sudreg';

    private const BASE_URL = 'https://sudreg-data.gov.hr/api/javni';

    private const TOKEN_URL = 'https://sudreg-data.gov.hr/api/oauth/token';

    /** Error code of a request that matched no rows. */
    private const NO_ROWS = 505;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Sudski registar (HR court register)',
            countries: ['HR'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Tax],
            nameSearch: false,
            credentials: [
                'client_id' => 'COMPANY_LOOKUP_SUDREG_CLIENT_ID',
                'client_secret' => 'COMPANY_LOOKUP_SUDREG_CLIENT_SECRET',
            ],
            registryUrl: 'https://sudreg.pravosudje.hr/',
            sample: '080266256',
            sampleCountry: 'HR',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $query = strtoupper($country) === 'HR' ? $this->query($identifier) : null;

        if ($query === null) {
            return LookupResult::notFound(self::KEY);
        }

        $clientId = $this->setting('client_id');
        $clientSecret = $this->setting('client_secret');
        if ($clientId === null || $clientSecret === null) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        return $this->guard(function () use ($query, $clientId, $clientSecret): LookupResult {
            $response = $this->details($query, $clientId, $clientSecret);

            // A token revoked before its expiry: fetch a new one and try once more.
            if ($response->status() === 401) {
                $this->forgetClientCredentialsToken(self::TOKEN_URL, $clientId);
                $response = $this->details($query, $clientId, $clientSecret);
            }

            $body = Data::json($response->body());
            if ($response->status() === 400 && $this->errorCode($body) === self::NO_ROWS) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, $this->errorMessage($body));
            }

            $data = $this->json($response);
            if (Data::string($data, 'mbs') === null && Data::string($data, 'potpuni_mbs') === null) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $this->company($data));
        });
    }

    /**
     * The register allows only a few detail requests per minute; retrying a 429 just burns the quota.
     */
    protected function retriesOnRateLimit(): bool
    {
        return false;
    }

    /**
     * @param  array{tip_identifikatora: string, identifikator: string}  $query
     */
    private function details(array $query, string $clientId, string $clientSecret): Response
    {
        $token = $this->clientCredentialsToken(self::TOKEN_URL, $clientId, $clientSecret);

        return $this->request()
            ->withToken($token)
            ->acceptJson()
            ->get($this->baseUrl(self::BASE_URL).'/detalji_subjekta', $query + [
                'expand_relations' => 'true',
                'no_data_error' => '0',
            ]);
    }

    /**
     * OIB check digit: ISO 7064, MOD 11,10.
     */
    public static function validOib(string $oib): bool
    {
        if (preg_match('/^\d{11}$/', $oib) !== 1) {
            return false;
        }

        $carry = 10;
        for ($i = 0; $i < 10; $i++) {
            $carry = ($carry + (int) $oib[$i]) % 10;
            $carry = (($carry === 0 ? 10 : $carry) * 2) % 11;
        }

        return (11 - $carry) % 10 === (int) $oib[10];
    }

    /**
     * @return array{tip_identifikatora: string, identifikator: string}|null
     */
    private function query(Identifier $identifier): ?array
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('HR', $identifier->value));

        return match ($identifier->type) {
            IdentifierType::Tax => self::validOib($digits) ? ['tip_identifikatora' => 'oib', 'identifikator' => $digits] : null,
            IdentifierType::Registration => match (true) {
                strlen($digits) === 11 && self::validOib($digits) => ['tip_identifikatora' => 'oib', 'identifikator' => $digits],
                strlen($digits) >= 6 && strlen($digits) <= 9 => ['tip_identifikatora' => 'mbs', 'identifikator' => str_pad($digits, 9, '0', STR_PAD_LEFT)],
                default => null,
            },
            default => null,
        };
    }

    /**
     * Error responses are a list with one {error_code, error_message, log_id} entry.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function errorCode(array $body): ?int
    {
        $code = Data::get($body, '0.error_code') ?? Data::get($body, 'error_code');

        return is_numeric($code) ? (int) $code : null;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function errorMessage(array $body): ?string
    {
        return Data::string($body, '0.error_message') ?? Data::string($body, 'error_message');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data): Company
    {
        $mbs = Data::string($data, 'potpuni_mbs') ?? str_pad((string) Data::string($data, 'mbs'), 9, '0', STR_PAD_LEFT);
        $identifiers = [Identifier::registration($mbs, 'HR-MBS')];

        $oib = Data::string($data, 'potpuni_oib') ?? $this->paddedOib(Data::string($data, 'oib'));
        if ($oib !== null) {
            $identifiers[] = Identifier::tax($oib, 'HR-OIB');
        }

        $activities = [];
        $activity = Data::map($data, 'pretezita_djelatnost.nacionalna_klasifikacija_djelatnosti');
        $code = Data::string($activity, 'sifra');
        if ($code !== null) {
            $activities[] = new ActivityCode('HR-NKD', $code, Data::string($activity, 'puni_naziv'));
        }

        $legalForm = Data::map($data, 'pravni_oblik.vrsta_pravnog_oblika');

        return new Company(
            name: Data::string($data, 'tvrtka.ime') ?? Data::string($data, 'tvrtka_kod_brisanja'),
            country: 'HR',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($data, 'sjediste')),
            status: $this->status($data),
            legalForm: Data::string($legalForm, 'naziv'),
            legalFormCode: Data::string($legalForm, 'kratica'),
            activities: $activities,
            foundedAt: Data::date(Data::string($data, 'datum_osnivanja')),
            raw: $data,
        );
    }

    private function paddedOib(?string $oib): ?string
    {
        return $oib === null ? null : str_pad($oib, 11, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<array-key, mixed>  $seat
     */
    private function address(array $seat): ?Address
    {
        $number = trim((Data::string($seat, 'kucni_broj') ?? '').(Data::string($seat, 'kucni_podbroj') ?? ''));
        $street = trim((Data::string($seat, 'ulica') ?? '').' '.$number);
        $city = Data::string($seat, 'naziv_naselja') ?? Data::string($seat, 'naselje_van_sifrarnika');
        $postcode = Data::string($seat, 'postanski_broj');

        if ($street === '' && $city === null && $postcode === null) {
            return null;
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: $postcode,
            city: $city,
            region: Data::string($seat, 'naziv_zupanije'),
            country: Data::string($seat, 'drzava.oznaka_2') ?? 'HR',
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function status(array $data): CompanyStatus
    {
        if (Data::string($data, 'datum_brisanja') !== null) {
            return CompanyStatus::Dissolved;
        }

        $proceeding = Data::string($data, 'postupak.postupak.znacenje') ?? '';

        return preg_match('/ste[cč]aj|likvidac/iu', $proceeding) === 1 ? CompanyStatus::Inactive : CompanyStatus::Active;
    }
}
