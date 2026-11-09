# Laravel Company Lookup

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/laravel-company-lookup/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/laravel-company-lookup/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/laravel-company-lookup.svg)](https://packagist.org/packages/intragile/laravel-company-lookup)
[![PHP](https://img.shields.io/packagist/php-v/intragile/laravel-company-lookup.svg)](https://packagist.org/packages/intragile/laravel-company-lookup)
[![License](https://img.shields.io/packagist/l/intragile/laravel-company-lookup.svg)](LICENSE)

Look up companies in **free official business registries** through one Laravel API, and get the
same `Company` object back from every one of them.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

- **32 sources, 39 countries, one interface.** EU VIES for VAT numbers in all 27 member states,
  GLEIF for Legal Entity Identifiers worldwide, and national registers for 27 countries,
  including the Czech Republic, Slovakia, Poland, Romania, France, Norway, Finland, Switzerland,
  the UK, Hungary, Denmark, Sweden, Japan, Australia and the US.
- **Only free sources.** About half need no key at all. The others are free once you register;
  you add your own key to `.env`, and until then the driver reports itself as not configured
  instead of failing.
- **Nothing throws for an answer.** Every source returns *found*, *not found*, *failed* (with a
  reason and whether to retry) or *unconfigured*. One registry being down never hides the others.
- **Production details handled.** Caching of definitive answers only, local rate limits for the
  strict registries (Slovak RPO, Polish white list, SEC), one retry on transient errors, no
  `ext-soap` for the SOAP registries, and a `company-lookup:doctor` command that tells you which
  drivers are ready.
- **Tested against the real services.** Responses of every keyless source were recorded live, and
  `composer test:e2e` looks up a known company in each of them.
- **Built for tests.** `CompanyLookup::fake()` answers from a map and records what was asked.

## Installation

```bash
composer require intragile/laravel-company-lookup
php artisan vendor:publish --tag=company-lookup-config   # optional
```

Requires PHP 8.3+ and Laravel 12 or 13. The service provider and the `CompanyLookup` facade are
discovered automatically.

## Usage

```php
use IntrAgile\CompanyLookup\Laravel\Facades\CompanyLookup;

// A VAT number with its country prefix: VIES, plus the national register if it covers VAT.
$company = CompanyLookup::vat('CZ45274649')->first();
$company->name;                       // "ČEZ, a. s."
$company->address?->format();         // "Duhová 1444/2, PRAHA 4 - MICHLE, 140 00 PRAHA 4"
$company->vatValid;                   // true

// A register number in a country. The type is guessed when you leave it out.
$outcome = CompanyLookup::lookup('NO', '923609016');
$outcome->first()?->status;           // CompanyStatus::Active
$outcome->first()?->activities[0]->code;  // "06.100" (NACE)

// Say the type when it matters.
use IntrAgile\CompanyLookup\Enums\IdentifierType;
CompanyLookup::lookup('HU', '10773381', IdentifierType::Tax);

// Name search, in every source that supports it for the country (and GLEIF).
foreach (CompanyLookup::search('FI', 'nokia', limit: 5)->companies() as $company) {
    echo $company->name, ' ', $company->identifier(IdentifierType::Registration), PHP_EOL;
}

// Ask every source instead of stopping at the first hit, then combine the records.
$merged = CompanyLookup::all()->lookup('CZ', '45274649')->merged();

// Only some drivers.
CompanyLookup::using('ares')->lookup('CZ', '45274649');
```

### The result

`lookup()`, `vat()` and `search()` return a `LookupOutcome`:

| Method | Returns |
|---|---|
| `first()` | The first `Company` found, or `null` |
| `companies()` | Every `Company` found |
| `merged()` | The first company with missing fields filled from the other records of the same country |
| `found()` | Whether anything was found |
| `failedSources()` / `failures()` | Sources that did not answer, with `reason` and `retryable` |
| `unconfiguredSources()` | Sources skipped because their key is missing |
| `isComplete()` | `true` when every source gave a definitive answer |
| `registryUrl` | A page to search by hand, set when no source covers the country |

A `Company` has `name`, `country`, `source`, `identifiers` (VAT, registration, tax, LEI, each with
its national scheme such as `CZ-ICO`), `address` (structured where the registry provides it),
`status` (`active`, `inactive`, `dissolved`, `unknown`), `legalForm` and `legalFormCode`,
`activities` (NACE, SIC, CAEN, …), `foundedAt`, `vatValid` and `raw` (the decoded source
response). Fields a source does not provide are `null`. `toArray()` gives snake_case keys.

**What "found" means for a VAT number:** a lookup with a VAT number counts as found only when the
number is valid. A register-number lookup of the same company still returns it, with
`vatValid: false`.

### Testing your code

```php
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Laravel\Facades\CompanyLookup;

$fake = CompanyLookup::fake([
    'HU:10773381'      => new Company('Magyar Telekom Nyrt.', 'HU', 'fake'),
    'search:CZ:čez'    => [new Company('ČEZ, a. s.', 'CZ', 'fake')],
    '*'                => null,   // everything else: not found
]);

// ... run your code ...

$fake->assertLookedUp('HU', 'HU 10773381');   // identifiers are compared normalised
$fake->assertSearched('CZ', 'ČEZ');
```

A value can also be a `LookupResult`, for example
`LookupResult::failed('fake', FailureReason::Timeout, retryable: true)`.

### Artisan

```bash
php artisan company-lookup:doctor           # which drivers are ready, which need a key
php artisan company-lookup:doctor --live    # also look up each driver's sample company
php artisan company-lookup:lookup CZ 45274649
php artisan company-lookup:lookup FI nokia --name --json
```

## Sources

✅ live: tested against the real service with recorded responses and an e2e test.
📄 docs: built and tested against the official documentation; **not yet run with a real key**.
If you run one of these with your key, please open an issue with the result.

### No key needed

| Driver | Country | Identifiers | Name search | Verified |
|---|---|---|---|---|
| `vies` | EU-27 + XI | VAT | – | ✅ live |
| `gleif` | worldwide | LEI, register no. | ✅ | ✅ live |
| `ares` | CZ | IČO, DIČ | ✅ | ✅ live |
| `rpo` | SK | IČO | ✅ | ✅ live |
| `pl-whitelist` | PL | NIP, VAT | – | ✅ live |
| `krs` | PL | KRS | – | ✅ live |
| `anaf` | RO | CUI, VAT | – | ✅ live |
| `fr-entreprises` | FR | SIREN, SIRET | ✅ | ✅ live |
| `brreg` | NO | org.nr, MVA | ✅ | ✅ live |
| `prh` | FI | Y-tunnus, VAT | ✅ | ✅ live |
| `swiss-uid` | CH | UID, MWST/TVA | ✅ | ✅ live |
| `sg-acra` | SG | UEN | ✅ | ✅ live |
| `tw-gcis` | TW | 統一編號 | ✅ (active companies) | ✅ live |
| `il-registrar` | IL | company no. | ✅ | ✅ live |
| `sec-edgar` | US (SEC filers) | CIK | ✅ | ✅ live — needs a contact User-Agent, see below |
| `brasilapi` | BR | CNPJ | – | ✅ live — **community mirror, disabled by default** |

### Free, with your own key

| Driver | Country | Identifiers | Name search | Get a key | Verified |
|---|---|---|---|---|---|
| `nav` | HU | adószám, VAT | – | Your NAV Online Számla technical user, via [`intragile/laravel-nav-online-szamla`](https://github.com/intragile/laravel-nav-online-szamla) | 📄 docs |
| `companies-house` | GB | company number | ✅ | [Companies House developer hub](https://developer.company-information.service.gov.uk/), instant | 📄 docs |
| `hmrc` | GB | VAT | – | [HMRC Developer Hub](https://developer.service.hmrc.gov.uk/), app subscribed to *Check a UK VAT number* | 📄 docs |
| `gus-regon` | PL | REGON, NIP | – | Request a key from [GUS](https://api.stat.gov.pl/Home/RegonApi); the public test key works with `COMPANY_LOOKUP_GUS_REGON_SANDBOX=true` | ✅ sandbox |
| `cro` | IE | company no. | ✅ | [CRO Open Services](https://services.cro.ie/) | 📄 docs |
| `cvr` | DK | CVR, VAT | ✅ | System-to-system access from [Erhvervsstyrelsen](https://erhvervsstyrelsen.dk/kom-godt-igang-med-elasticSearch) (by e-mail) | 📄 docs |
| `bolagsverket` | SE | org.nr, VAT | – | [Bolagsverket API portal](https://portal.api.bolagsverket.se/), *Värdefulla datamängder* | 📄 docs |
| `sudreg` | HR | MBS, OIB | – | [sudreg-data.gov.hr](https://sudreg-data.gov.hr/) | 📄 docs |
| `gemi` | GR | GEMI no., AFM | ✅ | [GEMI open data API](https://opendata-api.businessportal.gr/) | 📄 docs |
| `abn` | AU | ABN, ACN | ✅ | [ABN Lookup web services](https://abr.business.gov.au/Tools/WebServices) (GUID) | 📄 docs |
| `nzbn` | NZ | NZBN | ✅ | [api.business.govt.nz](https://api.business.govt.nz/) | 📄 docs |
| `houjin-bangou` | JP | 法人番号 | ✅ | [NTA Web-API application](https://www.houjin-bangou.nta.go.jp/webapi/) (reviewed, by e-mail) | 📄 docs |
| `ised` | CA (federal) | corporation no., BN | – | [ISED API Store](https://api.ised-isde.canada.ca/) account | 📄 docs |

The environment variable names are in [`config/company-lookup.php`](config/company-lookup.php), for
example `COMPANY_LOOKUP_COMPANIES_HOUSE_KEY` and `COMPANY_LOOKUP_ABN_GUID`. `company-lookup:doctor`
lists exactly what each driver is missing.

### Downloaded datasets (opt-in)

Latvia, Estonia and Belgium publish their registers only as files. With bulk enabled, the package
imports them into a local table and answers from there.

| Driver | Country | Source | Verified |
|---|---|---|---|
| `lv-ur` | LV | Register of Enterprises open data (CC0), no key | ✅ real file (490k rows) |
| `ee-rik` | EE | e-Business Register open data, no key | ✅ real file (381k rows) |
| `be-kbo` | BE | KBO Open Data, [free registration](https://kbopub.economie.fgov.be/kbo-open-data/) | 📄 docs |

```bash
COMPANY_LOOKUP_BULK_ENABLED=true
php artisan migrate
php artisan company-lookup:import            # all enabled datasets
```

Schedule the import daily (`Schedule::command('company-lookup:import')->dailyAt('04:00')`). An
import that yields fewer than 90% of the stored rows is refused, so a truncated download cannot
wipe your data; `--force` overrides that.

### Not covered

Germany, Austria, the Netherlands, Italy, Spain and the Hungarian company register have no free
official API. For these countries `vies` still validates VAT numbers, and `registryUrl` points to
the registry's own search page.

## Configuration

All options are in [`config/company-lookup.php`](config/company-lookup.php):

| Option | Default | Meaning |
|---|---|---|
| `timeout` | 8 | Seconds per registry call |
| `user_agent` | package name and URL | Sent with every call |
| `strategy` | `first` | `first`: stop at the first source that finds the company; `all`: ask every source |
| `cache.enabled` / `store` / `ttl` | `true` / default store / 86400 | Only found and not-found answers are cached |
| `rate_limits` | strict registries | `[max attempts, per seconds]` per driver; over the limit the driver is skipped with a retryable failure |
| `registries` | | Manual search pages for countries without a driver |
| `drivers.<key>.enabled` | `true` (`brasilapi`: `false`) | Turn a driver off; `using('key')` still reaches it |
| `bulk.*` | disabled | Downloaded datasets, see above |

Add your own source with `CompanyLookup::extend('key', fn () => new MyDriver(...))`; a driver
implements `IntrAgile\CompanyLookup\Contracts\Driver`, and HTTP drivers can extend
`IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver`.

## Good to know

- **Germany and Spain never disclose name or address in VIES.** The lookup confirms the number is
  valid and leaves `name` and `address` empty.
- **SEC EDGAR asks for a User-Agent with a contact.** Set
  `COMPANY_LOOKUP_SEC_EDGAR_USER_AGENT="Your Company admin@example.com"`; without it the driver
  stays unconfigured.
- **The Danish CVR endpoint is plain HTTP**, as the registry documents it; its HTTPS host does not
  answer. Your CVR username and password therefore travel unencrypted. If that is unacceptable,
  route it through your own TLS proxy with `drivers.cvr.base_url`.
- **BrasilAPI is not run by the Brazilian government.** It mirrors Receita Federal data and is off
  until you enable it.
- **Names stay in the registry's language,** except Japan and Israel, where the registered English
  name is used when there is one (the original is in `raw`).
- **Respect the registries' terms.** Several require attribution (CC BY, NLOD, Licence Ouverte,
  OGL); each driver's `capabilities()->attribution` holds the required text.
- **Whether you may rely on a lookup** for a legal check (KYC, VAT invoicing) is your decision; the
  package returns what the registry says at that moment.

## Development

```bash
composer test          # unit, feature and driver tests, offline
composer test:e2e      # every keyless driver against its live service
composer analyse       # PHPStan, level max
composer format        # Laravel Pint
```

Run a subset live with `COMPANY_LOOKUP_E2E_DRIVERS=vies,ares composer test:e2e`.

## License

MIT. See [LICENSE](LICENSE). Registry data belongs to the respective registries and is subject to
their licences.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
