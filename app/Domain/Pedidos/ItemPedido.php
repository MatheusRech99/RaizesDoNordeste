<?php

namespace App\Domain\Pedidos;

use App\Domain\Cardapio\Produto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPedido extends Model
{
    public $timestamps = false;

    protected $table = 'itens_pedido';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preco_unitario' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}
