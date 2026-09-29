<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Morada da visita no serviço da agenda (pedido da equipa, set. 2026): preenche-se com a do
// local do equipamento ou a do cliente, pode mudar-se à mão, e dá os botões Google Maps / Waze.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->string('morada', 500)->nullable()->after('notas');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('morada');
        });
    }
};
