<?php

namespace App\Services\Backtesting;

use InvalidArgumentException;

class OptionSellingBacktester
{
    public function __construct(private readonly SupertrendCalculator $supertrend) {}

    /**
     * @param  array<int, array{datetime:string,open:float,high:float,low:float,close:float}>  $candles
     * @param  array<string, float|int>  $settings
     * @return array<string, mixed>
     */
    public function run(array $candles, array $settings): array
    {
        $settings = $this->settings($settings);
        $rows = $this->supertrend->calculate($candles, (int) $settings['period'], (float) $settings['multiplier']);

        $trades = [];
        $position = null;

        foreach ($rows as $index => $row) {
            if (! $row['flip']) {
                if ($position !== null) {
                    $position = $this->markPosition($position, $row, $settings);
                }

                continue;
            }

            if ($position !== null) {
                $trades[] = $this->closeTrade(
                    $this->markPosition($position, $row, $settings),
                    $row,
                    $settings,
                    'Supertrend flip',
                );
            }

            $position = [
                'side' => $row['direction'] === 'bullish' ? 'SELL_PE' : 'SELL_CE',
                'entry_time' => $row['datetime'],
                'entry_underlying' => $row['close'],
                'entry_premium' => $settings['entry_premium'],
                'bars' => 0,
                'current_premium' => $settings['entry_premium'],
                'exit_reason' => null,
            ];
        }

        if ($position !== null && $rows !== []) {
            $lastRow = $rows[array_key_last($rows)];
            $trades[] = $this->closeTrade(
                $this->markPosition($position, $lastRow, $settings),
                $lastRow,
                $settings,
                'End of data',
            );
        }

        $netPnl = array_sum(array_column($trades, 'net_pnl'));
        $grossPnl = array_sum(array_column($trades, 'gross_pnl'));
        $wins = count(array_filter($trades, fn (array $trade): bool => $trade['net_pnl'] > 0));

        return [
            'settings' => $settings,
            'candles' => count($candles),
            'indicator_rows' => count($rows),
            'trades' => $trades,
            'summary' => [
                'total_trades' => count($trades),
                'wins' => $wins,
                'losses' => count($trades) - $wins,
                'win_rate' => count($trades) > 0 ? round(($wins / count($trades)) * 100, 2) : 0.0,
                'gross_pnl' => round($grossPnl, 2),
                'charges' => round(count($trades) * $settings['brokerage_per_trade'], 2),
                'net_pnl' => round($netPnl, 2),
            ],
        ];
    }

    /**
     * @param  array<int, array{datetime:string,open:float,high:float,low:float,close:float}>  $candles
     * @param  array<string, float|int>  $settings
     * @param  callable(array<string, mixed>, string): array{premium:float,instrument?:array<string, mixed>}  $premiumResolver
     * @return array<string, mixed>
     */
    public function runWithAngelPremiums(array $candles, array $settings, callable $premiumResolver): array
    {
        $settings = $this->settings($settings);
        $rows = $this->supertrend->calculate($candles, (int) $settings['period'], (float) $settings['multiplier']);

        $trades = [];
        $position = null;

        foreach ($rows as $row) {
            if ($position !== null && $this->isAtOrAfterTime($row['datetime'], (string) $settings['intraday_exit_time'])) {
                $exit = $premiumResolver($position + ['exit_row' => $row], 'exit');
                $position['current_premium'] = $exit['premium'];
                $trades[] = $this->closeTrade($position, $row, $settings, 'Intraday cutoff');
                $position = null;

                continue;
            }

            if (! $row['flip']) {
                continue;
            }

            if ($this->isAtOrAfterTime($row['datetime'], (string) $settings['intraday_exit_time'])) {
                continue;
            }

            if ($position !== null) {
                $exit = $premiumResolver($position + ['exit_row' => $row], 'exit');
                $position['current_premium'] = $exit['premium'];
                $trades[] = $this->closeTrade($position, $row, $settings, 'Supertrend TSL');
            }

            $side = $row['direction'] === 'bullish' ? 'SELL_PE' : 'SELL_CE';
            $entry = $premiumResolver([
                'side' => $side,
                'entry_row' => $row,
            ], 'entry');

            $position = [
                'side' => $side,
                'entry_time' => $row['datetime'],
                'entry_underlying' => $row['close'],
                'entry_premium' => $entry['premium'],
                'current_premium' => $entry['premium'],
                'exit_reason' => null,
                'instrument' => $entry['instrument'] ?? [],
            ];
        }

        if ($position !== null && $rows !== []) {
            $lastRow = $rows[array_key_last($rows)];
            $exit = $premiumResolver($position + ['exit_row' => $lastRow], 'exit');
            $position['current_premium'] = $exit['premium'];
            $trades[] = $this->closeTrade($position, $lastRow, $settings, 'End of data');
        }

        $netPnl = array_sum(array_column($trades, 'net_pnl'));
        $grossPnl = array_sum(array_column($trades, 'gross_pnl'));
        $wins = count(array_filter($trades, fn (array $trade): bool => $trade['net_pnl'] > 0));

        return [
            'settings' => $settings,
            'candles' => count($candles),
            'indicator_rows' => count($rows),
            'trades' => $trades,
            'summary' => [
                'total_trades' => count($trades),
                'wins' => $wins,
                'losses' => count($trades) - $wins,
                'win_rate' => count($trades) > 0 ? round(($wins / count($trades)) * 100, 2) : 0.0,
                'gross_pnl' => round($grossPnl, 2),
                'charges' => round(count($trades) * $settings['brokerage_per_trade'], 2),
                'net_pnl' => round($netPnl, 2),
            ],
        ];
    }

