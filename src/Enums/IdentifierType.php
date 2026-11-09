<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Enums;

/**
 * The kind of identifier a lookup is made with.
 */
enum IdentifierType: string
{
    /** A VAT number, with or without the country prefix. */
    case Vat = 'vat';

    /** A national business register number (company number, IČO, KRS, SIREN, org.nr ...). */
    case Registration = 'registration';

    /** A national tax number that is not a VAT number (e.g. Hungarian adószám, Polish NIP). */
    case Tax = 'tax';

    /** A 20-character ISO 17442 Legal Entity Identifier. */
    case Lei = 'lei';
}
