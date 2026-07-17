<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// Ficha de medições (UPS): no modo contrato, uma ficha por equipamento coberto substitui a
// checklist genérica. Todos os campos são opcionais.
class FichaMedicaoRelatorioTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Contrato, 2: Equipamento, 3: Equipamento} */
    private function cenarioContrato(): array
    {
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $e1 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => 'SN-1', 'atributos' => ['num_baterias' => 40]]);
        $e2 = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'MST', 'numero_serie' => 'SN-2']);

        $contrato = Contrato::create([
            'numero' => '2026/7001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$e1->id, $e2->id]);

        return [$admin, $contrato, $e1, $e2];
    }

    public function test_modo_contrato_cria_uma_ficha_por_equipamento_pre_preenchida(): void
    {
        [$admin, $contrato, $e1, $e2] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            // Uma medição em cada → ambas as fichas têm conteúdo e são persistidas.
            ->set("fichas.{$e1->id}.verificacoes.acessibilidade.estado", 'ok')
            ->set("fichas.{$e2->id}.verificacoes.acessibilidade.estado", 'ok')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $this->assertSame(2, $interv->fichasMedicao()->count());

        // Pré-preenchida com os dados do equipamento (marca/modelo/série/baterias).
        $f1 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->firstOrFail();
        $this->assertSame('Riello', $f1->marca);
        $this->assertSame('NPW', $f1->modelo);
        $this->assertSame('SN-1', $f1->serie);
        $this->assertSame('40', $f1->baterias);
        $this->assertSame('ups', $f1->tipo_equipamento);

        // Sem checklist genérica no modo contrato.
        $this->assertSame(0, $interv->checklistEtapas()->count());
    }

    public function test_pre_preenchimento_visivel_no_estado_da_ficha(): void
    {
        [$admin, $contrato, $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->assertSet("fichas.{$e1->id}.marca", 'Riello')
            ->assertSet("fichas.{$e1->id}.serie", 'SN-1');
    }

    public function test_valores_preenchidos_sao_persistidos_e_vazios_ficam_null(): void
    {
        [$admin, $contrato, $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$e1->id}.ve_ln_l1", '230.5')
            ->set("fichas.{$e1->id}.ve_ln_l2", '')                 // vazio → null
            ->set("fichas.{$e1->id}.verificacoes.acessibilidade.estado", 'ok')
            ->set("fichas.{$e1->id}.verificacoes.limpeza.estado", 'nok')
            ->set("fichas.{$e1->id}.ups_modo_normal", 'ok')
            ->set("fichas.{$e1->id}.teste_descarga.inicio.vbat_pos", '13.6')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $f = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->firstOrFail();

        $this->assertEquals(230.5, (float) $f->ve_ln_l1);
        $this->assertNull($f->ve_ln_l2);
        $this->assertSame('ok', $f->verificacoes['acessibilidade']['estado']);
        $this->assertSame('nok', $f->verificacoes['limpeza']['estado']);
        $this->assertSame('ok', $f->ups_modo_normal);
        $this->assertEquals(13.6, (float) $f->teste_descarga['inicio']['vbat_pos']);
    }

    public function test_reabrir_carrega_a_ficha_persistida(): void
    {
        [$admin, $contrato, $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$e1->id}.notas_finais", 'Tudo OK')
            ->call('guardarRascunho');

        $relatorio = Intervencao::whereNotNull('contrato_id')->firstOrFail()->relatorio;

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet("fichas.{$e1->id}.notas_finais", 'Tudo OK');
    }

    public function test_recomendacao_e_prioridade_sao_por_equipamento(): void
    {
        [$admin, $contrato, $e1, $e2] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            // Recomendações distintas por equipamento (sem qualquer outra medição).
            ->set("fichas.{$e1->id}.recomendacao", 'Substituir baterias')
            ->set("fichas.{$e1->id}.prioridade", 'Alta')
            ->set("fichas.{$e2->id}.recomendacao", 'Rever ventilação')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();

        // Uma recomendação basta para a ficha persistir (mesmo sem medições).
        $f1 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->firstOrFail();
        $this->assertSame('Substituir baterias', $f1->recomendacao);
        $this->assertSame('Alta', $f1->prioridade);

        // Prioridade default (Normal) quando não escolhida mas há recomendação.
        $f2 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e2->id)->firstOrFail();
        $this->assertSame('Rever ventilação', $f2->recomendacao);
        $this->assertSame('Normal', $f2->prioridade);
    }

    public function test_recomendacao_reabre_no_editor(): void
    {
        [$admin, $contrato, $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$e1->id}.recomendacao", 'Substituir baterias em 2027')
            ->set("fichas.{$e1->id}.prioridade", 'Alta')
            ->call('guardarRascunho');

        $relatorio = Intervencao::whereNotNull('contrato_id')->firstOrFail()->relatorio;

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet("fichas.{$e1->id}.recomendacao", 'Substituir baterias em 2027')
            ->assertSet("fichas.{$e1->id}.prioridade", 'Alta');
    }

    public function test_remover_equipamento_coberto_apaga_a_sua_ficha(): void
    {
        [$admin, $contrato, $e1, $e2] = $this->cenarioContrato();

        $c = Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            // Ambas com conteúdo → 2 fichas persistidas.
            ->set("fichas.{$e1->id}.notas_finais", 'ficha principal')
            ->set("fichas.{$e2->id}.notas_finais", 'ficha coberto')
            ->call('guardarRascunho');

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $this->assertSame(2, $interv->fichasMedicao()->count());

        // Remove o coberto e volta a gravar → a sua ficha desaparece.
        $relatorio = $interv->relatorio;
        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('removerEquipamentoDoRelatorio', $e2->id)
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertSame(1, $interv->fichasMedicao()->count());
        $this->assertSame(0, FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e2->id)->count());
    }

    public function test_ficha_vazia_nao_e_persistida(): void
    {
        [$admin, $contrato] = $this->cenarioContrato();

        // Contrato escolhido, nenhuma medição introduzida → nenhuma ficha gravada.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();
        $this->assertSame(0, $interv->fichasMedicao()->count());
    }

    public function test_ciclo_gravar_reabrir_manter_medicoes_e_regravar(): void
    {
        // 3 equipamentos no contrato: preenche 2, deixa o 3.º vazio.
        [$admin, $contrato, $e1, $e2] = $this->cenarioContrato();
        $e3 = Equipamento::create(['local_id' => $e1->local_id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'DLD', 'numero_serie' => 'SN-3']);
        $contrato->equipamentos()->syncWithoutDetaching([$e3->id]);

        // 1) Gravar rascunho com 2 fichas preenchidas (e1, e2); e3 fica vazio.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$e1->id}.ve_ln_l1", '230.1')
            ->set("fichas.{$e1->id}.notas_finais", 'ficha e1')
            ->set("fichas.{$e2->id}.verificacoes.limpeza.estado", 'ok')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::whereNotNull('contrato_id')->firstOrFail();

        // Só as 2 preenchidas são persistidas; a vazia (e3) não cria registo.
        $this->assertSame(2, $interv->fichasMedicao()->count());
        $this->assertSame(0, FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e3->id)->count());

        $idF1 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->value('id');
        $idF2 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e2->id)->value('id');

        // 2) Reabrir: as 2 preenchidas mantêm valores; a 3.ª cai em estruturaVazia (sem rebentar).
        $relatorio = $interv->relatorio;
        $comp = Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->assertSet("fichas.{$e1->id}.notas_finais", 'ficha e1')
            ->assertSet("fichas.{$e2->id}.verificacoes.limpeza.estado", 'ok')
            ->assertSet("fichas.{$e3->id}.notas_finais", ''); // 3.º equipamento sem ficha na BD → vazio

        // 3) Gravar de novo sem mexer → continua com 2 fichas, mesmos IDs (update in-place), valores intactos.
        $comp->call('guardarRascunho')->assertHasNoErrors();

        $this->assertSame(2, $interv->fichasMedicao()->count());
        $this->assertSame($idF1, FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->value('id'));
        $this->assertSame($idF2, FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e2->id)->value('id'));

        $f1 = FichaMedicao::where('intervencao_id', $interv->id)->where('equipamento_id', $e1->id)->firstOrFail();
        $this->assertEquals(230.1, (float) $f1->ve_ln_l1);
        $this->assertSame('ficha e1', $f1->notas_finais);
    }

    public function test_render_http_formulario_novo_individual_compila_o_blade(): void
    {
        [$admin] = $this->cenarioContrato();

        // GET real → compila o novo.blade.php inteiro e renderiza o modo individual (default).
        $this->actingAs($admin)
            ->get(route('relatorios.novo'))
            ->assertOk()
            ->assertSee('Dados Gerais')
            ->assertSee('Registo Fotográfico')
            ->assertDontSee('Checklist'); // checklist genérica foi removida (fichas em ambos os modos)
    }

    public function test_render_http_formulario_contrato_mostra_abas_e_ficha(): void
    {
        [$admin, $contrato, $e1, $e2] = $this->cenarioContrato();

        // Relatório de contrato (rascunho): a intervenção tem contrato_id → modo contrato.
        $interv = Intervencao::create([
            'equipamento_id' => $e1->id, 'contrato_id' => $contrato->id,
            'tipo' => 'preventiva', 'estado' => 'em_curso', 'data_inicio' => now(),
        ]);
        $interv->equipamentosCobertos()->attach($e2->id);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => null, 'data' => now(), 'estado' => 'rascunho']);

        // GET real → renderiza os ramos do modo contrato: aba por equipamento + <x-relatorios.ficha-ups>.
        $resp = $this->actingAs($admin)
            ->get(route('relatorios.editar', $relatorio))
            ->assertOk();

        $resp->assertSee($e1->numero_serie);   // separador/aba do equipamento principal
        $resp->assertSee($e2->numero_serie);   // separador/aba do coberto
        $resp->assertSee('Medições elétricas'); // conteúdo da ficha-ups (só existe no componente)
        $resp->assertSee('Teste de descarga');
    }

    public function test_finalizar_contrato_com_ficha_gera_relatorio_e_pdf(): void
    {
        \Illuminate\Support\Facades\Storage::fake();
        [$admin, $contrato, $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'contrato')
            ->call('selecionarContrato', $contrato->id)
            ->set('data', now()->toDateString())
            ->set('hora_inicio', '10:00')
            ->set('hora_fim', '11:00')
            ->set("fichas.{$e1->id}.ve_ln_l1", '230.00')
            ->call('finalizar')
            ->assertHasNoErrors();

        $relatorio = \App\Models\Relatorio::firstOrFail();
        $this->assertSame(\App\Enums\EstadoRelatorio::Finalizado, $relatorio->estado);
        $this->assertNotNull($relatorio->numero);         // número atribuído antes do PDF
        $this->assertNotNull($relatorio->fresh()->pdf_path); // PDF gerado (queue sync) sem 500
    }

    public function test_remover_foto_ja_carregada_apaga_o_anexo_e_o_ficheiro(): void
    {
        \Illuminate\Support\Facades\Storage::fake();
        [$admin, , $e1] = $this->cenarioContrato();

        // Carrega uma foto e guarda o rascunho → cria o anexo no storage.
        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'individual')
            ->set('equipamento_id', $e1->id)
            ->set('data', now()->toDateString())
            ->set('fotos', [\Illuminate\Http\UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg')])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::where('equipamento_id', $e1->id)->firstOrFail();
        $anexo = $interv->anexos()->firstOrFail();
        \Illuminate\Support\Facades\Storage::disk()->assertExists($anexo->storage_key);

        // Reabre o relatório e remove a foto (o botão que no telemóvel estava invisível por ser só-hover).
        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $interv->relatorio])
            ->call('removerAnexoExistente', $anexo->id)
            ->assertHasNoErrors();

        $this->assertSame(0, $interv->anexos()->count());
        \Illuminate\Support\Facades\Storage::disk()->assertMissing($anexo->storage_key);
    }

    public function test_modo_individual_cria_ficha_por_equipamento_e_sem_checklist(): void
    {
        [$admin, , $e1] = $this->cenarioContrato();

        Livewire::actingAs($admin)->test(Novo::class)
            ->call('definirModo', 'individual')
            ->set('equipamento_id', $e1->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$e1->id}.ve_ln_l1", '230.00') // medição → ficha persiste
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $interv = Intervencao::where('equipamento_id', $e1->id)->firstOrFail();
        $this->assertSame(1, $interv->fichasMedicao()->count());   // individual também tem ficha
        $this->assertSame(0, $interv->checklistEtapas()->count()); // relatório novo nasce sem checklist
    }

    public function test_reabrir_e_gravar_relatorio_legado_nao_apaga_a_checklist(): void
    {
        [$admin, , $e1] = $this->cenarioContrato();

        // Relatório LEGADO: intervenção individual com checklist antiga (etapa + item) e SEM fichas.
        $interv = Intervencao::create(['equipamento_id' => $e1->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now()]);
        $etapa = $interv->checklistEtapas()->create(['titulo' => 'Inspeção', 'ordem' => 0]);
        $etapa->itens()->create(['intervencao_id' => $interv->id, 'descricao' => 'Verificar ventoinhas', 'concluido' => true, 'ordem' => 0]);
        $relatorio = $interv->relatorio()->create(['numero' => '2026/0500', 'data' => now(), 'estado' => 'finalizado']);

        // Reabrir no editor e gravar (sem tocar na checklist).
        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        // A checklist legada CONTINUA intacta na BD — nada foi apagado.
        $this->assertSame(1, $interv->checklistEtapas()->count());
        $this->assertDatabaseHas('checklist_itens', ['intervencao_id' => $interv->id, 'descricao' => 'Verificar ventoinhas']);
    }

    public function test_reabrir_e_gravar_nao_apaga_diagnostico_legado(): void
    {
        [$admin, , $e1] = $this->cenarioContrato();

        // Relatório LEGADO com diagnóstico técnico antigo (estado geral, carga, etc.) —
        // semeado direto na BD, porque o campo saiu do fillable (nada o escreve na app).
        $interv = Intervencao::create([
            'equipamento_id' => $e1->id, 'tipo' => 'corretiva', 'estado' => 'concluida', 'data_inicio' => now(),
        ]);
        DB::table('intervencoes')->where('id', $interv->id)->update([
            'diagnostico' => json_encode(['estado_geral' => 'Degradado', 'carga' => '62', 'tensao_entrada' => '230', 'prioridade' => 'Alta']),
        ]);
        $relatorio = $interv->relatorio()->create(['numero' => '2026/0600', 'data' => now(), 'estado' => 'finalizado']);

        Livewire::actingAs($admin)->test(Novo::class, ['relatorio' => $relatorio])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        // O diagnóstico legado CONTINUA na BD, intacto (a app já não escreve neste campo).
        $d = json_decode(DB::table('intervencoes')->where('id', $interv->id)->value('diagnostico'), true);
        $this->assertSame('Degradado', $d['estado_geral']);
        $this->assertSame('62', $d['carga']);
        $this->assertSame('230', $d['tensao_entrada']);
        $this->assertSame('Alta', $d['prioridade']);
    }
}
