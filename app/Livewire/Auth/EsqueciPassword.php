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

        $status = Password::sendResetLink(['email' => $this->email]);

        // Se houver throttle, avisa; caso contrário mensagem neutra (não revela se
        // o email existe — evita enumeração de contas).
        if ($status === Password::RESET_THROTTLED) {
            $this->addError('email', 'Aguarde um momento antes de pedir um novo link.');

            return;
        }

        $this->estado = 'Se existir uma conta com esse email, enviámos um link para redefinir a palavra-passe.';
        $this->reset('email');
    }

    public function render()
    {
        return view('livewire.auth.esqueci-password');
    }
}
