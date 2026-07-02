<?php

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraPassword;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Aceitar convite: o utilizador define a sua palavra-passe. Reutiliza o mecanismo de reset via
// o broker 'invites' (validade 3 dias, uso único — o token é apagado ao concluir).
#[Layout('components.layouts.guest')]
class AceitarConvite extends Component
{
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function definir()
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', RegraPassword::min(8)],
        ]);

        $status = Password::broker('invites')->reset(
            [
                // Email normalizado para bater com a conta (guardada em minúsculas).
                'email' => strtolower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user) {
                // O cast 'password' => 'hashed' trata do hashing. O broker apaga o token (uso único).
                $user->forceFill([
                    'password' => $this->password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('sucesso', 'Palavra-passe definida com sucesso. Já pode entrar.');

            return redirect()->route('login');
        }

        $mensagens = [
            Password::INVALID_TOKEN => 'O link de convite é inválido ou expirou. Peça um novo convite.',
            Password::INVALID_USER => 'Não encontrámos uma conta com esse email.',
        ];

        $this->addError('email', $mensagens[$status] ?? 'Não foi possível definir a palavra-passe.');
    }

    public function render()
    {
        return view('livewire.auth.aceitar-convite');
    }
}
