<?php

use Illuminate\Support\Facades\Route;

Route::view('/pay', 'mpesa-pay')->name('mpesa.pay');
