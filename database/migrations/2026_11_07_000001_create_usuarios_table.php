<?php

use App\Domain\Usuarios\Perfil;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('email')->unique();
            $table->string('senha');
            $table->enum('perfil', array_column(Perfil::cases(), 'value'));
            // a FK para unidades entra na etapa 3, quando a tabela existir
            $table->unsignedBigInteger('unidade_id')->nullable();
            $table->string('telefone')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->timestamp('anonimizado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
