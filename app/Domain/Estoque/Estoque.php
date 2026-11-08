<?php

namespace App\Domain\Estoque;

use App\Domain\Cardapio\Produto;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Unidades\Unidade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estoque extends Model
{
    protected $table = 'estoques';

    protected $fillable = ['unidade_id', 'produto_id', 'quantidade'];

    protected $attributes = ['quantidade' => 0];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }

    // so chamar com a linha travada (lockForUpdate), senao o saldo lido pode estar velho
    public function aplicar(int $delta): void
    {
        $novo = $this->quantidade + $delta;

        // RN11
        if ($novo < 0) {
            throw new NegocioException(
                CodigoErro::ESTOQUE_INSUFICIENTE,
                'Estoque insuficiente',
                [['produtoId' => $this->produto_id, 'disponivel' => $this->quantidade, 'solicitado' => -$delta]],
            );
        }

        $this->quantidade = $novo;
        $this->save();
    }
}
