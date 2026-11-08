<?php

namespace App\Application\Estoque;

use App\Domain\Auditoria\Acao;
use App\Domain\Cardapio\Produto;
use App\Domain\Estoque\Estoque;
use App\Domain\Estoque\MovimentacaoEstoque;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class MovimentarEstoque
{
    public function __construct(private AuditLogger $auditoria)
    {
    }

    // quantidade e positiva em ENTRADA/SAIDA/ESTORNO e vem com sinal no AJUSTE.
    // O pedido chama isso por item dentro da transacao dele, em ordem de produto_id (evita deadlock)
    public function executar(
        Unidade $unidade,
        int $produtoId,
        TipoMovimentacao $tipo,
        int $quantidade,
        string $motivo,
        Usuario $usuario,
        ?int $pedidoId = null,
    ): MovimentacaoEstoque {
        Produto::acharOuFalha($produtoId);

        return DB::transaction(function () use ($unidade, $produtoId, $tipo, $quantidade, $motivo, $usuario, $pedidoId) {
            // garante que a linha existe e depois trava para ler o saldo certo (RNF09)
            Estoque::firstOrCreate(['unidade_id' => $unidade->id, 'produto_id' => $produtoId]);
            $estoque = Estoque::where('unidade_id', $unidade->id)
                ->where('produto_id', $produtoId)
                ->lockForUpdate()
                ->first();

            $antes = $estoque->quantidade;
            $delta = $tipo->delta($quantidade);
            $estoque->aplicar($delta);

            $mov = $estoque->movimentacoes()->create([
                'tipo' => $tipo,
                'quantidade' => $delta,
                'saldo_apos' => $estoque->quantidade,
                'motivo' => $motivo,
                'pedido_id' => $pedidoId,
                'usuario_id' => $usuario->id,
            ]);

            // RN18: ajuste manual audita; a baixa do pedido fica na auditoria do pedido
            if ($pedidoId === null) {
                $this->auditoria->registrar(
                    Acao::AJUSTE_ESTOQUE,
                    $estoque,
                    ['quantidade' => $antes],
                    ['quantidade' => $estoque->quantidade, 'tipo' => $tipo->value, 'motivo' => $motivo],
                    $usuario->id,
                );
            }

            return $mov;
        });
    }
}
