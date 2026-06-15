// Verificação visual do painel de alertas (admin).
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1000 });
    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'admin@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);

    await page.goto('http://localhost:8080/alertas', { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 800));
    const nAlertas = await page.$$eval('[wire\\:key^="alerta-"]', (els) => els.length).catch(() => 0);
    await page.screenshot({ path: 'preview/alertas.png', fullPage: true });

    console.log('RESULTADO: alertas_visiveis=' + nAlertas);
    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
    await browser.close();
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
