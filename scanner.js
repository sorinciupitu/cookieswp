#!/usr/bin/env node
/**
 * CCM Headless Cookie Scanner
 * Scans sites in a real browser, captures ALL cookies (incl. third-party
 * Set-Cookie), classifies them using the site's own data-ccm-category
 * markers + built-in dictionaries, and pushes results to WordPress.
 *
 * Usage:  node scanner.js [--config config.json] [--site clientsite.com]
 */
'use strict';

const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

/* ---------------- config ---------------- */
const argv    = process.argv.slice(2);
const cfgArg  = argv.indexOf('--config');
const siteArg = argv.indexOf('--site');
const config  = JSON.parse(
    fs.readFileSync(cfgArg > -1 ? argv[cfgArg + 1] : path.join(__dirname, 'config.json'), 'utf8')
);
let sites = config.sites || [];
if (siteArg > -1) sites = sites.filter(s => s.url.includes(argv[siteArg + 1]));

const sleep = ms => new Promise(r => setTimeout(r, ms));

/* ---------------- classification dictionaries ---------------- */
// [domain needle, provider, category]
const DOMAIN_MAP = [
    ['google-analytics.com',   'Google',                 'analytics'],
    ['googletagmanager.com',   'Google Tag Manager',     'analytics'],
    ['doubleclick.net',        'Google (Doubleclick)',   'advertisement'],
    ['googlesyndication.com',  'Google AdSense',         'advertisement'],
    ['googleadservices.com',   'Google Ads',             'advertisement'],
    ['connect.facebook.net',   'Meta',                   'advertisement'],
    ['facebook.com',           'Meta',                   'advertisement'],
    ['analytics.tiktok.com',   'TikTok',                 'advertisement'],
    ['ads.linkedin.com',       'LinkedIn',               'advertisement'],
    ['snap.licdn.com',         'LinkedIn',               'advertisement'],
    ['clarity.ms',             'Microsoft Clarity',      'analytics'],
    ['bat.bing.com',           'Microsoft (Bing)',       'analytics'],
    ['hotjar.com',             'Hotjar',                 'analytics'],
    ['cloudflareinsights.com', 'Cloudflare',             'analytics'],
    ['hubspot',                'HubSpot',                'analytics'],
    ['hs-scripts.com',         'HubSpot',                'functional'],
    ['intercom',               'Intercom',               'functional'],
    ['zendesk',                'Zendesk',                'functional'],
    ['matomo',                 'Matomo',                 'analytics'],
    ['mc.yandex.ru',           'Yandex',                 'analytics'],
    ['criteo',                 'Criteo',                 'advertisement'],
    ['taboola',                'Taboola',                'advertisement'],
    ['outbrain',               'Outbrain',               'advertisement'],
    ['amazon-adsystem.com',    'Amazon Ads',             'advertisement'],
    ['youtube.com',            'YouTube',                'functional'],
    ['youtube-nocookie.com',   'YouTube',                'functional'],
    ['player.vimeo.com',       'Vimeo',                  'functional'],
    ['google.com',             'Google',                 'functional'],
    ['gstatic.com',            'Google',                 'functional'],
    ['recaptcha',              'Google reCAPTCHA',       'functional'],
    ['pinterest.com',          'Pinterest',              'advertisement'],
];

// name → [provider, category];  '*' suffix = prefix match (e.g. _ga_*)
const COOKIE_MAP = {
    '_ga':            ['Google',                 'analytics'],
    '_ga_*':          ['Google',                 'analytics'],
    '_gid':           ['Google',                 'analytics'],
    '_gat':           ['Google',                 'analytics'],
    '_gcl_au':        ['Google Ads',             'advertisement'],
    '_gcl_aw':        ['Google Ads',             'advertisement'],
    'IDE':            ['Google (Doubleclick)',   'advertisement'],
    'test_cookie':    ['Google (Doubleclick)',   'advertisement'],
    'DSID':           ['Google (Doubleclick)',   'advertisement'],
    'NID':            ['Google',                 'advertisement'],
    '_fbp':           ['Meta',                   'advertisement'],
    'fr':             ['Meta',                   'advertisement'],
    'undefined':      ['Meta',                   'advertisement'], // yes, Meta really sets this
    'ttclid':         ['TikTok',                 'advertisement'],
    '_ttp':           ['TikTok',                 'advertisement'],
    'li_sugr':        ['LinkedIn',               'advertisement'],
    'bcookie':        ['LinkedIn',               'advertisement'],
    'lidc':           ['LinkedIn',               'functional'],
    '_uetvid':        ['Microsoft (Bing)',       'advertisement'],
    '_uetsid':        ['Microsoft (Bing)',       'analytics'],
    '_clck':          ['Microsoft Clarity',      'analytics'],
    '_clsk':          ['Microsoft Clarity',      'analytics'],
    '_hjSessionUser': ['Hotjar',                 'analytics'],
    '_hjSession':     ['Hotjar',                 'analytics'],
    'hubspotutk':     ['HubSpot',                'analytics'],
    '__hstc':         ['HubSpot',                'analytics'],
    '__hssrc':        ['HubSpot',                'analytics'],
    '__hssc':         ['HubSpot',                'analytics'],
    'messagesUtk':    ['HubSpot',                'functional'],
    'VISITOR_INFO1_LIVE': ['YouTube',            'functional'],
    'YSC':            ['YouTube',                'functional'],
    'vuid':           ['Vimeo',                  'functional'],
    'wordpress_*':    ['WordPress',              'functional'],
    'wp-settings-*':  ['WordPress',              'functional'],
};

