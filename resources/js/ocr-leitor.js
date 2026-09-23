// Leitura do TEXTO do talão (OCR) no próprio telemóvel — carregada à parte (import dinâmico no
// app.js), só quando alguém junta um recibo. Tesseract (tesseract.js), em WebAssembly: gratuito,
// sem servidor de IA, e a fotografia não sai do telemóvel. O texto segue para o servidor
// (Editor::lerTalao), que tira dele a loja, a terra, o tipo e a hora.
//
// Por omissão o tesseract.js vai buscar o motor, o trabalhador e o português a uma CDN pública
// (cdn.jsdelivr.net) — o CSP da aplicação (connect-src 'self') bloqueava-o, e mandava um pedido
// para fora a cada recibo. Tudo vem da mesma origem, publicado pelo Vite com a aplicação (cerca
// de 4 MB na primeira vez; depois fica na cache do browser). O português tem caminho fixo —
// ver portuguesDoOcr() no vite.config.js.
import { createWorker } from 'tesseract.js';
import { simd } from 'wasm-feature-detect';
import trabalhadorUrl from 'tesseract.js/dist/worker.min.js?url';
import motorSimdUrl from 'tesseract.js-core/tesseract-core-simd-lstm.wasm.js?url';
import motorUrl from 'tesseract.js-core/tesseract-core-lstm.wasm.js?url';

// Largura a que se lê: as letras de um talão ficam com ~20 px de altura, que é onde o Tesseract
// acerta mais; maior só o torna mais lento.
const LARGURA = 1400;

let trabalhador = null;

function motor() {
    trabalhador ??= (async () =>
        createWorker('por', 1 /* só LSTM */, {
            workerPath: trabalhadorUrl,
            corePath: (await simd()) ? motorSimdUrl : motorUrl,
            langPath: new URL(import.meta.env.BASE_URL + __CAMINHO_OCR__, location.origin).href,
            workerBlobURL: false, // o trabalhador vem do próprio ficheiro, não de um blob:
            cacheMethod: 'none',  // a cache é a do browser (o ficheiro publicado)
        })
    )().catch((e) => {
        trabalhador = null; // tenta outra vez no recibo seguinte
        throw e;
    });

    return trabalhador;
}

export async function lerTexto(tela) {
    const w = await motor();
    const { data } = await w.recognize(reduzida(tela));

    return data.text ?? '';
}

function reduzida(tela) {
    if (tela.width <= LARGURA) return tela;
    const s = LARGURA / tela.width;
    const nova = document.createElement('canvas');
    nova.width = LARGURA;
    nova.height = Math.round(tela.height * s);
    nova.getContext('2d').drawImage(tela, 0, 0, nova.width, nova.height);

    return nova;
}
