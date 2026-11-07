<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidade_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->restrictOnDelete();
            $table->decimal('preco', 10, 2);
            $table->boolean('disponivel')->default(true);
            // periodo sazonal como MM-DD, assim repete todo ano sem recadastro (RN16)
            $table->char('inicio_sazonal', 5)->nullable();
            $table->char('fim_sazonal', 5)->nullable();
            $table->string('descricao_regional')->nullable();
            $table->timestamps();

            $table->unique(['unidade_id', 'produto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidade_produtos');
    }
};
