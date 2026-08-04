<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Consolidação pós-backfill: o backfill deu um registo A CADA despesa antiga, separando as
// que tinham sido lançadas juntas (mesmo colaborador + mesma data + mesma descrição — o
// modelo anterior criava uma despesa por coluna preenchida). Junta-as num só registo:
// o mais antigo do grupo fica como canónico; os esvaziados são apagados.
return new class extends Migration
{
    public function up(): void
    {
        $grupos = DB::table('despesas')
            ->whereNull('deleted_at')
            ->whereNotNull('registo_despesa_id')
            ->selectRaw("coalesce(criado_por, 0) as dono, data, descricao, min(registo_despesa_id) as canonico, count(distinct registo_despesa_id) as n_registos")
            ->groupBy('dono', 'data', 'descricao')
            ->havingRaw('count(distinct registo_despesa_id) > 1')
            ->get();

        foreach ($grupos as $grupo) {
            $registosDoGrupo = DB::table('despesas')
                ->whereNull('deleted_at')
                ->whereRaw('coalesce(criado_por, 0) = ?', [$grupo->dono])
                ->whereDate('data', $grupo->data)
                ->where('descricao', $grupo->descricao)
                ->pluck('registo_despesa_id')
                ->unique()
                ->reject(fn ($id) => (int) $id === (int) $grupo->canonico);

            // Move as despesas e os recibos para o registo canónico.
            DB::table('despesas')
                ->whereIn('registo_despesa_id', $registosDoGrupo)
                ->whereNull('deleted_at')
                ->whereRaw('coalesce(criado_por, 0) = ?', [$grupo->dono])
                ->whereDate('data', $grupo->data)
                ->where('descricao', $grupo->descricao)
                ->update(['registo_despesa_id' => $grupo->canonico]);

            DB::table('anexos')
                ->where('anexavel_type', 'App\\Models\\RegistoDespesa')
                ->whereIn('anexavel_id', $registosDoGrupo)
                ->update(['anexavel_id' => $grupo->canonico]);

            // Registos que ficaram sem despesas vivas desaparecem.
            foreach ($registosDoGrupo as $id) {
                $temVivas = DB::table('despesas')->where('registo_despesa_id', $id)->whereNull('deleted_at')->exists();
                if (! $temVivas) {
                    DB::table('registos_despesa')->where('id', $id)->delete();
                }
            }
        }
    }

    public function down(): void
    {
        // A consolidação não é reversível (a separação original perdeu-se) — e não precisa:
        // o estado consolidado é o correto.
    }
};
