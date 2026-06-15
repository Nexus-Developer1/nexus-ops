<?php

namespace App\Livewire\Contratos;

use App\Enums\ModeloFaturacao;
use App\Enums\Periodicidade;
use App\Enums\PrioridadeSla;
use App\Enums\TipoContrato;
use App\Enums\TipoEquipamento;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Editor extends Component
{
    public ?Contrato $contrato = null;

    // Dados gerais.
    public string $numero = '';
    public ?int $cliente_id = null;
    public string $data_inicio = '';
    public string $data_fim = '';
    public string $tipo = '';
    public string $modelo_faturacao = '';
    public ?string $valor = null;
    public string $periodo_faturacao = '';
    public string $coberturas = '';
    public string $exclusoes = '';
    public bool $renovacao_automatica = false;
    public int $periodo_aviso_dias = 30;

    /** @var array<int, int> */
    public array $equipamentoIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $planos = [];

    /** @var array<int, array<string, mixed>> */
    public array $slas = [];

    public function mount(?Contrato $contrato = null): void
    {
        if ($contrato && $contrato->exists) {
            $contrato->load(['equipamentos:id', 'planosVisita', 'slas']);
            $this->contrato = $contrato;
            $this->numero = $contrato->numero;
            $this->cliente_id = $contrato->cliente_id;
            $this->data_inicio = $contrato->data_inicio->toDateString();
            $this->data_fim = $contrato->data_fim->toDateString();
            $this->tipo = $contrato->tipo->value;
            $this->modelo_faturacao = $contrato->modelo_faturacao->value;
            $this->valor = $contrato->valor;
            $this->periodo_faturacao = $contrato->periodo_faturacao ?? '';
            $this->coberturas = $contrato->coberturas ?? '';
            $this->exclusoes = $contrato->exclusoes ?? '';
            $this->renovacao_automatica = $contrato->renovacao_automatica;
            $this->periodo_aviso_dias = $contrato->periodo_aviso_dias;
            $this->equipamentoIds = $contrato->equipamentos->pluck('id')->all();
            $this->planos = $contrato->planosVisita->map(fn ($p) => [
                'equipamento_tipo' => $p->equipamento_tipo->value,
                'periodicidade' => $p->periodicidade->value,
                'duracao_estimada_min' => $p->duracao_estimada_min,
            ])->all();
            $this->slas = $contrato->slas->map(fn ($s) => [
                'prioridade' => $s->prioridade->value,
                'tempo_resposta_horas' => $s->tempo_resposta_horas,
                'tempo_resolucao_horas' => $s->tempo_resolucao_horas,
                'horario_cobertura' => $s->horario_cobertura,
            ])->all();
        } else {
            // Sugere o próximo número sequencial (ex.: 2026/0007).
            $this->numero = $this->proximoNumero();
            $this->data_inicio = now()->toDateString();
            $this->data_fim = now()->addYear()->toDateString();
        }
    }

    private function proximoNumero(): string
    {
        $ano = now()->year;
        $contagem = Contrato::where('numero', 'like', $ano . '/%')->count();

        return sprintf('%d/%04d', $ano, $contagem + 1);
    }

    public function adicionarPlano(): void
    {
        $this->planos[] = ['equipamento_tipo' => '', 'periodicidade' => '', 'duracao_estimada_min' => null];
    }

    public function removerPlano(int $i): void
    {
        unset($this->planos[$i]);
        $this->planos = array_values($this->planos);
    }

    public function adicionarSla(): void
    {
        $this->slas[] = ['prioridade' => '', 'tempo_resposta_horas' => null, 'tempo_resolucao_horas' => null, 'horario_cobertura' => '8x5'];
    }

    public function removerSla(int $i): void
    {
        unset($this->slas[$i]);
        $this->slas = array_values($this->slas);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'max:255', Rule::unique('contratos', 'numero')->ignore($this->contrato)],
            'cliente_id' => ['required', 'exists:clientes,id'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after:data_inicio'],
            'tipo' => ['required', Rule::enum(TipoContrato::class)],
            'modelo_faturacao' => ['required', Rule::enum(ModeloFaturacao::class)],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'periodo_faturacao' => ['nullable', 'string', 'max:255'],
            'coberturas' => ['nullable', 'string'],
            'exclusoes' => ['nullable', 'string'],
            'periodo_aviso_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'equipamentoIds' => ['array'],
            'equipamentoIds.*' => ['exists:equipamentos,id'],
            'planos' => ['array'],
            'planos.*.equipamento_tipo' => ['required', Rule::enum(TipoEquipamento::class)],
            'planos.*.periodicidade' => ['required', Rule::enum(Periodicidade::class)],
            'planos.*.duracao_estimada_min' => ['nullable', 'integer', 'min:0'],
            'slas' => ['array'],
            'slas.*.prioridade' => ['required', Rule::enum(PrioridadeSla::class)],
            'slas.*.tempo_resposta_horas' => ['nullable', 'integer', 'min:0'],
            'slas.*.tempo_resolucao_horas' => ['nullable', 'integer', 'min:0'],
            'slas.*.horario_cobertura' => ['required', 'in:8x5,24x7'],
        ];
    }

    public function guardar()
    {
        $dados = $this->validate();

        $atributos = [
            'numero' => $this->numero,
            'cliente_id' => $this->cliente_id,
            'data_inicio' => $this->data_inicio,
            'data_fim' => $this->data_fim,
            'tipo' => $this->tipo,
            'modelo_faturacao' => $this->modelo_faturacao,
            'valor' => $this->valor !== '' ? $this->valor : null,
            'periodo_faturacao' => $this->periodo_faturacao ?: null,
            'coberturas' => $this->coberturas ?: null,
            'exclusoes' => $this->exclusoes ?: null,
            'renovacao_automatica' => $this->renovacao_automatica,
            'periodo_aviso_dias' => $this->periodo_aviso_dias,
        ];

        if ($this->contrato) {
            $this->contrato->update($atributos);
        } else {
            // Contratos nascem em rascunho; a ativação gera as visitas (ver Ficha).
            $this->contrato = Contrato::create($atributos);
        }

        $this->contrato->equipamentos()->sync($this->equipamentoIds);

        // Planos e SLAs: substitui o conjunto (forma simples e previsível).
        $this->contrato->planosVisita()->delete();
        foreach ($this->planos as $p) {
            $this->contrato->planosVisita()->create($p);
        }

        $this->contrato->slas()->delete();
        foreach ($this->slas as $s) {
            $this->contrato->slas()->create($s);
        }

        session()->flash('sucesso', 'Contrato guardado com sucesso.');

        return redirect()->route('contratos.ficha', $this->contrato);
    }

    public function render()
    {
        // Equipamentos disponíveis para o cliente escolhido (âmbito do contrato).
        $equipamentos = $this->cliente_id
            ? Equipamento::query()
                ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
                ->with('local')
                ->orderBy('id')
                ->get()
            : collect();

        return view('livewire.contratos.editor', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'equipamentos' => $equipamentos,
            'tiposContrato' => TipoContrato::cases(),
            'modelosFaturacao' => ModeloFaturacao::cases(),
            'tiposEquipamento' => TipoEquipamento::cases(),
            'periodicidades' => Periodicidade::cases(),
            'prioridades' => PrioridadeSla::cases(),
        ]);
    }
}
