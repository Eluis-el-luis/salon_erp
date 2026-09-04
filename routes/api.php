<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ArticuloController;

// Rutas de Recursos (CRUD automático)
Route::apiResource('clients', ClienteController::class);
Route::apiResource('items', ArticuloController::class);
Route::apiResource('appointments', CitaController::class);