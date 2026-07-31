<?php

namespace App\Livewire\Equipamentos;

use App\Livewire\Concerns\ApenasEquipa;
use App\Enums\TipoEquipamento;
use App\Models\Equipamento;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'ativos', 'titulo' => 'Equipamentos'])]
class Listagem extends Component
{
    use ApenasEquipa;

    use WithPagination;

    #[Url]
    public string $pesquisa = '';

    #[Url]
    public string $tipo = '';

    // Filtro por família do artigo (faminome, vem do PHC) — ex.: ver só UPS, esconder "Peças".
    #[Url]
    public string $familia = '';

    // Filtro por banco de baterias associado: '' | 'com' | 'sem' | 'banco'.
    #[Url]
    public string $banco = '';

    // Ordenação ativa (valor de uma whitelist — nunca interpolado em cru). Por defeito, os
    // MAIS RECENTES primeiro: era a ordem de inserção do ERP (id), que escondia lá no fim
    // os equipamentos acabados de sincronizar/registar.
    #[Url]
    public string $ordenar = 'recentes';

    /** Opções de ordenação (valor => rótulo), no padrão da lista de clientes. */
    private function ordenacoes(): array
    {
        return [
            'recentes' => 'Mais recentes',
            'antigos' => 'Mais antigos',
            'serie_asc' => 'Nº de série (A → Z)',
            'serie_desc' => 'Nº de série (Z → A)',
            'cliente_asc' => 'Cliente (A → Z)',
            'cliente_desc' => 'Cliente (Z → A)',
        ];
    }

    // Subquery com o nome do cliente (equipamento → local → cliente), sem acentos, para
    // ordenar sem carregar as relações linha a linha.
    private function subNomeCliente(): \Illuminate\Database\Query\Builder
    {
        return \Illuminate\Support\Facades\DB::table('locais')
            ->join('clientes', 'clientes.id', '=', 'locais.cliente_id')
            ->whereColumn('locais.id', 'equipamentos.local_id')
            ->selectRaw("translate(lower(btrim(clientes.nome)), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')")
            ->limit(1);
    }

    public function updatingOrdenar(): void
    {
        $this->resetPage();
    }

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingFamilia(): void
    {
        $this->resetPage();
    }

    public function updatingBanco(): void
    {
        $this->resetPage();
    }

    public function filtrarTipo(string $tipo): void
    {
        $this->tipo = $tipo;
        $this->resetPage();
    }

    public function render()
    {
        $equipamentos = Equipamento::query()
            ->with('local.cliente')
            ->withCount('equipamentosAssociados')
            ->when($this->tipo, fn ($q) => $q->where('tipo', $this->tipo))
            ->when($this->familia, fn ($q) => $q->where('faminome', $this->familia))
            // 'com'/'sem' banco associado (exclui os próprios bancos); 'banco' = só bancos associados a um UPS.
            ->when($this->banco === 'com', fn ($q) => $q->whereHas('equipamentosAssociados'))
            ->when($this->banco === 'sem', fn ($q) => $q->whereNull('equipamento_pai_id')->whereDoesntHave('equipamentosAssociados'))
            ->when($this->banco === 'banco', fn ($q) => $q->whereNotNull('equipamento_pai_id'))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero_serie', 'ilike', $termo)
                        ->orWhere('modelo', 'ilike', $termo)
                        ->orWhereHas('local.cliente', fn ($q) => $q->where('nome', 'ilike', $termo))
                        // Encontra o UPS pelo nº de série do banco de baterias associado.
                        ->orWhereHas('equipamentosAssociados', fn ($q) => $q->where('numero_serie', 'ilike', $termo));
                });
            })
            ;

        // Whitelist (default = mais recentes). Desempate por id → paginação estável.
        match ($this->ordenar) {
            'antigos' => $equipamentos->orderBy('id'),
            'serie_asc' => $equipamentos->orderByRaw('numero_serie asc nulls last')->orderBy('id'),
            'serie_desc' => $equipamentos->orderByRaw('numero_serie desc nulls last')->orderBy('id'),
            'cliente_asc' => $equipamentos->orderBy($this->subNomeCliente())->orderByDesc('id'),
            'cliente_desc' => $equipamentos->orderByDesc($this->subNomeCliente())->orderByDesc('id'),
            default => $equipamentos->orderByDesc('id'),
        };

        $equipamentos = $equipamentos->paginate(10);

        // Famílias disponíveis (nomes distintos já presentes) para o dropdown do filtro.
        $familias = Equipamento::query()
            ->whereNotNull('faminome')
            ->distinct()
            ->orderBy('faminome')
            ->pluck('faminome');

        return view('livewire.equipamentos.listagem', [
            'equipamentos' => $equipamentos,
            'tipos' => TipoEquipamento::cases(),
            'familias' => $familias,
            'ordenacoes' => $this->ordenacoes(),
        ]);
    }
}
