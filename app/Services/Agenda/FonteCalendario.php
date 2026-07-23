<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Models\EventoAgenda;
use App\Models\TecnicoDisponibilidade;
use App\Models\User;
use Illuminate\Support\Carbon;

// Fonte de dados do FullCalendar: eventos + ausências no formato do calendário, e as cores
// por técnico (partilhadas entre eventos e legenda). Extraído do componente Calendario —
// é leitura pura, sem estado de UI.
class FonteCalendario
{
    // Paleta de cores por técnico (legenda + eventos).
    private const PALETA = ['#16a34a', '#2563eb', '#9333ea', '#ea580c', '#0891b2', '#db2777'];

    // Mapa nome→cor calculado uma vez por pedido (lazy).
    private ?array $coresTecnicos = null;

    // Eventos + ausências que se SOBREPÕEM à janela visível (não só os que começam dentro
    // dela — um evento iniciado antes e a acabar lá dentro também aparece).
    /** @return array<int, array<string, mixed>> */
    public function eventos(Carbon $de, Carbon $ate, string $tecnicoNome = ''): array
    {
        $eventos = EventoAgenda::query()
            ->with(['cliente', 'equipamento'])
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->where('inicio', '<', $ate)
            ->where('fim', '>', $de)
            ->when($tecnicoNome !== '', fn ($q) => $q->where('tecnico_nome', $tecnicoNome))
            ->get()
            ->map(function (EventoAgenda $e) {
                $cor = $this->corTecnico($e->tecnico_nome);

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
            ->when($tecnicoNome !== '', fn ($q) => $q->whereHas('tecnico', fn ($t) => $t->where('nome', $tecnicoNome)))
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

    public function corTecnico(?string $nome): string
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            return '#94a3b8'; // por atribuir
        }

        // Cor pela posição numa lista ESTÁVEL: contas de técnico por id (ids nunca mudam;
        // contas novas entram no FIM, sem mexer nas cores de quem já existe), seguidas dos
        // nomes legados (só texto) por ordem alfabética. Distintas até 6 técnicos.
        $this->coresTecnicos ??= (function (): array {
            $contas = User::where('papel', PapelUtilizador::Tecnico)
                ->orderBy('id')
                ->pluck('nome')
                ->map(fn (string $n) => trim($n));

            $legados = EventoAgenda::query()
                ->whereNotNull('tecnico_nome')
                ->where('tecnico_nome', '!=', '')
                ->distinct()
                ->orderBy('tecnico_nome')
                ->pluck('tecnico_nome')
                ->map(fn (string $n) => trim($n))
                ->reject(fn (string $n) => $contas->contains($n));

            return $contas->concat($legados)
                ->unique()
                ->values()
                ->mapWithKeys(fn (string $n, int $i) => [$n => self::PALETA[$i % count(self::PALETA)]])
                ->all();
        })();

        // Nome fora da lista (não devia acontecer): fallback determinístico por hash.
        return $this->coresTecnicos[$nome] ?? self::PALETA[abs(crc32($nome)) % count(self::PALETA)];
    }

    // Nomes de técnico usados nos eventos + cor respetiva — filtro e legenda do calendário.
    /** @return array<int, array{nome: string, cor: string}> */
    public function legenda(): array
    {
        return EventoAgenda::query()
            ->whereNotNull('tecnico_nome')
            ->where('tecnico_nome', '!=', '')
            ->distinct()
            ->orderBy('tecnico_nome')
            ->pluck('tecnico_nome')
            ->map(fn (string $nome) => ['nome' => $nome, 'cor' => $this->corTecnico($nome)])
            ->all();
    }
}
