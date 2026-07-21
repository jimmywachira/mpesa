<?php

use App\Http\Controllers\MpesaController;
use Illuminate\Support\Facades\Route;

Route::post('/mpesa/initiate', [MpesaController::class, 'initiateStkPush'])->name('mpesa.initiate');
Route::get('/mpesa/status/{checkoutRequestId}', [MpesaController::class, 'checkStatus'])->name('mpesa.status');
Route::post('/mpesa/callback', [MpesaController::class, 'handleCallback'])->name('mpesa.callback');
