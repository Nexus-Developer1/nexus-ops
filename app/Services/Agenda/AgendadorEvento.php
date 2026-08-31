<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Gravação (criar/editar) e reagendamento de eventos da agenda: verificação de conflitos e
// escrita na MESMA transação, serializada por técnico (advisory lock — sem double-booking).
// Extraído do componente Calendario: aqui vivem as regras de agendamento; o componente fica
// com o estado de UI (modais, formulário e mensagens).
class AgendadorEvento
{
    public function __construct(private DetetorConflitos $detetor) {}

    /**
     * Cria (editandoId null) ou edita um evento próprio da agenda.
     *
     * @param  array<string, mixed>  $atributos  atributos do evento (titulo, inicio, fim, técnico, âmbito, cobertura)
     * @param  Collection<int, User>  $tecnicos  contas escolhidas, ordenadas (1.ª = principal)
     * @param  list<int>  $adicionaisIds  ids dos técnicos além do principal (pivot evento_tecnicos)
     * @return array{erro?: string, bloqueado?: bool, evento?: EventoAgenda}
     *                                                                       erro = razão legível (horário/conflito); bloqueado = evento já não editável
     */
    public function gravar(array $atributos, Collection $tecnicos, array $adicionaisIds, ?int $editandoId, array $equipamentosExtraIds = []): array
    {
        /** @var Carbon $inicio */
        $inicio = $atributos['inicio'];
        /** @var Carbon $fim */
        $fim = $atributos['fim'];

        // Segmentos reais (horas por dia, eventos multi-dia): os conflitos comparam o trabalho
        // efetivo de cada dia, não o intervalo contínuo (as noites pelo meio ficam livres).
        $segmentos = collect($atributos['horas_dias'] ?? [])
            ->map(fn (array $l) => [
                Carbon::parse($l['dia'].' '.$l['inicio']),
                Carbon::parse($l['dia'].' '.$l['fim']),
            ])
            ->values()
            ->all() ?: null;

        return DB::transaction(function () use ($atributos, $tecnicos, $adicionaisIds, $editandoId, $inicio, $fim, $segmentos, $equipamentosExtraIds) {
            // Trava por id de conta E por nome: o reagendamento de eventos legados trava
            // pelo nome, e assim os dois caminhos serializam entre si.
            $this->detetor->travarAgendaDe([
                ...$tecnicos->pluck('id')->all(),
                ...$tecnicos->pluck('nome')->all(),
            ]);

            foreach ($tecnicos as $t) {
                // Por CONTA: sobreposição com eventos ligados à conta do técnico.
                // Por NOME: apanha também eventos legados (só texto, sem conta) do mesmo técnico.
                // Na edição, o próprio evento é excluído (senão conflituava consigo mesmo).
                $razao = $this->detetor->conflito($t->id, $inicio, $fim, $editandoId, $segmentos)
                    ?? $this->detetor->conflitoPorNome($t->nome, $inicio, $fim, $editandoId, $segmentos);
                if ($razao) {
                    return ['erro' => $razao];
                }
            }

            if ($editandoId) {
                // EDIÇÃO: atualiza o evento existente (tipo e estado ficam como estão). Re-verifica
                // a elegibilidade — o estado pode ter mudado desde que o modal abriu (ex.: outro
                // utilizador finalizou o relatório entretanto).
                $evento = EventoAgenda::with('intervencao.relatorio')->findOrFail($editandoId);
                if (! $evento->editavelPelaAgenda()) {
                    return ['bloqueado' => true];
                }

                if ($evento->intervencao_id) {
                    // Convertido (relatório em rascunho): equipamento/contrato pertencem ao relatório
                    // e não mudam por aqui — só título, técnicos, datas e cobertura. Datas/horas e
                    // técnico principal propagam-se à intervenção para os dois lados contarem a mesma
                    // história (única fonte de verdade — CLAUDE.md §6).
                    $evento->update([
                        'titulo' => $atributos['titulo'],
                        'inicio' => $inicio,
                        'fim' => $fim,
                        'tecnico_id' => $atributos['tecnico_id'],
                        'tecnico_nome' => $atributos['tecnico_nome'],
                        'cobertura' => $evento->contrato_id ? ($atributos['cobertura'] ?? null) : null,
                        'horas_dias' => $atributos['horas_dias'] ?? null,
                    ]);

                    $evento->intervencao->update(array_filter([
                        'data_inicio' => $inicio->toDateString(),
                        'data_fim' => $fim, // término real (data + hora do fim do evento)
                        'hora_inicio' => $inicio->format('H:i'),
                        'hora_fim' => $fim->format('H:i'),
                        // Só propaga o técnico quando foi escolhido (não apaga o principal do relatório).
                        'tecnico_id' => $atributos['tecnico_id'],
                    ]));
                } else {
                    $evento->update($atributos);
                }

                // Técnicos adicionais (além do principal) — em ambos os tipos de edição.
                $evento->tecnicosAdicionais()->sync($adicionaisIds);
                $evento->unsetRelation('tecnicosAdicionais');
                // A pivot não dispara eventos do modelo: o touch garante que uma edição SÓ de
                // técnicos chega ao observer (espelho no calendário do M365).
                $evento->touch();

                // Equipamentos adicionais — na pivot do evento E, se já há relatório, nos COBERTOS
                // do relatório (uma só fonte de verdade; o principal do relatório não muda por aqui).
                $evento->equipamentosAdicionais()->sync($equipamentosExtraIds);
                if ($evento->intervencao_id) {
                    $principalRelatorio = (int) $evento->intervencao->equipamento_id;
                    $evento->intervencao->equipamentosCobertos()->sync(array_values(array_diff($equipamentosExtraIds, [$principalRelatorio])));
                }
                $evento->unsetRelation('equipamentosAdicionais');

                return ['evento' => $evento];
            }

            $evento = EventoAgenda::create($atributos + [
                'tipo' => TipoEvento::Outro,
                'estado' => EstadoEvento::Planeado,
            ]);
            $evento->tecnicosAdicionais()->sync($adicionaisIds);
            $evento->equipamentosAdicionais()->sync($equipamentosExtraIds);

            return ['evento' => $evento];
        });
    }

