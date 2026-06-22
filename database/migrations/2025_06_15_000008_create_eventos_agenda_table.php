<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agenda: projeção temporal de visitas preventivas, intervenções e eventos próprios
// (CLAUDE.md §4 e §6). A agenda gera as intervenções (fonte de verdade — decisão #4).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_agenda', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');                       // visita_preventiva|intervencao|ausencia|outro
            $table->string('titulo');
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->string('estado')->default('planeado'); // planeado|confirmado|em_curso|concluido|cancelado

            $table->foreignId('tecnico_id')->nullable()->constrained('utilizadores')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('local_id')->nullable()->constrained('locais')->nullOnDelete();
            $table->foreignId('equipamento_id')->nullable()->constrained('equipamentos')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('intervencao_id')->nullable()->constrained('intervencoes')->nullOnDelete();

            // RRULE — metadado em DESUSO: escrita mas nunca lida (não há motor de RRULE;
            // a recorrência é materializada como uma linha por ocorrência). Ver EventoAgenda.
            $table->string('recorrencia')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inicio', 'fim']);
            $table->index('tecnico_id');
        });

        // Disponibilidade do técnico: ausências/férias → deteção de conflitos e capacidade.
        Schema::create('tecnico_disponibilidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tecnico_id')->constrained('utilizadores')->cascadeOnDelete();
            $table->string('tipo')->default('ausencia');  // ausencia|ferias|outro
            $table->timestamp('inicio');
            $table->timestamp('fim');
            $table->string('motivo')->nullable();
            $table->timestamps();

            $table->index(['tecnico_id', 'inicio', 'fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tecnico_disponibilidade');
        Schema::dropIfExists('eventos_agenda');
    }
};
