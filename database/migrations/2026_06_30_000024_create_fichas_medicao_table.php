<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ficha de medições estruturada por (intervenção, equipamento) — substitui a checklist
// genérica. Toda a frota é Riello UPS; o `tipo_equipamento` é o discriminador (só UPS por
// agora). Todos os campos são NULLABLE (preenchem-se durante a visita). Elétricos em
// decimal tipado; grelhas repetitivas (módulos, bancos, teste de descarga) e verificações
// em jsonb. Coexiste com a checklist antiga (não removida nesta fase).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas_medicao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->string('tipo_equipamento')->default('ups'); // discriminador

            // Dados do equipamento (pré-preenchidos do equipamento, editáveis).
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('serie')->nullable();
            $table->string('baterias')->nullable();

            // Configuração.
            $table->string('config_tipo')->nullable();       // simples | modular | paralelo
            $table->boolean('bypass_externo')->nullable();
            $table->jsonb('modulos')->nullable();             // até 4× {modelo, sn}
            $table->jsonb('bancos_bateria')->nullable();      // até 4× {modelo, sn}

            // Valores elétricos (V / % / Hz / A / °C).
            $eletricos = [
                've_ln_l1', 've_ln_l2', 've_ln_l3',
                've_ll_l1l2', 've_ll_l1l3', 've_ll_l2l3',
                'carga_l1', 'carga_l2', 'carga_l3',
                'frequencia',
                'vs_ln_l1', 'vs_ln_l2', 'vs_ln_l3',
                'vs_ll_l1l2', 'vs_ll_l1l3', 'vs_ll_l2l3',
                'is_l1', 'is_l2', 'is_l3',
                'ispico_l1', 'ispico_l2', 'ispico_l3',
                'vbat_pos', 'vbat_neg',
                'temperatura',
            ];
            foreach ($eletricos as $col) {
                $table->decimal($col, 8, 2)->nullable();
            }

            // Verificações (9× {estado: ok|nok|null, nota}) e teste de descarga (grelha).
            $table->jsonb('verificacoes')->nullable();
            $table->jsonb('teste_descarga')->nullable();
            $table->string('baterias_funcionamento')->nullable(); // ok | nok

            // Relatório final.
            $table->string('carga_a_funcionar')->nullable();      // ok | nok
            $table->string('ups_modo_normal')->nullable();        // ok | nok
            $table->text('notas_finais')->nullable();

            $table->timestamps();

            $table->unique(['intervencao_id', 'equipamento_id']); // uma ficha por equipamento no relatório
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas_medicao');
    }
};
