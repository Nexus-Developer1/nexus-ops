<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\ServicoMfa;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    // Limite de tentativas de login antes de bloquear temporariamente (por email+IP).
    private const MAX_TENTATIVAS = 5;

    private const JANELA_SEGUNDOS = 60;

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $manter = false;

    public function autenticar(ServicoMfa $mfa)
    {
        $this->validate();

        // Rate limiting: trava brute-force de passwords e enumeração de contas. Contado por
        // email+IP; limpo ao autenticar com sucesso.
        $chave = $this->chaveThrottle();
        if (RateLimiter::tooManyAttempts($chave, self::MAX_TENTATIVAS)) {
            $segundos = RateLimiter::availableIn($chave);
            throw ValidationException::withMessages([
                'email' => "Demasiadas tentativas. Tente novamente dentro de {$segundos} segundos.",
            ]);
        }

        // Só utilizadores ativos podem entrar (ver secção 7 do CLAUDE.md). Email normalizado
        // (minúsculas) para o login ser case-insensitive — bate com o email guardado.
        $email = strtolower(trim($this->email));
        $user = User::query()->where('email', $email)->where('ativo', true)->first();

        // Verifica a password sem iniciar sessão: a autenticação só se completa depois do
        // código MFA (verificação em duas etapas). Mensagem genérica (não revela se o email existe).
        if (! $user || ! Hash::check($this->password, (string) $user->password)) {
            RateLimiter::hit($chave, self::JANELA_SEGUNDOS);
            throw ValidationException::withMessages([
                'email' => 'As credenciais não correspondem aos nossos registos.',
            ]);
        }

        RateLimiter::clear($chave);

        // Credenciais válidas → envia o código por email e guarda o estado pendente na sessão.
        // NÃO se faz login aqui; isso acontece em VerificarCodigo após o código certo.
        $mfa->enviar($user);

        session([
            'mfa.user_id' => $user->id,
            'mfa.remember' => $this->manter,
            'mfa.email' => $user->email,
        ]);

        return redirect()->route('mfa.verificar');
    }

    // Chave de rate limiting: por email submetido + IP (uniforme, não revela se a conta existe).
    private function chaveThrottle(): string
    {
        return 'login:'.strtolower(trim($this->email)).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
