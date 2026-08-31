<?php

namespace App\Livewire\Encomendas;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Dossier;
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

    public function updatingPesquisa(): void
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

    public function render()
    {
        $dossiers = Dossier::query()
            ->when($this->tipo !== '', fn ($q) => $q->where('ndos', (int) $this->tipo))
            ->when($this->estado === 'aberta', fn ($q) => $q->where('fechada', false))
            ->when($this->estado === 'fechada', fn ($q) => $q->where('fechada', true))
            ->when($this->ano !== '', fn ($q) => $q->where('ano', (int) $this->ano))
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
            ->paginate(20);

        // Anos disponíveis para o filtro (distintos, do mais recente ao mais antigo).
        $anos = Dossier::query()->whereNotNull('ano')->distinct()->orderByDesc('ano')->pluck('ano');

        return view('livewire.encomendas.listagem', [
            'dossiers' => $dossiers,
            'anos' => $anos,
            'tipos' => Dossier::TIPOS, // [1 => 'Encomenda Peças', 3 => 'Proposta', 7 => 'Encomenda Produção']
        ]);
    }
}
