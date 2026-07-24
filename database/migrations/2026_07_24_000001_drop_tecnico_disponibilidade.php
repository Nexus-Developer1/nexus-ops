<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A marcação de ausências foi removida da agenda (2026-07-24, pedido da equipa): a tabela
// nunca chegou a ser usada em produção (0 linhas). O down() recria a estrutura original
// (de 2025_06_15_000008) para rollback limpo — os dados não são recuperáveis (eram zero).
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('tecnico_disponibilidade');
    }

    public function down(): void
    {
        Schema::create('tecnico_disponibilidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->constrained('utilizadores')->cascadeOnDelete();
            $table->string('tipo')->default('ausencia');  // ausencia|ferias|outro
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->string('motivo')->nullable();
            $table->timestamps();
            $table->index(['tecnico_id', 'inicio', 'fim']);
        });
    }
};
