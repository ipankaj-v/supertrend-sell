<?php

namespace App\Http\Controllers;

use App\Services\Angel\AngelSmartApiClient;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AngelConnectionController extends Controller
{
    public function test(AngelSmartApiClient $angel): RedirectResponse
    {
        try {
            $login = $angel->login();
            $profile = $angel->profile($login['data']['jwtToken']);

            return redirect()
                ->route('backtest.index')
                ->with('angel_status', 'Angel connected successfully for client '.$profile['data']['clientcode'].'.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('backtest.index')
                ->with('angel_error', $exception->getMessage());
        }
    }
}
