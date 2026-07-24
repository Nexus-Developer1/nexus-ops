<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Livewire\Contratos\Editor as ContratoEditor;
use App\Livewire\Contratos\Ficha as ContratoFicha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\EventoAgenda;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Gestao\ServicoMetricas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Fase 1 — caminho manual de visitas (nº fixo incluído + cobertura), a coexistir
// com o modelo automático. Saldo conta por cobertura, sem filtrar tipo.
class SaldoVisitasFase1Test extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function contratoAtivo(?int $visitasIncluidas = null): Contrato
    {
        $this->seq++;
        $cliente = Cliente::create(['nome' => 'ACME ' . $this->seq, 'ativo' => true]);

        return Contrato::create([
            'numero' => '2026/90' . $this->seq,
            'cliente_id' => $cliente->id,
            'data_inicio' => now()->startOfYear(),
            'data_fim' => now()->endOfYear(),
            'estado' => 'ativo',
            'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
            'visitas_incluidas' => $visitasIncluidas,
        ]);
    }

    private function evento(Contrato $c, ?string $cobertura, string $estado, string $tipo = 'outro', ?Carbon $inicio = null): EventoAgenda
    {
        $inicio ??= now();

        return EventoAgenda::create([
            'tipo' => $tipo,
            'titulo' => 'V',
            'inicio' => $inicio,
            'fim' => $inicio->copy()->addHour(),
            'estado' => $estado,
            'cliente_id' => $c->cliente_id,
            'contrato_id' => $c->id,
            'cobertura' => $cobertura,
        ]);
    }

    private function horaValida(): Carbon
    {
        return Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0); // dia útil, dentro de 8h–19h
    }

    // ---- Editor ----

    public function test_editor_guarda_visitas_incluidas(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(ContratoEditor::class)
            ->set('numero', '2026/7777')->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->set('periodo_aviso_dias', 30)
            ->set('visitas_incluidas', 4)
            ->call('guardar')->assertHasNoErrors();

        $this->assertSame(4, Contrato::where('numero', '2026/7777')->value('visitas_incluidas'));
    }

    public function test_editor_visitas_incluidas_vazio_fica_null(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(ContratoEditor::class)
            ->set('numero', '2026/7778')->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->set('periodo_aviso_dias', 30)
            ->set('visitas_incluidas', null)
            ->call('guardar')->assertHasNoErrors();

        $this->assertNull(Contrato::where('numero', '2026/7778')->value('visitas_incluidas'));
    }

    public function test_editor_rejeita_visitas_incluidas_zero_ou_negativo(): void
    {
        $cliente = Cliente::create(['nome' => 'C', 'ativo' => true]);

        Livewire::actingAs($this->admin())->test(ContratoEditor::class)
            ->set('numero', '2026/7779')->set('cliente_id', $cliente->id)
            ->set('data_inicio', now()->toDateString())->set('data_fim', now()->addYear()->toDateString())
            ->set('tipo', 'preventiva')->set('modelo_faturacao_id', ModeloFaturacao::query()->value('id'))
            ->set('periodo_aviso_dias', 30)
            ->set('visitas_incluidas', 0)
            ->call('guardar')->assertHasErrors('visitas_incluidas');
    }

    // ---- Agenda (criação de visita manual) ----

    public function test_agenda_cria_visita_com_contrato_e_cobertura(): void
    {
        $c = $this->contratoAtivo();
        $d = $this->horaValida();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Visita manual')
            ->set('formInicio', $d->format('Y-m-d\TH:i'))
            ->set('formFim', $d->copy()->addHour()->format('Y-m-d\TH:i'))
            ->set('formContratoId', $c->id)
            ->set('formCobertura', 'incluida')
            ->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('contrato_id', $c->id)->firstOrFail();
        $this->assertSame('incluida', $e->cobertura);
        $this->assertSame('outro', $e->tipo->value); // coexistência: NÃO é visita_preventiva
    }

    public function test_agenda_sem_contrato_nao_grava_cobertura(): void
    {
        $d = $this->horaValida();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Reunião')
            ->set('formInicio', $d->format('Y-m-d\TH:i'))
            ->set('formFim', $d->copy()->addHour()->format('Y-m-d\TH:i'))
            ->set('formContratoId', null)
            ->set('formCobertura', 'incluida') // enviada, mas sem contrato deve ser ignorada
            ->call('criarEvento')->assertHasNoErrors();

        $e = EventoAgenda::where('titulo', 'Reunião')->firstOrFail();
        $this->assertNull($e->contrato_id);
        $this->assertNull($e->cobertura);
    }

    public function test_agenda_contrato_sem_cobertura_da_erro(): void
    {
        $c = $this->contratoAtivo();
        $d = $this->horaValida();

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Visita manual')
            ->set('formInicio', $d->format('Y-m-d\TH:i'))
            ->set('formFim', $d->copy()->addHour()->format('Y-m-d\TH:i'))
            ->set('formContratoId', $c->id)   // hook põe 'incluida'
            ->set('formCobertura', null)       // forçar vazio → required_with falha
            ->call('criarEvento')->assertHasErrors('formCobertura');
    }

    // ---- Saldo (Ficha) ----

    public function test_saldo_conta_incluidas_nao_canceladas(): void
    {
        $c = $this->contratoAtivo(2);
        $this->evento($c, 'incluida', 'planeado');
        $this->evento($c, 'incluida', 'concluido');

        Livewire::actingAs($this->admin())->test(ContratoFicha::class, ['contrato' => $c])
            ->assertViewHas('saldo', fn ($s) => $s['incluidas'] === 2 && $s['usadas'] === 2 && $s['restantes'] === 0 && $s['excedido'] === 0);
    }

    public function test_saldo_ignora_extra_canceladas_e_auto_geradas(): void
    {
        $c = $this->contratoAtivo(2);
        $this->evento($c, 'incluida', 'planeado');   // conta
        $this->evento($c, 'extra', 'planeado');       // não conta (extra)
        $this->evento($c, 'incluida', 'cancelado');   // não conta (cancelado)
        $this->evento($c, null, 'planeado', 'visita_preventiva'); // auto-gerada (cobertura null) não conta

        Livewire::actingAs($this->admin())->test(ContratoFicha::class, ['contrato' => $c])
            ->assertViewHas('saldo', fn ($s) => $s['usadas'] === 1 && $s['extras'] === 1 && $s['restantes'] === 1);
    }

    public function test_saldo_escondido_se_visitas_incluidas_null(): void
    {
        $c = $this->contratoAtivo(null);
        $this->evento($c, 'incluida', 'planeado'); // existe, mas sem cláusula não há saldo

        Livewire::actingAs($this->admin())->test(ContratoFicha::class, ['contrato' => $c])
            ->assertViewHas('saldo', fn ($s) => $s === null);
    }

    public function test_saldo_excedido_mostra_zero_restantes_sem_negativo(): void
    {
        $c = $this->contratoAtivo(2);
        $this->evento($c, 'incluida', 'planeado');
        $this->evento($c, 'incluida', 'planeado');
        $this->evento($c, 'incluida', 'concluido');

        Livewire::actingAs($this->admin())->test(ContratoFicha::class, ['contrato' => $c])
            ->assertViewHas('saldo', fn ($s) => $s['usadas'] === 3 && $s['restantes'] === 0 && $s['excedido'] === 1);
    }

    // ---- Coexistência ----

    public function test_saldo_conta_manual_e_grafico_conta_ambas(): void
    {
        $c = $this->contratoAtivo(2);

        // Visita MANUAL (tipo outro, incluída) concluída → entra no saldo.
        $this->evento($c, 'incluida', 'concluido', 'outro', now());
        // Visita LEGADO (visita_preventiva, cobertura null) concluída → NÃO entra no saldo.
        $this->evento($c, null, 'concluido', 'visita_preventiva', now());

        // Saldo conta só a manual (a preventiva sem cobertura não desconta).
        Livewire::actingAs($this->admin())->test(ContratoFicha::class, ['contrato' => $c])
            ->assertViewHas('saldo', fn ($s) => $s['usadas'] === 1);

        // O gráfico mensal (Fase 3, adaptado) conta AMBAS como realizadas (manual + legado).
        $realizadas = app(ServicoMetricas::class)->visitasPorMes()['realizadas'];
        $this->assertSame(2, array_sum($realizadas));
    }
}