/* ---------------- helpers ---------------- */
function base(host) {
    const p = String(host).replace(/^\./, '').split('.');
    return p.length > 2 ? p.slice(-2).join('.') : host;
}

function matchKey(map, name) {
    if (map[name]) return map[name];
    for (const k of Object.keys(map)) {
        if (k.endsWith('*') && name.startsWith(k.slice(0, -1))) return map[k];
    }
    return null;
}

/** Precedence: 1) the site's own blocker markers, 2) cookie name, 3) domain dictionary */
function classifyCookie(name, domain, domCats) {
    if (domain) {
        const b = base(domain);
        if (domCats[b]) return { provider: b, category: domCats[b] };
    }
    const byName = matchKey(COOKIE_MAP, name);
    if (byName) return { provider: byName[0], category: byName[1] };
    if (domain) {
        for (const [needle, provider, cat] of DOMAIN_MAP) {
            if (domain.includes(needle)) return { provider, category: cat };
        }
    }
    return { provider: domain ? base(domain) : '', category: 'unclassified' };
}

function classifyHost(host) {
    for (const [needle, provider, cat] of DOMAIN_MAP) {
        if (host.includes(needle)) return [provider, cat];
    }
    return [base(host), 'unclassified'];
}

function humanExpiry(c) {
    if (!c.expires || c.expires < 0) return 'session';
    const days = Math.round((c.expires * 1000 - Date.now()) / 86400000);
    if (days <= 1) return '1 day';
    if (days < 60) return days + ' days';
    const months = Math.round(days / 30);
    if (months < 24) return months + ' months';
    const years = Math.max(1, Math.round(days / 365));
    return years + (years === 1 ? ' year' : ' years');
}

function isThirdParty(host, baseUrl) {
    const h = host.replace(/^www\./, '');
    const b = baseUrl.hostname.replace(/^www\./, '');
    return h !== b && !h.endsWith('.' + b);
}

function trackRequest(result, pageUrl, reqUrl, baseUrl) {
    try {
        const u = new URL(reqUrl);
        if (!/^https?:$/.test(u.protocol)) return;
        if (!isThirdParty(u.hostname, baseUrl)) return;
        const host = base(u.hostname);
        const e = result.requests.get(host) || { pages: new Set(), count: 0 };
        e.pages.add(pageUrl);
        e.count++;
        result.requests.set(host, e);
    } catch (err) { /* ignore */ }
}

async function autoScroll(page) {
    await page.evaluate(() => new Promise(resolve => {
        let total = 0;
        const step = () => {
            window.scrollBy(0, 600);
            total += 600;
            if (total >= (document.body ? document.body.scrollHeight : 0) || total > 8000) {
                window.scrollTo(0, 0);
                return resolve();
            }
            setTimeout(step, 250);
        };
        step();
    }));
}

