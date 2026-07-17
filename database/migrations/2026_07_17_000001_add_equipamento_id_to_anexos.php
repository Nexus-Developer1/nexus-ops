<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// As fotos dos relatórios passam a ser POR EQUIPAMENTO (junto da ficha de medições de cada um),
// em vez de um bloco único no relatório. Guarda-se em cada anexo a que equipamento pertence.
// Nulo = anexo geral (relatórios antigos, ou fotos não ligadas a um equipamento específico).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anexos', function (Blueprint $table) {
            $table->foreignId('equipamento_id')->nullable()->after('anexavel_id')
                ->constrained('equipamentos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('anexos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipamento_id');
        });
    }
};
