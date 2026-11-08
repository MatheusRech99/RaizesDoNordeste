<?php

namespace App\Domain\Pedidos;

use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Usuario;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends Model
{
    use HasFactory;

    protected $table = 'pedidos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'canal_pedido' => CanalPedido::class,
            'status' => StatusPedido::class,
            'subtotal' => 'decimal:2',
            'desconto' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function newFactory(): PedidoFactory
    {
        return PedidoFactory::new();
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cliente_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemPedido::class);
    }

    public function historico(): HasMany
    {
        return $this->hasMany(HistoricoStatus::class)->orderBy('id');
    }
}
