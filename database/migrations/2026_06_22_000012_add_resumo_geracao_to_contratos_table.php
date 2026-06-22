<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Resumo da última geração de visitas preventivas (total criado + sobreposições por
// equipamento detetadas). Apenas visibilidade — não bloqueia nem altera a geração.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->jsonb('resumo_geracao')->nullable()->after('periodo_aviso_dias');
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropColumn('resumo_geracao');
        });
    }
};
