<?php

namespace Tests\Feature;

use App\Enums\EstadoContrato;
use App\Enums\PapelUtilizador;
use App\Livewire\Contratos\Ficha;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Equipamento;
use App\Models\EventoAgenda;
use App\Models\Local;
use App\Models\ModeloFaturacao;
use App\Models\User;
use App\Services\Agenda\GeradorVisitasPreventivas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// Ativação do contrato (Fase 2): muda só o estado para Ativo, sem gerar visitas.
// O gerador/estimar continuam testados diretamente (ficam funcionais para o KPI/revert).
class ContratoAtivacaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function contratoCom(int $nEquip, string $periodicidade, $inicio, $fim, string $numero): Contrato
    {
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'acme@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC1']);
        $ids = [];
        for ($i = 0; $i < $nEquip; $i++) {
            $ids[] = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X' . $i])->id;
        }
        $contrato = Contrato::create([
            'numero' => $numero, 'cliente_id' => $cliente->id,
            'data_inicio' => $inicio, 'data_fim' => $fim,
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync($ids);
        $contrato->planosVisita()->create(['equipamento_tipo' => 'ups', 'periodicidade' => $periodicidade, 'duracao_estimada_min' => 60]);

        return $contrato;
    }

    public function test_estimar_conta_sem_criar_nada(): void
    {
        // 2 equipamentos, trimestral, 6 meses → 2 × 3 ocorrências (mês 0,3,6) = 6.
        $contrato = $this->contratoCom(2, 'trimestral', now()->startOfYear(), now()->startOfYear()->addMonths(6), '2026/8001');

        $this->assertSame(6, app(GeradorVisitasPreventivas::class)->estimar($contrato));
        $this->assertSame(0, $contrato->eventos()->count()); // estimar não cria
    }

    public function test_ativar_nao_gera_visitas(): void
    {
        // (Fase 2) Ativar passa o contrato a Ativo SEM gerar visitas na agenda.
        $contrato = $this->contratoCom(2, 'trimestral', now()->startOfYear(), now()->startOfYear()->addMonths(6), '2026/8002');

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $contrato->refresh();
        $this->assertSame(EstadoContrato::Ativo, $contrato->estado);
        $this->assertSame(0, $contrato->eventos()->count());  // nenhuma visita gerada
        $this->assertNull($contrato->resumo_geracao);          // sem resumo de geração
    }

    public function test_ativar_sem_planos_e_permitido(): void
    {
        // Com equipamento mas SEM planos de visita → pode na mesma ser ativado.
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'p@x.pt', 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'DC']);
        $equip = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional', 'fabricante' => 'APC', 'modelo' => 'X']);
        $contrato = Contrato::create([
            'numero' => '2026/8003', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);
        $contrato->equipamentos()->sync([$equip->id]);

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Ativo, $contrato->fresh()->estado);
        $this->assertSame(0, $contrato->planosVisita()->count()); // não tinha planos
        $this->assertSame(0, $contrato->eventos()->count());
    }

    public function test_ativar_sem_equipamento_e_bloqueado(): void
    {
        // Sem equipamentos → continua a não poder ser ativado (requisito mantido).
        $cliente = Cliente::create(['nome' => 'ACME', 'email' => 'q@x.pt', 'ativo' => true]);
        $contrato = Contrato::create([
            'numero' => '2026/8007', 'cliente_id' => $cliente->id,
            'data_inicio' => now(), 'data_fim' => now()->addYear(),
            'estado' => EstadoContrato::Rascunho, 'tipo' => 'preventiva',
            'modelo_faturacao_id' => ModeloFaturacao::query()->value('id'),
        ]);

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])
            ->call('ativar');

        $this->assertSame(EstadoContrato::Rascunho, $contrato->fresh()->estado); // continua rascunho
    }

    public function test_ativar_nao_mexe_em_eventos_existentes(): void
    {
        // Um evento já na agenda fica intacto quando se ativa um contrato.
        $contrato = $this->contratoCom(1, 'trimestral', now()->startOfYear(), now()->endOfYear(), '2026/8008');
        $evento = EventoAgenda::create(['tipo' => 'visita_preventiva', 'titulo' => 'V', 'estado' => 'planeado',
            'inicio' => now(), 'fim' => now()->addHour(), 'cliente_id' => $contrato->cliente_id]);

        Livewire::actingAs($this->admin())->test(Ficha::class, ['contrato' => $contrato])->call('ativar');

        $this->assertDatabaseHas('eventos_agenda', ['id' => $evento->id, 'estado' => 'planeado']);
        $this->assertDatabaseCount('eventos_agenda', 1); // não criou nem apagou nada
    }

    public function test_gerar_usa_a_hora_do_contrato(): void
    {
        $contrato = $this->contratoCom(1, 'anual', now()->startOfYear(), now()->startOfYear()->addMonths(1), '2026/8004');
        $contrato->update(['hora_visita' => '14:30']);

        app(GeradorVisitasPreventivas::class)->gerar($contrato);

        $evento = $contrato->eventos()->where('tipo', 'visita_preventiva')->firstOrFail();
        $this->assertSame('14:30', \Illuminate\Support\Carbon::parse($evento->inicio)->format('H:i'));
        // Duração (60 min do plano) inalterada → fim às 15:30.
        $this->assertSame('15:30', \Illuminate\Support\Carbon::parse($evento->fim)->format('H:i'));
    }

    public function test_gerar_sem_hora_usa_as_09h_por_defeito(): void
    {
        $contrato = $this->contratoCom(1, 'anual', now()->startOfYear(), now()->startOfYear()->addMonths(1), '2026/8005');
        // hora_visita fica null (não definida) → fallback 09:00.

        app(GeradorVisitasPreventivas::class)->gerar($contrato);

        $evento = $contrato->eventos()->where('tipo', 'visita_preventiva')->firstOrFail();
        $this->assertSame('09:00', \Illuminate\Support\Carbon::parse($evento->inicio)->format('H:i'));
    }

    public function test_hora_do_contrato_nao_afeta_o_kpi_contratadas(): void
    {
        $contrato = $this->contratoCom(1, 'mensal', now()->startOfYear(), now()->endOfYear(), '2026/8006');
        $gerador = app(GeradorVisitasPreventivas::class);

        $semHora = $gerador->estimarNoAno($contrato, now()->year);
        $contrato->update(['hora_visita' => '23:45']);
        $comHora = $gerador->estimarNoAno($contrato->fresh(), now()->year);

        $this->assertSame($semHora, $comHora); // a hora não muda a contagem de ocorrências
    }
}
