<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\FichaMedicao;
use App\Models\Local;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Ficha de Verificações SADEI: equipamentos do tipo "incendio" têm ficha técnica própria
// (espelho da folha oficial) no formulário e no PDF; os restantes mantêm a ficha UPS.
class RelatorioFichaSadeiTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(string $tipo): array
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede', 'morada' => 'Rua X']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => $tipo, 'estado' => 'operacional', 'fabricante' => 'Bosch', 'modelo' => 'FPA-5000', 'numero_serie' => 'SAD-1']);

        return [$tecnico, $equip];
    }

    public function test_formulario_de_incendio_mostra_a_ficha_sadei_e_grava(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        $c = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString());

        // O separador do equipamento mostra a ficha SADEI (não a de medições UPS).
        $c->assertSee('Central de deteção e extinção de incêndio')
            ->assertSee('Verificação trimestral')
            ->assertDontSee('Teste de descarga');

        $c->set("fichas.{$equip->id}.sadei.tipo_manutencao", 'semestral')
            ->set("fichas.{$equip->id}.sadei.central.limpeza.estado", 'ok')
            ->set("fichas.{$equip->id}.sadei.central.limpeza.nota", 'Sem poeira')
            ->set("fichas.{$equip->id}.sadei.aspiracao.filtro.estado", 'na')
            ->set("fichas.{$equip->id}.sadei.semestral.mangueiras.estado", 'ko')
            ->set("fichas.{$equip->id}.sadei.num_sensores", '12')
            ->set("fichas.{$equip->id}.sadei.tipo_agente", 'NOVEC 1230')
            ->set("fichas.{$equip->id}.sadei.cilindros.0.identificacao", 'CIL-01')
            ->set("fichas.{$equip->id}.sadei.cilindros.0.estado", 'ok')
            ->set("fichas.{$equip->id}.sadei.final_automatico", 'ok')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertSame('semestral', $ficha->sadei['tipo_manutencao']);
        $this->assertSame('ok', $ficha->sadei['central']['limpeza']['estado']);
        $this->assertSame('Sem poeira', $ficha->sadei['central']['limpeza']['nota']);
        $this->assertSame('na', $ficha->sadei['aspiracao']['filtro']['estado']);
        $this->assertSame('ko', $ficha->sadei['semestral']['mangueiras']['estado']);
        $this->assertSame('12', $ficha->sadei['num_sensores']);
        $this->assertCount(1, $ficha->sadei['cilindros']); // só a linha preenchida (vazias descartadas)
        $this->assertSame('CIL-01', $ficha->sadei['cilindros'][0]['identificacao']);
        $this->assertSame('ok', $ficha->sadei['cilindros'][0]['estado']);
        $this->assertSame('ok', $ficha->sadei['final_automatico']);
        // Pré-preenchimento da identificação a partir do equipamento.
        $this->assertSame('Bosch', $ficha->marca);
        // A ficha regista o TIPO real do equipamento (era 'ups' hardcoded).
        $this->assertSame('incendio', $ficha->tipo_equipamento);
    }

    // O tipo de manutenção preenche automaticamente com N\A as verificações que NÃO se aplicam:
    // trimestral → semestral + anual; semestral → anual. Alargar o período limpa o que o
    // automatismo pôs a N\A; escolhas manuais item a item nunca se perdem.
    public function test_tipo_de_manutencao_preenche_na_as_verificacoes_nao_aplicaveis(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        $c = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id);

        // Trimestral: semestral e anual ficam TODAS a N\A; a trimestral fica por preencher.
        $c->set("fichas.{$equip->id}.sadei.tipo_manutencao", 'trimestral')
            ->assertSet("fichas.{$equip->id}.sadei.semestral.mangueiras.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.semestral.rotinas_trimestrais.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.anual.baterias.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.trimestral.acessos.estado", '');

        // Alargar para anual: o que o automatismo pôs a N\A volta a ficar por preencher.
        $c->set("fichas.{$equip->id}.sadei.tipo_manutencao", 'anual')
            ->assertSet("fichas.{$equip->id}.sadei.semestral.mangueiras.estado", '')
            ->assertSet("fichas.{$equip->id}.sadei.anual.baterias.estado", '');

        // Semestral: só a anual fica a N\A — e uma escolha manual na semestral mantém-se.
        $c->set("fichas.{$equip->id}.sadei.semestral.mangueiras.estado", 'ko')
            ->set("fichas.{$equip->id}.sadei.tipo_manutencao", 'semestral')
            ->assertSet("fichas.{$equip->id}.sadei.semestral.mangueiras.estado", 'ko')
            ->assertSet("fichas.{$equip->id}.sadei.anual.baterias.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.trimestral.acessos.estado", '');
    }

    // "Sistema de deteção": escolher o sistema em uso (Aspiração ou Detecção) preenche logo
    // com N\A o que não está a ser utilizado — o outro item e a secção dele.
    public function test_escolher_o_sistema_de_detecao_preenche_na_o_nao_utilizado(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        $c = Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id);

        // Detecção OK (sistema por sensores em uso) → Aspiração fica a N\A e a secção
        // "Sistema de aspiração" preenche-se toda a N\A; a dos sensores fica por preencher.
        $c->set("fichas.{$equip->id}.sadei.detecao.detecao.estado", 'ok')
            ->assertSet("fichas.{$equip->id}.sadei.detecao.aspiracao.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.aspiracao.filtro.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.aspiracao.sem_avarias.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.sensores.opticos_fumo.estado", '');

        // Afinal também há aspiração: marcar Aspiração OK limpa a secção auto-preenchida.
        $c->set("fichas.{$equip->id}.sadei.detecao.aspiracao.estado", 'ok')
            ->assertSet("fichas.{$equip->id}.sadei.aspiracao.filtro.estado", '');

        // Marcar Aspiração a N\A diretamente volta a preencher a secção — mas uma escolha
        // manual já feita numa linha impede a limpeza automática ao reverter.
        $c->set("fichas.{$equip->id}.sadei.detecao.aspiracao.estado", 'na')
            ->assertSet("fichas.{$equip->id}.sadei.aspiracao.ligacoes.estado", 'na');
    }

    public function test_estado_forjado_fora_da_whitelist_e_descartado(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            // 'na' não é válido na central (só OK/KO) e '<script>' nunca é um estado.
            ->set("fichas.{$equip->id}.sadei.central.limpeza.estado", 'na')
            ->set("fichas.{$equip->id}.sadei.central.baterias.estado", '<script>')
            ->set("fichas.{$equip->id}.sadei.final_automatico", 'ok')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertNull($ficha->sadei['central']['limpeza']['estado']);
        $this->assertNull($ficha->sadei['central']['baterias']['estado']);
        $this->assertSame('ok', $ficha->sadei['final_automatico']);
    }

    public function test_ficha_ups_continua_igual_e_sem_sadei(): void
    {
        [$tecnico, $equip] = $this->cenario('ups');

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->assertSee('Teste de descarga')
            ->assertDontSee('Central de deteção e extinção de incêndio')
            ->set("fichas.{$equip->id}.ve_ln_l1", '231.4')
            // Payload forjado: SADEI numa UPS (a secção nem existe no formulário) → descartado.
            ->set("fichas.{$equip->id}.sadei.final_automatico", 'ok')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertNull($ficha->sadei); // fichas UPS nunca gravam bloco SADEI (nem forjado)
    }

    public function test_payloads_hostis_nao_rebentam_nem_incham_a_ficha(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        // sadei como string (em vez de array) + nota gigante + valor não-escalar: nada
        // rebenta (500) e a nota é truncada a 2000 caracteres.
        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.sadei.central.limpeza.estado", 'ok')
            ->set("fichas.{$equip->id}.sadei.central.limpeza.nota", str_repeat('A', 50000))
            ->set("fichas.{$equip->id}.sadei.num_sensores", ['a' => 'b'])
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $ficha = FichaMedicao::firstOrFail();
        $this->assertSame(2000, mb_strlen($ficha->sadei['central']['limpeza']['nota']));
        $this->assertNull($ficha->sadei['num_sensores']); // não-escalar descartado

        // sadei inteiro como string → ignorado sem erro (grava null, resto da ficha vive).
        $ficha->delete();
        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set("fichas.{$equip->id}.notas_finais", 'ok')
            ->set("fichas.{$equip->id}.sadei", 'lixo')
            ->call('guardarRascunho')
            ->assertHasNoErrors();

        $this->assertNull(FichaMedicao::firstOrFail()->sadei);
    }

    public function test_pdf_de_incendio_usa_a_ficha_sadei(): void
    {
        [$tecnico, $equip] = $this->cenario('incendio');

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tecnicoIds', [$tecnico->id])
            ->set("fichas.{$equip->id}.sadei.central.limpeza.estado", 'ok')
            ->set("fichas.{$equip->id}.sadei.trimestral.acessos.estado", 'ok')
            ->call('finalizar')
            ->assertHasNoErrors();

        $relatorio = \App\Models\Relatorio::firstOrFail();
        $html = view('pdf.relatorio', ['relatorio' => $relatorio->load('intervencao.fichasMedicao'), 'fotos' => []])->render();

        $this->assertStringContainsString('Ficha de Verificações SADEI', $html);
        $this->assertStringContainsString('Botoneira ativação', $html);
        $this->assertStringContainsString('Dados dos cilindros', $html);
        $this->assertStringNotContainsString('Ficha de Medições — UPS', $html);
        $this->assertStringNotContainsString('Teste de descarga', $html);
    }
}
