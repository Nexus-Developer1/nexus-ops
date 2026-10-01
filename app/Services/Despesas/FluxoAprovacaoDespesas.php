<?php

namespace App\Services\Despesas;

use App\Enums\EstadoDespesa;
use App\Models\RegistoDespesa;
use App\Models\User;
use App\Notifications\DespesaDecidida;
use App\Notifications\DespesaSubmetida;
use App\Services\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as Notificador;

// Processo de validação das despesas (pedido da equipa):
//   guardar → PENDENTE + emails: ao APROVADOR o pedido de aprovação; a quem criou uma
//   confirmação de submissão; ao financeiro um registo informativo (sem a parte de aprovar)
//   aprovador aprova/rejeita → email de decisão IGUAL para os três; na APROVAÇÃO também à
//   contabilidade (despesas.notificar_aprovacao), que só trata do que está aprovado
//   rejeitada e corrigida → volta a PENDENTE (novos emails); aprovada = fechada, ninguém edita.
//   APROVAÇÃO PARCIAL (out. 2026): o aprovador recusa algumas linhas (com motivo) e aprova as
//   outras → «Aprovada parcialmente», fechada como uma aprovada. Os emails são os da aprovação;
//   a contabilidade recebe SÓ as linhas aprovadas, os outros veem também as recusadas e porquê.
class FluxoAprovacaoDespesas
{
    // Nome da aplicação nos emails das despesas. O Nexus Suporte (Tempos) também tem despesas
    // com aprovação, e quem aprova tem de saber sem margem para dúvida de qual das duas é
    // (set. 2026): vai no assunto, no cabeçalho e numa faixa no topo do email.
    public const APLICACAO = 'Nexus IFE';

    // Aprovadores: SÓ os emails em config(despesas.aprovadores) — hoje o Paulo Gouveia. Os
    // administradores já não aprovam (pedido da equipa, set. 2026: «só o Paulo Gouveia é que
    // pode aprovar, mesmo os outros sendo admins»). Para ter um substituto, acrescenta-se o
    // email dele a DESPESAS_APROVADORES.
    public static function podeAprovar(?User $utilizador): bool
    {
        if (! $utilizador || ! $utilizador->ativo) {
            return false;
        }

        return in_array(strtolower((string) $utilizador->email), config('despesas.aprovadores', []), true);
    }

    public function submeter(RegistoDespesa $registo, bool $reenvio = false): void
    {
        $registo->update([
            'estado' => EstadoDespesa::Pendente,
            'submetido_em' => now(),
            'decidido_por' => null,
            'decidido_em' => null,
            'motivo_rejeicao' => null,
        ]);

        Auditor::registar($reenvio ? 'despesa_resubmetida' : 'despesa_submetida', $registo, ['total' => $registo->total()]);

        // Cada papel recebe a SUA variante do email; quem acumular papéis (ex.: o aprovador
        // submete a própria despesa) recebe só a variante mais forte, sem duplicar.
        $registo->loadMissing('colaborador');
        $instantaneo = $this->instantaneo($registo->fresh());
        $criador = $registo->colaborador;
        $aprovadores = config('despesas.aprovadores', []);
        $enviados = [];

        $enviar = function (string $email, ?User $conta, string $variante) use (&$enviados, $instantaneo, $reenvio) {
            $email = strtolower(trim($email));
            if ($email === '' || in_array($email, $enviados, true)) {
                return;
            }
            $notificacao = new DespesaSubmetida($instantaneo, $reenvio, $variante);
            $conta ? $conta->notify($notificacao) : Notificador::route('mail', $email)->notify($notificacao);
            $enviados[] = $email;
        };

        foreach ($aprovadores as $email) {
            $enviar($email, $this->contaAtiva($email), 'aprovador');
        }
        if ($criador && $criador->ativo && filled($criador->email)) {
            $enviar($criador->email, $criador, 'criador');
        }
        foreach (config('despesas.notificar', []) as $email) {
            $enviar($email, $this->contaAtiva($email), 'informativo'); // financeiro e afins
        }
    }

    /** @throws AuthorizationException */
    public function decidir(RegistoDespesa $registo, User $quem, bool $aprovar, ?string $motivo = null): void
    {
        if (! self::podeAprovar($quem)) {
            throw new AuthorizationException('Sem permissão para aprovar despesas.');
        }
        if ($registo->estado !== EstadoDespesa::Pendente) {
            throw new \LogicException('Só despesas pendentes podem ser aprovadas ou rejeitadas.');
        }

        $registo->update([
            'estado' => $aprovar ? EstadoDespesa::Aprovada : EstadoDespesa::Rejeitada,
            'decidido_por' => $quem->id,
            'decidido_em' => now(),
            'motivo_rejeicao' => $aprovar ? null : trim((string) $motivo),
        ]);

        Auditor::registar($aprovar ? 'despesa_aprovada' : 'despesa_rejeitada', $registo, array_filter([
            'total' => $registo->total(),
            'motivo' => $aprovar ? null : trim((string) $motivo),
        ]));

        $this->notificarDecisao($registo, new DespesaDecidida($this->instantaneo($registo->fresh())), $aprovar);
    }

