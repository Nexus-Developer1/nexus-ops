// Verificação visual da Agenda: faz login e fotografa o calendário com eventos.
// Uso: node preview/agenda-shot.cjs <png>
const puppeteer = require('puppeteer');

(async () => {
    const out = process.argv[2] || 'preview/agenda.png';
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1100 });

    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

    // Login (Livewire).
    await page.goto('http://localhost:8080/login', { waitUntil: 'networkidle0' });
    await page.type('input[type="email"]', 'admin@nexus.pt');
    await page.type('input[type="password"]', 'password');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);

    // Agenda.
    await page.goto('http://localhost:8080/agenda', { waitUntil: 'networkidle0' });
    await page.waitForSelector('.fc', { timeout: 15000 });
    await new Promise((r) => setTimeout(r, 2500)); // deixar o FullCalendar pintar eventos

    const numEventos = await page.$$eval('.fc-event', (els) => els.length);
    const temToolbar = await page.$('.fc-toolbar-title') !== null;

    await page.screenshot({ path: out, fullPage: true });
    await browser.close();

    console.log('RESULTADO: fc_toolbar=' + temToolbar + ' eventos_visiveis=' + numEventos);
    console.log('ERROS_CONSOLA=' + (erros.length ? JSON.stringify(erros) : 'nenhum'));
})().catch((e) => { console.error('FALHOU:', e.message); process.exit(1); });
