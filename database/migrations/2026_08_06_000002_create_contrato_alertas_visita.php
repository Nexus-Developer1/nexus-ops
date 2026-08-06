<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alertas de visita PROGRAMADOS no contrato: a equipa marca uma data e escreve o texto do
// aviso (editável); o alerta entra no painel de alertas, no dashboard e no email diário a
// partir de 7 dias antes da data. Lembra de agendar as visitas incluídas (que são manuais).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrato_alertas_visita', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $tabela->date('data');       // quando o aviso deve disparar
            $tabela->string('texto');    // texto editável do aviso (ex.: "Agendar 1.ª visita preventiva")
            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_alertas_visita');
    }
};
