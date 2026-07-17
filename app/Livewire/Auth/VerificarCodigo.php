<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\ResultadoMfa;
use App\Services\Auth\ServicoMfa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

// Segunda etapa do login: o utilizador introduz o código de 6 dígitos enviado para o
// email. Só aqui se completa a autenticação. Ver secção 7 do CLAUDE.md.
#[Layout('components.layouts.guest')]
class VerificarCodigo extends Component
{
    #[Validate('required|digits:6')]
    public string $codigo = '';

    // Só acessível a meio de um login: exige o estado MFA pendente na sessão.
    public function mount(): void
    {
        if (! session('mfa.user_id')) {
            $this->redirect(route('login'), navigate: false);
        }
    }

    public function verificar(ServicoMfa $mfa)
    {
        $this->validate();

        $user = $this->utilizadorPendente();
        if (! $user) {
            return $this->redirect(route('login'), navigate: false);
        }

        $resultado = $mfa->validar($user, $this->codigo);

        if ($resultado !== ResultadoMfa::Ok) {
            throw ValidationException::withMessages([
                'codigo' => match ($resultado) {
                    ResultadoMfa::Expirado => 'O código expirou. Peça um novo código.',
                    ResultadoMfa::DemasiadasTentativas => 'Demasiadas tentativas. Peça um novo código.',
                    default => 'Código incorreto.',
                },
            ]);
        }

        // Sucesso: completa a autenticação, respeitando o "manter sessão" da 1.ª etapa.
        $remember = (bool) session('mfa.remember', false);
        Auth::login($user, $remember);

        session()->forget(['mfa.user_id', 'mfa.remember', 'mfa.email']);
        session()->regenerate();

        // Cada papel aterra na sua área (CLAUDE.md §7).
        return redirect()->intended(route($user->rotaInicial()));
    }

    public function reenviar(ServicoMfa $mfa): void
    {
        $user = $this->utilizadorPendente();
        if (! $user) {
            $this->redirect(route('login'), navigate: false);

            return;
        }

        if ($faltam = $mfa->segundosAteReenvio($user)) {
            $this->addError('codigo', "Aguarde {$faltam}s antes de pedir um novo código.");

            return;
        }

        $mfa->enviar($user);
        $this->reset('codigo');
        session()->flash('reenviado', 'Enviámos um novo código para o seu email.');
    }

    // Email mascarado para mostrar na página (ex.: su•••@nxs.pt), sem expor o endereço todo.
    #[Computed]
    public function emailMascarado(): string
    {
        $email = (string) session('mfa.email', '');
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$conta, $dominio] = explode('@', $email, 2);
        $visivel = mb_substr($conta, 0, 2);

        return $visivel.str_repeat('•', max(1, mb_strlen($conta) - 2)).'@'.$dominio;
    }

    protected function utilizadorPendente(): ?User
    {
        $id = session('mfa.user_id');

        // Exige `ativo` também nesta etapa: se a conta for desativada durante a janela do MFA
        // (entre o envio do código e a sua introdução), o login não se completa — coerente com
        // a 1.ª etapa (Login), que já filtra por ativo.
        return $id ? User::where('ativo', true)->find($id) : null;
    }

    public function render()
    {
        return view('livewire.auth.verificar-codigo');
    }
}
