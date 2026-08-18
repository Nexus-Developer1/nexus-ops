<?php

namespace Tests\Feature;

use App\Jobs\SincronizarErp;
use App\Jobs\SincronizarEtapaErp;
use App\Models\Auditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// API de sincronização PHC → Nexus (routes/api.php): chave partilhada (fail-closed), disparos
// para a fila (202) com 409 se já há sync em curso, GET e POST aceites, /estado a ler o marcador,
// o último resultado e a auditoria; a etapa única corre o comando certo com o lock partilhado.
class ApiSincronizacaoTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-de-teste-muito-secreta';

    protected function setUp(): void
    {
        parent::setUp();
        config(['api.chave' => self::CHAVE, 'erp.driver' => 'fake']);
        Cache::flush();
    }

    private function comChave(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.self::CHAVE);
    }

    // ---- Autenticação ----

    public function test_sem_chave_configurada_a_api_esta_desligada(): void
    {
        config(['api.chave' => '']);

        $this->getJson('/api/sync/estado')->assertStatus(503);
        $this->comChave()->getJson('/api/sync/estado')->assertStatus(503); // mesmo com header
    }

    public function test_sem_chave_ou_chave_errada_da_401_em_json(): void
    {
        $this->getJson('/api/sync/estado')->assertUnauthorized()->assertJsonStructure(['mensagem']);
        $this->withHeader('Authorization', 'Bearer errada')->getJson('/api/sync/estado')->assertUnauthorized();
        $this->withHeader('X-Api-Key', 'errada')->getJson('/api/sync/tudo')->assertUnauthorized();
    }

    public function test_chave_por_bearer_ou_x_api_key(): void
    {
        $this->comChave()->getJson('/api/sync/estado')->assertOk();
        $this->withHeader('X-Api-Key', self::CHAVE)->getJson('/api/sync/estado')->assertOk();
    }

    // ---- Disparos ----

    public function test_tudo_vai_para_a_fila_com_origem_api_em_get_e_post(): void
    {
        Queue::fake();

        $this->comChave()->getJson('/api/sync/tudo')
            ->assertStatus(202)
            ->assertJsonPath('completo', false)
            ->assertJsonStructure(['mensagem', 'pedido_em']);
        $this->comChave()->postJson('/api/sync/tudo?completo=1')
            ->assertStatus(202)
            ->assertJsonPath('completo', true);

        Queue::assertPushed(SincronizarErp::class, 2);
        Queue::assertPushed(SincronizarErp::class, fn ($j) => $j->origem === 'api' && ! $j->agendado && ! $j->completo);
        Queue::assertPushed(SincronizarErp::class, fn ($j) => $j->origem === 'api' && $j->completo);
    }

    public function test_etapa_unica_vai_para_a_fila_e_etapa_desconhecida_da_404(): void
    {
        Queue::fake();

        foreach (['clientes', 'equipamentos', 'artigos', 'dossiers', 'faturacao'] as $etapa) {
            $this->comChave()->postJson('/api/sync/'.$etapa)->assertStatus(202);
        }
        Queue::assertPushed(SincronizarEtapaErp::class, 5);
        Queue::assertPushed(SincronizarEtapaErp::class, fn ($j) => $j->etapa === 'faturacao' && $j->origem === 'api');

        $this->comChave()->postJson('/api/sync/marcianos')->assertNotFound();
        Queue::assertPushed(SincronizarEtapaErp::class, 5); // nada a mais
    }

    public function test_sync_em_curso_da_409_e_nao_empilha(): void
    {
        Queue::fake();
        SincronizarErp::marcarEmCurso('agendado', ['Clientes']);

        $this->comChave()->postJson('/api/sync/tudo')
            ->assertStatus(409)
            ->assertJsonPath('em_curso.origem', 'agendado');
        $this->comChave()->postJson('/api/sync/clientes')->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_sem_ligacao_ao_phc_da_503_no_disparo(): void
    {
        Queue::fake();
        config(['erp.driver' => '']);

        $this->comChave()->postJson('/api/sync/tudo')->assertStatus(503);
        Queue::assertNothingPushed();
        // O /estado continua a responder (diz que a ligação não está configurada).
        $this->comChave()->getJson('/api/sync/estado')->assertOk()->assertJsonPath('ligacao_phc', false);
    }

    // ---- Estado ----

    public function test_estado_mostra_em_curso_ultimo_e_ultimas_corridas(): void
    {
        SincronizarErp::registarUltimo(['Clientes' => ['ok' => true, 'detalhe' => '3 criados']], false, 'api');
        Auditoria::create(['acao' => 'sync_erp', 'detalhe' => ['origem' => 'api', 'etapa' => 'Clientes', 'falhou' => false, 'resultados' => ['Clientes' => '3 criados']]]);
        Auditoria::create(['acao' => 'sync_erp', 'detalhe' => ['agendado' => true, 'falhou' => true, 'resultados' => ['Faturação' => 'falhou']]]);

        $r = $this->comChave()->getJson('/api/sync/estado')->assertOk();
        $r->assertJsonPath('ligacao_phc', true)
            ->assertJsonPath('em_curso', null)
            ->assertJsonPath('ultimo.origem', 'api')
            ->assertJsonPath('ultimo.resultados.Clientes.detalhe', '3 criados')
            ->assertJsonCount(2, 'ultimas_corridas')
            ->assertJsonPath('ultimas_corridas.0.origem', 'agendado') // mais recente primeiro
            ->assertJsonPath('ultimas_corridas.0.falhou', true)
            ->assertJsonPath('ultimas_corridas.1.origem', 'api')
            ->assertJsonPath('etapas_disponiveis', ['clientes', 'equipamentos', 'artigos', 'dossiers', 'faturacao']);

        SincronizarErp::marcarEmCurso('api', ['Equipamentos']);
        $this->comChave()->getJson('/api/sync/estado')->assertJsonPath('em_curso.etapas.0', 'Equipamentos');
    }

    // ---- O job de etapa a sério (driver fake) ----

    public function test_job_de_etapa_corre_o_comando_e_regista_resultado_e_auditoria(): void
    {
        (new SincronizarEtapaErp('clientes', false, 'api'))->handle();

        $ultimo = Cache::get('erp-sync:ultimo');
        $this->assertSame('api', $ultimo['origem']);
        $this->assertFalse($ultimo['falhou']);
        $this->assertArrayHasKey('Clientes', $ultimo['resultados']);
        $this->assertTrue($ultimo['resultados']['Clientes']['ok']);
        $this->assertNull(Cache::get('erp-sync:em-curso')); // desmarcado no fim

        $this->assertDatabaseHas('auditoria', ['acao' => 'sync_erp']);
        $this->assertSame('Clientes', Auditoria::latest('id')->first()->detalhe['etapa']);
    }

    public function test_job_de_etapa_respeita_o_lock_partilhado(): void
    {
        $lock = Cache::lock('erp-sync', 60);
        $this->assertTrue($lock->get());

        (new SincronizarEtapaErp('clientes', false, 'api'))->handle();

        // Não correu: sem último resultado, sem auditoria.
        $this->assertNull(Cache::get('erp-sync:ultimo'));
        $this->assertSame(0, Auditoria::where('acao', 'sync_erp')->count());
        $lock->release();
    }

    // ---- O job encadeado continua igual para os chamadores antigos ----

    public function test_job_encadeado_sem_origem_continua_a_funcionar_e_marca_em_curso(): void
    {
        // Construtor antigo (botão do dashboard): origem vazia → "dashboard".
        (new SincronizarErp)->handle();

        $ultimo = Cache::get('erp-sync:ultimo');
        $this->assertSame('dashboard', $ultimo['origem']);
        $this->assertNull(Cache::get('erp-sync:em-curso'));
        $this->assertSame('dashboard', Auditoria::latest('id')->first()->detalhe['origem']);
    }
}
