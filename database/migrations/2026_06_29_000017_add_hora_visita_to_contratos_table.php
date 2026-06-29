<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hora de início (única por contrato) aplicada às visitas preventivas geradas.
// Nullable: se vazia, o gerador usa as 09:00 por defeito (comportamento anterior).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->time('hora_visita')->nullable()->after('data_fim');
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropColumn('hora_visita');
        });
    }
};
