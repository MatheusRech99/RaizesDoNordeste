<?php

namespace App\Domain\Pagamentos;

// o que o gateway devolveu quando conseguiu responder (APROVADO ou RECUSADO)
class RespostaGateway
{
    public function __construct(
        public readonly StatusPagamento $status,
        public readonly string $transacaoId,
        public readonly ?string $motivo = null,
        public readonly array $bruta = [],
    ) {
    }
}
