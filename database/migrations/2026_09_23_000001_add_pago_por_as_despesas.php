<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Quem pagou cada despesa (set. 2026): 'cartao_tecnico' (cartão da empresa atribuído ao
// técnico), 'financeiro' (pago diretamente pelo financeiro) ou 'tecnico' (do bolso do
// técnico — é o que há a reembolsar). Obrigatório nas linhas novas; null no histórico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->string('pago_por', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn('pago_por');
        });
    }
};
