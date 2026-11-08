<?php

namespace App\Infrastructure\Auditoria;

use App\Domain\Auditoria\Acao;
use App\Domain\Auditoria\RegistroAuditoria;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    // nao abre transacao: quem chama deve estar dentro da transacao da operacao (RN18)
    // nunca colocar senha ou data de nascimento em $antes/$depois
    public function registrar(Acao $acao, Model $entidade, ?array $antes = null, ?array $depois = null, ?int $usuarioId = null): RegistroAuditoria
    {
        return RegistroAuditoria::create([
            'usuario_id' => $usuarioId ?? auth('api')->id(),
            'acao' => $acao,
            'entidade' => $entidade->getTable(),
            'entidade_id' => $entidade->getKey(),
            'dados_antes' => $antes,
            'dados_depois' => $depois,
            'ip' => request()->ip(),
        ]);
    }
}
