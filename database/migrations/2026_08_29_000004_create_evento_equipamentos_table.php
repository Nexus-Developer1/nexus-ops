<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Equipamentos ADICIONAIS de um evento da agenda (além do principal em eventos_agenda.equipamento_id):
// um trabalho pode abranger vários equipamentos do mesmo cliente. Ao nascer o rascunho de relatório
// (ou ao iniciar a visita) passam para intervencao_equipamentos (os "cobertos" do relatório); num
// evento já convertido, editar aqui sincroniza os cobertos do relatório — uma só fonte de verdade.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_equipamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_agenda_id')->constrained('eventos_agenda')->cascadeOnDelete();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['evento_agenda_id', 'equipamento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_equipamentos');
    }
};