    /**
     * Aprovação PARCIAL: recusa as linhas indicadas (id da despesa => motivo) e aprova as
     * restantes. Tem de ficar pelo menos uma de cada lado — tudo aprovado é «Aprovar», tudo
     * recusado é «Rejeitar» (que devolve o registo ao colaborador para corrigir).
     *
     * @param  array<int, string>  $recusadas
     *
     * @throws AuthorizationException
     */
    public function decidirParcial(RegistoDespesa $registo, User $quem, array $recusadas): void
    {
        if (! self::podeAprovar($quem)) {
            throw new AuthorizationException('Sem permissão para aprovar despesas.');
        }
        if ($registo->estado !== EstadoDespesa::Pendente) {
            throw new \LogicException('Só despesas pendentes podem ser aprovadas ou rejeitadas.');
        }

        $ids = $registo->despesas()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $recusadas = collect($recusadas)->mapWithKeys(fn ($motivo, $id) => [(int) $id => trim((string) $motivo)]);
        if ($recusadas->isEmpty() || $recusadas->keys()->diff($ids)->isNotEmpty() || $recusadas->count() >= count($ids)) {
            throw new \InvalidArgumentException('A aprovação parcial tem de recusar algumas linhas deste registo e aprovar outras.');
        }
        if ($recusadas->contains(fn ($motivo) => $motivo === '')) {
            throw new \InvalidArgumentException('Cada linha recusada precisa do motivo.');
        }

        DB::transaction(function () use ($registo, $quem, $recusadas) {
            foreach ($recusadas as $id => $motivo) {
                $registo->despesas()->whereKey($id)->update(['recusada' => true, 'motivo_recusa' => mb_substr($motivo, 0, 500)]);
            }
            $registo->update([
                'estado' => EstadoDespesa::AprovadaParcialmente,
                'decidido_por' => $quem->id,
                'decidido_em' => now(),
                'motivo_rejeicao' => null,
            ]);
        });

        $registo = $registo->fresh();
        Auditor::registar('despesa_aprovada_parcialmente', $registo, [
            'total' => $registo->total(),
            'aprovado' => $registo->totalAprovado(),
            'recusadas' => $recusadas->all(),
        ]);

        $this->notificarDecisao(
            $registo,
            new DespesaDecidida($this->instantaneo($registo)),
            aprovada: true,
            // A contabilidade trata do que é para pagar: recebe só as linhas aprovadas.
            paraContabilidade: new DespesaDecidida($this->instantaneo($registo, soAprovadas: true)),
        );
    }

    // Decisão: o MESMO email para quem criou, aprovador e financeiro — e, se foi aprovada, a
    // contabilidade —, sem duplicar quando o criador é um deles. Emails de config com conta
    // ativa notificam a conta; os restantes vão por notificação "on demand".
    private function notificarDecisao(RegistoDespesa $registo, Notification $notificacao, bool $aprovada, ?Notification $paraContabilidade = null): void
    {
        $registo->loadMissing('colaborador');
        $criador = $registo->colaborador;
        $enviados = [];

        if ($criador && $criador->ativo && filled($criador->email)) {
            $criador->notify($notificacao);
            $enviados[] = strtolower($criador->email);
        }

        $destinatarios = array_merge(
            config('despesas.aprovadores', []),
            config('despesas.notificar', []),
            $aprovada ? config('despesas.notificar_aprovacao', []) : [],
        );

        $contabilidade = $aprovada ? array_map('strtolower', config('despesas.notificar_aprovacao', [])) : [];
        foreach (array_unique($destinatarios) as $email) {
            if ($email === '' || in_array($email, $enviados, true)) {
                continue;
            }
            // Contabilidade (só contabilidade — não é também aprovador/financeiro): a versão própria.
            $paraEste = $paraContabilidade && in_array(strtolower($email), $contabilidade, true)
                && ! in_array(strtolower($email), array_map('strtolower', array_merge(config('despesas.aprovadores', []), config('despesas.notificar', []))), true)
                ? $paraContabilidade : $notificacao;
            $conta = $this->contaAtiva($email);
            $conta ? $conta->notify($paraEste) : Notificador::route('mail', $email)->notify($paraEste);
            $enviados[] = $email;
        }
    }

    private function contaAtiva(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [strtolower(trim($email))])->where('ativo', true)->first();
    }

    // Instantâneo do registo para o email (vai pela fila — não depende do modelo existir).
    /** @return array<string, mixed> */
    // $soAprovadas: a versão da contabilidade numa aprovação parcial — sem as linhas recusadas.
    private function instantaneo(RegistoDespesa $registo, bool $soAprovadas = false): array
    {
        $registo->loadMissing(['colaborador', 'despesas', 'decisor']);
        $linhas = $registo->despesas->sortBy('data')->values()
            ->when($soAprovadas, fn ($c) => $c->reject(fn ($d) => $d->recusada)->values());

        return [
            'id' => $registo->id,
            'colaborador' => $registo->colaborador?->nome ?? '—',
            'total' => (float) $registo->despesas->sum('valor'),
            'total_aprovado' => (float) $registo->despesas->where('recusada', false)->sum('valor'),
            'so_aprovadas' => $soAprovadas,
            'estado' => $registo->estado->value,
            'motivo' => $registo->motivo_rejeicao,
            'decisor' => $registo->decisor?->nome,
            'decidido_em' => $registo->decidido_em?->format('d/m/Y H:i'),
            'linhas' => $linhas->map(fn ($d) => [
                'data' => $d->data->format('d/m/Y'),
                'categoria' => $d->categoria,
                'descricao' => trim($d->descricao.($d->detalhe ? ' — '.$d->detalhe : '')),
                'valor' => (float) $d->valor,
                'recusada' => (bool) $d->recusada,
                'motivo_recusa' => $d->motivo_recusa,
            ])->all(),
            'dinheiro' => $registo->contasDoDinheiro(), // levantado / gasto / saldo, ou null
            'url' => route('despesas.registo.ficha', $registo),
        ];
    }
}
