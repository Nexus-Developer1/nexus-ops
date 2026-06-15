<?php

namespace App\Console\Commands;

use App\Models\Intervencao;
use App\Services\GeradorRelatorio;
use Illuminate\Console\Command;

// Gera (ou regenera) o relatório/PDF de uma intervenção. Útil em dev/manutenção.
class GerarRelatorioComando extends Command
{
    protected $signature = 'relatorio:gerar {intervencao}';

    protected $description = 'Gera o relatório e o PDF de uma intervenção.';

    public function handle(GeradorRelatorio $gerador): int
    {
        $intervencao = Intervencao::findOrFail($this->argument('intervencao'));

        $relatorio = $gerador->criarParaIntervencao($intervencao);
        $gerador->gerarPdf($relatorio);

        $this->info("Relatório {$relatorio->numero} gerado em: {$relatorio->pdf_path}");

        return self::SUCCESS;
    }
}
