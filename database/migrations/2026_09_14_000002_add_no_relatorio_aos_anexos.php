<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Escolher que fotografias saem no PDF do cliente (pedido da equipa, set. 2026): o técnico
// tira fotos para registo interno (etiquetas, cabos, avarias) que não interessa enviar ao
// cliente. Cada foto passa a ter um interruptor «no relatório»; desligado, fica guardada na
// intervenção mas fora do PDF. Por defeito ligado — tudo o que já existe continua a sair.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anexos', function (Blueprint $t) {
            $t->boolean('no_relatorio')->default(true)->after('equipamento_id');
        });
    }

    public function down(): void
    {
        Schema::table('anexos', function (Blueprint $t) {
            $t->dropColumn('no_relatorio');
        });
    }
};
