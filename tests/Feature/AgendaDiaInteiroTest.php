<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// "Dia inteiro": férias e ausências ocupam CADA dia do período das 00:00 às 23:59 — não 08h–19h.
// Liga-se sozinho ao escrever "Férias" no tipo de evento; pode marcar-se à mão; mudar as datas
// com a opção ligada mantém os dias cheios; ao reabrir um evento de dia inteiro a opção vem ligada.
class AgendaDiaInteiroTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tec;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->tec = User::create(['nome' => 'Paulo Bento', 'email' => 'p@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_escrever_ferias_liga_dia_inteiro_e_enche_todos_os_dias(): void
    {
        $c = Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formTecnicoIds', [$this->tec->id])
            ->set('formInicio', '2026-09-07T09:00')
            ->set('formFim', '2026-09-11T18:00')
            ->set('formTitulo', 'Férias');

        // A opção ligou-se sozinha e normalizou tudo: 00:00 do 1.º dia → 23:59 do último, 5 dias cheios.
        $c->assertSet('formDiaInteiro', true)
            ->assertSet('formInicio', '2026-09-07T00:00')
            ->assertSet('formFim', '2026-09-11T23:59');
        $this->assertCount(5, $c->get('formHorasDias'));
        foreach ($c->get('formHorasDias') as $linha) {
            $this->assertSame('00:00', $linha['inicio']);
            $this->assertSame('23:59', $linha['fim']);
        }

        $c->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Férias')->firstOrFail();
        $this->assertSame('2026-09-07 00:00:00', $e->inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-11 23:59:00', $e->fim->format('Y-m-d H:i:s'));
        $this->assertCount(5, $e->segmentos());
        $this->assertSame('2026-09-09 00:00', $e->segmentos()[2][0]->format('Y-m-d H:i'));
        $this->assertSame('2026-09-09 23:59', $e->segmentos()[2][1]->format('Y-m-d H:i'));
    }

    public function test_ferias_com_acento_ou_maiusculas_tambem_liga(): void
    {
        foreach (['FÉRIAS', 'ferias', 'Férias de verão', 'Feria'] as $titulo) {
            $c = Livewire::actingAs($this->admin)->test(Calendario::class)
                ->call('abrirCriacao', '2026-09-07', '2026-09-07')
                ->set('formTitulo', $titulo);
            $this->assertTrue($c->get('formDiaInteiro'), $titulo);
        }

        // Outro tipo qualquer não liga.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formTitulo', 'Reunião')
            ->assertSet('formDiaInteiro', false);
    }

    public function test_marcar_a_mao_para_outro_tipo_e_mudar_datas_mantem_os_dias_cheios(): void
    {
        $c = Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formTitulo', 'Formação')
            ->set('formTecnicoIds', [$this->tec->id])
            ->set('formDiaInteiro', true)
            ->assertSet('formInicio', '2026-09-07T00:00')
            ->assertSet('formFim', '2026-09-07T23:59');

        // Esticar o fim com a opção ligada: os dias novos vêm cheios, não 08h–19h.
        $c->set('formFim', '2026-09-09T12:00')
            ->assertSet('formFim', '2026-09-09T23:59');
        $this->assertCount(3, $c->get('formHorasDias'));
        $this->assertSame(['00:00', '23:59'], [$c->get('formHorasDias')[2]['inicio'], $c->get('formHorasDias')[2]['fim']]);

        // Desligar volta às horas propostas (08h–19h) nos dias.
        $c->set('formDiaInteiro', false);
        $this->assertSame(['08:00', '19:00'], [$c->get('formHorasDias')[1]['inicio'], $c->get('formHorasDias')[1]['fim']]);
    }

    public function test_reabrir_evento_de_dia_inteiro_traz_a_opcao_ligada(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formTecnicoIds', [$this->tec->id])
            ->set('formTitulo', 'Férias')
            ->set('formFim', '2026-09-08T00:00')
            ->call('criarEvento')->assertHasNoErrors();
        $e = EventoAgenda::where('titulo', 'Férias')->firstOrFail();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)
            ->call('abrirEdicao')
            ->assertSet('formDiaInteiro', true)
            ->assertSet('formInicio', '2026-09-07T00:00')
            ->assertSet('formFim', '2026-09-08T23:59');

        // Um evento normal reabre com a opção desligada.
        $normal = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-09-10 09:00'), 'fim' => Carbon::parse('2026-09-10 10:00')]);
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $normal->id)
            ->call('abrirEdicao')
            ->assertSet('formDiaInteiro', false);
    }

    public function test_ferias_bloqueiam_outro_evento_do_tecnico_nesses_dias(): void
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-07', '2026-09-07')
            ->set('formTecnicoIds', [$this->tec->id])
            ->set('formTitulo', 'Férias')
            ->set('formFim', '2026-09-11T00:00')
            ->call('criarEvento')->assertHasNoErrors();

        // O técnico está de férias na quarta: uma visita nesse dia é conflito.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-09', '2026-09-09')
            ->set('formTitulo', 'Visita')
            ->set('formTecnicoIds', [$this->tec->id])
            ->set('formInicio', '2026-09-09T10:00')
            ->set('formFim', '2026-09-09T11:00')
            ->call('criarEvento')
            ->assertHasErrors('formInicio');
    }
}
