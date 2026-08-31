<?php

// Parâmetros da agenda.
//
// NÃO há horário de cobertura nem dias úteis: os técnicos não têm horário fixo — trabalho
// noturno, fim-de-semana e serviços que atravessam dias são normais, e a agenda nunca os
// recusa (regra retirada a pedido da equipa, 2026-08-29). O único conflito que a agenda
// deteta é a sobreposição com outro evento do mesmo técnico.
//
// As horas abaixo são só a PROPOSTA por defeito ao abrir um evento novo (o técnico escreve
// depois as horas realmente trabalhadas) e as horas por dia pré-preenchidas nos eventos
// multi-dia.
return [
    'hora_abertura' => env('AGENDA_HORA_ABERTURA', 8),   // 08:00 — hora proposta de início
    'hora_fecho' => env('AGENDA_HORA_FECHO', 19),        // 19:00 — hora proposta de fim
];
