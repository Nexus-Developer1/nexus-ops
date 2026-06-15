<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Intervenções (ordens de trabalho). Centro da cadeia: equipamento -> intervenção -> relatório.
// contrato_id e evento_agenda_id ficam para a Fase 2 (nullable, sem FK por agora).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervencoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->unsignedBigInteger('contrato_id')->nullable();       // Fase 2
            $table->unsignedBigInteger('evento_agenda_id')->nullable();  // Fase 2
            $table->string('tipo');                       // preventiva | corretiva | instalacao
            $table->string('estado')->default('planeada'); // planeada | em_curso | concluida | cancelada
            $table->timestamp('data_inicio')->nullable();
            $table->timestamp('data_fim')->nullable();
            $table->text('descricao_problema')->nullable();
            $table->text('trabalho_realizado')->nullable();
            $table->text('observacoes')->nullable();
            $table->jsonb('diagnostico')->nullable(); // estado geral, leituras, anomalias
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervencoes');
    }
};
