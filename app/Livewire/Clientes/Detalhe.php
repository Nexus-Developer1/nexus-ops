<?php

namespace App\Livewire\Clientes;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\LinhaFatura;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Página de detalhe de um cliente (só leitura): dados gerais + 3 secções
// (contratos, equipamentos, relatórios) com os 10 mais recentes + contagem e
// "Ver todos" para a lista completa paginada.
#[Layout('components.layouts.app', ['ativo' => 'clientes', 'titulo' => 'Cliente'])]
class Detalhe extends Component
{
    private const LIMITE = 10;

    public Cliente $cliente;

    public function mount(Cliente $cliente): void
    {
        $this->cliente = $cliente;
    }

    public function render()
    {
        $id = $this->cliente->id;

        // Contratos — ligação direta por cliente_id.
        $contratos = Contrato::where('cliente_id', $id);
        // Equipamentos — cadeia cliente -> locais -> equipamentos.
        $equipamentos = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $id));
        // Relatórios — cadeia relatorio -> intervencao -> equipamento -> local -> cliente.
        $relatorios = Relatorio::whereHas('intervencao.equipamento.local', fn ($q) => $q->where('cliente_id', $id));
        // Faturação — linhas do PHC ligadas por cliente_no = id_erp do cliente.
        $faturacao = LinhaFatura::where('cliente_no', $this->cliente->id_erp);

        return view('livewire.clientes.detalhe', [
            'contratos' => (clone $contratos)->with('modeloFaturacao')->orderByDesc('data_inicio')->limit(self::LIMITE)->get(),
            'contratosTotal' => (clone $contratos)->count(),
            'equipamentos' => (clone $equipamentos)->with('local')->orderByDesc('id')->limit(self::LIMITE)->get(),
            'equipamentosTotal' => (clone $equipamentos)->count(),
            'relatorios' => (clone $relatorios)->with('intervencao.equipamento')->orderByDesc('data')->limit(self::LIMITE)->get(),
            'relatoriosTotal' => (clone $relatorios)->count(),
            'faturacao' => (clone $faturacao)->orderByDesc('data')->limit(self::LIMITE)->get(),
            'faturacaoTotal' => (clone $faturacao)->count(),
            'limite' => self::LIMITE,
        ]);
    }
}
