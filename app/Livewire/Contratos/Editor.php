<?php

namespace App\Livewire\Contratos;

use App\Enums\PrioridadeSla;
use App\Enums\TipoContrato;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\ModeloFaturacao;
use App\Support\FaixaEquipamentos;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Editor extends Component
{
    public ?Contrato $contrato = null;

    // Expressão pura (sem extensão) para pesquisar nome ignorando acentos/maiúsculas.
    private const NOME_SEM_ACENTOS = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    // Dados gerais.
    public string $numero = '';
    public ?int $cliente_id = null;
    // Texto do combobox de cliente (pesquisa server-side).
    public string $clienteBusca = '';
    public string $data_inicio = '';
    public string $data_fim = '';
    public ?int $visitas_incluidas = null; // total de visitas incluídas pela vida do contrato (vazio = sem cláusula)
    public string $tipo = '';
    public ?int $modelo_faturacao_id = null;
    public ?string $valor = null;
    public string $periodo_faturacao = '';
    public string $coberturas = '';
    public string $exclusoes = '';
    public bool $renovacao_automatica = false;
    public int $periodo_aviso_dias = 30;

    /** @var array<int, int> Cobertura do contrato (ids em contrato_equipamentos). */
    public array $equipamentoIds = [];

    // Faixa do fluxo de equipamentos por nº do cliente (auto/lista/pesquisa) — evita renderizar
    // 1.053 checkboxes de um cliente grande. Ver App\Support\FaixaEquipamentos.
    public string $faixaEquipamentos = '';
    public string $equipamentoBusca = ''; // pesquisa server-side (faixa 'pesquisa')

    /** @var array<int, array<string, mixed>> */
    public array $slas = [];

    public function mount(?Contrato $contrato = null): void
    {
        if ($contrato && $contrato->exists) {
            $contrato->load(['equipamentos:id', 'slas']);
            $this->contrato = $contrato;
            $this->numero = $contrato->numero;
            $this->cliente_id = $contrato->cliente_id;
            $this->clienteBusca = $contrato->cliente?->nome ?? '';
            $this->data_inicio = $contrato->data_inicio->toDateString();
            $this->data_fim = $contrato->data_fim->toDateString();
            $this->visitas_incluidas = $contrato->visitas_incluidas;
            $this->tipo = $contrato->tipo->value;
            $this->modelo_faturacao_id = $contrato->modelo_faturacao_id;
            $this->valor = $contrato->valor;
            $this->periodo_faturacao = $contrato->periodo_faturacao ?? '';
            $this->coberturas = $contrato->coberturas ?? '';
            $this->exclusoes = $contrato->exclusoes ?? '';
            $this->renovacao_automatica = $contrato->renovacao_automatica;
            $this->periodo_aviso_dias = $contrato->periodo_aviso_dias;
            $this->equipamentoIds = $contrato->equipamentos->pluck('id')->all();
            // Faixa pela contagem do cliente (só count — NÃO carrega os equipamentos todos).
            $this->faixaEquipamentos = FaixaEquipamentos::para($this->totalEquipamentosCliente());
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

    public function adicionarSla(): void
    {
        $this->slas[] = ['prioridade' => '', 'tempo_resposta_horas' => null, 'tempo_resolucao_horas' => null, 'horario_cobertura' => '8x5'];
    }

    public function removerSla(int $i): void
    {
        unset($this->slas[$i]);
        $this->slas = array_values($this->slas);
    }

    // Cria um novo modelo de faturação (persistido) e seleciona-o. Rejeita duplicados
    // (case-insensitive, sem acentos). Devolve true em caso de sucesso.
    public function adicionarModelo(string $nome): bool
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        if ($nome === '') {
            $this->addError('novoModelo', 'Indique o nome do modelo de faturação.');

            return false;
        }

        if (ModeloFaturacao::where('nome_normalizado', ModeloFaturacao::normalizar($nome))->exists()) {
            $this->addError('novoModelo', 'Já existe um modelo de faturação com esse nome.');

            return false;
        }

        $modelo = ModeloFaturacao::create(['nome' => $nome]);
        $this->modelo_faturacao_id = $modelo->id;
        $this->resetErrorBag('novoModelo');

        return true;
    }

    public function cancelarModelo(): void
    {
        $this->resetErrorBag('novoModelo');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'numero' => ['required', 'string', 'max:255', Rule::unique('contratos', 'numero')->ignore($this->contrato)],
            'cliente_id' => ['required', 'exists:clientes,id'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after:data_inicio'],
            'visitas_incluidas' => ['nullable', 'integer', 'min:1'],
            'tipo' => ['required', Rule::enum(TipoContrato::class)],
            'modelo_faturacao_id' => ['required', 'integer', 'exists:modelos_faturacao,id'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'periodo_faturacao' => ['nullable', 'string', 'max:255'],
            'coberturas' => ['nullable', 'string'],
            'exclusoes' => ['nullable', 'string'],
            'periodo_aviso_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'equipamentoIds' => ['array'],
            'equipamentoIds.*' => ['exists:equipamentos,id'],
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
            'visitas_incluidas' => $this->visitas_incluidas ?: null,
            'tipo' => $this->tipo,
            'modelo_faturacao_id' => $this->modelo_faturacao_id,
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

        // SLAs: substitui o conjunto (forma simples e previsível). Os planos de visita
        // (modelo antigo) já NÃO são editados aqui — não se tocam, preservando os existentes.
        $this->contrato->slas()->delete();
        foreach ($this->slas as $s) {
            $this->contrato->slas()->create($s);
        }

        session()->flash('sucesso', 'Contrato guardado com sucesso.');

        return redirect()->route('contratos.ficha', $this->contrato);
    }

    // Seleciona um cliente do combobox: guarda o id, mostra o nome, decide a faixa e RECOMEÇA a
    // cobertura (cliente novo = equipamentos novos). Não carrega os equipamentos todos.
    public function selecionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if (! $cliente) {
            return;
        }

        $this->cliente_id = $cliente->id;
        $this->clienteBusca = $cliente->nome;
        $this->equipamentoIds = [];
        $this->equipamentoBusca = '';
        $this->faixaEquipamentos = FaixaEquipamentos::para($this->totalEquipamentosCliente());
    }

    // Nº de equipamentos do cliente escolhido (só contagem).
    private function totalEquipamentosCliente(): int
    {
        return $this->cliente_id
            ? Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))->count()
            : 0;
    }

    // Faixa 'lista' (11-50): marca todos os equipamentos do cliente. Query limitada a
    // MAX_LISTA_CHECKBOXES (50) — garante que nunca se cobrem/renderizam >50.
    public function selecionarTodosEquipamentos(): void
    {
        if ($this->cliente_id === null) {
            return;
        }

        $this->equipamentoIds = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->orderBy('numero_serie')
            ->limit(FaixaEquipamentos::MAX_LISTA_CHECKBOXES)
            ->pluck('id')
            ->all();
    }

    // Faixa 'lista': limpa a cobertura (para o "Selecionar todos" alternar com "Limpar").
    public function limparEquipamentos(): void
    {
        $this->equipamentoIds = [];
    }

    // Faixa 'pesquisa' (>50): acrescenta um equipamento à cobertura (só do cliente escolhido).
    public function adicionarEquipamento(int $id): void
    {
        if ($this->cliente_id === null || in_array($id, $this->equipamentoIds, true)) {
            $this->equipamentoBusca = '';

            return;
        }

        $doCliente = Equipamento::whereKey($id)
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->exists();

        if ($doCliente) {
            $this->equipamentoIds[] = $id;
        }

        $this->equipamentoBusca = '';
    }

    // Remove um equipamento da cobertura (chip da faixa 'pesquisa' ou desmarcar).
    public function removerEquipamento(int $id): void
    {
        $this->equipamentoIds = array_values(array_filter($this->equipamentoIds, fn ($e) => $e !== $id));
    }

    // Normaliza o termo de pesquisa (minúsculas, sem acentos) para casar com a
    // expressão translate() aplicada ao nome.
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    // Pesquisa server-side de equipamentos do cliente (nº série / modelo sem acentos), limitada.
    // Faixa 'pesquisa': vazia sem texto — NUNCA carrega os (potencialmente milhares) do cliente.
    private function equipamentosDoClienteFiltrados(): Collection
    {
        if ($this->cliente_id === null || trim($this->equipamentoBusca) === '') {
            return collect();
        }

        $termo = '%' . $this->equipamentoBusca . '%';
        $norm = '%' . $this->normalizarBusca($this->equipamentoBusca) . '%';
        $semAcentos = "translate(lower(coalesce(modelo, '')), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

        return Equipamento::query()
            ->with('local')
            ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->where(function ($q) use ($termo, $norm, $semAcentos) {
                $q->where('numero_serie', 'ilike', $termo)
                    ->orWhereRaw($semAcentos . ' like ?', [$norm]);
            })
            ->orderBy('numero_serie')
            ->limit(20)
            ->get();
    }

    public function render()
    {
        // Checkboxes (faixas 'auto'/'lista'): carrega os equipamentos do cliente, limitado a
        // MAX_LISTA_CHECKBOXES (≤50). Nas outras faixas fica vazio (não carrega os 1.053).
        $equipamentos = in_array($this->faixaEquipamentos, ['auto', 'lista'], true)
            ? Equipamento::query()
                ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
                ->with('local')
                ->orderBy('numero_serie')
                ->limit(FaixaEquipamentos::MAX_LISTA_CHECKBOXES)
                ->get()
            : collect();

        // Faixa 'pesquisa': equipamentos JÁ cobertos (para os mostrar) — só os selecionados (punhado).
        $equipamentosAdicionados = ($this->faixaEquipamentos === 'pesquisa' && $this->equipamentoIds !== [])
            ? Equipamento::with('local')->whereIn('id', $this->equipamentoIds)->orderBy('numero_serie')->get()
            : collect();

        // Pesquisa de clientes server-side (nome sem acentos + NIF + nº ERP), limitada.
        $clientesFiltrados = Cliente::query()
            ->when($this->clienteBusca !== '', function ($q) {
                $termo = '%' . $this->clienteBusca . '%';
                $nomeNorm = '%' . $this->normalizarBusca($this->clienteBusca) . '%';
                $q->where(function ($q) use ($termo, $nomeNorm) {
                    $q->whereRaw(self::NOME_SEM_ACENTOS . ' like ?', [$nomeNorm])
                        ->orWhere('nif', 'ilike', $termo)
                        ->orWhere('id_erp', 'ilike', $termo);
                });
            })
            ->orderBy('nome')
            ->limit(30)
            ->get();

        return view('livewire.contratos.editor', [
            'clientesFiltrados' => $clientesFiltrados,
            'equipamentos' => $equipamentos,
            'equipamentosFiltrados' => $this->equipamentosDoClienteFiltrados(),
            'equipamentosAdicionados' => $equipamentosAdicionados,
            'tiposContrato' => TipoContrato::cases(),
            'modelosFaturacao' => ModeloFaturacao::orderBy('nome')->get(),
            'prioridades' => PrioridadeSla::cases(),
        ]);
    }
}
