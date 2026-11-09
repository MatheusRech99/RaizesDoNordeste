<?php

namespace App\Domain\Fidelidade;

use App\Domain\Pedidos\Pedido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PontosFidelidade extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pontos_fidelidade';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tipo' => TipoPontos::class, 'pontos' => 'integer'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public static function saldoDe(int $usuarioId): int
    {
        return (int) static::where('usuario_id', $usuarioId)->sum('pontos');
    }
}
