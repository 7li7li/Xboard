<?php

use Illuminate\Support\Facades\Route;
use Plugin\LdcPay\Controllers\CallbackController;

require_once dirname(__DIR__) . '/Controllers/CallbackController.php';

Route::match(['get', 'post'], '/pay/ldcnotify/', [CallbackController::class, 'notify']);
Route::match(['get', 'post'], '/pay/ldcnotify', [CallbackController::class, 'notify']);
Route::match(['get', 'post'], '/pay/ldcreturn/', [CallbackController::class, 'returnPage']);
Route::match(['get', 'post'], '/pay/ldcreturn', [CallbackController::class, 'returnPage']);
