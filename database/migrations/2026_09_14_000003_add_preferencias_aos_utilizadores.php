<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Preferências de interface por utilizador (JSON livre, chave => valor). Primeira: a ordem dos
// campos do editor de relatórios (pedido da equipa, set. 2026 — cada um organiza os campos
// mediante a importância). Na BD e não na sessão, para seguir o utilizador entre telemóvel e PC.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $t) {
            $t->json('preferencias')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $t) {
            $t->dropColumn('preferencias');
        });
    }
};
