<?php

use App\Domain\Pedidos\CanalPedido;
use App\Domain\Pedidos\StatusPedido;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $status = array_column(StatusPedido::cases(), 'value');

        Schema::create('pedidos', function (Blueprint $table) use ($status) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            // vazio no balcao, quando o atendente nao identifica o cliente
            $table->foreignId('cliente_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('criado_por')->constrained('usuarios')->restrictOnDelete();
            $table->enum('canal_pedido', array_column(CanalPedido::cases(), 'value'));
            $table->enum('status', $status);
            $table->decimal('subtotal', 10, 2);
            $table->decimal('desconto', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('idempotency_key', 100)->nullable();
            $table->string('motivo_cancelamento')->nullable();
            $table->timestamps();

            $table->unique(['criado_por', 'idempotency_key']);
            $table->index(['unidade_id', 'status', 'canal_pedido', 'created_at']);
        });

        Schema::create('itens_pedido', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->unsignedInteger('quantidade');
            // preco do cardapio no momento do pedido (RN04)
            $table->decimal('preco_unitario', 10, 2);
            $table->decimal('subtotal', 10, 2);

            $table->unique(['pedido_id', 'produto_id']);
        });

        Schema::create('historico_status', function (Blueprint $table) use ($status) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            // vazio na criacao do pedido
            $table->enum('status_anterior', $status)->nullable();
            $table->enum('status_novo', $status);
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_status');
        Schema::dropIfExists('itens_pedido');
        Schema::dropIfExists('pedidos');
    }
};
