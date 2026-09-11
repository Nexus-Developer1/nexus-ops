<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Agenda\GeradorIcs;
use Tests\TestCase;

// Revisão de segurança (set. 2026): o texto do evento (assunto, notas) entra no convite
// iCalendar, que é um formato de LINHAS — uma quebra de linha por escapar abre uma linha nova
// e injeta uma propriedade. O corpo HTML (X-ALT-DESC) usava um escape nosso que tratava CRLF
// e LF mas deixava passar um CR sozinho. Um assunto forjado ("…\rATTENDEE:…") metia um
// convidado, um URL ou um END:VEVENT no convite que os colegas recebem.
class ConviteIcsInjecaoTest extends TestCase
{
    private function ics(array $sobrepor): string
    {
        $e = $sobrepor + [
            'id' => 1, 'uid' => GeradorIcs::uid(1), 'sequence' => 0, 'titulo' => 'Serviço',
            'motivo' => null, 'notas' => null,
            'inicio' => '2026-09-20T09:00:00+01:00', 'fim' => '2026-09-20T10:00:00+01:00', 'segmentos' => [],
            'tecnico_ids' => [], 'tecnicos_nomes' => 'Paulo Bento', 'cliente' => 'ACME',
            'equipamento' => null, 'contrato' => null,
        ];

        return app(GeradorIcs::class)->convite($e, 0, new User(['nome' => 'Paulo Bento', 'email' => 'p@nexus.pt']));
    }

    // A regra que fecha a porta: no ficheiro, um CR só pode existir como parte de um CRLF (o
    // fim de linha do formato). Um CR sozinho é uma linha nova para os leitores tolerantes.
    private function assertSemCrSozinho(string $ics): void
    {
        $this->assertSame(0, preg_match("/\r(?!\n)/", $ics), 'O convite tem um CR sozinho (linha injetável).');
    }

    public function test_assunto_com_cr_sozinho_nao_abre_linha_nova(): void
    {
        $ics = $this->ics(['motivo' => "Visita\rURL:https://intruso.exemplo/x\rEND:VEVENT"]);

        $this->assertSemCrSozinho($ics);
        // Só existe o END:VEVENT verdadeiro.
        $this->assertSame(1, preg_match_all('/^END:VEVENT\r$/m', $ics));
    }

    public function test_notas_com_cr_sozinho_nao_abrem_linha_nova(): void
    {
        $ics = $this->ics(['notas' => "Portão das traseiras\rATTENDEE:mailto:intruso@exemplo.pt"]);

        $this->assertSemCrSozinho($ics);

        // O único convidado é o técnico verdadeiro.
        $linhas = preg_split("/\r\n|\r|\n/", $ics);
        $convidados = array_values(array_filter($linhas, fn ($l) => str_starts_with($l, 'ATTENDEE')));
        $this->assertCount(1, $convidados);
        $this->assertStringContainsString('mailto:p@nexus.pt', $convidados[0]);
    }

    public function test_quebras_normais_continuam_a_sair_como_mudanca_de_linha(): void
    {
        // Notas escritas à mão (Enter no textarea) chegam com CRLF ou LF: ficam como sempre —
        // \n dentro do texto, sem linhas novas no ficheiro.
        $ics = $this->ics(['notas' => "Linha 1\r\nLinha 2\nLinha 3"]);

        $this->assertSemCrSozinho($ics);
        $desdobrado = preg_replace("/\r\n[ \t]/", '', $ics);
        $this->assertStringContainsString('Linha 1\nLinha 2\nLinha 3', $desdobrado);
    }
}
