<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Os relatórios em RASCUNHO ligados a um serviço da agenda passam a ter o horário da agenda
// (pedido da equipa, set. 2026). Até aqui o rascunho criado pela agenda não levava a data do fim
// (num serviço de dois dias ficava «25/09, 14:00–02:00»), e arrastar o serviço não mudava o
// relatório. O código novo trata dos próximos; isto acerta os que já existem.
//
// Só rascunhos: um relatório finalizado/enviado regista o que aconteceu de facto e não se toca.
// Escreve direto na tabela (sem eventos dos modelos): é um acerto de dados, não uma gravação.
return new class extends Migration
{
    public function up(): void
    {
        $linhas = DB::table('eventos_agenda as e')
            ->join('intervencoes as i', 'i.id', '=', 'e.intervencao_id')
            ->join('relatorios as r', 'r.intervencao_id', '=', 'i.id')
            ->whereNull('e.deleted_at')
            ->whereNull('i.deleted_at')
            ->whereNull('r.deleted_at')
            ->where('r.estado', 'rascunho')
            ->get(['i.id', 'e.inicio', 'e.fim']);

        foreach ($linhas as $l) {
            $inicio = Carbon::parse($l->inicio);
            $fim = Carbon::parse($l->fim);
            DB::table('intervencoes')->where('id', $l->id)->update([
                'data_inicio' => $inicio->toDateString(),
                'data_fim' => $fim->toDateTimeString(),
                'hora_inicio' => $inicio->format('H:i'),
                'hora_fim' => $fim->format('H:i'),
            ]);
        }
    }

    public function down(): void
    {
        // Acerto de dados: não há estado anterior a repor.
    }
};
