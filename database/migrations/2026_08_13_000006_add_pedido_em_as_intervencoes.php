<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vaga 2 (SLA): instante do PEDIDO do cliente — o relógio real do SLA de resposta. Sem
// isto, o tempo de resposta contratado nunca era medido (só aparecia como rótulo).
// Preenchido automaticamente na criação da intervenção (melhor esforço) e editável no
// editor de relatórios para pedidos telefónicos registados mais tarde.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intervencoes', function (Blueprint $table) {
            $table->timestamp('pedido_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('intervencoes', function (Blueprint $table) {
            $table->dropColumn('pedido_em');
        });
    }
};
