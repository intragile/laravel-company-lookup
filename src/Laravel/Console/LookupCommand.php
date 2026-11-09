<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\LookupOutcome;
use IntrAgile\CompanyLookup\LookupResult;

final class LookupCommand extends Command
{
    protected $signature = 'company-lookup:lookup
        {country : ISO country code, e.g. HU}
        {identifier : VAT number, register number or LEI}
        {--type= : vat, registration, tax or lei (guessed when omitted)}
        {--driver=* : Only ask these drivers}
        {--name : Treat the identifier as a company name and search}
        {--json : Print the result as JSON}';

    protected $description = 'Look up a company in the configured registries';

    public function handle(CompanyLookup $lookup): int
    {
        $country = $this->stringArgument('country');
        $identifier = $this->stringArgument('identifier');

        $typeOption = $this->option('type');
        $type = is_string($typeOption) && $typeOption !== '' ? IdentifierType::tryFrom(strtolower($typeOption)) : null;
        if (is_string($typeOption) && $typeOption !== '' && $type === null) {
            $this->error("Unknown identifier type [{$typeOption}]. Use vat, registration, tax or lei.");

            return self::INVALID;
        }

        $drivers = array_values(array_filter((array) $this->option('driver'), 'is_string'));
        if ($drivers !== []) {
            $lookup = $lookup->using(...$drivers);
        }

        $outcome = $this->option('name')
            ? $lookup->search($country, $identifier)
            : $lookup->lookup($country, $identifier, $type);

        if ($this->option('json')) {
            $this->line((string) json_encode($this->toArray($outcome), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $outcome->found() ? self::SUCCESS : self::FAILURE;
        }

        foreach ($outcome->results as $result) {
            $this->line(sprintf('<comment>%s</comment>: %s', $result->source, $this->describe($result)));
        }

        foreach ($outcome->companies() as $company) {
            $this->newLine();
            $this->table(['Field', 'Value'], array_map(
                fn (string $field, mixed $value): array => [$field, is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE)],
                array_keys($company->toArray()),
                array_values($company->toArray()),
            ));
        }

        if ($outcome->registryUrl !== null) {
            $this->warn("No driver covers {$country}. Search manually: {$outcome->registryUrl}");
        }

        return $outcome->found() ? self::SUCCESS : self::FAILURE;
    }

    private function describe(LookupResult $result): string
    {
        return match ($result->state) {
            LookupResult::FOUND => count($result->companies).' found',
            LookupResult::NOT_FOUND => 'not found',
            LookupResult::UNCONFIGURED => 'not configured (missing: '.$result->message.')',
            default => 'failed: '.($result->reason !== null ? $result->reason->value : 'unknown').($result->message !== null ? ' ('.$result->message.')' : ''),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(LookupOutcome $outcome): array
    {
        return [
            'companies' => array_map(fn ($company): array => $company->toArray(), $outcome->companies()),
            'sources' => array_map(fn (LookupResult $result): array => [
                'source' => $result->source,
                'state' => $result->state,
                'reason' => $result->reason?->value,
                'message' => $result->message,
            ], $outcome->results),
            'registry_url' => $outcome->registryUrl,
        ];
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? trim($value) : '';
    }
}
