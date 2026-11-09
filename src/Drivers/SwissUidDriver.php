<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Drivers\Support\Soap;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use SimpleXMLElement;
use Throwable;

/**
 * Swiss UID register public web service (UID-WSE V5.0, SOAP 1.1), built without ext-soap. Free, no key.
 *
 * The public service is rate limited (about 20 requests per minute per IP).
 * Name search needs at least three non-space characters; broader queries return no results from the service.
 * A VAT lookup is found only for entities whose active VAT registration carries the requested UID;
 * members of a VAT group are reached through the group's UID.
 */
final class SwissUidDriver extends AbstractHttpDriver
{
    public const string KEY = 'swiss-uid';

    private const ENDPOINT = 'https://www.uid-wse.admin.ch/V5.0/PublicServices.svc';

    private const ACTION = 'http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/';

    private const NAMESPACES = [
        'uid' => 'http://www.uid.admin.ch/xmlns/uid-wse',
        'uid5' => 'http://www.uid.admin.ch/xmlns/uid-wse/5',
        'shared' => 'http://www.uid.admin.ch/xmlns/uid-wse-shared/2',
        'ech97' => 'http://www.ech.ch/xmlns/eCH-0097/5',
    ];

    /** Shorter names make the service return an empty result. */
    private const MIN_SEARCH_LENGTH = 3;

    /** Business fault error code of the service's per-IP rate limit. */
    private const RATE_LIMIT_ERROR = 'Request_limit_exceeded';

    /** eCH-0108 uidregStatusEnterpriseDetail: 5 deleted, 6 definitively deleted, 7 cancelled. */
    private const UIDREG_GONE = ['5', '6', '7'];

    /** eCH-0108 commercialRegisterStatus 3: deleted from the commercial register. */
    private const COMMERCIAL_REGISTER_DELETED = '3';

    /** eCH-0108 vatStatus 2: active VAT registration. */
    private const VAT_ACTIVE = '2';

    /** eCH-0097 legal form codes of the commercial register entities. */
    private const LEGAL_FORMS = [
        '0101' => 'Sole proprietorship',
        '0103' => 'General partnership',
        '0104' => 'Limited partnership',
        '0105' => 'Partnership limited by shares',
        '0106' => 'Company limited by shares',
        '0107' => 'Limited liability company',
        '0108' => 'Cooperative',
        '0109' => 'Association',
        '0110' => 'Foundation',
    ];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'Switzerland – UID register',
            countries: ['CH'],
            identifierTypes: [IdentifierType::Vat, IdentifierType::Registration],
            nameSearch: true,
            attribution: 'Source: Swiss Federal Statistical Office (FSO), UID register',
            registryUrl: 'https://www.uid.admin.ch/',
            sample: 'CHE-116.281.710 MWST',
            sampleCountry: 'CH',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $uid = self::uid($identifier->value);

        if (strtoupper($country) !== 'CH' || $uid === null) {
            return LookupResult::notFound(self::KEY);
        }

        $body = '<uid:GetByUID><uid:uid>'
            .'<ech97:uidOrganisationIdCategorie>CHE</ech97:uidOrganisationIdCategorie>'
            .'<ech97:uidOrganisationId>'.$uid.'</ech97:uidOrganisationId>'
            .'</uid:uid></uid:GetByUID>';

        $vat = $identifier->type === IdentifierType::Vat;

