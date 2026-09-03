<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Contrato;
use App\Services\Auditor;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Ficha extends Component
{
    use ApenasEquipa;

    public Contrato $contrato;

    public function mount(Contrato $contrato): void
    {
        $this->contrato = $contrato;
    }

    // Ativa o contrato → passa a governar a operação. (Fase 2) A ativação já NÃO gera
    // visitas: passam a ser agendadas manualmente na agenda. Exige só ≥1 equipamento
    // (âmbito do contrato). O gerador/job de visitas continua no código, mas deixou de
    // ser chamado aqui (usado pelo KPI e disponível para revert).
    public function ativar(): void
    {
        if ($this->contrato->estado !== EstadoContrato::Rascunho) {
            session()->flash('erro', 'Só é possível ativar contratos em rascunho.');

            return;
        }

        if ($this->contrato->equipamentos()->count() === 0) {
            session()->flash('erro', 'Associe pelo menos um equipamento antes de ativar.');

            return;
        }

        $this->contrato->update(['estado' => EstadoContrato::Ativo]);
        Auditor::registar('contrato_mudou_estado', $this->contrato, ['numero' => $this->contrato->numero, 'de' => 'rascunho', 'para' => 'ativo']);
        session()->flash('sucesso', 'Contrato ativado.');
    }

    public function suspender(): void
    {
        if ($this->contrato->estado === EstadoContrato::Ativo) {
            $this->contrato->update(['estado' => EstadoContrato::Suspenso]);
            Auditor::registar('contrato_mudou_estado', $this->contrato, ['numero' => $this->contrato->numero, 'de' => 'ativo', 'para' => 'suspenso']);
            session()->flash('sucesso', 'Contrato suspenso.');
        }
    }

    public function reativar(): void
    {
        if ($this->contrato->estado === EstadoContrato::Suspenso) {
            // Mesma invariante do ativar (CLAUDE.md §6): sem equipamentos não há contrato ativo.
            // Sem isto, rascunho→suspender→reativar contornava a regra em 3 cliques (12.ª revisão).
            if ($this->contrato->equipamentos()->count() === 0) {
                session()->flash('erro', 'Associe pelo menos um equipamento antes de reativar.');

                return;
            }

            $this->contrato->update(['estado' => EstadoContrato::Ativo]);
            Auditor::registar('contrato_mudou_estado', $this->contrato, ['numero' => $this->contrato->numero, 'de' => 'suspenso', 'para' => 'ativo']);
            session()->flash('sucesso', 'Contrato reativado.');
        }
    }

    public function render()
    {
        $this->contrato->load([
            'cliente',
            'equipamentos.local.cliente',
            'slas',
        ]);

        return view('livewire.contratos.ficha', [
            'contrato' => $this->contrato,
            // Saldo de visitas incluídas (o cálculo vive no modelo — o modal da agenda também
            // o mostra). Null se o contrato não tem cláusula de visitas.
            'saldo' => $this->contrato->saldoVisitas(),
            // Visitas que alimentam o saldo (Vaga 2) — as mais recentes primeiro.
            'visitas' => $this->contrato->eventos()
                ->with('tecnico')
                ->orderByDesc('inicio')
                ->limit(50)
                ->get(),
            // Relatórios feitos no âmbito do contrato (mais recentes primeiro).
            'relatorios' => $this->contrato->relatorios()
                ->with('intervencao.equipamento', 'intervencao.tecnico', 'intervencao.tecnicos')
                ->orderByDesc('data')
                ->get(),
        ]);
    }
}
