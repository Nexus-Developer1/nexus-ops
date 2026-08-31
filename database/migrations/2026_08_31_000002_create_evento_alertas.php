<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Alertas PROGRAMADOS num evento da agenda: data + texto editável (o que se quer que o aviso
// diga). Mesma mecânica dos alertas do contrato e do equipamento: entram no painel de alertas,
// no dashboard e no email diário a partir de 7 dias antes; alta quando a data chega/passa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_alertas', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('evento_agenda_id')->constrained('eventos_agenda')->cascadeOnDelete();
            $tabela->date('data');       // quando o aviso deve disparar
            $tabela->string('texto');    // texto editável do aviso (ex.: "Confirmar acesso com o cliente")
            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_alertas');
    }
};
