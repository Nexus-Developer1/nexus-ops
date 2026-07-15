<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cliente final (utilizador real do equipamento, mesmo que não esteja no ERP) e localização
// física da instalação. Texto livre — independentes do cliente/local do sistema. Mostrados na
// ficha e no relatório (com fallback para a lógica derivada quando vazios).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->string('cliente_final')->nullable()->after('numero_serie');
            $table->string('localizacao_instalacao')->nullable()->after('cliente_final');
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->dropColumn(['cliente_final', 'localizacao_instalacao']);
        });
    }
};
