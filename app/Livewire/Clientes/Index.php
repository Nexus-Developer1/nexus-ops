<?php

namespace App\Livewire\Clientes;

use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Lista de clientes (só leitura). Pesquisa + ordenação server-side; cada linha
// abre a página de detalhe (/clientes/{cliente}).
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Clientes'])]
class Index extends Component
{
    use ApenasEquipa;

    use WithPagination;

    // Expressão pura (sem extensão) para ordenar por nome ignorando acentos, maiúsculas e espaços.
    private const NOME_SEM_ACENTOS = "translate(lower(btrim(nome)), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    #[Url]
    public string $pesquisa = '';

    // Ordenação ativa (valor de uma whitelist — nunca interpolado em cru). Por defeito, os
    // MAIS RECENTES primeiro (igual à lista de equipamentos): quem chega novo do PHC fica à
    // vista, em vez de enterrado no meio de 3 mil nomes.
    #[Url]
    public string $ordenar = 'recentes';

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingOrdenar(): void
    {
        $this->resetPage();
    }

    /** Opções de ordenação (valor => rótulo). data_criacao_erp = data real do PHC. */
    private function ordenacoes(): array
    {
        return [
            'nome_asc' => 'Nome (A → Z)',
            'nome_desc' => 'Nome (Z → A)',
            'recentes' => 'Mais recentes',
            'antigos' => 'Mais antigos',
            'erp_asc' => 'Nº cliente (crescente)',
            'erp_desc' => 'Nº cliente (decrescente)',
        ];
    }

    /** Cláusula ORDER BY correspondente (whitelist — segura contra injeção). */
    private function clausulaOrdenacao(): string
    {
        return match ($this->ordenar) {
            'nome_desc' => self::NOME_SEM_ACENTOS . ' desc',
            'recentes' => 'data_criacao_erp desc nulls last',
            'antigos' => 'data_criacao_erp asc nulls last',
            'erp_asc' => 'id_erp::bigint asc nulls last',
            'erp_desc' => 'id_erp::bigint desc nulls last',
            default => self::NOME_SEM_ACENTOS . ' asc', // nome_asc
        };
    }

    public function render()
    {
        $clientes = Cliente::query()
            ->when($this->pesquisa, function ($q) {
                $termo = '%' . $this->pesquisa . '%';
                // Pesquisa parcial e case-insensitive por nome, NIF e email.
                $q->where(function ($q) use ($termo) {
                    $q->where('nome', 'ilike', $termo)
                        ->orWhere('nif', 'ilike', $termo)
                        ->orWhere('email', 'ilike', $termo);
                });
            })
            ->orderByRaw($this->clausulaOrdenacao())
            ->orderBy('id') // desempate estável (paginação consistente)
            ->paginate(15);

        return view('livewire.clientes.index', [
            'clientes' => $clientes,
            'ordenacoes' => $this->ordenacoes(),
        ]);
    }
}
