<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alertas de manutenção PROGRAMADOS no equipamento (par do contrato_alertas_visita): a equipa
// marca uma data e escreve o texto do aviso (editável); entra no painel de alertas, no dashboard
// e no email diário a partir de 7 dias antes da data. Complementa o alerta automático de
// troca de baterias (proxima_troca_baterias), que se mantém.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipamento_alertas_manutencao', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $tabela->date('data');       // quando o aviso deve disparar
            $tabela->string('texto');    // texto editável (ex.: "Manutenção anual — teste de autonomia")
            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipamento_alertas_manutencao');
    }
};
