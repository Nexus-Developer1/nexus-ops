<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alertas programados atribuídos a um utilizador (pedido da equipa): nos equipamentos e nos
// contratos escolhe-se "equipa completa" (null) ou uma pessoa; nos eventos da agenda a
// atribuição é automática (os técnicos do evento) — sem coluna.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['equipamento_alertas_manutencao', 'contrato_alertas_visita'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('texto')->constrained('utilizadores')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['equipamento_alertas_manutencao', 'contrato_alertas_visita'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
