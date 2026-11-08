<?php

use App\Domain\Estoque\TipoMovimentacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->integer('quantidade')->default(0);
            $table->timestamps();

            $table->unique(['unidade_id', 'produto_id']);
        });

        Schema::create('movimentacoes_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estoque_id')->constrained('estoques')->restrictOnDelete();
            $table->enum('tipo', array_column(TipoMovimentacao::cases(), 'value'));
            // com sinal: positivo soma no saldo, negativo tira
            $table->integer('quantidade');
            $table->integer('saldo_apos');
            $table->string('motivo');
            // a FK para pedidos entra na etapa do pedido, quando a tabela existir
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['estoque_id', 'created_at']);
        });

        // sqlite (testes) nao aceita ADD CONSTRAINT, ai a validacao fica so na aplicacao
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE estoques ADD CONSTRAINT estoques_quantidade_nao_negativa CHECK (quantidade >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_estoque');
        Schema::dropIfExists('estoques');
    }
};
