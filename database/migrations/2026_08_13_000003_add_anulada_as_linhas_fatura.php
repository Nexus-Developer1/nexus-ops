<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Flag de fatura ANULADA no PHC (Vaga 1): uma fatura anulada aparecia na app como válida —
// correção de qualidade de dados, separada (e sobrevivente) da feature de valores que a
// equipa cancelou a 13/08. Read-only, preenchida pelo sync a partir de ft.anulado.
// Nota: entra no hash do incremental — a 1.ª corrida reprocessa as ~191 mil linhas (uma vez).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->boolean('anulada')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('linhas_fatura', function (Blueprint $table) {
            $table->dropColumn('anulada');
        });
    }
};
