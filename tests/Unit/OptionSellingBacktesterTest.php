<?php

namespace Tests\Unit;

use App\Services\Backtesting\OptionSellingBacktester;
use App\Services\Backtesting\SupertrendCalculator;
use PHPUnit\Framework\TestCase;

class OptionSellingBacktesterTest extends TestCase
{
    public function test_it_generates_a_backtest_summary(): void
    {
        $candles = [];
        $price = 22000;

        for ($i = 0; $i < 80; $i++) {
            $price += $i < 35 ? 8 : -12;
            $candles[] = [
                'datetime' => '2026-10-13 09:'.str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT),
                'open' => $price - 4,
                'high' => $price + 10,
                'low' => $price - 10,
                'close' => $price,
            ];
        }

        $result = (new OptionSellingBacktester(new SupertrendCalculator))->run($candles, [
            'period' => 7,
            'multiplier' => 2,
        ]);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('trades', $result);
        $this->assertSame(80, $result['candles']);
    }
}
