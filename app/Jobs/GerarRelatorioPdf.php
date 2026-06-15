<?php

namespace App\Jobs;

use App\Models\Relatorio;
use App\Services\GeradorRelatorio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

// Geração de PDF em job assíncrono (ver secção 12 do CLAUDE.md).
class GerarRelatorioPdf implements ShouldQueue
{
    use Queueable;

    public function __construct(public Relatorio $relatorio) {}

    public function handle(GeradorRelatorio $gerador): void
    {
        $gerador->gerarPdf($this->relatorio);
    }
}
