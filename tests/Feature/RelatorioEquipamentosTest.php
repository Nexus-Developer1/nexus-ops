<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Editor as ContratoEditor;
use App\Livewire\Contratos\Ficha as ContratoFicha;
use App\Livewire\Equipamentos\Ficha;
use App\Livewire\Relatorios\Listagem as RelatoriosListagem;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Um relatório pode cobrir vários equipamentos: principal (equipamento_id) + cobertos (pivot).
class RelatorioEquipamentosTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Equipamento, 2: Equipamento, 3: Equipamento} */
    private function cenario(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $mk = fn (string $sn) => Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);

        return [$admin, $mk('SN-PRINC'), $mk('SN-EX1'), $mk('SN-EX2')];
    }

    public function test_relatorio_cobre_varios_equipamentos_sem_duplicar_o_principal(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $principal->id)
            ->set('data', now()->toDateString())
            ->call('adicionarEquipamentoCoberto', $extra1->id)
            ->call('adicionarEquipamentoCoberto', $extra2->id)
            ->call('adicionarEquipamentoCoberto', $principal->id) // o principal é ignorado
            ->call('adicionarEquipamentoCoberto', $extra1->id)    // repetido é ignorado
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::where('equipamento_id', $principal->id)->firstOrFail();
        $cobertos = $interv->equipamentosCobertos()->pluck('equipamentos.id')->sort()->values()->all();

        $this->assertSame([$extra1->id, $extra2->id], $cobertos);
    }

    public function test_reabrir_rascunho_carrega_os_equipamentos_cobertos(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $principal->id)
            ->set('data', now()->toDateString())
            ->call('adicionarEquipamentoCoberto', $extra1->id)
            ->call('adicionarEquipamentoCoberto', $extra2->id)
            ->call('guardarRascunho');

        $relatorio = Intervencao::where('equipamento_id', $principal->id)->firstOrFail()->relatorio;

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet('equipamentosCobertos', [$extra1->id, $extra2->id]);
    }

    public function test_ficha_inclui_cobertos_sem_duplicar_e_mantem_ordenacao(): void
    {
        [$admin, $a, $b] = $this->cenario(); // $a = equipamento da ficha

        // Interv 1: A é o principal (mais antiga).
        $i1 = Intervencao::create(['equipamento_id' => $a->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()->subDays(3)]);
        // Interv 2: B é principal, A é coberto (mais recente).
        $i2 = Intervencao::create(['equipamento_id' => $b->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()->subDay()]);
        $i2->equipamentosCobertos()->attach($a->id);
        // Interv 3: A é principal E coberto (não pode duplicar) — data intermédia.
        $i3 = Intervencao::create(['equipamento_id' => $a->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()->subDays(2)]);
        $i3->equipamentosCobertos()->attach($a->id);

        Livewire::actingAs($admin)->test(Ficha::class, ['equipamento' => $a])
            ->assertViewHas('intervencoes', function ($lista) use ($i1, $i2, $i3) {
                $ids = $lista->pluck('id')->all();

                return count($ids) === 3                               // os 3, sem duplicar (i3 é principal E coberto)
                    && $ids === array_values(array_unique($ids))      // sem duplicados
                    && $ids === [$i2->id, $i3->id, $i1->id];           // ordenado por data_inicio desc
            });
    }

    public function test_modo_contrato_carrega_equipamentos_do_contrato_e_liga_contrato(): void
    {
        [$admin, $e1, $e2] = $this->cenario();
        $cliente = Cliente::firstOrFail();
        $contrato = Contrato::create([
            'numero' => '2026/7001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$e1->id, $e2->id]);

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $this->assertSame($contrato->id, $interv->contrato_id);

        // Os 2 equipamentos do contrato ficam no relatório (principal + coberto), sem assumir ordem.
        $todos = collect([$interv->equipamento_id])
            ->merge($interv->equipamentosCobertos()->pluck('equipamentos.id'))
            ->sort()->values()->all();
        $this->assertSame([$e1->id, $e2->id], $todos);
    }

    public function test_modo_individual_nao_liga_contrato(): void
    {
        [$admin, $e1] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'individual')
            ->set('equipamento_id', $e1->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertNull(Intervencao::where('equipamento_id', $e1->id)->firstOrFail()->contrato_id);
    }

    public function test_modo_contrato_exige_contrato(): void
    {
        [$admin, $e1] = $this->cenario();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->set('equipamento_id', $e1->id) // tem equipamento mas falta o contrato
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasErrors('contrato_id');
    }

    public function test_picker_exclui_rascunho_mas_inclui_apos_ativar(): void
    {
        [$admin, $e1, $e2, $e3] = $this->cenario();
        $cliente = Cliente::firstOrFail();

        // Cria o contrato como o utilizador faria (com plano, para depois poder ativar).
        Livewire::actingAs($admin)->test(ContratoEditor::class)
            ->set('numero', '2026/9001')
            ->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())
            ->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')
            ->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->set('periodo_aviso_dias', 30)
            ->set('equipamentoIds', [$e1->id, $e2->id, $e3->id])
            ->set('planos', [['equipamento_tipo' => 'ups', 'periodicidade' => 'trimestral', 'duracao_estimada_min' => 60]])
            ->call('guardar')
            ->assertHasNoErrors();

        $contrato = Contrato::where('numero', '2026/9001')->firstOrFail();
        $this->assertSame('rascunho', $contrato->estado->value); // nasce em rascunho

        // RASCUNHO → não aparece no picker.
        Livewire::actingAs($admin)->test(Novo::class)
            ->assertViewHas('contratos', fn ($c) => ! $c->contains('id', $contrato->id));

        // Ativar o contrato.
        Livewire::actingAs($admin)->test(ContratoFicha::class, ['contrato' => $contrato])->call('ativar');
        $this->assertSame('ativo', $contrato->fresh()->estado->value);

        // Agora (não-rascunho) → aparece no picker.
        Livewire::actingAs($admin)->test(Novo::class)
            ->assertViewHas('contratos', fn ($c) => $c->contains('id', $contrato->id));
    }

    public function test_trocar_de_modo_limpa_a_selecao_de_equipamento(): void
    {
        [$admin, $e1, $e2] = $this->cenario();
        $cliente = Cliente::firstOrFail();
        $contrato = Contrato::create([
            'numero' => '2026/6001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$e1->id, $e2->id]);

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id);

        // Carregou o equipamento do contrato.
        $this->assertNotNull($c->get('equipamento_id'));
        $this->assertNotEmpty($c->get('equipamentosCobertos'));

        // Trocar para individual → tudo limpo (campo vazio para preencher à mão).
        $c->call('definirModo', 'individual');
        $this->assertNull($c->get('equipamento_id'));
        $this->assertSame([], $c->get('equipamentosCobertos'));
        $this->assertNull($c->get('contrato_id'));
    }

    public function test_clicar_no_mesmo_modo_nao_limpa_a_selecao(): void
    {
        [$admin, $e1] = $this->cenario();

        // Default é 'individual'; com equipamento à mão, clicar 'individual' não apaga.
        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $e1->id)
            ->call('definirModo', 'individual');

        $this->assertSame($e1->id, $c->get('equipamento_id'));
    }

    public function test_pesquisa_de_equipamento_e_server_side(): void
    {
        [$admin, $e1] = $this->cenario(); // e1: SN-PRINC · Riello NPW

        $c = Livewire::actingAs($admin)->test(Novo::class);

        // Sem texto → não carrega nada (não traz os ~17k).
        $c->assertViewHas('equipamentosPrincipalFiltrados', fn ($r) => $r->isEmpty());

        // Com texto → traz os que batem certo (limit server-side).
        $c->set('equipamentoBusca', 'SN-PRINC')
            ->assertViewHas('equipamentosPrincipalFiltrados', fn ($r) => $r->contains('id', $e1->id));

        // Selecionar fixa o id.
        $c->call('selecionarEquipamentoPrincipal', $e1->id)
            ->assertSet('equipamento_id', $e1->id);

        // Escrever de novo desfaz a seleção (até escolher outro).
        $c->set('equipamentoBusca', 'outro')
            ->assertSet('equipamento_id', null);
    }

    public function test_comboboxes_tem_wire_key_distinto_e_equipamento_sem_filtrados(): void
    {
        [$admin] = $this->cenario();

        // Modo individual (default): comboboxes de equipamento server-side, SEM a expressão Alpine `filtrados`.
        $individual = Livewire::actingAs($admin)->test(Novo::class)->html();
        $this->assertStringContainsString('wire:key="combo-equip-principal"', $individual);
        $this->assertStringContainsString('wire:key="combo-equip-coberto"', $individual);
        $this->assertStringNotContainsString('filtrados', $individual); // equipamento não usa Alpine `filtrados`

        // Modo contrato: picker de contratos (o único com `filtrados`), com a sua própria key.
        $contrato = Livewire::actingAs($admin)->test(Novo::class)->call('definirModo', 'contrato')->html();
        $this->assertStringContainsString('wire:key="combo-contrato"', $contrato);
        // O `filtrados` do picker está no MESMO x-data que o define (key própria → o morph reinicializa o Alpine).
        $this->assertStringContainsString('get filtrados()', $contrato);
    }

    public function test_listagem_filtra_por_tipo_e_combina_com_estado(): void
    {
        [$admin, $e1, $e2] = $this->cenario();
        $cliente = Cliente::firstOrFail();
        $contrato = Contrato::create([
            'numero' => '2026/5001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        // Relatório DE CONTRATO (finalizado).
        $iC = Intervencao::create(['equipamento_id' => $e1->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $rC = Relatorio::create(['intervencao_id' => $iC->id, 'numero' => '2026/0001', 'data' => now(), 'estado' => 'finalizado']);

        // Relatório INDIVIDUAL (rascunho, sem contrato).
        $iI = Intervencao::create(['equipamento_id' => $e2->id, 'tipo' => 'corretiva', 'estado' => 'em_curso']);
        $rI = Relatorio::create(['intervencao_id' => $iI->id, 'numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        $ids = fn ($p) => $p->pluck('id')->all();

        // tipo='contrato' → só o de contrato.
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)->set('tipo', 'contrato')
            ->assertViewHas('relatorios', fn ($p) => in_array($rC->id, $ids($p), true) && ! in_array($rI->id, $ids($p), true));

        // tipo='individual' → só o individual.
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)->set('tipo', 'individual')
            ->assertViewHas('relatorios', fn ($p) => in_array($rI->id, $ids($p), true) && ! in_array($rC->id, $ids($p), true));

        // tipo='' → ambos (sem filtro de tipo).
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)
            ->assertViewHas('relatorios', fn ($p) => in_array($rC->id, $ids($p), true) && in_array($rI->id, $ids($p), true));

        // Combina com estado: de contrato + rascunho → nenhum (o de contrato é finalizado).
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)->set('tipo', 'contrato')->set('estado', 'rascunho')
            ->assertViewHas('relatorios', fn ($p) => ! in_array($rC->id, $ids($p), true) && ! in_array($rI->id, $ids($p), true));
    }
}
