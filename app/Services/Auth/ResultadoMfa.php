<?php

namespace App\Services\Auth;

// Resultado da validação de um código MFA. O componente traduz cada caso na mensagem
// a mostrar ao utilizador.
enum ResultadoMfa
{
    case Ok;
    case Incorreto;
    case Expirado;
    case DemasiadasTentativas;
}
