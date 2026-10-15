<?php

use App\Http\Controllers\AngelConnectionController;
use App\Http\Controllers\BacktestController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BacktestController::class, 'index'])->name('backtest.index');
Route::post('/backtest', [BacktestController::class, 'run'])->name('backtest.run');
Route::post('/backtest/angel', [BacktestController::class, 'runAngel'])->name('backtest.angel');
Route::post('/angel/test', [AngelConnectionController::class, 'test'])->name('angel.test');
