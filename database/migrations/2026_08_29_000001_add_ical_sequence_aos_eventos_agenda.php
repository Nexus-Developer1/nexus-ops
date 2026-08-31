<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Convites iCalendar para o Outlook: o SEQUENCE de cada evento. Começa em 0 na criação e
// incrementa a cada alteração ENVIADA; o cancelamento vai com SEQUENCE+1. Sem isto o Outlook
// ignora atualizações com sequence igual ou inferior ao que já tem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->unsignedInteger('ical_sequence')->default(0)->after('notificar_tecnicos');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('ical_sequence');
        });
    }
};
