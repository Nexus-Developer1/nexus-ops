<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\User;
use App\Services\Agenda\SincronizadorAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Fase 2 do refactor da agenda: guardas explícitas do SincronizadorAgenda (ponto único
// das camadas 2/3) e backfill do tecnico_id nos eventos legados (nome → conta).
class AgendaRefactorTest extends TestCase
{
    use RefreshDatabase;

    // Data fixa (segunda-feira, 14/09/2026): os testes marcam serviços uns dias antes/depois de
    // hoje, e a agenda recusa feriados — com a data real, alguns dias calhariam num.
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 09:00:00');
    }

    /** @return array{0: Cliente, 1: Equipamento} */
    private function contexto(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-1']);

        return [$cliente, $equip];
    }

    public function test_sincronizador_gera_rascunho_de_evento_futuro_com_equipamento(): void
    {
        [$cliente, $equip] = $this->contexto();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);

        $relatorio = app(SincronizadorAgenda::class)->eventoGravado($evento);

        $this->assertNotNull($relatorio);
        $this->assertSame('rascunho', $relatorio->estado->value);
        $this->assertSame($evento->fresh()->intervencao_id, $relatorio->intervencao_id); // ligado dos dois lados
    }

    public function test_sincronizador_guarda_anti_loop_evento_ja_convertido(): void
    {
        [$cliente, $equip] = $this->contexto();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id]);
        $evento->update(['intervencao_id' => $interv->id]);

        // Evento já convertido (como os criados pela camada 3) → NUNCA gera segundo rascunho.
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($evento->fresh()));
        $this->assertSame(1, Intervencao::count());
    }

    public function test_sincronizador_ignora_evento_sem_ambito_ou_passado(): void
    {
        [$cliente, $equip] = $this->contexto();

        // Sem equipamento nem contrato → nada.
        $semAmbito = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Reunião', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0), 'cliente_id' => $cliente->id]);
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($semAmbito));

        // Passado → registo histórico, não gera trabalho.
        $passado = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Antiga', 'estado' => 'planeado',
            'inicio' => now()->subWeek()->setTime(9, 0), 'fim' => now()->subWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($passado));

        $this->assertSame(0, Intervencao::count());
    }

    // O caso real (set. 2026): evento criado SEM equipamento; a meio da visita o tecnico regista
    // o equipamento a mao e associa-o ao evento. E ai que o rascunho tem de nascer -- a regra
    // antiga (so eventos com INICIO no futuro) deixava a visita sem relatorio.
    public function test_equipamento_associado_com_a_visita_a_decorrer_gera_rascunho(): void
    {
        [$cliente, $equip] = $this->contexto();

        // Visita a decorrer: comecou ha uma hora, acaba daqui a uma.
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => now()->subHour(), 'fim' => now()->addHour(), 'cliente_id' => $cliente->id]);

        // Sem equipamento ainda nao ha ambito.
        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($evento));

        // O equipamento e associado a meio da visita.
        $evento->update(['equipamento_id' => $equip->id]);
        $relatorio = app(SincronizadorAgenda::class)->eventoGravado($evento->fresh());

        $this->assertNotNull($relatorio);
        $this->assertSame('rascunho', $relatorio->estado->value);
        $this->assertSame($evento->fresh()->intervencao_id, $relatorio->intervencao_id);
    }

    public function test_visita_acabada_ha_pouco_ainda_gera_o_rascunho_em_falta(): void
    {
        [$cliente, $equip] = $this->contexto();

        // Acabou ha 3 horas (o equipamento so foi registado depois de sair do cliente).
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => now()->subHours(5), 'fim' => now()->subHours(3),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);

        $this->assertNotNull(app(SincronizadorAgenda::class)->eventoGravado($evento));
    }

    public function test_visita_antiga_continua_a_ser_registo_historico(): void
    {
        [$cliente, $equip] = $this->contexto();

        // Acabou ha 5 dias: mexer nela (corrigir umas notas) nao pode fazer nascer relatorios.
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Antiga', 'estado' => 'planeado',
            'inicio' => now()->subDays(5)->setTime(9, 0), 'fim' => now()->subDays(5)->setTime(11, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);

        $this->assertNull(app(SincronizadorAgenda::class)->eventoGravado($evento));
        $this->assertSame(0, Intervencao::count());
    }

    // ---- Pedido da equipa (set. 2026): o que conta é quando o equipamento é associado ----
    // «Marquei o serviço sem equipamento porque não tinha os dados; quando o associo, mesmo com a
    // data já passada, tem de dar para abrir a intervenção e fazer o relatório.»

    public function test_equipamento_associado_depois_da_data_passar_cria_o_relatorio(): void
    {
        [$cliente, $equip] = $this->contexto();
        $tecnico = $this->tecnicoDeTeste();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => now()->subDays(3)->setTime(14, 0), 'fim' => now()->subDays(3)->setTime(18, 0),
            'tecnico_id' => $tecnico->id, 'tecnico_nome' => $tecnico->nome, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('abrirEdicao')
            ->set('formEquipamentoId', $equip->id)
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento->refresh();
        $this->assertNotNull($evento->intervencao_id, 'ganhou intervenção → aparece «Abrir intervenção»');
        $this->assertSame('rascunho', $evento->intervencao->relatorio->estado->value);
    }

    // Serviço antigo que JÁ tinha equipamento: mexer-lhe (corrigir o título) não cria nada.
    public function test_servico_antigo_que_ja_tinha_equipamento_nao_ganha_relatorio_ao_editar(): void
    {
        [$cliente, $equip] = $this->contexto();
        $tecnico = $this->tecnicoDeTeste();
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => now()->subDays(5)->setTime(9, 0), 'fim' => now()->subDays(5)->setTime(11, 0),
            'tecnico_id' => $tecnico->id, 'tecnico_nome' => $tecnico->nome,
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->call('selecionar', $evento->id)
            ->call('abrirEdicao')
            ->set('formTitulo', 'Serviço corrigido')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertNull($evento->fresh()->intervencao_id);
        $this->assertSame(0, Intervencao::count());
    }

    // Serviço NOVO com data já passada e com equipamento (registar trabalho feito): também cria.
    public function test_servico_novo_com_data_passada_e_equipamento_cria_o_relatorio(): void
    {
        [, $equip] = $this->contexto();
        $dia = now()->subDays(4);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Serviço')
            ->set('formEquipamentoId', $equip->id)
            ->set('formTecnicoIds', [$this->tecnicoDeTeste()->id])
            ->set('formInicio', $dia->format('Y-m-d').'T09:00')
            ->set('formFim', $dia->format('Y-m-d').'T12:00')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $evento = EventoAgenda::firstOrFail();
        $this->assertNotNull($evento->intervencao_id);
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'admin-refactor@nexus.pt'],
            ['nome' => 'Admin', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_backfill_liga_eventos_legados_a_conta_do_tecnico(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $joao = User::create(['nome' => 'João Silva', 'email' => 'j@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        // Nome AMBÍGUO: duas contas de técnico com o mesmo nome — não deve ser tocado.
        User::create(['nome' => 'Rui Costa', 'email' => 'r1@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        User::create(['nome' => 'Rui Costa', 'email' => 'r2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);

        $legado = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'L', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'tecnico_nome' => '  joão silva ', 'cliente_id' => $cliente->id]);
        $ambiguo = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'A', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'tecnico_nome' => 'Rui Costa', 'cliente_id' => $cliente->id]);

        // Reexecuta o backfill (a migração corre no arranque do RefreshDatabase, antes dos dados).
        $migracao = require database_path('migrations/2026_07_23_000002_backfill_tecnico_id_eventos_agenda.php');
        $migracao->up();

        $this->assertSame($joao->id, $legado->fresh()->tecnico_id);   // casado apesar de caixa/espaços
        $this->assertNull($ambiguo->fresh()->tecnico_id);             // ambíguo fica como está
    }
}
