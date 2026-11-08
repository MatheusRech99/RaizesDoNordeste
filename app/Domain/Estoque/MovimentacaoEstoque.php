<?php

namespace App\Domain\Estoque;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentacaoEstoque extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'movimentacoes_estoque';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tipo' => TipoMovimentacao::class];
    }

    public function estoque(): BelongsTo
    {
        return $this->belongsTo(Estoque::class);
    }
}
