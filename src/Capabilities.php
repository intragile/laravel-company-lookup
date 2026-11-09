<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup;

use IntrAgile\CompanyLookup\Enums\IdentifierType;

/**
 * What a driver can do. Must be available without any configuration.
 */
final readonly class Capabilities
{
    /**
     * @param  list<string>  $countries  ISO alpha-2 codes, or ['*'] for worldwide coverage.
     * @param  list<IdentifierType>  $identifierTypes
     * @param  array<string, string>  $credentials  Config field => environment variable name, for every required credential.
     * @param  bool  $official  False for community mirrors that are not run by the registry itself.
     * @param  string|null  $attribution  Licence attribution the data owner requires, if any.
     * @param  string|null  $registryUrl  Public web page of the registry.
     * @param  string|null  $sample  A known identifier that the live self-test can look up.
     * @param  string|null  $sampleCountry  Country of the sample; defaults to the first listed country.
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $countries,
        public array $identifierTypes,
        public bool $nameSearch,
        public array $credentials = [],
        public bool $official = true,
        public ?string $attribution = null,
        public ?string $registryUrl = null,
        public ?string $sample = null,
        public ?string $sampleCountry = null,
    ) {}

    /**
     * The country to use with the sample identifier.
     */
    public function sampleCountry(): string
    {
        return $this->sampleCountry ?? ($this->countries[0] === '*' ? 'DE' : $this->countries[0]);
    }

    public function supportsCountry(string $country): bool
    {
        $country = strtoupper(trim($country));

        if ($country === '') {
            return false;
        }

        return in_array('*', $this->countries, true) || in_array($country, $this->countries, true);
    }

    public function supportsType(IdentifierType $type): bool
    {
        return in_array($type, $this->identifierTypes, true);
    }

    public function requiresCredentials(): bool
    {
        return $this->credentials !== [];
    }
}
