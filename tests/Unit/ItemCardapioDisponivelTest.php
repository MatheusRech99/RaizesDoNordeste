<?php

namespace Tests\Unit;

use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Cardapio\Produto;
use App\Domain\Unidades\Unidade;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// confere que o metodo em PHP e a consulta em SQL dao a mesma resposta
class ItemCardapioDisponivelTest extends TestCase
{
    use RefreshDatabase;

    private function criar(array $item = [], array $produto = []): ItemCardapio
    {
        return ItemCardapio::factory()->create($item + [
            'unidade_id' => Unidade::factory(),
            'produto_id' => Produto::factory()->create($produto)->id,
        ]);
    }

    private function noSql(ItemCardapio $item, string $data): bool
    {
        return ItemCardapio::cardapioDe($item->unidade, Carbon::parse($data))->where('unidade_produtos.id', $item->id)->exists();
    }

    private function conferir(ItemCardapio $item, string $data, bool $esperado): void
    {
        $item->load('produto');

        $this->assertSame($esperado, $item->disponivelEm(Carbon::parse($data)), "php em $data");
        $this->assertSame($esperado, $this->noSql($item, $data), "sql em $data");
    }

    public function test_sem_periodo_vale_o_ano_todo(): void
    {
        $item = $this->criar();

        $this->conferir($item, '2026-01-01', true);
        $this->conferir($item, '2026-12-31', true);
    }

    public function test_periodo_normal(): void
    {
        $item = $this->criar(['inicio_sazonal' => '05-15', 'fim_sazonal' => '07-15']);

        $this->conferir($item, '2026-05-14', false);
        $this->conferir($item, '2026-05-15', true);
        $this->conferir($item, '2026-06-30', true);
        $this->conferir($item, '2026-07-15', true);
        $this->conferir($item, '2026-07-16', false);
        $this->conferir($item, '2026-12-25', false);
    }

    public function test_periodo_que_passa_da_virada_do_ano(): void
    {
        $item = $this->criar(['inicio_sazonal' => '12-01', 'fim_sazonal' => '01-31']);

        $this->conferir($item, '2026-11-30', false);
        $this->conferir($item, '2026-12-01', true);
        $this->conferir($item, '2026-12-31', true);
        $this->conferir($item, '2027-01-01', true);
        $this->conferir($item, '2027-01-31', true);
        $this->conferir($item, '2027-02-01', false);
        $this->conferir($item, '2027-07-10', false);
    }

    public function test_periodo_de_um_dia_so(): void
    {
        $item = $this->criar(['inicio_sazonal' => '06-24', 'fim_sazonal' => '06-24']);

        $this->conferir($item, '2026-06-23', false);
        $this->conferir($item, '2026-06-24', true);
        $this->conferir($item, '2026-06-25', false);
    }

    public function test_indisponivel_ou_produto_inativo_nunca_vale(): void
    {
        $this->conferir($this->criar(['disponivel' => false]), '2026-06-20', false);
        $this->conferir($this->criar([], ['ativo' => false]), '2026-06-20', false);
    }
}
