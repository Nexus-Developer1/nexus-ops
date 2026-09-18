<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema morto fora da base de dados: as duas tabelas da checklist antiga.
 *
 * A checklist genérica da intervenção foi substituída pelas FICHAS DE MEDIÇÃO (set. 2026) e
 * os modelos `ChecklistItem`/`ChecklistEtapa` saíram do código na limpeza de 16/09 (ce22ec4).
 * As tabelas ficaram para trás, vazias: zero linhas em produção e zero referências nas três
 * aplicações da suite (confirmado antes de escrever esta migração).
 *
 * A ordem importa: `checklist_itens.etapa_id` aponta para `checklist_etapas`, por isso os
 * itens saem primeiro. O `down()` repõe as duas tal como estavam — sem dados, que já não
 * existem em lado nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('checklist_itens');
        Schema::dropIfExists('checklist_etapas');
    }

    public function down(): void
    {
        Schema::create('checklist_etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->string('titulo');
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('checklist_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->foreignId('etapa_id')->nullable()->constrained('checklist_etapas')->cascadeOnDelete();
            $table->string('descricao');
            $table->boolean('concluido')->default(false);
            $table->unsignedInteger('ordem')->default(0);
            $table->text('observacao')->nullable();
            $table->timestamps();
        });
    }
};
