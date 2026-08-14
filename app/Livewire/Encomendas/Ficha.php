<?php

namespace App\Livewire\Encomendas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Dossier;
use App\Services\Erp\ErpSyncDriver;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
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

    public function mount(Dossier $dossier): void
    {
        $this->dossier = $dossier;
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
        ]);
    }
}
