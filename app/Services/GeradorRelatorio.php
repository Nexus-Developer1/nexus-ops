<?php

namespace App\Services;

use App\Enums\EstadoRelatorio;
use App\Models\Intervencao;
use App\Models\Relatorio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

// Cria o relatório de uma intervenção e gera o respetivo PDF no object storage.
class GeradorRelatorio
{
    // Cria (ou reutiliza) o relatório da intervenção com numeração sequencial.
    public function criarParaIntervencao(Intervencao $intervencao): Relatorio
    {
        return $intervencao->relatorio()->firstOrCreate(
            [],
            [
                'numero' => $this->proximoNumero(),
                'data' => now(),
                'estado' => EstadoRelatorio::Finalizado,
            ],
        );
    }

    // Numeração sequencial por ano (ex.: 2026/0042).
    public function proximoNumero(): string
    {
        // A numeração é GLOBAL — ignora os global scopes (ex.: técnico/cliente),
        // senão a contagem seria parcial e geraria números duplicados.
        $ano = now()->year;
        $contagem = Relatorio::withoutGlobalScopes()->where('numero', 'like', $ano . '/%')->count();

        return sprintf('%d/%04d', $ano, $contagem + 1);
    }

    // Gera o PDF e guarda-o no object storage, atualizando pdf_path.
    public function gerarPdf(Relatorio $relatorio): void
    {
        // Documento de sistema — ignora global scopes (técnico/cliente) ao carregar,
        // senão a intervenção/equipamento podiam ser filtrados conforme quem gera.
        $relatorio = Relatorio::withoutGlobalScopes()
            ->with([
                'intervencao' => fn ($q) => $q->withoutGlobalScopes()->with([
                    'equipamento' => fn ($q) => $q->withoutGlobalScopes()->with('local.cliente'),
                    'equipamentosCobertos' => fn ($q) => $q->withoutGlobalScopes(),
                    'tecnico',
                    'checklistItens',
                    'checklistEtapas.itens',
                    'anexos',
                ]),
            ])
            ->findOrFail($relatorio->getKey());

        // Fotos embebidas no PDF como data URI (lidas do object storage).
        $fotos = $relatorio->intervencao->anexos
            ->filter(fn ($a) => str_starts_with((string) $a->mime, 'image/'))
            ->map(fn ($a) => 'data:' . $a->mime . ';base64,' . base64_encode($a->conteudo()))
            ->values()
            ->all();

        $pdf = Pdf::loadView('pdf.relatorio', [
            'relatorio' => $relatorio,
            'fotos' => $fotos,
        ])->setPaper('a4');

        $caminho = 'relatorios/' . str_replace('/', '-', $relatorio->numero) . '.pdf';
        Storage::disk()->put($caminho, $pdf->output());

        $relatorio->update(['pdf_path' => $caminho]);
    }
}
