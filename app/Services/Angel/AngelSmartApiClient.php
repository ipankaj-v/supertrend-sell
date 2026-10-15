<?php

namespace App\Services\Angel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

class AngelSmartApiClient
{
    private const REQUEST_INTERVAL_MICROSECONDS = 1_100_000;

    private string $baseUrl = 'https://apiconnect.angelone.in/rest';

    /**
     * @return array<string, mixed>
     */
    public function login(?string $totp = null): array
    {
        $totp ??= $this->totp();
        $this->paceRequests(self::REQUEST_INTERVAL_MICROSECONDS);

        $response = $this->request()->post('/auth/angelbroking/user/v1/loginByPassword', [
            'clientcode' => $this->config('client_code'),
            'password' => $this->config('password'),
            'totp' => $totp,
        ]);

        return $this->decode($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(string $jwtToken): array
    {
        $this->paceRequests(self::REQUEST_INTERVAL_MICROSECONDS);

        $response = $this->request($jwtToken)->get('/secure/angelbroking/user/v1/getProfile');

        return $this->decode($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function candleData(
        string $jwtToken,
        string $exchange,
        string $symbolToken,
        string $interval,
        string $fromDate,
        string $toDate,
    ): array {
        $cacheKey = 'angel:candles:'.hash('sha256', implode('|', [
            $this->config('client_code'),
            $exchange,
            $symbolToken,
            $interval,
            $fromDate,
            $toDate,
        ]));
        $expiresAt = substr($toDate, 0, 10) < now()->toDateString()
            ? now()->addDay()
            : now()->addMinute();

        return Cache::lock($cacheKey.':lock', 60)->block(60, function () use ($cacheKey, $expiresAt, $jwtToken, $exchange, $symbolToken, $interval, $fromDate, $toDate): array {
            return Cache::remember($cacheKey, $expiresAt, function () use ($jwtToken, $exchange, $symbolToken, $interval, $fromDate, $toDate): array {
                $this->paceRequests(self::REQUEST_INTERVAL_MICROSECONDS);

                $response = $this->request($jwtToken)->post('/secure/angelbroking/historical/v1/getCandleData', [
                    'exchange' => $exchange,
                    'symboltoken' => $symbolToken,
                    'interval' => $interval,
                    'fromdate' => $fromDate,
                    'todate' => $toDate,
                ]);

                return $this->decode($response->json());
            });
        });
    }

    private function request(?string $jwtToken = null): PendingRequest
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-UserType' => 'USER',
            'X-SourceID' => 'WEB',
            'X-ClientLocalIP' => config('angel.client_local_ip', '127.0.0.1'),
            'X-ClientPublicIP' => config('angel.client_public_ip', '127.0.0.1'),
            'X-MACAddress' => config('angel.mac_address', '00:00:00:00:00:00'),
            'X-PrivateKey' => $this->config('api_key'),
        ];

        if ($jwtToken !== null) {
            $headers['Authorization'] = 'Bearer '.$jwtToken;
        }

        return Http::baseUrl($this->baseUrl)
            ->withHeaders($headers)
            ->timeout(20)
            ->retry(2, 500, function (\Throwable $exception, PendingRequest $request, ?string $method): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            });
    }

    private function paceRequests(int $minimumIntervalMicroseconds): void
    {
        $rateLimitKey = 'angel:rate-limit:'.hash('sha256', $this->config('client_code'));
        $nextRequestKey = $rateLimitKey.':next';

        Cache::lock($rateLimitKey.':lock', 10)->block(10, function () use ($nextRequestKey, $minimumIntervalMicroseconds): void {
            $nextRequestAt = (float) Cache::get($nextRequestKey, 0);
            $now = microtime(true);
            $waitMicroseconds = (int) ceil(max(0, $nextRequestAt - $now) * 1_000_000);

            if ($waitMicroseconds > 0) {
                Sleep::usleep($waitMicroseconds)->then(static fn () => null);
            }

            Cache::put(
                $nextRequestKey,
                max($nextRequestAt, $now) + ($minimumIntervalMicroseconds / 1_000_000),
                now()->addMinute(),
            );
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function decode(?array $payload): array
    {
        if (! is_array($payload)) {
            throw new RuntimeException('Angel API returned an invalid response.');
        }

        if (($payload['status'] ?? false) !== true) {
            $message = $payload['message'] ?? $payload['errorcode'] ?? 'Angel API request failed.';
            throw new RuntimeException((string) $message);
        }

        return $payload;
    }

    private function config(string $key): string
    {
        $value = config("angel.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Missing Angel config value: ANGEL_'.strtoupper($key));
        }

        return $value;
    }

    private function totp(): string
    {
        $secret = $this->config('totp_secret');
        $counter = intdiv(time(), 30);
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';
        $output = '';

        foreach (str_split($secret) as $character) {
            $value = strpos($alphabet, $character);

            if ($value === false) {
                continue;
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }

        return $output;
    }
}
