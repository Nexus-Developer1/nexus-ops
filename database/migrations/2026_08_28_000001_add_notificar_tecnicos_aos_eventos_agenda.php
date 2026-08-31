<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notificação por email aos técnicos associados a um evento (criar / alterar / remover).
// A escolha fica NO EVENTO: um evento marcado ao criar continua a avisar quando é arrastado
// na agenda ou removido do detalhe — sítios onde não há formulário para voltar a perguntar.
// Default false: os eventos já existentes não começam a mandar emails sem ninguém pedir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->boolean('notificar_tecnicos')->default(false)->after('horas_dias');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $table) {
            $table->dropColumn('notificar_tecnicos');
        });
    }
};
