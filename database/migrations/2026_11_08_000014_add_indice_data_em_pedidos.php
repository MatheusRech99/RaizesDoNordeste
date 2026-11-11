<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // RNF12: relatorio da rede inteira filtra so por periodo e status, sem unidade
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'status']);
        });
    }
};
