<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Enums;

/**
 * Why a source could not answer a lookup.
 */
enum FailureReason: string
{
    /** The service is down or returned a server error. */
    case Unavailable = 'unavailable';

    /** The service or the local rate limiter refused the request. */
    case RateLimited = 'rate_limited';

    /** The connection failed or did not complete in time. */
    case Timeout = 'timeout';

    /** The service answered with something that could not be understood. */
    case InvalidResponse = 'invalid_response';

    /** The service refused the request (bad credentials, malformed input). */
    case Rejected = 'rejected';
}
