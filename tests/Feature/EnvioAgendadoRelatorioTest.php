<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Enviar;
use App\Livewire\Relatorios\Listagem;
use App\Mail\RelatorioParaCliente;
use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Envio AGENDADO do relatório (out. 2026): na página de envio escolhe-se Imediato ou daqui a
// 30 min / 1 / 2 / 4 / 8 / 24 h. O job vai para a fila com atraso e leva um token, que fica no
// relatório; na hora só envia se o token ainda for o do relatório. Cancelar, reagendar ou
// enviar já invalidam o agendamento anterior — o cliente nunca recebe duas vezes.
class EnvioAgendadoRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->admin = User::create(['nome' => 'Rui Pereira', 'email' => 'rpereira@nxs.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function relatorio(): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'POLY LANEMA LDA', 'email' => 'artur.santos@lanema.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $e = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $i = Intervencao::create(['equipamento_id' => $e->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);

        return Relatorio::create(['intervencao_id' => $i->id, 'numero' => '2026/0021', 'data' => now(),
            'estado' => 'finalizado', 'pdf_path' => 'relatorios/2026-0021.pdf']);
    }

    private function agendar(Relatorio $r, string $quando)
    {
        return Livewire::actingAs($this->admin)->test(Enviar::class, ['relatorio' => $r])
            ->set('quando', $quando)
            ->call('enviar');
    }

    public function test_opcoes_e_botao_mudam_com_a_escolha(): void
    {
        // (o PHP guarda as chaves numéricas como inteiros — compara-se o texto)
        $this->assertSame(['agora', '30', '60', '120', '240', '480', '1440'], array_map('strval', array_keys(Enviar::OPCOES_ENVIO)));

        Livewire::actingAs($this->admin)->test(Enviar::class, ['relatorio' => $this->relatorio()])
            ->assertSet('quando', 'agora')
            ->assertSee('Enviar email')->assertSee('Daqui a 24 h')
            ->set('quando', '120')
            ->assertSee('Agendar envio');
    }

    public function test_imediato_vai_logo_para_a_fila_sem_agendamento(): void
    {
        Queue::fake();
        $r = $this->relatorio();

        $this->agendar($r, 'agora')->assertHasNoErrors()->assertRedirect(route('relatorios'));

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->token === null && $job->delay === null);
        $this->assertFalse($r->fresh()->temEnvioAgendado());
    }

    public function test_agendar_atrasa_o_job_e_regista_o_agendamento(): void
    {
        Queue::fake();
        $r = $this->relatorio();

        $this->agendar($r, '120')->assertHasNoErrors()->assertRedirect(route('relatorios'));

        $r->refresh();
        $this->assertTrue($r->temEnvioAgendado());
        $this->assertSame('2026-10-06 12:00', $r->envio_agendado_em->format('Y-m-d H:i'));
        $this->assertSame('artur.santos@lanema.pt', $r->envio_agendado_destino);
        $this->assertSame(EstadoRelatorio::Finalizado, $r->estado); // ainda não saiu

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->token === $r->envio_agendado_token
            && $job->delay instanceof Carbon && $job->delay->equalTo(now()->addMinutes(120)));
        $this->assertTrue(Auditoria::where('acao', 'relatorio_envio_agendado')->exists());
    }

    public function test_na_hora_o_job_com_o_token_certo_envia_e_limpa_o_agendamento(): void
    {
        Mail::fake();
        $r = $this->relatorio();
        $r->update(['envio_agendado_em' => now()->addHour(), 'envio_agendado_token' => 'tok-1', 'envio_agendado_destino' => 'artur.santos@lanema.pt']);

        (new EnviarRelatorioPorEmail($r, 'artur.santos@lanema.pt', 'A', 'M', null, 'tok-1'))->handle(app(GeradorRelatorio::class));

        Mail::assertSent(RelatorioParaCliente::class);
        $r->refresh();
        $this->assertSame(EstadoRelatorio::Enviado, $r->estado);
        $this->assertFalse($r->temEnvioAgendado());
    }

    public function test_cancelar_faz_o_job_da_fila_nao_enviar_nada(): void
    {
        Queue::fake();
        Mail::fake();
        $r = $this->relatorio();
        $this->agendar($r, '30');
        $token = $r->fresh()->envio_agendado_token;

        Livewire::actingAs($this->admin)->test(Enviar::class, ['relatorio' => $r->fresh()])
            ->assertSee('Cancelar envio agendado')
            ->call('cancelarAgendamento')
            ->assertRedirect(route('relatorios'));
        $this->assertFalse($r->fresh()->temEnvioAgendado());
        $this->assertTrue(Auditoria::where('acao', 'relatorio_envio_agendado_cancelado')->exists());

        // Na hora marcada o job ainda corre — e não faz nada.
        (new EnviarRelatorioPorEmail($r, 'artur.santos@lanema.pt', 'A', 'M', null, $token))->handle(app(GeradorRelatorio::class));
        Mail::assertNothingSent();
        $this->assertSame(EstadoRelatorio::Finalizado, $r->fresh()->estado);
    }

    public function test_reagendar_ou_enviar_ja_invalida_o_agendamento_anterior(): void
    {
        Queue::fake();
        Mail::fake();
        $r = $this->relatorio();

        $this->agendar($r, '480');
        $primeiro = $r->fresh()->envio_agendado_token;

        // Reagendar: o token muda; o primeiro job já não vale.
        $this->agendar($r->fresh(), '30');
        $segundo = $r->fresh()->envio_agendado_token;
        $this->assertNotSame($primeiro, $segundo);
        (new EnviarRelatorioPorEmail($r, 'x@y.pt', 'A', 'M', null, $primeiro))->handle(app(GeradorRelatorio::class));
        Mail::assertNothingSent();

        // Enviar já: o agendamento à espera é apagado (senão saíam dois).
        $this->agendar($r->fresh(), 'agora');
        $this->assertFalse($r->fresh()->temEnvioAgendado());
        (new EnviarRelatorioPorEmail($r, 'x@y.pt', 'A', 'M', null, $segundo))->handle(app(GeradorRelatorio::class));
        Mail::assertNothingSent();
    }

    public function test_agendado_que_volta_a_rascunho_nao_sai_e_deixa_de_estar_agendado(): void
    {
        Mail::fake();
        $r = $this->relatorio();
        $r->update(['envio_agendado_em' => now()->addHour(), 'envio_agendado_token' => 'tok-r', 'envio_agendado_destino' => 'a@b.pt']);
        $r->update(['estado' => EstadoRelatorio::Rascunho]); // reaberto entretanto

        (new EnviarRelatorioPorEmail($r, 'a@b.pt', 'A', 'M', null, 'tok-r'))->handle(app(GeradorRelatorio::class));

        Mail::assertNothingSent();
        $this->assertFalse($r->fresh()->temEnvioAgendado());
    }

    public function test_opcao_invalida_e_recusada(): void
    {
        Queue::fake();
        $this->agendar($this->relatorio(), '7')->assertHasErrors('quando');
        Queue::assertNothingPushed();
    }

    public function test_listagem_mostra_que_esta_agendado(): void
    {
        $r = $this->relatorio();
        $r->update(['envio_agendado_em' => Carbon::parse('2026-10-06 14:30'), 'envio_agendado_token' => 'tok-l', 'envio_agendado_destino' => 'a@b.pt']);

        Livewire::actingAs($this->admin)->test(Listagem::class)->assertSee('Agendado 14:30');
    }
}
