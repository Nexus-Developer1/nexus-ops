<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Models\Equipamento;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'ativos'])]
class Ficha extends Component
{
    public Equipamento $equipamento;

    public function mount(Equipamento $equipamento): void
    {
        $this->equipamento = $equipamento->load('local.cliente');
    }

    // Inicia uma nova intervenção corretiva e abre o formulário de execução.
    public function novaIntervencao()
    {
        $intervencao = $this->equipamento->intervencoes()->create([
            'tecnico_id' => auth()->id(),
            'tipo' => TipoIntervencao::Corretiva,
            'estado' => EstadoIntervencao::EmCurso,
            'data_inicio' => now(),
        ]);

        return redirect()->route('intervencoes.formulario', $intervencao);
    }

    // Especificações formatadas a partir dos atributos JSONB (adapta-se ao tipo).
    public function especificacoes(): array
    {
        $atributos = $this->equipamento->atributos ?? [];

        $mapa = [
            'potencia_kva' => fn ($v) => ['Potência', $v . ' kVA'],
            'topologia' => fn ($v) => ['Topologia', $v],
            'autonomia_min' => fn ($v) => ['Autonomia', $v . ' min'],
            'num_baterias' => fn ($v) => ['Nº de baterias', $v],
            'data_baterias' => fn ($v) => ['Data das baterias', Carbon::parse($v)->translatedFormat('M Y')],
            'firmware' => fn ($v) => ['Firmware', $v],
            'combustivel' => fn ($v) => ['Combustível', $v],
            'horas_funcionamento' => fn ($v) => ['Horas de funcionamento', number_format($v, 0, ',', '.') . ' h'],
            'num_tomadas' => fn ($v) => ['Nº de tomadas', $v],
            'corrente_a' => fn ($v) => ['Corrente', $v . ' A'],
        ];

        $specs = [];
        foreach ($atributos as $chave => $valor) {
            if (isset($mapa[$chave]) && $valor !== null && $valor !== '') {
                [$rotulo, $val] = $mapa[$chave]($valor);
                $specs[] = ['rotulo' => $rotulo, 'valor' => $val];
            }
        }

        return $specs;
    }

    public function render()
    {
        return view('livewire.equipamentos.ficha', [
            'especificacoes' => $this->especificacoes(),
            'intervencoes' => $this->equipamento->intervencoes()
                ->with('tecnico')
                ->orderByDesc('data_inicio')
                ->get(),
        ]);
    }
}
