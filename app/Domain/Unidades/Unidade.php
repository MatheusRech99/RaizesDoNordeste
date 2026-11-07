<?php

namespace App\Domain\Unidades;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Cardapio\ItemCardapio;
use Carbon\CarbonInterface;
use Database\Factories\UnidadeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unidade extends Model
{
    use HasFactory;

    protected $table = 'unidades';

    protected $fillable = ['nome', 'cidade', 'uf', 'regiao', 'formato', 'ativa'];

    protected function casts(): array
    {
        return [
            'formato' => FormatoUnidade::class,
            'ativa' => 'boolean',
        ];
    }

    protected static function newFactory(): UnidadeFactory
    {
        return UnidadeFactory::new();
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioFuncionamento::class)->orderBy('dia_semana');
    }

    public function itensCardapio(): HasMany
    {
        return $this->hasMany(ItemCardapio::class);
    }

    // RN02: a unidade tem que existir e estar ativa
    public static function ativaOuFalha(int $id): self
    {
        $unidade = self::where('ativa', true)->find($id);

        if (! $unidade) {
            throw new NegocioException(CodigoErro::UNIDADE_NAO_ENCONTRADA, 'Unidade não encontrada');
        }

        return $unidade;
    }

    // RN15: sem horario cadastrado no dia da semana a unidade esta fechada
    public function estaAberta(CarbonInterface $quando): bool
    {
        $horario = $this->horarios->firstWhere('dia_semana', $quando->dayOfWeek);

        if (! $horario) {
            return false;
        }

        $agora = $quando->format('H:i');

        return $agora >= substr($horario->abre, 0, 5) && $agora < substr($horario->fecha, 0, 5);
    }
}
