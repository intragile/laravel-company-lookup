<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Exceptions;

use InvalidArgumentException;

final class UnknownDriverException extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No company lookup driver is registered as [{$key}].");
    }
}
