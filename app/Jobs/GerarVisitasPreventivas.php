<?php

namespace App\Jobs;

use App\Models\Contrato;
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

    public function handle(GeradorVisitasPreventivas $gerador): void
    {
        $criadas = $gerador->gerar($this->contrato);

        Log::info('Visitas preventivas geradas para contrato.', [
            'contrato' => $this->contrato->numero,
            'visitas' => $criadas,
        ]);
    }
}
