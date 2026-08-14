<?php

namespace App\Http\Controllers;

use App\Models\Despesa;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Export CSV consolidado das despesas do período/filtros (fecho mensal para a contabilidade).
// Mesma semântica de filtros da listagem (período mes|tudo|AAAA-MM, categoria, pesquisa por
// descrição/detalhe/colaborador). Streamed — não carrega tudo em memória. Só equipa (a rota
// vive no grupo papel:admin,tecnico).
class ExportarDespesas extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $periodo = (string) $request->query('periodo', 'mes');
        $categoria = (string) $request->query('categoria', '');
        $pesquisa = (string) $request->query('pesquisa', '');

        $query = Despesa::query()
            ->with('registo.colaborador')
            ->when($periodo === 'mes', fn ($q) => $q->whereYear('data', now()->year)->whereMonth('data', now()->month))
            ->when(
                preg_match('/^\d{4}-\d{2}$/', $periodo),
                fn ($q) => $q->whereYear('data', (int) substr($periodo, 0, 4))
                    ->whereMonth('data', (int) substr($periodo, 5, 2)),
            )
            ->when($categoria !== '', fn ($q) => $q->where('categoria', $categoria))
            ->when($pesquisa !== '', function ($q) use ($pesquisa) {
                $termo = '%'.$pesquisa.'%';
                $q->where(fn ($q) => $q->where('descricao', 'ilike', $termo)
                    ->orWhere('detalhe', 'ilike', $termo)
                    ->orWhereHas('registo.colaborador', fn ($q) => $q->where('nome', 'ilike', $termo)));
            })
            ->orderBy('data')
            ->orderBy('id');

        $etiqueta = $periodo === 'mes' ? now()->format('Y-m') : ($periodo === 'tudo' ? 'tudo' : $periodo);
        $nome = "despesas-{$etiqueta}.csv";

        return response()->streamDownload(function () use ($query) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // BOM UTF-8 → o Excel abre com acentos certos
            // Separador ';' (o Excel em PT usa ';' por defeito).
            fputcsv($saida, ['Data', 'Colaborador', 'Departamento', 'Matrícula', 'Tipo', 'Refeição', 'Descrição', 'Detalhe', 'Valor (€)'], ';');

            $query->chunk(500, function ($despesas) use ($saida) {
                foreach ($despesas as $d) {
                    fputcsv($saida, [
                        $d->data?->format('Y-m-d'),
                        $d->registo?->colaborador?->nome ?? '',
                        $d->registo?->departamento ?? '',
                        $d->registo?->matricula ?? '',
                        $d->categoria,
                        $d->refeicao_tipo === 'A' ? 'Almoço' : ($d->refeicao_tipo === 'J' ? 'Jantar' : ''),
                        $d->descricao,
                        $d->detalhe ?? '',
                        number_format((float) $d->valor, 2, ',', ''),
                    ], ';');
                }
            });

            fclose($saida);
        }, $nome, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
