<?php

namespace App\Domain\Usuarios;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consentimento extends Model
{
    protected $table = 'consentimentos';

    protected $fillable = ['usuario_id', 'finalidade', 'aceito', 'versao_termo', 'ip', 'revogado_em'];

    protected function casts(): array
    {
        return [
            'finalidade' => Finalidade::class,
            'aceito' => 'boolean',
            'revogado_em' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }

    public function ativo(): bool
    {
        return $this->aceito && $this->revogado_em === null;
    }
}
