<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aprovação PARCIAL das despesas (pedido da equipa, out. 2026): o aprovador aprova ou recusa
// cada linha; as recusadas ficam recusadas (com o motivo) e o registo fecha como «Aprovada
// parcialmente» (estado novo, só texto — a coluna estado é string). A contabilidade recebe só
// as linhas aprovadas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->boolean('recusada')->default(false)->after('pago_por');
            $table->string('motivo_recusa', 500)->nullable()->after('recusada');
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn(['recusada', 'motivo_recusa']);
        });
    }
};
