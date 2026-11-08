<?php

namespace App\Domain\Pedidos;

use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
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

    // RN13: o que cada perfil pode enxergar na listagem
    public function scopeVisivelPara(Builder $query, Usuario $usuario): void
    {
        match ($usuario->perfil) {
            Perfil::ADMIN => null,
            Perfil::CLIENTE => $query->where('cliente_id', $usuario->id),
            default => $query->where('unidade_id', $usuario->unidade_id),
        };
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
