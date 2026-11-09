<?php

use App\Domain\Fidelidade\TipoPontos;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pontos_fidelidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('pedido_id')->nullable()->constrained('pedidos')->restrictOnDelete();
            $table->enum('tipo', array_column(TipoPontos::cases(), 'value'));
            // com sinal (credito soma, estorno e debito subtraem); o saldo e a soma da coluna
            $table->integer('pontos');
            $table->timestamp('created_at')->useCurrent();

            // um credito e um estorno por pedido, mesmo se o fluxo rodar duas vezes
            $table->unique(['pedido_id', 'tipo']);
            $table->index(['usuario_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pontos_fidelidade');
    }
};
