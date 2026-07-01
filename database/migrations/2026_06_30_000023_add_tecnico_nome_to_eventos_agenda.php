<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nome do técnico como TEXTO LIVRE (com histórico), para eventos onde o técnico não é uma
// conta de utilizador. Coexiste com tecnico_id (conta) — quando há nome livre, tecnico_id
// fica null. Ver Agenda\Calendario (campo "Técnico" do modal de criar evento).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->string('tecnico_nome')->nullable()->after('tecnico_id');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('tecnico_nome');
        });
    }
};
