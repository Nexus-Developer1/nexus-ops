<?php

namespace Tests\Feature;

use App\Enums\EstadoEvento;
use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Correções da dinâmica da agenda: janela de visibilidade por sobreposição, cores de
// técnico estáveis (incl. multi-técnico), conflitos verificados dentro de transação e
// fecho do evento quando o relatório é finalizado (não só quando é enviado).
class AgendaCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function tecnico(string $nome, string $email): User
    {
        return User::create(['nome' => $nome, 'email' => $email, 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
    }

    public function test_evento_que_atravessa_a_janela_visivel_aparece(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);

        // Começa ANTES da janela pedida e acaba lá dentro (ex.: vista de mês seguinte).
        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Atravessa', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-06-30 09:00'), 'fim' => Carbon::parse('2026-07-01 10:00'), 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())
            ->test(Calendario::class)
            ->call('eventos', '2026-07-01', '2026-08-01')
            ->assertReturned(fn (array $r) => collect($r)->contains(fn ($e) => str_starts_with($e['title'], 'Atravessa')));
    }

    public function test_cores_dos_tecnicos_nao_mudam_quando_entra_conta_nova(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $bruno = $this->tecnico('Bruno', 'b@nexus.pt');
        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 09:00'), 'fim' => Carbon::parse('2026-07-01 10:00'),
            'tecnico_id' => $bruno->id, 'tecnico_nome' => 'Bruno', 'cliente_id' => $cliente->id]);

        $corAntes = null;
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('eventos', '2026-07-01', '2026-07-08')
            ->assertReturned(function (array $r) use (&$corAntes) {
                $corAntes = collect($r)->first(fn ($b) => str_starts_with($b['title'], 'Visita'))['backgroundColor'] ?? null;

                return $corAntes !== null;
            });

        // Entra uma conta nova com nome alfabeticamente ANTERIOR (o esquema antigo, por ordem
        // alfabética de nomes, empurrava o Bruno para a 2.ª cor; por id de conta, não mexe).
        $this->tecnico('Alberto', 'al@nexus.pt');

        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('eventos', '2026-07-01', '2026-07-08')
            ->assertReturned(fn (array $r) => (collect($r)->first(fn ($b) => str_starts_with($b['title'], 'Visita'))['backgroundColor'] ?? null) === $corAntes);
    }

    public function test_evento_multi_tecnico_leva_as_cores_de_todos(): void
    {
        $admin = $this->admin();
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        $bruno = $this->tecnico('Bruno', 'b@nexus.pt');
        $carla = $this->tecnico('Carla', 'c@nexus.pt');

        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Serviço', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 11:30'), 'fim' => Carbon::parse('2026-07-01 17:00'),
            'tecnico_id' => $bruno->id, 'tecnico_nome' => 'Bruno', 'cliente_id' => $cliente->id]);
        $evento->tecnicosAdicionais()->sync([$carla->id]);

        // O payload leva as cores de TODOS os técnicos (o frontend divide o bloco em faixas);
        // a 1.ª é a do principal e coincide com o backgroundColor de fallback.
        Livewire::actingAs($admin)->test(Calendario::class)
            ->call('eventos', '2026-07-01', '2026-07-08')
            ->assertReturned(function (array $r) {
                $e = collect($r)->first(fn ($b) => str_starts_with($b['title'], 'Serviço')); // título agora leva ' · cliente'

                return count($e['extendedProps']['cores']) === 2
                    && count(array_unique($e['extendedProps']['cores'])) === 2
                    && $e['extendedProps']['cores'][0] === $e['backgroundColor'];
            });
    }

    public function test_criar_evento_com_conflito_de_tecnico_e_recusado_na_transacao(): void
    {
        $tec = $this->tecnico('Téc', 't@nexus.pt');
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);
        EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Ocupado', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-01 09:00'), 'fim' => Carbon::parse('2026-07-01 10:00'),
            'tecnico_id' => $tec->id, 'tecnico_nome' => $tec->nome, 'cliente_id' => $cliente->id]);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Sobreposto')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-07-01T09:30')
            ->set('formFim', '2026-07-01T10:30')
            ->call('criarEvento')
            ->assertHasErrors('formInicio');

        $this->assertDatabaseCount('eventos_agenda', 1); // nada gravado
    }

    public function test_criar_evento_grava_sem_enviar_email(): void
    {
        // A notificação de atribuição por email foi REMOVIDA (pedido da equipa, 2026-07-27):
        // criar um serviço/reunião não envia nada ao técnico.
        Notification::fake();
        $tec = $this->tecnico('Téc', 't@nexus.pt');

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Reunião')
            ->set('formEquipamentoId', $this->equipamentoDeTeste()->id)
            ->set('formTecnicoIds', [$tec->id])
            ->set('formInicio', '2026-07-01T09:00')
            ->set('formFim', '2026-07-01T10:00')
            ->set('formNotificar', false) // o email é testado em NotificacaoEventoTest
            ->call('criarEvento')
            ->assertHasNoErrors();

        $this->assertSame($tec->id, EventoAgenda::firstOrFail()->tecnico_id);
        Notification::assertNothingSent();
    }

    public function test_finalizar_relatorio_conclui_o_evento_ligado(): void
    {
        Queue::fake(); // não gera o PDF a sério neste teste

        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X40', 'numero_serie' => 'SN-1']);

        // Evento futuro já convertido (como deixaria a camada 2), com relatório em rascunho.
        $evento = EventoAgenda::create(['tipo' => 'outro', 'titulo' => 'Visita', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $equip->id, 'cliente_id' => $cliente->id]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'planeada',
            'data_inicio' => now()->addWeek()->toDateString(), 'evento_agenda_id' => $evento->id]);
        $evento->update(['intervencao_id' => $interv->id]);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        $tec = $this->tecnico('Téc', 'tec@nexus.pt');
        Livewire::actingAs($this->admin())->test(Novo::class, ['relatorio' => $relatorio])
            ->set('data', now()->addWeek()->toDateString())
            ->set('tecnicoIds', [$tec->id]) // finalizar exige quem fez a intervenção
            ->set('finalizarComFichasVazias', true) // confirma o aviso de fichas vazias (Vaga 1)
            ->call('finalizar')
            ->assertHasNoErrors();

        // Visita executada → evento concluído, mesmo SEM envio por email (a data futura faz a
        // camada 3 repor "planeado" na atualização — o fecho tem de vir depois dela).
        $this->assertSame(EstadoEvento::Concluido, $evento->fresh()->estado);
    }
}
