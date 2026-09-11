<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assunto/motivo do evento (pedido da equipa, set. 2026): o "Tipo de evento" diz O QUE é
// (Serviço, Reunião, Férias…), isto diz PARA QUÊ ("Substituição de baterias da UPS").
// Opcional — umas férias não precisam de assunto.
//
// Chama-se `motivo` (e não `assunto`) porque "assunto" já é, por razões históricas, o nome
// do lookup dos TIPOS de evento (AssuntoEvento) — dois "assuntos" diferentes confundiam.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $t) {
            $t->string('motivo')->nullable()->after('titulo');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_agenda', function (Blueprint $t) {
            $t->dropColumn('motivo');
        });
    }
};
