<?php

namespace Tests\Feature;

use App\Mail\Transport\GraphTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

// Transporte de email Microsoft Graph: mailer registado + payload correto do Graph a partir
// de um email Symfony. Nunca chama a API real (Http::fake).
class GraphTransportTest extends TestCase
{
    private function configurarGraph(): void
    {
        config(['services.microsoft_graph' => [
            'tenant_id' => 'tenant-123',
            'client_id' => 'client-abc',
            'client_secret' => 'segredo',
            'sender' => 'Suporte@nxs.pt',
        ]]);
    }

    public function test_mailer_graph_esta_registado(): void
    {
        $this->configurarGraph();

        $transporte = Mail::mailer('graph')->getSymfonyTransport();

        $this->assertInstanceOf(GraphTransport::class, $transporte);
        $this->assertSame('graph', (string) $transporte);
    }

    public function test_constroi_payload_do_graph_a_partir_de_email_symfony(): void
    {
        $transporte = new GraphTransport('t', 'c', 's', 'Suporte@nxs.pt');

        $email = (new Email)
            ->from('Suporte@nxs.pt')
            ->to('cliente@acme.pt')
            ->cc('gestor@acme.pt')
            ->subject('Relatório de intervenção 2026/0042')
            ->html('<p>Segue o relatório em anexo.</p>')
            ->attach('CONTEUDO-PDF-BINARIO', 'relatorio-2026-0042.pdf', 'application/pdf');

        $payload = $transporte->construirPayload(
            $email,
            new Envelope(new Address('Suporte@nxs.pt'), [new Address('cliente@acme.pt')]),
        );

        $msg = $payload['message'];
        $this->assertSame('Relatório de intervenção 2026/0042', $msg['subject']);
        $this->assertSame('HTML', $msg['body']['contentType']);
        $this->assertSame('<p>Segue o relatório em anexo.</p>', $msg['body']['content']);
        $this->assertSame('cliente@acme.pt', $msg['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('gestor@acme.pt', $msg['ccRecipients'][0]['emailAddress']['address']);
        $this->assertFalse($payload['saveToSentItems']);

        // Anexo PDF como fileAttachment, com o conteúdo em base64.
        $anexo = $msg['attachments'][0];
        $this->assertSame('#microsoft.graph.fileAttachment', $anexo['@odata.type']);
        $this->assertSame('relatorio-2026-0042.pdf', $anexo['name']);
        $this->assertSame('application/pdf', $anexo['contentType']);
        $this->assertSame('CONTEUDO-PDF-BINARIO', base64_decode($anexo['contentBytes']));
    }

    public function test_envio_pede_token_e_faz_post_ao_sendmail_sem_api_real(): void
    {
        $this->configurarGraph();
        Cache::flush(); // garante que o token é pedido (não vem de cache de outro teste)

        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'TOKEN-XYZ', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response('', 202), // sendMail devolve 202 Accepted
        ]);

        Mail::mailer('graph')->raw('corpo do email', function ($m) {
            $m->to('cliente@acme.pt')->subject('Assunto de teste');
        });

        // Pediu token ao endpoint da Microsoft.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'login.microsoftonline.com/tenant-123/oauth2/v2.0/token')
            && $req['grant_type'] === 'client_credentials'
            && $req['scope'] === 'https://graph.microsoft.com/.default');

        // Fez POST ao sendMail da mailbox certa, com Bearer e o payload correto.
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'graph.microsoft.com/v1.0/users/Suporte@nxs.pt/sendMail')) {
                return false;
            }

            return $req->hasHeader('Authorization', 'Bearer TOKEN-XYZ')
                && $req['message']['subject'] === 'Assunto de teste'
                && $req['message']['toRecipients'][0]['emailAddress']['address'] === 'cliente@acme.pt';
        });
    }
}
