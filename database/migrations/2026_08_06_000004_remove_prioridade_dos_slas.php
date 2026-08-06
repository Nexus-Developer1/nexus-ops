<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A "Prioridade" sai dos SLAs do contrato (pedido da equipa): as linhas passam a ser só
// resposta/NBD + resolução + cobertura. A coluna fica (nullable) para o histórico; o unique
// por (contrato, prioridade) deixa de fazer sentido — sem prioridade, um contrato pode ter
// várias linhas de SLA livres.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_slas', function (Blueprint $tabela) {
            $tabela->dropUnique(['contrato_id', 'prioridade']);
        });
        DB::statement('alter table contrato_slas alter column prioridade drop not null');
    }

    public function down(): void
    {
        DB::statement('alter table contrato_slas alter column prioridade set not null');
        Schema::table('contrato_slas', function (Blueprint $tabela) {
            $tabela->unique(['contrato_id', 'prioridade']);
        });
    }
};
