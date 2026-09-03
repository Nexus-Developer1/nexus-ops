<?php

use App\Enums\PapelUtilizador;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cor de cada pessoa na agenda, GUARDADA na conta.
 *
 * Até aqui a cor era a posição numa lista calculada a cada pedido (contas com papel=técnico,
 * por id). Duas consequências, ambas reportadas pela equipa: mudava sozinha — bastava alguém
 * entrar, sair ou passar a administrador para todos os seguintes trocarem de cor — e repetia-se,
 * porque quem não estivesse nessa lista (um administrador que também vai a serviços) caía num
 * fallback por hash que ia bater na cor de outra pessoa.
 *
 * Guardada na conta, a cor é atribuída UMA vez e não volta a mudar. O backfill mantém as cores
 * que os técnicos já tinham hoje (mesma ordem, mesma paleta), para ninguém estranhar a agenda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->string('cor_agenda', 7)->nullable()->after('ativo');
        });

        $usadas = [];

        // 1) Técnicos por id → exatamente as cores que já tinham (as 6 primeiras da paleta).
        foreach (DB::table('utilizadores')->where('papel', PapelUtilizador::Tecnico->value)->orderBy('id')->pluck('id') as $i => $id) {
            $cor = FonteCalendario::PALETA[$i % count(FonteCalendario::PALETA)];
            $usadas[] = $cor;
            DB::table('utilizadores')->where('id', $id)->update(['cor_agenda' => $cor]);
        }

        // 2) Restante equipa (administradores que também vão a serviços) → cores ainda livres.
        $livres = array_values(array_diff(FonteCalendario::PALETA, $usadas));
        foreach (DB::table('utilizadores')->where('papel', PapelUtilizador::Admin->value)->orderBy('id')->pluck('id') as $i => $id) {
            if (! isset($livres[$i])) {
                break; // mais gente do que cores: as que faltam são atribuídas ao primeiro uso
            }
            DB::table('utilizadores')->where('id', $id)->update(['cor_agenda' => $livres[$i]]);
        }
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropColumn('cor_agenda');
        });
    }
};