    /**
     * @return array<int, array{datetime:string,open:float,high:float,low:float,close:float}>
     */
    public function candlesFromCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException('Unable to open CSV file.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            throw new InvalidArgumentException('CSV file is empty.');
        }

        $header = array_map(fn (string $value): string => strtolower(trim($value)), $header);
        $required = ['datetime', 'open', 'high', 'low', 'close'];
        foreach ($required as $column) {
            if (! in_array($column, $header, true)) {
                throw new InvalidArgumentException("Missing required CSV column: {$column}");
            }
        }

        $candles = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($value): bool => $value !== null && $value !== '')) === 0) {
                continue;
            }

            $mapped = array_combine($header, $row);
            if ($mapped === false) {
                continue;
            }

            $candles[] = [
                'datetime' => trim((string) $mapped['datetime']),
                'open' => (float) $mapped['open'],
                'high' => (float) $mapped['high'],
                'low' => (float) $mapped['low'],
                'close' => (float) $mapped['close'],
            ];
        }

        fclose($handle);

        return $candles;
    }

    /**
     * @param  array<int, array{0:string,1:float|int,2:float|int,3:float|int,4:float|int,5?:float|int}>  $rows
     * @return array<int, array{datetime:string,open:float,high:float,low:float,close:float}>
     */
    public function candlesFromAngelRows(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'datetime' => str_replace('T', ' ', substr((string) $row[0], 0, 16)),
            'open' => (float) $row[1],
            'high' => (float) $row[2],
            'low' => (float) $row[3],
            'close' => (float) $row[4],
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $row
     * @param  array<string, float|int>  $settings
     * @return array<string, mixed>
     */
    private function markPosition(array $position, array $row, array $settings): array
    {
        $position['bars']++;
        $move = $row['close'] - $position['entry_underlying'];
        $adverseMove = $position['side'] === 'SELL_CE' ? max(0, $move) : max(0, -$move);
        $favourableMove = $position['side'] === 'SELL_CE' ? max(0, -$move) : max(0, $move);

        $premium = $position['entry_premium']
            + ($adverseMove * $settings['delta'])
            - ($favourableMove * $settings['favourable_delta'])
            - ($position['bars'] * $settings['theta_per_candle']);

        $position['current_premium'] = round(max($settings['minimum_premium'], $premium), 2);

        return $position;
    }

    private function isAtOrAfterTime(string $datetime, string $time): bool
    {
        return substr($datetime, 11, 5) >= $time;
    }

    /**
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>  $row
     * @param  array<string, float|int>  $settings
     * @return array<string, mixed>
     */
    private function closeTrade(array $position, array $row, array $settings, ?string $reason = null): array
    {
        $grossPoints = $position['entry_premium'] - $position['current_premium'];
        $lotSize = $position['instrument']['lot_size'] ?? $settings['lot_size'];
        $grossPnl = $grossPoints * $lotSize * $settings['lots'];
        $netPnl = $grossPnl - $settings['brokerage_per_trade'];

        return [
            'side' => $position['side'],
            'entry_time' => $position['entry_time'],
            'exit_time' => $row['datetime'],
            'entry_underlying' => round($position['entry_underlying'], 2),
            'exit_underlying' => round($row['close'], 2),
            'entry_premium' => round($position['entry_premium'], 2),
            'exit_premium' => round($position['current_premium'], 2),
            'gross_points' => round($grossPoints, 2),
            'gross_pnl' => round($grossPnl, 2),
            'net_pnl' => round($netPnl, 2),
            'exit_reason' => $reason ?? $position['exit_reason'] ?? 'Exit',
            'instrument' => $position['instrument']['symbol'] ?? null,
        ];
    }

    /**
     * @param  array<string, float|int>  $settings
     * @return array<string, float|int>
     */
    private function settings(array $settings): array
    {
        return [
            'period' => (int) ($settings['period'] ?? 10),
            'multiplier' => (float) ($settings['multiplier'] ?? 3),
            'entry_premium' => (float) ($settings['entry_premium'] ?? 120),
            'delta' => (float) ($settings['delta'] ?? 0.45),
            'favourable_delta' => (float) ($settings['favourable_delta'] ?? 0.18),
            'theta_per_candle' => (float) ($settings['theta_per_candle'] ?? 0.35),
            'minimum_premium' => (float) ($settings['minimum_premium'] ?? 5),
            'stop_loss_percent' => (float) ($settings['stop_loss_percent'] ?? 50),
            'lot_size' => (int) ($settings['lot_size'] ?? 75),
            'lots' => (int) ($settings['lots'] ?? 1),
            'brokerage_per_trade' => (float) ($settings['brokerage_per_trade'] ?? 60),
            'intraday_exit_time' => (string) ($settings['intraday_exit_time'] ?? '14:50'),
        ];
    }
}
