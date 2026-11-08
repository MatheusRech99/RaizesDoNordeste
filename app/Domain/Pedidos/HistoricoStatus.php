<?php

namespace App\Domain\Pedidos;

use Illuminate\Database\Eloquent\Model;

class HistoricoStatus extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'historico_status';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status_anterior' => StatusPedido::class,
            'status_novo' => StatusPedido::class,
        ];
    }
}
