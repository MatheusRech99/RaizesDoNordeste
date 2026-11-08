<?php

use App\Domain\Usuarios\Finalidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consentimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->enum('finalidade', array_column(Finalidade::cases(), 'value'));
            $table->boolean('aceito');
            $table->string('versao_termo', 20);
            $table->string('ip', 45);
            $table->timestamp('revogado_em')->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'finalidade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentimentos');
    }
};
