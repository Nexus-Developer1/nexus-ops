<?php

namespace App\Livewire\Utilizadores;

use App\Enums\PapelUtilizador;
use App\Models\User;
use App\Notifications\ConviteDefinirPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Convidar um novo utilizador (TÉCNICO). Nasce SEM password; recebe um email de convite com um
// link seguro para a definir. Só o admin acede (Gate 'gerir-utilizadores' + abort_unless).
#[Layout('components.layouts.app', ['ativo' => 'utilizadores', 'titulo' => 'Utilizadores'])]
class Adicionar extends Component
{
    public string $nome = '';
    public string $email = '';

    public function mount(): void
    {
        abort_unless(Gate::allows('gerir-utilizadores'), 403);
    }

    public function convidar()
    {
        abort_unless(Gate::allows('gerir-utilizadores'), 403);

        // Normaliza ANTES de validar → o 'unique' apanha duplicados com outra capitalização
        // (ex.: Suporte@ vs suporte@), já que os emails são guardados em minúsculas.
        $this->email = strtolower(trim($this->email));

        $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            // Único: bloqueia criar um segundo utilizador com o mesmo email.
            'email' => ['required', 'email', 'max:255', 'unique:utilizadores,email'],
        ], attributes: ['nome' => 'nome', 'email' => 'email']);

        // Técnico, ativo, SEM password (chave omitida → NULL → login impossível até aceitar).
        // O email é normalizado pelo mutator do modelo (minúsculas).
        $user = User::create([
            'nome' => trim($this->nome),
            'email' => $this->email,
            'papel' => PapelUtilizador::Tecnico,
            'ativo' => true,
        ]);

        // Token de convite (broker 'invites': imprevisível, guardado hasheado, validade 3 dias,
        // uso único) + email de convite próprio.
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new ConviteDefinirPassword($token));

        session()->flash('sucesso', "Convite enviado para {$user->email}.");

        return redirect()->route('utilizadores.adicionar');
    }

    // Reenvia o convite a um técnico que ainda não o aceitou (ex.: email perdido). Gera um token
    // novo (o anterior fica sem efeito) e reenvia o email. Só admin; só para convites pendentes.
    public function reenviar(int $id): void
    {
        abort_unless(Gate::allows('gerir-utilizadores'), 403);

        $user = User::where('papel', PapelUtilizador::Tecnico)->findOrFail($id);

        // Guarda de negócio: só reenviar a quem ainda não definiu password (convite pendente).
        if ($user->password !== null) {
            session()->flash('sucesso', "{$user->email} já aceitou o convite.");

            return;
        }

        $token = Password::broker('invites')->createToken($user);
        $user->notify(new ConviteDefinirPassword($token));

        session()->flash('sucesso', "Convite reenviado para {$user->email}.");
    }

    public function render()
    {
        // Lista de técnicos já no site: convite pendente (password NULL, ainda não aceitaram) vs
        // aceite (password definida). Pendentes primeiro, depois por nome. Só o admin chega aqui
        // (mount aborta 403 para os restantes), por isso a lista é intrinsecamente só-admin.
        $tecnicos = User::where('papel', PapelUtilizador::Tecnico)
            ->orderByRaw('(password is null) desc') // pendentes no topo (acionáveis)
            ->orderBy('nome')
            ->get(['id', 'nome', 'email', 'password']);

        return view('livewire.utilizadores.adicionar', [
            'tecnicos' => $tecnicos,
        ]);
    }
}
