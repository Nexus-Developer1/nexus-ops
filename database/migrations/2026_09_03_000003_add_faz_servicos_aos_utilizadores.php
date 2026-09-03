<?php

use App\Enums\PapelUtilizador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administradores que TAMBÉM vão a serviços.
 *
 * O papel decidia duas coisas ao mesmo tempo: o que a pessoa pode fazer na aplicação e se
 * aparece como técnico para ser escolhida em eventos e relatórios. Quem administra e também
 * trabalha em campo (pedido da equipa, set. 2026) ficava de fora das listas de técnicos —
 * não se podia sequer marcar num evento.
 *
 * Esta coluna separa as duas coisas: o papel continua a mandar nas permissões, e `faz_servicos`
 * diz se a pessoa entra nas listas de técnicos. Só faz sentido em administradores (um técnico
 * já lá está por definição).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->boolean('faz_servicos')->default(false)->after('papel');
        });

        // Backfill: administradores que JÁ aparecem em eventos ou intervenções estavam a
        // trabalhar em campo apesar do papel — é o caso que motivou a coluna.
        DB::table('utilizadores')
            ->where('papel', PapelUtilizador::Admin->value)
            ->where(function ($q) {
                $q->whereIn('id', fn ($s) => $s->from('eventos_agenda')->select('tecnico_id')->whereNotNull('tecnico_id'))
                    ->orWhereIn('id', fn ($s) => $s->from('evento_tecnicos')->select('user_id'))
                    ->orWhereIn('id', fn ($s) => $s->from('intervencoes')->select('tecnico_id')->whereNotNull('tecnico_id'))
                    ->orWhereIn('nome', fn ($s) => $s->from('eventos_agenda')->select('tecnico_nome')->whereNotNull('tecnico_nome'));
            })
            ->update(['faz_servicos' => true]);
    }

    public function down(): void
    {
        Schema::table('utilizadores', function (Blueprint $table) {
            $table->dropColumn('faz_servicos');
        });
    }
};
