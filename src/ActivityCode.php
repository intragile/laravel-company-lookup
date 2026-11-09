<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

/**
 * An economic activity classification code, such as NACE, SIC or CAEN.
 */
final readonly class ActivityCode
{
    public function __construct(
        public string $scheme,
        public string $code,
        public ?string $description = null,
    ) {}

    /**
     * @return array{scheme: string, code: string, description: string|null}
     */
    public function toArray(): array
    {
        return ['scheme' => $this->scheme, 'code' => $this->code, 'description' => $this->description];
    }
}
