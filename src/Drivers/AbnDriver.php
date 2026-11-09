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

/**
 * Australian Business Register ABN Lookup (JSON services). Free; needs a GUID
 * registered with the ABR. Looks up an 11-digit ABN or a 9-digit ACN, and searches names.
 *
 * The service always answers HTTP 200 with a JSONP body; errors arrive in "Message".
 */
final class AbnDriver extends AbstractHttpDriver
{
    public const string KEY = 'abn';

    private const BASE_URL = 'https://abr.business.gov.au/json';

    private const ABN_WEIGHTS = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

    private const ACN_WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 1];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Australian Business Register (ABN Lookup)',
            countries: ['AU'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: ['guid' => 'COMPANY_LOOKUP_ABN_GUID'],
            attribution: 'Contains data from the Australian Business Register (ABN Lookup), CC BY 3.0 AU.',
            registryUrl: 'https://abr.business.gov.au/',
            sample: '33051775556',
            sampleCountry: 'AU',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $number = Normalize::digits(Normalize::withoutPrefix('AU', $identifier->value));

        if (strtoupper($country) !== 'AU' || $identifier->type !== IdentifierType::Registration) {
            return LookupResult::notFound(self::KEY);
        }

        $query = match (true) {
            strlen($number) === 11 && self::validAbn($number) => ['AbnDetails.aspx', ['abn' => $number]],
            strlen($number) === 9 && self::validAcn($number) => ['AcnDetails.aspx', ['acn' => $number]],
            default => null,
        };

        if ($query === null) {
            return LookupResult::notFound(self::KEY);
        }

        $guid = $this->setting('guid');
        if ($guid === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_ABN_GUID');
        }

        return $this->guard(function () use ($query, $guid): LookupResult {
            $response = $this->call($query[0], $query[1] + ['guid' => $guid]);
            if (! $response->successful()) {
                return $this->failure($response);
            }

            $data = $this->payload($response);
            $abn = Data::string($data, 'Abn');

            if ($abn === null) {
                return $this->messageResult(Data::string($data, 'Message'));
            }

            return LookupResult::found(self::KEY, $this->company($data, $abn));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'AU' || $name === '') {
            return LookupResult::notFound(self::KEY);
        }

        $guid = $this->setting('guid');
        if ($guid === null) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_ABN_GUID');
        }

        return $this->guard(function () use ($name, $limit, $guid): LookupResult {
            $response = $this->call('MatchingNames.aspx', [
                'name' => $name,
                'maxResults' => max(1, min(200, $limit)),
                'guid' => $guid,
            ]);
            if (! $response->successful()) {
                return $this->failure($response);
            }

            $data = $this->payload($response);
            $names = Data::list($data, 'Names');

            if ($names === []) {
                return $this->messageResult(Data::string($data, 'Message'));
            }

            $companies = [];
            foreach ($names as $item) {
                $abn = Data::string($item, 'Abn');
                if ($abn === null) {
                    continue;
                }

                $companies[] = new Company(
                    name: Data::string($item, 'Name'),
                    country: 'AU',
                    source: self::KEY,
                    identifiers: [Identifier::registration($abn, 'AU-ABN')],
                    address: $this->address(Data::string($item, 'State'), Data::string($item, 'Postcode')),
                    status: $this->status(Data::string($item, 'AbnStatus')),
                    raw: $item,
                );
            }

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * ABN check: subtract 1 from the first digit, weighted sum divisible by 89.
     */
    public static function validAbn(string $abn): bool
    {
        if (preg_match('/^\d{11}$/', $abn) !== 1) {
            return false;
        }

        $digits = array_map('intval', str_split($abn));
        $digits[0]--;

        $sum = 0;
        foreach (self::ABN_WEIGHTS as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        return $sum % 89 === 0;
    }

    /**
     * ACN check digit: complement of the weighted sum modulo 10.
     */
    public static function validAcn(string $acn): bool
    {
        if (preg_match('/^\d{9}$/', $acn) !== 1) {
            return false;
        }

        $digits = array_map('intval', str_split($acn));
        $sum = 0;
        foreach (self::ACN_WEIGHTS as $index => $weight) {
            $sum += $digits[$index] * $weight;
        }

        return (10 - $sum % 10) % 10 === $digits[8];
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function call(string $endpoint, array $query): Response
    {
        return $this->request()->get($this->baseUrl(self::BASE_URL).'/'.$endpoint, $query + ['callback' => 'callback']);
    }

    /**
     * The JSON object inside the JSONP callback wrapper.
     *
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException When the body is neither JSONP nor JSON.
     */
    private function payload(Response $response): array
    {
        $body = trim($response->body());

        if (preg_match('/^[A-Za-z_$][\w$.]*\s*\((.*)\)\s*;?$/s', $body, $match) === 1) {
            $body = $match[1];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The ABN Lookup response is not valid JSON(P).');
        }

        return $decoded;
    }

    /**
     * An empty answer. Only messages that mean "no such record" are a definitive not found
     * (see the documented exception list); a GUID problem is rejected, anything else fails.
     */
    private function messageResult(?string $message): LookupResult
    {
        $text = strtolower($message ?? '');

        return match (true) {
            $text === '', str_contains($text, 'no records found'), str_contains($text, 'not a valid'),
            str_contains($text, 'is invalid in'), str_contains($text, 'not currently') => LookupResult::notFound(self::KEY),
            str_contains($text, 'guid') => LookupResult::failed(self::KEY, FailureReason::Rejected, false, $message),
            default => LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, $message),
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(array $data, string $abn): Company
    {
        $identifiers = [Identifier::registration($abn, 'AU-ABN')];
        $acn = Data::string($data, 'Acn');
        if ($acn !== null) {
            $identifiers[] = Identifier::registration($acn, 'AU-ACN');
        }

        return new Company(
            name: Data::string($data, 'EntityName'),
            country: 'AU',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::string($data, 'AddressState'), Data::string($data, 'AddressPostcode')),
            status: $this->status(Data::string($data, 'AbnStatus')),
            legalForm: Data::string($data, 'EntityTypeName'),
            legalFormCode: Data::string($data, 'EntityTypeCode'),
            vatValid: Data::string($data, 'Gst') !== null ? true : null,
            raw: $data,
        );
    }

    /**
     * ABN Lookup only discloses the state and postcode of the main business location.
     */
    private function address(?string $state, ?string $postcode): ?Address
    {
        if ($state === null && $postcode === null) {
            return null;
        }

        return new Address(postalCode: $postcode, region: $state, country: 'AU');
    }

    /**
     * ABN status as documented for the entity status code ("Active" or "Cancelled", at most
     * 9 characters); any other value, such as a numeric code, stays unknown.
     */
    private function status(?string $status): CompanyStatus
    {
        return match (strtolower($status ?? '')) {
            'active' => CompanyStatus::Active,
            'cancelled' => CompanyStatus::Inactive,
            default => CompanyStatus::Unknown,
        };
    }
}
