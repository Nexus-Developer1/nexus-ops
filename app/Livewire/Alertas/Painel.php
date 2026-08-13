<?php

namespace App\Livewire\Alertas;

use App\Livewire\Concerns\ApenasEquipa;
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

    public function filtrar(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function render(ServicoAlertas $servico)
    {
        $alertas = $servico->recolher();

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
        ]);
    }
}
