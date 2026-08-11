<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ItemController;

// Rutas de Recursos (CRUD automático)
Route::apiResource('clients', ClientController::class);
Route::apiResource('items', ItemController::class);
Route::apiResource('appointments', AppointmentController::class);

