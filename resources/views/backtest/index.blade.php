<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NIFTY Supertrend Option Seller</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #09090b;
            --panel: #18181b;
            --panel-2: #0f1115;
            --line: #2f333b;
            --muted: #a1a1aa;
            --text: #f4f4f5;
            --green: #34d399;
            --amber: #fbbf24;
            --red: #fca5a5;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(1240px, calc(100% - 32px));
            min-height: 100vh;
            margin: 0 auto;
            padding: 28px 0;
        }

        .layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
            align-items: start;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 24px 70px rgba(0, 0, 0, .22);
        }

        .control-panel { padding: 22px; }
        .results { padding: 22px; min-height: 520px; }
        .eyebrow { margin: 0; color: var(--green); font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.15; }
        h2 { margin: 0; font-size: 22px; }
        .copy { margin: 10px 0 0; color: var(--muted); font-size: 14px; line-height: 1.65; }
        .section-title { margin: 22px 0 0; color: #e4e4e7; font-size: 15px; font-weight: 800; }
        .alert { margin: 18px 0; border: 1px solid rgba(248, 113, 113, .5); border-radius: 6px; background: rgba(239, 68, 68, .12); padding: 12px; color: #fecaca; font-size: 14px; }
        label { display: block; color: #d4d4d8; font-size: 14px; font-weight: 600; }
        input {
            width: 100%;
            margin-top: 7px;
            border: 1px solid #3f3f46;
            border-radius: 6px;
            background: #09090b;
            color: var(--text);
            padding: 10px 11px;
            font: inherit;
            outline: none;
        }

        input:focus { border-color: var(--green); }
        input[type=file] { font-size: 13px; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; margin-top: 20px; }
        button {
            width: 100%;
            margin-top: 20px;
            border: 0;
            border-radius: 6px;
            background: var(--green);
            color: #052e20;
            padding: 12px 14px;
            font-weight: 800;
            cursor: pointer;
        }

        button:hover { background: #6ee7b7; }
        .secondary {
            width: auto;
            margin-top: 14px;
            border: 1px solid var(--line);
            background: var(--panel-2);
            color: var(--text);
        }

        .secondary:hover { background: #27272a; }
        .ok { margin: 18px 0; border: 1px solid rgba(52, 211, 153, .5); border-radius: 6px; background: rgba(16, 185, 129, .12); padding: 12px; color: #bbf7d0; font-size: 14px; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .stat { border: 1px solid var(--line); border-radius: 7px; background: var(--panel-2); padding: 16px; }
        .stat span { display: block; color: #71717a; font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .stat strong { display: block; margin-top: 8px; font-size: 25px; }
        .table-wrap { margin-top: 22px; overflow-x: auto; }
        table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 14px; }
        th { border-bottom: 1px solid var(--line); padding: 12px 14px 12px 0; color: #85858e; font-size: 11px; letter-spacing: .08em; text-align: left; text-transform: uppercase; }
        td { border-bottom: 1px solid var(--line); padding: 13px 14px 13px 0; color: #d4d4d8; white-space: nowrap; }
        .green { color: var(--green); font-weight: 800; }
        .amber { color: var(--amber); font-weight: 800; }
        .red { color: var(--red); font-weight: 800; }
        .empty { display: grid; min-height: 470px; place-items: center; text-align: center; }
        .empty div { max-width: 460px; }

        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 560px) {
            main { width: min(100% - 20px, 1240px); padding: 10px 0; }
            .control-panel, .results { padding: 16px; }
            .fields, .stats { grid-template-columns: 1fr; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <main>
        <section class="layout">
            <div class="panel control-panel">
                <p class="eyebrow">NIFTY 5-minute</p>
                <h1>Supertrend Option Selling Backtest</h1>
                <p class="copy">Upload NIFTY OHLC candles with columns: datetime, open, high, low, close.</p>

                @if ($errors->any())
                    <div class="alert">{{ $errors->first() }}</div>
                @endif

                @if (session('angel_status'))
                    <div class="ok">{{ session('angel_status') }}</div>
                @endif

                @if (session('angel_error'))
                    <div class="alert">{{ session('angel_error') }}</div>
                @endif

                @if ($error)
                    <div class="alert">{{ $error }}</div>
                @endif

                @php
                    $angelFields = [
                        ['period', 'ST Period', 10, '1'],
                        ['multiplier', 'ST Multiplier', 3, '0.1'],
                        ['strike_offset', 'OTM Points', 0, '50'],
                        ['lots', 'Lots', 1, '1'],
                        ['lot_size', 'Fallback Lot Size', 75, '1'],
                        ['brokerage_per_trade', 'Charges/Trade', 60, '1'],
                    ];
                    $csvFields = [
                        ['period', 'ST Period', 10, '1'],
                        ['multiplier', 'ST Multiplier', 3, '0.1'],
                        ['entry_premium', 'Entry Premium', 120, '1'],
                        ['delta', 'Adverse Delta', 0.45, '0.01'],
                        ['theta_per_candle', 'Theta/Candle', 0.35, '0.01'],
                        ['stop_loss_percent', 'SL %', 50, '1'],
                        ['lot_size', 'Lot Size', 75, '1'],
                        ['lots', 'Lots', 1, '1'],
                        ['brokerage_per_trade', 'Charges/Trade', 60, '1'],
                    ];
                @endphp

                <form action="{{ route('angel.test') }}" method="post">
                    @csrf
                    <button type="submit" class="secondary">Test Angel Connection</button>
                </form>

                <p class="section-title">Backtest From Angel</p>
                <form action="{{ route('backtest.angel') }}" method="post">
                    @csrf

                    <div class="fields">
                        <label>
                            From
                            <input name="fromdate" type="datetime-local" value="{{ old('fromdate') }}" required>
                        </label>
                        <label>
                            To
                            <input name="todate" type="datetime-local" value="{{ old('todate') }}" required>
                        </label>
                        <label style="grid-column: 1 / -1;">
                            Current Angel OTP
                            <input name="totp" type="text" inputmode="numeric" maxlength="6" value="{{ old('totp') }}" placeholder="Optional if TOTP secret is set">
                        </label>
                    </div>

                    <div class="fields">
                        @foreach ($angelFields as [$name, $label, $default, $step])
                            <label>
                                {{ $label }}
                                <input name="{{ $name }}" type="number" step="{{ $step }}" value="{{ old($name, $default) }}">
                            </label>
                        @endforeach
                    </div>

                    <button type="submit">Fetch Angel & Run Backtest</button>
                </form>

                <p class="section-title">Backtest From CSV</p>
                <form action="{{ route('backtest.run') }}" method="post" enctype="multipart/form-data">
                    @csrf

                    <div style="margin-top: 20px;">
                        <label for="candles">NIFTY CSV</label>
                        <input id="candles" name="candles" type="file" accept=".csv,text/csv" required>
                    </div>

                <div class="fields">
                    @foreach ($csvFields as [$name, $label, $default, $step])
                        <label>
                            {{ $label }}
                            <input name="{{ $name }}" type="number" step="{{ $step }}" value="{{ old($name, $default) }}">
                        </label>
                    @endforeach
                </div>

                    <button type="submit">Run Backtest</button>
                </form>
            </div>

            <section class="panel results">
                @if ($result)
                    <div class="stats">
                        @foreach ([
                            'Total Trades' => $result['summary']['total_trades'],
                            'Win Rate' => $result['summary']['win_rate'].'%',
                            'Gross P&L' => number_format($result['summary']['gross_pnl'], 2),
                            'Net P&L' => number_format($result['summary']['net_pnl'], 2),
                        ] as $label => $value)
                            <div class="stat">
                                <span>{{ $label }}</span>
                                <strong>{{ $value }}</strong>
                            </div>
                        @endforeach
                    </div>

                    @if (! empty($result['source']))
                        <p class="copy">Source: {{ $result['source'] }}</p>
                    @endif

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Side</th>
                                    <th>Instrument</th>
                                    <th>Entry</th>
                                    <th>Exit</th>
                                    <th>Underlying</th>
                                    <th>Premium</th>
                                    <th>Net P&L</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($result['trades'] as $trade)
                                    <tr>
                                        <td class="{{ $trade['side'] === 'SELL_PE' ? 'green' : 'amber' }}">{{ $trade['side'] }}</td>
                                        <td>{{ $trade['instrument'] ?? '-' }}</td>
                                        <td>{{ $trade['entry_time'] }}</td>
                                        <td>{{ $trade['exit_time'] }}</td>
                                        <td>{{ $trade['entry_underlying'] }} -> {{ $trade['exit_underlying'] }}</td>
                                        <td>{{ $trade['entry_premium'] }} -> {{ $trade['exit_premium'] }}</td>
                                        <td class="{{ $trade['net_pnl'] >= 0 ? 'green' : 'red' }}">{{ number_format($trade['net_pnl'], 2) }}</td>
                                        <td>{{ $trade['exit_reason'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" style="padding: 34px; text-align: center; color: var(--muted);">No Supertrend flips found in this file.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="empty">
                        <div>
                            <h2>Ready for your NIFTY candles</h2>
                            <p class="copy">The first version backtests confirmed Supertrend flips on candle close. Bullish flip sells PE, bearish flip sells CE, and exits happen on the next flip, SL, target, or end of data.</p>
                        </div>
                    </div>
                @endif
            </section>
        </section>
    </main>
</body>
</html>
