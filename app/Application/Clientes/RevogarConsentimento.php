<?php

namespace App\Application\Clientes;

use App\Domain\Auditoria\Acao;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Usuarios\Consentimento;
use App\Domain\Usuarios\Finalidade;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class RevogarConsentimento
{
    public function __construct(private AuditLogger $auditoria)
    {
    }

    public function executar(Usuario $cliente, Finalidade $finalidade): Consentimento
    {
        if ($finalidade === Finalidade::TERMOS) {
            throw new NegocioException(
                CodigoErro::VALIDACAO,
                'Os termos não podem ser revogados por aqui, solicite a exclusão da conta',
            );
        }

        $consentimento = $cliente->consentimentos()->where('finalidade', $finalidade)->first();

        if (! $consentimento || ! $consentimento->ativo()) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Não há consentimento ativo para essa finalidade');
        }

        DB::transaction(function () use ($cliente, $consentimento, $finalidade) {
            $consentimento->update(['revogado_em' => now()]);

            $this->auditoria->registrar(
                Acao::REVOGACAO_CONSENTIMENTO,
                $consentimento,
                ['finalidade' => $finalidade->value, 'aceito' => true],
                ['finalidade' => $finalidade->value, 'aceito' => false, 'revogadoEm' => $consentimento->revogado_em->toIso8601String()],
                $cliente->id,
            );

            // sem fidelidade nao pode ficar com a data de nascimento (RN14)
            if ($finalidade === Finalidade::FIDELIDADE) {
                $cliente->update(['data_nascimento' => null]);
            }
        });

        return $consentimento;
    }
}
