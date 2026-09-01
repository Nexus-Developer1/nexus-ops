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

    #[Url]
    public string $tipo = '';

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

    public function filtrar(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function render(ServicoAlertas $servico)
    {
        $eu = auth()->user();
        $modo = $this->atribuido !== '' ? $this->atribuido : ($eu->ehAdmin() ? 'todos' : 'meus');
        $alertas = $servico->recolher()->filter(fn ($a) => match (true) {
            $modo === 'todos' => true,
            $modo === 'equipa' => $a['atribuido_a'] === [],
            $modo === 'meus' => ServicoAlertas::visivelPara($a, $eu) && ($a['atribuido_a'] === [] || in_array($eu->id, $a['atribuido_a'], true)),
            ctype_digit($modo) => in_array((int) $modo, $a['atribuido_a'], true),
            default => true,
        })->values();

        // Contagens DINÂMICAS por tipo (Vaga 1): a lista fixa de 4 tipos fazia o cartão
        // "Todos" não bater com a lista quando havia visitas/manutenções programadas ou
        // alertas de backup — o gestor via "6" num painel com 10 linhas.
        $contagens = $alertas->countBy('tipo')->all();

        if ($this->tipo) {
            $alertas = $alertas->where('tipo', $this->tipo)->values();
        }

        return view('livewire.alertas.painel', [
            'alertas' => $alertas,
            'contagens' => $contagens,
            'modo' => $modo,
            'listaConcluidos' => $this->concluidos ? $servico->concluidos() : collect(),
            'equipa' => User::where('ativo', true)
                ->whereIn('papel', [PapelUtilizador::Tecnico->value, PapelUtilizador::Admin->value])
                ->orderBy('nome')->get(['id', 'nome']),
        ]);
    }
}
