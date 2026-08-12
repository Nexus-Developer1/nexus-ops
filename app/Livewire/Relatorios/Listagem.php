<?php

namespace App\Livewire\Relatorios;

use App\Livewire\Concerns\ApenasEquipa;
use App\Enums\EstadoRelatorio;
use App\Models\Relatorio;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Relatórios'])]
class Listagem extends Component
{
    use ApenasEquipa;

    use WithPagination;

    // Expressão pura para ordenar pelo nome do cliente ignorando acentos/maiúsculas/espaços
    // (mesma da lista de clientes, qualificada porque entra numa subquery com joins).
    private const NOME_SEM_ACENTOS = "translate(lower(btrim(clientes.nome)), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    #[Session]
    public string $pesquisa = '';

    #[Session]
    public string $estado = '';

    // Tipo de relatório: '' (todos) | 'contrato' | 'individual'. Distingue-se pelo
    // intervencao.contrato_id (preenchido = de contrato; null = individual).
    #[Session]
    public string $tipo = '';

    // Ordenação ativa (valor de uma whitelist — nunca interpolado em cru).
    #[Session]
    public string $ordenar = 'recentes';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingOrdenar(): void
    {
        $this->resetPage();
    }

    public function filtrarEstado(string $estado): void
    {
        $this->estado = $estado;
        $this->resetPage();
    }

    public function filtrarTipo(string $tipo): void
    {
        $this->tipo = $tipo;
        $this->resetPage();
    }


    // Soft delete (marca deleted_at) — recuperável; nunca DELETE físico nem apaga o PDF.
    // Se o relatório está ligado a um evento de agenda, apaga-se a unidade toda
    // (relatório + intervenção + evento) — sai da agenda e não deixa intervenção órfã.
    // ENVIADO nunca é apagado (documento já entregue ao cliente, como na edição).
    public function eliminar(int $relatorio): void
    {
        $relatorio = Relatorio::with('intervencao.eventoAgenda')->findOrFail($relatorio);

        // Guarda de servidor (além de esconder o botão na UI): enviado é imutável.
        if ($relatorio->estado === EstadoRelatorio::Enviado) {
            session()->flash('erro', "O relatório {$relatorio->numero} já foi enviado ao cliente e não pode ser eliminado.");

            return;
        }

        $rotulo = $relatorio->numero ?? 'rascunho';
        $intervencao = $relatorio->intervencao;
        $evento = $intervencao?->eventoAgenda;

        DB::transaction(function () use ($relatorio, $intervencao, $evento) {
            $relatorio->delete();

            // Ligado à agenda → apaga também a intervenção e o evento (rascunho e finalizado
            // comportam-se igual: não fica intervenção órfã nem evento fantasma).
            if ($evento) {
                $intervencao?->delete();
                $evento->delete();
            }
        });

        session()->flash('sucesso', "Relatório {$rotulo} eliminado.");
    }

    /** Opções de ordenação (valor => rótulo), no padrão da lista de clientes. */
    private function ordenacoes(): array
    {
        return [
            'recentes' => 'Mais recentes',
            'antigos' => 'Mais antigos',
            'cliente_asc' => 'Cliente (A → Z)',
            'cliente_desc' => 'Cliente (Z → A)',
            'numero_desc' => 'Nº relatório (decrescente)',
            'numero_asc' => 'Nº relatório (crescente)',
        ];
    }

    // Subquery com o nome do cliente do relatório (relatório → intervenção → equipamento →
    // local → cliente), para ordenar sem carregar as relações linha a linha.
    private function subNomeCliente(): \Illuminate\Database\Query\Builder
    {
        return DB::table('intervencoes')
            ->join('equipamentos', 'equipamentos.id', '=', 'intervencoes.equipamento_id')
            ->join('locais', 'locais.id', '=', 'equipamentos.local_id')
            ->join('clientes', 'clientes.id', '=', 'locais.cliente_id')
            ->whereColumn('intervencoes.id', 'relatorios.intervencao_id')
            ->selectRaw(self::NOME_SEM_ACENTOS)
            ->limit(1);
    }

    public function render()
    {
        $query = Relatorio::query()
            ->with('intervencao.equipamento.local.cliente', 'intervencao.tecnico')
            ->when($this->estado, fn ($q) => $q->where('estado', $this->estado))
            ->when($this->tipo === 'contrato', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNotNull('contrato_id')))
            ->when($this->tipo === 'individual', fn ($q) => $q->whereHas('intervencao', fn ($q) => $q->whereNull('contrato_id')))
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                $q->where(function ($q) use ($termo) {
                    $q->where('numero', 'ilike', $termo)
                        ->orWhereHas('intervencao.equipamento.local.cliente', fn ($q) => $q->where('nome', 'ilike', $termo))
                        ->orWhereHas('intervencao.tecnico', fn ($q) => $q->where('nome', 'ilike', $termo));
                });
            });

        // Whitelist (default = recentes). Desempate por id → paginação estável.
        match ($this->ordenar) {
            'antigos' => $query->orderBy('data')->orderBy('id'),
            'cliente_asc' => $query->orderBy($this->subNomeCliente())->orderByDesc('data')->orderBy('id'),
            'cliente_desc' => $query->orderByDesc($this->subNomeCliente())->orderByDesc('data')->orderBy('id'),
            // Rascunhos ainda sem número ficam no fim ('2026/0042' ordena bem como texto).
            'numero_asc' => $query->orderByRaw('numero asc nulls last')->orderBy('id'),
            'numero_desc' => $query->orderByRaw('numero desc nulls last')->orderBy('id'),
            default => $query->orderByDesc('data')->orderByDesc('id'),
        };

        return view('livewire.relatorios.listagem', [
            'relatorios' => $query->paginate(10),
            'estados' => EstadoRelatorio::cases(),
            'ordenacoes' => $this->ordenacoes(),
        ]);
    }
}
