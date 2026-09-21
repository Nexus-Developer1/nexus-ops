<?php

// Textos da barra de páginas (a do Livewire, nas listagens). A aplicação sempre esteve em
// `pt`, mas sem a pasta `lang/` o Laravel devolvia as chaves tal como estão — em inglês
// («Showing 1 to 10 of 202068 results», «Previous», «Next»). Ver também lang/pt.json.
return [
    'previous' => '&laquo; Anterior',
    'next' => 'Seguinte &raquo;',
];
