<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Listagem as RelatoriosListagem;
use App\Mail\RelatorioParaCliente;
use App\Models\Cliente;
use App\Models\Equipamento;
use App\Models\Intervencao;
use App\Models\Local;
use App\Models\Relatorio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

// Última etapa da cadeia do domínio: Relatório → enviado ao Cliente (CLAUDE.md §6).
class EnvioRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private function relatorioPara(?string $email): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'email' => $email, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala Técnica']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create([
            'equipamento_id' => $equipamento->id,
            'tipo' => 'corretiva',
            'estado' => 'concluida',
        ]);

        // pdf_path já preenchido para o job não invocar a geração de PDF no teste.
        return Relatorio::create([
            'intervencao_id' => $intervencao->id,
            'numero' => '2026/9001',
            'data' => now(),
            'estado' => EstadoRelatorio::Finalizado,
            'pdf_path' => 'relatorios/2026-9001.pdf',
        ]);
    }

    public function test_envia_relatorio_e_marca_como_enviado(): void
    {
        Mail::fake();
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        (new EnviarRelatorioPorEmail($relatorio))->handle(app(\App\Services\GeradorRelatorio::class));

        Mail::assertSent(RelatorioParaCliente::class, fn ($mail) => $mail->hasTo('cliente@exemplo.pt'));

        $relatorio->refresh();
        $this->assertSame(EstadoRelatorio::Enviado, $relatorio->estado);
        $this->assertSame('cliente@exemplo.pt', $relatorio->enviado_para);
        $this->assertNotNull($relatorio->enviado_em);
    }

    public function test_botao_enviar_da_listagem_despacha_o_job(): void
    {
        Queue::fake();
        $admin = User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        Livewire::actingAs($admin)->test(RelatoriosListagem::class)
            ->call('enviar', $relatorio->id)
            ->assertHasNoErrors();

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->relatorio->id === $relatorio->id);
    }

    public function test_nao_envia_sem_email_do_cliente(): void
    {
        Mail::fake();
        $relatorio = $this->relatorioPara(null);

        (new EnviarRelatorioPorEmail($relatorio))->handle(app(\App\Services\GeradorRelatorio::class));

        Mail::assertNothingSent();

        $relatorio->refresh();
        $this->assertSame(EstadoRelatorio::Finalizado, $relatorio->estado);
        $this->assertNull($relatorio->enviado_em);
    }
}
