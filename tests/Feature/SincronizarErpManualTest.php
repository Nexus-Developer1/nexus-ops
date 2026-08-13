<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\SincronizarErp;
use App\Livewire\DashboardGestao;
use App\Mail\ResultadoSincronizacaoErp;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// Sincronização do PHC: o botão do dashboard dispara o job em modo SILENCIOSO (sem email);
// o agendado (08h/13h/19h) usa o mesmo job com agendado=true e envia SEMPRE o email de
// resultado ao suporte (config erp.email_sync) — sucesso ou falha.
class SincronizarErpManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neste ambiente as env reais ($_SERVER) ganham ao force do phpunit → o cache dos
        // testes pode ser o Redis de dev, partilhado entre corridas. Sem esta limpeza, o
        // throttle de 10 min do botão sobrevive de uma corrida para a seguinte e o teste
        // fica intermitente (passa, e 5 minutos depois falha).
        Cache::forget('erp-sync-manual-pedido');
        Cache::forget('erp-sync:ultimo');
    }

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function simularEtapas(int $clientes = 0, int $equipamentos = 0, int $faturacao = 0): void
    {
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-clientes')->andReturn($clientes);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn($clientes === 0 ? 'Sincronização concluída: 10 criados, 2 atualizados, 0 erros.' : 'Sync de clientes FALHOU: ligação recusada');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-equipamentos')->andReturn($equipamentos);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criados, 5 atualizados, 0 erros.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-artigos')->andReturn(0);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 3 criados, 0 atualizados, 0 erros.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-faturacao')->andReturn($faturacao);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criadas, 9 atualizadas, 0 erros.');
    }

    public function test_botao_dispara_o_job_e_faz_throttle(): void
    {
        Queue::fake();
        config()->set('erp.driver', 'fake');

        $c = Livewire::actingAs($this->admin())->test(DashboardGestao::class);

        $c->call('sincronizarErp');
        Queue::assertPushed(SincronizarErp::class, 1);
        Queue::assertPushed(fn (SincronizarErp $job) => $job->agendado === false);

        // 2.º clique logo a seguir: throttle — não empilha outro sync.
        $c->call('sincronizarErp');
        Queue::assertPushed(SincronizarErp::class, 1);
    }

    public function test_sem_driver_configurado_nao_dispara(): void
    {
        Queue::fake();
        config()->set('erp.driver', null);

        Livewire::actingAs($this->admin())->test(DashboardGestao::class)
            ->call('sincronizarErp');

        Queue::assertNothingPushed();
    }

    public function test_manual_e_silencioso_mesmo_com_falha(): void
    {
        Mail::fake();
        $this->simularEtapas(clientes: 1);

        (new SincronizarErp(agendado: false))->handle();

        Mail::assertNothingSent();
    }

    public function test_agendado_com_sucesso_envia_email_de_sucesso(): void
    {
        Mail::fake();
        $this->simularEtapas();

        (new SincronizarErp(agendado: true))->handle();

        Mail::assertSent(ResultadoSincronizacaoErp::class, function (ResultadoSincronizacaoErp $mail) {
            return $mail->hasTo(config('erp.email_sync'))
                && $mail->falhou === false
                && array_keys($mail->resultados) === ['Clientes', 'Equipamentos', 'Artigos', 'Faturação']
                && str_contains($mail->resultados['Clientes']['detalhe'], '10 criados');
        });
    }

    public function test_agendado_com_falha_envia_email_de_falha(): void
    {
        Mail::fake();
        $this->simularEtapas(clientes: 1);

        (new SincronizarErp(agendado: true))->handle();

        Mail::assertSent(ResultadoSincronizacaoErp::class, function (ResultadoSincronizacaoErp $mail) {
            return $mail->hasTo(config('erp.email_sync'))
                && $mail->falhou === true
                && $mail->resultados['Clientes']['ok'] === false
                && str_contains($mail->resultados['Clientes']['detalhe'], 'log da aplicação') // genérico — detalhe fica no log
                && $mail->resultados['Equipamentos']['ok'] === true; // uma falha não trava as seguintes
        });
    }

    public function test_crash_do_job_agendado_envia_email_e_manual_nao(): void
    {
        Mail::fake();

        (new SincronizarErp(agendado: false))->failed(new \RuntimeException('worker morto'));
        Mail::assertNothingSent();

        (new SincronizarErp(agendado: true))->failed(new \RuntimeException('worker morto'));
        Mail::assertSent(ResultadoSincronizacaoErp::class, fn ($m) => $m->hasTo(config('erp.email_sync')) && $m->falhou === true);
    }

    public function test_dashboard_mostra_o_resumo_quando_o_sync_termina(): void
    {
        Queue::fake();
        config()->set('erp.driver', 'fake');

        $c = Livewire::actingAs($this->admin())->test(DashboardGestao::class);
        $c->call('sincronizarErp');
        $this->assertNotNull($c->get('syncPedidoEm')); // entra em modo "à espera" (poll)

        // O job termina e deixa o resultado em cache (timestamp posterior ao pedido).
        Cache::put('erp-sync:ultimo', [
            'terminado_em' => now()->addSecond()->toIso8601String(),
            'falhou' => false,
            'resultados' => [
                'Clientes' => ['ok' => true, 'detalhe' => '2 criados, 1 atualizados, 3040 iguais (saltados), 0 erros.'],
                'Faturação' => ['ok' => true, 'detalhe' => '12 criadas, 0 atualizadas, 191090 iguais (saltadas), 0 erros.'],
            ],
        ], 600);

        $c->call('verificarSync')
            ->assertSet('syncPedidoEm', null)
            ->assertSee('Sincronização concluída')
            ->assertSee('2 criados')
            ->assertSee('12 criadas');
    }

    public function test_poll_desiste_de_corridas_longas_com_aviso(): void
    {
        Queue::fake();
        config()->set('erp.driver', 'fake');

        $c = Livewire::actingAs($this->admin())->test(DashboardGestao::class);
        $c->call('sincronizarErp');

        // 5 minutos depois, sem resultado em cache (corrida longa) → desiste do poll.
        $this->travel(5)->minutes();
        $c->call('verificarSync')->assertSet('syncPedidoEm', null)->assertSet('syncResultado', null);
        $this->travelBack();
    }

    public function test_props_do_poll_sao_trancadas_ao_browser(): void
    {
        Queue::fake();
        config()->set('erp.driver', 'fake');

        // #[Locked]: um payload forjado a definir o estado do poll (que rebentava no
        // Carbon::parse/render) é recusado pelo Livewire.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->admin())->test(DashboardGestao::class)
            ->set('syncPedidoEm', 'lixo');
    }

    public function test_job_deixa_o_resultado_em_cache(): void
    {
        Mail::fake();
        $this->simularEtapas();

        (new SincronizarErp)->handle();

        $ultimo = Cache::get('erp-sync:ultimo');
        $this->assertNotNull($ultimo);
        $this->assertFalse($ultimo['falhou']);
        $this->assertArrayHasKey('Clientes', $ultimo['resultados']);
        $this->assertStringContainsString('10 criados', $ultimo['resultados']['Clientes']['detalhe']);
    }

    public function test_agendado_usa_o_mesmo_job_encadeado(): void
    {
        // O cron das 08h/13h/19h dispara UMA corrida encadeada via o mesmo job do botão
        // (e não os 3 comandos desfasados de antes) + a corrida COMPLETA semanal (domingo 06h,
        // rede de segurança contra drift/hash envenenado — 10.ª revisão de segurança).
        $eventos = collect(app(Schedule::class)->events());

        $doJob = $eventos->filter(fn ($e) => str_contains((string) $e->description, SincronizarErp::class));
        $this->assertCount(2, $doJob);
        $this->assertEqualsCanonicalizing(
            ['0 8,13,19 * * *', '0 6 * * 0'],
            $doJob->map(fn ($e) => $e->expression)->values()->all(),
        );

        // Os comandos individuais deixaram de estar agendados.
        $comandos = $eventos->filter(fn ($e) => str_contains((string) $e->command, 'erp:sincronizar'));
        $this->assertCount(0, $comandos);
    }

    public function test_modo_completo_passa_a_flag_aos_comandos(): void
    {
        Mail::fake();

        // Em modo completo, cada comando recebe --completo (ignora os hashes do incremental).
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-clientes', ['--completo' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criados, 0 atualizados, 0 erros.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-equipamentos', ['--completo' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criados.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-artigos', ['--completo' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criados.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-faturacao', ['--completo' => true])->andReturn(0);
        Artisan::shouldReceive('output')->once()->ordered()->andReturn('Sincronização concluída: 0 criadas.');

        (new SincronizarErp(agendado: true, completo: true))->handle();

        Mail::assertSent(ResultadoSincronizacaoErp::class, fn ($m) => $m->falhou === false);
    }

    public function test_email_de_falha_nao_expoe_detalhe_tecnico(): void
    {
        Mail::fake();

        // A exceção traz host/query do ERP — nada disso pode chegar ao email (fica no log).
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-clientes')
            ->andThrow(new \RuntimeException('SQLSTATE[08001] host 192.168.1.50:1433 SELECT * FROM cl'));
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-equipamentos')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Sincronização concluída: ok.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-artigos')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Sincronização concluída: ok.');
        Artisan::shouldReceive('call')->once()->ordered()->with('erp:sincronizar-faturacao')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Sincronização concluída: ok.');

        (new SincronizarErp(agendado: true))->handle();

        Mail::assertSent(ResultadoSincronizacaoErp::class, function (ResultadoSincronizacaoErp $mail) {
            $detalhe = $mail->resultados['Clientes']['detalhe'];

            return $mail->falhou === true
                && ! str_contains($detalhe, '192.168.1.50')
                && ! str_contains($detalhe, 'SELECT')
                && str_contains($detalhe, 'log da aplicação');
        });
    }
}
