<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Microsoft Graph (envio de email app-only / client-credentials). Segredos só no .env
    // do servidor — nunca em código nem commitados. Ver App\Mail\Transport\GraphTransport.
    'microsoft_graph' => [
        'tenant_id' => env('MS_GRAPH_TENANT_ID'),
        'client_id' => env('MS_GRAPH_CLIENT_ID'),
        'client_secret' => env('MS_GRAPH_CLIENT_SECRET'),
        'sender' => env('MS_GRAPH_SENDER'),

        // Calendário PARTILHADO da agenda no M365 (via Graph, Calendars.ReadWrite): a app escreve
        // os eventos num calendário da mailbox `sender` e partilha-o com a equipa — aparece no
        // Outlook de todos, em tempo real, sem porta aberta nem subscrição por URL. Desligado
        // por defeito: liga-se (MS_GRAPH_CALENDARIO_ATIVO=true) DEPOIS do consentimento de admin
        // à permissão Calendars.ReadWrite — sem ela cada chamada dá 403.
        'calendario_ativo' => (bool) env('MS_GRAPH_CALENDARIO_ATIVO', false),
        'calendario_agenda' => env('MS_GRAPH_CALENDARIO_AGENDA', 'Agenda Nexus Infra'),
    ],

];
