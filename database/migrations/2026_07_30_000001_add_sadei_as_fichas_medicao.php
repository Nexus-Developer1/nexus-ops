<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ficha de Verificações SADEI (deteção/extinção de incêndio): equipamentos do tipo
// "incendio" têm uma ficha técnica própria (espelho da folha oficial Nexus 2024) em vez
// das medições elétricas da UPS. Vive num só JSONB — a folha tem ~60 itens em 9 secções
// e não justifica 60 colunas. NULL = ficha sem conteúdo SADEI (ex.: todas as fichas UPS).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_medicao', fn (Blueprint $t) => $t->jsonb('sadei')->nullable());
    }

    public function down(): void
    {
        Schema::table('fichas_medicao', fn (Blueprint $t) => $t->dropColumn('sadei'));
    }
};
