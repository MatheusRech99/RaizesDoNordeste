<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chaves_idempotencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            // metodo + rota, para a mesma chave poder ser usada em endpoints diferentes
            $table->string('escopo', 150);
            $table->string('chave', 100);
            $table->char('hash_corpo', 64);
            // nulos enquanto a requisicao original ainda esta rodando
            $table->unsignedSmallInteger('status_resposta')->nullable();
            $table->longText('corpo_resposta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expira_em')->index();

            $table->unique(['usuario_id', 'escopo', 'chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chaves_idempotencia');
    }
};
