import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// Português do OCR dos talões (resources/js/ocr-leitor.js). O tesseract.js vai buscá-lo a uma
// PASTA — «<langPath>/por.traineddata.gz» — e não a um ficheiro com nome à escolha, por isso não
// pode ir com o nome com hash dos outros assets: publica-se num caminho fixo, com a versão dos
// dados no caminho (se mudar, muda o endereço e nenhuma cache fica com a antiga).
// (Passar os dados diretamente, { code, data }, é o que o tesseract.js diz aceitar, mas na 7.0.0
// o init recebe os bytes em vez do nome da língua e rebenta.)
const DADOS_OCR = '@tesseract.js-data/por/4.0.0_best_int/por.traineddata.gz';
const CAMINHO_OCR = 'ocr/por-4.0.0_best_int/por.traineddata.gz';

function portuguesDoOcr() {
    const origem = require.resolve(DADOS_OCR);

    return {
        name: 'portugues-do-ocr',
        generateBundle() {
            this.emitFile({ type: 'asset', fileName: CAMINHO_OCR, source: fs.readFileSync(origem) });
        },
        configureServer(server) {
            server.middlewares.use((pedido, resposta, seguinte) => {
                if (!pedido.url?.endsWith('/' + CAMINHO_OCR)) return seguinte();
                resposta.setHeader('Content-Type', 'application/octet-stream');
                fs.createReadStream(origem).pipe(resposta);
            });
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        portuguesDoOcr(),
    ],
    define: {
        __CAMINHO_OCR__: JSON.stringify(CAMINHO_OCR.replace(/\/[^/]+$/, '')),
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
