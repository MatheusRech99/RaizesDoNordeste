<?php

namespace App\Application\Relatorios;

use App\Domain\Usuarios\Usuario;

// RF25: vendas (pedido pago e nao cancelado) no periodo, agrupadas por unidade, regiao ou canal
class RelatorioVendas
{
    public function executar(Usuario $quem, array $f, int $limite): array
    {
        $grupo = $f['agruparPor'] ?? 'unidade';

        $query = FiltrosRelatorio::pedidosVendidos($quem, $f);
        $totais = (clone $query)->selectRaw('count(*) as pedidos, coalesce(sum(p.total), 0) as faturamento')->first();

        $query->selectRaw('count(p.id) as pedidos, sum(p.total) as faturamento');
        // empate no faturamento desempata pela chave do grupo
        $desempate = match ($grupo) {
            'regiao' => 'u.regiao',
            'canal' => 'p.canal_pedido',
            default => 'u.id',
        };
        match ($grupo) {
            'regiao' => $query->addSelect('u.regiao')->groupBy('u.regiao'),
            'canal' => $query->addSelect('p.canal_pedido')->groupBy('p.canal_pedido'),
            default => $query->addSelect('u.id as unidade_id', 'u.nome as unidade', 'u.regiao')->groupBy('u.id', 'u.nome', 'u.regiao'),
        };

        $linhas = $query->orderByDesc('faturamento')->orderBy($desempate)->paginate($limite);

        return [
            'agruparPor' => $grupo,
            'periodo' => ['de' => $f['de'], 'ate' => $f['ate']],
            'totais' => self::resumo($totais),
            'linhas' => $linhas,
        ];
    }

    public static function resumo(object $linha): array
    {
        $faturamento = round((float) $linha->faturamento, 2);
        $pedidos = (int) $linha->pedidos;

        return [
            'pedidos' => $pedidos,
            'faturamento' => $faturamento,
            'ticketMedio' => $pedidos > 0 ? round($faturamento / $pedidos, 2) : 0.0,
        ];
    }
}
