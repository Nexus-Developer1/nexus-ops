<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor;
use App\Livewire\Contratos\Ficha;
use App\Livewire\Contratos\Listagem;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContratoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Admin, 'ativo' => true,
        ]);
    }

    // Os modelos de faturação são semeados na migração; usamos o primeiro disponível.
    private function modeloFaturacaoId(): int
    {
        return ModeloFaturacao::query()->value('id');
    }

    private function clienteComEquipamento(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        return [$cliente, $equip];
    }

    public function test_listagem_mostra_contratos(): void
    {
        [$cliente] = $this->clienteComEquipamento();
        Contrato::create([
            'numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Ativo, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);

        $this->actingAs($this->admin())
            ->get('/contratos')
            ->assertOk()
            ->assertSee('2026/0001')
            ->assertSee('ACME');
    }

    public function test_cria_contrato_com_equipamentos_e_slas(): void
    {
        [$cliente, $equip] = $this->clienteComEquipamento();

        Livewire::actingAs($this->admin())
            ->test(Editor::class)
            ->set('numero', '2026/0042')
            ->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'full_service')
            ->set('modelo_faturacao_id', $this->modeloFaturacaoId())
            ->set('equipamentoIds', [$equip->id])
            ->set('slas', [['prioridade' => 'critica', 'tempo_resposta_horas' => 4, 'tempo_resolucao_horas' => 24, 'horario_cobertura' => '24x7']])
            ->call('guardar')
            ->assertHasNoErrors()
            ->assertRedirect();

        $contrato = Contrato::where('numero', '2026/0042')->firstOrFail();
        $this->assertSame(EstadoContrato::Rascunho, $contrato->estado);
        $this->assertCount(1, $contrato->equipamentos);
        $this->assertCount(1, $contrato->slas);
    }

    public function test_nao_ativa_sem_equipamento(): void
    {
        [$cliente] = $this->clienteComEquipamento();
        $contrato = Contrato::create([
            'numero' => '2026/0009', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Rascunho, $contrato->fresh()->estado);
    }

    public function test_ativa_contrato_com_equipamento(): void
    {
        [$cliente, $equip] = $this->clienteComEquipamento();
        $contrato = Contrato::create([
            'numero' => '2026/0010', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);

        Livewire::actingAs($this->admin())
            ->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
    }

    // ---- Faixas de escolha de equipamentos (evitam renderizar 1.053 checkboxes) ----

    /** Cria um cliente com $n equipamentos (WF-0001…). */
    private function clienteComN(int $n, string $nome = 'GRANDE'): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        for ($i = 1; $i <= $n; $i++) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'modelo' => 'NPW', 'numero_serie' => sprintf('WF-%04d', $i)]);
        }

        return $cliente;
    }

    public function test_faixa_por_contagem_ao_escolher_cliente(): void
    {
        $admin = $this->admin();
        $faixa = fn (int $n, string $nome) => Livewire::actingAs($admin)->test(Editor::class)
            ->call('selecionarCliente', $this->clienteComN($n, $nome)->id)
            ->get('faixaEquipamentos');

        $this->assertSame('auto', $faixa(10, 'C10'));
        $this->assertSame('lista', $faixa(11, 'C11'));
        $this->assertSame('lista', $faixa(50, 'C50'));
        $this->assertSame('pesquisa', $faixa(51, 'C51'));
    }

    public function test_faixa_lista_selecionar_todos_e_limpar_ate_50(): void
    {
        $cliente = $this->clienteComN(50); // faixa lista

        $c = Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('faixaEquipamentos', 'lista')
            ->assertSet('equipamentoIds', [])           // escolher cliente recomeça a cobertura
            ->call('selecionarTodosEquipamentos');

        $this->assertCount(50, $c->get('equipamentoIds')); // nunca mais que MAX_LISTA_CHECKBOXES

        $c->call('limparEquipamentos')->assertSet('equipamentoIds', []);
    }

    public function test_faixa_pesquisa_nao_carrega_todos_e_adiciona_por_pesquisa(): void
    {
        $cliente = $this->clienteComN(60);          // > 50 → pesquisa
        // Equipamento de OUTRO cliente (não deve ser adicionável).
        $outro = $this->clienteComN(1, 'OUTRO');
        $eqOutro = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $outro->id))->firstOrFail();
        $eqs = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))->orderBy('numero_serie')->pluck('id')->all();

        $c = Livewire::actingAs($this->admin())->test(Editor::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('faixaEquipamentos', 'pesquisa')
            ->assertSet('equipamentoIds', []);

        // Não renderiza os 60 checkboxes (sem lista) — só a pesquisa, vazia sem texto.
        $c->assertViewHas('equipamentos', fn ($r) => $r->isEmpty())
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->isEmpty())
            ->assertDontSeeHtml('wire:model="equipamentoIds"'); // não há checkbox list

        // Pesquisa filtra ao cliente.
        $c->set('equipamentoBusca', 'WF-0005')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->count() === 1 && $r->first()->numero_serie === 'WF-0005');

        // Adicionar → entra na cobertura; equipamento de outro cliente é rejeitado.
        $c->call('adicionarEquipamento', $eqs[0]);
        $this->assertSame([$eqs[0]], $c->get('equipamentoIds'));
        $c->call('adicionarEquipamento', $eqOutro->id);
        $this->assertSame([$eqs[0]], $c->get('equipamentoIds')); // inalterado

        // Remover.
        $c->call('removerEquipamento', $eqs[0])->assertSet('equipamentoIds', []);
    }

    public function test_editar_contrato_de_cliente_grande_mostra_cobertos_sem_carregar_todos(): void
    {
        $cliente = $this->clienteComN(120); // grande → pesquisa
        $cobertos = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->orderBy('numero_serie')->limit(3)->pluck('id')->all();

        $contrato = Contrato::create([
            'numero' => '2026/0500', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva', 'modelo_faturacao_id' => $this->modeloFaturacaoId(),
            'renovacao_automatica' => false, 'periodo_aviso_dias' => 30,
        ]);
        $contrato->equipamentos()->sync($cobertos);

        $c = Livewire::actingAs($this->admin())->test(Editor::class, ['contrato' => $contrato])
            ->assertSet('faixaEquipamentos', 'pesquisa')
            ->assertSet('equipamentoIds', $cobertos);

        // Mostra os 3 cobertos SEM carregar os 120 (checkbox list vazia).
        $c->assertViewHas('equipamentos', fn ($r) => $r->isEmpty())
            ->assertViewHas('equipamentosAdicionados', fn ($r) => $r->count() === 3);
    }

    public function test_guardar_grava_a_cobertura_escolhida(): void
    {
        $cliente = $this->clienteComN(60); // pesquisa
        $eqs = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))->orderBy('numero_serie')->pluck('id')->all();

        Livewire::actingAs($this->admin())->test(Editor::class)
            ->set('numero', '2026/0600')
            ->call('selecionarCliente', $cliente->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', $this->modeloFaturacaoId())
            ->call('adicionarEquipamento', $eqs[0])
            ->call('adicionarEquipamento', $eqs[1])
            ->call('guardar')
            ->assertHasNoErrors();

        $contrato = Contrato::where('numero', '2026/0600')->firstOrFail();
        $this->assertSame(
            collect([$eqs[0], $eqs[1]])->sort()->values()->all(),
            $contrato->equipamentos->pluck('id')->sort()->values()->all(),
        );
    }
}
