<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Equipamentos / ativos (UPS, geradores, PDUs). Origem: aplicação.
// Atributos específicos do tipo (ex.: UPS) ficam em JSONB; proxima_troca_baterias
// é coluna própria por ser usada em alertas (ver secção 6 do CLAUDE.md).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_id')->constrained('locais')->cascadeOnDelete();
            $table->string('tipo'); // ups | gerador | pdu
            $table->string('fabricante')->nullable();
            $table->string('modelo')->nullable();
            $table->string('numero_serie')->nullable()->index();
            $table->date('data_instalacao')->nullable();
            $table->date('fim_garantia')->nullable();
            $table->string('estado')->default('operacional'); // operacional | degradado | critico | inativo
            $table->date('proxima_troca_baterias')->nullable();
            $table->jsonb('atributos')->nullable(); // específicos do tipo (UPS: potencia_kva, topologia, ...)
            $table->string('qr_code')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        // Histórico de componentes (baterias, peças) por equipamento.
        Schema::create('componentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipamento_id')->constrained('equipamentos')->cascadeOnDelete();
            $table->string('tipo'); // bateria | ventilador | ...
            $table->string('numero_serie')->nullable();
            $table->date('data_instalacao')->nullable();
            $table->date('data_substituicao')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('componentes');
        Schema::dropIfExists('equipamentos');
    }
};
