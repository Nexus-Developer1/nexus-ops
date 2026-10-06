<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Envio AGENDADO do relatório ao cliente (out. 2026): na página de envio escolhe-se Imediato
// ou daqui a 30 min / 1 / 2 / 4 / 8 / 24 h. O job vai para a fila com atraso; aqui fica o que
// está marcado — para se VER na listagem e no relatório, e para se poder CANCELAR ou
// SUBSTITUIR: o job só envia se o token dele ainda for o do relatório (um job atrasado não se
// tira da fila; invalida-se). Tudo a null = nada agendado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->timestamp('envio_agendado_em')->nullable()->after('enviado_para');
            $table->string('envio_agendado_token', 36)->nullable()->after('envio_agendado_em');
            $table->string('envio_agendado_destino', 1000)->nullable()->after('envio_agendado_token');
        });
    }

    public function down(): void
    {
        Schema::table('relatorios', function (Blueprint $table) {
            $table->dropColumn(['envio_agendado_em', 'envio_agendado_token', 'envio_agendado_destino']);
        });
    }
};
