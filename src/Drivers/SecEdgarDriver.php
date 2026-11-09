<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\ActivityCode;
use IntrAgile\CompanyLookup\Address;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Drivers\Support\Normalize;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use Throwable;

/**
 * US SEC EDGAR company submissions. Free, no key, but the SEC requires a User-Agent that identifies
 * the caller with a contact address, so the driver stays unconfigured until one is set.
 *
 * Only entities that file with the SEC are covered. Name search matches the titles in the SEC
 * ticker list (listed companies only) on whole words, which is downloaded once and cached for a day.
 */
final class SecEdgarDriver extends AbstractHttpDriver
{
    public const string KEY = 'sec-edgar';

    public const string USER_AGENT_ENV = 'COMPANY_LOOKUP_SEC_EDGAR_USER_AGENT';

    private const TICKERS_TTL = 86400;

    /** EDGAR location codes of US states, DC, territories and "X1" (United States). */
    private const US_CODES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME',
        'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI',
        'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'PR', 'VI', 'GU', 'AS', 'MP', 'UM', 'X1',
    ];

    /** Non-US EDGAR location codes: Canadian provinces and common countries. */
    private const EDGAR_COUNTRIES = [
        'A0' => 'CA', 'A1' => 'CA', 'A2' => 'CA', 'A3' => 'CA', 'A4' => 'CA', 'A5' => 'CA', 'A6' => 'CA', 'A7' => 'CA',
        'A8' => 'CA', 'A9' => 'CA', 'B0' => 'CA', 'Z4' => 'CA', 'L2' => 'IE', 'X0' => 'GB',
    ];

    /** Country names as EDGAR describes locations, lowercased. */
    private const COUNTRY_NAMES = [
        'ireland' => 'IE', 'united kingdom' => 'GB', 'canada' => 'CA', 'germany' => 'DE', 'france' => 'FR', 'netherlands' => 'NL',
        'switzerland' => 'CH', 'luxembourg' => 'LU', 'belgium' => 'BE', 'spain' => 'ES', 'italy' => 'IT', 'sweden' => 'SE',
        'denmark' => 'DK', 'norway' => 'NO', 'finland' => 'FI', 'greece' => 'GR', 'cyprus' => 'CY', 'israel' => 'IL',
        'bermuda' => 'BM', 'cayman islands' => 'KY', 'bahamas' => 'BS', 'british virgin islands' => 'VG', 'virgin islands, british' => 'VG',
        'jersey' => 'JE', 'guernsey' => 'GG', 'isle of man' => 'IM', 'marshall islands' => 'MH', 'panama' => 'PA', 'japan' => 'JP',
        'china' => 'CN', 'hong kong' => 'HK', 'taiwan' => 'TW', 'singapore' => 'SG', 'india' => 'IN', 'australia' => 'AU',
        'brazil' => 'BR', 'mexico' => 'MX', 'argentina' => 'AR', 'chile' => 'CL',
    ];

    /** @var list<array{cik: string, title: string, tickers: list<string>}>|null */
    private ?array $tickers = null;

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            key: self::KEY,
            label: 'US SEC EDGAR',
            countries: ['US'],
            identifierTypes: [IdentifierType::Registration],
            nameSearch: true,
            credentials: ['user_agent' => self::USER_AGENT_ENV],
            registryUrl: 'https://www.sec.gov/edgar/search/',
            sample: '0000320193',
            sampleCountry: 'US',
        );
    }

    public function lookup(string $country, Identifier $identifier): LookupResult
    {
        if (! $this->isConfigured()) {
            return LookupResult::unconfigured(self::KEY, self::USER_AGENT_ENV);
        }

        $cik = $this->cik($identifier->value);

        if (strtoupper($country) !== 'US' || $cik === null) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($cik): LookupResult {
            $response = $this->secRequest()
                ->acceptJson()
                ->get($this->baseUrl('https://data.sec.gov').'/submissions/CIK'.$cik.'.json');

            if ($response->status() === 404) {
                return LookupResult::notFound(self::KEY);
            }

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $data = $this->json($response);
            if (Data::string($data, 'name') === null) {
                return LookupResult::notFound(self::KEY);
            }

            return LookupResult::found(self::KEY, $this->company($cik, $data));
        });
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        if (! $this->isConfigured()) {
            return LookupResult::unconfigured(self::KEY, self::USER_AGENT_ENV);
        }

        $needle = Normalize::name($name);

        if (strtoupper($country) !== 'US' || $needle === '' || $limit < 1) {
            return LookupResult::notFound(self::KEY);
        }

        return $this->guard(function () use ($needle, $limit): LookupResult {
            $matches = [];
            foreach ($this->tickers() as $entry) {
                $title = Normalize::name($entry['title']);
                $rank = match (true) {
                    $title === $needle => 0,
                    str_starts_with($title, $needle.' ') => 1,
                    str_contains(' '.$title.' ', ' '.$needle.' ') => 2,
                    default => null,
                };
                if ($rank !== null) {
                    $matches[] = ['rank' => $rank, 'entry' => $entry];
                }
            }

            usort($matches, fn (array $a, array $b): int => [$a['rank'], strlen($a['entry']['title'])] <=> [$b['rank'], strlen($b['entry']['title'])]);

            $companies = array_map(fn (array $match): Company => new Company(
                name: $match['entry']['title'],
                country: 'US',
                source: self::KEY,
                identifiers: [Identifier::registration($match['entry']['cik'], 'US-CIK')],
                raw: $match['entry'],
            ), array_slice($matches, 0, $limit));

            return $companies === [] ? LookupResult::notFound(self::KEY) : LookupResult::found(self::KEY, ...$companies);
        });
    }

    /**
     * The CIK left-padded to 10 digits, or null when the input is not a CIK.
     */
    private function cik(string $value): ?string
    {
        $value = Normalize::compact($value);
        $value = str_starts_with($value, 'CIK') ? substr($value, 3) : $value;
        $digits = ltrim($value, '0');

        if (preg_match('/^\d{1,10}$/', $value) !== 1 || $digits === '') {
            return null;
        }

        return str_pad($digits, 10, '0', STR_PAD_LEFT);
    }

    /**
     * A request that identifies the caller as the SEC fair-access policy requires.
     */
    private function secRequest(): PendingRequest
    {
        return $this->request()->withUserAgent((string) $this->setting('user_agent'));
    }

    /**
     * The SEC ticker list (CIK, title, tickers), grouped by CIK and cached for a day.
     *
     * @return list<array{cik: string, title: string, tickers: list<string>}>
     *
     * @throws RequestException When the download fails.
     */
    private function tickers(): array
    {
        if ($this->tickers !== null) {
            return $this->tickers;
        }

        $url = $this->setting('tickers_url') ?? 'https://www.sec.gov/files/company_tickers.json';
        $cacheKey = 'company-lookup:'.self::KEY.':tickers:'.sha1($url);

        $cached = $this->decodeTickers($this->cacheGet($cacheKey));
        if ($cached !== []) {
            return $this->tickers = $cached;
        }

        $response = $this->secRequest()->acceptJson()->get($url);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $grouped = [];
        foreach (Data::arrays($this->json($response)) as $row) {
            $cik = Data::int($row, 'cik_str');
            $title = Data::string($row, 'title');
            if ($cik === null || $title === null) {
                continue;
            }

            $key = str_pad((string) $cik, 10, '0', STR_PAD_LEFT);
            $grouped[$key] ??= ['cik' => $key, 'title' => $title, 'tickers' => []];
            $ticker = Data::string($row, 'ticker');
            if ($ticker !== null) {
                $grouped[$key]['tickers'][] = $ticker;
            }
        }

        $tickers = array_values($grouped);
        $this->cachePut($cacheKey, $this->encodeTickers($tickers));

        return $this->tickers = $tickers;
    }

    /**
     * Packs the ticker list as base64 of gzip-compressed JSON rows [cik, title, tickers],
     * which keeps the cache entry around 250 KB and safe for any store.
     *
     * @param  list<array{cik: string, title: string, tickers: list<string>}>  $tickers
     */
    private function encodeTickers(array $tickers): ?string
    {
        $rows = array_map(fn (array $entry): array => [(int) $entry['cik'], $entry['title'], implode(' ', $entry['tickers'])], $tickers);
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compressed = $json === false ? false : gzcompress($json, 6);

        return $compressed === false ? null : base64_encode($compressed);
    }

    /**
     * Unpacks a cached ticker list; anything unreadable counts as a cache miss.
     *
     * @return list<array{cik: string, title: string, tickers: list<string>}>
     */
    private function decodeTickers(mixed $cached): array
    {
        if (! is_string($cached) || $cached === '') {
            return [];
        }

        $compressed = base64_decode($cached, true);
        $json = $compressed === false ? false : @gzuncompress($compressed);
        if ($json === false) {
            return [];
        }

        $entries = [];
        foreach (Data::arrays(Data::json($json)) as $row) {
            $cik = Data::int($row, '0');
            $title = Data::string($row, '1');
            if ($cik !== null && $title !== null) {
                $tickers = preg_split('/\s+/', Data::string($row, '2') ?? '', -1, PREG_SPLIT_NO_EMPTY);
                $entries[] = ['cik' => str_pad((string) $cik, 10, '0', STR_PAD_LEFT), 'title' => $title, 'tickers' => $tickers === false ? [] : $tickers];
            }
        }

        return $entries;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache?->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stores a value, ignoring cache failures such as an item size limit.
     */
    private function cachePut(string $key, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            $this->cache?->put($key, $value, self::TICKERS_TTL);
        } catch (Throwable) {
            // Search still works without the cache, it just downloads the list again.
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function company(string $cik, array $data): Company
    {
        $identifiers = [Identifier::registration($cik, 'US-CIK')];

        $ein = Normalize::digits(Data::string($data, 'ein') ?? '');
        if (strlen($ein) === 9 && $ein !== '000000000') {
            $identifiers[] = Identifier::tax($ein, 'US-EIN');
        }

        $lei = Normalize::compact(Data::string($data, 'lei') ?? '');
        if (preg_match('/^[A-Z0-9]{18}\d{2}$/', $lei) === 1) {
            $identifiers[] = Identifier::lei($lei);
        }

        $sic = Data::string($data, 'sic');
        unset($data['filings']);

        return new Company(
            name: Data::string($data, 'name'),
            country: 'US',
            source: self::KEY,
            identifiers: $identifiers,
            address: $this->address(Data::map($data, 'addresses.business')),
            activities: $sic === null ? [] : [new ActivityCode('SIC', $sic, Data::string($data, 'sicDescription'))],
            raw: $data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $address
     */
    private function address(array $address): ?Address
    {
        $street = implode(', ', array_filter([Data::string($address, 'street1'), Data::string($address, 'street2')]));
        $city = Data::string($address, 'city');

        if ($street === '' && $city === null) {
            return null;
        }

        $code = strtoupper(Data::string($address, 'stateOrCountry') ?? Data::string($address, 'countryCode') ?? '');

        if ($code !== '' && in_array($code, self::US_CODES, true)) {
            return new Address(
                street: $street === '' ? null : $street,
                postalCode: Data::string($address, 'zipCode'),
                city: $city,
                region: $code === 'X1' ? null : $code,
                country: 'US',
            );
        }

        return new Address(
            street: $street === '' ? null : $street,
            postalCode: Data::string($address, 'zipCode'),
            city: $city,
            region: Data::string($address, 'foreignStateTerritory'),
            country: $this->foreignCountry($code, Data::string($address, 'stateOrCountryDescription')),
        );
    }

    /**
     * ISO country of a non-US EDGAR location code, or null when it is not known.
     */
    private function foreignCountry(string $code, ?string $description): ?string
    {
        if (isset(self::EDGAR_COUNTRIES[$code])) {
            return self::EDGAR_COUNTRIES[$code];
        }

        $name = strtolower(trim((string) preg_replace('/^.*,/', '', $description ?? '')));

        return self::COUNTRY_NAMES[$name] ?? null;
    }

    /**
     * Turns the SEC's 403 throttling answer into a retryable rate-limit failure.
     */
    protected function failure(Response $response, ?string $message = null): LookupResult
    {
        if ($response->status() === 403 && str_contains($response->body(), 'Rate Threshold')) {
            return LookupResult::failed(self::KEY, FailureReason::RateLimited, true, $message ?? 'SEC request rate threshold exceeded.');
        }

        return parent::failure($response, $message);
    }
}
