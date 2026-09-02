<?php

namespace App\Livewire\Alertas;

use App\Enums\PapelUtilizador;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\User;
use App\Services\Alertas\ServicoAlertas;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'alertas', 'titulo' => 'Alertas'])]
class Painel extends Component
{
    use ApenasEquipa;

    // Atribuição: '' = por defeito (admin: todos; técnico: os meus) | meus | equipa | todos | id.
    #[Url]
    public string $atribuido = '';

    // Mostrar o histórico dos alertas concluídos (com quem/quando e "Reabrir").
    #[Url]
    public bool $concluidos = false;

    // Dar por concluído: sai do dashboard, do painel e do email diário até ser reaberto.
    public function concluir(string $chave, ServicoAlertas $servico): void
    {
        session()->flash('sucesso', $servico->concluir($chave, auth()->user())
            ? 'Alerta concluído.'
            : 'Esse alerta já não está em aberto.');
    }

    public function reabrir(string $chave, ServicoAlertas $servico): void
    {
        $servico->reabrir($chave);
        session()->flash('sucesso', 'Alerta reaberto.');
    }

    public function render(ServicoAlertas $servico)
    {
        $eu = auth()->user();
        // Por defeito TODOS (o mesmo que o dashboard); "os meus" é opção explícita.
        $modo = $this->atribuido !== '' ? $this->atribuido : 'todos';
        $alertas = $servico->recolher()->filter(fn ($a) => match (true) {
            $modo === 'todos' => true,
            $modo === 'equipa' => $a['atribuido_a'] === [],
            $modo === 'meus' => ServicoAlertas::visivelPara($a, $eu) && ($a['atribuido_a'] === [] || in_array($eu->id, $a['atribuido_a'], true)),
            ctype_digit($modo) => in_array((int) $modo, $a['atribuido_a'], true),
            default => true,
        })->values();

        // (Os cartões-resumo por tipo e o filtro por tipo saíram a pedido da equipa — set. 2026.)
        return view('livewire.alertas.painel', [
            'alertas' => $alertas,
            'modo' => $modo,
            'listaConcluidos' => $this->concluidos ? $servico->concluidos() : collect(),
            'equipa' => User::where('ativo', true)
                ->whereIn('papel', [PapelUtilizador::Tecnico->value, PapelUtilizador::Admin->value])
                ->orderBy('nome')->get(['id', 'nome']),
        ]);
    }
}
