<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Encomendas de peças escritas À MÃO no relatório (pedido da equipa, set. 2026): a encomenda
// é criada no PHC durante a visita, mas o sync dos dossiês só corre às 8h, 13h e 19h — até
// lá não aparece na pesquisa. Guarda-se o nº e o ANO (a numeração recomeça todos os anos no
// PHC: a nº 3408 existe em 2026, em 2025, em 2024…). Quando o dossiê chega, a linha daqui
// passa a ligação normal (intervencao_encomenda) e é apagada — ver LigadorEncomendasManuais.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intervencao_encomendas_manuais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intervencao_id')->constrained('intervencoes')->cascadeOnDelete();
            $t->unsignedInteger('obrano');
            $t->unsignedSmallInteger('ano');
            $t->foreignId('criado_por')->nullable()->constrained('utilizadores')->nullOnDelete();
            $t->timestamps();
            $t->unique(['intervencao_id', 'obrano', 'ano']);
            $t->index(['obrano', 'ano']); // o sync procura por aqui
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intervencao_encomendas_manuais');
    }
};
