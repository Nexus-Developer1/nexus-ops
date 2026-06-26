<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Equipamentos ADICIONAIS cobertos por uma intervenção/relatório (N:M). O equipamento
// principal continua em intervencoes.equipamento_id (cadeia/PDF/sync inalterados); esta
// tabela acrescenta os restantes que o mesmo relatório também cobre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervencao_equipamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->unique(['intervencao_id', 'equipamento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervencao_equipamentos');
    }
};
