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
            'inicio' => Carbon::parse($dia . ' 10:00'), 'fim' => Carbon::parse($dia . ' 11:00'),
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
            ->assertReturned(fn ($r) => count($r) === 1 && $r[0]['title'] === 'Ev Rui');

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
}
