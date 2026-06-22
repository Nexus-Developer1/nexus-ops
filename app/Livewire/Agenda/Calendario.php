<?php

namespace App\Livewire\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Models\AssuntoEvento;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\TecnicoDisponibilidade;
use App\Models\User;
use App\Notifications\EventoAtribuido;
use App\Services\Agenda\ConversorVisita;
use App\Services\Agenda\DetetorConflitos;
use App\Services\Agenda\GeradorRascunhoDeEvento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL as UrlFacade;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.app', ['ativo' => 'agenda', 'titulo' => 'Agenda'])]
class Calendario extends Component
{
    #[Url]
    public ?int $tecnicoId = null;

    public function mount(): void
    {
        // O técnico só vê (e filtra para) a sua própria agenda (CLAUDE.md §7).
        if (auth()->user()->ehTecnico()) {
            $this->tecnicoId = auth()->id();
        }
    }

    // Detalhe de um evento (clique num evento).
    public ?int $eventoSelecionadoId = null;

    // Detalhe de uma ausência (clique numa ausência).
    public ?int $ausenciaSelecionadaId = null;

    // Modal de criação de evento próprio (sempre tipo "outro"; o texto livre vai para o título).
    public bool $modalCriar = false;
    public string $formTitulo = '';
    public ?int $formTecnicoId = null;
    public string $formInicio = '';
    public string $formFim = '';

    // Equipamento opcional do evento (pesquisa server-side; deriva local/cliente).
    public ?int $formEquipamentoId = null;
    public string $formEquipamentoBusca = '';

    // Modal dedicado de marcação de ausência (grava em tecnico_disponibilidade).
    public bool $modalAusencia = false;
    public ?int $ausTecnicoId = null;
    public string $ausInicio = '';
    public string $ausFim = '';
    public string $ausMotivo = '';

    // Paleta de cores por técnico (legenda + eventos).
    private const PALETA = ['#16a34a', '#2563eb', '#9333ea', '#ea580c', '#0891b2', '#db2777'];

    public function updatedTecnicoId(): void
    {
        $this->recarregar();
    }

    private function recarregar(): void
    {
        $this->js("window.dispatchEvent(new Event('agenda:refetch'))");
    }

    private function corTecnico(?int $tecnicoId): string
    {
        if (! $tecnicoId) {
            return '#94a3b8'; // por atribuir
        }

        return self::PALETA[$tecnicoId % count(self::PALETA)];
    }

    // ---- Fonte de eventos do FullCalendar (intervalo visível) ----
    /** @return array<int, array<string, mixed>> */
    public function eventos(string $inicio, string $fim): array
    {
        $de = Carbon::parse($inicio);
        $ate = Carbon::parse($fim);

        $eventos = EventoAgenda::query()
            ->with(['cliente', 'equipamento'])
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->whereBetween('inicio', [$de, $ate])
            ->when($this->tecnicoId, fn ($q) => $q->where('tecnico_id', $this->tecnicoId))
            ->get()
            ->map(function (EventoAgenda $e) {
                $cor = $this->corTecnico($e->tecnico_id);

                return [
                    'id' => (string) $e->id,
                    'title' => $e->titulo,
                    'start' => $e->inicio->format('Y-m-d\TH:i:s'),
                    'end' => $e->fim->format('Y-m-d\TH:i:s'),
                    'backgroundColor' => $cor,
                    'borderColor' => $cor,
                    'extendedProps' => [
                        'kind' => 'evento',
                        'tecnico_id' => $e->tecnico_id,
                        'tipo' => $e->tipo->value,
                        'estado' => $e->estado->value,
                    ],
                ];
            })
            ->all();

        // Ausências (tecnico_disponibilidade) — eventos cinza, não arrastáveis.
        $ausencias = TecnicoDisponibilidade::query()
            ->where('inicio', '<', $ate)
            ->where('fim', '>', $de)
            ->when($this->tecnicoId, fn ($q) => $q->where('tecnico_id', $this->tecnicoId))
            ->get()
            ->map(fn (TecnicoDisponibilidade $a) => [
                'id' => 'aus-' . $a->id,
                'title' => '🚫 ' . ($a->motivo ?: 'Ausência'),
                'start' => $a->inicio->format('Y-m-d\TH:i:s'),
                'end' => $a->fim->format('Y-m-d\TH:i:s'),
                'backgroundColor' => '#e2e8f0',
                'borderColor' => '#cbd5e1',
                'textColor' => '#475569',
                'editable' => false,
                'extendedProps' => ['kind' => 'ausencia', 'ausencia_id' => $a->id],
            ])
            ->all();

        return array_merge($eventos, $ausencias);
    }

