<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remove o último rasto do esquema morto (fecho das rondas de limpeza de 17 e 24/08):
//
//  - `categorias_despesa`: a tabela ficou órfã quando o modelo CategoriaDespesa saiu na
//    1.ª ronda — nada a lê nem escreve; as categorias das despesas vivem na constante
//    Despesa::CATEGORIAS e no texto da coluna despesas.categoria. Em produção só tinha
//    as 4 seeds da migração original.
//
//  - `eventos_agenda.recorrencia`: metadado RRULE do modelo antigo de visitas geradas
//    por periodicidade, documentado "em desuso" desde junho (CLAUDE.md §4/§6: o saldo
//    conta eventos pela cobertura, não por periodicidade). Em produção: 0 valores.
//
// O down() repõe a ESTRUTURA (e as seeds de categorias, como a migração original) — os
// dados apagados não voltam, mas não havia nenhuns além das seeds.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('categorias_despesa');

        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('recorrencia');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->string('recorrencia')->nullable();
        });

        Schema::create('categorias_despesa', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_normalizado')->unique();
            $table->timestamps();
        });
    }
};
