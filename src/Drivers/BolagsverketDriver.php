<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * Bolagsverket "Värdefulla datamängder" (EU high-value datasets) API. Free; needs OAuth2
 * client credentials issued by Bolagsverket. Looks up by organisation number only:
 * the API has no name search.
 */
final class BolagsverketDriver extends AbstractHttpDriver
{
    public const string KEY = 'bolagsverket';

    private const BASE_URL = 'https://gw.api.bolagsverket.se/vardefulla-datamangder/v1';

    private const TOKEN_URL = 'https://portal.api.bolagsverket.se/oauth2/token';

    private const SCOPE = 'vardefulla-datamangder:read';

    /** Error type of a data producer that could not be reached. */
    private const SOURCE_UNAVAILABLE = 'OTILLGANGLIG_UPPGIFTSKALLA';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Bolagsverket (high-value datasets)',
            countries: ['SE'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: false,
            credentials: [
                'client_id' => 'COMPANY_LOOKUP_BOLAGSVERKET_CLIENT_ID',
                'client_secret' => 'COMPANY_LOOKUP_BOLAGSVERKET_CLIENT_SECRET',
            ],
            registryUrl: 'https://www.bolagsverket.se/',
            sample: '5560125790',
            sampleCountry: 'SE',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = strtoupper($country) === 'SE' ? $this->number($identifier) : null;

        if ($number === null) {
            return LookupResult::notFound(self::KEY);
        }

        $clientId = $this->setting('client_id');
        $clientSecret = $this->setting('client_secret');
        if ($clientId === null || $clientSecret === null) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        return $this->guard(function () use ($number, $clientId, $clientSecret): LookupResult {
            $response = $this->organisations($number, $clientId, $clientSecret);

            // A token revoked before its expiry: fetch a new one and try once more.
            if ($response->status() === 401) {
                $this->forgetClientCredentialsToken(self::TOKEN_URL, $clientId, self::SCOPE);
                $response = $this->organisations($number, $clientId, $clientSecret);
            }

            // Only the API's own not-found error means "no such organisation"; any other 404
            // (such as a wrong base URL) is a failure and must not be cached.
            if ($response->status() === 404 && Data::string(Data::json($response->body()), 'instance') === 'not.found') {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::string(Data::json($response->body()), 'detail'));
            }

            $organisations = Data::list($this->json($response), 'organisationer');
            if ($organisations === []) {
                return LookupResult::notFound(self::KEY);
            }

            $unavailable = Data::string($organisations[0], 'organisationsnamn.fel.typ') === self::SOURCE_UNAVAILABLE
                && Data::list($organisations[0], 'organisationsnamn.organisationsnamnLista') === [];
            if ($unavailable) {
                return LookupResult::failed(self::KEY, FailureReason::Unavailable, true, Data::string($organisations[0], 'organisationsnamn.fel.felBeskrivning'));
            }

            return LookupResult::found(self::KEY, ...array_map(fn (array $organisation): Company => $this->company($organisation, $number), $organisations));
        });
    }

    private function organisations(string $number, string $clientId, string $clientSecret): Response
    {
        // Bolagsverket expects the client credentials and the scope as form fields.
        $token = $this->clientCredentialsToken(self::TOKEN_URL, $clientId, $clientSecret, self::SCOPE, basicAuth: false);

        return $this->request()
            ->withToken($token)
            ->acceptJson()
            ->post($this->baseUrl(self::BASE_URL).'/organisationer', ['identitetsbeteckning' => $number]);
    }

    /**
     * Luhn (mod 10) check over the 10-digit organisation number.
     */
    public static function validOrganisationNumber(string $number): bool
    {
        if (preg_match('/^\d{10}$/', $number) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (str_split(strrev($number)) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }

    /**
     * A 10-digit organisation number, or a 12-digit personal identity number of a sole trader.
     * VAT numbers are not accepted: the register does not say whether one is VAT registered.
     */
    private function number(Identifier $identifier): ?string
    {
        $digits = Normalize::digits(Normalize::withoutPrefix('SE', $identifier->value));

        if ($identifier->type !== IdentifierType::Registration) {
            return null;
        }

        return match (true) {
            strlen($digits) === 10 && self::validOrganisationNumber($digits) => $digits,
            strlen($digits) === 12 && in_array(substr($digits, 0, 2), ['16', '18', '19', '20'], true) && self::validOrganisationNumber(substr($digits, 2)) => $digits,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $organisation
     */
    private function company(array $organisation, string $requested): Company
    {
        $number = Data::string($organisation, 'organisationsidentitet.identitetsbeteckning') ?? $requested;
        $identifiers = [Identifier::registration($number, 'SE-ORGNR')];
        // The SE VAT number format is derived from the organisation number; it is only listed for
        // registered organisations, and VAT registration itself is not confirmed (vatValid stays null).
        $deregistered = Data::get($organisation, 'avregistreradOrganisation.avregistreringsdatum') !== null;
        if (strlen($number) === 10 && ! $deregistered) {
            $identifiers[] = Identifier::vat('SE'.$number.'01');
        }

        $activities = [];
        foreach (Data::list($organisation, 'naringsgrenOrganisation.sni') as $sni) {
            $code = Data::string($sni, 'kod');
            if ($code !== null) {
                $activities[] = new ActivityCode('SE-SNI2007', $code, Data::string($sni, 'klartext'));
            }
        }

        return new Company(
            name: $this->name(Data::list($organisation, 'organisationsnamn.organisationsnamnLista')),
            country: 'SE',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($organisation, 'postadressOrganisation.postadress')),
            status: $this->status($organisation),
            legalForm: Data::string($organisation, 'organisationsform.klartext'),
            legalFormCode: Data::string($organisation, 'organisationsform.kod'),
            activities: $activities,
            foundedAt: $this->date(Data::get($organisation, 'organisationsdatum.registreringsdatum')),
            raw: $organisation,
        );
    }

    /**
     * The registered business name ("företagsnamn"), else the first listed name.
     *
     * @param  list<array<array-key, mixed>>  $names
     */
    private function name(array $names): ?string
    {
        foreach ($names as $name) {
            if (Data::string($name, 'organisationsnamntyp.kod') === 'FORETAGSNAMN') {
                return Data::string($name, 'namn');
            }
        }

        return isset($names[0]) ? Data::string($names[0], 'namn') : null;
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $street = Data::string($address, 'utdelningsadress');
        $postcode = Data::string($address, 'postnummer');
        $city = Data::string($address, 'postort');

        if ($street === null && $postcode === null && $city === null) {
            return null;
        }

        return new Address(street: $street, postalCode: $postcode, city: $city, country: 'SE');
    }

    /**
     * @param  array<array-key, mixed>  $organisation
     */
    private function status(array $organisation): CompanyStatus
    {
        if (Data::get($organisation, 'avregistreradOrganisation.avregistreringsdatum') !== null) {
            return CompanyStatus::Dissolved;
        }

        if (Data::list($organisation, 'pagaendeAvvecklingsEllerOmstruktureringsforfarande.pagaendeAvvecklingsEllerOmstruktureringsforfarandeLista') !== []) {
            return CompanyStatus::Inactive;
        }

        return Data::string($organisation, 'organisationsnamn.fel.typ') === null ? CompanyStatus::Active : CompanyStatus::Unknown;
    }

    /**
     * Dates are epoch milliseconds (UTC midnight).
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_int($value) && ! is_float($value)) {
            return Data::date(Data::clean($value));
        }

        $date = DateTimeImmutable::createFromFormat('U', (string) intdiv((int) $value, 1000), new DateTimeZone('UTC'));

        return $date === false ? null : Data::date($date->format('Y-m-d'));
    }
}
