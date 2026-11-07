<?php

namespace App\Domain\Cardapio;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use Database\Factories\ProdutoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Produto extends Model
{
    use HasFactory;

    protected $table = 'produtos';

    protected $fillable = ['nome', 'descricao', 'categoria', 'exige_cozinha_completa', 'ativo'];

    // mesmos defaults da migration, para o objeto recem criado ja vir preenchido
    protected $attributes = [
        'exige_cozinha_completa' => false,
        'ativo' => true,
    ];

    protected function casts(): array
    {
        return [
            'exige_cozinha_completa' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    protected static function newFactory(): ProdutoFactory
    {
        return ProdutoFactory::new();
    }

    public static function acharOuFalha(int $id): self
    {
        $produto = self::find($id);

        if (! $produto) {
            throw new NegocioException(CodigoErro::PRODUTO_NAO_ENCONTRADO, 'Produto não encontrado');
        }

        return $produto;
    }
}
