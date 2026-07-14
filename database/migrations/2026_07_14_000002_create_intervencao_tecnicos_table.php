<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Técnicos COLABORADORES de uma intervenção/relatório (N:M). O técnico PRINCIPAL continua
// em intervencoes.tecnico_id (atribuição, agenda e cadeia inalteradas); esta tabela acrescenta
// os restantes técnicos que também participaram na mesma intervenção.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervencao_tecnicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('utilizadores')->cascadeOnDelete();
            $table->unique(['intervencao_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervencao_tecnicos');
    }
};
