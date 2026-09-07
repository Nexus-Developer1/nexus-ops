<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\EventoAlerta;
use App\Models\User;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Alertas programados num evento (data + texto à escolha): gerem-se no modal do evento e entram
// no painel de alertas / email diário pelo ServicoAlertas — SÓ a partir do dia
// programado (sem antecipação), sempre alta; cancelar o evento cala-os; apagar o evento apaga-os (cascade).
class AgendaAlertasEventoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function criarComAlerta(string $dataAlerta, string $texto = 'Confirmar acesso com o cliente'): EventoAgenda
    {
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-10', '2026-09-10')
            ->set('formTitulo', 'Serviço')
            ->set('formInicio', '2026-09-10T09:00')->set('formFim', '2026-09-10T11:00')
            ->call('adicionarAlerta')
            ->set('formAlertas.0.data', $dataAlerta)
            ->set('formAlertas.0.texto', $texto)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])->call('criarEvento')->assertHasNoErrors();

        return EventoAgenda::where('titulo', 'Serviço')->firstOrFail();
    }

    public function test_grava_reabre_edita_e_valida_as_linhas(): void
    {
        $e = $this->criarComAlerta('2026-09-08');
        $this->assertSame([['2026-09-08', 'Confirmar acesso com o cliente']],
            $e->alertas()->get()->map(fn ($a) => [$a->data->toDateString(), $a->texto])->all());

        // Reabrir traz as linhas; tirar a linha apaga o alerta.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->assertSet('formAlertas.0.texto', 'Confirmar acesso com o cliente')
            ->call('removerAlerta', 0)
            ->call('criarEvento')->assertHasNoErrors();
        $this->assertSame(0, $e->alertas()->count());

        // Linha sem texto ou sem data é recusada.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('selecionar', $e->id)->call('abrirEdicao')
            ->call('adicionarAlerta')
            ->set('formAlertas.0.data', '2026-09-08')
            ->set('formAlertas.0.texto', '')
            ->call('criarEvento')->assertHasErrors('formAlertas.0.texto');
    }

    public function test_dispara_so_no_dia_programado_com_o_texto_escolhido(): void
    {
        $this->criarComAlerta('2026-09-05', 'Levar baterias novas'); // hoje é 01/09 → ainda nada

        // Antes do dia: não aparece (sem antecipação — o dia foi escolhido à mão).
        $this->assertNull(app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'evento_programado'));

        // No dia: aparece, alta, com o texto escolhido.
        Carbon::setTestNow('2026-09-05 08:00:00');
        $alertas = app(ServicoAlertas::class)->recolher()->where('tipo', 'evento_programado')->values();
        $this->assertCount(1, $alertas);
        $this->assertSame('alta', $alertas[0]['severidade']);
        $this->assertSame('Levar baterias novas · Serviço', $alertas[0]['titulo']);
        $this->assertStringContainsString('evento a 10 set 2026', $alertas[0]['descricao']);
    }

    public function test_evento_cancelado_cala_e_apagado_apaga(): void
    {
        $e = $this->criarComAlerta('2026-09-01'); // hoje — o alerta está a disparar
        $this->assertNotNull(app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'evento_programado'));

        $e->update(['estado' => 'cancelado']);
        $this->assertNull(app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'evento_programado'));

        $e->update(['estado' => 'planeado']);
        $e->forceDelete();
        $this->assertSame(0, EventoAlerta::count());
    }
}
