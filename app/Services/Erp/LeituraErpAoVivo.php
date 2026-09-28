<?php

namespace App\Services\Erp;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leituras AO VIVO do PHC para os ecrãs (listagem e ficha das encomendas, encomendas ligadas no
 * editor de relatórios) — o único sítio por onde passam.
 *
 * O ERP não pode estar no caminho crítico de um pedido (CLAUDE.md §5.3): o PHC lento prendia os
 * processos que atendem os pedidos e podia parar a aplicação inteira (26.ª revisão de segurança,
 * set. 2026). Por isso, além da ligação com timeout curto (erp_interativo):
 *  - o que se leu fica guardado uns segundos — escrever na pesquisa ou mudar de página não volta
 *    a perguntar pelos mesmos dossiês;
 *  - se o PHC falhar, não se insiste durante PAUSA segundos: os ecrãs mostram o que está guardado
 *    da última sincronização, e fica UMA linha no log (não uma por tecla).
 *
 * Os métodos nunca lançam exceções: devolvem o que conseguiram (ou null/[]).
 */
class LeituraErpAoVivo
{
    public const EM_BAIXO = 'erp-ao-vivo:em-baixo';

    public const PAUSA = 60;          // segundos sem voltar a tentar depois de uma falha

    public const TTL_TOTAIS = 90;     // segundos que um total lido fica guardado

    public const TTL_LINHAS = 90;     // idem para as linhas de um dossiê (quem quiser mais, passa)

    public function __construct(private ErpSyncDriver $erp) {}

    public function emBaixo(): bool
    {
        return Cache::has(self::EM_BAIXO);
    }

    /**
     * Totais (débito) de vários dossiês: os guardados e, numa só leitura, os que faltam.
     *
     * @param  list<string>  $bostamps
     * @return array<string, float> bostamp => total (os que não se conseguiu saber ficam de fora)
     */
    public function totais(array $bostamps): array
    {
        $bostamps = array_values(array_unique(array_filter(array_map('strval', $bostamps))));
        $totais = [];
        $faltam = [];
        foreach ($bostamps as $b) {
            $guardado = Cache::get('erp-ao-vivo:total:'.$b);
            if ($guardado !== null) {
                $totais[$b] = (float) $guardado;
            } else {
                $faltam[] = $b;
            }
        }

        if ($faltam !== [] && ! $this->emBaixo()) {
            foreach ($this->protegido(fn () => $this->erp->obterTotaisDossiers($faltam), 'totais') ?? [] as $b => $total) {
                Cache::put('erp-ao-vivo:total:'.$b, $total, self::TTL_TOTAIS);
                $totais[$b] = (float) $total;
            }
        }

        return $totais;
    }

    public function total(string $bostamp): ?float
    {
        return $this->totais([$bostamp])[$bostamp] ?? null;
    }

    /**
     * Linhas de um dossiê. Null = o PHC não respondeu (ou está em pausa) — o ecrã avisa.
     *
     * @return list<LinhaDossierErp>|null
     */
    public function linhas(string $bostamp, int $segundos = self::TTL_LINHAS): ?array
    {
        $guardadas = Cache::get('erp-ao-vivo:linhas:'.$bostamp);
        if (is_array($guardadas)) {
            return $guardadas;
        }
        if ($this->emBaixo()) {
            return null;
        }

        $linhas = $this->protegido(fn () => array_values(iterator_to_array($this->erp->obterLinhasDossier($bostamp), false)), 'linhas');
        if ($linhas !== null) {
            Cache::put('erp-ao-vivo:linhas:'.$bostamp, $linhas, $segundos);
        }

        return $linhas;
    }

    private function protegido(Closure $leitura, string $o): mixed
    {
        try {
            return $leitura();
        } catch (Throwable $e) {
            Cache::put(self::EM_BAIXO, true, self::PAUSA);
            Log::warning('PHC indisponível para as leituras ao vivo — pausa de '.self::PAUSA.' s; os ecrãs mostram o guardado.', [
                'leitura' => $o,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
