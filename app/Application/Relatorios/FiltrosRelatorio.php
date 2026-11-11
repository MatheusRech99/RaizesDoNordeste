<?php

namespace App\Application\Relatorios;

use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Usuarios\Perfil;
use App\Domain\Usuarios\Usuario;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// base dos relatorios: pedidos vendidos no periodo, ja com o escopo do usuario (RN13)
class FiltrosRelatorio
{
    // $f: de, ate (AAAA-MM-DD), unidadeId, regiao, canalPedido
    public static function pedidosVendidos(Usuario $quem, array $f): Builder
    {
        $unidadeId = $f['unidadeId'] ?? null;

        // gerente so enxerga a propria unidade; pedir outra e 403
        if ($quem->perfil === Perfil::GERENTE) {
            if ($unidadeId && (int) $unidadeId !== $quem->unidade_id) {
                throw new NegocioException(CodigoErro::ACESSO_NEGADO, 'Você não tem permissão para esta unidade');
            }
            $unidadeId = $quem->unidade_id;
        }

        return DB::table('pedidos as p')
            ->join('unidades as u', 'u.id', '=', 'p.unidade_id')
            ->whereIn('p.status', array_column(StatusPedido::vendas(), 'value'))
            ->where('p.created_at', '>=', $f['de'].' 00:00:00')
            ->where('p.created_at', '<=', $f['ate'].' 23:59:59')
            ->when($unidadeId, fn ($q, $v) => $q->where('p.unidade_id', $v))
            ->when($f['regiao'] ?? null, fn ($q, $v) => $q->where('u.regiao', $v))
            ->when($f['canalPedido'] ?? null, fn ($q, $v) => $q->where('p.canal_pedido', $v));
    }
}
