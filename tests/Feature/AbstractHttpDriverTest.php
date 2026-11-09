<?php

declare(strict_types=1);

namespace IntrAgile\CompanyLookup\Tests\Feature;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use IntrAgile\CompanyLookup\Capabilities;
use IntrAgile\CompanyLookup\Company;
use IntrAgile\CompanyLookup\Drivers\AbstractHttpDriver;
use IntrAgile\CompanyLookup\Drivers\Support\Data;
use IntrAgile\CompanyLookup\Enums\FailureReason;
use IntrAgile\CompanyLookup\Enums\IdentifierType;
use IntrAgile\CompanyLookup\Identifier;
use IntrAgile\CompanyLookup\LookupResult;
use IntrAgile\CompanyLookup\Tests\Support\ProbeDriver;
use IntrAgile\CompanyLookup\Tests\TestCase;

final class AbstractHttpDriverTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $config
     */
    private function driver(array $config = []): ProbeDriver
    {
        return new class($this->container()->make(Factory::class), $config, Cache::store(), ['timeout' => 3, 'user_agent' => 'test-agent']) extends AbstractHttpDriver implements ProbeDriver
        {
            public const string KEY = 'probe';

            public function capabilities(): Capabilities
            {
                return new Capabilities(self::KEY, 'Probe', ['HU'], [IdentifierType::Registration], false, ['key' => 'COMPANY_LOOKUP_PROBE_KEY']);
            }

            public function lookup(string $country, Identifier $identifier): LookupResult
            {
                return $this->guard(function (): LookupResult {
                    $response = $this->request()->get($this->baseUrl('https://probe.test').'/x');
                    if (! $response->successful()) {
                        return $this->failure($response);
                    }

                    return LookupResult::found(self::KEY, new Company(Data::string($this->json($response), 'name'), 'HU', self::KEY));
                });
            }

            public function token(): string
            {
                return $this->clientCredentialsToken('https://probe.test/token', 'id', 'secret', 'read');
            }
        };
    }

    public function test_successful_response_and_user_agent(): void
    {
        Http::fake(['probe.test/*' => Http::response(['name' => 'Acme'])]);

        $result = $this->driver(['key' => 'k'])->lookup('HU', Identifier::registration('1'));

        self::assertSame('Acme', $result->first()?->name);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'test-agent'));
    }

    public function test_server_error_is_retried_then_reported_retryable(): void
    {
        Http::fake(['probe.test/*' => Http::sequence()->push('down', 503)->push('down', 503)]);

        $result = $this->driver()->lookup('HU', Identifier::registration('1'));

        self::assertSame(FailureReason::Unavailable, $result->reason);
        self::assertTrue($result->retryable);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_mapping(): void
    {
        Http::fake(['probe.test/*' => Http::response('slow down', 429)]);

        self::assertSame(FailureReason::RateLimited, $this->driver()->lookup('HU', Identifier::registration('1'))->reason);
    }

    public function test_rejection_mapping(): void
    {
        Http::fake(['probe.test/*' => Http::response('nope', 401)]);
        $rejected = $this->driver()->lookup('HU', Identifier::registration('1'));
        self::assertSame(FailureReason::Rejected, $rejected->reason);
        self::assertFalse($rejected->retryable);
    }

    public function test_connection_failure_is_a_retryable_timeout(): void
    {
        Http::fake(['probe.test/*' => Http::failedConnection()]);

        $result = $this->driver()->lookup('HU', Identifier::registration('1'));

        self::assertSame(FailureReason::Timeout, $result->reason);
        self::assertTrue($result->retryable);
    }

    public function test_invalid_json_is_an_invalid_response(): void
    {
        Http::fake(['probe.test/*' => Http::response('<html>maintenance</html>', 200)]);

        $result = $this->driver()->lookup('HU', Identifier::registration('1'));

        self::assertSame(FailureReason::InvalidResponse, $result->reason);
        self::assertFalse($result->retryable);
    }

    public function test_base_url_override_and_credentials(): void
    {
        Http::fake(['other.test/*' => Http::response(['name' => 'Override'])]);

        $driver = $this->driver(['base_url' => 'https://other.test/', 'key' => ' ']);

        self::assertFalse($driver->isConfigured(), 'blank credential counts as missing');
        self::assertSame('Override', $driver->lookup('HU', Identifier::registration('1'))->first()?->name);
        self::assertTrue($this->driver(['key' => 'abc'])->isConfigured());
    }

    public function test_client_credentials_token_is_cached(): void
    {
        Http::fake(['probe.test/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])]);

        $driver = $this->driver();

        self::assertSame('tok', $driver->token());
        $driver->token();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Basic '.base64_encode('id:secret'))
            && str_contains($request->body(), 'grant_type=client_credentials'));
    }
}
