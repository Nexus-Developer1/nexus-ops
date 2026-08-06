<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Equipamentos SEM cliente passam a poder existir (local_id a null): no PHC há faturas
// lançadas sem o nº de cliente associado (erro humano) e esses equipamentos nunca entravam
// na app — a partir daqui o sync cria-os "por associar" e a pesquisa por série encontra-os.
// Sem local não há cliente → nunca aparecem no portal (o isolamento resolve via local).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table equipamentos alter column local_id drop not null');
    }

    public function down(): void
    {
        // Reverter exige que não existam equipamentos sem local (associá-los antes).
        DB::statement('alter table equipamentos alter column local_id set not null');
    }
};
