<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Assinaturas na ficha (usadas na SADEI — deteção de incêndio exige assinatura do cliente
// e do técnico no local). A IMAGEM vive no object storage (CLAUDE.md §2: nada de blobs na
// BD) — aqui ficam só a storage_key e o nome de quem assinou, mais a data da assinatura.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fichas_medicao', function (Blueprint $t) {
            $t->string('assinatura_cliente_key')->nullable();
            $t->string('assinatura_cliente_nome')->nullable();
            $t->string('assinatura_tecnico_key')->nullable();
            $t->string('assinatura_tecnico_nome')->nullable();
            $t->timestamp('assinado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fichas_medicao', fn (Blueprint $t) => $t->dropColumn([
            'assinatura_cliente_key', 'assinatura_cliente_nome',
            'assinatura_tecnico_key', 'assinatura_tecnico_nome', 'assinado_em',
        ]));
    }
};
