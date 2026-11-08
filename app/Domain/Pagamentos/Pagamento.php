<?php

namespace App\Domain\Pagamentos;

use App\Domain\Pedidos\Pedido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pagamento extends Model
{
    protected $table = 'pagamentos';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => StatusPagamento::class,
            'valor' => 'decimal:2',
            'resposta_gateway' => 'array',
            'processado_em' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }
}
