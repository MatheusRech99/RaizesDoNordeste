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

    // RNF04: perfil interno ve o e-mail e o telefone mascarados
    public function emailMascarado(): string
    {
        [$usuario, $dominio] = explode('@', $this->email, 2) + [1 => ''];

        return substr($usuario, 0, 1).'***@'.$dominio;
    }

    public function telefoneMascarado(): ?string
    {
        if (! $this->telefone) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $this->telefone);

        return str_repeat('*', max(strlen($digitos) - 4, 0)).substr($digitos, -4);
    }

    // a data de nascimento nunca sai; so a faixa (RNF04)
    public function faixaEtaria(): ?string
    {
        if (! $this->data_nascimento) {
            return null;
        }

        $idade = $this->data_nascimento->age;

        return match (true) {
            $idade < 18 => 'ATE_17',
            $idade < 25 => '18_24',
            $idade < 35 => '25_34',
            $idade < 45 => '35_44',
            $idade < 55 => '45_54',
            default => '55_MAIS',
        };
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
