<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tipos de evento com maiúscula inicial ("serviço" → "Serviço"): a partir de agora a app
// capitaliza ao gravar (AssuntoEvento::comMaiuscula); aqui arrumam-se os que já existem —
// o lookup e o título dos eventos já criados. Só toca em nomes começados por minúscula;
// siglas e nomes já capitalizados ficam intactos.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['assuntos_evento' => 'nome', 'eventos_agenda' => 'titulo'] as $tabela => $coluna) {
            DB::table($tabela)
                ->whereRaw("{$coluna} ~ '^[[:lower:]]'")
                ->update([$coluna => DB::raw("upper(substring({$coluna} from 1 for 1)) || substring({$coluna} from 2)")]);
        }
    }

    public function down(): void
    {
        // Sem retorno: não se sabe quais estavam em minúscula antes (nem faria sentido repor).
    }
};
