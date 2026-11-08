<?php

namespace App\Application\Pedidos;

use App\Application\Estoque\MovimentarEstoque;
use App\Domain\Auditoria\Acao;
use App\Domain\Cardapio\ItemCardapio;
use App\Domain\Estoque\TipoMovimentacao;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pedidos\CanalPedido;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Usuario;
use App\Infrastructure\Auditoria\AuditLogger;
use Illuminate\Support\Facades\DB;

class CriarPedido
{
    public function __construct(
        private MovimentarEstoque $estoque,
        private AuditLogger $auditoria,
    ) {
    }

    // $dados: canalPedido, itens [{produtoId, quantidade}] e clienteId (so o atendente manda).
    // A unidade ja vem validada e autorizada pelo controller
    public function executar(Usuario $quem, Unidade $unidade, array $dados, ?string $chave = null): Pedido
    {
        $agora = now();

        // RN15
        if (! $unidade->load('horarios')->estaAberta($agora)) {
            throw new NegocioException(CodigoErro::UNIDADE_FECHADA, 'A unidade está fora do horário de funcionamento');
        }

        $itens = collect($dados['itens'])->sortBy('produtoId')->values();
        $cardapio = ItemCardapio::where('unidade_id', $unidade->id)
            ->whereIn('produto_id', $itens->pluck('produtoId'))
            ->with('produto')
            ->get()
            ->keyBy('produto_id');

        $this->conferirCardapio($itens, $cardapio, $agora);

        // preco sempre do cardapio (RN04); conta em centavos para nao errar com float
        $total = 0;
        $linhas = [];
        foreach ($itens as $i) {
            $precoCentavos = (int) round($cardapio[$i['produtoId']]->preco * 100);
            $subtotal = $precoCentavos * $i['quantidade'];
            $total += $subtotal;
            $linhas[] = [
                'produto_id' => $i['produtoId'],
                'quantidade' => $i['quantidade'],
                'preco_unitario' => $this->reais($precoCentavos),
                'subtotal' => $this->reais($subtotal),
            ];
        }

        return DB::transaction(function () use ($quem, $unidade, $dados, $chave, $total, $linhas) {
            $pedido = Pedido::create([
                'unidade_id' => $unidade->id,
                'cliente_id' => $quem->perfil->value === 'CLIENTE' ? $quem->id : ($dados['clienteId'] ?? null),
                'criado_por' => $quem->id,
                'canal_pedido' => CanalPedido::from($dados['canalPedido']),
                'status' => StatusPedido::AGUARDANDO_PAGAMENTO,
                'subtotal' => $this->reais($total),
                'desconto' => '0.00',
                'total' => $this->reais($total),
                'idempotency_key' => $chave,
            ]);

            $pedido->itens()->createMany($linhas);

            $pedido->historico()->create([
                'status_anterior' => null,
                'status_novo' => StatusPedido::AGUARDANDO_PAGAMENTO,
                'usuario_id' => $quem->id,
            ]);

            // RN05: baixa de tudo ou nada; junta as faltas para avisar de todos os itens de uma vez
            $faltas = [];
            foreach ($linhas as $l) {
                try {
                    $this->estoque->executar($unidade, $l['produto_id'], TipoMovimentacao::SAIDA, $l['quantidade'], "Pedido #{$pedido->id}", $quem, $pedido->id);
                } catch (NegocioException $e) {
                    if ($e->codigo !== CodigoErro::ESTOQUE_INSUFICIENTE) {
                        throw $e;
                    }
                    $faltas = array_merge($faltas, $e->detalhes);
                }
            }

            if ($faltas) {
                throw new NegocioException(CodigoErro::ESTOQUE_INSUFICIENTE, 'Estoque insuficiente para um ou mais itens', $faltas);
            }

            $this->auditoria->registrar(Acao::CRIACAO_PEDIDO, $pedido, null, [
                'canalPedido' => $pedido->canal_pedido->value,
                'unidadeId' => $unidade->id,
                'total' => $pedido->total,
                'itens' => count($linhas),
            ], $quem->id);

            return $pedido->load('itens.produto');
        });
    }

    // RN02 (produto fora do cardapio da unidade) e RN16 (indisponivel ou fora do periodo)
    private function conferirCardapio($itens, $cardapio, $agora): void
    {
        $fora = $itens->pluck('produtoId')->reject(fn ($id) => $cardapio->has($id));

        if ($fora->isNotEmpty()) {
            throw new NegocioException(
                CodigoErro::PRODUTO_NAO_ENCONTRADO,
                'Produto fora do cardápio desta unidade',
                $fora->map(fn ($id) => ['produtoId' => $id])->values()->all(),
            );
        }

        $indisponiveis = $cardapio->reject(fn ($item) => $item->disponivelEm($agora));

        if ($indisponiveis->isNotEmpty()) {
            throw new NegocioException(
                CodigoErro::PRODUTO_INDISPONIVEL,
                'Há produtos indisponíveis no momento',
                $indisponiveis->map(fn ($item) => ['produtoId' => $item->produto_id, 'nome' => $item->produto->nome])->values()->all(),
            );
        }
    }

    private function reais(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
