<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

/**
 * HMRC "Check a UK VAT number" API v2. Free; needs an application registered on the
 * HMRC Developer Hub (OAuth2 client credentials, application-restricted).
 */
final class HmrcDriver extends AbstractHttpDriver
{
    public const string KEY = 'hmrc';

    private const PRODUCTION = 'https://api.service.hmrc.gov.uk';

    private const SANDBOX = 'https://test-api.service.hmrc.gov.uk';

    private const SCOPE = 'read:vat';

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'HMRC Check a UK VAT number',
            countries: ['GB'],
            identifierTypes: [IdentifierType::Vat],
            nameSearch: false,
            credentials: [
                'client_id' => 'COMPANY_LOOKUP_HMRC_CLIENT_ID',
                'client_secret' => 'COMPANY_LOOKUP_HMRC_CLIENT_SECRET',
            ],
            registryUrl: 'https://www.tax.service.gov.uk/check-vat-number/enter-vat-details',
            // The sandbox only knows HMRC's mock VAT numbers; 553557881 is the documented example.
            sample: $this->flag('sandbox') ? 'GB553557881' : 'GB220430231',
            sampleCountry: 'GB',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        $vrn = $this->vrn(strtoupper($country), $identifier);
        $clientId = $this->setting('client_id');
        $clientSecret = $this->setting('client_secret');

        if ($vrn === null) {
            return LookupResult::notFound(self::KEY);
        }

        if ($clientId === null || $clientSecret === null) {
            return LookupResult::unconfigured(self::KEY, implode(', ', $this->capabilities()->credentials));
        }

        $base = $this->baseUrl($this->flag('sandbox') ? self::SANDBOX : self::PRODUCTION);

        return $this->guard(function () use ($base, $vrn, $clientId, $clientSecret): LookupResult {
            $tokenUrl = $base.'/oauth/token';
            $response = $this->check($base, $vrn, $tokenUrl, $clientId, $clientSecret);

            // A token revoked before its expiry: fetch a new one and try once more.
            if ($response->status() === 401) {
                $this->forgetClientCredentialsToken($tokenUrl, $clientId, self::SCOPE);
                $response = $this->check($base, $vrn, $tokenUrl, $clientId, $clientSecret);
            }

            $error = Data::json($response->body());

            // Only HMRC's own NOT_FOUND code means "not registered"; other 404s (such as a
            // wrong base URL) are failures and must not be cached.
            if ($response->status() === 404 && Data::string($error, 'code') === 'NOT_FOUND') {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response, Data::string($error, 'message'));
            }

            $target = Data::map($this->json($response), 'target');
            $number = Data::string($target, 'vatNumber') ?? $vrn;

            return LookupResult::found(self::KEY, new Company(
                name: Data::string($target, 'name'),
                country: 'GB',
                source: self::KEY,
                identifiers: [Identifier::vat('GB'.$number)],
                address: $this->address(Data::map($target, 'address')),
                vatValid: true,
                raw: $this->json($response),
            ));
        });
    }

    private function check(string $base, string $vrn, string $tokenUrl, string $clientId, string $clientSecret): Response
    {
        // HMRC expects the client credentials as form fields, not HTTP Basic.
        $token = $this->clientCredentialsToken($tokenUrl, $clientId, $clientSecret, self::SCOPE, basicAuth: false);

        return $this->request()
            ->withToken($token)
            ->accept('application/vnd.hmrc.2.0+json')
            ->get($base.'/organisations/vat/check-vat-number/lookup/'.$vrn);
    }

    /**
     * A 9- or 12-digit VAT registration number without the GB prefix.
     */
    private function vrn(string $country, Identifier $identifier): ?string
    {
        if ($country !== 'GB' || $identifier->type !== IdentifierType::Vat) {
            return null;
        }

        $number = Normalize::withoutPrefix('GB', $identifier->value);

        return preg_match('/^(\d{9}|\d{12})$/', $number) === 1 ? $number : null;
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line): ?string => Data::string($address, $line),
            ['line1', 'line2', 'line3', 'line4', 'line5'],
        )));
        $postcode = Data::string($address, 'postcode');

        if ($lines === [] && $postcode === null) {
            return null;
        }

        return new Address(
            street: $lines === [] ? null : implode(', ', $lines),
            postalCode: $postcode,
            country: Data::string($address, 'countryCode') ?? 'GB',
        );
    }
}
