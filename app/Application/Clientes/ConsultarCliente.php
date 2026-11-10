<?php

namespace App\Application\Clientes;

use App\Domain\Auditoria\Acao;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class ConsultarCliente
{
    public function __construct(private AuditLogger $auditoria)
    {
    }

    // RNF06/RN18: leitura de dado pessoal por perfil interno grava auditoria antes de devolver;
    // se a auditoria falhar, nada e devolvido
    public function executar(Usuario $quem, Usuario $cliente): Usuario
    {
        DB::transaction(function () use ($quem, $cliente) {
            $this->auditoria->registrar(Acao::ACESSO_DADOS_PESSOAIS, $cliente, null, [
                'campos' => ['nome', 'email', 'telefone', 'faixaEtaria'],
            ], $quem->id);
        });

        return $cliente;
    }
}
