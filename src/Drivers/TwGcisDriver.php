<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use DateTimeImmutable;
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
 * Taiwan company register of the Ministry of Economic Affairs (GCIS open data API). Free, no key.
 *
 * Names and addresses are in Chinese. Dates are in the ROC calendar (year + 1911).
 * Name search only covers companies with "approved" status (Company_Status 01), as the API requires a status filter.
 */
final class TwGcisDriver extends AbstractHttpDriver
{
    public const string KEY = 'tw-gcis';

    /** Basic company data by Business Accounting Number. */
    private const DETAILS_DATASET = '5F64D864-61CB-4D0D-8AD9-492047CC1EA6';

    /** Company name keyword search. */
    private const SEARCH_DATASET = '6BBA2268-1367-4B42-9CCA-BC17499EBE8C';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Taiwan GCIS company register',
            countries: ['TW'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Data from the Ministry of Economic Affairs (GCIS), Open Government Data License, version 1.0.',
            registryUrl: 'https://findbiz.nat.gov.tw/',
            sample: '22099131',
            sampleCountry: 'TW',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = Normalize::digits(Normalize::withoutPrefix('TW', $identifier->value));

        if (strtoupper($country) !== 'TW' || strlen($number) !== 8) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($number): LookupResult {
            $records = $this->query(self::DETAILS_DATASET, 'Business_Accounting_NO eq '.$number, 1);
            if ($records instanceof LookupResult) {
                return $records;
            }

            foreach ($records as $record) {
                if (Data::string($record, 'Business_Accounting_NO') === $number) {
                    return LookupResult::found(self::KEY, $this->company($record));
                }
            }

            return LookupResult::notFound(self::KEY);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        // The filter syntax has no quoting, and a malformed filter is answered like "no match",
        // so only the longest whitespace-free token is sent; the other tokens filter the results here.
        $tokens = preg_split('/[\s\x{3000}]+/u', str_replace(['\'', '"'], '', $name), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = $tokens === false ? [] : $tokens;

        if (strtoupper($country) !== 'TW' || $tokens === [] || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        $keyword = $tokens[0];
        foreach ($tokens as $token) {
            if (mb_strlen($token) > mb_strlen($keyword)) {
                $keyword = $token;
            }
        }

        return $this->guard(function () use ($tokens, $keyword, $limit): LookupResult {
            $top = count($tokens) > 1 ? max($limit, 100) : $limit;
            $records = $this->query(self::SEARCH_DATASET, 'Company_Name like '.$keyword.' and Company_Status eq 01', $top);
            if ($records instanceof LookupResult) {
                return $records;
            }

            $companies = [];
            foreach ($records as $record) {
                $companyName = mb_strtolower(Data::string($record, 'Company_Name') ?? '');
                foreach ($tokens as $token) {
                    if (! str_contains($companyName, mb_strtolower($token))) {
                        continue 2;
                    }
                }
                $companies[] = $this->company($record);
            }

            $companies = array_slice($companies, 0, $limit);

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * Converts an ROC calendar date (YYYMMDD, e.g. 0760221) to a Gregorian date (1987-02-21).
     */
    public static function rocDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || preg_match('/^(\d{2,3})(\d{2})(\d{2})$/', trim($value), $match) !== 1) {
            return null;
        }

        $year = (int) $match[1] + 1911;
        $month = (int) $match[2];
        $day = (int) $match[3];

        if ((int) $match[1] === 0 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
    }

    /**
     * Runs an OData-style query. The API answers an empty body when nothing matches.
     *
     * @return list<array<array-key, mixed>>|LookupResult
     */
    private function query(string $dataset, string $filter, int $top): array|LookupResult
    {
        $response = $this->request()
            ->acceptJson()
            ->get($this->baseUrl('https://data.gcis.nat.gov.tw/od/data/api').'/'.$dataset, [
                '$format' => 'json',
                '$filter' => $filter,
                '$skip' => 0,
                '$top' => $top,
            ]);

        if (! $response->successful()) {
            return $this->failure($response);
        }

        if (trim($response->body()) === '') {
            return [];
        }

        return Data::arrays($this->json($response));
    }

    /**
     * @param  array<array-key, mixed>  $record
     */
    private function company(array $record): Company
    {
        $location = Data::string($record, 'Company_Location');

        return new Company(
            name: Data::string($record, 'Company_Name'),
            country: 'TW',
            source: self::KEY,
            identifiers: [Identifier::registration((string) Data::string($record, 'Business_Accounting_NO'), 'TW-UBN')],
            address: $location === null ? null : new Address(country: 'TW', freeText: $location),
            status: $this->status(Data::string($record, 'Company_Status_Desc')),
            foundedAt: self::rocDate(Data::string($record, 'Company_Setup_Date')),
            raw: $record,
        );
    }

    private function status(?string $description): CompanyStatus
    {
        $description ??= '';

        foreach (['解散', '廢止', '撤銷', '撤回', '破產', '歇業'] as $needle) {
            if (str_contains($description, $needle)) {
                return CompanyStatus::Dissolved;
            }
        }

        return match (true) {
            str_contains($description, '停業') => CompanyStatus::Inactive,
            str_contains($description, '核准設立') => CompanyStatus::Active,
            default => CompanyStatus::Unknown,
        };
    }
}
