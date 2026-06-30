<?php

namespace App\Services;

use App\Enums\EstadoRelatorio;
use App\Models\Intervencao;
use App\Models\Relatorio;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Cria o relatório de uma intervenção e gera o respetivo PDF no object storage.
class GeradorRelatorio
{
    // Cria (ou reutiliza) o relatório da intervenção com numeração sequencial.
    public function criarParaIntervencao(Intervencao $intervencao): Relatorio
    {
        $relatorio = $intervencao->relatorio()->firstOrNew([]);

        if ($relatorio->exists) {
            return $relatorio; // já tem relatório (e número) — não regenera
        }

        $relatorio->data = now();
        $relatorio->estado = EstadoRelatorio::Finalizado;
        $this->atribuirNumeroEGravar($relatorio);

        return $relatorio;
    }

    // Numeração sequencial por ano (ex.: 2026/0042). Usa o MAIOR número já usado no ano
    // — INCLUINDO soft-deleted — e soma 1. Nunca reutiliza um número, mesmo após eliminações
    // (um número "queimado" não volta; lacunas são aceitáveis). Global — ignora os global
    // scopes (técnico/cliente), senão o cálculo seria parcial.
    public function proximoNumero(): string
    {
        $ano = now()->year;

        $maxSufixo = (int) Relatorio::withoutGlobalScopes()
            ->withTrashed()
            ->where('numero', 'like', $ano . '/%')
            ->max(DB::raw("cast(split_part(numero, '/', 2) as integer)"));

        return sprintf('%d/%04d', $ano, $maxSufixo + 1);
    }

    // Atribui o próximo número e grava, com retry em caso de corrida (dois relatórios
    // finalizados ao mesmo tempo a apanhar o mesmo número → unique violation). Cada
    // tentativa corre num savepoint próprio (DB::transaction encadeada) para não poluir
    // uma transação exterior — ex.: o editor de relatórios grava dentro de uma transação.
    public function atribuirNumeroEGravar(Relatorio $relatorio): void
    {
        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_NUMERO; $tentativa++) {
            $relatorio->numero = $this->proximoNumero();

            try {
                DB::transaction(fn () => $relatorio->save());

                return;
            } catch (QueryException $e) {
                if (! $this->ehViolacaoUnicidade($e) || $tentativa === self::MAX_TENTATIVAS_NUMERO) {
                    throw $e;
                }
                // Colisão de número (corrida) → recalcula o MAX e tenta de novo.
            }
        }
    }

    private const MAX_TENTATIVAS_NUMERO = 5;

    private function ehViolacaoUnicidade(QueryException $e): bool
    {
        return $e->getCode() === '23505' || ($e->errorInfo[0] ?? null) === '23505'; // Postgres unique_violation
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
                    'contrato' => fn ($q) => $q->withoutGlobalScopes(),
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