        return $this->guard(function () use ($body, $uid, $vat): LookupResult {
            $envelope = $this->call('GetByUID', $body);
            if ($envelope instanceof LookupResult) {
                return $envelope;
            }

            $companies = [];
            foreach (Soap::all($envelope, 'organisationType') as $organisation) {
                $company = $this->company($organisation);
                if (! $vat || ($company->vatValid === true && $this->vatUid($organisation) === $uid)) {
                    $companies[] = $company;
                }
            }

            return LookupResult::found(self::KEY, ...$companies);
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        $name = trim($name);

        if (strtoupper($country) !== 'CH' || mb_strlen((string) preg_replace('/\s+/u', '', $name)) < self::MIN_SEARCH_LENGTH) {
            return LookupResult::notFound(self::KEY);
        }

        $body = '<uid:Search>'
            .'<uid:searchParameters><uid5:uidEntitySearchParameters>'
            .'<uid5:organisationName>'.Soap::escape($name).'</uid5:organisationName>'
            .'</uid5:uidEntitySearchParameters></uid:searchParameters>'
            .'<uid:config>'
            .'<shared:searchMode>Auto</shared:searchMode>'
            .'<shared:maxNumberOfRecords>'.max(1, $limit).'</shared:maxNumberOfRecords>'
            .'<shared:searchNameAndAddressHistory>false</shared:searchNameAndAddressHistory>'
            .'</uid:config>'
            .'</uid:Search>';

        return $this->guard(function () use ($body, $limit): LookupResult {
            $envelope = $this->call('Search', $body);
            if ($envelope instanceof LookupResult) {
                return $envelope;
            }

            $companies = [];
            foreach (Soap::all($envelope, 'uidEntitySearchResultItem') as $item) {
                $organisation = Soap::all($item, 'organisation')[0] ?? null;
                if ($organisation !== null) {
                    $companies[] = $this->company($organisation);
                }
            }

            return LookupResult::found(self::KEY, ...array_slice($companies, 0, max(1, $limit)));
        });
    }

    /**
     * The nine UID digits from "CHE-123.456.789", "CHE123456789 MWST" and similar,
     * or null when malformed or the mod-11 check digit is wrong.
     */
    public static function uid(string $value): ?string
    {
        $compact = (string) preg_replace('/(MWST|TVA|IVA|VAT)$/', '', Normalize::compact($value));
        $digits = Normalize::withoutPrefix('CH', $compact);

        if (preg_match('/^\d{9}$/', $digits) !== 1) {
            return null;
        }

        $sum = 0;
        foreach ([5, 4, 3, 2, 7, 6, 5, 4] as $position => $weight) {
            $sum += (int) $digits[$position] * $weight;
        }
        $check = 11 - $sum % 11;

        if ($check === 10) {
            return null;
        }

        return (int) $digits[8] === ($check === 11 ? 0 : $check) ? $digits : null;
    }

    /**
     * The UID in its official display form, CHE-123.456.789.
     */
    public static function format(string $digits): string
    {
        return 'CHE-'.implode('.', str_split($digits, 3));
    }

    /**
     * Posts a SOAP request. Returns the parsed envelope, or a failed result for SOAP faults and HTTP errors.
     */
    private function call(string $operation, string $body): SimpleXMLElement|LookupResult
    {
        $response = $this->request()
            // SOAP faults arrive as HTTP 500 and must not be retried; only transport errors are.
            ->retry(2, 300, fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $this->transient($exception->response)), throw: false)
            ->withHeaders(['SOAPAction' => '"'.self::ACTION.$operation.'"'])
            ->withBody(Soap::envelope($body, self::NAMESPACES), 'text/xml; charset=utf-8')
            ->post($this->baseUrl(self::ENDPOINT));

        if (! $response->successful() && ! $this->isFault($response)) {
            return $this->failure($response);
        }

        $envelope = Soap::parse($response->body());
        $fault = Soap::fault($envelope);

        if ($fault === null) {
            return $envelope;
        }

        $detail = Soap::value($envelope, 'errorDetail');
        $message = $detail === null || $detail === $fault ? $fault : $fault.': '.$detail;
        $code = Soap::value($envelope, 'faultcode') ?? '';

        if (in_array(self::RATE_LIMIT_ERROR, [$fault, Soap::value($envelope, 'error')], true)) {
            return LookupResult::failed(self::KEY, FailureReason::RateLimited, true, $message);
        }

        return str_ends_with($code, 'Client')
            ? LookupResult::failed(self::KEY, FailureReason::Rejected, false, $message)
            : LookupResult::failed(self::KEY, FailureReason::Unavailable, true, $message);
    }

    private function transient(Response $response): bool
    {
        return $response->status() === 429 || ($response->serverError() && ! $this->isFault($response));
    }

    private function isFault(Response $response): bool
    {
        return preg_match('/<([A-Za-z0-9_]+:)?Fault[\s>]/', $response->body()) === 1;
    }

