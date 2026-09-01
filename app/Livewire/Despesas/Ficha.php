<?php

namespace App\Livewire\Despesas;

use App\Enums\EstadoDespesa;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Anexo;
use App\Models\Despesa;
use App\Models\RegistoDespesa;
use App\Services\Despesas\FluxoAprovacaoDespesas;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Ficha (só leitura) de um registo de despesas: linhas, recibos, estado do processo de
// validação e — para o aprovador — os botões Aprovar / Rejeitar (com motivo).
#[Layout('components.layouts.app', ['ativo' => 'despesas', 'titulo' => 'Despesa'])]
class Ficha extends Component
{
    use ApenasEquipa;

    public RegistoDespesa $registo;

    public string $motivo = '';

    public function mount(RegistoDespesa $registo): void
    {
        $this->registo = $registo;
    }

    public function aprovar(FluxoAprovacaoDespesas $fluxo): void
    {
        Gate::authorize('aprovar-despesas');

        if ($this->registo->estado !== EstadoDespesa::Pendente) {
            session()->flash('erro', 'Esta despesa já foi decidida.');

            return;
        }

        $fluxo->decidir($this->registo, auth()->user(), aprovar: true);
        $this->registo->refresh();
        session()->flash('sucesso', 'Despesa aprovada — o colaborador e o financeiro foram avisados por email.');
    }

    public function rejeitar(FluxoAprovacaoDespesas $fluxo): void
    {
        Gate::authorize('aprovar-despesas');

        $this->validate(['motivo' => ['required', 'string', 'min:3', 'max:1000']], [
            'motivo.required' => 'Indique o motivo da rejeição — o colaborador precisa de saber o que corrigir.',
            'motivo.min' => 'Indique o motivo da rejeição — o colaborador precisa de saber o que corrigir.',
        ]);

        if ($this->registo->estado !== EstadoDespesa::Pendente) {
            session()->flash('erro', 'Esta despesa já foi decidida.');

            return;
        }

        $fluxo->decidir($this->registo, auth()->user(), aprovar: false, motivo: $this->motivo);
        $this->registo->refresh();
        $this->motivo = '';
        session()->flash('sucesso', 'Despesa rejeitada — o colaborador e o financeiro foram avisados por email.');
    }

    public function render()
    {
        $linhas = $this->registo->linhasOrdenadas();

        return view('livewire.despesas.ficha', [
            'linhas' => $linhas,
            'total' => (float) $linhas->sum('valor'),
            'podeAprovar' => Gate::allows('aprovar-despesas'),
            'podeEditar' => $this->registo->podeSerEditado(),
        ]);
    }
}
