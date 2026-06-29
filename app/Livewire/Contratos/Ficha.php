<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Enums\EstadoEvento;
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
        session()->flash('sucesso', 'Contrato ativado.');
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
            'slas',
        ]);

        return view('livewire.contratos.ficha', [
            'contrato' => $this->contrato,
            'saldo' => $this->saldoVisitas(),
        ]);
    }

    // Saldo de visitas incluídas (modelo manual). Conta por COBERTURA, sem filtrar tipo
    // (apanha visitas manuais; ignora as auto-geradas, que têm cobertura null). Null se o
    // contrato não tem cláusula de visitas (visitas_incluidas vazio) → não se mostra saldo.
    /** @return array{incluidas:int, usadas:int, extras:int, restantes:int, excedido:int}|null */
    private function saldoVisitas(): ?array
    {
        $incluidas = $this->contrato->visitas_incluidas;
        if ($incluidas === null) {
            return null;
        }

        $usadas = $this->contrato->eventos()
            ->where('cobertura', 'incluida')
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->count();
        $extras = $this->contrato->eventos()
            ->where('cobertura', 'extra')
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->count();

        return [
            'incluidas' => $incluidas,
            'usadas' => $usadas,
            'extras' => $extras,
            'restantes' => max(0, $incluidas - $usadas), // nunca negativo
            'excedido' => max(0, $usadas - $incluidas),
        ];
    }
}
