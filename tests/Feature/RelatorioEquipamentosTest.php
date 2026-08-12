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

    // Cliente com N equipamentos (para as faixas 'lista' 11-50 e 'pesquisa' >50).
    private function clienteComN(int $n, string $nome = 'WALLFUTURE'): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        for ($i = 1; $i <= $n; $i++) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => sprintf('WF-%04d', $i)]);
        }

        return $cliente;
    }

    public function test_relatorio_cobre_todos_os_equipamentos_do_cliente(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();
        $cliente = Cliente::firstOrFail();

        // Escolher o cliente anexa TODOS os equipamentos dele (principal + cobertos), sem duplicar.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('equipamento_id')->firstOrFail();
        $todos = collect([$interv->equipamento_id])
            ->merge($interv->equipamentosCobertos()->pluck('equipamentos.id'))
            ->unique()->sort()->values()->all();

        $this->assertSame(
            collect([$principal->id, $extra1->id, $extra2->id])->sort()->values()->all(),
            $todos,
        );
    }

    public function test_reabrir_rascunho_carrega_os_equipamentos_cobertos(): void
    {
        [$admin] = $this->cenario();
        $cliente = Cliente::firstOrFail();

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->set('data', now()->toDateString());
        $cobertosEsperados = collect($c->get('equipamentosCobertos'))->sort()->values()->all();
        $c->call('guardarRascunho');

        $relatorio = Intervencao::whereNotNull('equipamento_id')->firstOrFail()->relatorio;

        $reaberto = Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio]);
        $this->assertSame(
            $cobertosEsperados,
            collect($reaberto->get('equipamentosCobertos'))->sort()->values()->all(),
        );
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
            ->call('selecionarTodosEquipamentos') // com 2+ o técnico escolhe — aqui marca ambos
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

    public function test_selecionar_cliente_anexa_todos_os_equipamentos_como_principal_e_cobertos(): void
    {
        [$admin, $principal, $extra1, $extra2] = $this->cenario();
        $cliente = Cliente::firstOrFail();

        // Equipamento de OUTRO cliente não deve ser anexado.
        $outroCliente = Cliente::create(['nome' => 'BETA', 'ativo' => true]);
        $outroLocal = Local::create(['cliente_id' => $outroCliente->id, 'designacao' => 'DC-B']);
        Equipamento::create(['local_id' => $outroLocal->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-BETA']);

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id);

        // Um principal + o resto cobertos = os 3 equipamentos do cliente (e só esses).
        $this->assertNotNull($c->get('equipamento_id'));
        $todos = collect([$c->get('equipamento_id')])->merge($c->get('equipamentosCobertos'))->sort()->values()->all();
        $this->assertSame(
            collect([$principal->id, $extra1->id, $extra2->id])->sort()->values()->all(),
            $todos,
        );
    }

    public function test_faixa_por_contagem_de_equipamentos(): void
    {
        [$admin] = $this->cenario();

        $faixa = function (int $n, string $nome) use ($admin) {
            $cliente = $this->clienteComN($n, $nome);

            return Livewire::actingAs($admin)->test(Novo::class)
                ->call('selecionarCliente', $cliente->id)
                ->get('faixaEquipamentos');
        };

        $this->assertSame('auto', $faixa(10, 'C10'));       // ≤10 → anexa todos
        $this->assertSame('lista', $faixa(11, 'C11'));      // 11 → lista de checkboxes
        $this->assertSame('lista', $faixa(50, 'C50'));      // 50 → ainda lista (limite superior)
        $this->assertSame('pesquisa', $faixa(51, 'C51'));   // 51 → pesquisa (NUNCA lista)
    }

    public function test_faixa_lista_nao_anexa_automaticamente_e_mostra_checkboxes(): void
    {
        [$admin] = $this->cenario();
        $cliente = $this->clienteComN(20); // 11-50 → lista

        $c = Livewire::actingAs($admin)->test(Novo::class)->call('selecionarCliente', $cliente->id);

        // Não anexa nada automaticamente (nada de montar fichas).
        $c->assertSet('faixaEquipamentos', 'lista')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);

        // Mostra os checkboxes + "Selecionar todos", NÃO a pesquisa, NÃO fichas.
        $c->assertSeeHtml('wire:click="alternarEquipamento(')
            ->assertSee('Selecionar todos')
            ->assertSee('WF-0005')                                // um nº de série na lista
            ->assertDontSeeHtml('wire:key="combo-equip-selecao"') // não é o caminho da pesquisa
            ->assertDontSeeHtml('wire:key="tab-ficha-');          // nenhuma ficha montada
    }

    public function test_faixa_lista_marcar_anexa_e_desmarcar_remove_com_promocao(): void
    {
        [$admin] = $this->cenario();
        $cliente = $this->clienteComN(20);
        $eqs = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->orderBy('numero_serie')->pluck('id')->all();

        $c = Livewire::actingAs($admin)->test(Novo::class)->call('selecionarCliente', $cliente->id);

        // Marcar A → principal; marcar B → coberto.
        $c->call('alternarEquipamento', $eqs[0])->assertSet('equipamento_id', $eqs[0]);
        $c->call('alternarEquipamento', $eqs[1]);
        $this->assertSame([$eqs[1]], $c->get('equipamentosCobertos'));

        // Desmarcar o principal (A) → promove B a principal.
        $c->call('alternarEquipamento', $eqs[0])
            ->assertSet('equipamento_id', $eqs[1])
            ->assertSet('equipamentosCobertos', []);

        // Desmarcar B → fica sem nada.
        $c->call('alternarEquipamento', $eqs[1])
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);
    }

    public function test_faixa_lista_selecionar_todos_anexa_todos_e_limpar(): void
    {
        [$admin] = $this->cenario();
        $cliente = $this->clienteComN(50); // limite superior da lista

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->call('selecionarTodosEquipamentos');

        // 50 anexados (1 principal + 49 cobertos) — nunca mais que MAX_LISTA_CHECKBOXES.
        $this->assertNotNull($c->get('equipamento_id'));
        $this->assertCount(49, $c->get('equipamentosCobertos'));

        // "Limpar seleção" → volta a zero.
        $c->call('limparEquipamentos')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);
    }

    public function test_faixa_pesquisa_filtra_so_ao_cliente_escolhido(): void
    {
        [$admin] = $this->cenario();               // ACME (SN-PRINC, SN-EX1, SN-EX2)
        $cliente = $this->clienteComN(60);          // > 50 → pesquisa (WF-0001..0060)

        $c = Livewire::actingAs($admin)->test(Novo::class)->call('selecionarCliente', $cliente->id)
            ->assertSet('faixaEquipamentos', 'pesquisa');

        // Pesquisa por um nº de série do cliente escolhido → aparece.
        $c->set('equipamentoBusca', 'WF-0003')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->count() === 1 && $r->first()->numero_serie === 'WF-0003');

        // Nº de série de OUTRO cliente (ACME) → NÃO aparece (filtrado ao cliente).
        $c->set('equipamentoBusca', 'SN-PRINC')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->isEmpty());
    }

    public function test_faixa_pesquisa_adicionar_anexa_e_rejeita_de_outro_cliente(): void
    {
        [$admin, $acmeEquip] = $this->cenario();    // equipamento de ACME
        $cliente = $this->clienteComN(60);          // > 50 → pesquisa
        $eqs = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->orderBy('numero_serie')->pluck('id')->all();

        $c = Livewire::actingAs($admin)->test(Novo::class)->call('selecionarCliente', $cliente->id);

        // 1.º adicionado → principal; 2.º → coberto.
        $c->call('adicionarEquipamento', $eqs[0])->assertSet('equipamento_id', $eqs[0]);
        $c->call('adicionarEquipamento', $eqs[1]);
        $this->assertSame([$eqs[1]], $c->get('equipamentosCobertos'));

        // Equipamento de OUTRO cliente é rejeitado (guarda whereHas do cliente).
        $c->call('adicionarEquipamento', $acmeEquip->id);
        $this->assertSame($eqs[0], $c->get('equipamento_id'));
        $this->assertSame([$eqs[1]], $c->get('equipamentosCobertos'));
    }

    public function test_cliente_com_mais_de_100_equipamentos_nao_rebenta_a_pagina(): void
    {
        [$admin] = $this->cenario();
        $cliente = $this->clienteComN(120); // como o WALLFUTURE real (1.053) — aqui 120 chega

        // Selecionar o cliente renderiza SEM exceção e NÃO monta as fichas (a causa do 500).
        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('faixaEquipamentos', 'pesquisa')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);

        // Renderiza o campo de PESQUISA, não centenas de fichas nem a lista de checkboxes.
        $c->assertSee('Adicionar equipamento')
            ->assertSeeHtml('wire:key="combo-equip-selecao"')
            ->assertDontSeeHtml('wire:click="alternarEquipamento(') // não é a lista
            ->assertDontSeeHtml('wire:key="tab-ficha-');            // nenhuma ficha/aba montada
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
            ->call('selecionarContrato', $contrato->id)
            ->call('selecionarTodosEquipamentos'); // com 2+ o técnico escolhe — marca ambos

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

    public function test_pesquisa_de_cliente_e_server_side(): void
    {
        [$admin] = $this->cenario(); // cliente ACME com equipamentos
        $cliente = Cliente::firstOrFail();

        $c = Livewire::actingAs($admin)->test(Novo::class);

        // Sem texto → não carrega nada (não traz os milhares de clientes).
        $c->assertViewHas('clientesFiltrados', fn ($r) => $r->isEmpty());

        // Com texto → traz os que batem certo (limit server-side).
        $c->set('clienteBusca', 'ACME')
            ->assertViewHas('clientesFiltrados', fn ($r) => $r->contains('id', $cliente->id));

        // Selecionar o cliente fixa o id E anexa os equipamentos dele.
        $c->call('selecionarCliente', $cliente->id)
            ->assertSet('cliente_id', $cliente->id);
        $this->assertNotNull($c->get('equipamento_id'));
    }

    public function test_comboboxes_tem_wire_key_distinto_e_cliente_sem_filtrados(): void
    {
        [$admin] = $this->cenario();

        // Modo individual (default): combobox de cliente server-side, SEM a expressão Alpine `filtrados`.
        $individual = Livewire::actingAs($admin)->test(Novo::class)->html();
        $this->assertStringContainsString('wire:key="combo-cliente"', $individual);
        $this->assertStringNotContainsString('filtrados', $individual); // cliente não usa Alpine `filtrados`

        // Modo contrato: picker de contratos (o único com `filtrados`), com a sua própria key.
        $contrato = Livewire::actingAs($admin)->test(Novo::class)->call('definirModo', 'contrato')->html();
        $this->assertStringContainsString('wire:key="combo-contrato"', $contrato);
        // O `filtrados` do picker está no MESMO x-data que o define (key própria → o morph reinicializa o Alpine).
        $this->assertStringContainsString('get filtrados()', $contrato);
    }

    public function test_editar_carrega_finalizado_mas_bloqueia_enviado(): void
    {
        [$admin, $e1] = $this->cenario();

        // Finalizado → o editor CARREGA (não redireciona) e traz os dados.
        $iF = Intervencao::create(['equipamento_id' => $e1->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $finalizado = $iF->relatorio()->create(['numero' => '2026/0100', 'data' => now(), 'estado' => 'finalizado']);

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $finalizado])
            ->assertNoRedirect()
            ->assertSet('relatorioId', $finalizado->id)
            ->assertSet('equipamento_id', $e1->id);

        // Enviado → também CARREGA (pedido da equipa: enviados são editáveis; o editor avisa
        // que é preciso reenviar) e regista o estado inicial para a etiqueta/aviso.
        $iE = Intervencao::create(['equipamento_id' => $e1->id, 'tipo' => 'preventiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $enviado = $iE->relatorio()->create(['numero' => '2026/0101', 'data' => now(), 'estado' => 'enviado']);

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $enviado])
            ->assertNoRedirect()
            ->assertSet('relatorioId', $enviado->id)
            ->assertSet('estadoInicial', 'enviado');
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

        // tipo='' → ambos (sem filtro de tipo). Limpo explicitamente: os filtros passaram
        // a persistir na sessão entre visitas (#[Session]) — o 'individual' acima ficaria.
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)->set('tipo', '')
            ->assertViewHas('relatorios', fn ($p) => in_array($rC->id, $ids($p), true) && in_array($rI->id, $ids($p), true));

        // Combina com estado: de contrato + rascunho → nenhum (o de contrato é finalizado).
        Livewire::actingAs($admin)->test(RelatoriosListagem::class)->set('tipo', 'contrato')->set('estado', 'rascunho')
            ->assertViewHas('relatorios', fn ($p) => ! in_array($rC->id, $ids($p), true) && ! in_array($rI->id, $ids($p), true));
    }

    // ---- Modo CONTRATO: mesmas faixas do individual, mas a fonte é o CONTRATO ----

    /** Contrato que cobre $nCobertos equipamentos; o cliente pode ter $extraNaoCobertos NÃO cobertos. */
    private function contratoComN(int $nCobertos, int $extraNaoCobertos = 0): Contrato
    {
        $cliente = Cliente::create(['nome' => 'CONTR', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);

        $cobertos = [];
        for ($i = 1; $i <= $nCobertos; $i++) {
            $cobertos[] = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => sprintf('CT-%04d', $i)])->id;
        }
        // Equipamentos do MESMO cliente mas NÃO cobertos pelo contrato (para provar o filtro ao contrato).
        for ($i = 1; $i <= $extraNaoCobertos; $i++) {
            Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => sprintf('NC-%04d', $i)]);
        }

        $contrato = Contrato::create([
            'numero' => '2026/' . (9000 + $nCobertos + $extraNaoCobertos), 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync($cobertos);

        return $contrato;
    }

    // O contrato pode cobrir muitos equipamentos e a intervenção ser só num: com 2+ nada é
    // anexado automaticamente (o técnico escolhe na lista); só com 1 (sem escolha) anexa direto.
    public function test_contrato_com_um_anexa_o_e_com_varios_o_tecnico_escolhe(): void
    {
        [$admin] = $this->cenario();

        // 3 equipamentos → lista de checkboxes, nada anexado.
        $tres = $this->contratoComN(3);
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $tres->id)
            ->assertSet('faixaEquipamentos', 'lista')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);

        // 1 equipamento → anexa-o (não há escolha a fazer).
        $um = $this->contratoComN(1);
        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $um->id)
            ->assertSet('faixaEquipamentos', 'auto');
        $this->assertNotNull($c->get('equipamento_id'));
    }

    public function test_contrato_faixa_lista_nao_anexa_e_selecionar_todos_ate_50(): void
    {
        [$admin] = $this->cenario();
        $contrato = $this->contratoComN(20); // 11-50 → lista

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->assertSet('faixaEquipamentos', 'lista')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);

        $c->call('selecionarTodosEquipamentos');
        $this->assertCount(20, collect([$c->get('equipamento_id')])->merge($c->get('equipamentosCobertos'))->filter()->all());
    }

    public function test_contrato_grande_nao_monta_fichas_e_mostra_pesquisa(): void
    {
        [$admin] = $this->cenario();
        $contrato = $this->contratoComN(60); // > 50 → pesquisa

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->assertSet('faixaEquipamentos', 'pesquisa')
            ->assertSet('equipamento_id', null)
            ->assertSet('equipamentosCobertos', []);

        // Não monta as fichas (a causa do 500); mostra a pesquisa.
        $c->assertViewHas('equipamentosLista', fn ($r) => $r->isEmpty())
            ->assertSeeHtml('wire:key="combo-equip-selecao"')
            ->assertDontSeeHtml('wire:key="tab-ficha-');
    }

    public function test_contrato_pesquisa_so_traz_equipamentos_do_contrato_nao_do_cliente(): void
    {
        [$admin] = $this->cenario();
        $contrato = $this->contratoComN(60, 5); // 60 cobertos + 5 do cliente NÃO cobertos

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id);

        // Coberto pelo contrato → aparece.
        $c->set('equipamentoBusca', 'CT-0003')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->count() === 1 && $r->first()->numero_serie === 'CT-0003');

        // Do mesmo cliente mas NÃO coberto → NÃO aparece (filtro ao contrato, não ao cliente).
        $c->set('equipamentoBusca', 'NC-0001')
            ->assertViewHas('equipamentosFiltrados', fn ($r) => $r->isEmpty());
    }

    public function test_contrato_escolher_equipamento_monta_a_ficha(): void
    {
        [$admin] = $this->cenario();
        $contrato = $this->contratoComN(60);
        $ids = Equipamento::whereHas('contratos', fn ($q) => $q->where('contratos.id', $contrato->id))
            ->orderBy('numero_serie')->pluck('id')->all();

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->call('adicionarEquipamento', $ids[0])
            ->assertSet('equipamento_id', $ids[0]); // 1.º = principal

        $c->assertSeeHtml('wire:key="tab-ficha-' . $ids[0] . '"'); // a ficha desse equipamento é montada
    }

    public function test_editar_relatorio_de_contrato_grande_carrega_so_os_cobertos(): void
    {
        [$admin] = $this->cenario();
        $contrato = $this->contratoComN(120); // grande → pesquisa
        $cobertos = Equipamento::whereHas('contratos', fn ($q) => $q->where('contratos.id', $contrato->id))
            ->orderBy('numero_serie')->limit(3)->pluck('id')->all();

        // Relatório de contrato que mede só 3 dos 120.
        $interv = Intervencao::create(['equipamento_id' => $cobertos[0], 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => now()]);
        $interv->equipamentosCobertos()->sync([$cobertos[1], $cobertos[2]]);
        $rel = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        $c = Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $rel])
            ->assertSet('faixaEquipamentos', 'pesquisa');

        // Carrega só os 3 gravados — NÃO os 120.
        $this->assertSame(
            collect($cobertos)->sort()->values()->all(),
            collect([$c->get('equipamento_id')])->merge($c->get('equipamentosCobertos'))->sort()->values()->all(),
        );
        $c->assertViewHas('equipamentosLista', fn ($r) => $r->isEmpty()); // não carregou a lista dos 120
    }

    // ---- Atalho por nº de série (modo individual) ----

    public function test_pesquisa_por_serie_e_global_entre_clientes(): void
    {
        [$admin] = $this->cenario(); // ACME (SN-PRINC, SN-EX1, SN-EX2)
        $outro = Cliente::create(['nome' => 'BETA', 'ativo' => true]);
        $lb = Local::create(['cliente_id' => $outro->id, 'designacao' => 'DC-B']);
        $eb = Equipamento::create(['local_id' => $lb->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-BETA']);

        $c = Livewire::actingAs($admin)->test(Novo::class);

        // Sem texto → vazio (não carrega tudo).
        $c->assertViewHas('equipamentosPorSerie', fn ($r) => $r->isEmpty());

        // Pesquisa por um SN de OUTRO cliente → aparece (a pesquisa é global, não filtrada ao cliente).
        $c->set('serieBusca', 'SN-BETA')
            ->assertViewHas('equipamentosPorSerie', fn ($r) => $r->count() === 1 && $r->first()->id === $eb->id);
    }

    public function test_selecionar_por_serie_resolve_o_cliente_e_fixa_principal(): void
    {
        [$admin, $princ] = $this->cenario();
        $acme = Cliente::firstOrFail();

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('modo', 'individual')
            ->call('selecionarPorSerie', $princ->id)
            ->assertSet('modo', 'individual')
            ->assertSet('cliente_id', $acme->id)      // cliente resolvido a partir do equipamento
            ->assertSet('equipamento_id', $princ->id); // o pesquisado fica principal
    }

    public function test_selecionar_por_serie_cliente_pequeno_anexa_todos_com_pesquisado_principal(): void
    {
        [$admin, $princ, $ex1, $ex2] = $this->cenario(); // 3 equipamentos → 'auto'

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarPorSerie', $ex1->id)
            ->assertSet('faixaEquipamentos', 'auto')
            ->assertSet('equipamento_id', $ex1->id); // pesquisado = principal (não o 1.º por nº de série)

        $todos = collect([$c->get('equipamento_id')])->merge($c->get('equipamentosCobertos'))->sort()->values()->all();
        $this->assertSame(collect([$princ->id, $ex1->id, $ex2->id])->sort()->values()->all(), $todos);
    }

    public function test_selecionar_por_serie_cliente_grande_fixa_so_o_principal(): void
    {
        [$admin] = $this->cenario();
        $cliente = $this->clienteComN(60); // > 50 → 'pesquisa'
        $equip = Equipamento::whereHas('local', fn ($q) => $q->where('cliente_id', $cliente->id))
            ->orderBy('numero_serie')->firstOrFail();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('selecionarPorSerie', $equip->id)
            ->assertSet('cliente_id', $cliente->id)
            ->assertSet('faixaEquipamentos', 'pesquisa')
            ->assertSet('equipamento_id', $equip->id)
            ->assertSet('equipamentosCobertos', []); // não anexa os 60
    }
}
