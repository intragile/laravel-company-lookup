<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Drivers\Support\Soap;
use IntrAgile\CompanyLookup\Enums\CompanyStatus;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use RuntimeException;
use SimpleXMLElement;

/**
 * Polish REGON register of Statistics Poland (GUS), BIR 1.1 SOAP 1.2 service.
 *
 * Free; production needs a user key requested from GUS. The test environment ('sandbox')
 * always uses the public test key and returns anonymised data.
 */
final class GusRegonDriver extends AbstractHttpDriver
{
    public const string KEY = 'gus-regon';

    private const PRODUCTION = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';

    private const SANDBOX = 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';

    private const TEST_KEY = 'abcde12345abcde12345';

    private const NS = 'http://CIS/BIR/PUBL/2014/07';

    private const DATA_NS = 'http://CIS/BIR/PUBL/2014/07/DataContract';

    private const WSA_NS = 'http://www.w3.org/2005/08/addressing';

    private const ACTION = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    /** GUS sessions expire after 60 minutes of inactivity. */
    private const SESSION_TTL = 3000;

    /** ErrorCode of DaneSzukajPodmioty when nothing matches. */
    private const NOT_FOUND = '4';

    private const TYPES = [
        'P' => 'Legal person or organisational unit without legal personality',
        'F' => 'Natural person conducting business activity',
        'LP' => 'Local unit of a legal person',
        'LF' => 'Local unit of a natural person',
    ];

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'GUS REGON (BIR 1.1)',
            countries: ['PL'],
            identifierTypes: [IdentifierType::Registration, IdentifierType::Tax, IdentifierType::Vat],
            nameSearch: false,
            credentials: ['key' => 'COMPANY_LOOKUP_GUS_REGON_KEY'],
            registryUrl: 'https://wyszukiwarkaregon.stat.gov.pl/',
            sample: '012100784',
            sampleCountry: 'PL',
        );
    }

    /**
     * The test environment works with the public test key, so no key is needed there.
     */
    public function isConfigured(): bool
    {
        return $this->flag('sandbox') || $this->setting('key') !== null;
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $parameter = strtoupper($country) === 'PL' ? $this->parameter($identifier) : null;

        if ($parameter === null) {
            return LookupResult::notFound(self::KEY);
        }

        if (! $this->isConfigured()) {
            return LookupResult::unconfigured(self::KEY, 'COMPANY_LOOKUP_GUS_REGON_KEY');
        }

        return $this->guard(fn (): LookupResult => $this->find($parameter[0], $parameter[1], retryWithNewSession: true));
    }

    /**
     * The DaneSzukajPodmioty search field and value for an identifier.
     *
     * @return array{string, string}|null
     */
    private function parameter(Identifier $identifier): ?array
    {
        if ($identifier->type === IdentifierType::Registration) {
            $regon = Normalize::digits($identifier->value);

            return in_array(strlen($regon), [9, 14], true) ? ['Regon', $regon] : null;
        }

        if ($identifier->type === IdentifierType::Tax || $identifier->type === IdentifierType::Vat) {
            $nip = Normalize::digits(Normalize::withoutPrefix('PL', $identifier->value));

            return strlen($nip) === 10 ? ['Nip', $nip] : null;
        }

        return null;
    }

    private function find(string $field, string $value, bool $retryWithNewSession): LookupResult
    {
        $sid = $this->session();
        if ($sid instanceof LookupResult) {
            return $sid;
        }

        $body = '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania>'
            .'<dat:'.$field.'>'.Soap::escape($value).'</dat:'.$field.'>'
            .'</ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>';

        $envelope = $this->call('DaneSzukajPodmioty', $body, $sid);
        if ($envelope instanceof LookupResult) {
            return $envelope;
        }

        $result = Soap::value($envelope, 'DaneSzukajPodmiotyResult');

        // An empty result without an error code means the session is unknown or expired.
        if ($result === null) {
            $this->cache?->forget($this->sessionKey());

            return $retryWithNewSession
                ? $this->find($field, $value, retryWithNewSession: false)
                : LookupResult::failed(self::KEY, FailureReason::Rejected, false, 'GUS did not accept the session.');
        }

        return $this->companies($result);
    }

    /**
     * A session ID for the configured key, cached while GUS keeps it alive.
     */
    private function session(): string|LookupResult
    {
        $cached = $this->cache?->get($this->sessionKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $body = '<ns:Zaloguj><ns:pKluczUzytkownika>'.Soap::escape($this->userKey()).'</ns:pKluczUzytkownika></ns:Zaloguj>';
        $envelope = $this->call('Zaloguj', $body);

        if ($envelope instanceof LookupResult) {
            return $envelope;
        }

        // GUS answers an unknown key with an empty session ID.
        $sid = Soap::value($envelope, 'ZalogujResult');
        if ($sid === null) {
            return LookupResult::failed(self::KEY, FailureReason::Rejected, false, 'GUS rejected the user key.');
        }

        $this->cache?->put($this->sessionKey(), $sid, self::SESSION_TTL);

        return $sid;
    }

    /**
     * Sends one SOAP 1.2 request with WS-Addressing headers.
     */
    private function call(string $operation, string $body, ?string $sid = null): SimpleXMLElement|LookupResult
    {
        $endpoint = $this->endpoint();
        $header = '<wsa:To>'.Soap::escape($endpoint).'</wsa:To><wsa:Action>'.self::ACTION.$operation.'</wsa:Action>';
        $xml = Soap::envelope($body, ['ns' => self::NS, 'dat' => self::DATA_NS, 'wsa' => self::WSA_NS], Soap::V12, $header);

        $request = $this->request();
        if ($sid !== null) {
            $request = $request->withHeaders(['sid' => $sid]);
        }

        $response = $request->withBody($xml, 'application/soap+xml; charset=utf-8')->post($endpoint);

        if (! $response->successful()) {
            $fault = null;
            try {
                $fault = Soap::fault(Soap::parse($response->body()));
            } catch (RuntimeException) {
                // Not a SOAP body; report the HTTP status.
            }

            return $this->failure($response, $fault);
        }

        $envelope = Soap::parse($response->body());
        $fault = Soap::fault($envelope);

        return $fault === null ? $envelope : LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, $fault);
    }

    /**
     * Maps the XML document that DaneSzukajPodmioty returns as escaped text.
     */
    private function companies(string $document): LookupResult
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($document, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($root === false) {
            return LookupResult::failed(self::KEY, FailureReason::InvalidResponse, false, 'The GUS result is not valid XML.');
        }

        $companies = [];
        foreach (Soap::all($root, 'dane') as $entry) {
            $fields = $this->fields($entry);

            if (isset($fields['ErrorCode'])) {
                return $fields['ErrorCode'] === self::NOT_FOUND
                    ? LookupResult::notFound(self::KEY)
                    : LookupResult::failed(self::KEY, FailureReason::Rejected, false, $fields['ErrorMessageEn'] ?? 'GUS error '.$fields['ErrorCode']);
            }

            $companies[] = $this->company($fields);
        }

        return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
    }

    /**
     * @return array<string, string>
     */
    private function fields(SimpleXMLElement $entry): array
    {
        $fields = [];
        foreach ($entry->children() as $name => $child) {
            $value = Data::clean((string) $child);
            if ($value !== null) {
                $fields[(string) $name] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function company(array $fields): Company
    {
        $identifiers = [];
        if (isset($fields['Regon'])) {
            $identifiers[] = Identifier::registration($fields['Regon'], 'PL-REGON');
        }
        if (isset($fields['Nip'])) {
            $identifiers[] = Identifier::tax($fields['Nip'], 'PL-NIP');

            // StatusNip is empty for a NIP in force and holds a status (revoked or annulled)
            // otherwise; such a NIP is not offered as a VAT number. VAT registration itself is
            // not part of REGON data, so vatValid stays null.
            if (! isset($fields['StatusNip'])) {
                $identifiers[] = Identifier::vat('PL'.$fields['Nip']);
            }
        }

        $type = $fields['Typ'] ?? null;

        return new Company(
            name: $fields['Nazwa'] ?? null,
            country: 'PL',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address($fields),
            status: isset($fields['DataZakonczeniaDzialalnosci']) ? CompanyStatus::Dissolved : CompanyStatus::Active,
            legalForm: $type === null ? null : (self::TYPES[$type] ?? null),
            legalFormCode: $type,
            raw: $fields,
        );
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function address(array $fields): ?Address
    {
        $number = $fields['NrNieruchomosci'] ?? null;
        if ($number !== null && isset($fields['NrLokalu'])) {
            $number .= '/'.$fields['NrLokalu'];
        }

        $street = trim(($fields['Ulica'] ?? '').' '.($number ?? ''));

        $address = new Address(
            street: $street === '' ? null : $street,
            postalCode: $fields['KodPocztowy'] ?? null,
            city: $fields['Miejscowosc'] ?? null,
            region: $fields['Wojewodztwo'] ?? null,
            country: 'PL',
        );

        return $address->street === null && $address->city === null ? null : $address;
    }

    private function endpoint(): string
    {
        return $this->baseUrl($this->flag('sandbox') ? self::SANDBOX : self::PRODUCTION);
    }

    private function userKey(): string
    {
        return $this->flag('sandbox') ? self::TEST_KEY : (string) $this->setting('key');
    }

    private function sessionKey(): string
    {
        return 'company-lookup:session:'.self::KEY.':'.sha1($this->endpoint().'|'.$this->userKey());
    }
}
