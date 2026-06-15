<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $manter = false;

    public function autenticar()
    {
        $this->validate();

        // Só utilizadores ativos podem entrar (ver secção 7 do CLAUDE.md).
        $credenciais = [
            'email' => $this->email,
            'password' => $this->password,
            'ativo' => true,
        ];

        if (! Auth::attempt($credenciais, $this->manter)) {
            throw ValidationException::withMessages([
                'email' => 'As credenciais não correspondem aos nossos registos.',
            ]);
        }

        session()->regenerate();

        // Cada papel aterra na sua área (CLAUDE.md §7).
        return redirect()->intended(route(Auth::user()->rotaInicial()));
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
