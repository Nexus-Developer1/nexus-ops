<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remove a tabela `componentes` (histórico de baterias/peças). A funcionalidade nunca foi
// implementada — não havia escrita nem leitura, e a tabela estava vazia. O down() recria a
// estrutura original (definida na migração dos equipamentos) para a migração ser reversível.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('componentes');
    }

    public function down(): void
    {
        Schema::create('componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->string('tipo');
            $table->string('numero_serie')->nullable();
            $table->date('data_instalacao')->nullable();
            $table->date('data_substituicao')->nullable();
            $table->timestamps();
        });
    }
};
