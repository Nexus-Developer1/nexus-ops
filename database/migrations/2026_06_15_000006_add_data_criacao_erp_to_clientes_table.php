<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Data REAL de criação do cliente no ERP (PHC cl.ousrdata). Usada só para
// ordenação/informação — NÃO substitui o created_at da app. A população é feita
// à parte (a partir do schema phc), por isso a migration é apenas de esquema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->timestamp('data_criacao_erp')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('data_criacao_erp');
        });
    }
};
