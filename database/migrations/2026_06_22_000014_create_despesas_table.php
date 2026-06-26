<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Despesas da operação (materiais, mão de obra, deslocações...). Ligam-se opcionalmente
// a cliente/equipamento/intervenção/contrato e distinguem faturável à parte vs incluído
// no contrato (CLAUDE.md §6 — faturação e rentabilidade). Área de gestão (admin).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despesas', function (Blueprint $table) {
            $table->id();
            $table->date('data');
            $table->string('categoria'); // material | mao_de_obra | deslocacao | outro
            $table->string('descricao');
            $table->decimal('valor', 12, 2);
            $table->boolean('faturavel')->default(false); // true = faturável à parte; false = incluído no contrato

            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('equipamento_id')->nullable()->constrained('equipamentos')->nullOnDelete();
            $table->foreignId('intervencao_id')->nullable()->constrained('intervencoes')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas');
    }
};
