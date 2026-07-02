<?php

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as RegraPassword;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class RedefinirPassword extends Component
{
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        // O email vem como query string no link enviado por email.
        $this->email = (string) request()->query('email', '');
    }

    public function redefinir()
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', RegraPassword::min(8)],
        ]);

        $status = Password::reset(
            [
                // Email normalizado para bater com a conta (guardada em minúsculas).
                'email' => strtolower(trim($this->email)),
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function ($user) {
                // O cast 'password' => 'hashed' no modelo trata do hashing.
                $user->forceFill([
                    'password' => $this->password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            session()->flash('sucesso', 'Palavra-passe redefinida com sucesso. Já pode entrar.');

            return redirect()->route('login');
        }

        // Mensagens de erro em PT (token inválido/expirado, conta inexistente).
        $mensagens = [
            Password::INVALID_TOKEN => 'O link de redefinição é inválido ou expirou. Peça um novo.',
            Password::INVALID_USER => 'Não encontrámos uma conta com esse email.',
        ];

        $this->addError('email', $mensagens[$status] ?? 'Não foi possível redefinir a palavra-passe.');
    }

    public function render()
    {
        return view('livewire.auth.redefinir-password');
    }
}
