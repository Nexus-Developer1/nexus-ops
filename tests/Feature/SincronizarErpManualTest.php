<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\SincronizarErp;
use App\Livewire\DashboardGestao;
use App\Mail\SincronizacaoErpFalhou;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Botão "Sincronizar PHC" do dashboard: dispara o job em background; se alguma etapa
// falhar, o suporte (config erp.email_falhas) é avisado por email.
class SincronizarErpManualTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    public function test_botao_dispara_o_job_e_faz_throttle(): void
    {
        Queue::fake();
        config()->set('erp.driver', 'fake');

        $c = Livewire::actingAs($this->admin())->test(DashboardGestao::class);

        $c->call('sincronizarErp');
        Queue::assertPushed(SincronizarErp::class, 1);

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

    public function test_falha_numa_etapa_envia_email_ao_suporte(): void
    {
        Mail::fake();

        // Clientes falha (código 1); as outras etapas passam — o email lista SÓ a falhada.
        Artisan::shouldReceive('call')->once()->with('erp:sincronizar-clientes')->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Sync de clientes FALHOU: ligação recusada');
        Artisan::shouldReceive('call')->once()->with('erp:sincronizar-faturacao')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('erp:sincronizar-equipamentos')->andReturn(0);

        (new SincronizarErp)->handle();

        Mail::assertSent(SincronizacaoErpFalhou::class, function (SincronizacaoErpFalhou $mail) {
            return $mail->hasTo(config('erp.email_falhas'))
                && array_keys($mail->falhas) === ['Clientes']
                && str_contains($mail->falhas['Clientes'], 'ligação recusada');
        });
    }

    public function test_sucesso_nao_envia_email(): void
    {
        Mail::fake();

        Artisan::shouldReceive('call')->times(3)->andReturn(0);

        (new SincronizarErp)->handle();

        Mail::assertNothingSent();
    }

    public function test_crash_do_job_envia_email_ao_suporte(): void
    {
        Mail::fake();

        (new SincronizarErp)->failed(new \RuntimeException('worker morto'));

        Mail::assertSent(SincronizacaoErpFalhou::class, fn ($m) => $m->hasTo(config('erp.email_falhas')));
    }
}
