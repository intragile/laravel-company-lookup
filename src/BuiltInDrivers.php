<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

/**
 * The drivers that ship with the package, in default priority order.
 * Bulk datasets are registered after the HTTP drivers.
 */
final class BuiltInDrivers
{
    /** @var array<string, class-string<Drivers\AbstractHttpDriver>> */
    public const HTTP = [
        'vies' => Drivers\ViesDriver::class,
        'nav' => Drivers\NavDriver::class,
        'ares' => Drivers\AresDriver::class,
        'rpo' => Drivers\RpoDriver::class,
        'pl-whitelist' => Drivers\PlWhitelistDriver::class,
        'krs' => Drivers\KrsDriver::class,
        'gus-regon' => Drivers\GusRegonDriver::class,
        'anaf' => Drivers\AnafDriver::class,
        'fr-entreprises' => Drivers\FrEntreprisesDriver::class,
        'brreg' => Drivers\BrregDriver::class,
        'prh' => Drivers\PrhDriver::class,
        'swiss-uid' => Drivers\SwissUidDriver::class,
        'companies-house' => Drivers\CompaniesHouseDriver::class,
        'hmrc' => Drivers\HmrcDriver::class,
        'cro' => Drivers\CroDriver::class,
        'cvr' => Drivers\CvrDriver::class,
        'bolagsverket' => Drivers\BolagsverketDriver::class,
        'sudreg' => Drivers\SudregDriver::class,
        'gemi' => Drivers\GemiDriver::class,
        'sg-acra' => Drivers\SgAcraDriver::class,
        'tw-gcis' => Drivers\TwGcisDriver::class,
        'il-registrar' => Drivers\IlRegistrarDriver::class,
        'sec-edgar' => Drivers\SecEdgarDriver::class,
        'abn' => Drivers\AbnDriver::class,
        'nzbn' => Drivers\NzbnDriver::class,
        'houjin-bangou' => Drivers\HoujinBangouDriver::class,
        'ised' => Drivers\IsedDriver::class,
        'brasilapi' => Drivers\BrasilApiDriver::class,
        'gleif' => Drivers\GleifDriver::class,
    ];

    /** @var array<string, string> Bulk datasets answered from the local table after `company-lookup:import`. */
    public const BULK = [
        'lv-ur' => Bulk\Datasets\LatviaUr::class,
        'ee-rik' => Bulk\Datasets\EstoniaRik::class,
        'be-kbo' => Bulk\Datasets\BelgiumKbo::class,
    ];
}
