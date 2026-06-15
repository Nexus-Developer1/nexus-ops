// Verificação visual da conversão evento→intervenção: login, abrir evento na
// agenda, "Iniciar visita" e confirmar a navegação para o formulário de intervenção.
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1100 });

    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'admin@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);

    await page.goto('http://localhost:8080/agenda', { waitUntil: 'networkidle0' });
    await page.waitForSelector('.fc-event', { timeout: 15000 });
    await new Promise((r) => setTimeout(r, 1500));

    // Clicar no evento → abre o painel de detalhe.
    await page.click('.fc-event');
    await page.waitForFunction(() => document.body.innerText.includes('Iniciar visita'), { timeout: 8000 });
    await page.screenshot({ path: 'preview/conversao-modal.png', fullPage: true });

    // Clicar "Iniciar visita" → deve navegar para /intervencoes/{id}.
    const botoes = await page.$$('button');
    for (const b of botoes) {
        const t = await page.evaluate((el) => el.innerText, b);
        if (t.includes('Iniciar visita')) { await b.click(); break; }
    }
    await page.waitForFunction(() => location.pathname.startsWith('/intervencoes/'), { timeout: 10000 });
    await new Promise((r) => setTimeout(r, 1500));
    await page.screenshot({ path: 'preview/conversao-intervencao.png', fullPage: true });

    console.log('RESULTADO: url_final=' + page.url());
    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
    await browser.close();
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
