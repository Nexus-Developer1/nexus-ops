<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// O feed ICS de subscrição saiu (a agenda vai para o Outlook pelo calendário partilhado do
// M365 e pelos convites por email — a via do feed nunca chegou a ser usada e exigia a porta
// 443 aberta). Sem página que os gere ou revogue, os tokens ficavam órfãos: a coluna sai.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropUnique(['agenda_feed_token']);
            $table->dropColumn('agenda_feed_token');
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->string('agenda_feed_token', 64)->nullable()->unique();
        });
    }
};
