<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Equipamentos\Editar;
use App\Livewire\Equipamentos\Listagem;
use App\Models\Artigo;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\User;
use App\Services\Erp\ErpSyncDriver;
use App\Services\Erp\FakeErpDriver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EquipamentoEditarEliminarTest extends TestCase
{
    use RefreshDatabase;

    public function test_listagem_tem_editar_eliminar_e_confirmacao(): void
    {
        $equipamento = $this->equipamentoDeTeste();

        Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
            ->assertSeeHtml(route('equipamentos.editar', $equipamento))
            ->assertSee('Editar')->assertSee('Eliminar')->assertSeeHtml('wire:confirm=');
    }

    public function test_edita_manual_sem_perder_bancos_componentes_ou_ligacoes(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $atributos = ['bancos' => [['modelo' => 'BX', 'num_baterias' => 12]], 'componentes' => [['designacao' => 'Módulo', 'quantidade' => 2]], 'potencia_kva' => 10];
        $equipamento->update(['atributos' => $atributos, 'proxima_troca_baterias' => '2027-01-10']);
        Artigo::create(['id_erp' => 'ART-EDITAR', 'designacao' => 'UPS', 'familia' => 'UPS', 'faminome' => 'UPS BATERIAS']);

        Livewire::actingAs($this->tecnicoDeTeste())->test(Editar::class, ['equipamento' => $equipamento])
            ->assertSet('form.modelo', 'X40')
            ->set('form.modelo', ' Modelo corrigido ')->set('form.numero_serie', 'S-EDITADA')
            ->set('form.fabricante', 'AROS')->set('form.familia', 'UPS')
            ->set('form.estado', 'degradado')->set('form.data_instalacao', '2026-08-10')
            ->set('form.fim_garantia', '2028-08-10')->set('form.notas', '0')
            ->set('form.id_erp', 'FORJADO')->set('form.local_id', 999999)
            ->call('guardar')->assertHasNoErrors()->assertRedirect(route('ativos'));

        $atual = $equipamento->fresh();
        $this->assertSame('Modelo corrigido', $atual->modelo);
        $this->assertSame('S-EDITADA', $atual->numero_serie);
        $this->assertSame('AROS', $atual->fabricante);
        $this->assertSame('UPS BATERIAS', $atual->faminome);
        $this->assertSame('degradado', $atual->estado->value);
        $this->assertSame('2026-08-10', $atual->data_instalacao->toDateString());
        $this->assertSame('0', $atual->notas);
        $this->assertEquals($atributos, $atual->atributos);
        $this->assertSame('2027-01-10', $atual->proxima_troca_baterias->toDateString());
        $this->assertSame($equipamento->local_id, $atual->local_id);
        $this->assertNull($atual->id_erp);
        $this->assertDatabaseHas('auditoria', ['acao' => 'equipamento_editado', 'entidade_id' => $equipamento->id]);
    }

    public function test_phc_sem_cliente_permite_editar_dados_locais_mas_protege_origem(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $equipamento->update(['id_erp' => 'Mic23091346621,906000001', 'local_id' => null,
            'familia' => 'ORIGINAL', 'faminome' => 'Família original', 'data_instalacao' => '2025-01-01']);
        $antes = $equipamento->only(['id_erp', 'fabricante', 'modelo', 'numero_serie', 'familia', 'faminome']);

        $this->actingAs($this->tecnicoDeTeste())->get(route('equipamentos.editar', $equipamento))->assertOk();
        Livewire::test(Editar::class, ['equipamento' => $equipamento])
            ->assertSee('Sem cliente')->set('form.modelo', 'FORJADO')->set('form.fabricante', 'FORJADO')
            ->set('form.numero_serie', 'FORJADO')->set('form.familia', 'FORJADO')->set('form.data_instalacao', '2030-01-01')
            ->set('form.tipo', 'sistema')->set('form.estado', 'critico')
            ->set('form.cliente_final', 'Utilizador final')->set('form.localizacao_instalacao', 'Sala 2')
            ->call('guardar')->assertHasNoErrors();

        $atual = $equipamento->fresh();
        $this->assertSame($antes, $atual->only(array_keys($antes)));
        $this->assertSame('2025-01-01', $atual->data_instalacao->toDateString());
        $this->assertSame('sistema', $atual->tipo->value);
        $this->assertSame('critico', $atual->estado->value);
        $this->assertSame('Sala 2', $atual->localizacao_instalacao);
        $this->assertNull($atual->local_id);
    }

    public function test_valida_campos_e_familia_sem_gravar_alteracoes_parciais(): void
    {
        $equipamento = $this->equipamentoDeTeste();

        Livewire::actingAs($this->tecnicoDeTeste())->test(Editar::class, ['equipamento' => $equipamento])
            ->set('form.modelo', 'Não gravar')->set('form.tipo', 'invalido')->set('form.estado', 'invalido')
            ->set('form.familia', 'INEXISTENTE')->set('form.fim_garantia', 'ontem')
            ->call('guardar')->assertHasErrors(['form.tipo', 'form.estado', 'form.familia', 'form.fim_garantia']);

        $this->assertSame('X40', $equipamento->fresh()->modelo);
    }

    public function test_tipo_diversos_exige_descricao_e_preserva_atributos(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $equipamento->update(['atributos' => ['potencia_kva' => 10]]);
        $componente = Livewire::actingAs($this->tecnicoDeTeste())->test(Editar::class, ['equipamento' => $equipamento])
            ->set('form.tipo', 'diversos')->call('guardar')->assertHasErrors('form.tipo_descricao');
        $componente->set('form.tipo_descricao', 'Solução especial')->call('guardar')->assertHasNoErrors();
        $this->assertEquals(['potencia_kva' => 10, 'tipo_descricao' => 'Solução especial'], $equipamento->fresh()->atributos);
    }

    public function test_mantem_tipo_pdu_e_familia_legados(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $equipamento->update(['tipo' => 'pdu', 'familia' => 'ANTIGA', 'faminome' => 'Família antiga']);
        Livewire::actingAs($this->tecnicoDeTeste())->test(Editar::class, ['equipamento' => $equipamento])
            ->set('form.notas', 'Atualizado')->call('guardar')->assertHasNoErrors();
        $this->assertSame('pdu', $equipamento->fresh()->tipo->value);
        $this->assertSame('Família antiga', $equipamento->fresh()->faminome);
    }

    public function test_elimina_sem_apagar_fisicamente_e_regista_auditoria(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
            ->call('eliminar', $equipamento->id)->assertHasNoErrors()->assertDontSee($equipamento->numero_serie);
        $this->assertSoftDeleted($equipamento);
        $this->assertDatabaseHas('auditoria', ['acao' => 'equipamento_eliminado', 'entidade_id' => $equipamento->id]);
        $this->get(route('equipamentos.editar', $equipamento))->assertNotFound();
    }

    public function test_formulario_aberto_nao_altera_equipamento_entretanto_eliminado(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $componente = Livewire::actingAs($this->tecnicoDeTeste())->test(Editar::class, ['equipamento' => $equipamento]);
        $equipamento->delete();
        $this->assertSoftDeleted($equipamento);
        $this->expectException(ModelNotFoundException::class);
        $componente->call('guardar');
    }

    public function test_nao_elimina_equipamentos_com_intervencoes_arquivadas_ou_cobertos_por_relatorio(): void
    {
        $principal = $this->equipamentoDeTeste();
        $adicional = $this->equipamentoDeTeste();
        $intervencao = Intervencao::create(['equipamento_id' => $principal->id, 'tecnico_id' => $this->tecnicoDeTeste()->id,
            'tipo' => 'corretiva', 'estado' => 'planeada']);
        $intervencao->equipamentosCobertos()->attach($adicional->id);
        $intervencao->delete();

        foreach ([$principal, $adicional] as $equipamento) {
            Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
                ->call('eliminar', $equipamento->id)->assertHasErrors('eliminar');
            $this->assertNotSoftDeleted($equipamento);
        }
    }

    public function test_nao_elimina_equipamento_de_contrato(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $contrato = Contrato::create(['numero' => 'C-EDIT', 'cliente_id' => $equipamento->local->cliente_id,
            'data_inicio' => '2026-01-01', 'data_fim' => '2027-01-01', 'estado' => 'ativo', 'tipo' => 'preventiva']);
        $contrato->equipamentos()->attach($equipamento->id);
        Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
            ->call('eliminar', $equipamento->id)->assertHasErrors('eliminar')->assertSee('ligações a contratos');
        $this->assertNotSoftDeleted($equipamento);
    }

    public function test_nao_elimina_equipamentos_principais_ou_adicionais_de_agenda(): void
    {
        $principal = $this->equipamentoDeTeste();
        $adicional = $this->equipamentoDeTeste();
        $evento = EventoAgenda::withoutEvents(fn () => EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'Visita', 'inicio' => '2026-09-20 09:00', 'fim' => '2026-09-20 10:00',
            'estado' => 'planeado', 'equipamento_id' => $principal->id,
        ]));
        DB::table('evento_equipamentos')->insert(['evento_agenda_id' => $evento->id, 'equipamento_id' => $adicional->id]);
        foreach ([$principal, $adicional] as $equipamento) {
            Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
                ->call('eliminar', $equipamento->id)->assertHasErrors('eliminar');
            $this->assertNotSoftDeleted($equipamento);
        }
    }

    public function test_nao_elimina_com_bancos_associados_ou_alertas(): void
    {
        $pai = $this->equipamentoDeTeste();
        $filho = $this->equipamentoDeTeste();
        $filho->update(['equipamento_pai_id' => $pai->id]);
        $comAlerta = $this->equipamentoDeTeste();
        $comAlerta->alertasManutencao()->create(['data' => '2026-10-01', 'texto' => 'Revisão']);
        foreach ([$pai, $filho, $comAlerta] as $equipamento) {
            Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
                ->call('eliminar', $equipamento->id)->assertHasErrors('eliminar');
            $this->assertNotSoftDeleted($equipamento);
        }
    }

    public function test_sync_nao_ressuscita_equipamento_eliminado_na_listagem(): void
    {
        $this->app->bind(ErpSyncDriver::class, fn () => new FakeErpDriver);
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 1])->assertSuccessful();
        $equipamento = Equipamento::firstOrFail();
        Livewire::actingAs($this->tecnicoDeTeste())->test(Listagem::class)
            ->call('eliminar', $equipamento->id)->assertHasNoErrors();
        $this->artisan('erp:sincronizar-equipamentos', ['--limit' => 1, '--completo' => true])->assertSuccessful();
        $this->assertSoftDeleted($equipamento);
        $this->assertSame(1, Equipamento::withTrashed()->where('id_erp', $equipamento->id_erp)->count());
    }

    public function test_cliente_nao_pode_abrir_editor_nem_chamar_componentes_da_equipa(): void
    {
        $equipamento = $this->equipamentoDeTeste();
        $cliente = User::create(['nome' => 'Cliente', 'email' => 'cliente-editar@nexus.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'ativo' => true, 'cliente_id' => $equipamento->local->cliente_id]);
        $this->actingAs($cliente)->get(route('equipamentos.editar', $equipamento))->assertRedirect(route($cliente->rotaInicial()));
        Livewire::test(Editar::class, ['equipamento' => $equipamento])->assertForbidden();
        Livewire::test(Listagem::class)->assertForbidden();
        $this->assertNotSoftDeleted($equipamento);
    }
}
