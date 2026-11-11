<?php

namespace App\Http\Controllers\Api;

use App\Application\Relatorios\RankingProdutos;
use App\Application\Relatorios\RelatorioVendas;
use App\Http\Controllers\Controller;
use App\Http\Requests\RelatorioRequest;
use App\Http\Requests\RelatorioVendasRequest;

class RelatorioController extends Controller
{
    /**
     * Vendas no período
     *
     * ADMIN e GERENTE (só a sua unidade; outra unidade: 403). `de` e `ate` são obrigatórios (até 366 dias). Conta pedidos `PAGO`, `EM_PREPARO`, `PRONTO` e `ENTREGUE`. Agrupa por `unidade` (padrão), `regiao` ou `canal`; `totais` cobre todos os grupos.
     */
    public function vendas(RelatorioVendasRequest $request, RelatorioVendas $relatorio)
    {
        $r = $relatorio->executar(auth('api')->user(), $request->validated(), $request->limite());
        $grupo = $r['agruparPor'];
        $pagina = $r['linhas'];

        $dados = $pagina->getCollection()->map(function ($l) use ($grupo) {
            $resumo = RelatorioVendas::resumo($l);

            return match ($grupo) {
                'regiao' => ['regiao' => $l->regiao] + $resumo,
                'canal' => ['canalPedido' => $l->canal_pedido] + $resumo,
                default => ['unidadeId' => (int) $l->unidade_id, 'unidade' => $l->unidade, 'regiao' => $l->regiao] + $resumo,
            };
        })->values();

        return response()->json([
            'agruparPor' => $grupo,
            'periodo' => $r['periodo'],
            'totais' => $r['totais'],
            'data' => $dados,
            'meta' => $this->meta($pagina),
        ]);
    }

    /**
     * Ranking de produtos mais vendidos
     *
     * ADMIN e GERENTE (só a sua unidade). Ordenado por quantidade, no período informado.
     */
    public function produtosMaisVendidos(RelatorioRequest $request, RankingProdutos $ranking)
    {
        $pagina = $ranking->executar(auth('api')->user(), $request->validated(), $request->limite());
        $inicio = ($pagina->currentPage() - 1) * $pagina->perPage();

        $dados = $pagina->getCollection()->values()->map(fn ($l, $i) => [
            'posicao' => $inicio + $i + 1,
            'produtoId' => (int) $l->produto_id,
            'nome' => $l->nome,
            'quantidade' => (int) $l->quantidade,
            'faturamento' => round((float) $l->faturamento, 2),
            'pedidos' => (int) $l->pedidos,
        ]);

        return response()->json([
            'periodo' => ['de' => $request->validated('de'), 'ate' => $request->validated('ate')],
            'data' => $dados,
            'meta' => $this->meta($pagina),
        ]);
    }

    private function meta($pagina): array
    {
        return [
            'current_page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'total' => $pagina->total(),
            'last_page' => $pagina->lastPage(),
        ];
    }
}
