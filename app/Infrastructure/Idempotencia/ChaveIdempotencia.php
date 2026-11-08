<?php

namespace App\Infrastructure\Idempotencia;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class ChaveIdempotencia extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'chaves_idempotencia';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime'];
    }

    public function emAndamento(): bool
    {
        return $this->status_resposta === null;
    }

    // limpeza das chaves vencidas: php artisan model:prune (agendado em routes/console.php)
    public function prunable(): Builder
    {
        return static::where('expira_em', '<', now());
    }
}
