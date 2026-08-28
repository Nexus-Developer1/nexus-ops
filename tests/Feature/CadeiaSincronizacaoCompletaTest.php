<?php

namespace Tests\Feature;

use App\Jobs\CadeiaSincronizacaoCompletaErp;
use App\Jobs\ResumoSincronizacaoCompletaErp;
use App\Jobs\SincronizarEtapaErp;
use App\Mail\ResultadoSincronizacaoErp;
use App\Models\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// A corrida COMPLETA (domingo 06:00 e `?completo=1`) em CADEIA — um job por etapa + resumo —
// porque o job único rebentava o timeout todos os domingos a meio da faturação. Cobre: a
// cadeia é despachada na ordem certa; cada etapa em cadeia ACUMULA em cache (sem auditar);
// o resumo junta tudo, audita UMA linha, atualiza o "último" e envia o email só no agendado;
// uma etapa interrompida dispara o resumo na mesma, com as seguintes como "não chegou a correr".
class CadeiaSincronizacaoCompletaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.driver' => 'fake', 'erp.email_sync' => 'suporte@nxs.pt']);
        Cache::flush();
    }

    public function test_despacha_as_cinco_etapas_completas_e_o_resumo_pela_ordem(): void
    {
        Bus::fake();

        CadeiaSincronizacaoCompletaErp::despachar('agendado-completo');

        Bus::assertChained([
            new SincronizarEtapaErp('clientes', true, 'agendado-completo', true),
            new SincronizarEtapaErp('equipamentos', true, 'agendado-completo', true),
            new SincronizarEtapaErp('artigos', true, 'agendado-completo', true),
            new SincronizarEtapaErp('dossiers', true, 'agendado-completo', true),
            new SincronizarEtapaErp('faturacao', true, 'agendado-completo', true),
            new ResumoSincronizacaoCompletaErp('agendado-completo'),
        ]);
    }

    public function test_etapa_em_cadeia_acumula_em_cache_e_nao_audita(): void
    {
        (new SincronizarEtapaErp('clientes', completo: true, origem: 'agendado-completo', emCadeia: true))->handle();

        $acumulado = Cache::get(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR);
        $this->assertSame('agendado-completo', $acumulado['origem']);
        $this->assertTrue($acumulado['resultados']['Clientes']['ok']);
        // O fecho é do resumo: nem "último resultado" nem auditoria por etapa.
        $this->assertNull(Cache::get('erp-sync:ultimo'));
        $this->assertSame(0, Auditoria::where('acao', 'sync_erp')->count());
        $this->assertNull(Cache::get('erp-sync:em-curso'));
    }

    public function test_resumo_junta_tudo_audita_uma_vez_e_envia_email_no_agendado(): void
    {
        Mail::fake();
        foreach (['clientes', 'equipamentos', 'artigos', 'dossiers', 'faturacao'] as $etapa) {
            (new SincronizarEtapaErp($etapa, completo: true, origem: 'agendado-completo', emCadeia: true))->handle();
        }

        (new ResumoSincronizacaoCompletaErp('agendado-completo'))->handle();

        $ultimo = Cache::get('erp-sync:ultimo');
        $this->assertSame('agendado-completo', $ultimo['origem']);
        $this->assertFalse($ultimo['falhou']);
        $this->assertSame(['Clientes', 'Equipamentos', 'Artigos', 'Dossiês', 'Faturação'], array_keys($ultimo['resultados']));

        $this->assertSame(1, Auditoria::where('acao', 'sync_erp')->count());
        $detalhe = Auditoria::latest('id')->first()->detalhe;
        $this->assertTrue($detalhe['completo']);
        $this->assertTrue($detalhe['agendado']);
        $this->assertFalse($detalhe['falhou']);

        Mail::assertSent(ResultadoSincronizacaoErp::class, fn ($m) => $m->hasTo('suporte@nxs.pt'));
        // O acumulador é consumido — a próxima cadeia começa limpa.
        $this->assertNull(Cache::get(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR));
    }

    public function test_resumo_pela_api_e_silencioso_por_email(): void
    {
        Mail::fake();
        (new SincronizarEtapaErp('clientes', completo: true, origem: 'api-completo', emCadeia: true))->handle();

        (new ResumoSincronizacaoCompletaErp('api-completo'))->handle();

        Mail::assertNothingSent();
        $this->assertSame('api-completo', Cache::get('erp-sync:ultimo')['origem']);
    }

    public function test_etapa_interrompida_dispara_o_resumo_com_as_seguintes_por_correr(): void
    {
        Queue::fake();
        Mail::fake();
        (new SincronizarEtapaErp('clientes', completo: true, origem: 'agendado-completo', emCadeia: true))->handle();

        // A faturação morre por timeout: o failed() acumula "interrompido" e dispara o resumo.
        (new SincronizarEtapaErp('dossiers', completo: true, origem: 'agendado-completo', emCadeia: true))
            ->failed(new \RuntimeException('has timed out'));
        Queue::assertPushed(ResumoSincronizacaoCompletaErp::class, fn ($j) => $j->origem === 'agendado-completo');

        (new ResumoSincronizacaoCompletaErp('agendado-completo'))->handle();

        $ultimo = Cache::get('erp-sync:ultimo');
        $this->assertTrue($ultimo['falhou']);
        $this->assertTrue($ultimo['resultados']['Clientes']['ok']);
        $this->assertStringContainsString('interrompido', $ultimo['resultados']['Dossiês']['detalhe']);
        $this->assertStringContainsString('não chegou a correr', $ultimo['resultados']['Faturação']['detalhe']);
        $this->assertStringContainsString('não chegou a correr', $ultimo['resultados']['Equipamentos']['detalhe']);

        Mail::assertSent(ResultadoSincronizacaoErp::class);
        $this->assertTrue(Auditoria::latest('id')->first()->detalhe['falhou']);
    }

    public function test_etapa_em_cadeia_com_lock_ocupado_fica_registada_como_ignorada(): void
    {
        $lock = Cache::lock('erp-sync', 60);
        $this->assertTrue($lock->get());

        (new SincronizarEtapaErp('artigos', completo: true, origem: 'agendado-completo', emCadeia: true))->handle();

        $this->assertStringContainsString('ignorada', Cache::get(CadeiaSincronizacaoCompletaErp::CACHE_ACUMULADOR)['resultados']['Artigos']['detalhe']);
        $lock->release();
    }
}
