<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Horas trabalhadas POR DIA num evento que atravessa vários dias (serviços longos):
// lista JSONB [{dia: 'Y-m-d', inicio: 'H:i', fim: 'H:i'}, ...], ordenada por dia.
// Null = evento de um só dia (ou multi-dia legado, tratado como bloco contínuo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->jsonb('horas_dias')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('horas_dias');
        });
    }
};
