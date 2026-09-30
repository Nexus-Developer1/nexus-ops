<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Os rascunhos (sem número) gravavam o PDF todos no MESMO ficheiro, «relatorios/.pdf», e o de
// um aparecia no de outro (27.ª revisão de segurança). O código novo dá a cada um o seu ficheiro;
// isto larga o apontador para o ficheiro partilhado nos que já o tinham — o próximo pedido gera
// o PDF certo. Acerto de dados, sem volta.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('relatorios')->where('pdf_path', 'relatorios/.pdf')->update(['pdf_path' => null]);
    }

    public function down(): void
    {
        // nada a repor
    }
};
