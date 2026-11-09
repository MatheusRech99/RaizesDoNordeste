<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardapioController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\EstoqueController;
use App\Http\Controllers\Api\PagamentoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProdutoController;
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

Route::post('clientes', [ClienteController::class, 'store']);
Route::middleware(['auth:api', 'perfil:CLIENTE'])->prefix('clientes/me')->group(function () {
    Route::get('consentimentos', [ClienteController::class, 'consentimentos']);
    Route::delete('consentimentos/{finalidade}', [ClienteController::class, 'revogar'])
        ->whereIn('finalidade', ['TERMOS', 'FIDELIDADE', 'MARKETING']);
});

Route::get('unidades', [UnidadeController::class, 'index']);
Route::get('unidades/{id}', [UnidadeController::class, 'show'])->whereNumber('id');
Route::put('unidades/{id}', [UnidadeController::class, 'update'])
    ->whereNumber('id')
    ->middleware(['auth:api', 'perfil:ADMIN,GERENTE']);

Route::get('unidades/{id}/cardapio', [CardapioController::class, 'index'])->whereNumber('id');
Route::put('unidades/{id}/cardapio/{produtoId}', [CardapioController::class, 'salvar'])
    ->whereNumber(['id', 'produtoId'])
    ->middleware(['auth:api', 'perfil:GERENTE']);

Route::middleware('auth:api')->whereNumber('id')->group(function () {
    Route::get('unidades/{id}/estoque', [EstoqueController::class, 'index'])->middleware('perfil:ADMIN,GERENTE,ATENDENTE');
    Route::get('unidades/{id}/estoque/movimentacoes', [EstoqueController::class, 'movimentacoes'])->middleware('perfil:ADMIN,GERENTE,ATENDENTE');
    Route::post('unidades/{id}/estoque/movimentacoes', [EstoqueController::class, 'movimentar'])->middleware('perfil:GERENTE');
});

Route::middleware('auth:api')->group(function () {
    Route::get('produtos', [ProdutoController::class, 'index'])->middleware('perfil:ADMIN,GERENTE');
    Route::get('produtos/{id}', [ProdutoController::class, 'show'])->whereNumber('id')->middleware('perfil:ADMIN,GERENTE');
    Route::post('produtos', [ProdutoController::class, 'store'])->middleware('perfil:ADMIN');
    Route::put('produtos/{id}', [ProdutoController::class, 'update'])->whereNumber('id')->middleware('perfil:ADMIN');
});

Route::post('pedidos', [PedidoController::class, 'store'])
    ->middleware(['auth:api', 'perfil:CLIENTE,ATENDENTE', 'idempotente']);

Route::middleware('auth:api')->group(function () {
    Route::get('pedidos', [PedidoController::class, 'index']);
    Route::get('pedidos/{id}', [PedidoController::class, 'show'])->whereNumber('id');
    Route::post('pedidos/{id}/cancelamento', [PedidoController::class, 'cancelar'])->whereNumber('id')->middleware('perfil:CLIENTE,ATENDENTE,GERENTE');
    Route::patch('pedidos/{id}/status', [PedidoController::class, 'atualizarStatus'])->whereNumber('id')->middleware('perfil:COZINHA,ATENDENTE');
});

Route::post('pagamentos', [PagamentoController::class, 'store'])
    ->middleware(['auth:api', 'perfil:CLIENTE,ATENDENTE', 'idempotente']);
