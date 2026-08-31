<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Curva completa do teste de descarga, importada do ficheiro do carregador (battest.txt):
// lista de amostras {t: "20:02:51", p: Vbat+, n: Vbat−}, já reduzida a ~600 pontos no upload.
// O gráfico do relatório desenha-se a partir daqui; a tabela manual fica como último recurso.
// JSON de números, não blob (CLAUDE.md §2 — o ficheiro em si não é guardado).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $table) {
            $table->json('descarga_curva')->nullable()->after('teste_descarga');
        });
    }

    public function down(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $table) {
            $table->dropColumn('descarga_curva');
        });
    }
};
