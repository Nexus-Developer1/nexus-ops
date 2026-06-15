<?php

namespace App\Livewire\Alertas;

use App\Services\Alertas\ServicoAlertas;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'alertas', 'titulo' => 'Alertas'])]
class Painel extends Component
{
    #[Url]
    public string $tipo = '';

    public function filtrar(string $tipo): void
    {
        $this->tipo = $tipo;
    }

    public function render(ServicoAlertas $servico)
    {
        $alertas = $servico->recolher();

        $contagens = [
            'bateria' => $alertas->where('tipo', 'bateria')->count(),
            'renovacao' => $alertas->where('tipo', 'renovacao')->count(),
            'visita_atraso' => $alertas->where('tipo', 'visita_atraso')->count(),
            'sla' => $alertas->where('tipo', 'sla')->count(),
        ];

        if ($this->tipo) {
            $alertas = $alertas->where('tipo', $this->tipo)->values();
        }

        return view('livewire.alertas.painel', [
            'alertas' => $alertas,
            'contagens' => $contagens,
        ]);
    }
}
