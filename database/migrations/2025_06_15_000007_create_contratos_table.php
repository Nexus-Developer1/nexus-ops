<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Contratos de manutenção: âmbito, periodicidade, SLA e faturação (CLAUDE.md §4).
// Os planos de visita alimentam a geração automática de visitas preventivas (§6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->string('estado')->default('rascunho');   // rascunho|ativo|suspenso|expirado|renovado
            $table->string('tipo');                          // preventiva|corretiva|full_service
            $table->string('modelo_faturacao');              // avenca|por_visita|t_m
            $table->decimal('valor', 12, 2)->nullable();
            $table->string('periodo_faturacao')->nullable(); // mensal|trimestral|anual
            $table->text('coberturas')->nullable();
            $table->text('exclusoes')->nullable();
            $table->boolean('renovacao_automatica')->default(false);
            $table->unsignedInteger('periodo_aviso_dias')->default(30);
            $table->timestamps();
            $table->softDeletes();
        });

        // N:M contrato ↔ equipamento (âmbito coberto).
        Schema::create('contrato_equipamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['contrato_id', 'equipamento_id']);
        });

        // Planos de visita: periodicidade por tipo de equipamento → gera visitas.
        Schema::create('contrato_planos_visita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('equipamento_tipo');             // ups|gerador|pdu
            $table->string('periodicidade');                // mensal|trimestral|semestral|anual
            $table->unsignedInteger('duracao_estimada_min')->nullable();
            $table->timestamps();
        });

        // SLAs por prioridade (medidos contra as intervenções corretivas).
        Schema::create('contrato_slas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('prioridade');                   // critica|alta|normal
            $table->unsignedInteger('tempo_resposta_horas')->nullable();
            $table->unsignedInteger('tempo_resolucao_horas')->nullable();
            $table->string('horario_cobertura')->default('8x5'); // 8x5|24x7
            $table->timestamps();
            $table->unique(['contrato_id', 'prioridade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contrato_slas');
        Schema::dropIfExists('contrato_planos_visita');
        Schema::dropIfExists('contrato_equipamentos');
        Schema::dropIfExists('contratos');
    }
};
