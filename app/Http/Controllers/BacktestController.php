<?php

namespace App\Http\Controllers;

use App\Services\Angel\AngelInstrumentMaster;
use App\Services\Angel\AngelSmartApiClient;
use App\Services\Backtesting\OptionSellingBacktester;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class BacktestController extends Controller
{
    public function index(): View
    {
        return view('backtest.index', [
            'result' => null,
            'error' => null,
        ]);
    }

    public function run(Request $request, OptionSellingBacktester $backtester): View
    {
        $validated = $this->validateBacktestSettings($request, [
            'candles' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            $candles = $backtester->candlesFromCsv($request->file('candles')->getRealPath());
            $result = $backtester->run($candles, $validated);
        } catch (Throwable $exception) {
            return view('backtest.index', [
                'result' => null,
                'error' => $exception->getMessage(),
            ]);
        }

        return view('backtest.index', [
            'result' => $result,
            'error' => null,
        ]);
    }

    public function runAngel(
        Request $request,
        AngelSmartApiClient $angel,
        AngelInstrumentMaster $instrumentMaster,
        OptionSellingBacktester $backtester,
    ): View {
        $validated = $this->validateBacktestSettings($request, [
            'fromdate' => ['required', 'date_format:Y-m-d\TH:i'],
            'todate' => ['required', 'date_format:Y-m-d\TH:i', 'after:fromdate'],
            'totp' => ['nullable', 'digits:6'],
            'strike_offset' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $from = new DateTimeImmutable($validated['fromdate']);
        $to = new DateTimeImmutable($validated['todate']);

        if ($from->diff($to)->days > 100) {
            return view('backtest.index', [
                'result' => null,
                'error' => 'Angel allows a maximum 100-day window for FIVE_MINUTE historical candles.',
            ]);
        }

        try {
            $login = $angel->login($validated['totp'] ?: null);
            $candlesResponse = $angel->candleData(
                $login['data']['jwtToken'],
                'NSE',
                '99926000',
                'FIVE_MINUTE',
                $from->format('Y-m-d H:i'),
                $to->format('Y-m-d H:i'),
            );

            $candles = $backtester->candlesFromAngelRows($candlesResponse['data'] ?? []);
            $optionCandles = [];
            $result = $backtester->runWithAngelPremiums(
                $candles,
                $validated,
                function (array $trade, string $event) use ($angel, $instrumentMaster, $login, $from, $to, $backtester, &$optionCandles, $validated): array {
                    $row = $event === 'entry' ? $trade['entry_row'] : $trade['exit_row'];
                    $instrument = $trade['instrument'] ?? null;

                    if ($event === 'entry') {
                        $optionType = $trade['side'] === 'SELL_CE' ? 'CE' : 'PE';
                        $instrument = $instrumentMaster->findNiftyOption(
                            new DateTimeImmutable($row['datetime']),
                            (float) $row['close'],
                            $optionType,
                            (int) $validated['strike_offset'],
                        );
                    }

                    $token = $instrument['token'];
                    if (! isset($optionCandles[$token])) {
                        $response = $angel->candleData(
                            $login['data']['jwtToken'],
                            'NFO',
                            $token,
                            'FIVE_MINUTE',
                            $from->format('Y-m-d H:i'),
                            $to->format('Y-m-d H:i'),
                        );

                        $optionCandles[$token] = $this->indexCandlesByTime($backtester->candlesFromAngelRows($response['data'] ?? []));
                    }

                    $premium = $optionCandles[$token][$row['datetime']]['close'] ?? null;
                    if ($premium === null) {
                        throw new \RuntimeException("No option candle found for {$instrument['symbol']} at {$row['datetime']}.");
                    }

                    return [
                        'premium' => (float) $premium,
                        'instrument' => $instrument,
                    ];
                },
            );
            $result['source'] = 'Angel SmartAPI NIFTY + option premiums, Supertrend TSL, no target';
        } catch (Throwable $exception) {
            return view('backtest.index', [
                'result' => null,
                'error' => $exception->getMessage(),
            ]);
        }

        return view('backtest.index', [
            'result' => $result,
            'error' => null,
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $extraRules
     * @return array<string, mixed>
     */
    private function validateBacktestSettings(Request $request, array $extraRules = []): array
    {
        return $request->validate([
            ...$extraRules,
            'period' => ['required', 'integer', 'min:2', 'max:100'],
            'multiplier' => ['required', 'numeric', 'min:0.1', 'max:20'],
            'entry_premium' => ['nullable', 'numeric', 'min:1'],
            'delta' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'theta_per_candle' => ['nullable', 'numeric', 'min:0'],
            'stop_loss_percent' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'target_percent' => ['nullable', 'numeric', 'min:1', 'max:100'],
            'lot_size' => ['required', 'integer', 'min:1'],
            'lots' => ['required', 'integer', 'min:1'],
            'brokerage_per_trade' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @param  array<int, array{datetime:string,open:float,high:float,low:float,close:float}>  $candles
     * @return array<string, array{datetime:string,open:float,high:float,low:float,close:float}>
     */
    private function indexCandlesByTime(array $candles): array
    {
        $indexed = [];

        foreach ($candles as $candle) {
            $indexed[$candle['datetime']] = $candle;
        }

        return $indexed;
    }
}
