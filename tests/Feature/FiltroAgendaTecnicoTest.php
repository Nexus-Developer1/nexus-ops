<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Filtro da agenda por técnico: como os eventos são marcados por tecnico_nome (texto livre),
// o filtro é pelo NOME. Filtrar por um técnico mostra os eventos DESSE técnico.
class FiltroAgendaTecnicoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function evento(string $titulo, string $nome, string $dia): EventoAgenda
    {
        return EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => $titulo, 'estado' => 'planeado',
            'inicio' => Carbon::parse($dia.' 10:00'), 'fim' => Carbon::parse($dia.' 11:00'),
            'tecnico_nome' => $nome,
        ]);
    }

    public function test_filtrar_por_nome_mostra_so_os_eventos_desse_tecnico(): void
    {
        $admin = $this->admin();
        $this->evento('Ev Rui', 'Rui Moreira', '2026-07-02');
        $this->evento('Ev Ana', 'Ana Silva', '2026-07-03');

        [$de, $ate] = ['2026-07-01', '2026-07-08'];

        // Sem filtro → vê os dois.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('eventos', $de, $ate)
            ->assertReturned(fn ($r) => count($r) === 2);

        // Filtrar por "Rui Moreira" → só o dele (o bug: antes, filtrar por conta dava 0).
        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('tecnicoNome', 'Rui Moreira')
            ->call('eventos', $de, $ate)
            ->assertReturned(fn ($r) => count($r) === 1 && $r[0]['title'] === 'Ev Rui · Rui Moreira'); // tipo · técnicos

        // Filtrar por um nome sem eventos → nenhum.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('tecnicoNome', 'Zé Ninguém')
            ->call('eventos', $de, $ate)
            ->assertReturned(fn ($r) => count($r) === 0);
    }

    public function test_dropdown_do_filtro_lista_os_nomes_usados_nos_eventos(): void
    {
        $admin = $this->admin();
        $this->evento('Ev Rui', 'Rui Moreira', '2026-07-02');

        Livewire::actingAs($admin)->test(Calendario::class)
            ->assertViewHas('nomesTecnicos', fn ($lista) => collect($lista)->pluck('nome')->contains('Rui Moreira'))
            ->assertSee('Rui Moreira');
    }

    // A legenda (e o filtro) mostram a equipa TODA: contas de técnico ativas mesmo sem eventos,
    // e quem só entra em eventos como técnico adicional — cada um com a sua cor. O filtro por
    // esse nome inclui os eventos em que é adicional. Contas inativas sem eventos não aparecem.
    public function test_legenda_inclui_todas_as_contas_de_tecnico_e_os_adicionais(): void
    {
        $admin = $this->admin();
        $mk = fn (string $nome, string $email, bool $ativo = true) => User::create(['nome' => $nome, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => $ativo]);
        $mk('Daniel Ribeiro', 'd@nexus.pt');                 // conta sem eventos
        $paulo = $mk('Paulo Bento', 'p@nexus.pt');            // só adicional
        $mk('Antigo Saído', 'x@nexus.pt', ativo: false);      // inativa, sem eventos → fora
        $ev = $this->evento('Ev Rui', 'Rui Moreira', '2026-07-02'); // legado, só nome
        $ev->tecnicosAdicionais()->sync([$paulo->id]);

        $c = Livewire::actingAs($admin)->test(Calendario::class)
            ->assertViewHas('nomesTecnicos', function ($lista) {
                $nomes = collect($lista)->pluck('nome')->all();
                $cores = collect($lista)->pluck('cor')->unique();

                return $nomes === ['Daniel Ribeiro', 'Paulo Bento', 'Rui Moreira'] && $cores->count() === 3;
            });

        // Filtrar pelo Paulo (só adicional) traz o evento em que acompanha o Rui.
        $c->set('tecnicoNome', 'Paulo Bento')
            ->call('eventos', '2026-07-01', '2026-07-08')
            ->assertReturned(fn ($r) => count($r) === 1 && str_starts_with($r[0]['title'], 'Ev Rui · Rui Moreira'));
    }
}
