<?php

namespace App\Domain\Exceptions;

use Exception;

class NegocioException extends Exception
{
    public function __construct(
        public readonly CodigoErro $codigo,
        string $mensagem,
        public readonly array $detalhes = [],
    ) {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return $this->codigo->status();
    }
}
