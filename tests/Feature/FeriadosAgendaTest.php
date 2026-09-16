<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\FeriadosPortugal;
use App\Services\Agenda\FonteCalendario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Feriados nacionais de Portugal na agenda (set. 2026): aparecem no calendário e não se
// marcam eventos que comecem nesses dias. As férias são a exceção (atravessam feriados), e
// o Carnaval é só tolerância de ponto — vê-se, mas não bloqueia.
class FeriadosAgendaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Equipamento $ups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $this->ups = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-F1']);
    }

    private function feriados(): FeriadosPortugal
    {
        return app(FeriadosPortugal::class);
    }

    public function test_pascoa_e_os_feriados_moveis_batem_certo(): void
    {
        // Domingos de Páscoa conhecidos — se o algoritmo se enganar, isto acusa logo.
        $esperado = ['2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2030-04-21'];
        foreach ($esperado as $data) {
            $this->assertSame($data, $this->feriados()->pascoa((int) substr($data, 0, 4))->format('Y-m-d'));
        }

        // 2026: Páscoa a 05/04 → Sexta-feira Santa a 03/04 e Corpo de Deus a 04/06.
        $this->assertSame('Sexta-feira Santa', $this->feriados()->nome('2026-04-03'));
        $this->assertSame('Domingo de Páscoa', $this->feriados()->nome('2026-04-05'));
        $this->assertSame('Corpo de Deus', $this->feriados()->nome('2026-06-04'));
    }

    public function test_os_treze_feriados_obrigatorios_de_cada_ano(): void
    {
        foreach ([2026, 2027, 2028] as $ano) {
            $obrigatorios = array_filter($this->feriados()->doAno($ano), fn ($f) => ! $f['tolerancia']);
            $this->assertCount(13, $obrigatorios, "Ano $ano");
        }

        $this->assertSame('Natal', $this->feriados()->nome('2026-12-25'));
        $this->assertSame('Dia da Liberdade', $this->feriados()->nome('2026-04-25'));
        $this->assertSame('Restauração da Independência', $this->feriados()->nome('2026-12-01'));
        $this->assertNull($this->feriados()->nome('2026-12-26')); // dia 26 não é feriado
    }

    public function test_carnaval_e_so_tolerancia_e_nao_bloqueia(): void
    {
        $carnaval = '2026-02-17'; // Páscoa 05/04 − 47 dias

        $this->assertNull($this->feriados()->nome($carnaval));                 // não é feriado
        $this->assertSame('Carnaval (tolerância)', $this->feriados()->nome($carnaval, incluirTolerancia: true));
        $this->assertFalse($this->feriados()->eFeriado($carnaval));

        $this->criarEvento($carnaval)->assertHasNoErrors();
        $this->assertSame(1, EventoAgenda::count());
    }

    public function test_o_calendario_mostra_os_feriados_como_fundo(): void
    {
        $eventos = app(FonteCalendario::class)->eventos(Carbon::parse('2026-12-01'), Carbon::parse('2027-01-01'));
        $feriados = collect($eventos)->where('extendedProps.kind', 'feriado');

        // Dezembro de 2026: 01, 08 e 25.
        $this->assertSame(['2026-12-01', '2026-12-08', '2026-12-25'], $feriados->pluck('start')->sort()->values()->all());

        $natal = $feriados->firstWhere('start', '2026-12-25');
        $this->assertSame('Natal', $natal['title']);
        $this->assertSame('background', $natal['display']);
        $this->assertContains('fc-feriado', $natal['classNames']);
        $this->assertFalse($natal['editable']);
    }

    public function test_nao_se_marca_um_evento_que_comece_num_feriado(): void
    {
        $this->criarEvento('2026-12-25')
            ->assertHasErrors('formInicio');

        $this->assertSame(0, EventoAgenda::count());
    }

    public function test_marca_se_na_vespera_e_no_dia_seguinte(): void
    {
        $this->criarEvento('2026-12-24')->assertHasNoErrors();
        $this->criarEvento('2026-12-26')->assertHasNoErrors();

        $this->assertSame(2, EventoAgenda::count());
    }

    public function test_ferias_podem_comecar_num_feriado(): void
    {
        $this->criarEvento('2026-12-25', titulo: 'Férias')->assertHasNoErrors();

        $this->assertSame(1, EventoAgenda::count());
    }

    public function test_um_servico_de_varios_dias_pode_atravessar_um_feriado(): void
    {
        // Começa a 24 e acaba a 26: atravessa o Natal, mas não começa nele.
        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', '2026-12-24', '2026-12-26')
            ->set('formTitulo', 'Migração')
            ->set('formEquipamentoId', $this->ups->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])
            ->set('formInicio', '2026-12-24T09:00')
            ->set('formFim', '2026-12-26T18:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame(1, EventoAgenda::count());
    }

    public function test_arrastar_um_evento_para_cima_de_um_feriado_e_recusado(): void
    {
        $this->criarEvento('2026-12-24')->assertHasNoErrors();
        $evento = EventoAgenda::firstOrFail();

        Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('reagendar', $evento->id, '2026-12-25T09:00:00', '2026-12-25T10:00:00', null)
            ->assertReturned(fn ($r) => $r['ok'] === false && str_contains($r['mensagem'], 'Natal'));
        $this->assertSame('2026-12-24', $evento->fresh()->inicio->format('Y-m-d'));
    }

    public function test_com_a_regra_desligada_volta_ao_comportamento_anterior(): void
    {
        config(['agenda.bloquear_feriados' => false]);

        $this->criarEvento('2026-12-25')->assertHasNoErrors();
        $this->assertSame(1, EventoAgenda::count());
    }

    private function criarEvento(string $dia, string $titulo = 'Preventiva')
    {
        return Livewire::actingAs($this->admin)->test(Calendario::class)
            ->call('abrirCriacao', $dia, $dia)
            ->set('formTitulo', $titulo)
            ->set('formEquipamentoId', $this->ups->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])
            ->set('formInicio', $dia.'T09:00')
            ->set('formFim', $dia.'T10:00')
            ->call('criarEvento');
    }
}
