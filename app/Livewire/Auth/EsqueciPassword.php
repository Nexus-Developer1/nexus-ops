<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class EsqueciPassword extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public ?string $estado = null;

    // Envia o link de recuperação (job de email do broker nativo do Laravel).
    public function enviarLink(): void
    {
        $this->validate();

        // Normaliza o email (minúsculas) para encontrar a conta guardada em minúsculas.
        // Ignora-se o status devolvido (inclusive RESET_THROTTLED): mostrar uma mensagem
        // diferente no throttle revelava se a conta existe (só contas reais são "throttled"),
        // permitindo enumeração. Mensagem SEMPRE neutra, igual para email existente ou não.
        Password::sendResetLink(['email' => strtolower(trim($this->email))]);

        $this->estado = 'Se existir uma conta com esse email, enviámos um link para redefinir a palavra-passe.';
        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.auth.esqueci-password');
    }
}
