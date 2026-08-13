<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Invariante §2 #3 do CLAUDE.md: isolamento por cliente imposto na CAMADA DE DADOS.
// Um cliente nunca vê dados de outro cliente — em qualquer query dos modelos.
class IsolamentoClienteTest extends TestCase
{
    use RefreshDatabase;

    /** Cria um cliente completo (local, equipamento, intervenção, relatório, contrato, evento). */
    private function clienteCompleto(string $nome): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/'.$cliente->id, 'data' => now(), 'estado' => 'finalizado']);
        Contrato::create(['numero' => 'C-'.$cliente->id, 'cliente_id' => $cliente->id, 'data_inicio' => now(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);
        EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => Carbon::parse('2026-07-06 09:00'), 'fim' => Carbon::parse('2026-07-06 10:00'),
            'cliente_id' => $cliente->id, 'equipamento_id' => $equip->id]);

        return $cliente;
    }

    private function utilizadorCliente(Cliente $cliente): User
    {
        return User::create(['nome' => 'Portal '.$cliente->id, 'email' => 'c'.$cliente->id.'@x.pt',
            'password' => 'x', 'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);
    }

    public function test_cliente_so_ve_os_seus_dados(): void
    {
        $a = $this->clienteCompleto('Cliente A');
        $b = $this->clienteCompleto('Cliente B');

        $this->actingAs($this->utilizadorCliente($a));

        // Cada modelo devolve apenas os registos do cliente A.
        $this->assertSame(1, Equipamento::count());
        $this->assertSame(1, Intervencao::count());
        $this->assertSame(1, Relatorio::count());
        $this->assertSame(1, Contrato::count());
        $this->assertSame(1, EventoAgenda::count());

        $this->assertSame($a->id, Contrato::first()->cliente_id);
        $this->assertSame($a->id, EventoAgenda::first()->cliente_id);

        // E não consegue carregar diretamente um registo do cliente B (404 via find).
        $relatorioB = Relatorio::withoutGlobalScopes()->where('numero', '2026/'.$b->id)->first();
        $this->assertNull(Relatorio::find($relatorioB->id));
    }

    public function test_admin_ve_todos_os_dados(): void
    {
        $this->clienteCompleto('Cliente A');
        $this->clienteCompleto('Cliente B');

        $admin = User::create(['nome' => 'Admin', 'email' => 'admin@x.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $this->actingAs($admin);

        $this->assertSame(2, Equipamento::count());
        $this->assertSame(2, Contrato::count());
        $this->assertSame(2, Relatorio::count());
    }

    public function test_sem_autenticacao_nao_filtra(): void
    {
        $this->clienteCompleto('Cliente A');
        $this->clienteCompleto('Cliente B');

        // Operações de sistema (jobs/console) sem auth veem tudo.
        $this->assertSame(2, Contrato::count());
        $this->assertSame(2, Equipamento::count());
    }
}
