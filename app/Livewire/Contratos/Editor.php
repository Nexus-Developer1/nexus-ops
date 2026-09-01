<?php

namespace App\Livewire\Contratos;

use App\Enums\EstadoContrato;
use App\Enums\TipoContrato;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\ModeloFaturacao;
use App\Services\Auditor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'contratos', 'titulo' => 'Contratos'])]
class Editor extends Component
{
    use ApenasEquipa;

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

    /** @var array<int, int> */
    public array $equipamentoIds = [];

    // Filtro por tipo no seletor de equipamentos ('' = todos; 'ups', 'incendio', …) —
    // permite ver e marcar só as UPS ou só os equipamentos de incêndio de um cliente.
    public string $filtroTipoEquipamento = '';

    /** @var array<int, array<string, mixed>> */
    public array $slas = [];

    // Alertas de visita programados: linhas { data, texto, user_id } — o texto do aviso é
    // editável; user_id = a quem está atribuído ('' = equipa completa).
    /** @var list<array{data: ?string, texto: string, user_id: string}> */
    public array $alertasVisita = [];

    // Popup pós-gravação (só contratos em RASCUNHO): ativar / suspender / manter rascunho.
    public bool $modalEstado = false;

    public function decidirEstado(string $decisao)
    {
        abort_if($this->contrato === null, 404);
        abort_unless(in_array($decisao, ['ativar', 'suspender', 'rascunho'], true), 400);

        // Máquina de estados: este popup só existe para RASCUNHOS — re-verificado no servidor
        // (12.ª revisão de segurança: uma chamada forjada num contrato Expirado/Renovado
        // conseguia ressuscitá-lo para Ativo/Suspenso, contornando as regras da ficha).
        if ($this->contrato->fresh()->estado !== EstadoContrato::Rascunho) {
            $this->modalEstado = false;

            return redirect()->route('contratos.ficha', $this->contrato);
        }

        if ($decisao === 'ativar') {
            // Mesma regra da ficha: ativar exige ≥1 equipamento associado (CLAUDE.md §6).
            if ($this->contrato->equipamentos()->count() === 0) {
                $this->modalEstado = false;
                session()->flash('erro', 'Contrato guardado em rascunho — associe pelo menos um equipamento antes de ativar.');

                return redirect()->route('contratos.ficha', $this->contrato);
            }
            $this->contrato->update(['estado' => EstadoContrato::Ativo]);
            Auditor::registar('contrato_mudou_estado', $this->contrato, ['numero' => $this->contrato->numero, 'de' => 'rascunho', 'para' => 'ativo']);
            session()->flash('sucesso', 'Contrato guardado e ativado.');
        } elseif ($decisao === 'suspender') {
            $this->contrato->update(['estado' => EstadoContrato::Suspenso]);
            Auditor::registar('contrato_mudou_estado', $this->contrato, ['numero' => $this->contrato->numero, 'de' => 'rascunho', 'para' => 'suspenso']);
            session()->flash('sucesso', 'Contrato guardado e suspenso.');
        } else {
            session()->flash('sucesso', 'Contrato guardado (fica em rascunho).');
        }

        return redirect()->route('contratos.ficha', $this->contrato);
    }

    public function mount(?Contrato $contrato = null): void
    {
        if ($contrato && $contrato->exists) {
            $contrato->load(['equipamentos:id', 'slas', 'alertasVisita']);
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
            $this->slas = $contrato->slas->map(fn ($s) => [
                'tempo_resposta_horas' => $s->tempo_resposta_horas,
                'resposta_nbd' => $s->resposta_nbd,
                'tempo_resolucao_horas' => $s->tempo_resolucao_horas,
                'horario_cobertura' => $s->horario_cobertura,
            ])->all();
            $this->alertasVisita = $contrato->alertasVisita
                ->sortBy('data')->values()
                ->map(fn ($a) => ['data' => $a->data->toDateString(), 'texto' => $a->texto, 'user_id' => (string) ($a->user_id ?? '')])
                ->all();
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
        $contagem = Contrato::where('numero', 'like', $ano.'/%')->count();

        return sprintf('%d/%04d', $ano, $contagem + 1);
    }

    public function adicionarSla(): void
    {
        $this->slas[] = ['tempo_resposta_horas' => null, 'resposta_nbd' => false, 'tempo_resolucao_horas' => null, 'horario_cobertura' => '8x5'];
    }

    public function removerSla(int $i): void
    {
        unset($this->slas[$i]);
        $this->slas = array_values($this->slas);
    }

    public function adicionarAlertaVisita(): void
    {
        if (count($this->alertasVisita) < 24) {
            // Texto por defeito editável — o mais comum é lembrar de agendar a visita.
            $this->alertasVisita[] = ['data' => '', 'texto' => 'Agendar visita preventiva', 'user_id' => ''];
        }
    }

    public function removerAlertaVisita(int $i): void
    {
        unset($this->alertasVisita[$i]);
        $this->alertasVisita = array_values($this->alertasVisita);
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
            'slas.*.tempo_resposta_horas' => ['nullable', 'integer', 'min:0'],
            'slas.*.resposta_nbd' => ['boolean'],
            'slas.*.tempo_resolucao_horas' => ['nullable', 'integer', 'min:0'],
            'slas.*.horario_cobertura' => ['required', 'in:8x5,24x7'],
            'alertasVisita' => ['array', 'max:24'],
            'alertasVisita.*.data' => ['required', 'date'],
            'alertasVisita.*.texto' => ['required', 'string', 'max:255'],
            // Atribuição: equipa completa ('') ou uma conta ATIVA da equipa (técnico/admin).
            'alertasVisita.*.user_id' => ['nullable', \Illuminate\Validation\Rule::exists('utilizadores', 'id')
                ->where('ativo', true)->whereIn('papel', [\App\Enums\PapelUtilizador::Tecnico->value, \App\Enums\PapelUtilizador::Admin->value])],
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
            // NBD e horas de resposta são mutuamente exclusivos — NBD ganha.
            if (! empty($s['resposta_nbd'])) {
                $s['tempo_resposta_horas'] = null;
            }
            $this->contrato->slas()->create($s);
        }

        // Alertas de visita programados: substitui o conjunto (mesma mecânica dos SLAs).
        $this->contrato->alertasVisita()->delete();
        foreach ($this->alertasVisita as $a) {
            $this->contrato->alertasVisita()->create(['data' => $a['data'], 'texto' => trim($a['texto']), 'user_id' => ($a['user_id'] ?? '') !== '' ? (int) $a['user_id'] : null]);
        }

        // Contrato ainda em RASCUNHO → popup "ativar / suspender / deixar em rascunho" antes
        // de sair (pedido da equipa). Contratos já ativos/suspensos gravam e saem como sempre.
        if ($this->contrato->fresh()->estado === EstadoContrato::Rascunho) {
            $this->modalEstado = true;

            return;
        }

        session()->flash('sucesso', 'Contrato guardado com sucesso.');

        return redirect()->route('contratos.ficha', $this->contrato);
    }

