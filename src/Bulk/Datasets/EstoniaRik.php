<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk\Datasets;

use Generator;
use IntrAgile\CompanyLookup\Bulk\Csv;
use IntrAgile\CompanyLookup\Bulk\Record;
use IntrAgile\CompanyLookup\Bulk\SelectsArchiveMembers;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;

/**
 * Estonian e-Business Register (RIK) open data, "basic data" file: a zip with one semicolon-separated
 * UTF-8 CSV (with BOM, CRLF). Updated daily. Deleted entities are not part of the file.
 *
 * Status codes: R = registered, L = in liquidation, N = bankrupt, K = deleted.
 */
final class EstoniaRik extends Dataset implements SelectsArchiveMembers
{
    public const string KEY = 'ee-rik';

    public const string URL = 'https://avaandmed.ariregister.rik.ee/sites/default/files/avaandmed/ettevotja_rekvisiidid__lihtandmed.csv.zip';

    public function key(): string
    {
        return self::KEY;
    }

    public function country(): string
    {
        return 'EE';
    }

    public function sourceUrl(): string
    {
        return $this->setting('url') ?? self::URL;
    }

    public function label(): string
    {
        return 'Estonia e-Business Register (open data)';
    }

    public function registryUrl(): string
    {
        return 'https://ariregister.rik.ee/';
    }

    public function sample(): string
    {
        return '10060701';
    }

    public function archiveMembers(): array
    {
        return ['ettevotja_rekvisiidid__lihtandmed.csv'];
    }

    public function rows(string $localFile): Generator
    {
        foreach (Csv::read($localFile, ';') as $row) {
            $identifier = trim($row['ariregistri_kood'] ?? '');
            $name = trim($row['nimi'] ?? '');
            if ($identifier === '' || $name === '') {
                continue;
            }

            $street = Data::clean($row['asukoht_ettevotja_aadressis'] ?? null);
            $postalCode = Data::clean($row['indeks_ettevotja_aadressis'] ?? null);
            $city = Data::clean($row['asukoha_ehak_tekstina'] ?? null);
            $statusCode = Data::clean($row['ettevotja_staatus'] ?? null);

            yield Record::make(
                identifier: $identifier,
                name: $name,
                address: Record::join($street, trim($postalCode.' '.$city)),
                status: match ($statusCode) {
                    'R' => CompanyStatus::Active,
                    'L', 'N' => CompanyStatus::Inactive,
                    'K' => CompanyStatus::Dissolved,
                    default => CompanyStatus::Unknown,
                },
                legalForm: Data::clean($row['ettevotja_oiguslik_vorm'] ?? null),
                foundedAt: Data::date(Data::clean($row['ettevotja_esmakande_kpv'] ?? null), ['d.m.Y']),
                payload: [
                    'vat' => Data::clean($row['kmkr_nr'] ?? null),
                    'legal_form_subtype' => Data::clean($row['ettevotja_oigusliku_vormi_alaliik'] ?? null),
                    'status_code' => $statusCode,
                    'status_text' => Data::clean($row['ettevotja_staatus_tekstina'] ?? null),
                    'street' => $street,
                    'postal_code' => $postalCode,
                    'city' => $city,
                    'ehak_code' => Data::clean($row['asukoha_ehak_kood'] ?? null),
                    'normalized_address' => Data::clean($row['ads_normaliseeritud_taisaadress'] ?? null),
                    'url' => Data::clean($row['teabesysteemi_link'] ?? null),
                ],
            );
        }
    }
}
