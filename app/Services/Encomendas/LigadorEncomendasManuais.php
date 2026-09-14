<?php

namespace App\Services\Encomendas;

use App\Models\Dossier;
use App\Models\EncomendaManual;
use Illuminate\Support\Facades\DB;

// Passa as encomendas escritas à mão (nº + ano) a ligações normais assim que o dossiê de peças
// correspondente existe na aplicação. Corre no fim de cada sync dos dossiês e ao gravar o
// relatório. Idempotente: uma ligação que já exista não é duplicada.
class LigadorEncomendasManuais
{
    /** Devolve quantas encomendas escritas à mão foram ligadas. */
    public function reconciliar(?int $intervencaoId = null): int
    {
        $ligadas = 0;

        EncomendaManual::query()
            ->when($intervencaoId, fn ($q) => $q->where('intervencao_id', $intervencaoId))
            ->orderBy('id')
            ->chunkById(500, function ($manuais) use (&$ligadas) {
                foreach ($manuais as $m) {
                    $dossierId = Dossier::where('ndos', Dossier::TIPO_ENCOMENDA_PECAS)
                        ->where('obrano', $m->obrano)
                        ->where('ano', $m->ano)
                        ->value('id');

                    if (! $dossierId) {
                        continue; // ainda não chegou do PHC
                    }

                    DB::transaction(function () use ($m, $dossierId) {
                        $m->intervencao->encomendas()->syncWithoutDetaching([$dossierId]);
                        $m->delete();
                    });
                    $ligadas++;
                }
            });

        return $ligadas;
    }
}
