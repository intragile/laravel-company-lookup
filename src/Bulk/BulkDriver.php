<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Bulk\Datasets\Dataset;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Contracts\BulkDataset;
use IntrAgile\CompanyLookup\Contracts\Driver;
use IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use RuntimeException;
use Throwable;

/**
 * Answers lookups and name searches from the local copy of a bulk dataset.
 *
 * Until `company-lookup:import <key>` has filled the table, every call reports "unconfigured".
 */
final class BulkDriver implements Driver
{
    /**
     * @param  array<string, mixed>  $config  The company-lookup config array (uses bulk.enabled, bulk.connection, bulk.table and drivers.<key>).
     */
    public function __construct(
        private readonly BulkDataset $dataset,
        private readonly ConnectionResolverInterface $db,
        private readonly array $config = [],
    ) {}

    public function dataset(): BulkDataset
    {
        return $this->dataset;
    }

    public function capabilities(): Capabilities
    {
        $dataset = $this->dataset;
        $described = $dataset instanceof Dataset ? $dataset : null;
        $credentials = $dataset->credentials();

        if (! $this->bulkEnabled()) {
            $credentials = ['bulk.enabled' => 'COMPANY_LOOKUP_BULK_ENABLED'] + $credentials;
        }

        return new Capabilities(
            key: $dataset->key(),
            label: $described?->label() ?? strtoupper($dataset->key()),
            countries: [strtoupper($dataset->country())],
            identifierTypes: $described?->identifierTypes() ?? [IdentifierType::Registration],
            nameSearch: true,
            credentials: $credentials,
            attribution: $described?->attribution(),
            registryUrl: $described?->registryUrl(),
            sample: $described?->sample(),
        );
    }

    public function isConfigured(): bool
    {
        if (! $this->bulkEnabled()) {
            return false;
        }

        foreach (array_keys($this->dataset->credentials()) as $field) {
            $value = data_get($this->config, 'drivers.'.$this->dataset->key().'.'.$field);
            if (! is_scalar($value) || trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        if (strtoupper($country) !== strtoupper($this->dataset->country())) {
            return LookupResult::notFound($this->dataset->key());
        }

        $value = $this->dataset instanceof Dataset
            ? $this->dataset->normalizeIdentifier($identifier->value)
            : Normalize::digits($identifier->value);

        if ($value === '') {
            return LookupResult::notFound($this->dataset->key());
        }

        return $this->guard(function () use ($value): LookupResult {
            $row = $this->query()->where('identifier', $value)->first();

            return $row === null ? $this->empty() : LookupResult::found($this->dataset->key(), $this->company($row));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $term = Normalize::name($name);

        if ($term === '' || strtoupper($country) !== strtoupper($this->dataset->country())) {
            return LookupResult::notFound($this->dataset->key());
        }

        return $this->guard(function () use ($term, $limit): LookupResult {
            // Normalized names hold only [a-z0-9 ], so the term needs no LIKE escaping.
            $rows = $this->query()
                ->where('normalized_name', 'like', '%'.$term.'%')
                ->orderByRaw('case when normalized_name = ? then 0 when normalized_name like ? then 1 else 2 end', [$term, $term.'%'])
                ->orderBy('name')
                ->limit(max(1, $limit))
                ->get();

            if ($rows->isEmpty()) {
                return $this->empty();
            }

            return LookupResult::found($this->dataset->key(), ...$rows->map(fn (object $row): Company => $this->company($row))->values()->all());
        });
    }

    /**
     * Not found, or unconfigured when nothing has been imported for this dataset yet.
     */
    private function empty(): LookupResult
    {
        return $this->query()->exists()
            ? LookupResult::notFound($this->dataset->key())
            : $this->notImported();
    }

    private function notImported(): LookupResult
    {
        return LookupResult::unconfigured($this->dataset->key(), 'php artisan company-lookup:import '.$this->dataset->key());
    }

    /**
     * @param  callable(): LookupResult  $call
     */
    private function guard(callable $call): LookupResult
    {
        try {
            if (! $this->connection()->getSchemaBuilder()->hasTable($this->table())) {
                return $this->notImported();
            }

            return $call();
        } catch (Throwable $exception) {
            return LookupResult::failed($this->dataset->key(), FailureReason::Unavailable, true, AbstractHttpDriver::redact($exception->getMessage()));
        }
    }

    private function company(object $row): Company
    {
        $data = (array) $row;
        $payload = Data::json(Data::string($data, 'payload') ?? '');
        $key = $this->dataset->key();
        $identifier = Data::string($data, 'identifier') ?? '';
        $vat = Data::string($payload, 'vat');

        $street = Data::string($payload, 'street');
        $postalCode = Data::string($payload, 'postal_code');
        $city = Data::string($payload, 'city');
        $line = Data::string($data, 'address');

        $address = match (true) {
            $street !== null || $postalCode !== null || $city !== null => new Address($street, $postalCode, $city),
            $line !== null => new Address(freeText: $line),
            default => null,
        };

        return new Company(
            name: Data::string($data, 'name'),
            country: strtoupper($this->dataset->country()),
            source: $key,
            identifiers: $vat === null
                ? [Identifier::registration($identifier, strtoupper($key))]
                : [Identifier::registration($identifier, strtoupper($key)), Identifier::vat($vat)],
            address: $address,
            status: CompanyStatus::tryFrom(Data::string($data, 'status') ?? '') ?? CompanyStatus::Unknown,
            legalForm: Data::string($data, 'legal_form'),
            legalFormCode: Data::string($payload, 'legal_form_code'),
            foundedAt: Data::date(Data::string($data, 'founded_at')),
            raw: $payload,
        );
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->table())->where('dataset', $this->dataset->key());
    }

    private function connection(): Connection
    {
        $name = data_get($this->config, 'bulk.connection');
        $connection = $this->db->connection(is_string($name) && $name !== '' ? $name : null);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('The bulk connection must be an Illuminate database connection.');
        }

        return $connection;
    }

    private function table(): string
    {
        $table = data_get($this->config, 'bulk.table');

        return is_string($table) && $table !== '' ? $table : 'company_lookup_records';
    }

    private function bulkEnabled(): bool
    {
        return filter_var(data_get($this->config, 'bulk.enabled', false), FILTER_VALIDATE_BOOL);
    }
}