    /**
     * Reagenda (drag/resize): valida horário e conflitos de TODOS os técnicos do evento
     * dentro da transação serializada, e grava as novas datas.
     *
     * @return array{ok: bool, mensagem?: string}
     */
    public function reagendar(EventoAgenda $evento, Carbon $novoInicio, Carbon $novoFim): array
    {
        return DB::transaction(function () use ($evento, $novoInicio, $novoFim) {
            $this->detetor->travarAgendaDe($evento->tecnicoIdsTodos() !== []
                ? $evento->tecnicoIdsTodos()
                : array_filter([$evento->tecnico_nome]));

            // Verifica TODOS os técnicos do evento (principal + adicionais) no novo horário.
            foreach ($evento->tecnicoIdsTodos() as $idTecnico) {
                if ($razao = $this->detetor->conflito($idTecnico, $novoInicio, $novoFim, $evento->id)) {
                    return ['ok' => false, 'mensagem' => $razao];
                }
            }

            // Eventos legados (só nome, sem conta): deteta pelo menos a sobreposição por nome.
            if ($evento->tecnicoIdsTodos() === [] && $evento->tecnico_nome
                && $razao = $this->detetor->conflitoPorNome($evento->tecnico_nome, $novoInicio, $novoFim, $evento->id)) {
                return ['ok' => false, 'mensagem' => $razao];
            }

            // O arrasto define um intervalo contínuo novo — horas por dia anteriores deixariam
            // de corresponder (a UI nem oferece arrasto a eventos segmentados; guarda defensiva).
            $evento->update(['inicio' => $novoInicio, 'fim' => $novoFim, 'horas_dias' => null]);

            return ['ok' => true];
        });
    }
}
