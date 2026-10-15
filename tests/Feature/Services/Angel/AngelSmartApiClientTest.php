<?php

namespace Tests\Feature\Services\Angel;

use App\Services\Angel\AngelSmartApiClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AngelSmartApiClientTest extends TestCase
{
    public function test_403_rate_limit_response_is_not_retried(): void
    {
        config()->set('cache.default', 'array');
        config()->set('angel.api_key', 'test-api-key');
        config()->set('angel.client_code', 'test-client');
        config()->set('angel.password', 'test-password');

        Http::preventStrayRequests();
        Http::fake([
            'https://apiconnect.angelone.in/rest/auth/angelbroking/user/v1/loginByPassword' => Http::response([
                'status' => false,
                'message' => 'Access denied because of exceeding access rate',
            ], 403),
        ]);

        $exception = null;
        try {
            (new AngelSmartApiClient)->login('123456');
        } catch (RequestException $requestException) {
            $exception = $requestException;
        }

        $this->assertInstanceOf(RequestException::class, $exception);
        $this->assertSame(403, $exception->response->status());
        Http::assertSentCount(1);
    }

    public function test_identical_historical_requests_reuse_the_cached_response(): void
    {
        config()->set('cache.default', 'array');
        config()->set('angel.api_key', 'test-api-key');
        config()->set('angel.client_code', 'test-client');

        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/historical/v1/getCandleData' => Http::response([
                'status' => true,
                'data' => [],
            ]),
        ]);

        $client = new AngelSmartApiClient;
        $client->candleData('test-token', 'NSE', '99926000', 'FIVE_MINUTE', '2026-10-14 09:15', '2026-10-14 09:20');
        $client->candleData('test-token', 'NSE', '99926000', 'FIVE_MINUTE', '2026-10-14 09:15', '2026-10-14 09:20');

        Http::assertSentCount(1);
        Sleep::assertSleptTimes(0);
    }

    public function test_distinct_historical_requests_are_spaced_to_stay_within_angel_rate_limits(): void
    {
        config()->set('cache.default', 'array');
        config()->set('angel.api_key', 'test-api-key');
        config()->set('angel.client_code', 'test-client');

        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://apiconnect.angelone.in/rest/secure/angelbroking/historical/v1/getCandleData' => Http::response([
                'status' => true,
                'data' => [],
            ]),
        ]);

        $client = new AngelSmartApiClient;
        $client->candleData('test-token', 'NSE', '99926000', 'FIVE_MINUTE', '2026-10-14 09:15', '2026-10-14 09:20');
        $client->candleData('test-token', 'NSE', '99926001', 'FIVE_MINUTE', '2026-10-14 09:15', '2026-10-14 09:20');

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalMicroseconds > 0);
    }

    public function test_distinct_smart_api_endpoints_share_rate_limit_spacing(): void
    {
        config()->set('cache.default', 'array');
        config()->set('angel.api_key', 'test-api-key');
        config()->set('angel.client_code', 'test-client');
        config()->set('angel.password', 'test-password');

        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            'https://apiconnect.angelone.in/rest/auth/angelbroking/user/v1/loginByPassword' => Http::response([
                'status' => true,
                'data' => ['jwtToken' => 'test-token'],
            ]),
            'https://apiconnect.angelone.in/rest/secure/angelbroking/user/v1/getProfile' => Http::response([
                'status' => true,
                'data' => ['clientcode' => 'test-client'],
            ]),
            'https://apiconnect.angelone.in/rest/secure/angelbroking/historical/v1/getCandleData' => Http::response([
                'status' => true,
                'data' => [],
            ]),
        ]);

        $client = new AngelSmartApiClient;
        $login = $client->login('123456');
        $client->profile($login['data']['jwtToken']);
        $client->candleData('test-token', 'NSE', '99926000', 'FIVE_MINUTE', '2026-10-14 09:15', '2026-10-14 09:20');

        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }
}
