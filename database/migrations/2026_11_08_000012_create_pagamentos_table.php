<?php

use App\Domain\Pagamentos\StatusPagamento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $table->enum('status', array_column(StatusPagamento::cases(), 'value'));
            $table->decimal('valor', 10, 2);
            $table->string('metodo', 20)->default('MOCK');
            // RN08: o retorno do gateway e idempotente por este id (varios NULL passam no UNIQUE)
            $table->string('transacao_externa_id')->nullable()->unique();
            // cada tentativa e uma linha; a chave fica so como referencia (quem barra repeticao e o middleware)
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->json('resposta_gateway')->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamps();

            $table->index(['pedido_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
