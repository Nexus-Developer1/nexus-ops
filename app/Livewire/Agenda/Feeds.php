<?php

namespace App\Livewire\Agenda;

use App\Enums\PapelUtilizador;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\User;
use App\Services\Auditor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Feeds da agenda para o Outlook — gestão dos URLs de subscrição (Parte C). SÓ ADMIN (Gate
// 'gerir-feeds-agenda', verificada em mount E render: cobre também as ações Livewire). Secção
// separada da agenda operacional. Lista os utilizadores da equipa com o URL completo do feed,
// gerar / regenerar / revogar token — revogar invalida o URL antigo de imediato (o endpoint
// valida o token contra a BD). Cada emissão/revogação fica na auditoria.
//
// Quem recebe convites (técnicos) TAMBÉM pode ter feed: o feed de cada pessoa exclui os eventos
// em que ela é convidada (ver GeradorIcs), por isso não vê nada a dobrar.
#[Layout('components.layouts.app', ['ativo' => 'feeds', 'titulo' => 'Feeds da agenda'])]
class Feeds extends Component
{
    use ApenasEquipa;

    public function mount(): void
    {
        abort_unless(Gate::allows('gerir-feeds-agenda'), 403);
    }

    public function gerar(int $userId): void
    {
        abort_unless(Gate::allows('gerir-feeds-agenda'), 403);

        $user = User::whereKey($userId)->where('ativo', true)->firstOrFail();
        $tinha = $user->agenda_feed_token !== null;

        // 48 caracteres alfanuméricos (~285 bits): impossível de adivinhar; o URL é o segredo.
        $user->forceFill(['agenda_feed_token' => Str::random(48)])->save();

        Auditor::registar($tinha ? 'agenda.feed_regenerado' : 'agenda.feed_gerado', $user);
        session()->flash('sucesso', ($tinha ? 'Token regenerado' : 'Feed criado').' para '.$user->nome.'. '.($tinha ? 'O URL antigo deixou de funcionar.' : 'Copie o URL e subscreva-o no Outlook.'));
    }

    public function revogar(int $userId): void
    {
        abort_unless(Gate::allows('gerir-feeds-agenda'), 403);

        $user = User::findOrFail($userId);
        $user->forceFill(['agenda_feed_token' => null])->save();

        Auditor::registar('agenda.feed_revogado', $user);
        session()->flash('sucesso', 'Feed de '.$user->nome.' revogado — o URL deixou de funcionar.');
    }

    public function render()
    {
        abort_unless(Gate::allows('gerir-feeds-agenda'), 403);

        $utilizadores = User::query()
            ->whereIn('papel', [PapelUtilizador::Admin, PapelUtilizador::Tecnico])
            ->orderByDesc('ativo')
            ->orderBy('nome')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->nome,
                'email' => $u->email,
                'papel' => $u->papel->value,
                'ativo' => (bool) $u->ativo,
                'tem_feed' => $u->agenda_feed_token !== null,
                'url' => $u->agenda_feed_token ? route('agenda.feed', ['token' => $u->agenda_feed_token]) : null,
            ]);

        return view('livewire.agenda.feeds', ['utilizadores' => $utilizadores]);
    }
}