/** Read the site's OWN blocking markers → domain → category map (most accurate source) */
async function collectDomCategories(page, result) {
    const pairs = await page.evaluate(() =>
        [...document.querySelectorAll('[data-ccm-category]')].map(el => [
            (el.getAttribute('src') || el.getAttribute('data-ccm-src') || el.textContent || '').slice(0, 500),
            el.getAttribute('data-ccm-category'),
        ])
    );
    for (const [str, cat] of pairs) {
        const m = String(str).match(/https?:\/\/([^/"'\s>]+)/);
        if (m && cat) result.domCats[base(m[1])] = cat;
    }
}

/** Grab every cookie in the browser context (incl. third-party Set-Cookie) */
async function grabCookies(page, result, pageUrl) {
    const client = await page.createCDPSession();
    let cookies = [];
    try {
        ({ cookies } = await client.send('Network.getAllCookies'));
    } catch (e) {
        ({ cookies } = await client.send('Storage.getCookies'));
    }
    await client.detach();

    for (const c of cookies) {
        const domain = c.domain || '';
        const key = c.name + '|' + domain;
        const entry = result.cookies.get(key) || {
            name: c.name, domain, provider: '', category: '',
            ctype: 'http', expiry: '', pages: new Set(), phases: new Set(),
        };
        entry.pages.add(pageUrl);
        entry.phases.add(result.phase);
        entry.expiry = humanExpiry(c);
        result.cookies.set(key, entry);
    }
}

async function getPageUrls(siteUrl, limit) {
    const urls = [siteUrl.replace(/\/+$/, '') + '/'];
    try {
        const xml = await (await fetch(new URL('/wp-sitemap.xml', siteUrl))).text();
        for (const m of xml.matchAll(/<loc>([^<]+)<\/loc>/g)) {
            const loc = m[1];
            if (/\.xml($|\?)/.test(loc)) {
                try { // sitemap index → children
                    const child = await (await fetch(loc)).text();
                    for (const c of child.matchAll(/<loc>([^<]+)<\/loc>/g)) {
                        if (urls.length >= limit) break;
                        if (!/\.xml($|\?)/.test(c[1])) urls.push(c[1]);
                    }
                } catch (e) {}
                continue;
            }
            if (urls.length < limit) urls.push(loc);
        }
    } catch (e) { /* no sitemap — homepage only */ }
    return [...new Set(urls)].slice(0, limit);
}

/* ---------------- scan one site ---------------- */
async function scanSite(site, browser) {
    const baseUrl = new URL(site.url);
    const result = {
        site: site.url, scanned_at: new Date().toISOString(), phase: 'pre',
        cookies: new Map(), requests: new Map(), domCats: {},
    };
    const urls = await getPageUrls(site.url, site.maxPages || config.maxPages || 25);
    console.log(`   ${urls.length} page(s) to scan`);

    for (const url of urls) {
        // Fresh context per page → clean cookie state → accurate per-page attribution
        const context = browser.createBrowserContext
            ? await browser.createBrowserContext()
            : await browser.createIncognitoContext();
        const page = await context.newPage();
        page.setDefaultNavigationTimeout(45000);

        try {
            const ua = await browser.userAgent();
            await page.setUserAgent(ua.replace('HeadlessChrome/', 'Chrome/'));
        } catch (e) {}

        // skip assets that never matter for cookies; log every third-party request
        await page.setRequestInterception(true);
        page.on('request', req => {
            const t = req.resourceType();
            if (t === 'font' || t === 'media') return req.abort();
            trackRequest(result, url, req.url(), baseUrl);
            req.continue();
        });

        try {
            await page.goto(url, { waitUntil: 'networkidle2' });
        } catch (e) {
            console.warn('   ! navigation issue:', url, '-', e.message.split('\n')[0]);
        }

        await autoScroll(page);
        await sleep(config.waitMs || 6000);

        await collectDomCategories(page, result);
        result.phase = 'pre';
        await grabCookies(page, result, url);

        // pass 2: consent → blocked tags fire → attribute them via domCats
        if (site.acceptSelector) {
            try {
                await page.click(site.acceptSelector);
                await sleep(config.waitMs || 6000);
                result.phase = 'post';
                await grabCookies(page, result, url);
            } catch (e) { /* banner not found / already handled — fine */ }
        }

        await context.close();
        console.log(`   • ${url}  (${result.cookies.size} cookies so far)`);
    }
    return finalize(result);
}

function finalize(result) {
    const cookies = [...result.cookies.values()].map(c => {
        const cls = classifyCookie(c.name, c.domain, result.domCats);
        return {
            name: c.name,
            domain: c.domain,
            provider: cls.provider,
            category: cls.category,
            ctype: 'http',
            expiry: c.expiry,
            pages: [...c.pages],
            post_consent_only: !c.phases.has('pre'),   // = was blocked by consent → non-essential
        };
    });
    const tags = {};
    for (const [host, e] of result.requests) {
        const [provider, category] = classifyHost(host);
        tags[host] = { count: e.count, provider, category, pages: e.pages.size };
    }
    return { site: result.site, scanned_at: result.scanned_at, cookies, tags };
}

async function pushToWp(site, payload) {
    const res = await fetch(site.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CCM-Token': site.token },
        body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`WP push failed ${res.status}: ${(await res.text()).slice(0, 200)}`);
    return res.json();
}

/* ---------------- main ---------------- */
(async () => {
    if (!sites.length) { console.error('No sites configured.'); process.exit(1); }
    console.log(`Scanning ${sites.length} site(s)…`);

    const browser = await puppeteer.launch({
        headless: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });

    for (const site of sites) {
        console.log('\n▶ ' + site.url);
        try {
            const payload = await scanSite(site, browser);
            const summary = `${payload.cookies.length} cookies, ${Object.keys(payload.tags).length} third-party hosts`;
            if (site.endpoint && site.token) {
                const r = await pushToWp(site, payload);
                console.log(`✔ pushed to WP — ${summary} (added: ${r.added}, updated: ${r.updated})`);
            } else {
                fs.mkdirSync(path.join(__dirname, 'scans'), { recursive: true });
                const out = path.join('scans', site.url.replace(/[^\w.-]+/g, '_') + '.json');
                fs.writeFileSync(out, JSON.stringify(payload, null, 2));
                console.log(`✔ saved to ${out} — ${summary}`);
            }
        } catch (e) {
            console.error('✖ ' + site.url + ' — ' + e.message);
        }
        await sleep(2000); // be gentle
    }
    await browser.close();
})();