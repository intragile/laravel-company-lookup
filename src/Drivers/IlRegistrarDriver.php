<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\Response;
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
 * Israeli Companies Registrar, from the companies dataset on data.gov.il (CKAN datastore API). Free, no key.
 *
 * Field names are Hebrew. The name is the English name when the registry has one, otherwise the Hebrew name.
 * The registry writes the Hebrew quotation mark as "~" (בע~מ); names are returned with a regular double quote.
 */
final class IlRegistrarDriver extends AbstractHttpDriver
{
    public const string KEY = 'il-registrar';

    /** The companies resource, override with the resource_id setting. */
    public const string RESOURCE_ID = 'f004176c-b85f-4542-8901-7b3176f9a054';

    private const F_NUMBER = 'מספר חברה';

    private const F_NAME = 'שם חברה';

    private const F_NAME_EN = 'שם באנגלית';

    private const F_TYPE = 'סוג תאגיד';

    private const F_TYPE_CODE = 'קוד סוג חברה';

    private const F_STATUS = 'סטטוס חברה';

    private const F_FOUNDED = 'תאריך התאגדות';

    private const F_CITY = 'שם עיר';

    private const F_STREET = 'שם רחוב';

    private const F_HOUSE = 'מספר בית';

    private const F_POSTCODE = 'מיקוד';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Israel Companies Registrar (data.gov.il)',
            countries: ['IL'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Data from the Israeli Corporations Authority, published on data.gov.il.',
            registryUrl: 'https://ica.justice.gov.il/GenericCorporarionInfo/SearchCorporation',
            sample: '520013954',
            sampleCountry: 'IL',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = Normalize::digits(Normalize::withoutPrefix('IL', $identifier->value));

        if (strtoupper($country) !== 'IL' || strlen($number) !== 9) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($number): LookupResult {
            $records = $this->records(['filters' => $this->encode([self::F_NUMBER => (int) $number]), 'limit' => 5]);
            if ($records instanceof LookupResult) {
                return $records;
            }

            foreach ($records as $record) {
                if (Data::string($record, self::F_NUMBER) === $number) {
                    return LookupResult::found(self::KEY, $this->company($record));
                }
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'IL' || $name === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        // Hebrew input searches the Hebrew name, anything else the English name.
        $field = preg_match('/\p{Hebrew}/u', $name) === 1 ? self::F_NAME : self::F_NAME_EN;

        return $this->guard(function () use ($field, $name, $limit): LookupResult {
            $records = $this->records(['q' => $this->encode([$field => $name]), 'limit' => $limit]);
            if ($records instanceof LookupResult) {
                return $records;
            }

            $companies = array_map(fn (array $record): Company => $this->company($record), $records);

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * Runs a datastore_search query and returns its records, or a failed result.
     *
     * @param  array<string, string|int>  $query
     * @return list<array<array-key, mixed>>|LookupResult
     */
    private function records(array $query): array|LookupResult
    {
        $response = $this->request()
            ->acceptJson()
            ->get($this->baseUrl('https://data.gov.il/api/3/action').'/datastore_search', [
                'resource_id' => $this->setting('resource_id', self::RESOURCE_ID),
                ...$query,
            ]);

        if (! $response->successful()) {
            return $this->failure($response, $this->errorMessage($response));
        }

        // The portal answers with an empty body when it throttles a client.
        if (trim($response->body()) === '') {
            return LookupResult::failed(self::KEY, FailureReason::Unavailable, true, 'Empty response from data.gov.il.');
        }

        $data = $this->json($response);
        if (Data::bool($data, 'success') !== true) {
            return LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, $this->errorMessage($response));
        }

        return Data::list($data, 'result.records');
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function company(array $record): Company
    {
        $hebrewName = $this->unquote(Data::string($record, self::F_NAME));
        $typeCode = Data::string($record, self::F_TYPE_CODE);

        return new Company(
            name: Data::string($record, self::F_NAME_EN) ?? $hebrewName,
            country: 'IL',
            source: self::KEY,
            identifiers: [Identifier::registration((string) Data::string($record, self::F_NUMBER), 'IL-COMPANY')],
            address: $this->address($record),
            status: $this->status(Data::string($record, self::F_STATUS)),
            legalForm: Data::string($record, self::F_TYPE),
            legalFormCode: $typeCode,
            foundedAt: Data::date(Data::string($record, self::F_FOUNDED), ['d/m/Y']),
            raw: $record,
        );
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function address(array $record): ?Address
    {
        $street = trim(implode(' ', array_filter([Data::string($record, self::F_STREET), Data::string($record, self::F_HOUSE)])));
        $city = Data::string($record, self::F_CITY);
        $postalCode = Data::string($record, self::F_POSTCODE);

        if ($street === '' && $city === null && $postalCode === null) {
            return null;
        }

        return new Address(street: $street === '' ? null : $street, postalCode: $postalCode, city: $city, country: 'IL');
    }

    /**
     * Maps the Hebrew status text. Companies in receivership or arrangement proceedings are still active.
     */
    private function status(?string $description): CompanyStatus
    {
        $description = $this->unquote($description) ?? '';

        return match (true) {
            str_starts_with($description, 'פעילה') => CompanyStatus::Active,
            str_contains($description, 'פרוק') || str_contains($description, 'פירוק') => CompanyStatus::Inactive,
            str_contains($description, 'מחוקה')
                || str_contains($description, 'מחוסלת')
                || str_contains($description, 'חיסול')
                || str_contains($description, 'נגרעה') => CompanyStatus::Dissolved,
            default => CompanyStatus::Unknown,
        };
    }

    private function unquote(?string $value): ?string
    {
        return $value === null ? null : str_replace('~', '"', $value);
    }

    /**
     * @param  array<string, string|int>  $value
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function errorMessage(Response $response): string
    {
        $error = Data::get(Data::json($response->body()), 'error');

        return 'HTTP '.$response->status().(is_array($error) ? ' '.json_encode($error, JSON_UNESCAPED_UNICODE) : '');
    }
}
