<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Laravel\Console;

use Illuminate\Console\Command;
use IntrAgile\CompanyLookup\CompanyLookup;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;

final class DoctorCommand extends Command
{
    protected $signature = 'company-lookup:doctor
        {--live : Look up each ready driver\'s sample identifier}
        {--driver=* : Only check these drivers}';

    protected $description = 'Show which company lookup drivers are ready and which need credentials';

    public function handle(CompanyLookup $lookup): int
    {
        $only = array_values(array_filter((array) $this->option('driver'), 'is_string'));
        $live = (bool) $this->option('live');
        $rows = [];
        $broken = 0;

        foreach ($lookup->drivers() as $key => $driver) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }

            $capabilities = $driver->capabilities();
            $status = match (true) {
                ! $lookup->isEnabled($key) => 'disabled',
                ! $driver->isConfigured() => 'missing: '.implode(', ', $capabilities->credentials),
                default => 'ready',
            };

            $check = '';
            if ($live && $status === 'ready' && $capabilities->sample !== null) {
                $country = $capabilities->sampleCountry();
                $type = $capabilities->identifierTypes[0] ?? null;

                if ($type !== null) {
                    $result = $driver->lookup($country, new Identifier($type, $capabilities->sample));
                    $check = $this->describe($result);
                    $broken += $result->isFound() ? 0 : 1;
                }
            }

            $rows[] = [
                $key,
                implode(',', $capabilities->countries),
                $capabilities->official ? 'official' : 'community',
                $status,
                ...($live ? [$check] : []),
            ];
        }

        $this->table(['Driver', 'Countries', 'Source', 'Status', ...($live ? ['Live check'] : [])], $rows);

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function describe(LookupResult $result): string
    {
        return match ($result->state) {
            LookupResult::FOUND => 'ok: '.($result->first()->name ?? '(no name)'),
            LookupResult::NOT_FOUND => 'sample not found',
            default => 'failed: '.($result->reason !== null ? $result->reason->value : 'unknown').($result->message !== null ? ' - '.mb_strimwidth($result->message, 0, 80, '…') : ''),
        };
    }
}
