<?php

namespace App\Domain\Usuarios;

use Database\Factories\UsuarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Usuario extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $table = 'usuarios';

    protected $fillable = [
        'nome', 'email', 'senha', 'perfil', 'unidade_id', 'telefone', 'data_nascimento',
    ];

    protected $hidden = ['senha', 'data_nascimento'];

    protected function casts(): array
    {
        return [
            'senha' => 'hashed',
            'perfil' => Perfil::class,
            'data_nascimento' => 'date',
            'anonimizado_em' => 'datetime',
        ];
    }

    protected static function newFactory(): UsuarioFactory
    {
        return UsuarioFactory::new();
    }

    public function consentimentos(): HasMany
    {
        return $this->hasMany(Consentimento::class);
    }

    public function temConsentimento(Finalidade $finalidade): bool
    {
        return $this->consentimentos()
            ->where('finalidade', $finalidade)
            ->where('aceito', true)
            ->whereNull('revogado_em')
            ->exists();
    }

    public function getAuthPassword(): string
    {
        return $this->senha;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
