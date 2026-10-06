<?php

namespace App\Livewire\Relatorios;

use App\Enums\EstadoRelatorio;
use App\Jobs\EnviarRelatorioPorEmail;
use App\Livewire\Concerns\ApenasEquipa;
use App\Models\Comercial;
use App\Models\Relatorio;
use App\Services\Auditor;
use Illuminate\Support\Str;
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

    // Quando sai o email (out. 2026): já, ou daqui a N minutos. A chave é o atraso em minutos.
    public const OPCOES_ENVIO = [
        'agora' => 'Imediato',
        '30' => 'Daqui a 30 min',
        '60' => 'Daqui a 1 h',
        '120' => 'Daqui a 2 h',
        '240' => 'Daqui a 4 h',
        '480' => 'Daqui a 8 h',
        '1440' => 'Daqui a 24 h',
    ];

    public string $quando = 'agora';

    // Avisar o COMERCIAL de que o serviço pode ser faturado (out. 2026): email escrito à mão ou
    // escolhido da lista (comerciais já usados). Vem preenchido com o comercial já usado para o
    // vendedor deste cliente (código do PHC), quando o há.
    public bool $avisarComercial = false;

    public string $comercial = '';

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
        $this->comercial = Comercial::doVendedor($cliente?->vendedor) ?? '';

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
            'quando' => ['required', 'in:'.implode(',', array_keys(self::OPCOES_ENVIO))],
        ]);
        foreach (self::destinatarios($this->para) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('para', "«{$email}» não é um email válido.");

                return;
            }
        }

        // Comercial: só conta com a opção ligada; aí é obrigatório e cada email tem de ser válido.
        $comercial = null;
        if ($this->avisarComercial) {
            $this->comercial = self::normalizarDestinatarios($this->comercial);
            if ($this->comercial === '') {
                $this->addError('comercial', 'Indique o email do comercial.');

                return;
            }
            if (mb_strlen($this->comercial) > 1000) {
                $this->addError('comercial', 'Demasiados endereços.');

                return;
            }
            foreach (self::destinatarios($this->comercial) as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->addError('comercial', "«{$email}» não é um email válido.");

                    return;
                }
            }
            $comercial = $this->comercial;
            // Fica na lista e ligado ao vendedor do cliente — da próxima vez vem preenchido.
            Comercial::aprender(self::destinatarios($comercial), $this->relatorio->intervencao?->equipamento?->local?->cliente?->vendedor);
        }

        // Quem envia recebe sempre cópia (pedido da equipa, set. 2026): fica com o mesmo email
        // que o cliente recebeu, com o PDF, na própria caixa.
        $cc = auth()->user()->email ?: null;

        // ENVIO AGENDADO (out. 2026): o job vai para a fila com atraso e leva um token novo,
        // que fica no relatório. Um agendamento que já lá estivesse deixa de valer (o token
        // muda) — o cliente nunca recebe duas vezes o mesmo envio.
        if ($this->quando !== 'agora') {
            $hora = now()->addMinutes((int) $this->quando);
            $token = (string) Str::uuid();
            $this->relatorio->update([
                'envio_agendado_em' => $hora,
                'envio_agendado_token' => $token,
                'envio_agendado_destino' => $this->para,
            ]);

            EnviarRelatorioPorEmail::dispatch($this->relatorio, $this->para, trim($this->assunto), $this->mensagem, $cc, $token, $comercial)
                ->delay($hora);

            Auditor::registar('relatorio_envio_agendado', $this->relatorio, [
                'numero' => $this->relatorio->numero,
                'para' => $this->para,
                'cc' => $cc,
                'comercial' => $comercial,
                'agendado_para' => $hora->toIso8601String(),
            ]);

            session()->flash('sucesso', "Relatório {$this->relatorio->numero} agendado para {$hora->format('d/m')} às {$hora->format('H:i')}, para {$this->para}.");

            return redirect()->route('relatorios');
        }

        // Envio imediato: se havia um agendado à espera, deixa de valer (senão saíam dois).
        if ($this->relatorio->temEnvioAgendado()) {
            $this->relatorio->update(Relatorio::SEM_AGENDAMENTO);
        }

        EnviarRelatorioPorEmail::dispatch(
            $this->relatorio,
            $this->para,
            trim($this->assunto),
            $this->mensagem,
            $cc,
            null,
            $comercial,
        );

        // Auditoria: emissão de documento oficial ao cliente (CLAUDE.md §11).
        Auditor::registar('relatorio_enviado', $this->relatorio, [
            'numero' => $this->relatorio->numero,
            'para' => $this->para,
            'cc' => $cc,
            'comercial' => $comercial,
        ]);

        session()->flash('sucesso', "Relatório {$this->relatorio->numero} em envio para {$this->para}.");

        return redirect()->route('relatorios');
    }

    // Cancela o envio agendado: o job continua na fila, mas sem o token certo não envia nada.
    public function cancelarAgendamento()
    {
        abort_if(auth()->user()->ehCliente(), 403);

        $this->relatorio->refresh();
        if (! $this->relatorio->temEnvioAgendado()) {
            return null;
        }

        $hora = $this->relatorio->envio_agendado_em;
        $this->relatorio->update(Relatorio::SEM_AGENDAMENTO);

        Auditor::registar('relatorio_envio_agendado_cancelado', $this->relatorio, [
            'numero' => $this->relatorio->numero,
            'agendado_para' => $hora?->toIso8601String(),
        ]);

        session()->flash('sucesso', "Envio agendado do relatório {$this->relatorio->numero} cancelado — nada foi enviado ao cliente.");

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
        return view('livewire.relatorios.enviar', [
            'opcoesEnvio' => self::OPCOES_ENVIO,
            // Sugestões do campo do comercial: os já usados, os mais recentes primeiro.
            'comerciais' => Comercial::orderByDesc('ultimo_uso_em')->limit(50)->pluck('email'),
            // O que vai no aviso — mostra-se antes de enviar.
            'encomendas' => $this->avisarComercial ? ($this->relatorio->intervencao?->rotulosEncomendas() ?? []) : [],
            // Nome do vendedor do cliente no PHC (cl.vendnm) — ajuda a escolher o comercial certo.
            'vendedorPhc' => $this->relatorio->intervencao?->equipamento?->local?->cliente?->vendnm,
        ]);
    }
}
