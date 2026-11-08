<?php

namespace App\Domain\Auditoria;

use Illuminate\Database\Eloquent\Model;
use LogicException;

// so insere: alterar ou apagar e proibido (RN18)
class RegistroAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'auditoria';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acao' => Acao::class,
            'dados_antes' => 'array',
            'dados_depois' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registro de auditoria não pode ser alterado'));
        static::deleting(fn () => throw new LogicException('Registro de auditoria não pode ser apagado'));
    }
}