    // ---- Reagendamento (drag/resize) ----
    /** @return array<string, mixed> */
    public function reagendar(int $id, string $inicio, string $fim, ?int $tecnicoId, DetetorConflitos $detetor): array
    {
        $evento = EventoAgenda::findOrFail($id);

        $novoInicio = Carbon::parse($inicio);
        $novoFim = Carbon::parse($fim);

        if ($razao = $detetor->foraDeHorario($novoInicio, $novoFim)) {
            return ['ok' => false, 'mensagem' => $razao];
        }

        if ($tecnicoId && $razao = $detetor->conflito($tecnicoId, $novoInicio, $novoFim, $evento->id)) {
            return ['ok' => false, 'mensagem' => $razao];
        }

        $evento->update(['inicio' => $novoInicio, 'fim' => $novoFim]);

        return ['ok' => true];
    }

    // ---- Detalhe + conversão evento→intervenção ----
    public function selecionar(int $id): void
    {
        $this->eventoSelecionadoId = $id;
    }

    public function fecharModal(): void
    {
        $this->eventoSelecionadoId = null;
    }

    // Remove um evento próprio (apenas tipo "outro"; visitas/intervenções não).
    public function removerEvento(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::findOrFail($this->eventoSelecionadoId);
        if ($evento->tipo === TipoEvento::Outro) {
            $evento->delete();
        }

        $this->eventoSelecionadoId = null;
        $this->recarregar();
    }

    public function iniciarVisita(ConversorVisita $conversor)
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $evento = EventoAgenda::findOrFail($this->eventoSelecionadoId);
        $intervencao = $conversor->iniciar($evento, $evento->tecnico_id ?? auth()->id());

