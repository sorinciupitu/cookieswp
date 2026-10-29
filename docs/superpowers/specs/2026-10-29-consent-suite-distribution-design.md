# Consent Suite — distribuire dintr-o singură sursă + auto-update via GitHub Releases

Data: 2026-10-29

## Intent / de ce

Pluginul `consent-suite` e folosit pe toate site-urile WordPress ale clienților. Vrem o
sursă unică de adevăr: o versiune nouă publicată într-un singur loc să ajungă pe toate
site-urile instalate, fără să comitem vreodată date specifice unui client în repo.

## Obiective

- Repo GitHub public = sursa unică a codului pluginului.
- Un release (git tag + GitHub Release) face versiunea nouă disponibilă peste tot.
- Fiecare site afișează „Update available" în wp-admin și poate face update
  (opțional, auto-update).
- Repo-ul nu conține zero date sensibile / specifice unui client.

## Non-obiective

- Fără sistem de licențe / chei (repo public — nu e nevoie).
- Fără listare pe wordpress.org.
- Scannerul Node.js (`scanner.js`) NU face parte din pluginul distribuit și NU este
  versionat prin procesul de release — e tooling local/de-ops.
- Nu reparăm în această sarcină bug-urile neînrudite ale scanerului/REST (vezi „Notă").

## Anti-obiective (eșec chiar dacă tehnic merge)

- Un site care nu poate instala efectiv update-ul (nume de folder greșit în zip).
- Date sensibile (`config.json` cu token-uri, URL-uri de site) ajunse accidental în repo.

## Constrângeri

- Mecanismul de update al WordPress: pluginul trebuie să intervină în
  `pre_set_site_transient_update_plugins` (sau să folosească o librărie care o face) și
  să returneze un URL de zip accesibil de orice site instalat.
- Arhivele source ale GitHub (`codeload …/refs/tags/vX.Y.Z.zip`) au folderul rădăcină
  numit `repo-tag`, nu `consent-suite` → sparg update-ul WordPress. Deci trebuie un zip
  construit corect, livrat ca asset de release.
- Repo-ul e public → niciun secret în fișiere; `config.json` al scanerului trebuie ignorat.

## Abordări considerate

- **A — GitHub Releases + librăria `plugin-update-checker` + workflow de release** (aleasă).
  Standardul de facto, robust, rezolvă capcana numelui de folder prin workflow.
- **B — același mecanism, dar cu update-checker scris manual (~120 linii)**.
  Zero dependențe, dar reinventează roata (caching tranzient, folder, rate-limit, testare).
- **C — directorul wordpress.org.** Update-uri native, dar review public, nu pot livra
  scanerul, și nu e „repo-ul tău ca sursă unică".

## Cum se construiește (decizii)

1. **Structura repo-ului**: pluginul rămâne la rădăcină (`consent-suite.php`, `includes/`,
   `public/`, `uninstall.php`). `scanner.js`, `package.json`, `config.example.json` rămân
   ca tooling de dev la rădăcină, dar sunt **excluse** din zip-ul de release. Se adaugă
   `.gitignore` (cel puțin: `config.json`, `node_modules/`, `*.json` de output al scanerului).

2. **Update checker**: se vendorizează librăria YahnisElsts `plugin-update-checker`
   (un singur fișier PHP, în `includes/lib/`). Bootstrap în `consent-suite.php`: construiește
   checker-ul îndreptat către `https://github.com/sorinciupitu/cookieswp`, cu fișierul
   pluginului și slug-ul `consent-suite`.

3. **Workflow de release** (`.github/workflows/release.yml`): pornit la push de tag `v*`.
   Construiește zip cu fișierele la rădăcina zip-ului, sub folderul `consent-suite/`, și îl
   atașează unui GitHub Release ca asset (pluginul îl va prefera față de arhiva source).

4. **Versionare — sursă unică**: `Version:` din header-ul pluginului, constanta `CCM_VERSION`
   și git tag-ul `vX.Y.Z` se bump-uiesc împreună. Se documentează pașii de release
   (opțional un mic script de bump).

5. **Comportamentul de update**: site-urile afișează „Update available", instalarea e
   manuală (adminul dă click); auto-update complet e opțional per-site.
   *Decis cu utilizatorul:* implicit manual, auto-update opt-in.

6. **Reguli pentru date sensibile**: în repo doar `config.example.json` cu placeholder-uri;
   `config.json` real e gitignorat; setările și jurnalele per-site stau în baza de date a
   fiecărui site, niciodată în repo.

## Ce rămâne la implementator (builder)

- Versiunea exactă de API a librăriei `plugin-update-checker` și modul de bootstrap.
- Detaliile YAML ale workflow-ului și scriptul de build al zip-ului (prefix corect de folder).
- `README.md` + instrucțiuni de release pentru agenție.
- Unde exact stă librăria și cum se exclude `scanner.js`/config din zip.

## Notă (în afara scopului, doar semnalat)

Am observat că `class-ccm-rest.php` declară ruta `/ccm/v1/consent` cu callback-uri
`scan_auth` / `ingest_scan` care nu există în clasă, iar `scanner.js` postează către
`/ccm/v1/scan`. Nu intră în această sarcină; menționez ca să nu livrăm release cu cod spart
mai târziu — se poate decide separat dacă se repară acum.
