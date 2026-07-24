<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sequela da remoção da marcação de ausências (2026-07-24): tira "Ausência" do lookup
// "Tipo de evento" (assuntos_evento) para deixar de ser sugerida ao criar eventos.
// Eventos JÁ criados com esse título não são tocados (histórico do calendário).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assuntos_evento')->where('nome_normalizado', 'ausencia')->delete();
    }

    public function down(): void
    {
        DB::table('assuntos_evento')->insertOrIgnore([
            'nome' => 'Ausencia',
            'nome_normalizado' => 'ausencia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
