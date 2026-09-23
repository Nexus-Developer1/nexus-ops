// Porta de entrada do leitor de QR no browser — carregada à parte (import dinâmico no app.js),
// só quando alguém junta um recibo.
//
// O motor do zxing-cpp é um ficheiro .wasm que, por omissão, o pacote vai buscar a uma CDN
// pública (fastly.jsdelivr.net). Isso mandava um pedido para fora a cada recibo e o CSP da
// aplicação (connect-src 'self') bloqueava-o. Aponta-se para a cópia que o Vite publica junto
// com a aplicação, na mesma origem.
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';
import { lerQrDosPixeis, prepararMotor } from './qr-fatura.js';

prepararMotor({
    locateFile: (ficheiro, prefixo) => (ficheiro.endsWith('.wasm') ? wasmUrl : prefixo + ficheiro),
});

export { lerQrDosPixeis };
