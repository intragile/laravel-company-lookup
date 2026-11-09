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
 * Danish Business Authority (Erhvervsstyrelsen) CVR "system-to-system" access: the
 * cvr-permanent Elasticsearch index. Free; needs a username and password issued by
 * Erhvervsstyrelsen (cvrselvbetjening@erst.dk).
 *
 * The documented endpoint is plain HTTP; set base_url to an HTTPS proxy if you have one.
 */
final class CvrDriver extends AbstractHttpDriver
{
    public const string KEY = 'cvr';

    private const BASE_URL = 'http://distribution.virk.dk';

    /** Only the parts of the (large) company document the mapping needs. */
    private const SOURCE = ['Vrvirksomhed.cvrNummer', 'Vrvirksomhed.virksomhedMetadata'];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'CVR (Danish Central Business Register)',
            countries: ['DK'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: [
                'username' => 'COMPANY_LOOKUP_CVR_USERNAME',
                'password' => 'COMPANY_LOOKUP_CVR_PASSWORD',
            ],
            registryUrl: 'https://datacvr.virk.dk/',
            sample: '24256790',
            sampleCountry: 'DK',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $cvr = Normalize::digits(Normalize::withoutPrefix('DK', $identifier->value));

        // VAT numbers are left to VIES: CVR does not say whether a company is VAT registered.
        // Newer CVR numbers no longer satisfy the old modulus-11 rule, so only the length is checked.
        if (strtoupper($country) !== 'DK' || $identifier->type !== IdentifierType::Registration || preg_match('/^\d{8}$/', $cvr) !== 1) {
            return LookupResult::notFound(self::KEY);
        }

        if (! $this->isConfigured()) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        return $this->guard(fn (): LookupResult => $this->result($this->query([
            '_source' => self::SOURCE,
            'query' => ['term' => ['Vrvirksomhed.cvrNummer' => (int) $cvr]],
            'size' => 1,
        ])));
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'DK' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        if (! $this->isConfigured()) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        // A match query with "and" needs every word, like the documented query_string example,
        // without exposing the query_string syntax to user input.
        return $this->guard(fn (): LookupResult => $this->result($this->query([
            '_source' => self::SOURCE,
            'query' => ['match' => ['Vrvirksomhed.virksomhedMetadata.nyesteNavn.navn' => ['query' => $name, 'operator' => 'and']]],
            'size' => max(1, min(100, $limit)),
        ])));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function query(array $body): Response
    {
        return $this->request()
            ->acceptJson()
            ->withBasicAuth((string) $this->setting('username'), (string) $this->setting('password'))
            ->post($this->baseUrl(self::BASE_URL).'/cvr-permanent/virksomhed/_search', $body);
    }

    private function result(Response $response): LookupResult
    {
        if (! $response->successful()) {
            return $this->failure($response);
        }

        $companies = [];
        foreach (Data::list($this->json($response), 'hits.hits') as $hit) {
            $company = Data::map($hit, '_source.Vrvirksomhed');
            if (Data::string($company, 'cvrNummer') !== null) {
                $companies[] = $this->company($company);
            }
        }

        return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
    }

    /**
     * @param  array<array-key, mixed>  $company
     */
    private function company(array $company): Company
    {
        $cvr = str_pad((string) Data::string($company, 'cvrNummer'), 8, '0', STR_PAD_LEFT);
        $meta = Data::map($company, 'virksomhedMetadata');

        $activities = [];
        $code = Data::string($meta, 'nyesteHovedbranche.branchekode');
        if ($code !== null) {
            $activities[] = new ActivityCode('DB07', $code, Data::string($meta, 'nyesteHovedbranche.branchetekst'));
        }

        $status = $this->status(Data::string($meta, 'sammensatStatus'));
        $identifiers = [Identifier::registration($cvr, 'DK-CVR')];
        if ($status !== CompanyStatus::Dissolved) {
            // The DK VAT number format is the CVR number; registration itself is not confirmed.
            $identifiers[] = Identifier::vat('DK'.$cvr);
        }

        return new Company(
            name: Data::string($meta, 'nyesteNavn.navn'),
            country: 'DK',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($meta, 'nyesteBeliggenhedsadresse')),
            status: $status,
            legalForm: Data::string($meta, 'nyesteVirksomhedsform.langBeskrivelse') ?? Data::string($meta, 'nyesteVirksomhedsform.kortBeskrivelse'),
            legalFormCode: Data::string($meta, 'nyesteVirksomhedsform.virksomhedsformkode'),
            activities: $activities,
            foundedAt: Data::date(Data::string($meta, 'stiftelsesDato')),
            raw: $company,
        );
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $freeText = Data::string($address, 'fritekst');
        $number = Data::string($address, 'husnummerFra');
        if ($number !== null) {
            $number .= Data::string($address, 'bogstavFra') ?? '';
            $to = Data::string($address, 'husnummerTil');
            $number .= $to !== null ? '-'.$to.(Data::string($address, 'bogstavTil') ?? '') : '';
        }

        $floor = implode(' ', array_filter([Data::string($address, 'etage'), Data::string($address, 'sidedoer')]));
        $street = trim(implode(' ', array_filter([Data::string($address, 'vejnavn'), $number])).($floor !== '' ? ', '.$floor : ''), ', ');
        $postbox = Data::string($address, 'postboks');
        if ($street === '' && $postbox !== null) {
            $street = 'Postboks '.$postbox;
        }

        $postcode = Data::string($address, 'postnummer');
        $city = Data::string($address, 'postdistrikt') ?? Data::string($address, 'bynavn');

        if ($street === '' && $postcode === null && $city === null) {
            return $freeText === null ? null : new Address(country: 'DK', freeText: $freeText);
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: $postcode,
            city: $city,
            country: Data::string($address, 'landekode') ?? 'DK',
        );
    }

    private function status(?string $status): CompanyStatus
    {
        $status = mb_strtolower($status ?? '');

        return match (true) {
            $status === '' => CompanyStatus::Unknown,
            in_array($status, ['normal', 'aktiv'], true) => CompanyStatus::Active,
            str_starts_with($status, 'under ') => CompanyStatus::Inactive,
            str_contains($status, 'opløst'), str_contains($status, 'ophørt'), str_contains($status, 'slettet') => CompanyStatus::Dissolved,
            default => CompanyStatus::Inactive,
        };
    }
}
