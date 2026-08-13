<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Botão "PDF" da listagem -> rota relatorios.pdf: gera e serve o PDF no disco
// CONFIGURADO (FILESYSTEM_DISK), sem instanciar o cliente S3 (regressão da region).
class PdfRelatorioTest extends TestCase
{
    use RefreshDatabase;

    public function test_botao_pdf_gera_e_serve_o_pdf_no_disco_configurado(): void
    {
        $admin = User::create([
            'nome' => 'Admin Teste', 'email' => 'admin.pdf@teste.pt', 'password' => 'password',
            'papel' => PapelUtilizador::Admin, 'ativo' => true,
        ]);

        $cliente = Cliente::create(['nome' => 'Cliente PDF', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida',
        ]);
        // Sem pdf_path: a rota gera o PDF on-demand (exercita GeradorRelatorio + Storage).
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9100',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $resp = $this->actingAs($admin)->get(route('relatorios.pdf', $relatorio));

        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $resp->getContent());

        $relatorio->refresh();
        $this->assertNotNull($relatorio->pdf_path);
        $this->assertTrue(Storage::disk()->exists($relatorio->pdf_path));

        // Limpa o ficheiro físico gerado (não coberto pelo RefreshDatabase).
        Storage::disk()->delete($relatorio->pdf_path);
    }

    public function test_pdf_mostra_so_o_nome_do_cliente_e_a_sede_como_local(): void
    {
        $cliente = Cliente::create([
            'nome' => 'ACME Lda', 'nif' => '500100200',
            'morada' => 'Rua do Teste 10', 'codpost' => '1000-001 LISBOA',
            'telefone' => '210000000', 'tlmvl' => '966000000',
            'email' => 'geral@acme.pt', 'ativo' => true,
        ]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9101',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Do cliente aparece SÓ o nome — NIF, contactos e email saíram do relatório.
        $this->assertStringContainsString('ACME Lda', $html);
        $this->assertStringNotContainsString('NIF', $html);
        $this->assertStringNotContainsString('Tel.', $html);
        $this->assertStringNotContainsString('Tlm.', $html);
        $this->assertStringNotContainsString('geral@acme.pt', $html);

        // Local = sede da empresa (morada do ERP), NUNCA o local da intervenção.
        $this->assertStringContainsString('Rua do Teste 10 · 1000-001 LISBOA', $html);
        $this->assertStringNotContainsString('>Sala<', $html);
        $this->assertStringNotContainsString('null', $html);
    }

    public function test_pdf_local_usa_o_cliente_final_quando_o_equipamento_o_tem(): void
    {
        $cliente = Cliente::create(['nome' => 'Parceiro Lda', 'morada' => 'Rua da Sede 1', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Instalação principal']);
        $equipamento = Equipamento::create([
            'local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional',
            'cliente_final' => 'Hospital Central',
        ]);
        $intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9102',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Cliente final tem prioridade sobre a sede no campo Local.
        $this->assertStringContainsString('Hospital Central', $html);
        $this->assertStringNotContainsString('Rua da Sede 1', $html);
        $this->assertStringNotContainsString('Instalação principal', $html);
    }

    public function test_pdf_separa_pagina_tecnica_e_respeita_quebras_no_trabalho_realizado(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SN-77']);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equipamento->id, 'tipo' => 'preventiva', 'estado' => 'concluida',
            'trabalho_realizado' => "Substituição de baterias.\nTeste de autonomia OK.",
        ]);
        $relatorio = Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => '2026/9103',
            'data' => now(), 'estado' => EstadoRelatorio::Finalizado,
        ]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        // Sem conteúdo técnico (nem checklist, nem extras, nem fichas), a página técnica
        // NÃO existe — uma div vazia com page-break deixava uma página em branco.
        // (procura-se a class= no body; o seletor CSS no <head> existe sempre)
        $this->assertStringNotContainsString('class="pagina-tecnica"', $html);
        // A identificação do equipamento (S/N, fabricante, tipo) saiu do relatório.
        $this->assertStringNotContainsString('SN-77', $html);

        // As quebras de linha escritas pelo técnico chegam ao HTML e o CSS preserva-as.
        $this->assertStringContainsString("Substituição de baterias.\nTeste de autonomia OK.", $html);
        $this->assertStringContainsString('white-space: pre-line', $html);

        // Com checklist (relatório legado), a página técnica volta a existir, em página nova.
        $etapa = $intervencao->checklistEtapas()->create(['titulo' => 'Inspeção', 'ordem' => 0]);
        $etapa->itens()->create(['intervencao_id' => $intervencao->id, 'descricao' => 'Verificar ventoinhas', 'concluido' => true, 'ordem' => 0]);
        $htmlComChecklist = view('pdf.relatorio', ['relatorio' => $relatorio->fresh(), 'fotos' => []])->render();

        $this->assertStringContainsString('class="pagina-tecnica"', $htmlComChecklist);
        $this->assertStringContainsString('page-break-before: always', $htmlComChecklist);
    }

    public function test_pdf_mostra_contrato_quando_existe_e_omite_quando_individual(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $contrato = Contrato::create([
            'numero' => '2026/0001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        // Relatório DE CONTRATO → mostra a linha do contrato.
        $iContrato = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $rContrato = Relatorio::create(['intervencao_id' => $iContrato->id, 'numero' => '2026/9200', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $htmlContrato = view('pdf.relatorio', ['relatorio' => $rContrato, 'fotos' => []])->render();

        $this->assertStringContainsString('Contrato', $htmlContrato);
        $this->assertStringContainsString('2026/0001', $htmlContrato);

        // Relatório INDIVIDUAL (sem contrato) → não mostra contrato nem "null".
        $iIndividual = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida']);
        $rIndividual = Relatorio::create(['intervencao_id' => $iIndividual->id, 'numero' => '2026/9201', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $htmlIndividual = view('pdf.relatorio', ['relatorio' => $rIndividual, 'fotos' => []])->render();

        $this->assertStringNotContainsString('2026/0001', $htmlIndividual); // nº do contrato não aparece
        $this->assertStringNotContainsString('null', $htmlIndividual);
        // A etiqueta "Contrato" não surge no individual (só existe nessa linha).
        $this->assertStringNotContainsString('>Contrato<', $htmlIndividual);
    }

    public function test_pdf_mostra_horario_escrito_pelo_tecnico(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        // Datas e horas ESCRITAS no formulário — intervenção de VÁRIOS dias com horário.
        $interv = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida',
            'data_inicio' => '2026-07-27', 'data_fim' => '2026-07-28 12:15:00',
            'hora_inicio' => '09:30', 'hora_fim' => '12:15']);
        $relatorio = Relatorio::create(['intervencao_id' => $interv->id, 'numero' => '2026/9300', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);

        $html = view('pdf.relatorio', ['relatorio' => $relatorio, 'fotos' => []])->render();

        $this->assertStringContainsString('27/07/2026 · 09:30', $html); // início: data + hora do formulário
        $this->assertStringContainsString('28/07/2026 · 12:15', $html); // término: data + hora do formulário
        $this->assertStringNotContainsString('00:00', $html);           // fim do "data com hora fantasma"

        // Sem horas nem término: mostra as datas (término = mesmo dia), nunca "00:00".
        $semHoras = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'corretiva', 'estado' => 'concluida',
            'data_inicio' => '2026-07-27']);
        $rSemHoras = Relatorio::create(['intervencao_id' => $semHoras->id, 'numero' => '2026/9301', 'data' => now(), 'estado' => EstadoRelatorio::Finalizado]);
        $htmlSem = view('pdf.relatorio', ['relatorio' => $rSemHoras, 'fotos' => []])->render();

        $this->assertStringContainsString('Término', $htmlSem);
        $this->assertStringNotContainsString('00:00', $htmlSem);
    }

    public function test_finalizar_grava_termino_real_e_nao_o_instante_da_redacao(): void
    {
        Queue::fake();
        $admin = User::create(['nome' => 'Admin', 'email' => 'a2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $tec = User::create(['nome' => 'Téc', 'email' => 't2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME2', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);

        Livewire::actingAs($admin)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('tipo', 'corretiva')
            ->set('data', '2026-07-20')
            ->set('data_fim', '2026-07-22')
            ->set('hora_inicio', '22:00')
            ->set('hora_fim', '06:00') // fim "menor" que o início é válido porque termina noutro dia
            ->set('tecnicoIds', [$tec->id])
            ->set('finalizarComFichasVazias', true) // confirma o aviso de fichas vazias (Vaga 1)
            ->call('finalizar')
            ->assertHasNoErrors();

        $interv = Intervencao::latest('id')->firstOrFail();
        $this->assertSame('2026-07-20', $interv->data_inicio->toDateString());
        $this->assertSame('2026-07-22 06:00', $interv->data_fim->format('Y-m-d H:i')); // término REAL, não now()
    }
}
