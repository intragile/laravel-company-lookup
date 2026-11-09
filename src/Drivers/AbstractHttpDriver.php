<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Drivers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use IntrAgile\CompanyLookup\Contracts\Driver;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\LookupResult;
use RuntimeException;
use Throwable;

/**
 * Base class for drivers that call an HTTP API through the Laravel HTTP client.
 */
abstract class AbstractHttpDriver implements Driver
{
    /** The driver key, unique across all drivers. */
    public const string KEY = '';

    public const DEFAULT_USER_AGENT = 'intragile-laravel-company-lookup/1.x (+https://github.com/intragile/laravel-company-lookup)';

    /**
     * @param  array<string, mixed>  $config  This driver's config section (company-lookup.drivers.<key>).
     * @param  array{timeout?: int|float, user_agent?: string}  $options  Package-wide HTTP options.
     */
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
        protected ?CacheRepository $cache = null,
        protected array $options = [],
    ) {}

    public function isConfigured(): bool
    {
        foreach (array_keys($this->capabilities()->credentials) as $field) {
            if ($this->setting($field) === null) {
                return false;
            }
        }

        return true;
    }

    public function search(string $country, string $name, int $limit): LookupResult
    {
        return LookupResult::notFound(static::KEY);
    }

    /**
     * A string config value of this driver, or null when unset or empty.
     */
    protected function setting(string $field, ?string $default = null): ?string
    {
        $value = $this->config[$field] ?? null;

        if (is_bool($value) || ! is_scalar($value) || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    protected function flag(string $field, bool $default = false): bool
    {
        $value = $this->config[$field] ?? null;

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * The configured base URL override, or the given default, without a trailing slash.
     */
    protected function baseUrl(string $default): string
    {
        return rtrim($this->setting('base_url', $default) ?? $default, '/');
    }

    /**
     * A request with timeout, User-Agent and one retry on connection errors, 429 and 5xx.
     * Responses are not thrown; use failure() on unsuccessful ones.
     */
    protected function request(): PendingRequest
    {
        $timeout = (float) ($this->options['timeout'] ?? 8);
        $userAgent = $this->options['user_agent'] ?? self::DEFAULT_USER_AGENT;

        return $this->http
            ->timeout((int) max(1, ceil($timeout)))
            ->connectTimeout((int) max(1, min(5, ceil($timeout))))
            ->withUserAgent($userAgent)
            ->retry(2, 300, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && (($exception->response->status() === 429 && $this->retriesOnRateLimit()) || $exception->response->serverError());
            }, throw: false);
    }

    /**
     * Whether a 429 answer is retried once. Drivers whose source counts every request
     * against a strict or daily quota return false.
     */
    protected function retriesOnRateLimit(): bool
    {
        return true;
    }

    /**
     * Runs the call and turns exceptions into failed results.
     *
     * @param  callable(): LookupResult  $call
     */
    protected function guard(callable $call): LookupResult
    {
        try {
            return $call();
        } catch (ConnectionException $exception) {
            return LookupResult::failed(static::KEY, FailureReason::Timeout, true, $exception->getMessage());
        } catch (RequestException $exception) {
            return $this->failure($exception->response);
        } catch (Throwable $exception) {
            return LookupResult::failed(static::KEY, FailureReason::InvalidResponse, false, $exception->getMessage());
        }
    }

    /**
     * Maps an unsuccessful response to a failed result.
     */
    protected function failure(Response $response, ?string $message = null): LookupResult
    {
        $message ??= 'HTTP '.$response->status();

        return match (true) {
            $response->status() === 429 => LookupResult::failed(static::KEY, FailureReason::RateLimited, true, $message),
            $response->serverError() => LookupResult::failed(static::KEY, FailureReason::Unavailable, true, $message),
            in_array($response->status(), [400, 401, 403, 422], true) => LookupResult::failed(static::KEY, FailureReason::Rejected, false, $message),
            default => LookupResult::failed(static::KEY, FailureReason::InvalidResponse, false, $message),
        };
    }

    /**
     * Decoded JSON body as an array.
     *
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException When the body is not a JSON object or array.
     */
    protected function json(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The response is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Drops a cached client-credentials token, e.g. after the API answered 401 with it.
     */
    protected function forgetClientCredentialsToken(string $tokenUrl, string $clientId, ?string $scope = null): void
    {
        $this->cache?->forget($this->tokenCacheKey($tokenUrl, $clientId, $scope));
    }

    private function tokenCacheKey(string $tokenUrl, string $clientId, ?string $scope): string
    {
        return 'company-lookup:token:'.static::KEY.':'.sha1($tokenUrl.'|'.$clientId.'|'.$scope);
    }

    /**
     * An OAuth2 client-credentials access token, cached until shortly before it expires.
     * The client authenticates with HTTP Basic, or with form fields when $basicAuth is false.
     *
     * @throws RuntimeException When the token endpoint does not return a token.
     */
    protected function clientCredentialsToken(string $tokenUrl, string $clientId, string $clientSecret, ?string $scope = null, bool $basicAuth = true): string
    {
        $cacheKey = $this->tokenCacheKey($tokenUrl, $clientId, $scope);

        $cached = $this->cache?->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $form = ['grant_type' => 'client_credentials'];
        if ($scope !== null) {
            $form['scope'] = $scope;
        }

        $request = $this->request()->asForm();
        if ($basicAuth) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $form['client_id'] = $clientId;
            $form['client_secret'] = $clientSecret;
        }

        $response = $request->post($tokenUrl, $form);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $payload = $this->json($response);
        $token = $payload['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('The token endpoint did not return an access token.');
        }

        $expiresIn = $payload['expires_in'] ?? 3600;
        $ttl = max(30, (is_numeric($expiresIn) ? (int) $expiresIn : 3600) - 60);
        $this->cache?->put($cacheKey, $token, $ttl);

        return $token;
    }
}
