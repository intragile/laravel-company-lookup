<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk\Datasets;

use Generator;
use IntrAgile\CompanyLookup\Bulk\Csv;
use IntrAgile\CompanyLookup\Bulk\Record;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * Latvian Register of Enterprises (Uzņēmumu reģistrs) open data: one semicolon-separated UTF-8 CSV, CC0.
 *
 * Columns: regcode;sepa;name;name_before_quotes;name_in_quotes;name_after_quotes;without_quotes;regtype;
 * regtype_text;type;type_text;registered;terminated;closed;address;index;addressid;region;city;atvk;
 * reregistration_term. "closed" is L (liquidated) or R (reorganised) for removed entities, blank otherwise.
 */
final class LatviaUr extends Dataset
{
    public const string KEY = 'lv-ur';

    /** Published at data.gov.lv, dataset "uz" (CKAN package_show?id=uz). */
    public const string URL = 'https://data.gov.lv/dati/dataset/4de9697f-850b-45ec-8bba-61fa09ce932f/resource/25e80bf3-f107-4ab4-89ef-251b5b9374e9/download/register.csv';

    public function key(): string
    {
        return self::KEY;
    }

    public function country(): string
    {
        return 'LV';
    }

    public function sourceUrl(): string
    {
        return $this->setting('url') ?? self::URL;
    }

    public function label(): string
    {
        return 'Latvia Register of Enterprises (open data)';
    }

    public function registryUrl(): string
    {
        return 'https://www.ur.gov.lv/en/';
    }

    public function sample(): string
    {
        return '40003032949';
    }

    public function identifierTypes(): array
    {
        return [IdentifierType::Registration, IdentifierType::Vat];
    }

    public function rows(string $localFile): Generator
    {
        foreach (Csv::read($localFile, ';') as $row) {
            $identifier = trim($row['regcode'] ?? '');
            $name = trim($row['name'] ?? '');
            if ($identifier === '' || $name === '') {
                continue;
            }

            $terminated = Data::clean($row['terminated'] ?? null);
            $closed = Data::clean($row['closed'] ?? null);
            $street = Data::clean($row['address'] ?? null);
            $index = Data::clean($row['index'] ?? null);
            $postalCode = $index === null ? null : 'LV-'.$index;

            yield Record::make(
                identifier: $identifier,
                name: $name,
                address: Record::join($street, $postalCode),
                status: $terminated !== null || $closed !== null ? CompanyStatus::Dissolved : CompanyStatus::Active,
                legalForm: Data::clean($row['type_text'] ?? null),
                foundedAt: Data::date(Data::clean($row['registered'] ?? null)),
                payload: [
                    'legal_form_code' => Data::clean($row['type'] ?? null),
                    'street' => $street,
                    'postal_code' => $postalCode,
                    'register' => Data::clean($row['regtype_text'] ?? null),
                    'sepa' => Data::clean($row['sepa'] ?? null),
                    'terminated' => $terminated,
                    'closed' => $closed,
                    'address_id' => Data::clean($row['addressid'] ?? null),
                ],
            );
        }
    }
}
