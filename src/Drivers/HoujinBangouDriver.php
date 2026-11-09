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
use RuntimeException;
use SimpleXMLElement;

/**
 * Japan National Tax Agency corporate number publication site, Web-API version 4
 * (XML/Unicode responses). Free; needs an application ID issued by the NTA.
 *
 * The English name is used when the corporation registered one; the Japanese name stays in raw.
 */
final class HoujinBangouDriver extends AbstractHttpDriver
{
    public const string KEY = 'houjin-bangou';

    private const BASE_URL = 'https://api.houjin-bangou.nta.go.jp/4';

    /** Response type 12: XML, Unicode (JIS levels 1-4). */
    private const XML_UNICODE = '12';

    /** Corporation kinds ("kind") from the NTA resource definition. */
    private const KINDS = [
        '101' => '国の機関',
        '201' => '地方公共団体',
        '301' => '株式会社',
        '302' => '有限会社',
        '303' => '合名会社',
        '304' => '合資会社',
        '305' => '合同会社',
        '399' => 'その他の設立登記法人',
        '401' => '外国会社等',
        '499' => 'その他',
    ];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'NTA Corporate Number Publication Site',
            countries: ['JP'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: ['app_id' => 'COMPANY_LOOKUP_HOUJIN_BANGOU_APP_ID'],
            attribution: 'Source: National Tax Agency Japan, Corporate Number Publication Site (processed).',
            registryUrl: 'https://www.houjin-bangou.nta.go.jp/',
            sample: '1180301018771',
            sampleCountry: 'JP',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = Normalize::digits(Normalize::withoutPrefix('JP', $identifier->value));

        if (strtoupper($country) !== 'JP' || $identifier->type !== IdentifierType::Registration || ! self::validCorporateNumber($number)) {
            return LookupResult::notFound(self::KEY);
        }

        $appId = $this->setting('app_id');
        if ($appId === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_HOUJIN_BANGOU_APP_ID');
        }

        return $this->guard(function () use ($appId, $number): LookupResult {
            $response = $this->request()->get($this->baseUrl(self::BASE_URL).'/num', [
                'id' => $appId,
                'number' => $number,
                'type' => self::XML_UNICODE,
                'history' => 0,
            ]);

            return $this->result($response, 1);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'JP' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        $appId = $this->setting('app_id');
        if ($appId === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_HOUJIN_BANGOU_APP_ID');
        }

        // Target 3 searches the registered English names; 1 is the fuzzy Japanese search.
        $english = preg_match('/^[\x20-\x7E]+$/', $name) === 1;

        return $this->guard(function () use ($appId, $name, $english, $limit): LookupResult {
            $response = $this->request()->get($this->baseUrl(self::BASE_URL).'/name', [
                'id' => $appId,
                'name' => $name,
                'type' => self::XML_UNICODE,
                'mode' => 2,
                'target' => $english ? 3 : 1,
            ]);

            return $this->result($response, max(1, $limit));
        });
    }

    /**
     * Corporate number check digit (the first digit): 9 - (weighted sum of the other 12 mod 9),
     * weights 1 and 2 alternating from the rightmost digit.
     */
    public static function validCorporateNumber(string $number): bool
    {
        if (preg_match('/^\d{13}$/', $number) !== 1) {
            return false;
        }

        $base = strrev(substr($number, 1));
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $base[$i] * ($i % 2 === 0 ? 1 : 2);
        }

        return 9 - $sum % 9 === (int) $number[0];
    }

    private function result(Response $response, int $limit): LookupResult
    {
        // The NTA answers 403 after an application ID was throttled and 404 for an unknown or invalid ID.
        if ($response->status() === 403) {
            return LookupResult::failed(self::KEY, FailureReason::RateLimited, true, 'Access restricted for this application ID');
        }

        if ($response->status() === 404) {
            return LookupResult::failed(self::KEY, FailureReason::Rejected, false, 'Unknown or invalid application ID');
        }

        if (! $response->successful()) {
            return $this->failure($response, Data::clean($response->body()));
        }

        $companies = [];
        foreach ($this->xml($response->body())->corporation as $corporation) {
            if ((string) $corporation->latest === '0') {
                continue;
            }

            $company = $this->company($corporation);
            if ($company !== null) {
                $companies[] = $company;
            }
        }

        $companies = array_slice($companies, 0, $limit);

        return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
    }

    /**
     * @throws RuntimeException When the body is not XML.
     */
    private function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RuntimeException('The corporate number response is not valid XML.');
        }

        return $document;
    }

    private function company(SimpleXMLElement $corporation): ?Company
    {
        $fields = [];
        foreach ($corporation->children() as $child) {
            $value = Data::clean((string) $child);
            if ($value !== null) {
                $fields[$child->getName()] = $value;
            }
        }

        $number = $fields['corporateNumber'] ?? null;
        if ($number === null) {
            return null;
        }

        $kind = $fields['kind'] ?? null;
        $closed = isset($fields['closeDate']);

        return new Company(
            name: $fields['enName'] ?? $fields['name'] ?? null,
            country: 'JP',
            source: self::KEY,
            identifiers: [Identifier::registration($number, 'JP-CN')],
            address: $this->address($fields),
            status: $closed ? CompanyStatus::Dissolved : CompanyStatus::Active,
            legalForm: $kind === null ? null : (self::KINDS[$kind] ?? null),
            legalFormCode: $kind,
            raw: $fields,
        );
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function address(array $fields): ?Address
    {
        $postcode = $fields['postCode'] ?? null;
        if ($postcode !== null && preg_match('/^\d{7}$/', $postcode) === 1) {
            $postcode = substr($postcode, 0, 3).'-'.substr($postcode, 3);
        }

        $outside = $fields['addressOutside'] ?? null;
        if ($outside !== null) {
            return new Address(postalCode: $postcode, freeText: $outside);
        }

        $prefecture = $fields['prefectureName'] ?? null;
        $city = $fields['cityName'] ?? null;
        $street = $fields['streetNumber'] ?? null;

        if ($prefecture === null && $city === null && $street === null) {
            return null;
        }

        // Japanese addresses read from the largest unit down, without separators.
        return new Address(
            street: $street,
            postalCode: $postcode,
            city: $city,
            region: $prefecture,
            country: 'JP',
            freeText: implode('', array_filter([$prefecture, $city, $street])),
        );
    }
}
