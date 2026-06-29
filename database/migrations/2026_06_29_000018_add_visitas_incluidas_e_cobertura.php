<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 0 do novo modelo de visitas (nº fixo incluído por contrato + marcação
// incluída/extra por visita). Puramente aditivo: colunas nullable, sem backfill,
// sem alterar comportamento — a geração automática continua exatamente como está.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            // Total de visitas incluídas pela vida do contrato (null = não controlado).
            $table->unsignedInteger('visitas_incluidas')->nullable()->after('hora_visita');
        });

        Schema::table('eventos_agenda', function (Blueprint $table) {
            // 'incluida' (desconta saldo) | 'extra' (faturável) | null (não é visita de contrato).
            $table->string('cobertura')->nullable()->after('contrato_id');
        });
    }

    public function down(): void
    {
        Schema::table('contratos', function (Blueprint $table) {
            $table->dropColumn('visitas_incluidas');
        });

        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('cobertura');
        });
    }
};
