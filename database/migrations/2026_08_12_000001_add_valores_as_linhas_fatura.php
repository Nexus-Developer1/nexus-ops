<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Valores das faturas do PHC (pedido da equipa, 12/08): preço/desconto/total por linha
// (fi) + totais do documento sem/com IVA (ft, denormalizados por linha — as linhas do
// mesmo documento agrupam-se por nmdoc+fno) + flag de anulada (marcada, não escondida —
// o histórico do equipamento não perde o rasto). Tudo ERP-owned, preenchido pelo sync;
// a primeira corrida após o deploy reprocessa as ~191 mil linhas (hash novo) uma vez.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->decimal('preco_unitario', 14, 3)->nullable();     // fi.epv
            $table->decimal('desconto', 6, 2)->nullable();            // fi.desconto (%)
            $table->decimal('total_linha', 14, 2)->nullable();        // fi.etotal (sem IVA)
            $table->decimal('total_documento', 14, 2)->nullable();    // ft.etotal (sem IVA)
            $table->decimal('total_documento_iva', 14, 2)->nullable(); // ft.ettotal (com IVA)
            $table->boolean('anulada')->default(false);               // ft.anulado
        });
    }

    public function down(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->dropColumn(['preco_unitario', 'desconto', 'total_linha', 'total_documento', 'total_documento_iva', 'anulada']);
        });
    }
};
