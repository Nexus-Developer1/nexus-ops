<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Data de criação do equipamento NO PHC (ma.ousrdata/ousrhora): a ordenação "mais recentes"
// passa a seguir a ordem do PHC e não a ordem de inserção na app — sem isto, o backfill dos
// "sem cliente" (2026-08-06) pôs equipamentos antigos do PHC no topo da listagem.
// Preenchida pelo sync (campo do ERP, sempre alinhado); manuais ficam a null e ordenam
// pela data de registo na app (coalesce).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $tabela) {
            $tabela->timestamp('criado_erp_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $tabela) {
            $tabela->dropColumn('criado_erp_em');
        });
    }
};
