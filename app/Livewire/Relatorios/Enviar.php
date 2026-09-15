<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoRelatorio;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Relatorio;
use App\Services\Auditor;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Página de composição do email de envio de um relatório. O destinatário, assunto e mensagem
// são escritos/editados à mão (pré-preenchidos) antes de enviar — não é um email genérico.
#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Enviar relatório'])]
class Enviar extends Component
{
    use ApenasEquipa;

    public Relatorio $relatorio;

    public string $para = '';

    public string $assunto = '';

    public string $mensagem = '';

    public function mount(Relatorio $relatorio): void
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->relatorio = $relatorio->load('intervencao.equipamento.local.cliente');

        // Só relatórios já emitidos (finalizado/enviado) se enviam — rascunho não.
        if ($this->relatorio->estado === EstadoRelatorio::Rascunho) {
            $this->redirectRoute('relatorios', navigate: true);

            return;
        }

        $cliente = $this->relatorio->intervencao?->equipamento?->local?->cliente;

        // Pré-preenche (tudo editável).
        $this->para = $cliente?->email ?? '';
        $this->assunto = 'Relatório de intervenção '.$this->relatorio->numero;
        $this->mensagem = 'Caro(a) '.($cliente?->nome ?? 'Cliente').",\n\n"
            ."Segue em anexo o relatório da intervenção técnica.\n\n"
            ."Para qualquer esclarecimento, não hesite em contactar-nos.\n\n"
            ."Com os melhores cumprimentos,\n"
            .config('app.name');
    }

    public function enviar()
    {
        abort_if(auth()->user()->ehCliente(), 403);

        // O estado é conferido ao abrir a página, mas a página pode ficar aberta enquanto o
        // relatório é reaberto noutro separador — um rascunho não se envia (22.ª revisão).
        $this->relatorio->refresh();
        if ($this->relatorio->estado === EstadoRelatorio::Rascunho) {
            session()->flash('erro', 'Este relatório voltou a rascunho — finalize-o antes de enviar.');

            return redirect()->route('relatorios');
        }

        // Vários destinatários, separados por «;» ou «,» (set. 2026) — cada um tem de ser um
        // email válido. Normaliza-se para «a@x.pt; b@y.pt» antes de validar e de guardar.
        $this->para = self::normalizarDestinatarios($this->para);

        $this->validate([
            'para' => ['required', 'string', 'max:1000'],
            'assunto' => ['required', 'string', 'max:255'],
            'mensagem' => ['required', 'string', 'max:5000'],
        ]);
        foreach (self::destinatarios($this->para) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('para', "«{$email}» não é um email válido.");

                return;
            }
        }

        // Quem envia recebe sempre cópia (pedido da equipa, set. 2026): fica com o mesmo email
        // que o cliente recebeu, com o PDF, na própria caixa.
        $cc = auth()->user()->email ?: null;

        EnviarRelatorioPorEmail::dispatch(
            $this->relatorio,
            $this->para,
            trim($this->assunto),
            $this->mensagem,
            $cc,
        );

        // Auditoria: emissão de documento oficial ao cliente (CLAUDE.md §11).
        Auditor::registar('relatorio_enviado', $this->relatorio, [
            'numero' => $this->relatorio->numero,
            'para' => $this->para,
            'cc' => $cc,
        ]);

        session()->flash('sucesso', "Relatório {$this->relatorio->numero} em envio para {$this->para}.");

        return redirect()->route('relatorios');
    }

    /** «a@x.pt;  b@y.pt , c@z.pt» → «a@x.pt; b@y.pt; c@z.pt» (sem repetidos, sem vazios). */
    public static function normalizarDestinatarios(string $texto): string
    {
        return implode('; ', self::destinatarios($texto));
    }

    /** @return list<string> */
    public static function destinatarios(string $texto): array
    {
        return collect(preg_split('/[;,\s]+/', $texto) ?: [])
            ->map(fn ($e) => mb_strtolower(trim($e)))
            ->filter()->unique()->values()->all();
    }

    public function render()
    {
        return view('livewire.relatorios.enviar');
    }
}
