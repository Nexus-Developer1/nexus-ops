<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Alertas\Painel;
use App\Models\User;
use App\Services\Alertas\CertificadoTls;
use App\Services\Alertas\ServicoAlertas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Vigia do certificado HTTPS. A renovação deste servidor é MANUAL (validação por DNS à mão):
// o certbot corria e falhava todos os dias em silêncio, e só se daria por isso quando os
// browsers começassem a avisar os utilizadores. O aviso passa a aparecer onde a equipa já olha.
class AlertaCertificadoTest extends TestCase
{
    use RefreshDatabase;

    private function fingirCertificado(?int $dias): void
    {
        $this->app->instance(CertificadoTls::class, new class($dias) extends CertificadoTls
        {
            public function __construct(private ?int $dias) {}

            public function diasParaExpirar(?string $url = null): ?int
            {
                return $this->dias;
            }
        });
    }

    private function alertas()
    {
        return app(ServicoAlertas::class)->recolher()->where('tipo', 'certificado')->values();
    }

    public function test_avisa_quando_falta_pouco_e_cala_se_ha_folga(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');

        $this->fingirCertificado(60);      // dois meses de folga
        $this->assertCount(0, $this->alertas());

        $this->fingirCertificado(20);      // dentro do limiar (21 dias)
        $a = $this->alertas()->first();
        $this->assertSame('Certificado HTTPS expira em 20 dias', $a['titulo']);
        $this->assertSame('media', $a['severidade']);
        $this->assertStringContainsString('renovação deste servidor é MANUAL', $a['descricao']);
        $this->assertSame('certificado:2026-09-27', $a['chave']);
    }

    public function test_fica_alta_na_ultima_semana_e_grita_se_expirou(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');

        $this->fingirCertificado(5);
        $this->assertSame('alta', $this->alertas()->first()['severidade']);

        $this->fingirCertificado(-2);      // já expirado
        $a = $this->alertas()->first();
        $this->assertSame('Certificado HTTPS EXPIRADO', $a['titulo']);
        $this->assertSame('alta', $a['severidade']);
        $this->assertStringContainsString('aviso de insegurança', $a['descricao']);
    }

    public function test_sem_https_ou_sem_resposta_nao_inventa_alertas(): void
    {
        $this->fingirCertificado(null);    // ambiente local, ou não se conseguiu verificar
        $this->assertCount(0, $this->alertas());
    }

    public function test_aparece_no_painel_e_a_chave_muda_quando_se_renova(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        $this->fingirCertificado(10);
        $chave = $this->alertas()->first()['chave'];

        Livewire::actingAs($admin)->test(Painel::class)
            ->assertSee('Certificado HTTPS expira em 10 dias')
            ->call('concluir', $chave)
            ->assertDontSee('Certificado HTTPS expira em 10 dias');

        // Renovado: nova data de expiração → alerta novo, o "concluído" antigo não o esconde.
        $this->fingirCertificado(15);
        $this->assertCount(1, $this->alertas());
        $this->assertNotSame($chave, $this->alertas()->first()['chave']);
    }

    public function test_o_servico_real_devolve_null_sem_https(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->assertNull(app(CertificadoTls::class)->diasParaExpirar());
    }
}
