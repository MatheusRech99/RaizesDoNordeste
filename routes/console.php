<?php

use App\Infrastructure\Idempotencia\ChaveIdempotencia;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// limpa as chaves de idempotencia vencidas
Schedule::command('model:prune', ['--model' => ChaveIdempotencia::class])->daily();
