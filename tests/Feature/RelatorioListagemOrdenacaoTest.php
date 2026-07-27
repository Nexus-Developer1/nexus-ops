<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Listagem;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Ordenação da listagem de relatórios (padrão da lista de clientes): mais recentes/antigos,
// cliente A→Z / Z→A (ignorando acentos) e nº de relatório (rascunhos sem número no fim).
class RelatorioListagemOrdenacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function relatorio(string $cliente, string $numero, string $data): Relatorio
    {
        $c = Cliente::create(['nome' => $cliente, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $c->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-' . $numero]);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => $data]);

        return Relatorio::create(['intervencao_id' => $interv->id, 'numero' => $numero, 'data' => $data, 'estado' => 'finalizado']);
    }

    public function test_ordena_por_data_cliente_e_numero(): void
    {
        $this->relatorio('Zebra Lda', '2026-0001', '2026-01-10');
        $this->relatorio('Águia SA', '2026-0003', '2026-03-10');   // acento não atrapalha o A→Z
        $this->relatorio('Mar Alto', '2026-0002', '2026-02-10');

        // Mais recentes (default) — Março, Fevereiro, Janeiro.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->assertSeeInOrder(['2026-0003', '2026-0002', '2026-0001']);

        // Mais antigos.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'antigos')
            ->assertSeeInOrder(['2026-0001', '2026-0002', '2026-0003']);

        // Cliente A→Z: Águia, Mar Alto, Zebra.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'cliente_asc')
            ->assertSeeInOrder(['guia SA', 'Mar Alto', 'Zebra Lda']); // 'Águia' sem o Á (o JSON do Livewire escapa acentos)

        // Cliente Z→A.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'cliente_desc')
            ->assertSeeInOrder(['Zebra Lda', 'Mar Alto', 'guia SA']);

        // Nº crescente / decrescente.
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'numero_asc')
            ->assertSeeInOrder(['2026-0001', '2026-0002', '2026-0003']);
        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'numero_desc')
            ->assertSeeInOrder(['2026-0003', '2026-0002', '2026-0001']);
    }

    public function test_rascunhos_sem_numero_ficam_no_fim_na_ordenacao_por_numero(): void
    {
        $this->relatorio('ACME', '2026-0009', '2026-05-01');
        $c = Cliente::create(['nome' => 'Beta', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $c->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-R']);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => '2026-06-01']);
        Relatorio::create(['intervencao_id' => $interv->id, 'numero' => null, 'data' => '2026-06-01', 'estado' => 'rascunho']);

        Livewire::actingAs($this->admin)->test(Listagem::class)
            ->set('ordenar', 'numero_asc')
            ->assertSeeInOrder(['2026-0009', 'Beta']); // o rascunho (sem nº, cliente Beta) vem depois
    }
}
