<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

// Robustez e agendamento dos syncs do ERP (routes/console.php): correm 3x/dia (08h/13h/19h),
// uma falha de LIGAÇÃO é logada e devolve FAILURE sem rebentar, e o upsert continua idempotente.
class AgendamentoSyncErpTest extends TestCase
{
    use RefreshDatabase;

    // Driver que finge um PHC em baixo: qualquer leitura rebenta com exceção de ligação.
    private function erpQueFalha(): ErpSyncDriver
    {
        return new class implements ErpSyncDriver
        {
            public function obterClientes(?int $limite = null): iterable
            {
                throw new RuntimeException('SQLSTATE[HY000]: Unable to connect to server');
            }

            public function obterLinhasFatura(?int $limite = null): iterable
            {
                throw new RuntimeException('SQLSTATE[HY000]: Unable to connect to server');
            }
        };
    }

    public function test_sync_clientes_com_ligacao_a_falhar_loga_e_devolve_failure(): void
    {
        Log::spy();
        $this->app->bind(ErpSyncDriver::class, fn () => $this->erpQueFalha());

        // Não rebenta com exceção não tratada; devolve FAILURE (código 1).
        $this->artisan('erp:sincronizar-clientes')->assertExitCode(1);

        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'Sync de clientes do ERP falhou'))->once();
    }

    public function test_sync_faturacao_com_ligacao_a_falhar_loga_e_devolve_failure(): void
    {
        Log::spy();
        $this->app->bind(ErpSyncDriver::class, fn () => $this->erpQueFalha());

        $this->artisan('erp:sincronizar-faturacao')->assertExitCode(1);

        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'Sync de faturação do ERP falhou'))->once();
    }

    public function test_agendamento_regista_os_dois_syncs_nas_horas_certas(): void
    {
        $eventos = app(Schedule::class)->events();

        $expressao = function (string $comando) use ($eventos): ?string {
            foreach ($eventos as $evento) {
                if (str_contains($evento->command ?? '', $comando)) {
                    return $evento->expression;
                }
            }

            return null;
        };

        // 08h/13h/19h — clientes ao minuto :00, faturação escalonada a :10 (nunca em simultâneo).
        $this->assertSame('0 8,13,19 * * *', $expressao('erp:sincronizar-clientes'));
        $this->assertSame('10 8,13,19 * * *', $expressao('erp:sincronizar-faturacao'));
    }

    public function test_sync_continua_idempotente_em_corrida_repetida(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver());

        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();
        $primeiro = Cliente::count();

        // 2.ª corrida com os mesmos id_erp (determinístico) → updateOrCreate atualiza, não duplica.
        $this->artisan('erp:sincronizar-clientes', ['--limit' => 10])->assertSuccessful();

        $this->assertGreaterThan(0, $primeiro);
        $this->assertSame($primeiro, Cliente::count());
    }
}
