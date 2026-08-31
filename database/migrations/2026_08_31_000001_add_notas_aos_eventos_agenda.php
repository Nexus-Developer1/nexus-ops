<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notas do evento: texto livre para o que não cabe nos campos (morada, contactos no local,
// indicações de acesso, o que levar…). Vai no detalhe, no email/convite aos técnicos, no feed e
// no calendário partilhado. Nullable: eventos antigos sem notas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->text('notas')->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('notas');
        });
    }
};
