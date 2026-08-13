<?php

namespace App\Services\Auth;

use App\Models\CodigoMfa;
use App\Models\User;
use App\Notifications\CodigoMfaNotification;
use Illuminate\Support\Facades\Hash;

// Orquestra a verificação em duas etapas (MFA por email): gera o código, guarda-o
// em hash, envia-o e valida-o. Centralizado aqui para o login e o reenvio partilharem
// exatamente a mesma lógica. Ver secção 7 do CLAUDE.md.
class ServicoMfa
{
    // Validade do código, nº máximo de tentativas e intervalo mínimo entre reenvios.
    public const EXPIRA_MINUTOS = 10;

    public const MAX_TENTATIVAS = 5;

    public const REENVIO_COOLDOWN_SEG = 60;

    // Emite um novo código para o utilizador: invalida os anteriores ainda vivos,
    // cria um código de 6 dígitos (só o hash é persistido) e envia-o por email.
    public function enviar(User $user): void
    {
        // Um utilizador só tem um código válido de cada vez.
        CodigoMfa::query()->where('user_id', $user->id)->whereNull('usado_em')->delete();

        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CodigoMfa::create([
            'user_id' => $user->id,
            'codigo_hash' => Hash::make($codigo),
            'expira_em' => now()->addMinutes(self::EXPIRA_MINUTOS),
        ]);

        // Envio síncrono (não em fila): o código tem de chegar de imediato ao login.
        $user->notify(new CodigoMfaNotification($codigo, self::EXPIRA_MINUTOS));
    }

    // Verifica o código introduzido. Devolve true e queima o código em caso de sucesso.
    // Conta cada tentativa; ao exceder o limite, queima o código (obriga a novo envio).
    // Lança-se ValidationException a partir do componente com a mensagem adequada — aqui
    // devolve-se um resultado simples para o componente decidir a mensagem.
    public function validar(User $user, string $codigo): ResultadoMfa
    {
        $registo = CodigoMfa::query()->where('user_id', $user->id)->vivo()->latest('id')->first();

        if (! $registo) {
            return ResultadoMfa::Expirado;
        }

        $registo->increment('tentativas');

        if ($registo->tentativas > self::MAX_TENTATIVAS) {
            $registo->update(['usado_em' => now()]); // queimado

            return ResultadoMfa::DemasiadasTentativas;
        }

        if (! Hash::check($codigo, $registo->codigo_hash)) {
            return ResultadoMfa::Incorreto;
        }

        $registo->update(['usado_em' => now()]);

        return ResultadoMfa::Ok;
    }

    // Segundos que faltam até se poder reenviar; null se já se pode reenviar.
    public function segundosAteReenvio(User $user): ?int
    {
        $ultimo = CodigoMfa::query()->where('user_id', $user->id)->latest('id')->first();

        if (! $ultimo) {
            return null;
        }

        $decorrido = (int) $ultimo->created_at->diffInSeconds(now());

        return $decorrido < self::REENVIO_COOLDOWN_SEG
            ? self::REENVIO_COOLDOWN_SEG - $decorrido
            : null;
    }
}
