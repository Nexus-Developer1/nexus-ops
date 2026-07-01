<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `series` (linhas_fatura) guarda TODOS os nºs de série de uma linha separados por vírgula.
// Uma linha pode vender muitas unidades (ex.: 133 discos), ultrapassando os 255 caracteres
// (SQLSTATE 22001). Passa para TEXT (sem limite). Remove também o índice btree em `series`:
// (1) um valor longo pode exceder o limite de tamanho de entrada do btree (~2704 bytes) —
// exatamente as linhas que rebentavam os 255; e (2) um índice de igualdade/prefixo sobre uma
// lista de séries não serve a pesquisa real (por UMA série via LIKE '%...%'). Se vier a ser
// preciso pesquisar por série, o caminho é um índice GIN trigram (pg_trgm) ou normalizar as
// séries numa tabela filha — mudança deliberada à parte.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->dropIndex(['series']);                  // remove o btree (inadequado + risco de tamanho)
            $table->text('series')->nullable()->change();   // 255 → TEXT (listas de dimensão imprevisível)
        });
    }

    public function down(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->string('series', 255)->nullable()->change();
            $table->index('series');
        });
    }
};
