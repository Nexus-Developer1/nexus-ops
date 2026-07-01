<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nº de cliente da fatura (PHC ft.no, via ftstamp) → correlaciona com clientes.id_erp.
// Aditivo: permite mostrar as faturas na ficha de cada cliente (Fase 2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->string('cliente_no', 50)->nullable()->after('id_erp'); // PHC ft.no = clientes.id_erp
            $table->index('cliente_no');
        });
    }

    public function down(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->dropIndex(['cliente_no']);
            $table->dropColumn('cliente_no');
        });
    }
};