    // Seleciona um cliente do combobox: guarda o id e mostra o nome no input.
    public function selecionarCliente(int $id): void
    {
        $cliente = Cliente::find($id);
        if ($cliente) {
            $this->cliente_id = $cliente->id;
            $this->clienteBusca = $cliente->nome;
        }
    }

    // Conveniência: marca TODOS os equipamentos do cliente atual como cobertos (evita marcar
    // centenas de checkboxes à mão). Respeita o filtro por tipo (com "UPS" ativo marca só as
    // UPS) e JUNTA à seleção existente — nunca desmarca (para isso há o "Limpar").
    public function selecionarTodosEquipamentos(): void
    {
        if (! $this->cliente_id) {
            return;
        }

        $ids = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
            ->when($this->filtroTipoEquipamento !== '', fn ($q) => $q->where('tipo', $this->filtroTipoEquipamento))
            ->pluck('id')
            ->all();

        $this->equipamentoIds = array_values(array_unique(array_map(intval(...), array_merge($this->equipamentoIds, $ids))));
    }

    // Desmarca todos.
    public function limparEquipamentos(): void
    {
        $this->equipamentoIds = [];
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

    public function render()
    {
        // Equipamentos disponíveis para o cliente escolhido (âmbito do contrato).
        // with local.cliente: a lista mostra a MORADA de instalação (ver Equipamento::localInstalacao()).
        $equipamentos = $this->cliente_id
            ? Equipamento::query()
                ->whereHas('local', fn ($q) => $q->where('cliente_id', $this->cliente_id))
                ->with('local.cliente')
                ->orderBy('id')
                ->get()
            : collect();

        // Chips do filtro por tipo: só os tipos que o cliente realmente tem (ex.: UPS,
        // Deteção de incêndio). A lista apresentada respeita o filtro ativo.
        $tiposEquipamentos = $equipamentos->pluck('tipo')->unique()->values();
        if ($this->filtroTipoEquipamento !== '' && ! $tiposEquipamentos->contains(fn ($t) => $t->value === $this->filtroTipoEquipamento)) {
            $this->filtroTipoEquipamento = ''; // cliente mudou e o tipo já não existe
        }
        if ($this->filtroTipoEquipamento !== '') {
            $equipamentos = $equipamentos->filter(fn ($e) => $e->tipo->value === $this->filtroTipoEquipamento)->values();
        }

        // Pesquisa de clientes server-side (nome sem acentos + NIF + nº ERP), limitada.
        $clientesFiltrados = Cliente::query()
            ->when($this->clienteBusca !== '', function ($q) {
                $termo = '%'.$this->clienteBusca.'%';
                $nomeNorm = '%'.$this->normalizarBusca($this->clienteBusca).'%';
                $q->where(function ($q) use ($termo, $nomeNorm) {
                    $q->whereRaw(self::NOME_SEM_ACENTOS.' like ?', [$nomeNorm])
                        ->orWhere('nif', 'ilike', $termo)
                        ->orWhere('id_erp', 'ilike', $termo);
                });
            })
            ->orderBy('nome')
            ->limit(30)
            ->get();

        return view('livewire.contratos.editor', [
            // Contas da equipa (técnicos e admins) para atribuir alertas.
            'equipaAlertas' => \App\Models\User::where('ativo', true)
                ->whereIn('papel', [\App\Enums\PapelUtilizador::Tecnico->value, \App\Enums\PapelUtilizador::Admin->value])
                ->orderBy('nome')->get(['id', 'nome']),
            'clientesFiltrados' => $clientesFiltrados,
            'equipamentos' => $equipamentos,
            'tiposEquipamentos' => $tiposEquipamentos,
            'tiposContrato' => TipoContrato::cases(),
            'modelosFaturacao' => ModeloFaturacao::orderBy('nome')->get(),
        ]);
    }
}
