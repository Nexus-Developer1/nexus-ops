<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recomendações e próximos passos passam a ser POR EQUIPAMENTO (na ficha de medições, que já é
// uma por intervenção+equipamento), em vez de um campo único no relatório. A prioridade (Baixa/
// Normal/Alta) acompanha a recomendação de cada equipamento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $table) {
            $table->text('recomendacao')->nullable();
            $table->string('prioridade')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $table) {
            $table->dropColumn(['recomendacao', 'prioridade']);
        });
    }
};
