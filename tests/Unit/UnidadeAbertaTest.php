<?php

namespace Tests\Unit;

use App\Domain\Unidades\Unidade;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadeAbertaTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        // segunda (1) das 10:00 as 22:00
        $unidade = Unidade::factory()->create();
        $unidade->horarios()->create(['dia_semana' => 1, 'abre' => '10:00:00', 'fecha' => '22:00:00']);

        return $unidade->load('horarios');
    }

    public function test_aberta_dentro_do_horario(): void
    {
        $segunda = Carbon::parse('2026-11-09 15:30');

        $this->assertTrue($this->unidade()->estaAberta($segunda));
    }

    public function test_fechada_antes_de_abrir(): void
    {
        $this->assertFalse($this->unidade()->estaAberta(Carbon::parse('2026-11-09 09:59')));
    }

    public function test_abre_na_hora_exata_e_fecha_na_hora_exata(): void
    {
        $unidade = $this->unidade();

        $this->assertTrue($unidade->estaAberta(Carbon::parse('2026-11-09 10:00')));
        $this->assertTrue($unidade->estaAberta(Carbon::parse('2026-11-09 21:59')));
        $this->assertFalse($unidade->estaAberta(Carbon::parse('2026-11-09 22:00')));
    }

    public function test_fechada_em_dia_sem_horario(): void
    {
        // 2026-11-10 e terca
        $this->assertFalse($this->unidade()->estaAberta(Carbon::parse('2026-11-10 15:00')));
    }

    public function test_domingo_e_dia_zero(): void
    {
        $unidade = Unidade::factory()->create();
        $unidade->horarios()->create(['dia_semana' => 0, 'abre' => '12:00:00', 'fecha' => '18:00:00']);
        $unidade->load('horarios');

        $this->assertTrue($unidade->estaAberta(Carbon::parse('2026-11-08 13:00')));
    }
}
