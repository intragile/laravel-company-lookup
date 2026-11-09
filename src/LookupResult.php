<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

use IntrAgile\CompanyLookup\Enums\FailureReason;

/**
 * The answer of one source. Drivers return this instead of throwing.
 */
final readonly class LookupResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    public const UNCONFIGURED = 'unconfigured';

    /**
     * @param  list<Company>  $companies
     */
    private function __construct(
        public string $source,
        public string $state,
        public array $companies = [],
        public ?FailureReason $reason = null,
        public bool $retryable = false,
        public ?string $message = null,
    ) {}

    public static function found(string $source, Company ...$companies): self
    {
        return $companies === []
            ? self::notFound($source)
            : new self($source, self::FOUND, array_values($companies));
    }

    /**
     * The source answered and has no matching company.
     */
    public static function notFound(string $source): self
    {
        return new self($source, self::NOT_FOUND);
    }

    /**
     * The source did not answer usefully.
     */
    public static function failed(string $source, FailureReason $reason, bool $retryable, ?string $message = null): self
    {
        return new self($source, self::FAILED, reason: $reason, retryable: $retryable, message: $message);
    }

    /**
     * The driver needs credentials or data that are not set up.
     *
     * @param  string  $missing  What is missing, usually environment variable names.
     */
    public static function unconfigured(string $source, string $missing): self
    {
        return new self($source, self::UNCONFIGURED, message: $missing);
    }

    public function isFound(): bool
    {
        return $this->state === self::FOUND;
    }

    public function isNotFound(): bool
    {
        return $this->state === self::NOT_FOUND;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }

    public function isUnconfigured(): bool
    {
        return $this->state === self::UNCONFIGURED;
    }

    /**
     * Only definitive answers may be cached.
     */
    public function isCacheable(): bool
    {
        return $this->isFound() || $this->isNotFound();
    }

    public function first(): ?Company
    {
        return $this->companies[0] ?? null;
    }
}
