<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Índice na ligação equipamento → equipamento-pai (bancos de baterias associados a um UPS).
// É por ela que se contam os bancos de cada equipamento e se filtram «com / sem banco» na
// listagem; sem índice, cada contagem percorria a tabela inteira (set. 2026).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->index('equipamento_pai_id');
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->dropIndex(['equipamento_pai_id']);
        });
    }
};
