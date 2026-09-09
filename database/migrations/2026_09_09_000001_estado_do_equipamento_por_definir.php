<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// O estado do equipamento nunca veio do PHC: era o sync (e o default da coluna) que punha
// «operacional» em tudo o que nascia. Isto dava por confirmada uma informação que ninguém
// tinha lido no local — 17 898 equipamentos "operacionais" sem que alguém os tivesse visto.
//
// Passa a existir o estado POR DEFINIR, que é agora o valor de origem. Quem for ao local
// marca o estado na ficha do equipamento. Os equipamentos que alguém já tenha marcado como
// degradado/crítico/inativo (escolha deliberada) NÃO são tocados.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipamentos', function (Blueprint $t) {
            $t->string('estado')->default('por_definir')->change(); // por_definir | operacional | degradado | critico | inativo
        });

        DB::table('equipamentos')->where('estado', 'operacional')->update(['estado' => 'por_definir']);
    }

    public function down(): void
    {
        DB::table('equipamentos')->where('estado', 'por_definir')->update(['estado' => 'operacional']);

        Schema::table('equipamentos', function (Blueprint $t) {
            $t->string('estado')->default('operacional')->change();
        });
    }
};
