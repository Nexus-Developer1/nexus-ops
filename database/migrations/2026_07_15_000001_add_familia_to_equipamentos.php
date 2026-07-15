<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Família do artigo (do PHC: st.familia = código, st.faminome = nome). Correlacionada por
// ma.ref = st.ref no sync. Serve para filtrar os equipamentos por família (ex.: separar UPS
// de "peças/reparação"). Campo do ERP — read-only na app, atualizado pelo sync.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->string('familia')->nullable()->after('modelo');
            $table->string('faminome')->nullable()->after('familia');
            $table->index('faminome');
        });
    }

    public function down(): void
    {
        Schema::table('equipamentos', function (Blueprint $table) {
            $table->dropIndex(['faminome']);
            $table->dropColumn(['familia', 'faminome']);
        });
    }
};
