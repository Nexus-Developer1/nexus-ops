<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Novo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\Relatorio;
use App\Models\User;
use App\Services\Alertas\ServicoAlertas;
use App\Services\GeradorRelatorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Vaga 2: (1) o PDF ENVIADO fica congelado (cópia imutável + sha256; o portal serve sempre
// a cópia, nunca regenera); (2) o SLA passa a ser MEDIDO — resposta (pedido_em → início) e
// resolução — com antecipação a 75% do prazo.
class Vaga2PdfImutavelSlaTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        Storage::fake('local');
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'V2-1']);
        $intervencao = Intervencao::create(['equipamento_id' => $equip->id, 'tipo' => 'preventiva', 'estado' => 'concluida']);
        $relatorio = Relatorio::create(['intervencao_id' => $intervencao->id, 'numero' => '2026/0100', 'data' => now(), 'estado' => 'finalizado']);

        // PDF de trabalho já gerado (conteúdo conhecido para provar o congelamento).
        Storage::disk()->put('relatorios/2026-0100.pdf', 'PDF-VERSAO-1');
        $relatorio->update(['pdf_path' => 'relatorios/2026-0100.pdf']);

        return [$cliente, $relatorio];
    }

    public function test_envio_congela_copia_imutavel_com_hash(): void
    {
        Mail::fake();
        [, $relatorio] = $this->cenario();

        (new EnviarRelatorioPorEmail($relatorio, 'cliente@acme.pt', 'Relatório', 'Segue.'))->handle(app(GeradorRelatorio::class));

        $relatorio->refresh();
        $this->assertSame(1, $relatorio->enviado_versao);
        $this->assertSame('PDF-VERSAO-1', Storage::disk()->get($relatorio->pdf_enviado_path));
        $this->assertSame(hash('sha256', 'PDF-VERSAO-1'), $relatorio->pdf_enviado_sha256);

        // Reabertura + regeneração mudam o PDF DE TRABALHO... a cópia congelada não mexe.
        Storage::disk()->put('relatorios/2026-0100.pdf', 'PDF-REGENERADO-TEMPLATE-NOVO');
        $this->assertSame('PDF-VERSAO-1', Storage::disk()->get($relatorio->pdf_enviado_path));

        // Reenvio = versão NOVA; a v1 fica intacta (prova histórica).
        (new EnviarRelatorioPorEmail($relatorio->fresh(), 'cliente@acme.pt', 'Relatório', 'Segue v2.'))->handle(app(GeradorRelatorio::class));
        $relatorio->refresh();
        $this->assertSame(2, $relatorio->enviado_versao);
        $this->assertSame('PDF-REGENERADO-TEMPLATE-NOVO', Storage::disk()->get($relatorio->pdf_enviado_path));
        $this->assertSame('PDF-VERSAO-1', Storage::disk()->get('relatorios/enviados/2026-0100-v1.pdf'));
    }

    public function test_portal_serve_sempre_a_copia_congelada(): void
    {
        Mail::fake();
        [$cliente, $relatorio] = $this->cenario();
        (new EnviarRelatorioPorEmail($relatorio, 'cliente@acme.pt', 'Relatório', 'Segue.'))->handle(app(GeradorRelatorio::class));

        // Entretanto o documento de trabalho foi reescrito (reabertura com template novo).
        Storage::disk()->put('relatorios/2026-0100.pdf', 'TRABALHO-EM-CURSO');

        $userCliente = User::create(['nome' => 'C', 'email' => 'c@acme.pt', 'password' => 'x',
            'papel' => PapelUtilizador::Cliente, 'cliente_id' => $cliente->id, 'ativo' => true]);

        $resposta = $this->actingAs($userCliente)->get(route('portal.relatorios.pdf', $relatorio->fresh()));
        $resposta->assertOk();
        $this->assertSame('PDF-VERSAO-1', $resposta->getContent()); // a cópia congelada, não o trabalho
    }

    public function test_sla_de_resposta_antecipa_e_estoura(): void
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $contrato = Contrato::create(['numero' => 'C-SLA', 'cliente_id' => $cliente->id, 'data_inicio' => now()->subYear(),
            'data_fim' => now()->addYear(), 'estado' => 'ativo', 'tipo' => 'corretiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id')]);
        $contrato->slas()->create(['tempo_resposta_horas' => 4, 'tempo_resolucao_horas' => 24, 'horario_cobertura' => '24x7']);

        // Pedido há 3h (75% de 4h) e SEM início de trabalho → média, "a esgotar-se".
        $i = Intervencao::create(['equipamento_id' => $equip->id, 'contrato_id' => $contrato->id, 'tipo' => 'corretiva',
            'estado' => 'em_curso', 'pedido_em' => now()->subHours(3), 'data_inicio' => null]);

        $alerta = app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'sla');
        $this->assertNotNull($alerta);
        $this->assertSame('media', $alerta['severidade']);
        $this->assertStringContainsString('resposta', $alerta['titulo']);

        // Pedido há 5h → excedido, alta.
        $i->update(['pedido_em' => now()->subHours(5)]);
        $alerta = app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'sla');
        $this->assertSame('alta', $alerta['severidade']);

        // Pedido há 1h (25%) → sem alerta (a antecipação começa aos 75%).
        $i->update(['pedido_em' => now()->subHour()]);
        $this->assertNull(app(ServicoAlertas::class)->recolher()->firstWhere('tipo', 'sla'));
    }

    public function test_editor_grava_o_pedido_em_nas_corretivas(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'SLA-ED']);

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('tipo', 'corretiva')
            ->set('pedido_em', '2026-08-10T09:30')
            ->call('autoGravar');

        $this->assertSame('2026-08-10 09:30', Intervencao::firstOrFail()->pedido_em->format('Y-m-d H:i'));
    }

    // Os metadados de captura das fotos vêm do browser — um payload forjado (datas
    // absurdas, coordenadas impossíveis, tipos errados) não pode rebentar a gravação.
    public function test_meta_de_fotos_forjada_nao_rebenta_a_gravacao(): void
    {
        $tecnico = User::create(['nome' => 'Téc', 'email' => 't2@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Tecnico, 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sede']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'numero_serie' => 'META-1']);

        Livewire::actingAs($tecnico)->test(Novo::class)
            ->set('equipamento_id', $equip->id)
            ->set('data', now()->toDateString())
            ->set('fotosMeta', [$equip->id => [
                ['nome' => 'x.jpg', 'capturada_em' => 'NADA-DE-DATA', 'latitude' => 999, 'longitude' => 'abc'],
                'não-é-array',
            ]])
            ->call('autoGravar')
            ->assertHasNoErrors();

        $this->assertSame(1, Relatorio::count()); // gravou normalmente, meta descartada
    }
}
