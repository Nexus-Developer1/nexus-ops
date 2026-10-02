<?php

namespace App\Livewire\Encomendas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Dossier;
use App\Services\Erp\LeituraErpAoVivo;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;

// Listagem dos dossiês do PHC (propostas e encomendas — tabela `dossiers`, só leitura).
// Filtros e pesquisa vivem na sessão (como as outras listagens). São ~200 mil registos:
// paginado sempre, e os filtros usam os índices (ndos, cliente_no).
#[Layout('components.layouts.app', ['ativo' => 'encomendas', 'titulo' => 'Dossiers PHC'])]
class Listagem extends Component
{
    use ApenasEquipa;
    use WithPagination;

    #[Session]
    public string $pesquisa = '';

    #[Session]
    public string $tipo = ''; // '' | '1' | '3' | '7' (ndos)

    #[Session]
    public string $estado = ''; // '' | 'aberta' | 'fechada'

    #[Session]
    public string $ano = '';

    // Conferência com o PHC: '' | 'ausente' (já não existe lá) | 'alterado' (mudou lá
    // nos últimos 7 dias). Alimentado pelo sync — ver SincronizarDossiersErp.
    #[Session]
    public string $phc = '';

    // A página também fica na sessão, como os filtros (pedido da equipa, out. 2026): abrir uma
    // proposta na página 13 e carregar em «Voltar» (que vem sem ?page) levava à página 1. Um
    // ?page explícito no endereço manda sempre.
    public function mount(): void
    {
        if (! request()->has('page') && ($pagina = (int) session('encomendas.pagina', 1)) > 1) {
            $this->setPage($pagina);
        }
    }

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingPhc(): void
    {
        $this->resetPage();
    }

    public function updatingTipo(): void
    {
        $this->resetPage();
    }

    public function updatingEstado(): void
    {
        $this->resetPage();
    }

    public function updatingAno(): void
    {
        $this->resetPage();
    }

    public function render(LeituraErpAoVivo $phc)
    {
        $dossiers = Dossier::query()
            ->when($this->tipo !== '', fn ($q) => $q->where('ndos', (int) $this->tipo))
            ->when($this->estado === 'aberta', fn ($q) => $q->where('fechada', false))
            ->when($this->estado === 'fechada', fn ($q) => $q->where('fechada', true))
            ->when($this->ano !== '', fn ($q) => $q->where('ano', (int) $this->ano))
            ->when($this->phc === 'ausente', fn ($q) => $q->whereNotNull('ausente_do_erp_em'))
            ->when($this->phc === 'alterado', fn ($q) => $q->where('alterado_erp_em', '>=', now()->subDays(7)))
            ->when($this->pesquisa !== '', function ($q) {
                $termo = '%'.$this->pesquisa.'%';
                $q->where(function ($q) use ($termo) {
                    $q->where('nome', 'ilike', $termo)
                        ->orWhere('obrano', 'ilike', $termo)
                        ->orWhere('cliente_no', 'ilike', $termo);
                });
            })
            // Mais recentes primeiro (ano desc, depois nº do dossiê desc); id desestabiliza empates.
            ->orderByDesc('ano')
            ->orderByDesc('obrano')
            ->orderByDesc('id')
            ->paginate(10); // 10 por página (pedido da equipa)

        // Página fora do fim (os filtros mudaram entretanto ou o PHC apagou dossiês) → a última.
        if ($dossiers->isEmpty() && $dossiers->currentPage() > 1) {
            $this->setPage($dossiers->lastPage());

            return $this->render($phc);
        }
        session(['encomendas.pagina' => $dossiers->currentPage()]);

        // Totais AO VIVO das linhas desta página, numa só leitura ao PHC: o guardado é o da
        // última sincronização (8h/13h/19h) e um dossiê alterado depois dela aparecia com o
        // total antigo (set. 2026 — proposta 7431: 1 062,09 € em vez de 2 816 €). Timeout
        // curto, guardados 90 s e pausa se o PHC falhar (LeituraErpAoVivo) — a pesquisa a cada
        // tecla não volta a perguntar, e um PHC lento não prende a aplicação. Sem resposta →
        // ficam os da sincronização.
        $totaisAoVivo = $phc->totais($dossiers->getCollection()->pluck('id_erp')->filter()->all());

        // Anos disponíveis para o filtro (distintos, do mais recente ao mais antigo).
        $anos = Dossier::query()->whereNotNull('ano')->distinct()->orderByDesc('ano')->pluck('ano');

        return view('livewire.encomendas.listagem', [
            'dossiers' => $dossiers,
            'totaisAoVivo' => $totaisAoVivo,
            'anos' => $anos,
            'tipos' => Dossier::TIPOS, // [1 => 'Encomenda Peças', 3 => 'Proposta', 7 => 'Encomenda Produção']
        ]);
    }
}
