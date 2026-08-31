<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Feed ICS da agenda para o Outlook: token de subscrição por utilizador (nullable = sem feed).
// Validado contra a BD em cada pedido — revogar/regenerar invalida o URL antigo de imediato
// (um URL assinado não permitia isso). Único: um token identifica um utilizador.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->string('agenda_feed_token', 64)->nullable()->unique()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropUnique(['agenda_feed_token']);
            $table->dropColumn('agenda_feed_token');
        });
    }
};
