<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\GeradorIcs;
use App\Services\Agenda\NotificadorAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Morada da visita no serviço da agenda (set. 2026): preenche-se com a do local do equipamento
// ou a do cliente, muda-se à mão, e o detalhe dá os botões Google Maps / Waze.
class AgendaMoradaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 09:00:00');
        Notification::fake();
    }

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(): User
    {
        return User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    private function cliente(): Cliente
    {
        return Cliente::create(['nome' => 'BNP PARIBAS, S.A.', 'morada' => 'Av. da Liberdade, 100', 'codpost' => '1250-146 LISBOA', 'ativo' => true]);
    }

    public function test_a_morada_vem_do_cliente_ou_do_local_e_o_que_se_escreve_fica(): void
    {
        $cliente = $this->cliente();
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC Porto', 'morada' => 'Rua do Datacenter, 5, 4100-001 Porto']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'MST120']);
        $tec = $this->tecnico();

        $c = Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('abrirCriacao', '2026-09-21', '2026-09-21')
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('formMorada', 'Av. da Liberdade, 100, 1250-146 LISBOA')   // a do cliente, com o código postal
            ->call('selecionarEquipamento', $equip->id)
            ->assertSet('formMorada', 'Rua do Datacenter, 5, 4100-001 Porto');    // a do local do equipamento ganha

        // Escrita à mão: não se troca ao mexer no cliente/equipamento.
        $c->set('formMorada', 'Obra na Rua Nova, 12, Maia')
            ->call('removerEquipamentoPrincipal')
            ->call('selecionarEquipamento', $equip->id)
            ->assertSet('formMorada', 'Obra na Rua Nova, 12, Maia')
            ->set('formTitulo', 'Serviço')
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-09-21T10:00')
            ->set('formFim', '2026-09-21T12:00')
            ->set('formNotificar', false)
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame('Obra na Rua Nova, 12, Maia', EventoAgenda::firstOrFail()->morada);
    }

    public function test_o_detalhe_tem_a_morada_e_os_botoes_maps_e_waze(): void
    {
        $cliente = $this->cliente();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => '2026-09-21 10:00', 'fim' => '2026-09-21 12:00', 'cliente_id' => $cliente->id,
            'morada' => 'Rua do Ouro, 7 & 9, Lisboa']);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->assertSee('Rua do Ouro, 7 &amp; 9, Lisboa', false)
            ->assertSeeHtml('href="https://www.google.com/maps/search/?api=1&amp;query=Rua%20do%20Ouro%2C%207%20%26%209%2C%20Lisboa"')
            ->assertSeeHtml('href="https://waze.com/ul?q=Rua%20do%20Ouro%2C%207%20%26%209%2C%20Lisboa&amp;navigate=yes"');
    }

    // Serviços antigos (sem morada gravada): o detalhe usa a do cliente, e editar propõe-na.
    public function test_servico_antigo_usa_a_morada_do_cliente(): void
    {
        $cliente = $this->cliente();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => '2026-09-21 10:00', 'fim' => '2026-09-21 12:00', 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->assertSee('Av. da Liberdade, 100, 1250-146 LISBOA')
            ->assertSee('Google Maps')
            ->call('abrirEdicao')
            ->assertSet('formMorada', 'Av. da Liberdade, 100, 1250-146 LISBOA');

        // Sem morada nenhuma (nem cliente) → sem botões.
        $sem = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => '2026-09-22 10:00', 'fim' => '2026-09-22 11:00']);
        Livewire::actingAs(User::first())->test(Calendario::class)
            ->call('selecionar', $sem->id)
            ->assertDontSee('Google Maps');
    }

    // O convite dos técnicos leva a morada como localização (o calendário do telemóvel abre o mapa).
    public function test_o_convite_leva_a_morada_como_localizacao(): void
    {
        $cliente = $this->cliente();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => '2026-09-21 10:00', 'fim' => '2026-09-21 12:00', 'cliente_id' => $cliente->id,
            'morada' => 'Rua do Datacenter 5 Porto']);

        $dados = NotificadorAgenda::instantaneo($evento);
        $this->assertSame('Rua do Datacenter 5 Porto', $dados['morada']);

        $ics = app(GeradorIcs::class)->convite($dados, 0, $this->tecnico());
        $this->assertStringContainsString('LOCATION:Rua do Datacenter 5 Porto', str_replace("\r\n ", '', $ics));
    }
}
