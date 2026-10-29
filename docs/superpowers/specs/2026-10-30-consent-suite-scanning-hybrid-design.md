# Consent Suite — Scanare hibridă (on-site default + headless la cerere)

Data: 2026-10-30

## Intent / de ce

Scanarea cookie-urilor trebuie să meargă fără un server central permanent: scanerul
on-site PHP (WP-Cron) rămâne automat și default pe fiecare site, iar scanerul headless
(browser real) devine un tool local opțional, rulat la cerere. Pentru asta trebuie reparat
stratul REST și adăugată o cheie per-site.

## Obiective

- Scanerul on-site (`CCM_Scanner`) rulează automat pe fiecare site, fără dependențe
  externe, cu un dicționar de clasificare îmbunătățit (livrat prin update-urile GitHub).
- Scanerul headless poate trimite rezultate complete (inclusiv cookie-uri third-party) pe
  site via REST, autentificat cu o cheie per-site.
- Consimțământul se loghează corect (azi ruta e ruptă).

## Non-obiective

- Fără server central 24/7 pentru scanare.
- Fără scaner headless inclus în zip-ul pluginului (rămâne tool local, în afara release-ului).
- Nu refacem schema DB (rămân `ccm_cookies` și `ccm_consent_logs`).

## Anti-obiective (eșec chiar dacă tehnic merge)

- O rută REST care acceptă scriere fără autentificare corectă (oricine poate injecta
  cookie-uri sau consimțământ fals).
- Headless-ul care nu poate trimite deloc (ruta `/scan` lipsește / token inexistent).

## Constatări din cod (ce e rupt azi)

1. `class-ccm-rest.php` înregistrează `/ccm/v1/consent` cu `permission_callback = scan_auth`
   și `callback = ingest_scan` — ambele metode lipsesc. Metoda `log()` există, dar nu e legată.
2. `scanner.js` postează la `/ccm/v1/scan` cu header `X-CCM-Token`, dar ruta `/scan` nu există.
3. Niciun token nu e generat/afișat nicăieri (`config.example.json` zice „PASTE_TOKEN_FROM_WP_ADMIN").
4. Dicționarul on-site `CCM_Scanner::known()` are ~12 intrări vs ~60 în `scanner.js`.

## Cum (decizii)

1. **Ruta `/consent` (logare consimțământ)**: `permission_callback` devine public
   (`__return_true`), `callback` devine `log()` existent. Consimțământul vine de la frontend,
   anonimizat, fără autentificare — normal.
2. **Ruta `/scan` (ingestie scaner headless)**: nouă, POST, `permission_callback` verifică
   header-ul `X-CCM-Token` față de un token per-site (cu `hash_equals`). Acceptă payload-ul
   din `scanner.js` (`cookies[]`) și face upsert în `ccm_cookies` după `name` (provider,
   category, ctype, expiry, pages → newline, source='auto', status='active'). `tags` se ignoră
   în prima fază.
3. **Token per-site**: generat la activare (`wp_generate_password(48, false)`), stocat în
   opțiunea `ccm_scan_token`, afișat în pagina de setări (readonly).
   *Decizia mea:* cheie per-site, nu IP-allowlist.
4. **Dicționar on-site**: extind `CCM_Scanner::known()` cu lista din `scanner.js`
   (COOKIE_MAP + DOMAIN_MAP). *Decizia mea:* o fac acum; duplicarea se poate unifica mai târziu
   într-un JSON comun.
5. **Headless local**: `scanner.js` rămâne tool local; `config.json` (gitignorat) conține URL +
   token per site. Se documentează în README.

## Ce rămâne la implementator (builder)

- Contractul JSON exact al rutei `/scan` și mappingul pe coloanele `ccm_cookies`.
- Unde exact se afișează tokenul în pagina de setări și dacă are buton de regenerare.
- Modul exact de extindere a dicționarului (inline PHP vs. o sursă partajată).
