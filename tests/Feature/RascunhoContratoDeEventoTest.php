<?php

namespace Tests\Feature;

use App\Enums\PapelUtilizador;
use App\Livewire\Agenda\Calendario;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Agenda\GeradorRascunhoDeEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Camada 2 enriquecida: um evento COM contrato gera o rascunho em MODO CONTRATO (contrato_id
// + equipamentos cobertos = os do contrato). Sem contrato → individual. Sempre preventiva.
class RascunhoContratoDeEventoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    /** @return array{0: Cliente, 1: Local} */
    private function clienteLocal(): array
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);

        return [$cliente, $local];
    }

    private function equip(Local $local, string $sn): Equipamento
    {
        return Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'Riello', 'modelo' => 'NPW', 'numero_serie' => $sn]);
    }

    private function contrato(Cliente $cliente): Contrato
    {
        return Contrato::create([
            'numero' => '2026/7001', 'cliente_id' => $cliente->id,
            'data_inicio' => now()->subMonth(), 'data_fim' => now()->addYear(),
            'estado' => 'ativo', 'tipo' => 'preventiva', 'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
    }

    public function test_evento_com_contrato_gera_rascunho_modo_contrato(): void
    {
        [$cliente, $local] = $this->clienteLocal();
        [$e1, $e2, $e3] = [$this->equip($local, 'SN-1'), $this->equip($local, 'SN-2'), $this->equip($local, 'SN-3')];
        $contrato = $this->contrato($cliente);
        $contrato->equipamentos()->sync([$e1->id, $e2->id, $e3->id]);

        $inicio = now()->addWeek()->setTime(10, 0);
        $fim = (clone $inicio)->setTime(11, 30);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Preventiva UPS')
            ->set('formEquipamentoId', $e1->id)
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', $fim->format('Y-m-d\TH:i'))
            ->set('formContratoId', $contrato->id)
            ->set('formCobertura', 'incluida')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();

        // Nasce em modo contrato (contrato_id), preventiva, principal = equip. do evento.
        $this->assertSame($contrato->id, $interv->contrato_id);
        $this->assertSame('preventiva', $interv->tipo->value);
        $this->assertSame($e1->id, $interv->equipamento_id);

        // Cobertos = os equipamentos do contrato MENOS o principal.
        $cobertos = $interv->equipamentosCobertos()->pluck('equipamentos.id')->sort()->values()->all();
        $this->assertSame([$e2->id, $e3->id], $cobertos);

        // Relatório em rascunho (sem número).
        $this->assertDatabaseHas('relatorios', ['intervencao_id' => $interv->id, 'estado' => 'rascunho', 'numero' => null]);
    }

    public function test_evento_com_contrato_sem_equipamento_gera_rascunho_do_contrato(): void
    {
        [$cliente, $local] = $this->clienteLocal();
        [$e1, $e2, $e3] = [$this->equip($local, 'SN-1'), $this->equip($local, 'SN-2'), $this->equip($local, 'SN-3')];
        $contrato = $this->contrato($cliente);
        $contrato->equipamentos()->sync([$e1->id, $e2->id, $e3->id]);

        $inicio = now()->addWeek()->setTime(10, 0);

        // Associa SÓ o contrato (sem escolher equipamento) → o âmbito vem do contrato.
        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Preventiva UPS')
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', (clone $inicio)->setTime(11, 30)->format('Y-m-d\TH:i'))
            ->set('formContratoId', $contrato->id)
            ->set('formCobertura', 'incluida')
            ->call('criarEvento')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertSame($contrato->id, $interv->contrato_id);
        $this->assertSame('preventiva', $interv->tipo->value);

        // Todos os equipamentos do contrato ficam no relatório (principal + cobertos).
        $todos = collect([$interv->equipamento_id])
            ->merge($interv->equipamentosCobertos()->pluck('equipamentos.id'))
            ->sort()->values()->all();
        $this->assertSame([$e1->id, $e2->id, $e3->id], $todos);

        // E o evento herda o cliente do contrato (não tinha equipamento de onde o tirar).
        $this->assertSame($cliente->id, EventoAgenda::firstOrFail()->cliente_id);
    }

    public function test_evento_sem_contrato_gera_rascunho_individual(): void
    {
        [, $local] = $this->clienteLocal();
        $e1 = $this->equip($local, 'SN-10');

        $inicio = now()->addWeek()->setTime(9, 0);

        Livewire::actingAs($this->admin())->test(Calendario::class)
            ->set('formTitulo', 'Visita')
            ->set('formEquipamentoId', $e1->id)
            ->set('formInicio', $inicio->format('Y-m-d\TH:i'))
            ->set('formFim', (clone $inicio)->setTime(10, 0)->format('Y-m-d\TH:i'))
            ->call('criarEvento')
            ->assertHasNoErrors();

        $interv = Intervencao::firstOrFail();
        $this->assertNull($interv->contrato_id);                 // individual
        $this->assertSame('preventiva', $interv->tipo->value);
        $this->assertSame($e1->id, $interv->equipamento_id);
        $this->assertSame(0, $interv->equipamentosCobertos()->count()); // sem cobertos
    }

    public function test_principal_fora_do_contrato_fica_principal_e_cobertos_sao_os_do_contrato(): void
    {
        [$cliente, $local] = $this->clienteLocal();
        [$fora, $e2, $e3] = [$this->equip($local, 'SN-FORA'), $this->equip($local, 'SN-2'), $this->equip($local, 'SN-3')];
        $contrato = $this->contrato($cliente);
        $contrato->equipamentos()->sync([$e2->id, $e3->id]); // o principal (fora) NÃO está no contrato

        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $fora->id, 'contrato_id' => $contrato->id,
        ]);

        app(GeradorRascunhoDeEvento::class)->gerar($evento);

        $interv = Intervencao::firstOrFail();
        $this->assertSame($fora->id, $interv->equipamento_id); // principal continua a ser o do evento
        $cobertos = $interv->equipamentosCobertos()->pluck('equipamentos.id')->sort()->values()->all();
        $this->assertSame([$e2->id, $e3->id], $cobertos);      // cobertos = todos os do contrato
    }

    public function test_principal_do_contrato_e_deterministico_e_estavel_ao_regerar(): void
    {
        [$cliente, $local] = $this->clienteLocal();
        // e1 é criado primeiro → menor id. A ordem de sync na pivot é propositadamente
        // ao contrário, para provar que o principal vem do orderBy('id'), não da pivot.
        [$e1, $e2, $e3] = [$this->equip($local, 'SN-1'), $this->equip($local, 'SN-2'), $this->equip($local, 'SN-3')];
        $contrato = $this->contrato($cliente);
        $contrato->equipamentos()->sync([$e3->id, $e2->id, $e1->id]);

        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => null, 'contrato_id' => $contrato->id, // só contrato, sem equipamento
        ]);

        $gerador = app(GeradorRascunhoDeEvento::class);
        $gerador->gerar($evento);

        $interv = Intervencao::firstOrFail();
        $this->assertSame($e1->id, $interv->equipamento_id); // 1.º por id, não por ordem de sync

        // Re-gerar: idempotente e o principal NÃO muda.
        $this->assertNull($gerador->gerar($evento->fresh()));
        $this->assertSame(1, Intervencao::count());
        $this->assertSame($e1->id, $interv->fresh()->equipamento_id);
    }

    public function test_regerar_nao_duplica(): void
    {
        [$cliente, $local] = $this->clienteLocal();
        [$e1, $e2] = [$this->equip($local, 'SN-1'), $this->equip($local, 'SN-2')];
        $contrato = $this->contrato($cliente);
        $contrato->equipamentos()->sync([$e1->id, $e2->id]);

        $evento = EventoAgenda::create([
            'tipo' => 'outro', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now()->addWeek()->setTime(9, 0), 'fim' => now()->addWeek()->setTime(10, 0),
            'equipamento_id' => $e1->id, 'contrato_id' => $contrato->id,
        ]);

        $gerador = app(GeradorRascunhoDeEvento::class);
        $gerador->gerar($evento);
        $segundo = $gerador->gerar($evento->fresh()); // re-gerar

        $this->assertNull($segundo);                       // idempotente
        $this->assertSame(1, Intervencao::count());        // não duplicou
        $this->assertSame(1, \App\Models\Relatorio::count());
    }
}
