<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UnidadeController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::get('unidades', [UnidadeController::class, 'index']);
Route::get('unidades/{id}', [UnidadeController::class, 'show'])->whereNumber('id');
Route::put('unidades/{id}', [UnidadeController::class, 'update'])
    ->whereNumber('id')
    ->middleware(['auth:api', 'perfil:ADMIN,GERENTE']);
