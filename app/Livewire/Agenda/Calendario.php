<?php

namespace App\Livewire\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Enums\TipoEvento;
use App\Models\AssuntoEvento;
use App\Models\EventoAgenda;
use App\Models\TecnicoDisponibilidade;
use App\Models\User;
use App\Notifications\EventoAtribuido;
use App\Services\Agenda\ConversorVisita;
use App\Services\Agenda\DetetorConflitos;
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

        $this->reset('formTitulo');
        $this->formTecnicoId = $this->tecnicoId;
        $this->formInicio = Carbon::parse($inicio)->format('Y-m-d\TH:i');
        $this->formFim = Carbon::parse($fim)->format('Y-m-d\TH:i');
        $this->modalCriar = true;
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

    public function criarEvento(DetetorConflitos $detetor)
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'formTitulo' => ['required', 'string', 'max:255'],
            'formTecnicoId' => ['nullable', 'exists:utilizadores,id'],
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

        $evento = EventoAgenda::create([
            'tipo' => TipoEvento::Outro,
            'titulo' => $titulo,
            'inicio' => $inicio,
            'fim' => $fim,
            'estado' => EstadoEvento::Planeado,
            'tecnico_id' => $this->formTecnicoId,
        ]);

        // Notifica o técnico atribuído (CLAUDE.md §6).
        if ($evento->tecnico_id) {
            $evento->tecnico->notify(new EventoAtribuido($evento));
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

        return view('livewire.agenda.calendario', [
            'tecnicos' => $tecnicos,
            'evento' => $evento,
            'ausencia' => $ausencia,
            'urlIcal' => $urlIcal,
            'assuntos' => AssuntoEvento::orderBy('nome')->get(),
        ]);
    }
}
