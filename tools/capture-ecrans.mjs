/**
 * Capture des ecrans de l'application TNB pour le dossier de projet.
 *
 * Les captures sont prises sur l'IMAGE DE PRODUCTION et non sur le conteneur
 * de developpement : en mode debug, Symfony injecte sa barre d'outils en bas
 * de chaque page, ce qui n'a pas sa place dans un dossier remis a un jury.
 *
 * Prerequis :
 *   - l'application repond sur TNB_URL (defaut http://localhost:8100) ;
 *   - les fixtures sont chargees ;
 *   - puppeteer-core est installe et Chrome present sur la machine.
 *
 * Usage :
 *   npm install puppeteer-core
 *   NODE_PATH=<chemin/node_modules> node tools/capture-ecrans.mjs
 */
import puppeteer from 'puppeteer-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE_URL = process.env.TNB_URL || 'http://localhost:8100';
const OUT = process.env.TNB_OUT || './docs/captures';
const CHROME = process.env.TNB_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';

const ADMIN = {
    email: process.env.TNB_ADMIN_EMAIL || 'admin@tarchoun.fr',
    pass: process.env.TNB_ADMIN_PASS || 'Admin1234!',
};
const CLIENT = {
    email: process.env.TNB_CLIENT_EMAIL || 'client@tarchoun.fr',
    pass: process.env.TNB_CLIENT_PASS || 'Client1234!',
};

const DESKTOP = { width: 1440, height: 900, deviceScaleFactor: 2 };
const MOBILE = { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true };

fs.mkdirSync(OUT, { recursive: true });

const produites = [];
const manquantes = [];

/** Fige les animations pour que deux captures successives soient identiques. */
async function stabiliser(page) {
    await page.addStyleTag({
        content: `*, *::before, *::after { animation: none !important; transition: none !important; }`,
    }).catch(() => {});
    await new Promise((r) => setTimeout(r, 350));
}

async function shot(page, nom, { full = true, attendu = null } = {}) {
    const fichier = path.join(OUT, `${nom}.png`);

    if (attendu) {
        try {
            await page.waitForSelector(attendu, { timeout: 10000 });
        } catch {
            manquantes.push(`${nom} — selecteur introuvable : ${attendu}`);
        }
    }

    await stabiliser(page);
    await page.screenshot({ path: fichier, fullPage: full });

    const taille = fs.statSync(fichier).size;
    if (taille < 8000) {
        manquantes.push(`${nom} — capture suspecte (${taille} octets), page probablement vide`);
    }

    produites.push({ nom: `${nom}.png`, ko: Math.round(taille / 1024) });
    console.log(`  ok  ${nom}.png  (${Math.round(taille / 1024)} Ko)`);
}

async function go(page, route) {
    await page.goto(`${BASE_URL}${route}`, { waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {});
}

/** Connexion par le formulaire reel : le jeton CSRF est soumis avec le form. */
async function login(page, { email, pass }) {
    await go(page, '/connexion');
    await page.waitForSelector('#email', { timeout: 15000 });
    await page.type('#email', email);
    await page.type('#motDePasse', pass);
    await Promise.all([
        page.click('form button[type="submit"]'),
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
    ]);

    const connecte = await page.$('.navbar .dropdown-toggle');
    if (!connecte) {
        throw new Error(`Connexion echouee pour ${email}`);
    }
}

const browser = await puppeteer.launch({
    headless: 'new',
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--hide-scrollbars', '--force-color-profile=srgb'],
});

/**
 * Contexte isole par role : sans cela, tous les onglets partagent les memes
 * cookies et la session du client empeche la connexion de l'administrateur
 * (/connexion redirige un utilisateur deja authentifie).
 */
async function nouveauContexte() {
    const creer = browser.createBrowserContext ?? browser.createIncognitoBrowserContext;

    return creer.call(browser);
}

try {
    // ---------------- 1. Pages publiques ----------------
    console.log('Pages publiques…');
    const pub = await browser.newPage();

    await pub.setViewport(DESKTOP);
    await go(pub, '/');
    await shot(pub, '05_accueil_desktop', { attendu: '.tnb-hero' });

    await go(pub, '/produits');
    await shot(pub, '05_catalogue_desktop', { attendu: '.tnb-carte' });

    // Fiche produit : on suit le lien du nom de la premiere carte.
    const lien = await pub.$('.tnb-carte-nom a');
    if (lien) {
        await Promise.all([
            lien.click(),
            pub.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
        ]);
        await shot(pub, '05_fiche_produit', { attendu: '.tnb-prix' });
    } else {
        manquantes.push('05_fiche_produit — aucun lien de produit trouve au catalogue');
    }

    await go(pub, '/connexion');
    await shot(pub, '09_connexion', { full: false, attendu: '#motDePasse' });

    await go(pub, '/inscription');
    await shot(pub, '09_inscription', { attendu: 'form[name="inscription"]' });

    // Versions mobiles
    await pub.setViewport(MOBILE);
    await go(pub, '/');
    await shot(pub, '05_accueil_mobile', { attendu: '.navbar-toggler' });

    await go(pub, '/produits');
    await shot(pub, '05_catalogue_mobile', { attendu: '.tnb-carte' });

    await pub.close();

    // ---------------- 2. Espace client ----------------
    console.log('Espace client…');
    const contexteClient = await nouveauContexte();
    const cli = await contexteClient.newPage();
    await cli.setViewport(DESKTOP);
    await login(cli, CLIENT);

    // Remplit le panier via le vrai formulaire (jeton CSRF inclus).
    await go(cli, '/produits');
    for (const n of [0, 1]) {
        const boutons = await cli.$$('form[action^="/panier/ajouter/"] button[type="submit"]:not([disabled])');
        if (boutons[n]) {
            await Promise.all([
                boutons[n].click(),
                cli.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}),
            ]);
            await go(cli, '/produits');
        }
    }

    await go(cli, '/panier');
    await shot(cli, '05_panier', { attendu: '.tnb-total' });

    await go(cli, '/mes-commandes');
    await shot(cli, '05_mes_commandes', { attendu: 'table' });

    await go(cli, '/profil');
    await shot(cli, '05_profil', { attendu: 'form' });

    await cli.close();
    await contexteClient.close();

    // ---------------- 3. Back-office ----------------
    console.log('Back-office…');
    const contexteAdmin = await nouveauContexte();
    const adm = await contexteAdmin.newPage();
    await adm.setViewport(DESKTOP);
    await login(adm, ADMIN);

    const ecransAdmin = [
        ['/admin', '05_admin_dashboard', '.tnb-indicateur'],
        ['/admin/produits', '05_admin_produits', 'table'],
        ['/admin/stocks', '05_admin_stocks', '.tnb-jauge'],
        ['/admin/commandes', '05_admin_commandes', '.tnb-badge'],
        ['/admin/categories', '05_admin_categories', 'table'],
    ];

    for (const [route, nom, attendu] of ecransAdmin) {
        await go(adm, route);
        await shot(adm, nom, { attendu });
    }

    await adm.close();
    await contexteAdmin.close();
} catch (e) {
    console.error('Erreur :', e.message);
    manquantes.push(`interruption : ${e.message}`);
} finally {
    await browser.close();
}

console.log(`\n${produites.length} capture(s) dans ${OUT}`);
if (manquantes.length > 0) {
    console.log('\nA verifier :');
    manquantes.forEach((m) => console.log('  -', m));
}
