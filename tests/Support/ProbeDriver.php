<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Support;

use IntrAgile\CompanyLookup\Contracts\Driver;

interface ProbeDriver extends Driver
{
    public function token(): string;
}
