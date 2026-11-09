<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Bulk;

use Illuminate\Http\Client\PendingRequest;

/**
 * A dataset whose file cannot be fetched with a plain GET of sourceUrl(), for example behind a login.
 */
interface DownloadsSource
{
    /**
     * Downloads the dataset file to $target, streaming it to disk.
     *
     * @param  PendingRequest  $request  Pre-configured with the package User-Agent and the bulk download timeout.
     *
     * @throws \RuntimeException When the file cannot be downloaded.
     */
    public function download(PendingRequest $request, string $target): void;
}
