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
//   guardar → PENDENTE + email a quem criou, ao aprovador e ao financeiro
//   aprovador aprova/rejeita → email de decisão aos mesmos
//   rejeitada e corrigida → volta a PENDENTE (novo email); aprovada = fechada, ninguém edita.
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

        $this->notificar($registo, new DespesaSubmetida($this->instantaneo($registo->fresh()), $reenvio));
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

        $this->notificar($registo, new DespesaDecidida($this->instantaneo($registo->fresh())));
    }

    // Destinatários: quem criou (conta da app) + os emails de config, sem duplicar quando
    // o criador é um deles. Emails de config com conta ativa notificam a conta; os restantes
    // (ex.: financeiro@) vão por notificação "on demand".
    private function notificar(RegistoDespesa $registo, Notification $notificacao): void
    {
        $registo->loadMissing('colaborador');
        $criador = $registo->colaborador;
        $enviados = [];

        if ($criador && $criador->ativo && filled($criador->email)) {
            $criador->notify($notificacao);
            $enviados[] = strtolower($criador->email);
        }

        foreach (config('despesas.notificar', []) as $email) {
            if ($email === '' || in_array($email, $enviados, true)) {
                continue;
            }
            // Com conta na app → notifica a conta (email tratado pelo nome); sem conta → solto.
            $conta = User::whereRaw('lower(email) = ?', [$email])->where('ativo', true)->first();
            $conta ? $conta->notify($notificacao) : Notificador::route('mail', $email)->notify($notificacao);
            $enviados[] = $email;
        }
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
