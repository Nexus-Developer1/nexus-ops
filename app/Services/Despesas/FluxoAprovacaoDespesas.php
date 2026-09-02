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
use Illuminate\Support\Facades\Notification as Notificador;

// Processo de validação das despesas (pedido da equipa):
//   guardar → PENDENTE + emails: ao APROVADOR o pedido de aprovação; a quem criou uma
//   confirmação de submissão; ao financeiro um registo informativo (sem a parte de aprovar)
//   aprovador aprova/rejeita → email de decisão IGUAL para os três
//   rejeitada e corrigida → volta a PENDENTE (novos emails); aprovada = fechada, ninguém edita.
class FluxoAprovacaoDespesas
{
    // Aprovadores: emails em config(despesas.aprovadores) + administradores (para o fluxo
    // não ficar bloqueado se o aprovador não tiver conta ou estiver ausente).
    public static function podeAprovar(?User $utilizador): bool
    {
        if (! $utilizador || ! $utilizador->ativo) {
            return false;
        }

        return $utilizador->ehAdmin()
            || in_array(strtolower((string) $utilizador->email), config('despesas.aprovadores', []), true);
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

        $this->notificarDecisao($registo, new DespesaDecidida($this->instantaneo($registo->fresh())));
    }

    // Decisão: o MESMO email para quem criou, aprovador e financeiro, sem duplicar quando o
    // criador é um deles. Emails de config com conta ativa notificam a conta; os restantes
    // vão por notificação "on demand".
    private function notificarDecisao(RegistoDespesa $registo, Notification $notificacao): void
    {
        $registo->loadMissing('colaborador');
        $criador = $registo->colaborador;
        $enviados = [];

        if ($criador && $criador->ativo && filled($criador->email)) {
            $criador->notify($notificacao);
            $enviados[] = strtolower($criador->email);
        }

        foreach (array_unique(array_merge(config('despesas.aprovadores', []), config('despesas.notificar', []))) as $email) {
            if ($email === '' || in_array($email, $enviados, true)) {
                continue;
            }
            $conta = $this->contaAtiva($email);
            $conta ? $conta->notify($notificacao) : Notificador::route('mail', $email)->notify($notificacao);
            $enviados[] = $email;
        }
    }

    private function contaAtiva(string $email): ?User
    {
        return User::whereRaw('lower(email) = ?', [strtolower(trim($email))])->where('ativo', true)->first();
    }

    // Instantâneo do registo para o email (vai pela fila — não depende do modelo existir).
    /** @return array<string, mixed> */
    private function instantaneo(RegistoDespesa $registo): array
    {
        $registo->loadMissing(['colaborador', 'despesas', 'decisor']);

        return [
            'id' => $registo->id,
            'colaborador' => $registo->colaborador?->nome ?? '—',
            'total' => (float) $registo->despesas->sum('valor'),
            'estado' => $registo->estado->value,
            'motivo' => $registo->motivo_rejeicao,
            'decisor' => $registo->decisor?->nome,
            'decidido_em' => $registo->decidido_em?->format('d/m/Y H:i'),
            'linhas' => $registo->despesas->sortBy('data')->values()->map(fn ($d) => [
                'data' => $d->data->format('d/m/Y'),
                'categoria' => $d->categoria,
                'descricao' => trim($d->descricao.($d->detalhe ? ' — '.$d->detalhe : '')),
                'valor' => (float) $d->valor,
            ])->all(),
            'url' => route('despesas.registo.ficha', $registo),
        ];
    }
}
