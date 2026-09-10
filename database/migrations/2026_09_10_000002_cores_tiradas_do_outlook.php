<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Cores dos técnicos tiradas DA LISTA DE CATEGORIAS DO OUTLOOK da equipa (print de 10-09-2026):
// a agenda da app passa a desenhar cada pessoa exatamente com a cor que o Outlook lhe dá.
//
// Nota importante: as cores das categorias vivem na caixa de correio de CADA pessoa, não no
// calendário. Por isso a referência é o que a equipa vê no Outlook dela — foi de lá que estas
// cores saíram, e não o contrário.
//
// O Rui Moreira e o Davide Fonseca ficam como estão (a preto, decidido a 10-09).
return new class extends Migration
{
    // email => cor da categoria no Outlook
    private const CORES = [
        'tpinto@nxs.pt' => '#1f6e7b',   // turquesa escuro
        'rpereira@nxs.pt' => '#b3a3e0', // roxo claro
        'ife@nxs.pt' => '#808c94',      // aço (cinzento)
        'jsantos@nxs.pt' => '#e2318c',  // framboesa (rosa forte)
        'dribeiro@nxs.pt' => '#c1c5c0', // cinzento claro
    ];

    public function up(): void
    {
        foreach (self::CORES as $email => $cor) {
            DB::table('utilizadores')->where('email', $email)->update(['cor_agenda' => $cor]);
        }
    }

    public function down(): void
    {
        // Volta a null: a cor é reatribuída da paleta na primeira utilização.
        DB::table('utilizadores')->whereIn('email', array_keys(self::CORES))->update(['cor_agenda' => null]);
    }
};