    private function company(SimpleXMLElement $organisation): Company
    {
        $uid = $this->uidOf(Soap::all($organisation, 'organisationIdentification')[0] ?? $organisation);
        $vatValid = $this->vatValid($organisation);
        $vatUid = $this->vatUid($organisation);

        $identifiers = [];
        if ($uid !== null) {
            $identifiers[] = Identifier::registration(self::format($uid), 'CH-UID');
        }
        foreach (Soap::all($organisation, 'OtherOrganisationId') as $other) {
            $id = Soap::value($other, 'organisationId');
            if ($id !== null && Soap::value($other, 'organisationIdCategory') === 'CH.HR') {
                $identifiers[] = Identifier::registration($id, 'CH-HR');
            }
        }
        if ($vatValid === true && $vatUid !== null) {
            $identifiers[] = Identifier::vat('CHE'.$vatUid.'MWST');
        }

        $legalForm = Soap::value($organisation, 'legalForm');

        return new Company(
            name: Soap::value($organisation, 'organisationName') ?? Soap::value($organisation, 'organisationLegalName'),
            country: 'CH',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address($organisation),
            status: $this->status($organisation),
            legalForm: $legalForm === null ? null : (self::LEGAL_FORMS[$legalForm] ?? null),
            legalFormCode: $legalForm,
            vatValid: $vatValid,
            raw: $this->toArray($organisation),
        );
    }

    private function uidOf(SimpleXMLElement $node): ?string
    {
        $uid = Soap::all($node, 'uid')[0] ?? null;
        $digits = $uid === null ? null : Soap::value($uid, 'uidOrganisationId');

        return $digits === null ? null : str_pad($digits, 9, '0', STR_PAD_LEFT);
    }

    /**
     * The UID under which the entity is VAT registered (its own, or its VAT group's).
     */
    private function vatUid(SimpleXMLElement $organisation): ?string
    {
        $vat = Soap::all($organisation, 'vatRegisterInformation')[0] ?? null;
        $uidVat = $vat === null ? null : (Soap::all($vat, 'uidVat')[0] ?? null);
        $digits = $uidVat === null ? null : Soap::value($uidVat, 'uidOrganisationId');

        return $digits === null ? null : str_pad($digits, 9, '0', STR_PAD_LEFT);
    }

    private function vatValid(SimpleXMLElement $organisation): bool
    {
        $vat = Soap::all($organisation, 'vatRegisterInformation')[0] ?? null;

        return $vat !== null && Soap::value($vat, 'vatStatus') === self::VAT_ACTIVE;
    }

    private function status(SimpleXMLElement $organisation): CompanyStatus
    {
        $uidreg = Soap::value($organisation, 'uidregStatusEnterpriseDetail');

        if (in_array($uidreg, self::UIDREG_GONE, true) || Soap::value($organisation, 'commercialRegisterStatus') === self::COMMERCIAL_REGISTER_DELETED) {
            return CompanyStatus::Dissolved;
        }

        return $uidreg === null ? CompanyStatus::Unknown : CompanyStatus::Active;
    }

    /**
     * The legal address, else the first address.
     */
    private function address(SimpleXMLElement $organisation): ?Address
    {
        $chosen = null;
        foreach (Soap::all($organisation, 'address') as $address) {
            if (Soap::value($address, 'addressCategory') === 'LEGAL') {
                $chosen = $address;
                break;
            }
            $chosen ??= $address;
        }

        if ($chosen === null) {
            return null;
        }

        $street = Soap::value($chosen, 'street');
        $number = Soap::value($chosen, 'houseNumber');

        return new Address(
            street: $street === null ? Soap::value($chosen, 'addressLine1') : trim($street.' '.($number ?? '')),
            postalCode: Soap::value($chosen, 'swissZipCode') ?? Soap::value($chosen, 'foreignZipCode'),
            city: Soap::value($chosen, 'town'),
            region: Soap::value($chosen, 'cantonAbbreviation'),
            country: Soap::value($chosen, 'countryIdISO2') ?? 'CH',
        );
    }

    /**
     * The XML element as nested arrays, keyed by local element name.
     *
     * @return array<array-key, mixed>
     */
    private function toArray(SimpleXMLElement $node): array
    {
        $result = [];
        foreach ($node->xpath('./*') ?: [] as $child) {
            $name = $child->getName();
            $value = $child->xpath('./*') ? $this->toArray($child) : trim((string) $child);

            if (! array_key_exists($name, $result)) {
                $result[$name] = $value;
            } elseif (is_array($result[$name]) && array_is_list($result[$name])) {
                $result[$name][] = $value;
            } else {
                $result[$name] = [$result[$name], $value];
            }
        }

        return $result;
    }
}
