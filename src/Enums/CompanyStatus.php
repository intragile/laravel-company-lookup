<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Enums;

/**
 * Normalised legal status of a company as reported by the registry.
 */
enum CompanyStatus: string
{
    case Active = 'active';

    /** Registered but not trading: suspended, inactive or under liquidation. */
    case Inactive = 'inactive';

    /** Deleted from the register, dissolved or merged away. */
    case Dissolved = 'dissolved';

    /** The source does not report a status. */
    case Unknown = 'unknown';
}
