<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Consulta de clientes (só leitura). Os clientes vêm do ERP por sync e são
// read-only na aplicação (CLAUDE.md §2). Sem criar/editar/apagar.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Clientes'])]
class Index extends Component
{
    use WithPagination;

    // Quantos registos mostrar por bloco no modal (os mais recentes).
    private const LIMITE_MODAL = 10;

    // Expressão pura (sem extensão) para ordenar por nome ignorando acentos e maiúsculas.
    private const NOME_SEM_ACENTOS = "translate(lower(btrim(nome)), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    #[Url]
    public string $pesquisa = '';

    // Ordenação ativa (valor de uma whitelist — nunca interpolado em cru).
    #[Url]
    public string $ordenar = 'nome_asc';

    // Cliente selecionado para a ficha (null = nenhum aberto).
    public ?int $clienteId = null;

    public function updatingPesquisa(): void
    {
        $this->resetPage();
    }

    public function updatingOrdenar(): void
    {
        $this->resetPage();
    }

    public function selecionar(int $id): void
    {
        $this->clienteId = $id;
    }

    public function fechar(): void
    {
        $this->clienteId = null;
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

        $cliente = $this->clienteId ? Cliente::find($this->clienteId) : null;

        // Dados associados ao cliente selecionado (só leitura, com eager loading).
        $contratos = $relatorios = $equipamentos = collect();
        $contratosTotal = $relatoriosTotal = $equipamentosTotal = 0;

        if ($cliente) {
            $id = $cliente->id;

            // Contratos — ligação direta por cliente_id.
            $contratosBase = Contrato::where('cliente_id', $id);
            $contratosTotal = (clone $contratosBase)->count();
            $contratos = $contratosBase
                ->with('modeloFaturacao')
                ->orderByDesc('data_inicio')
                ->limit(self::LIMITE_MODAL)
                ->get();

            // Equipamentos — cadeia cliente -> locais -> equipamentos.
            $equipamentosBase = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $id));
            $equipamentosTotal = (clone $equipamentosBase)->count();
            $equipamentos = $equipamentosBase
                ->with('local')
                ->orderBy('id')
                ->limit(self::LIMITE_MODAL)
                ->get();

            // Relatórios — cadeia relatorio -> intervencao -> equipamento -> local -> cliente.
            $relatoriosBase = Relatorio::whereHas(
                'intervencao.equipamento.local',
                fn ($q) => $q->where('cliente_id', $id),
            );
            $relatoriosTotal = (clone $relatoriosBase)->count();
            $relatorios = $relatoriosBase
                ->with('intervencao.equipamento')
                ->orderByDesc('data')
                ->limit(self::LIMITE_MODAL)
                ->get();
        }

        return view('livewire.clientes.index', [
            'clientes' => $clientes,
            'ordenacoes' => $this->ordenacoes(),
            'cliente' => $cliente,
            'contratos' => $contratos,
            'contratosTotal' => $contratosTotal,
            'equipamentos' => $equipamentos,
            'equipamentosTotal' => $equipamentosTotal,
            'relatorios' => $relatorios,
            'relatoriosTotal' => $relatoriosTotal,
            'limiteModal' => self::LIMITE_MODAL,
        ]);
    }
}
