<?php

namespace App\Livewire\Equipamentos;

use App\Enums\EstadoIntervencao;
use App\Enums\TipoIntervencao;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Equipamento;
use App\Models\Intervencao;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'ativos'])]
class Ficha extends Component
{
    use ApenasEquipa;

    public Equipamento $equipamento;

    public string $notas = '';

    // Identificação editável: cliente final (texto livre) e localização física da instalação.
    public string $clienteFinal = '';
    public string $localizacaoInstalacao = '';

    // Banco de baterias (parte do equipamento). Identidade em atributos; datas na coluna própria.
    public string $bancoNumeroSerie = '';
    public string $bancoModelo = '';
    public string $bancoCapacidade = '';
    public string $numBaterias = '';
    public string $dataBaterias = '';
    public string $proximaTrocaBaterias = '';

    // Componentes do sistema (equipamentos compostos) — { designacao, quantidade }.
    /** @var list<array{designacao: string, quantidade: string|int}> */
    public array $componentes = [];

    public function mount(Equipamento $equipamento): void
    {
        $this->equipamento = $equipamento->load('local.cliente');
        $this->notas = $equipamento->notas ?? '';
        $this->clienteFinal = $equipamento->cliente_final ?? '';
        $this->localizacaoInstalacao = $equipamento->localizacao_instalacao ?? '';

        $attrs = $equipamento->atributos ?? [];
        $this->bancoNumeroSerie = (string) ($attrs['banco_numero_serie'] ?? '');
        $this->bancoModelo = (string) ($attrs['banco_modelo'] ?? '');
        $this->bancoCapacidade = (string) ($attrs['banco_capacidade'] ?? '');
        $this->numBaterias = isset($attrs['num_baterias']) ? (string) $attrs['num_baterias'] : '';
        $this->dataBaterias = ! empty($attrs['data_baterias']) ? Carbon::parse($attrs['data_baterias'])->format('Y-m-d') : '';
        $this->proximaTrocaBaterias = $equipamento->proxima_troca_baterias?->format('Y-m-d') ?? '';
        $this->componentes = array_values($attrs['componentes'] ?? []);
    }

    public function adicionarComponente(): void
    {
        $this->componentes[] = ['designacao' => '', 'quantidade' => 1];
    }

    public function removerComponente(int $indice): void
    {
        unset($this->componentes[$indice]);
        $this->componentes = array_values($this->componentes);
    }

    // Guarda a lista de componentes (só linhas preenchidas). Preserva os restantes atributos.
    public function guardarComponentes(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $componentes = collect($this->componentes)
            ->map(fn ($c) => ['designacao' => trim((string) ($c['designacao'] ?? '')), 'quantidade' => (int) ($c['quantidade'] ?? 0)])
            ->filter(fn ($c) => $c['designacao'] !== '')
            ->values()
            ->all();

        $attrs = $this->equipamento->atributos ?? [];
        if ($componentes === []) {
            unset($attrs['componentes']);
        } else {
            $attrs['componentes'] = $componentes;
        }

        $this->equipamento->update(['atributos' => $attrs ?: null]);
        $this->componentes = $componentes;

        session()->flash('sucesso', 'Componentes guardados.');
    }

    // Guarda as notas livres do equipamento.
    public function guardarNotas(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate(['notas' => ['nullable', 'string', 'max:5000']]);

        $this->equipamento->update(['notas' => trim($this->notas) ?: null]);

        session()->flash('sucesso', 'Notas guardadas.');
    }

    // Guarda o cliente final e a localização da instalação (texto livre).
    public function guardarIdentificacao(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'clienteFinal' => ['nullable', 'string', 'max:255'],
            'localizacaoInstalacao' => ['nullable', 'string', 'max:255'],
        ]);

        $this->equipamento->update([
            'cliente_final' => trim($this->clienteFinal) ?: null,
            'localizacao_instalacao' => trim($this->localizacaoInstalacao) ?: null,
        ]);

        session()->flash('sucesso', 'Identificação guardada.');
    }

    // Guarda o banco de baterias (parte do equipamento). Preserva os restantes atributos.
    public function guardarBanco(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'bancoNumeroSerie' => ['nullable', 'string', 'max:255'],
            'bancoModelo' => ['nullable', 'string', 'max:255'],
            'bancoCapacidade' => ['nullable', 'string', 'max:100'],
            'numBaterias' => ['nullable', 'integer', 'min:0'],
            'dataBaterias' => ['nullable', 'date'],
            'proximaTrocaBaterias' => ['nullable', 'date'],
        ]);

        $attrs = $this->equipamento->atributos ?? [];
        $attrs['banco_numero_serie'] = trim($this->bancoNumeroSerie) ?: null;
        $attrs['banco_modelo'] = trim($this->bancoModelo) ?: null;
        $attrs['banco_capacidade'] = trim($this->bancoCapacidade) ?: null;
        $attrs['num_baterias'] = $this->numBaterias !== '' ? (int) $this->numBaterias : null;
        $attrs['data_baterias'] = $this->dataBaterias ?: null;
        $attrs = array_filter($attrs, fn ($v) => $v !== null); // mantém o JSON limpo

        $this->equipamento->update([
            'atributos' => $attrs ?: null,
            'proxima_troca_baterias' => $this->proximaTrocaBaterias ?: null,
        ]);

        session()->flash('sucesso', 'Banco de baterias guardado.');
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
        $id = $this->equipamento->id;

        // Histórico: intervenções onde o equipamento é o PRINCIPAL (equipamento_id) ou
        // está entre os COBERTOS (pivot). Query única sobre intervencoes com EXISTS, por
        // isso cada intervenção aparece UMA só vez — mesmo que fosse principal e coberto.
        $intervencoes = Intervencao::query()
            ->where(fn ($q) => $q->where('equipamento_id', $id)
                ->orWhereHas('equipamentosCobertos', fn ($q) => $q->whereKey($id)))
            ->with('tecnico')
            ->orderByDesc('data_inicio')
            ->get();

        // Contrato(s) que cobrem este equipamento (N:M via contrato_equipamentos).
        $contratos = $this->equipamento->contratos()
            ->orderByDesc('data_inicio')
            ->get();

        return view('livewire.equipamentos.ficha', [
            'especificacoes' => $this->especificacoes(),
            'intervencoes' => $intervencoes,
            'contratos' => $contratos,
        ]);
    }
}
