<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\DashboardGestao;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Quem foi convidado e ainda NÃO aceitou (sem palavra-passe definida) não pode ser escolhido
// para nada: nunca entrou na aplicação. Aparecia nas listas como se estivesse disponível
// — reportado pela equipa (o caso do Paulo Gouveia, set. 2026).
class ConvitePendenteNaoSelecionavelTest extends TestCase
{
    use RefreshDatabase;

    private User $aceite;

    private User $pendente;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['nome' => 'Admin Nexus', 'email' => 'suporte@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->aceite = User::create(['nome' => 'Rui Pereira', 'email' => 'r@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Convidado: a conta existe, mas a pessoa nunca definiu a palavra-passe.
        $this->pendente = User::create(['nome' => 'Paulo Gouveia', 'email' => 'p@nexus.pt',
            'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $this->pendente->forceFill(['password' => null])->save();
    }

    public function test_o_ambito_deixa_de_fora_quem_nao_aceitou(): void
    {
        $this->assertTrue($this->pendente->convitePendente());
        $this->assertFalse($this->aceite->convitePendente());

        $this->assertSame(['Rui Pereira'], User::selecionavel()->pluck('nome')->all());
    }

    public function test_nao_aparece_nas_caixas_do_evento_nem_pode_ser_gravado(): void
    {
        $c = Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-10', '2026-09-10');

        $c->assertSee('Rui Pereira')->assertDontSee('Paulo Gouveia');

        // E se alguém forçar o id na submissão, a validação recusa.
        $c->set('formTitulo', 'Visita')
            ->set('formTecnicoIds', [$this->pendente->id])
            ->set('formInicio', '2026-09-10T08:00')
            ->set('formFim', '2026-09-10T09:00')
            ->call('criarEvento')
            ->assertHasErrors('formTecnicoIds.0');

        $this->assertSame(0, EventoAgenda::count());
    }

    public function test_nao_aparece_no_filtro_do_dashboard_nem_na_legenda(): void
    {
        Livewire::actingAs($this->admin)->test(DashboardGestao::class)
            ->assertViewHas('tecnicosAgenda', function ($lista) {
                $nomes = collect($lista)->pluck('nome')->all();

                return in_array('Rui Pereira', $nomes, true) && ! in_array('Paulo Gouveia', $nomes, true);
            });

        $this->assertSame(['Rui Pereira'], collect(app(FonteCalendario::class)->legenda())->pluck('nome')->all());
    }

    public function test_quando_aceita_o_convite_passa_a_estar_disponivel(): void
    {
        $this->pendente->forceFill(['password' => bcrypt('escolhida-pela-pessoa')])->save();

        $this->assertFalse($this->pendente->fresh()->convitePendente());
        $this->assertEqualsCanonicalizing(
            ['Rui Pereira', 'Paulo Gouveia'],
            User::selecionavel()->pluck('nome')->all()
        );

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-10', '2026-09-10')
            ->assertSee('Paulo Gouveia');
    }
}
