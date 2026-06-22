<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assuntos de evento próprio (reunião/outro) — lookup que cresce com o uso.
// Apenas sugestões: o evento continua a guardar o texto em eventos_agenda.titulo
// (sem FK). Começa vazia; cada novo assunto fica disponível na próxima vez.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assuntos_evento', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_normalizado')->unique(); // impede duplicados (sem acentos/maiúsculas)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assuntos_evento');
    }
};
