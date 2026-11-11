<?php

namespace App\Http\Controllers\Api;

use App\Application\Pedidos\AtualizarStatusPedido;
use App\Application\Pedidos\CancelarPedido;
use App\Application\Pedidos\CriarPedido;
use App\Domain\Exceptions\CodigoErro;
use App\Domain\Exceptions\NegocioException;
use App\Domain\Pedidos\Pedido;
use App\Domain\Pedidos\StatusPedido;
use App\Domain\Unidades\Unidade;
use App\Domain\Usuarios\Perfil;
use App\Http\Controllers\Controller;
use App\Http\Requests\AtualizarStatusRequest;
use App\Http\Requests\CancelarPedidoRequest;
use App\Http\Requests\CriarPedidoRequest;
use App\Http\Requests\ListarPedidosRequest;
use App\Http\Resources\PedidoResource;
use Illuminate\Support\Facades\Gate;

class PedidoController extends Controller
{
    /**
     * Cria pedido
     *
     * CLIENTE (qualquer canal menos `BALCAO`, e o pedido é dele) ou ATENDENTE da unidade (`clienteId` obrigatório fora do `BALCAO`). Aceita `Idempotency-Key`. O preço e o total vêm sempre do cardápio, o estoque é baixado na mesma transação e o pedido nasce em `AGUARDANDO_PAGAMENTO`.
     *
     * Erros: 404 `UNIDADE_NAO_ENCONTRADA` ou `PRODUTO_NAO_ENCONTRADO`; 409 `UNIDADE_FECHADA`, `PRODUTO_INDISPONIVEL`, `ESTOQUE_INSUFICIENTE` (todos os itens sem saldo em `details`) e `CHAVE_IDEMPOTENCIA_CONFLITANTE`.
     */
    public function store(CriarPedidoRequest $request, CriarPedido $criar)
    {
        $unidade = Unidade::ativaOuFalha($request->integer('unidadeId'));
        Gate::authorize('criarPedido', $unidade);

        $pedido = $criar->executar(
            auth('api')->user(),
            $unidade,
            $request->validated(),
            $request->header('Idempotency-Key'),
        );

        return (new PedidoResource($pedido))->response()->setStatusCode(201);
    }

    /**
     * Lista pedidos
     *
     * CLIENTE vê só os próprios; ATENDENTE, COZINHA e GERENTE só os da unidade (filtrar outra unidade: 403); ADMIN vê a rede toda. Do mais novo para o mais antigo.
     */
    public function index(ListarPedidosRequest $request)
    {
        $usuario = auth('api')->user();
        $unidadeId = $request->query('unidadeId');

        // RN13: equipe da unidade nao filtra por outra unidade
        $daEquipe = ! in_array($usuario->perfil, [Perfil::ADMIN, Perfil::CLIENTE]);
        if ($daEquipe && $unidadeId && (int) $unidadeId !== $usuario->unidade_id) {
            throw new NegocioException(CodigoErro::ACESSO_NEGADO, 'Você não tem permissão para esta unidade');
        }

        $pedidos = Pedido::visivelPara($usuario)
            ->when($unidadeId, fn ($q, $v) => $q->where('unidade_id', $v))
            ->when($request->query('canalPedido'), fn ($q, $v) => $q->where('canal_pedido', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->with('itens.produto')
            ->orderByDesc('id')
            ->paginate($request->limite());

        return PedidoResource::collection($pedidos);
    }

    /**
     * Consulta pedido com histórico de status
     *
     * Pedido de outro cliente ou inexistente: 404 `NAO_ENCONTRADO`. Equipe de outra unidade: 403.
     */
    public function show(int $id)
    {
        $pedido = Pedido::with(['itens.produto', 'historico'])->find($id);
        if (! $pedido) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Pedido não encontrado');
        }

        Gate::authorize('ver', $pedido);

        return new PedidoResource($pedido);
    }

    /**
     * Avança o status do pedido
     *
     * COZINHA move `PAGO` → `EM_PREPARO` → `PRONTO`; ATENDENTE move `PRONTO` → `ENTREGUE`, sempre na própria unidade (senão 403). Transição fora da máquina de status: 409 `TRANSICAO_INVALIDA`. `PAGO` e `CANCELADO` não passam por aqui.
     */
    public function atualizarStatus(int $id, AtualizarStatusRequest $request, AtualizarStatusPedido $atualizar)
    {
        $pedido = Pedido::find($id);
        if (! $pedido) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Pedido não encontrado');
        }

        $novo = StatusPedido::from($request->validated('status'));
        Gate::authorize('mudarStatus', [$pedido, $novo]);

        return new PedidoResource($atualizar->executar(auth('api')->user(), $pedido, $novo));
    }

    /**
     * Cancela o pedido
     *
     * CLIENTE (só o dele) cancela em `AGUARDANDO_PAGAMENTO`; ATENDENTE até `PAGO`; GERENTE até `EM_PREPARO`. Devolve o estoque, estorna o pagamento e os pontos e grava auditoria. Fora do limite do perfil: 403. Pedido `PRONTO`, `ENTREGUE` ou já cancelado: 409 `TRANSICAO_INVALIDA`. Falha no estorno do gateway: 503 `GATEWAY_INDISPONIVEL` (nada é cancelado).
     */
    public function cancelar(int $id, CancelarPedidoRequest $request, CancelarPedido $cancelar)
    {
        $pedido = Pedido::find($id);
        if (! $pedido) {
            throw new NegocioException(CodigoErro::NAO_ENCONTRADO, 'Pedido não encontrado');
        }

        // escopo primeiro (404 pra cliente de outro, 403 pra outra unidade); o limite por status e conferido no caso de uso
        Gate::authorize('ver', $pedido);

        return new PedidoResource($cancelar->executar(auth('api')->user(), $pedido, $request->validated('motivo')));
    }
}
