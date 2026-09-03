<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// O campo Técnicos do evento é OBRIGATÓRIO (pedido da equipa): um evento sem ninguém atribuído
// não aparece na agenda de nenhum técnico, não gera convite nem alerta — passava despercebido.
class AgendaTecnicoObrigatorioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function modal()
    {
        return Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-05', '2026-09-05')
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-09-05T08:00')
            ->set('formFim', '2026-09-05T09:00');
    }

    public function test_sem_tecnico_nao_grava_e_avisa(): void
    {
        $this->modal()
            ->call('criarEvento')
            ->assertHasErrors('formTecnicoIds')
            ->assertSee('Escolha pelo menos um técnico para o evento.');

        $this->assertSame(0, EventoAgenda::count());
    }

    public function test_com_tecnico_grava(): void
    {
        $tecnico = $this->tecnicoDeTeste();

        $this->modal()
            ->set('formTecnicoIds', [$tecnico->id])
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame($tecnico->id, EventoAgenda::firstOrFail()->tecnico_id);
    }

    public function test_editar_um_evento_antigo_sem_tecnico_obriga_a_escolher(): void
    {
        $e = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Antigo', 'estado' => 'planeado',
            'inicio' => '2026-09-05 08:00', 'fim' => '2026-09-05 09:00']);

        $c = Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->set('formTitulo', 'Antigo (editado)')
            ->call('criarEvento')
            ->assertHasErrors('formTecnicoIds');

        $this->assertSame('Antigo', $e->fresh()->titulo); // não gravou

        $c->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();
        $this->assertSame('Antigo (editado)', $e->fresh()->titulo);
    }

    public function test_o_asterisco_marca_o_campo_no_modal(): void
    {
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-05', '2026-09-05')
            ->assertSeeHtml('Técnicos <span class="text-perigo-500">*</span>');
    }
}
