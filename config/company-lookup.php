<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for every registry call, and the User-Agent sent with
    | it. Some registries (SEC EDGAR) ask for a contact address in the agent.
    |
    */

    'timeout' => (int) env('COMPANY_LOOKUP_TIMEOUT', 8),

    'user_agent' => env('COMPANY_LOOKUP_USER_AGENT', 'intragile-laravel-company-lookup/1.x (+https://github.com/intragile/laravel-company-lookup)'),

    /*
    |--------------------------------------------------------------------------
    | Strategy
    |--------------------------------------------------------------------------
    |
    | "first": identifier lookups stop at the first driver that finds the
    | company (drivers are asked in the order listed under "drivers").
    | "all": every matching driver is asked; LookupOutcome::merged() then
    | combines the records. Name searches always ask every driver.
    |
    */

    'strategy' => env('COMPANY_LOOKUP_STRATEGY', 'first'),

    'max_results' => 10,

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Only definitive answers (found / not found) are cached. Failures and
    | missing credentials never are.
    |
    */

    'cache' => [
        'enabled' => (bool) env('COMPANY_LOOKUP_CACHE', true),
        'store' => env('COMPANY_LOOKUP_CACHE_STORE'),
        'ttl' => (int) env('COMPANY_LOOKUP_CACHE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Local limits per driver: [max attempts, per seconds]. When reached, the
    | driver is skipped with a retryable "rate_limited" failure instead of
    | being blocked by the registry.
    |
    */

    'rate_limits' => [
        'rpo' => [1, 2],
        'pl-whitelist' => [90, 86400],
        'gleif' => [50, 60],
        'anaf' => [1, 1],
        'sudreg' => [6, 60],
        'sec-edgar' => [8, 1],
        'companies-house' => [500, 300],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual search pages
    |--------------------------------------------------------------------------
    |
    | Returned as LookupOutcome::$registryUrl when no driver covers a country.
    |
    */

    'registries' => [
        'DE' => 'https://www.unternehmensregister.de/',
        'AT' => 'https://www.justiz.gv.at/service/datenbanken/firmenbuch.html',
        'HU' => 'https://www.e-cegjegyzek.hu/',
        'NL' => 'https://www.kvk.nl/zoeken/',
        'IT' => 'https://www.registroimprese.it/',
        'ES' => 'https://www.rmc.es/',
        '*' => 'https://e-justice.europa.eu/topics/registers-business-insolvency-land/business-registers-search-company-eu_en',
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Drivers that need credentials stay inactive (and report "unconfigured")
    | until you add your own free key. Run `php artisan company-lookup:doctor`
    | to see which drivers are ready.
    |
    */

    'drivers' => [
        // Free, no key.
        'vies' => ['enabled' => (bool) env('COMPANY_LOOKUP_VIES_ENABLED', true)],
        'gleif' => ['enabled' => (bool) env('COMPANY_LOOKUP_GLEIF_ENABLED', true)],
        'ares' => ['enabled' => (bool) env('COMPANY_LOOKUP_ARES_ENABLED', true)],
        'rpo' => ['enabled' => (bool) env('COMPANY_LOOKUP_RPO_ENABLED', true)],
        'pl-whitelist' => ['enabled' => (bool) env('COMPANY_LOOKUP_PL_WHITELIST_ENABLED', true)],
        'krs' => ['enabled' => (bool) env('COMPANY_LOOKUP_KRS_ENABLED', true)],
        'anaf' => ['enabled' => (bool) env('COMPANY_LOOKUP_ANAF_ENABLED', true)],
        'fr-entreprises' => ['enabled' => (bool) env('COMPANY_LOOKUP_FR_ENTREPRISES_ENABLED', true)],
        'brreg' => ['enabled' => (bool) env('COMPANY_LOOKUP_BRREG_ENABLED', true)],
        'prh' => ['enabled' => (bool) env('COMPANY_LOOKUP_PRH_ENABLED', true)],
        'swiss-uid' => ['enabled' => (bool) env('COMPANY_LOOKUP_SWISS_UID_ENABLED', true)],
        'sg-acra' => ['enabled' => (bool) env('COMPANY_LOOKUP_SG_ACRA_ENABLED', true)],
        'tw-gcis' => ['enabled' => (bool) env('COMPANY_LOOKUP_TW_GCIS_ENABLED', true)],
        'il-registrar' => ['enabled' => (bool) env('COMPANY_LOOKUP_IL_REGISTRAR_ENABLED', true)],
        'sec-edgar' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_SEC_EDGAR_ENABLED', true),
            // SEC requires a User-Agent with your contact, e.g. "Acme Ltd admin@acme.example".
            'user_agent' => env('COMPANY_LOOKUP_SEC_EDGAR_USER_AGENT'),
        ],
        // Unofficial community mirror of the Brazilian CNPJ data; opt in deliberately.
        'brasilapi' => ['enabled' => (bool) env('COMPANY_LOOKUP_BRASILAPI_ENABLED', false)],

        // Free, with your own credentials.
        'nav' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_NAV_ENABLED', true),
            // Connection name of intragile/laravel-nav-online-szamla; null uses its default.
            'connection' => env('COMPANY_LOOKUP_NAV_CONNECTION'),
        ],
        'hmrc' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_HMRC_ENABLED', true),
            'client_id' => env('COMPANY_LOOKUP_HMRC_CLIENT_ID'),
            'client_secret' => env('COMPANY_LOOKUP_HMRC_CLIENT_SECRET'),
            'sandbox' => (bool) env('COMPANY_LOOKUP_HMRC_SANDBOX', false),
        ],
        'companies-house' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_COMPANIES_HOUSE_ENABLED', true),
            'key' => env('COMPANY_LOOKUP_COMPANIES_HOUSE_KEY'),
        ],
        'gus-regon' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_GUS_REGON_ENABLED', true),
            'key' => env('COMPANY_LOOKUP_GUS_REGON_KEY'),
            'sandbox' => (bool) env('COMPANY_LOOKUP_GUS_REGON_SANDBOX', false),
        ],
        'abn' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_ABN_ENABLED', true),
            'guid' => env('COMPANY_LOOKUP_ABN_GUID'),
        ],
        'nzbn' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_NZBN_ENABLED', true),
            'key' => env('COMPANY_LOOKUP_NZBN_KEY'),
        ],
        'houjin-bangou' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_HOUJIN_BANGOU_ENABLED', true),
            'app_id' => env('COMPANY_LOOKUP_HOUJIN_BANGOU_APP_ID'),
        ],
        'sudreg' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_SUDREG_ENABLED', true),
            'client_id' => env('COMPANY_LOOKUP_SUDREG_CLIENT_ID'),
            'client_secret' => env('COMPANY_LOOKUP_SUDREG_CLIENT_SECRET'),
        ],
        'bolagsverket' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_BOLAGSVERKET_ENABLED', true),
            'client_id' => env('COMPANY_LOOKUP_BOLAGSVERKET_CLIENT_ID'),
            'client_secret' => env('COMPANY_LOOKUP_BOLAGSVERKET_CLIENT_SECRET'),
        ],
        'gemi' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_GEMI_ENABLED', true),
            'key' => env('COMPANY_LOOKUP_GEMI_KEY'),
        ],
        'cro' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_CRO_ENABLED', true),
            'email' => env('COMPANY_LOOKUP_CRO_EMAIL'),
            'key' => env('COMPANY_LOOKUP_CRO_KEY'),
        ],
        'cvr' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_CVR_ENABLED', true),
            'username' => env('COMPANY_LOOKUP_CVR_USERNAME'),
            'password' => env('COMPANY_LOOKUP_CVR_PASSWORD'),
        ],
        'ised' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_ISED_ENABLED', true),
            'key' => env('COMPANY_LOOKUP_ISED_KEY'),
        ],

        // Bulk open data, searched in a local table after `company-lookup:import`.
        'lv-ur' => ['enabled' => (bool) env('COMPANY_LOOKUP_LV_UR_ENABLED', true)],
        'ee-rik' => ['enabled' => (bool) env('COMPANY_LOOKUP_EE_RIK_ENABLED', true)],
        'be-kbo' => [
            'enabled' => (bool) env('COMPANY_LOOKUP_BE_KBO_ENABLED', true),
            'username' => env('COMPANY_LOOKUP_KBO_USERNAME'),
            'password' => env('COMPANY_LOOKUP_KBO_PASSWORD'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk datasets
    |--------------------------------------------------------------------------
    |
    | Latvia, Estonia and Belgium publish their registers only as downloads.
    | Enable this, run the migration and schedule `company-lookup:import`
    | (daily is enough) to look them up locally.
    |
    */

    'bulk' => [
        'enabled' => (bool) env('COMPANY_LOOKUP_BULK_ENABLED', false),
        'connection' => env('COMPANY_LOOKUP_BULK_CONNECTION'),
        'table' => 'company_lookup_records',
    ],
];
