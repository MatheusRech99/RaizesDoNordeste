<?php

use App\Domain\Unidades\FormatoUnidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cidade');
            $table->char('uf', 2);
            $table->string('regiao', 2)->index();
            $table->enum('formato', array_column(FormatoUnidade::cases(), 'value'));
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('horarios_funcionamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->time('abre');
            $table->time('fecha');

            $table->unique(['unidade_id', 'dia_semana']);
        });

        // sqlite (testes) nao aceita ADD CONSTRAINT, ai a validacao fica so na aplicacao
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE horarios_funcionamento ADD CONSTRAINT horarios_fecha_depois_abre CHECK (fecha > abre)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_funcionamento');
        Schema::dropIfExists('unidades');
    }
};
