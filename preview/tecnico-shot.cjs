// Verificação visual da vista do técnico (login como técnico).
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000 });
    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'tecnico@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);

    console.log('URL_APOS_LOGIN=' + page.url());
    await new Promise((r) => setTimeout(r, 600));
    await page.screenshot({ path: 'preview/tecnico-painel.png', fullPage: true });

    // Tenta abrir a área de gestão (dashboard) — deve ser reencaminhado.
    await page.goto('http://localhost:8080/dashboard', { waitUntil: 'networkidle0' });
    console.log('URL_TENTA_DASHBOARD=' + page.url());
    await page.goto('http://localhost:8080/contratos', { waitUntil: 'networkidle0' });
    console.log('URL_TENTA_CONTRATOS=' + page.url());

    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
    await browser.close();
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
