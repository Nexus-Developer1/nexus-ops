<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Calendário partilhado no M365 (Graph): id do evento correspondente no calendário. É o que torna
// a sincronização IDEMPOTENTE — com id, atualiza (PATCH) e apaga (DELETE) o mesmo evento; sem id,
// cria (POST) e guarda-o. Nullable: eventos ainda não sincronizados (ou com a via desligada).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->string('graph_event_id', 255)->nullable()->after('ical_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('graph_event_id');
        });
    }
};
