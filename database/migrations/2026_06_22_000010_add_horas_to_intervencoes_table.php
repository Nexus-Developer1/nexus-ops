<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hora de início/fim da intervenção (intervalo). NULLABLE para não partir
// rascunhos nem relatórios já existentes — as horas são opcionais mesmo ao finalizar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intervencoes', function (Blueprint $table) {
            $table->time('hora_inicio')->nullable()->after('data_inicio');
            $table->time('hora_fim')->nullable()->after('hora_inicio');
        });
    }

    public function down(): void
    {
        Schema::table('intervencoes', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio', 'hora_fim']);
        });
    }
};
