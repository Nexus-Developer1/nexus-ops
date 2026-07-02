<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoRelatorio;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Models\Relatorio;
use Livewire\Attributes\Layout;
use Livewire\Component;

// Página de composição do email de envio de um relatório. O destinatário, assunto e mensagem
// são escritos/editados à mão (pré-preenchidos) antes de enviar — não é um email genérico.
#[Layout('components.layouts.app', ['ativo' => 'relatorios', 'titulo' => 'Enviar relatório'])]
class Enviar extends Component
{
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
        $this->assunto = 'Relatório de intervenção ' . $this->relatorio->numero;
        $this->mensagem = "Caro(a) " . ($cliente?->nome ?? 'Cliente') . ",\n\n"
            . "Segue em anexo o relatório da intervenção técnica.\n\n"
            . "Para qualquer esclarecimento, não hesite em contactar-nos.\n\n"
            . "Com os melhores cumprimentos,\n"
            . config('app.name');
    }

    public function enviar()
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->validate([
            'para' => ['required', 'email'],
            'assunto' => ['required', 'string', 'max:255'],
            'mensagem' => ['required', 'string', 'max:5000'],
        ]);

        EnviarRelatorioPorEmail::dispatch(
            $this->relatorio,
            trim($this->para),
            trim($this->assunto),
            $this->mensagem,
        );

        session()->flash('sucesso', "Relatório {$this->relatorio->numero} em envio para {$this->para}.");

        return redirect()->route('relatorios');
    }

    public function render()
    {
        return view('livewire.relatorios.enviar');
    }
}
