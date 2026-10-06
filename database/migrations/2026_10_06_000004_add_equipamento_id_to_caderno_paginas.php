<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Caderno: uma página pode ficar ligada a um equipamento concreto do cliente (a ficha do
// equipamento mostra as páginas que falam dele). Opcional; se o equipamento for apagado, a
// página fica, só perde a ligação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('caderno_paginas', function (Blueprint $table) {
            $table->foreignId('equipamento_id')->nullable()->after('separador_id')->constrained('equipamentos')->nullOnDelete();
            $table->index('equipamento_id');
        });
    }

    public function down(): void
    {
        Schema::table('caderno_paginas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipamento_id');
        });
    }
};
