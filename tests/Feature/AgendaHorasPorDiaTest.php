<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\EventoAgenda;
use App\Models\User;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Serviços que duram VÁRIOS dias: cada dia tem as suas horas trabalhadas, editáveis a
// qualquer momento no formulário do evento. O calendário mostra um bloco por dia com as
// horas reais e os conflitos comparam o trabalho efetivo (as noites pelo meio ficam livres).
class AgendaHorasPorDiaTest extends TestCase
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

    public function test_multi_dia_grava_horas_por_dia_e_deriva_inicio_e_fim(): void
    {
        $tec = $this->tecnico();

        // 3 dias de serviço; o 2.º dia teve um horário diferente dos restantes.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Instalação grande')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T09:00')
            ->set('formFim', '2026-08-05T17:00')
            ->set('formHorasDias.1.inicio', '10:30')
            ->set('formHorasDias.1.fim', '15:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento = EventoAgenda::where('titulo', 'Instalação grande')->firstOrFail();

        // Uma linha por dia, com as horas do 2.º dia editadas.
        $this->assertEquals([
            ['dia' => '2026-08-03', 'inicio' => '09:00', 'fim' => '17:00'],
            ['dia' => '2026-08-04', 'inicio' => '10:30', 'fim' => '15:00'],
            ['dia' => '2026-08-05', 'inicio' => '09:00', 'fim' => '17:00'],
        ], $evento->horas_dias);

        // Início/fim do evento derivam do 1.º e do último dia.
        $this->assertSame('2026-08-03 09:00:00', $evento->inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-05 17:00:00', $evento->fim->format('Y-m-d H:i:s'));
    }

    public function test_editar_um_dia_depois_de_criado_atualiza_as_horas_desse_dia(): void
    {
        $tec = $this->tecnico();
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Serviço longo', 'estado' => 'planeado',
            'inicio' => '2026-08-03 09:00', 'fim' => '2026-08-04 17:00',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome,
            'horas_dias' => [
                ['dia' => '2026-08-03', 'inicio' => '09:00', 'fim' => '17:00'],
                ['dia' => '2026-08-04', 'inicio' => '09:00', 'fim' => '17:00'],
            ],
        ]);

        // No fim do 2.º dia, o técnico acerta as horas realmente trabalhadas nesse dia.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('abrirEdicao')
            ->assertSet('formHorasDias.1.inicio', '09:00') // pré-preenchido do gravado
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formHorasDias.1.inicio', '08:00')
            ->set('formHorasDias.1.fim', '12:30')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento->refresh();
        $this->assertEquals(['dia' => '2026-08-04', 'inicio' => '08:00', 'fim' => '12:30'], $evento->horas_dias[1]);
        $this->assertSame('2026-08-04 12:30:00', $evento->fim->format('Y-m-d H:i:s')); // fim segue o último dia
    }

    public function test_calendario_mostra_um_bloco_por_dia_com_o_id_do_evento(): void
    {
        $tec = $this->tecnico();
        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Serviço longo', 'estado' => 'planeado',
            'inicio' => '2026-08-03 09:00', 'fim' => '2026-08-04 15:00',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome,
            'horas_dias' => [
                ['dia' => '2026-08-03', 'inicio' => '09:00', 'fim' => '17:00'],
                ['dia' => '2026-08-04', 'inicio' => '10:00', 'fim' => '15:00'],
            ],
        ]);

        $blocos = app(FonteCalendario::class)->eventos(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertCount(2, $blocos);
        $this->assertSame('2026-08-03T09:00:00', $blocos[0]['start']);
        $this->assertSame('2026-08-03T17:00:00', $blocos[0]['end']);
        $this->assertSame('2026-08-04T10:00:00', $blocos[1]['start']);
        $this->assertSame('2026-08-04T15:00:00', $blocos[1]['end']);
        // Segmentos não são arrastáveis e o clique resolve para o MESMO evento.
        $this->assertFalse($blocos[0]['editable']);
        $this->assertSame($evento->id, $blocos[0]['extendedProps']['evento_id']);
        $this->assertSame($evento->id, $blocos[1]['extendedProps']['evento_id']);

        // Evento de um só dia continua a ser um bloco normal (arrastável, id simples).
        EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => '2026-08-05 10:00', 'fim' => '2026-08-05 11:00',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome,
        ]);
        $blocos = app(FonteCalendario::class)->eventos(Carbon::parse('2026-08-05'), Carbon::parse('2026-08-06'));
        $this->assertCount(1, $blocos);
        $this->assertArrayNotHasKey('editable', $blocos[0]);
    }

    public function test_conflitos_respeitam_as_horas_de_cada_dia_e_nao_o_bloco_continuo(): void
    {
        $admin = $this->admin();
        $tec = $this->tecnico();
        EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Serviço longo', 'estado' => 'planeado',
            'inicio' => '2026-08-03 09:00', 'fim' => '2026-08-04 17:00',
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome,
            'horas_dias' => [
                ['dia' => '2026-08-03', 'inicio' => '09:00', 'fim' => '17:00'],
                ['dia' => '2026-08-04', 'inicio' => '09:00', 'fim' => '17:00'],
            ],
        ]);

        // 18:00–19:00 do 1.º dia: dentro do intervalo contínuo antigo, mas FORA das horas
        // trabalhadas → deixa de bloquear.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('formTitulo', 'Reunião ao fim do dia')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T18:00')
            ->set('formFim', '2026-08-03T18:45')
            ->call('criarEvento')
            ->assertHasNoErrors();

        // 10:00 do 1.º dia: colide com as horas trabalhadas → bloqueado.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->set('formTitulo', 'Sobreposto')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-08-03T10:00')
            ->set('formFim', '2026-08-03T11:00')
            ->call('criarEvento')
            ->assertHasErrors('formInicio');
    }
}
