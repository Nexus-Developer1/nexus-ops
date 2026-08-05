<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// As linhas do registo de despesas passam a 1:1 com `despesas` (dia, descrição, "o que é",
// tipo, valor) e os RECIBOS anexam-se à LINHA (despesa), não ao registo. `detalhe` é o
// campo novo "o que realmente é" (ex.: "Portagem A1", "Almoço com cliente").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->string('detalhe')->nullable();
        });

        // Recibos anexados ao REGISTO passam para a 1.ª linha (despesa) desse registo —
        // continuam visíveis; dali em diante anexam-se linha a linha.
        foreach (DB::table('anexos')->where('anexavel_type', 'App\\Models\\RegistoDespesa')->get() as $a) {
            $primeira = DB::table('despesas')
                ->where('registo_despesa_id', $a->anexavel_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');
            if ($primeira) {
                DB::table('anexos')->where('id', $a->id)
                    ->update(['anexavel_type' => 'App\\Models\\Despesa', 'anexavel_id' => $primeira]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $table) {
            $table->dropColumn('detalhe');
        });
        // A reanexação dos recibos não se reverte (ficam na despesa — continuam acessíveis).
    }
};
