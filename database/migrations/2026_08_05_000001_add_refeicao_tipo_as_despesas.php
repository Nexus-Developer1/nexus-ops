<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nota a) da folha de despesas em funcionamento: nas despesas de REFEIÇÕES indica-se
// A (almoço) ou J (jantar). Null para as restantes categorias e para o histórico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->string('refeicao_tipo', 1)->nullable(); // 'A' | 'J'
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn('refeicao_tipo');
        });
    }
};
