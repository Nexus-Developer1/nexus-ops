<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Encomendas\Listagem;
use App\Models\Dossier;
use App\Models\User;
use App\Services\Erp\DossierErp;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Conferência com o PHC a cada sincronização de dossiês: apanha os que já lá não existem
// (órfãos, que ficavam cá para sempre sem ninguém dar por isso) e regista o que foi ALTERADO
// do lado do PHC, em vez de reescrever em silêncio.
class DossiersConferenciaPhcTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<array<string, mixed>> $linhas */
    private function fingirPhc(array $linhas): void
    {
        // Estende o fake do projeto (que já cumpre a interface toda) e manda só nos dossiês.
        $driver = new class($linhas) extends FakeErpDriver
        {
            /** @param list<array<string, mixed>> $linhas */
            public function __construct(private array $linhas) {}

            public function obterDossiers(?int $limite = null): iterable
            {
                foreach (array_slice($this->linhas, 0, $limite ?? count($this->linhas)) as $l) {
                    yield new DossierErp(
                        idErp: $l['id'],
                        ndos: $l['ndos'] ?? 3,
                        nmdos: $l['nmdos'] ?? 'Proposta',
                        obrano: $l['obrano'] ?? 1,
                        data: $l['data'] ?? '2026-09-01',
                        ano: $l['ano'] ?? 2026,
                        clienteNo: $l['clienteNo'] ?? '10',
                        nome: $l['nome'] ?? 'ACME',
                        totalDebito: $l['total'] ?? 100.0,
                        fechada: $l['fechada'] ?? false,
                        uRelat: $l['uRelat'] ?? null,
                    );
                }
            }
        };

        $this->app->instance(ErpSyncDriver::class, $driver);
    }

    public function test_marca_os_que_desapareceram_do_phc_e_desmarca_quando_voltam(): void
    {
        $this->fingirPhc([['id' => 'A'], ['id' => 'B']]);
        $this->artisan('erp:sincronizar-dossiers')->assertSuccessful();
        $this->assertSame(2, Dossier::count());

        // O B foi apagado no PHC: fica cá, mas marcado (nunca se apaga um espelho do ERP).
        $this->fingirPhc([['id' => 'A']]);
        $this->artisan('erp:sincronizar-dossiers')
            ->expectsOutputToContain('1 ausentes do PHC')
            ->assertSuccessful();

        $this->assertSame(2, Dossier::count());
        $this->assertNull(Dossier::where('id_erp', 'A')->value('ausente_do_erp_em'));
        $this->assertNotNull(Dossier::where('id_erp', 'B')->value('ausente_do_erp_em'));
        $this->assertTrue(Dossier::where('id_erp', 'B')->first()->ausenteDoErp());

        // Reapareceu (afinal era um engano do lado do PHC) → a marca sai.
        $this->fingirPhc([['id' => 'A'], ['id' => 'B']]);
        $this->artisan('erp:sincronizar-dossiers')->expectsOutputToContain('1 reencontrados');
        $this->assertNull(Dossier::where('id_erp', 'B')->value('ausente_do_erp_em'));
    }

    public function test_regista_o_que_mudou_no_phc(): void
    {
        Carbon::setTestNow('2026-09-03 10:00:00');
        $this->fingirPhc([['id' => 'A', 'total' => 100.0, 'nome' => 'ACME', 'fechada' => false]]);
        $this->artisan('erp:sincronizar-dossiers');
        $this->assertNull(Dossier::where('id_erp', 'A')->value('alterado_erp_em')); // criado ≠ alterado

        $this->fingirPhc([['id' => 'A', 'total' => 250.5, 'nome' => 'ACME II', 'fechada' => true]]);
        $this->artisan('erp:sincronizar-dossiers')->expectsOutputToContain('1 atualizados');

        $d = Dossier::where('id_erp', 'A')->firstOrFail();
        $this->assertSame('2026-09-03 10:00:00', $d->alterado_erp_em->toDateTimeString());
        $this->assertSame(['nome', 'total_debito', 'fechada'], array_keys($d->alteracoes_erp));
        $this->assertSame(['de' => 'ACME', 'para' => 'ACME II'], $d->alteracoes_erp['nome']);
        $this->assertSame('não', $d->alteracoes_erp['fechada']['de']);
        $this->assertSame('sim', $d->alteracoes_erp['fechada']['para']);
        $this->assertContains('Cliente: ACME → ACME II', $d->alteracoesLegiveis());

        // Correr outra vez sem mudanças no PHC não mexe no carimbo (fica o da última alteração).
        $this->artisan('erp:sincronizar-dossiers')->expectsOutputToContain('1 iguais');
        $this->assertSame('2026-09-03 10:00:00', $d->fresh()->alterado_erp_em->toDateTimeString());
    }

    public function test_nao_confere_em_corridas_parciais_nem_com_o_phc_vazio(): void
    {
        $this->fingirPhc([['id' => 'A'], ['id' => 'B']]);
        $this->artisan('erp:sincronizar-dossiers');

        // Com --limit o que falta é o que não foi pedido — marcar seria um falso alarme.
        $this->fingirPhc([['id' => 'A'], ['id' => 'B']]);
        $this->artisan('erp:sincronizar-dossiers', ['--limit' => 1])
            ->expectsOutputToContain('conferência com o PHC saltada: corrida parcial');
        $this->assertSame(0, Dossier::whereNotNull('ausente_do_erp_em')->count());

        // PHC sem nada (query partida/tabela vazia) → não marca a tabela inteira.
        $this->fingirPhc([]);
        $this->artisan('erp:sincronizar-dossiers')
            ->expectsOutputToContain('conferência com o PHC saltada: o PHC não devolveu dossiês');
        $this->assertSame(0, Dossier::whereNotNull('ausente_do_erp_em')->count());
    }

    public function test_listagem_marca_e_filtra(): void
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);

        Dossier::create(['id_erp' => 'A', 'ndos' => 3, 'obrano' => 11, 'nome' => 'ACME', 'ano' => 2026, 'fechada' => false]);
        Dossier::create(['id_erp' => 'B', 'ndos' => 3, 'obrano' => 22, 'nome' => 'BETA', 'ano' => 2026, 'fechada' => false,
            'ausente_do_erp_em' => now()]);
        Dossier::create(['id_erp' => 'C', 'ndos' => 3, 'obrano' => 33, 'nome' => 'CENA', 'ano' => 2026, 'fechada' => false,
            'alterado_erp_em' => now()->subDay(), 'alteracoes_erp' => ['nome' => ['de' => 'X', 'para' => 'CENA']]]);

        Livewire::actingAs($admin)->test(Listagem::class)
            ->assertSee('Fora do PHC')
            ->assertSee('Alterado')
            ->set('phc', 'ausente')
            ->assertSee('BETA')->assertDontSee('ACME')->assertDontSee('CENA')
            ->set('phc', 'alterado')
            ->assertSee('CENA')->assertDontSee('BETA')
            ->set('phc', '')
            ->assertSee('ACME')->assertSee('BETA')->assertSee('CENA');
    }
}
