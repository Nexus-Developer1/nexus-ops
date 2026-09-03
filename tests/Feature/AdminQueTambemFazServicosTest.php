<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Relatorios\Novo;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Quem administra E também vai a serviços (utilizadores.faz_servicos): mantém as permissões de
// administrador e passa a aparecer como técnico — antes o papel decidia as duas coisas e essa
// pessoa não podia sequer ser marcada num evento.
class AdminQueTambemFazServicosTest extends TestCase
{
    use RefreshDatabase;

    private User $julio;      // admin que também faz serviços

    private User $suporte;    // admin de secretária

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->julio = User::create(['nome' => 'Julio Santos', 'email' => 'j@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'faz_servicos' => true, 'ativo' => true]);
        $this->suporte = User::create(['nome' => 'Admin Nexus', 'email' => 's@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->tecnico = User::create(['nome' => 'Rui Pereira', 'email' => 'r@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_o_ambito_apanha_tecnicos_e_admins_que_fazem_servicos(): void
    {
        $nomes = User::fazServicos()->orderBy('nome')->pluck('nome')->all();

        $this->assertSame(['Julio Santos', 'Rui Pereira'], $nomes);
        $this->assertTrue($this->julio->faz_servicos);
        $this->assertFalse((bool) $this->suporte->faz_servicos);
    }

    public function test_aparece_nas_caixas_de_tecnicos_do_evento_e_pode_ser_gravado(): void
    {
        Livewire::actingAs($this->suporte)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-10', '2026-09-10')
            ->assertSee('Julio Santos')      // passa a ser opção
            ->assertSee('Rui Pereira')
            ->assertDontSee('Admin Nexus')   // admin de secretária continua de fora
            ->set('formTitulo', 'Visita')
            ->set('formTecnicoIds', [$this->julio->id])
            ->set('formInicio', '2026-09-10T08:00')
            ->set('formFim', '2026-09-10T09:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame($this->julio->id, EventoAgenda::firstOrFail()->tecnico_id);
    }

    public function test_admin_de_secretaria_continua_a_ser_recusado_no_evento(): void
    {
        Livewire::actingAs($this->suporte)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-10', '2026-09-10')
            ->set('formTitulo', 'Visita')
            ->set('formTecnicoIds', [$this->suporte->id])
            ->set('formInicio', '2026-09-10T08:00')
            ->set('formFim', '2026-09-10T09:00')
            ->call('criarEvento')
            ->assertHasErrors('formTecnicoIds.0');

        $this->assertSame(0, EventoAgenda::count());
    }

    public function test_aparece_na_legenda_com_cor_propria_e_no_relatorio(): void
    {
        $legenda = collect(app(FonteCalendario::class)->legenda());

        $this->assertSame(['Julio Santos', 'Rui Pereira'], $legenda->pluck('nome')->all());
        $this->assertCount(2, $legenda->pluck('cor')->unique());

        // E é escolhível como técnico de um relatório.
        Livewire::actingAs($this->suporte)->test(Novo::class)->assertSee('Julio Santos');
    }
}
