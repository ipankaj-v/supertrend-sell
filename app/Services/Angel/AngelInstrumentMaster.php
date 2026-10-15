<?php

namespace App\Services\Angel;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AngelInstrumentMaster
{
    private string $url = 'https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json';

    /**
     * @return array<string, mixed>
     */
    public function findNiftyOption(DateTimeImmutable $tradeDate, float $underlying, string $optionType, int $strikeOffset = 0): array
    {
        $tradeDate = $tradeDate->setTime(0, 0);
        $strike = $this->strike($underlying, $optionType, $strikeOffset);
        $instruments = array_filter($this->instruments(), function (array $instrument) use ($tradeDate, $strike, $optionType): bool {
            if (($instrument['exch_seg'] ?? '') !== 'NFO') {
                return false;
            }

            if (($instrument['name'] ?? '') !== 'NIFTY') {
                return false;
            }

            if (($instrument['instrumenttype'] ?? '') !== 'OPTIDX') {
                return false;
            }

            if (! str_ends_with((string) ($instrument['symbol'] ?? ''), $optionType)) {
                return false;
            }

            if ((int) round(((float) ($instrument['strike'] ?? 0)) / 100) !== $strike) {
                return false;
            }

            return $this->expiry($instrument) >= $tradeDate;
        });

        usort($instruments, fn (array $a, array $b): int => $this->expiry($a) <=> $this->expiry($b));

        $selected = $instruments[0] ?? null;
        if ($selected === null) {
            throw new RuntimeException("NIFTY {$strike} {$optionType} option token not found in Angel instrument master.");
        }

        return [
            'token' => (string) $selected['token'],
            'symbol' => (string) $selected['symbol'],
            'strike' => $strike,
            'expiry' => $this->expiry($selected)->format('Y-m-d'),
            'lot_size' => (int) ($selected['lotsize'] ?? 75),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function instruments(): array
    {
        return Cache::remember('angel_instrument_master', now()->addHours(6), function (): array {
            $response = Http::timeout(120)->get($this->url);

            if (! $response->successful()) {
                throw new RuntimeException('Unable to download Angel instrument master.');
            }

            $data = $response->json();
            if (! is_array($data)) {
                throw new RuntimeException('Angel instrument master returned invalid JSON.');
            }

            return $data;
        });
    }

    /**
     * @param  array<string, mixed>  $instrument
     */
    private function expiry(array $instrument): DateTimeImmutable
    {
        $raw = (string) ($instrument['expiry'] ?? '');

        foreach (['dMY', 'd-M-Y', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, strtoupper($raw));
            if ($date instanceof DateTimeImmutable) {
                return $date->setTime(0, 0);
            }
        }

        throw new RuntimeException("Invalid option expiry in Angel instrument master: {$raw}");
    }

    private function strike(float $underlying, string $optionType, int $strikeOffset): int
    {
        $atm = (int) (round($underlying / 50) * 50);

        return $optionType === 'CE' ? $atm + $strikeOffset : $atm - $strikeOffset;
    }
}
