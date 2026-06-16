<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Campos adicionais vindos do ERP PHC (tabela cl). Aditivo — mantém a PK interna
// e a correlação por id_erp (CLAUDE.md §2/§5). ncont (NIF) mapeia para o nif existente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('codpost')->nullable()->after('morada');      // PHC cl.codpost
            $table->string('tlmvl', 40)->nullable()->after('telefone');  // PHC cl.tlmvl (telemóvel)
            $table->integer('vendedor')->nullable()->after('tlmvl');     // PHC cl.vendedor (código)
            $table->string('vendnm')->nullable()->after('vendedor');     // PHC cl.vendnm (nome do vendedor)
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn(['codpost', 'tlmvl', 'vendedor', 'vendnm']);
        });
    }
};
