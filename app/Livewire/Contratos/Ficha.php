<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Jobs\GerarVisitasPreventivas;
use App\Models\Contrato;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Ficha extends Component
{
    public Contrato $contrato;

    public function mount(Contrato $contrato): void
    {
        $this->contrato = $contrato;
    }

    // Ativa o contrato → passa a governar a operação. A geração automática das
    // visitas preventivas (CLAUDE.md §6) liga-se aqui quando o módulo Agenda existir.
    public function ativar(): void
    {
        if ($this->contrato->estado !== EstadoContrato::Rascunho) {
            session()->flash('erro', 'Só é possível ativar contratos em rascunho.');

            return;
        }

        if ($this->contrato->planosVisita()->count() === 0 || $this->contrato->equipamentos()->count() === 0) {
            session()->flash('erro', 'Defina pelo menos um equipamento e um plano de visita antes de ativar.');

            return;
        }

        $this->contrato->update(['estado' => EstadoContrato::Ativo]);

        // Gera as visitas preventivas para todo o período de vigência (job assíncrono).
        GerarVisitasPreventivas::dispatch($this->contrato);

        session()->flash('sucesso', 'Contrato ativado. As visitas preventivas estão a ser geradas e aparecerão na agenda.');
    }

    public function suspender(): void
    {
        if ($this->contrato->estado === EstadoContrato::Ativo) {
            $this->contrato->update(['estado' => EstadoContrato::Suspenso]);
            session()->flash('sucesso', 'Contrato suspenso.');
        }
    }

    public function reativar(): void
    {
        if ($this->contrato->estado === EstadoContrato::Suspenso) {
            $this->contrato->update(['estado' => EstadoContrato::Ativo]);
            session()->flash('sucesso', 'Contrato reativado.');
        }
    }

    public function render()
    {
        $this->contrato->load([
            'cliente',
            'equipamentos.local',
            'planosVisita',
            'slas',
        ]);

        return view('livewire.contratos.ficha', [
            'contrato' => $this->contrato,
        ]);
    }
}
