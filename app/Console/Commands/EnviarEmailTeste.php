<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

// Teste isolado do envio de email (etapa 1 do rollout do Microsoft Graph): envia um email
// simples para um destinatário, ANTES de ligar os fluxos reais (reset de password / relatório).
// Ex.: php artisan mail:teste alguem@dominio.pt
class EnviarEmailTeste extends Command
{
    protected $signature = 'mail:teste {email : Destinatário do email de teste}';

    protected $description = 'Envia um email de teste (valida o transporte configurado, ex.: Microsoft Graph).';

    public function handle(): int
    {
        $para = $this->argument('email');

        $this->info('A enviar email de teste para '.$para.' via mailer "'.config('mail.default').'"...');

        try {
            Mail::raw('Email de teste do Nexus Ops. Se recebeste isto, o transporte de email está a funcionar.', function ($m) use ($para) {
                $m->to($para)->subject('Nexus Ops — email de teste');
            });
        } catch (\Throwable $e) {
            $this->error('Falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Enviado (sem erros do transporte). Confirma a caixa de entrada.');

        return self::SUCCESS;
    }
}
