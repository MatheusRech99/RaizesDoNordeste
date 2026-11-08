<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->string('acao', 60);
            $table->string('entidade', 60);
            $table->unsignedBigInteger('entidade_id')->nullable();
            $table->json('dados_antes')->nullable();
            $table->json('dados_depois')->nullable();
            $table->string('ip', 45)->nullable();
            // so created_at, o registro nunca muda
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entidade', 'entidade_id']);
            $table->index(['acao', 'created_at']);
        });

        // RN18: o banco tambem barra UPDATE e DELETE, nao so o model
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER auditoria_sem_update BEFORE UPDATE ON auditoria BEGIN SELECT RAISE(ABORT, 'auditoria nao pode ser alterada'); END");
            DB::unprepared("CREATE TRIGGER auditoria_sem_delete BEFORE DELETE ON auditoria BEGIN SELECT RAISE(ABORT, 'auditoria nao pode ser apagada'); END");
        } else {
            DB::unprepared("CREATE TRIGGER auditoria_sem_update BEFORE UPDATE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auditoria nao pode ser alterada'");
            DB::unprepared("CREATE TRIGGER auditoria_sem_delete BEFORE DELETE ON auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auditoria nao pode ser apagada'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
