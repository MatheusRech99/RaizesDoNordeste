<?php

namespace App\Domain\Cardapio;

use App\Domain\Unidades\Unidade;
use Carbon\CarbonInterface;
use Database\Factories\ItemCardapioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemCardapio extends Model
{
    use HasFactory;

    protected $table = 'unidade_produtos';

    protected $fillable = [
        'unidade_id', 'produto_id', 'preco', 'disponivel',
        'inicio_sazonal', 'fim_sazonal', 'descricao_regional',
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'disponivel' => 'boolean',
        ];
    }

    protected static function newFactory(): ItemCardapioFactory
    {
        return ItemCardapioFactory::new();
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    // RN16: indisponivel, produto inativo ou fora do periodo sazonal nao entra
    public function disponivelEm(CarbonInterface $data): bool
    {
        if (! $this->disponivel || ! $this->produto->ativo) {
            return false;
        }

        if (! $this->inicio_sazonal || ! $this->fim_sazonal) {
            return true;
        }

        $md = $data->format('m-d');

        if ($this->inicio_sazonal <= $this->fim_sazonal) {
            return $md >= $this->inicio_sazonal && $md <= $this->fim_sazonal;
        }

        // periodo que passa da virada do ano (ex.: 12-01 ate 01-31)
        return $md >= $this->inicio_sazonal || $md <= $this->fim_sazonal;
    }

    // mesma regra do disponivelEm, mas em SQL para poder paginar
    public static function cardapioDe(Unidade $unidade, CarbonInterface $data): Builder
    {
        $md = $data->format('m-d');

        return self::query()
            ->select('unidade_produtos.*')
            ->join('produtos', 'produtos.id', '=', 'unidade_produtos.produto_id')
            ->where('unidade_produtos.unidade_id', $unidade->id)
            ->where('unidade_produtos.disponivel', true)
            ->where('produtos.ativo', true)
            ->where(function ($q) use ($md) {
                $q->whereNull('inicio_sazonal')
                    ->orWhereNull('fim_sazonal')
                    ->orWhere(function ($q) use ($md) {
                        $q->whereColumn('inicio_sazonal', '<=', 'fim_sazonal')
                            ->where('inicio_sazonal', '<=', $md)
                            ->where('fim_sazonal', '>=', $md);
                    })
                    ->orWhere(function ($q) use ($md) {
                        $q->whereColumn('inicio_sazonal', '>', 'fim_sazonal')
                            ->where(function ($q) use ($md) {
                                $q->where('inicio_sazonal', '<=', $md)->orWhere('fim_sazonal', '>=', $md);
                            });
                    });
            })
            ->orderBy('produtos.categoria')
            ->orderBy('produtos.nome')
            ->with('produto');
    }
}
