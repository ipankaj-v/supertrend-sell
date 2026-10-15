<?php

namespace App\Services\Backtesting;

class SupertrendCalculator
{
    /**
     * @param  array<int, array{datetime:string,open:float,high:float,low:float,close:float}>  $candles
     * @return array<int, array<string, mixed>>
     */
    public function calculate(array $candles, int $period = 10, float $multiplier = 3.0): array
    {
        if (count($candles) < $period + 1) {
            return [];
        }

        $rows = [];
        $previousAtr = null;
        $previousFinalUpper = null;
        $previousFinalLower = null;
        $previousDirection = null;

        foreach ($candles as $index => $candle) {
            $previousClose = $index > 0 ? $candles[$index - 1]['close'] : $candle['close'];
            $trueRange = max(
                $candle['high'] - $candle['low'],
                abs($candle['high'] - $previousClose),
                abs($candle['low'] - $previousClose),
            );

            if ($index === 0) {
                $atr = $trueRange;
            } elseif ($index < $period) {
                $atr = (($previousAtr ?? $trueRange) * $index + $trueRange) / ($index + 1);
            } else {
                $atr = (($previousAtr ?? $trueRange) * ($period - 1) + $trueRange) / $period;
            }

            $hl2 = ($candle['high'] + $candle['low']) / 2;
            $basicUpper = $hl2 + ($multiplier * $atr);
            $basicLower = $hl2 - ($multiplier * $atr);

            $finalUpper = $basicUpper;
            if ($previousFinalUpper !== null && $previousClose <= $previousFinalUpper) {
                $finalUpper = min($basicUpper, $previousFinalUpper);
            }

            $finalLower = $basicLower;
            if ($previousFinalLower !== null && $previousClose >= $previousFinalLower) {
                $finalLower = max($basicLower, $previousFinalLower);
            }

            if ($previousDirection === null) {
                $direction = $candle['close'] >= $hl2 ? 'bullish' : 'bearish';
            } elseif ($previousDirection === 'bearish' && $candle['close'] > $previousFinalUpper) {
                $direction = 'bullish';
            } elseif ($previousDirection === 'bullish' && $candle['close'] < $previousFinalLower) {
                $direction = 'bearish';
            } else {
                $direction = $previousDirection;
            }

            $rows[] = [
                ...$candle,
                'atr' => $atr,
                'supertrend' => $direction === 'bullish' ? $finalLower : $finalUpper,
                'direction' => $direction,
                'flip' => $previousDirection !== null && $direction !== $previousDirection,
            ];

            $previousAtr = $atr;
            $previousFinalUpper = $finalUpper;
            $previousFinalLower = $finalLower;
            $previousDirection = $direction;
        }

        return array_slice($rows, $period);
    }
}
