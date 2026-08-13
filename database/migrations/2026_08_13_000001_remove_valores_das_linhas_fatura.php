<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A equipa CANCELOU a funcionalidade dos valores das faturas (13/08) — as colunas criadas
// a 12/08 saem outra vez. Nenhum dado se perde: o worker nunca chegou a correr o código
// que as preenchia (0 linhas com valores em produção). O down() repõe-nas vazias.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->dropColumn(['preco_unitario', 'desconto', 'total_linha', 'total_documento', 'total_documento_iva', 'anulada']);
        });
    }

    public function down(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->decimal('preco_unitario', 14, 3)->nullable();
            $table->decimal('desconto', 6, 2)->nullable();
            $table->decimal('total_linha', 14, 2)->nullable();
            $table->decimal('total_documento', 14, 2)->nullable();
            $table->decimal('total_documento_iva', 14, 2)->nullable();
            $table->boolean('anulada')->default(false);
        });
    }
};
