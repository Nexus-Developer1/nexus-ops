<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sync incremental do ERP: cada registo sincronizado guarda a impressão digital (md5) dos
// dados que o ERP forneceu na última corrida. Na corrida seguinte, hash igual → registo
// saltado sem NENHUMA query (o mapa id_erp→hash carrega-se numa só leitura). Era isto que
// faltava à faturação: 191 mil updateOrCreate por corrida (~20 min) para meia dúzia de
// linhas novas. NULL = nunca sincronizado com hash (força o caminho de escrita).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->string('hash_sync', 32)->nullable());
        Schema::table('equipamentos', fn (Blueprint $t) => $t->string('hash_sync', 32)->nullable());
        Schema::table('linhas_fatura', fn (Blueprint $t) => $t->string('hash_sync', 32)->nullable());
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('hash_sync'));
        Schema::table('equipamentos', fn (Blueprint $t) => $t->dropColumn('hash_sync'));
        Schema::table('linhas_fatura', fn (Blueprint $t) => $t->dropColumn('hash_sync'));
    }
};
