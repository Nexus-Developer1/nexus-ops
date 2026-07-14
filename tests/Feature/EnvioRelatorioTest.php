<?php

namespace Tests\Feature;

use App\Enums\EstadoRelatorio;
use App\Enums\PapelUtilizador;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Relatorios\Enviar;
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

// Última etapa da cadeia: Relatório → enviado ao Cliente (CLAUDE.md §6). O envio passa por uma
// página de composição onde o destinatário, assunto e mensagem são escritos à mão.
class EnvioRelatorioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['nome' => 'Admin', 'email' => 'a@nexus.pt', 'password' => 'x', 'papel' => PapelUtilizador::Admin, 'ativo' => true]);
    }

    private function relatorioPara(?string $email, string $estado = 'finalizado', ?string $numero = '2026/9001'): Relatorio
    {
        $cliente = Cliente::create(['nome' => 'Cliente Teste', 'email' => $email, 'ativo' => true]);
        $local = Local::create(['cliente_id' => $cliente->id, 'designacao' => 'Sala Técnica']);
        $equipamento = Equipamento::create(['local_id' => $local->id, 'tipo' => 'ups', 'estado' => 'operacional']);
        $intervencao = Intervencao::create(['equipamento_id' => $equipamento->id, 'tipo' => 'corretiva', 'estado' => 'concluida']);

        // pdf_path já preenchido para o job não invocar a geração de PDF no teste.
        return Relatorio::create([
            'intervencao_id' => $intervencao->id, 'numero' => $numero,
            'data' => now(), 'estado' => $estado, 'pdf_path' => 'relatorios/2026-9001.pdf',
        ]);
    }

    public function test_job_envia_com_valores_escritos_e_marca_enviado(): void
    {
        Mail::fake();
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        (new EnviarRelatorioPorEmail($relatorio, 'destinatario@escrito.pt', 'Assunto à mão', 'Mensagem à mão'))
            ->handle(app(\App\Services\GeradorRelatorio::class));

        Mail::assertSent(RelatorioParaCliente::class, fn ($mail) => $mail->hasTo('destinatario@escrito.pt') && $mail->hasSubject('Assunto à mão'));

        $relatorio->refresh();
        $this->assertSame(EstadoRelatorio::Enviado, $relatorio->estado);
        $this->assertSame('destinatario@escrito.pt', $relatorio->enviado_para); // o escrito, não o do cliente
        $this->assertNotNull($relatorio->enviado_em);
    }

    public function test_pagina_de_composicao_pre_preenche_e_despacha_com_o_escrito(): void
    {
        Queue::fake();
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        Livewire::actingAs($this->admin())->test(Enviar::class, ['relatorio' => $relatorio])
            ->assertSet('para', 'cliente@exemplo.pt')                              // pré-preenchido, editável
            ->assertSet('assunto', 'Relatório de intervenção 2026/9001')
            ->set('para', 'outro@destino.pt')
            ->set('assunto', 'O meu assunto')
            ->set('mensagem', 'Olá, segue o relatório.')
            ->call('enviar')
            ->assertHasNoErrors()
            ->assertRedirect(route('relatorios'));

        Queue::assertPushed(EnviarRelatorioPorEmail::class, fn ($job) => $job->relatorio->id === $relatorio->id
            && $job->para === 'outro@destino.pt'
            && $job->assunto === 'O meu assunto'
            && $job->mensagem === 'Olá, segue o relatório.');
    }

    public function test_email_do_relatorio_usa_o_template_verde_do_site(): void
    {
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        // Renderiza a view do email → tema verde + mensagem à mão + nº do relatório + anexo.
        $html = view('emails.relatorio', ['relatorio' => $relatorio, 'mensagem' => "Olá,\n\nSegue o relatório."])->render();

        $this->assertStringContainsString('#16a34a', $html);         // verde do site
        $this->assertStringContainsString('Nexus Infra', $html);
        $this->assertStringContainsString('Segue o relatório.', $html); // mensagem escrita à mão
        $this->assertStringContainsString('2026/9001', $html);        // nº do relatório
        $this->assertStringContainsString('Em anexo', $html);
        $this->assertStringNotContainsString('mail::message', $html); // já não é o markdown genérico
    }

    public function test_composicao_exige_destinatario_valido(): void
    {
        Queue::fake();
        $relatorio = $this->relatorioPara(null); // cliente sem email → para começa vazio

        $comp = Livewire::actingAs($this->admin())->test(Enviar::class, ['relatorio' => $relatorio])
            ->set('assunto', 'X')->set('mensagem', 'Y'); // isolar o erro no 'para'

        // Vazio → regra 'required', não despacha.
        $comp->set('para', '')
            ->call('enviar')
            ->assertHasErrors(['para' => 'required']);

        // Malformado ("joao@") → regra 'email', também barrado na página, não despacha.
        $comp->set('para', 'joao@')
            ->call('enviar')
            ->assertHasErrors(['para' => 'email']);

        // Nunca chegou nada à fila (a validação corre antes do dispatch).
        Queue::assertNothingPushed();
    }

    public function test_rascunho_nao_abre_composicao(): void
    {
        $rascunho = $this->relatorioPara('cliente@exemplo.pt', estado: 'rascunho', numero: null);

        Livewire::actingAs($this->admin())->test(Enviar::class, ['relatorio' => $rascunho])
            ->assertRedirect(route('relatorios'));
    }

    public function test_job_defensivo_nao_envia_com_destinatario_vazio(): void
    {
        Mail::fake();
        $relatorio = $this->relatorioPara('cliente@exemplo.pt');

        (new EnviarRelatorioPorEmail($relatorio, '', 'A', 'M'))->handle(app(\App\Services\GeradorRelatorio::class));

        Mail::assertNothingSent();
        $this->assertSame(EstadoRelatorio::Finalizado, $relatorio->fresh()->estado);
    }
}
