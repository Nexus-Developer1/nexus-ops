<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\SincronizarErp;
use App\Livewire\DashboardGestao;
use App\Mail\ResultadoSincronizacaoErp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Sincronização do PHC: o botão do dashboard dispara o job em modo SILENCIOSO (sem email);
// o agendado (08h/13h/19h) usa o mesmo job com agendado=true e envia SEMPRE o email de
// resultado ao suporte (config erp.email_sync) — sucesso ou falha.
class SincronizarErpManualTest extends TestCase
{
    use RefreshDatabase;

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
                && array_keys($mail->resultados) === ['Clientes', 'Equipamentos', 'Faturação']
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
                && str_contains($mail->resultados['Clientes']['detalhe'], 'ligação recusada')
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

    public function test_agendado_usa_o_mesmo_job_encadeado(): void
    {
        // O cron das 08h/13h/19h dispara UMA corrida encadeada via o mesmo job do botão
        // (e não os 3 comandos desfasados de antes).
        $eventos = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $doJob = $eventos->filter(fn ($e) => str_contains((string) $e->description, SincronizarErp::class));
        $this->assertCount(1, $doJob);
        $this->assertSame('0 8,13,19 * * *', $doJob->first()->expression);

        // Os comandos individuais deixaram de estar agendados.
        $comandos = $eventos->filter(fn ($e) => str_contains((string) $e->command, 'erp:sincronizar'));
        $this->assertCount(0, $comandos);
    }
}
