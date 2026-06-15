// Verificação visual do portal de cliente: login como cliente e fotografar o portal.
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000 });
    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'cliente@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);

    console.log('URL_APOS_LOGIN=' + page.url());
    await page.screenshot({ path: 'preview/portal-dashboard.png', fullPage: true });

    await page.goto('http://localhost:8080/portal/equipamentos', { waitUntil: 'networkidle0' });
    await page.screenshot({ path: 'preview/portal-equipamentos.png', fullPage: true });

    // Confirma que o cliente NÃO acede à app interna (deve ser reencaminhado).
    await page.goto('http://localhost:8080/contratos', { waitUntil: 'networkidle0' });
    console.log('URL_TENTATIVA_APP=' + page.url());

    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
    await browser.close();
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
