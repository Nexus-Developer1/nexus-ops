<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// As cores da agenda passam a ser as CORES DAS CATEGORIAS DO OUTLOOK (set. 2026): a mesma
// pessoa tinha um tom na app e outro no calendário partilhado, porque a categoria do Outlook
// saía de `id % 24` e não da cor da agenda.
//
// Cada cor guardada é trocada pela equivalente da paleta nova, na MESMA posição — ninguém muda
// de tom (o verde continua verde), só passa a ser o verde do Outlook. E as contas que aparecem
// na equipa mas não andam em serviços ficam a PRETO, em vez de gastarem uma cor da paleta.
return new class extends Migration
{
    // antigo => novo (mesma posição na paleta)
    private const MAPA = [
        '#16a34a' => '#107c10', // verde
        '#2563eb' => '#0078d4', // azul
        '#9333ea' => '#5c2d91', // roxo
        '#ea580c' => '#ca5010', // laranja
        '#0891b2' => '#038387', // turquesa
        '#db2777' => '#c30052', // framboesa
        '#ca8a04' => '#986f0b', // mostarda
        '#4f46e5' => '#1c3f95', // azul escuro
        '#0f766e' => '#005e5e', // turquesa escuro
        '#be123c' => '#a4262c', // vermelho escuro
        '#65a30d' => '#6b7d0c', // azeitona
        '#86198f' => '#6b0036', // framboesa escura
    ];

    // Quem aparece na equipa mas não anda em serviços — a preto (pedido da equipa).
    private const SEM_COR = ['ruipdrmoreira@gmail.com', 'dev@nxs.pt'];

    public function up(): void
    {
        foreach (self::MAPA as $antiga => $nova) {
            DB::table('utilizadores')->where('cor_agenda', $antiga)->update(['cor_agenda' => $nova]);
        }

        DB::table('utilizadores')->whereIn('email', self::SEM_COR)->update(['cor_agenda' => '#000000']);
    }

    public function down(): void
    {
        // O preto não tem equivalente antigo: volta a null e a cor é reatribuída da paleta.
        DB::table('utilizadores')->where('cor_agenda', '#000000')->update(['cor_agenda' => null]);

        foreach (self::MAPA as $antiga => $nova) {
            DB::table('utilizadores')->where('cor_agenda', $nova)->update(['cor_agenda' => $antiga]);
        }
    }
};
