<?php

namespace App\Livewire\Encomendas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Dossier;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Throwable;

// Ficha de um dossiê (encomenda/proposta): o cabeçalho vem da nossa BD (dossiers), mas as
// LINHAS são lidas AO VIVO do PHC (tabela bi) no momento de abrir — não são sincronizadas.
// O ERP nunca deve rebentar o pedido do utilizador (CLAUDE.md §5): se estiver em baixo ou
// sem driver, a ficha abre na mesma e avisa que não conseguiu obter as linhas.
#[Layout('components.layouts.app', ['ativo' => 'encomendas', 'titulo' => 'Encomenda'])]
class Ficha extends Component
{
    use ApenasEquipa;

    public Dossier $dossier;

    // Colunas das LINHAS (chave => rótulo). A ordem aqui é a de fábrica.
    public const COLUNAS = [
        'ref' => 'Referência',
        'pn' => 'PN',
        'marca' => 'Marca',
        'descricao' => 'Descrição',
        'faltas' => 'Faltas',
        'qtt' => 'Qtd',
        'movimentado' => 'Movim.',
        'series' => 'Série(s)',
        'unitario' => 'Unitário',
        'total' => 'Total',
    ];

    // Colunas alinhadas à direita (números/valores).
    public const NUMERICAS = ['faltas', 'qtt', 'movimentado', 'unitario', 'total'];

    // Ordem escolhida pelo utilizador (arrastar os títulos), guardada na sessão. As chaves
    // são uma whitelist — nada vindo do browser entra em cru.
    #[Session(key: 'encomendas.colunas-linhas')]
    public array $ordemColunas = [];

    public function mount(Dossier $dossier): void
    {
        $this->dossier = $dossier;
        $this->normalizarColunas();
    }

    // Garante que a ordem guardada é sempre uma permutação válida das colunas conhecidas
    // (apanha uma sessão antiga ou uma coluna nova/removida no código).
    private function normalizarColunas(): void
    {
        $validas = array_values(array_unique(array_filter(
            $this->ordemColunas,
            fn ($c) => is_string($c) && isset(self::COLUNAS[$c]),
        )));
        foreach (array_keys(self::COLUNAS) as $chave) {
            if (! in_array($chave, $validas, true)) {
                $validas[] = $chave; // acrescenta as que faltem, no fim
            }
        }
        $this->ordemColunas = $validas;
    }

    // Aplica a nova ordem vinda do arrastar (revalidada no servidor).
    public function reordenarColunas(array $ordem): void
    {
        $this->ordemColunas = $ordem;
        $this->normalizarColunas();
    }

    public function reporColunas(): void
    {
        $this->ordemColunas = array_keys(self::COLUNAS);
    }

    public function render(ErpSyncDriver $erp)
    {
        $linhas = [];
        $erroLinhas = false;

        try {
            $linhas = iterator_to_array($erp->obterLinhasDossier($this->dossier->id_erp));
        } catch (Throwable $e) {
            // PHC em baixo/timeout → a ficha abre na mesma; o detalhe fica no log.
            $erroLinhas = true;
            Log::warning('Falha a obter as linhas do dossiê ao vivo do PHC.', [
                'bostamp' => $this->dossier->id_erp,
                'erro' => $e->getMessage(),
            ]);
        }

        return view('livewire.encomendas.ficha', [
            'linhas' => $linhas,
            'erroLinhas' => $erroLinhas,
            'totalLinhas' => array_sum(array_map(fn ($l) => (float) ($l->total ?? 0), $linhas)),
            'colunas' => self::COLUNAS,
            'numericas' => self::NUMERICAS,
        ]);
    }
}
