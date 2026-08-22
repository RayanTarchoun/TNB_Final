/**
 * Rend les sorties texte (PHPUnit, PHPStan, PHP CS Fixer) en images de type
 * terminal, et capture la pipeline GitHub Actions.
 *
 * Les fichiers .txt restent la source de verite ; ces images servent a etre
 * inserees telles quelles dans le dossier de projet.
 *
 * Usage :
 *   npm install puppeteer-core
 *   node tools/capture-sorties.mjs
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';

const OUT = process.env.TNB_OUT || './docs/captures';
const CHROME = process.env.TNB_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const DEPOT = process.env.TNB_DEPOT || 'RayanTarchoun/TNB_Final';

const echapper = (s) =>
    s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Colorise les lignes marquantes de la sortie (succes, erreurs, progression). */
function coloriser(texte) {
    return echapper(texte)
        .split('\n')
        .map((ligne) => {
            if (/\bOK\b|No errors|Found 0 of/.test(ligne)) {
                return `<span class="ok">${ligne}</span>`;
            }
            if (/FAILURES|ERRORS|error/i.test(ligne)) {
                return `<span class="ko">${ligne}</span>`;
            }
            if (/^[.\s]*\d+ \/ \d+/.test(ligne)) {
                return `<span class="pale">${ligne}</span>`;
            }

            return ligne;
        })
        .join('\n');
}

function pageTerminal(titre, contenu) {
    return `<!doctype html><html lang="fr"><head><meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; background: #12100e; padding: 28px; font-family: Consolas, "Cascadia Mono", monospace; }
  .fenetre { background: #1c1917; border-radius: 10px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,.5); }
  .barre { background: #2c2724; padding: 10px 14px; display: flex; align-items: center; gap: 8px; }
  .pastille { width: 12px; height: 12px; border-radius: 50%; }
  .titre { color: #b9b0a6; font-size: 13px; margin-left: 10px; }
  pre { margin: 0; padding: 20px 22px; color: #e6ded4; font-size: 14px; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
  .ok { color: #6fd47e; font-weight: 700; }
  .ko { color: #ff6b6b; font-weight: 700; }
  .pale { color: #8d8378; }
</style></head><body>
  <div class="fenetre">
    <div class="barre">
      <span class="pastille" style="background:#ff5f57"></span>
      <span class="pastille" style="background:#febc2e"></span>
      <span class="pastille" style="background:#28c840"></span>
      <span class="titre">${echapper(titre)}</span>
    </div>
    <pre>${contenu}</pre>
  </div>
</body></html>`;
}

const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--hide-scrollbars'],
});

const produites = [];
const manquantes = [];

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1100, height: 800, deviceScaleFactor: 2 });

    const sorties = [
        ['10_tests_phpunit', 'php bin/phpunit', '10_tests_phpunit.txt'],
        ['10_phpstan', 'vendor/bin/phpstan analyse', '10_phpstan.txt'],
        ['10_cs-fixer', 'vendor/bin/php-cs-fixer fix --dry-run', '10_cs-fixer.txt'],
    ];

    for (const [nom, commande, source] of sorties) {
        const chemin = path.join(OUT, source);

        if (!fs.existsSync(chemin)) {
            manquantes.push(`${nom}.png — fichier source absent : ${source}`);
            continue;
        }

        // Retire l'eventuel BOM laisse par la redirection PowerShell.
        const texte = fs.readFileSync(chemin, 'utf8').replace(/^\uFEFF/, '').trim();

        await page.setContent(pageTerminal(commande, coloriser(texte)), { waitUntil: 'load' });
        const cible = path.join(OUT, `${nom}.png`);

        // On capture la fenetre seule : une capture pleine page laisserait
        // une large bande noire sous le contenu.
        const fenetre = await page.$('.fenetre');
        await fenetre.screenshot({ path: cible });
        produites.push(`${nom}.png`);
        console.log(`  ok  ${nom}.png`);
    }

    // ---------- Pipeline GitHub Actions ----------
    try {
        const reponse = await fetch(
            `https://api.github.com/repos/${DEPOT}/actions/runs?per_page=10&status=success`,
            { headers: { 'User-Agent': 'tnb-captures', Accept: 'application/vnd.github+json' } }
        );
        const donnees = await reponse.json();
        const run = (donnees.workflow_runs || []).find((r) => r.conclusion === 'success');

        if (!run) {
            throw new Error('aucune execution reussie trouvee');
        }

        await page.setViewport({ width: 1440, height: 1000, deviceScaleFactor: 2 });
        await page.goto(run.html_url, { waitUntil: 'networkidle2', timeout: 45000 });
        await new Promise((r) => setTimeout(r, 3000));

        const cible = path.join(OUT, '10_ci_github_actions.png');
        await page.screenshot({ path: cible, fullPage: false });
        produites.push('10_ci_github_actions.png');
        console.log(`  ok  10_ci_github_actions.png  (run #${run.run_number})`);
    } catch (e) {
        manquantes.push(`10_ci_github_actions.png — ${e.message}`);
    }

    await page.close();
} catch (e) {
    manquantes.push(`interruption : ${e.message}`);
} finally {
    await browser.close();
}

console.log(`\n${produites.length} image(s) generee(s) dans ${OUT}`);
if (manquantes.length > 0) {
    console.log('\nA verifier :');
    manquantes.forEach((m) => console.log('  -', m));
}