        return redirect()->route('intervencoes.formulario', $intervencao);
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'formTitulo' => 'tipo de evento',
            'formEquipamentoId' => 'equipamento',
            'formInicio' => 'início',
            'formFim' => 'fim',
            'ausTecnicoId' => 'técnico',
            'ausInicio' => 'início',
            'ausFim' => 'fim',
            'ausMotivo' => 'motivo',
        ];
    }

    // ---- Criação de evento próprio ----
    public function abrirCriacao(string $inicio, string $fim): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->reset(['formTitulo', 'formEquipamentoId', 'formEquipamentoBusca']);
        $this->formTecnicoId = $this->tecnicoId;
        $this->formInicio = Carbon::parse($inicio)->format('Y-m-d\TH:i');
        $this->formFim = Carbon::parse($fim)->format('Y-m-d\TH:i');
        $this->modalCriar = true;
    }

    // Seleção de um equipamento na pesquisa server-side: fixa o id e o texto da label.
    public function selecionarEquipamento(int $id): void
    {
        $equipamento = Equipamento::with('local')->find($id);
        if (! $equipamento) {
            return;
        }

        $this->formEquipamentoId = $equipamento->id;
        $this->formEquipamentoBusca = trim(($equipamento->numero_serie ?? '—')
            . ' · ' . trim($equipamento->fabricante . ' ' . $equipamento->modelo));
    }

    // Escrever na caixa desfaz a seleção (campo opcional: sem escolha => sem equipamento).
    public function updatedFormEquipamentoBusca(): void
    {
        $this->formEquipamentoId = null;
    }

    // Dobra de acentos para pesquisa (igual ao combobox de cliente).
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }

    public function fecharCriar(): void
    {
        $this->modalCriar = false;
    }

    // Guarda um novo assunto de evento próprio (lookup que cresce com o uso) e
    // seleciona-o. Idempotente: se já existir (sem acentos/maiúsculas), reutiliza.
    public function adicionarAssunto(string $nome): bool
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome));

        if ($nome === '') {
            $this->addError('novoAssunto', 'Indique o assunto do evento.');

            return false;
        }

        AssuntoEvento::firstOrCreate(
            ['nome_normalizado' => AssuntoEvento::normalizar($nome)],
            ['nome' => $nome],
        );

        $this->formTitulo = $nome;
        $this->resetErrorBag('novoAssunto');

        return true;
    }

    public function criarEvento(DetetorConflitos $detetor, GeradorRascunhoDeEvento $geradorRascunho)
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'formTitulo' => ['required', 'string', 'max:255'],
            'formTecnicoId' => ['nullable', 'exists:utilizadores,id'],
            'formEquipamentoId' => ['nullable', 'exists:equipamentos,id'],
            'formInicio' => ['required', 'date'],
            'formFim' => ['required', 'date', 'after:formInicio'],
        ]);

        $inicio = Carbon::parse($this->formInicio);
        $fim = Carbon::parse($this->formFim);

        // Evento próprio. Verifica horário e conflito de técnico.
        if ($razao = $detetor->foraDeHorario($inicio, $fim)) {
            $this->addError('formInicio', $razao);

            return;
        }
        if ($this->formTecnicoId && $razao = $detetor->conflito($this->formTecnicoId, $inicio, $fim)) {
            $this->addError('formInicio', $razao);

            return;
        }

        // O tipo de evento (texto livre) fica guardado para sugestões futuras (cresce com o uso).
        $titulo = trim(preg_replace('/\s+/', ' ', $this->formTitulo));
        if ($titulo !== '') {
            AssuntoEvento::firstOrCreate(
                ['nome_normalizado' => AssuntoEvento::normalizar($titulo)],
                ['nome' => $titulo],
            );
        }

        // Equipamento opcional: se escolhido, herda local e cliente (equipamento → local → cliente).
        $equipamentoId = $localId = $clienteId = null;
        if ($this->formEquipamentoId) {
            $equipamento = Equipamento::with('local')->find($this->formEquipamentoId);
            $equipamentoId = $equipamento->id;
            $localId = $equipamento->local_id;
            $clienteId = $equipamento->local?->cliente_id;
        }

        $evento = EventoAgenda::create([
            'tipo' => TipoEvento::Outro,
            'titulo' => $titulo,
            'inicio' => $inicio,
            'fim' => $fim,
            'estado' => EstadoEvento::Planeado,
            'tecnico_id' => $this->formTecnicoId,
            'equipamento_id' => $equipamentoId,
            'local_id' => $localId,
            'cliente_id' => $clienteId,
        ]);

        // Notifica o técnico atribuído (CLAUDE.md §6).
        if ($evento->tecnico_id) {
            $evento->tecnico->notify(new EventoAtribuido($evento));
        }

        // Camada 2: evento com equipamento + data futura → gera rascunho de relatório ligado.
        if ($equipamentoId && $inicio->isFuture() && $geradorRascunho->gerar($evento)) {
            session()->flash('sucesso', 'Rascunho de relatório criado para esta intervenção.');
        }

        $this->modalCriar = false;
        $this->recarregar();
    }

    // ---- Marcação de ausência (modal dedicado → tecnico_disponibilidade) ----
    public function abrirAusencia(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->reset(['ausInicio', 'ausFim', 'ausMotivo']);
        $this->ausTecnicoId = $this->tecnicoId; // técnico vê-se a si próprio pré-selecionado
        $this->modalAusencia = true;
    }

    public function fecharMarcarAusencia(): void
    {
        $this->modalAusencia = false;
    }

    public function marcarAusencia(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'ausTecnicoId' => ['required', 'exists:utilizadores,id'],
            'ausInicio' => ['required', 'date'],
            'ausFim' => ['required', 'date', 'after:ausInicio'],
            'ausMotivo' => ['nullable', 'string', 'max:255'],
        ]);

        // Indisponibiliza o técnico no período (lido por DetetorConflitos).
        TecnicoDisponibilidade::create([
            'tecnico_id' => $this->ausTecnicoId,
            'tipo' => 'ausencia',
            'inicio' => Carbon::parse($this->ausInicio),
            'fim' => Carbon::parse($this->ausFim),
            'motivo' => $this->ausMotivo ?: 'Ausência',
        ]);

        $this->modalAusencia = false;
        $this->recarregar();
    }

    // ---- Ausências ----
    public function selecionarAusencia(int $id): void
    {
        $this->ausenciaSelecionadaId = $id;
    }

    public function fecharAusencia(): void
    {
        $this->ausenciaSelecionadaId = null;
    }

    public function removerAusencia(): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        TecnicoDisponibilidade::whereKey($this->ausenciaSelecionadaId)->delete();
        $this->ausenciaSelecionadaId = null;
        $this->recarregar();
    }

    public function render()
    {
        $tecnicos = User::where('papel', PapelUtilizador::Tecnico)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (User $t) => [
                'id' => $t->id,
                'nome' => $t->nome,
                'cor' => $this->corTecnico($t->id),
            ]);

        $evento = $this->eventoSelecionadoId
            ? EventoAgenda::with(['cliente', 'equipamento', 'tecnico', 'intervencao'])->find($this->eventoSelecionadoId)
            : null;

        $ausencia = $this->ausenciaSelecionadaId
            ? TecnicoDisponibilidade::with('tecnico')->find($this->ausenciaSelecionadaId)
            : null;

        // URL iCal assinada do técnico filtrado (subscrição externa).
        $urlIcal = $this->tecnicoId
            ? UrlFacade::signedRoute('agenda.ical', ['tecnico' => $this->tecnicoId])
            : null;

        // Pesquisa de equipamentos server-side (nº série/fabricante/modelo, sem acentos), limitada.
        // São muitos (~17k) — nunca carregar tudo no cliente.
        $equipamentosFiltrados = $this->formEquipamentoBusca !== ''
            ? Equipamento::query()
                ->with('local.cliente')
                ->where(function ($q) {
                    $termo = '%' . $this->formEquipamentoBusca . '%';
                    $norm = '%' . $this->normalizarBusca($this->formEquipamentoBusca) . '%';
                    $semAcentos = "translate(lower(fabricante || ' ' || modelo), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";
                    $q->whereRaw($semAcentos . ' like ?', [$norm])
                        ->orWhere('numero_serie', 'ilike', $termo);
                })
                ->orderBy('numero_serie')
                ->limit(30)
                ->get()
            : collect();

        return view('livewire.agenda.calendario', [
            'tecnicos' => $tecnicos,
            'evento' => $evento,
            'ausencia' => $ausencia,
            'urlIcal' => $urlIcal,
            'assuntos' => AssuntoEvento::orderBy('nome')->get(),
            'equipamentosFiltrados' => $equipamentosFiltrados,
        ]);
    }
}
