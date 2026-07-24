<?php

namespace App\Services\Agenda;

use App\Enums\EstadoEvento;
use App\Enums\TipoEvento;
use App\Models\EventoAgenda;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Gravação (criar/editar) e reagendamento de eventos da agenda: verificação de conflitos e
// escrita na MESMA transação, serializada por técnico (advisory lock — sem double-booking).
// Extraído do componente Calendario: aqui vivem as regras de agendamento; o componente fica
// com o estado de UI (modais, formulário, notificações e mensagens).
class AgendadorEvento
{
    public function __construct(private DetetorConflitos $detetor) {}

    /**
     * Cria (editandoId null) ou edita um evento próprio da agenda.
     *
     * @param  array<string, mixed>  $atributos  atributos do evento (titulo, inicio, fim, técnico, âmbito, cobertura)
     * @param  Collection<int, \App\Models\User>  $tecnicos  contas escolhidas, ordenadas (1.ª = principal)
     * @param  list<int>  $adicionaisIds  ids dos técnicos além do principal (pivot evento_tecnicos)
     * @return array{erro?: string, bloqueado?: bool, evento?: EventoAgenda, notificar?: Collection}
     *         erro = razão legível (horário/conflito); bloqueado = evento já não editável;
     *         sucesso = evento gravado + contas a notificar (fora da transação, pelo chamador)
     */
    public function gravar(array $atributos, Collection $tecnicos, array $adicionaisIds, ?int $editandoId): array
    {
        /** @var Carbon $inicio */
        $inicio = $atributos['inicio'];
        /** @var Carbon $fim */
        $fim = $atributos['fim'];

        if ($razao = $this->detetor->foraDeHorario($inicio, $fim)) {
            return ['erro' => $razao];
        }

        return DB::transaction(function () use ($atributos, $tecnicos, $adicionaisIds, $editandoId, $inicio, $fim) {
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
                $razao = $this->detetor->conflito($t->id, $inicio, $fim, $editandoId)
                    ?? $this->detetor->conflitoPorNome($t->nome, $inicio, $fim, $editandoId);
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

                // Conjunto de técnicos ANTES da edição (para notificar só os agora adicionados).
                $idsAnteriores = $evento->tecnicoIdsTodos();

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
                    ]);

                    $evento->intervencao->update(array_filter([
                        'data_inicio' => $inicio->toDateString(),
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

                // Notificar só quem foi AGORA adicionado ao evento.
                return ['evento' => $evento, 'notificar' => $tecnicos->whereNotIn('id', $idsAnteriores)];
            }

            $evento = EventoAgenda::create($atributos + [
                'tipo' => TipoEvento::Outro,
                'estado' => EstadoEvento::Planeado,
            ]);
            $evento->tecnicosAdicionais()->sync($adicionaisIds);

            // Notificar TODOS os técnicos atribuídos (CLAUDE.md §6).
            return ['evento' => $evento, 'notificar' => $tecnicos];
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
        if ($razao = $this->detetor->foraDeHorario($novoInicio, $novoFim)) {
            return ['ok' => false, 'mensagem' => $razao];
        }

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

            $evento->update(['inicio' => $novoInicio, 'fim' => $novoFim]);

            return ['ok' => true];
        });
    }
}
