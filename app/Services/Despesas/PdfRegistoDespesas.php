<?php

namespace App\Services\Despesas;

use App\Models\RegistoDespesa;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;

/**
 * PDF de um registo de despesas, em duas versões (set. 2026):
 *
 *  - COMPLETO: a folha da empresa e, a seguir, os recibos digitalizados.
 *  - RECIBOS: só as digitalizações, uma por página e a encher a página.
 *
 * Porquê separado: a folha vai para a contabilidade com o processo todo, mas quem trata da
 * faturação quer muitas vezes apenas o talão — era o que se pedia à mão, recortando a
 * segunda página.
 *
 * A folha é DEITADA (sete colunas de valores não cabem de pé) e os recibos vão DE PÉ, que é
 * o feitio de um talão. Como o dompdf só sabe uma orientação por documento, é essa a razão
 * de o PDF só dos recibos ser gerado à parte, e não recortado do outro.
 */
class PdfRegistoDespesas
{
    public const COMPLETO = 'completo';

    public const RECIBOS = 'recibos';

    /**
     * Margem que o dompdf deixa à volta da página quando não se manda nada em contrário
     * (meia polegada). É daqui que sai o espaço útil onde a digitalização tem de caber.
     */
    private const MARGEM_MM = 12.7;

    /**
     * No PDF só das digitalizações a margem é mais apertada: a página existe para mostrar o
     * talão, não para ter espaço em branco à volta.
     */
    private const MARGEM_RECIBOS_MM = 8.0;

    private const A4_MM = [210.0, 297.0];

    /** Altura reservada à legenda do recibo (data, descrição, valor), em píxeis. */
    private const LEGENDA_PX = 34;

    public function gerar(RegistoDespesa $registo, string $parte = self::COMPLETO): string
    {
        $dompdf = new Dompdf(['enable_remote' => false]);
        $dompdf->loadHtml($this->html($registo, $parte));
        $dompdf->setPaper('a4', $this->orientacao($parte));
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** O HTML antes de virar PDF — é por aqui que os ensaios conferem as medidas. */
    public function html(RegistoDespesa $registo, string $parte = self::COMPLETO): string
    {
        $soRecibos = $parte === self::RECIBOS;
        $margem = $soRecibos ? self::MARGEM_RECIBOS_MM : self::MARGEM_MM;

        return view('pdf.registo-despesas', [
            'registo' => $registo,
            'apenasRecibos' => $soRecibos,
            'margemMm' => $margem,
            'caixa' => $this->caixaUtil($this->orientacao($parte), $margem),
        ])->render();
    }

    /** A folha é deitada (sete colunas de valores); o talão é de pé. */
    public function orientacao(string $parte): string
    {
        return $parte === self::RECIBOS ? 'portrait' : 'landscape';
    }

    public function nomeFicheiro(RegistoDespesa $registo, string $parte = self::COMPLETO): string
    {
        return $parte === self::RECIBOS
            ? 'recibos-registo-'.$registo->id.'.pdf'
            : 'registo-despesas-'.$registo->id.'.pdf';
    }

    /** As linhas que trazem recibos digitalizados (as outras não vão para o PDF). */
    public function linhasComRecibos(RegistoDespesa $registo): Collection
    {
        return $registo->linhasOrdenadas()->filter(fn ($linha) => $linha->anexos->isNotEmpty());
    }

    public function temRecibos(RegistoDespesa $registo): bool
    {
        return $this->linhasComRecibos($registo)->isNotEmpty();
    }

    /**
     * Espaço útil da página, em píxeis de CSS (96 por polegada, que é a conta do dompdf).
     *
     * @return array{largura: float, altura: float, imagem: float}
     */
    private function caixaUtil(string $orientacao, float $margemMm): array
    {
        [$curto, $longo] = self::A4_MM;
        [$largura, $altura] = $orientacao === 'landscape' ? [$longo, $curto] : [$curto, $longo];

        $porMm = 96 / 25.4;
        $larguraPx = ($largura - 2 * $margemMm) * $porMm;
        $alturaPx = ($altura - 2 * $margemMm) * $porMm;

        return [
            'largura' => round($larguraPx, 1),
            'altura' => round($alturaPx, 1),
            // O que sobra para a digitalização depois da legenda.
            'imagem' => round($alturaPx - self::LEGENDA_PX, 1),
        ];
    }
}
