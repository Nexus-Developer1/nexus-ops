<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Frequência de SAÍDA da UPS, à parte da de entrada (pedido da equipa, set. 2026). São duas
// medições diferentes — em bateria ou num conversor de frequência a saída não segue a rede.
// A coluna `frequencia` que já existia fica como a de ENTRADA (é o que sempre foi lido nela);
// as fichas antigas ficam com a de saída vazia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $t) {
            $t->decimal('frequencia_saida', 8, 2)->nullable()->after('frequencia');
        });
    }

    public function down(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $t) {
            $t->dropColumn('frequencia_saida');
        });
    }
};
