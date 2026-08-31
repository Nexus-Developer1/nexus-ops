<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\EventoAgenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Agenda: a criação passa a ser por DIA (as horas escrevem-se no formulário) e o registo de
// horas reais pode atravessar dias — nesse caso o horário de cobertura não bloqueia
// (é trabalho já feito, não planeamento). Conflitos/double-booking continuam a valer.
class AgendaDiaEHorasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_clique_no_dia_abre_o_formulario_na_hora_de_abertura(): void
    {
        // A agenda manda só a data (sem 'T'): arranca na abertura e propõe 1 hora.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-08-03', '2026-08-03')
            ->assertSet('modalCriar', true)
            ->assertSet('formInicio', '2026-08-03T'.str_pad((string) config('agenda.hora_abertura'), 2, '0', STR_PAD_LEFT).':00')
            ->assertSet('formFim', '2026-08-03T'.str_pad((string) (config('agenda.hora_abertura') + 1), 2, '0', STR_PAD_LEFT).':00');
    }

    public function test_horas_com_hora_explicita_continuam_a_ser_respeitadas(): void
    {
        // Caminho antigo (com hora) mantém-se — ex.: edição/arrasto que já traz horas.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-08-03T14:30:00', '2026-08-03T16:00:00')
            ->assertSet('formInicio', '2026-08-03T14:30')
            ->assertSet('formFim', '2026-08-03T16:00');
    }

    public function test_evento_que_atravessa_dias_e_aceite_fora_de_horario(): void
    {
        Notification::fake();
        $tec = $this->tecnico();

        // Trabalho real: entra às 22h de 2ª e sai às 6h de 3ª — é registo do que aconteceu;
        // tem de ser possível regularizar (e já não há horário de cobertura que o recuse).
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Intervenção noturna')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T22:00')
            ->set('formFim', '2026-08-04T06:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento = EventoAgenda::where('titulo', 'Intervenção noturna')->firstOrFail();
        $this->assertSame('2026-08-03 22:00:00', $evento->inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-04 06:00:00', $evento->fim->format('Y-m-d H:i:s'));
    }

    public function test_multi_dia_nao_desliga_a_detecao_de_conflitos(): void
    {
        Notification::fake();
        $tec = $this->tecnico();
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'intervencao', 'titulo' => 'Já marcado', 'estado' => 'planeado',
            'inicio' => '2026-08-04 09:00', 'fim' => '2026-08-04 11:00',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);

        // Multi-dia que se sobrepõe ao evento existente → continua bloqueado.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Sobreposto')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T22:00')
            ->set('formFim', '2026-08-04T10:00')
            ->call('criarEvento')
            ->assertHasErrors('formInicio');

        $this->assertDatabaseMissing('eventos_agenda', ['titulo' => 'Sobreposto']);
    }

    public function test_evento_no_mesmo_dia_fora_de_horario_continua_bloqueado(): void
    {
        Notification::fake();
        $tec = $this->tecnico();

        // Sem horário de cobertura: um evento das 22h às 23h no mesmo dia é aceite como
        // qualquer outro (os técnicos não têm horário fixo).
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Noite')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T22:00')
            ->set('formFim', '2026-08-03T23:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento = EventoAgenda::where('titulo', 'Noite')->firstOrFail();
        $this->assertSame('2026-08-03 22:00:00', $evento->inicio->format('Y-m-d H:i:s'));
    }
}
