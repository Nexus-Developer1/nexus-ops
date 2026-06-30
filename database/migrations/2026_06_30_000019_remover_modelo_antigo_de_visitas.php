<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 5 — remoção destrutiva do modelo antigo de visitas (periodicidade + geração
// automática). Larga a tabela de planos de visita e as colunas que só serviam a geração
// (hora_visita, resumo_geracao). O modelo novo (visitas_incluidas + cobertura/saldo) fica
// intacto. O down() recria a ESTRUTURA (vazia) — os dados só se recuperam de backup.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contrato_planos_visita');

        Schema::table('contratos', function (Blueprint $table) {
            $table->dropColumn(['hora_visita', 'resumo_geracao']);
        });
    }

    public function down(): void
    {
        Schema::create('contrato_planos_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('equipamento_tipo');             // ups|gerador|pdu
            $table->string('periodicidade');                // mensal|trimestral|semestral|anual
            $table->unsignedInteger('duracao_estimada_min')->nullable();
            $table->timestamps();
        });

        Schema::table('contratos', function (Blueprint $table) {
            $table->time('hora_visita')->nullable()->after('data_fim');
            $table->jsonb('resumo_geracao')->nullable()->after('periodo_aviso_dias');
        });
    }
};
