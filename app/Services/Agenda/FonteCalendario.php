<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Support\Carbon;

// Fonte de dados do FullCalendar: eventos no formato do calendário, e as cores
// por técnico (partilhadas entre eventos e legenda). Extraído do componente Calendario —
// é leitura pura, sem estado de UI.
class FonteCalendario
{
    // Paleta de cores por técnico (legenda + eventos).
    private const PALETA = ['#16a34a', '#2563eb', '#9333ea', '#ea580c', '#0891b2', '#db2777'];

    // Mapa nome→cor calculado uma vez por pedido (lazy).
    private ?array $coresTecnicos = null;

    // Eventos que se SOBREPÕEM à janela visível (não só os que começam dentro
    // dela — um evento iniciado antes e a acabar lá dentro também aparece).
    /** @return array<int, array<string, mixed>> */
    public function eventos(Carbon $de, Carbon $ate, string $tecnicoNome = ''): array
    {
        $eventos = EventoAgenda::query()
            ->with(['cliente', 'equipamento', 'tecnicosAdicionais'])
            ->where('estado', '!=', EstadoEvento::Cancelado->value)
            ->where('inicio', '<', $ate)
            ->where('fim', '>', $de)
            ->when($tecnicoNome !== '', fn ($q) => $q->where('tecnico_nome', $tecnicoNome))
            ->get()
            ->map(function (EventoAgenda $e) {
                // Cores de TODOS os técnicos do evento (principal + adicionais, sem repetidos):
                // com mais do que um, o frontend divide o bloco em faixas verticais, uma cor
                // por técnico (eventDidMount no app.js). Com um só, comporta-se como sempre.
                $cores = collect([$e->tecnico_nome])
                    ->concat($e->tecnicosAdicionais->sortBy('nome')->pluck('nome'))
                    ->map(fn ($n) => trim((string) $n))
                    ->filter()
                    ->unique()
                    ->map(fn (string $n) => $this->corTecnico($n))
                    ->unique()
                    ->values();
                $cor = $cores->first() ?? $this->corTecnico(null);

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
                        'cores' => $cores->all(),
                    ],
                ];
            })
            ->all();

        return $eventos;
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
