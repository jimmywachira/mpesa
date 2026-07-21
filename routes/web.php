<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\MpesaPay;

Route::view('/pay', 'mpesa-pay')->name('mpesa.pay');

Route::livewire('/', 'mpesa-payment')->name('mpesa.pay');
