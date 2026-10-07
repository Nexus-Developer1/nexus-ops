<?php

namespace App\Livewire\Despesas;

use App\Enums\EstadoDespesa;
use App\Livewire\Concerns\AcessoDespesas;
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
    use AcessoDespesas;

    public RegistoDespesa $registo;

    public string $motivo = '';

    // Aprovação PARCIAL (out. 2026): que linhas o aprovador aprova (id => true/false; nascem todas
    // aprovadas) e o motivo de cada recusada. Os ids vêm do browser — o FluxoAprovacaoDespesas só
    // aceita linhas DESTE registo.
    /** @var array<int, bool> */
    public array $aprovarLinha = [];

    /** @var array<int, string> */
    public array $motivosRecusa = [];

    public function mount(RegistoDespesa $registo): void
    {
        $this->registo = $registo;
        $this->aprovarLinha = $registo->despesas()->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    // Aprova SÓ as linhas marcadas; as desmarcadas ficam recusadas, cada uma com o seu motivo.
    public function aprovarParcialmente(FluxoAprovacaoDespesas $fluxo): void
    {
        Gate::authorize('aprovar-despesas');

        if ($this->registo->estado !== EstadoDespesa::Pendente) {
            session()->flash('erro', 'Esta despesa já foi decidida.');

            return;
        }

        $this->resetErrorBag(); // erros da tentativa anterior (ex.: motivo em falta) não contam
        $recusadas = collect($this->aprovarLinha)->filter(fn ($sim) => ! $sim)->keys()->map(fn ($id) => (int) $id);
        if ($recusadas->isEmpty()) {
            $this->aprovar($fluxo); // nada recusado = aprovação normal

            return;
        }
        if ($recusadas->count() >= $this->registo->despesas()->count()) {
            $this->addError('aprovarLinha', 'Para recusar todas as linhas use «Rejeitar…» — a despesa volta ao colaborador para corrigir.');

            return;
        }
        foreach ($recusadas as $id) {
            if (trim((string) ($this->motivosRecusa[$id] ?? '')) === '') {
                $this->addError("motivosRecusa.$id", 'Indique o motivo da recusa desta linha.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $decidiu = $this->decidirOuAvisar(fn () => $fluxo->decidirParcial($this->registo, auth()->user(),
                $recusadas->mapWithKeys(fn ($id) => [$id => (string) $this->motivosRecusa[$id]])->all()));
        } catch (\InvalidArgumentException $e) {
            $this->addError('aprovarLinha', $e->getMessage());

            return;
        }
        if (! $decidiu) {
            return;
        }

        $this->registo->refresh();
        session()->flash('sucesso', 'Despesa aprovada parcialmente — o colaborador, o financeiro e a contabilidade foram avisados por email.');
    }

    public function aprovar(FluxoAprovacaoDespesas $fluxo): void
    {
        Gate::authorize('aprovar-despesas');

        if ($this->registo->estado !== EstadoDespesa::Pendente) {
            session()->flash('erro', 'Esta despesa já foi decidida.');

            return;
        }

        if (! $this->decidirOuAvisar(fn () => $fluxo->decidir($this->registo, auth()->user(), aprovar: true))) {
            return;
        }
        $this->registo->refresh();
        session()->flash('sucesso', 'Despesa aprovada — o colaborador, o financeiro e a contabilidade foram avisados por email.');
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

        if (! $this->decidirOuAvisar(fn () => $fluxo->decidir($this->registo, auth()->user(), aprovar: false, motivo: $this->motivo))) {
            return;
        }
        $this->registo->refresh();
        $this->motivo = '';
        session()->flash('sucesso', 'Despesa rejeitada — o colaborador e o financeiro foram avisados por email.');
    }

    // Outra pessoa decidiu no mesmo instante: o fluxo recusa (o registo já não está pendente)
    // e aqui mostra-se o aviso em vez de um erro.
    private function decidirOuAvisar(\Closure $decisao): bool
    {
        try {
            $decisao();

            return true;
        } catch (\LogicException $e) {
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            $this->registo->refresh();
            session()->flash('erro', 'Esta despesa já foi decidida.');

            return false;
        }
    }

    public function render()
    {
        $linhas = $this->registo->linhasOrdenadas();

        $pendente = $this->registo->estado === EstadoDespesa::Pendente;

        return view('livewire.despesas.ficha', [
            'linhas' => $linhas,
            'total' => (float) $linhas->sum('valor'),
            'totalAprovado' => (float) $linhas->where('recusada', false)->sum('valor'),
            // Na escolha da aprovação parcial: quanto fica aprovado com as linhas marcadas agora.
            'totalAAprovar' => $pendente
                ? (float) $linhas->filter(fn ($d) => $this->aprovarLinha[$d->id] ?? true)->sum('valor')
                : null,
            'nRecusar' => $pendente ? collect($this->aprovarLinha)->filter(fn ($sim) => ! $sim)->count() : 0,
            'levantamentos' => $this->registo->levantamentos()->with('anexos')->get(),
            'contasDinheiro' => $this->registo->contasDoDinheiro(),
            'podeAprovar' => Gate::allows('aprovar-despesas'),
            'podeEditar' => $this->registo->podeSerEditado(),
        ]);
    }
}
