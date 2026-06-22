<?php

namespace App\Jobs;

use App\Models\Contrato;
use App\Services\Agenda\DetetorSobreposicaoEquipamento;
use App\Services\Agenda\GeradorVisitasPreventivas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

// Geração das visitas preventivas de um contrato — job assíncrono (CLAUDE.md §12).
// Despachado ao ativar o contrato (ver Contratos\Ficha::ativar()).
class GerarVisitasPreventivas implements ShouldQueue
{
    use Queueable;

    public function __construct(public Contrato $contrato) {}

    public function handle(GeradorVisitasPreventivas $gerador, DetetorSobreposicaoEquipamento $detetor): void
    {
        // A criação é exatamente como antes — todas as visitas são criadas, nada é movido.
        $criadas = $gerador->gerar($this->contrato);

        // Só visibilidade: deteta sobreposições por equipamento e guarda o resumo no contrato.
        $sobreposicoes = $detetor->paraContrato($this->contrato);

        $this->contrato->update(['resumo_geracao' => [
            'criadas' => $criadas,
            'sobreposicoes' => count($sobreposicoes),
            'detalhe' => $sobreposicoes,
            'gerado_em' => now()->toDateTimeString(),
        ]]);

        Log::info('Visitas preventivas geradas para contrato.', [
            'contrato' => $this->contrato->numero,
            'visitas' => $criadas,
            'sobreposicoes' => count($sobreposicoes),
        ]);
    }
}
