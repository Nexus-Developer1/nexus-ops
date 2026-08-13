<?php

namespace App\Livewire\Auditoria;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Auditoria;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Ecrã de auditoria — SÓ ADMIN (os técnicos não veem o rasto uns dos outros; o trait
// ApenasEquipa barra os clientes e o abort no render barra os técnicos em TODOS os
// pedidos, incluindo ações Livewire). Leitura pura: a tabela é append-only.
#[Layout('components.layouts.app', ['ativo' => 'auditoria', 'titulo' => 'Auditoria'])]
class Listagem extends Component
{
    use ApenasEquipa;

    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $acao = '';

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->ehAdmin(), 403);
    }

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingAcao(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Repetido em cada render (não só no mount): cobre também as ações Livewire.
        abort_unless((bool) auth()->user()?->ehAdmin(), 403);

        $registos = Auditoria::query()
            ->with('utilizador')
            ->when($this->acao, fn ($q) => $q->where('acao', $this->acao))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                // Vaga 1: "quem mexeu no contrato #42?" — um nº (com ou sem '#') pesquisa
                // também o id da entidade, não só texto.
                $numero = ltrim(trim($this->pesquisa), '#');
                $q->where(fn ($q) => $q->where('email', 'ilike', $termo)
                    ->orWhere('acao', 'ilike', $termo)
                    ->orWhere('entidade_tipo', 'ilike', $termo)
                    ->when(ctype_digit($numero), fn ($q) => $q->orWhere('entidade_id', (int) $numero)));
            })
            ->orderByDesc('id')
            ->paginate(25);

        // Ações distintas existentes (para o filtro).
        $acoes = Auditoria::query()->distinct()->orderBy('acao')->pluck('acao');

        return view('livewire.auditoria.listagem', [
            'registos' => $registos,
            'acoes' => $acoes,
        ]);
    }
}
