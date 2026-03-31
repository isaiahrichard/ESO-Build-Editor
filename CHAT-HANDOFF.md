# ESO Build Editor — Local setup handoff

This document summarizes a working thread: **goals**, **what the stack is**, **what was done**, and **what remains**. Use it as a personal runbook.

---

## Goals

- Run the **UESP ESO build editor**–class tooling **locally**, for **personal use only** (not a public competitor site).
- **Modify** behavior or data as needed.
- Avoid depending on UESP admin / DB dumps where possible.
- Focus especially on the **computed-stats engine** (`rules`, `effects`, `computedStats`).

Non-goals (for this thread): production deployment, full MediaWiki mirror, hosting for other users.

---

## What this repository is (upstream)

- **Source:** [uesp/uesp-esochardata](https://github.com/uesp/uesp-esochardata) — PHP viewers, parsers, build editor, JS/CSS/templates.
- **Live wiki URL:** [Special:EsoBuildEditor](https://en.uesp.net/wiki/Special:EsoBuildEditor) is served via a **separate** MediaWiki extension, not this repo alone.
- **Production** adds: Linux paths (`/home/uesp/esolog.static/`, `/home/uesp/secrets/`), multiple MySQL databases, Memcached/wiki session bridging, and static hosts (`esobuilds-static.uesp.net`, `esolog-static.uesp.net`).

---

## Architecture (how the editor thinks)

### Databases (conceptual)

| Area | Contents | Needed for personal editor? |
|------|----------|-------------------------------|
| **`rules` / `effects` / `computedStats` / `versions`** | Stat engine: when rules apply, what they add to buckets (`Set.*`, `Buff.*`, …), and formulas for each displayed stat | **Yes** |
| **`characters`, `stats`, `skills`, …** | Saved builds (yours + everyone’s on UESP) | **Schema yes**, other people’s rows **no** |
| **`uesp_esolog` mined tables** | Items, skills, tooltips (large) | Often **not local**: browser calls `esolog.uesp.net` APIs for search/export |

### Runtime flow

1. **PHP** loads rule rows from MySQL and embeds JSON into the page (`g_EsoBuildRules`, `g_EsoComputedStats`).
2. **Browser** keeps **live** state: gear slots, skills, buffs, CP, level, attributes (`g_EsoBuildItemData`, DOM, etc.).
3. On change, JS **debounces** and runs **`GetEsoInputValues`** → applies **rules** to fill buckets → evaluates each **`computedStats.compute`** token list → updates the UI.
4. **MySQL is not hit on every gear click**; only definitions came from DB (or embedded once per page load).

---

## Related repositories (fork list)

### Minimum for local PHP + shared libraries

| Repo | URL |
|------|-----|
| `uesp-esochardata` | https://github.com/uesp/uesp-esochardata |
| `uesp-esolog` | https://github.com/uesp/uesp-esolog |

`uesp-esolog` supplies files production mounts as `esolog.static` (`esoCommon.php`, `viewCps.class.php`, `viewSkills.class.php`, `esoSkillRankData.php`, session helpers, etc.). You must **rewire** `require_once` paths and provide **local secrets** (DB credentials).

### Optional

| Repo | URL | When |
|------|-----|------|
| `uesp-wiki-esochardata` | https://github.com/uesp/uesp-wiki-esochardata | Own MediaWiki + `Special:EsoBuildEditor` |
| `uesp-wiki-esoitemlink` | https://github.com/uesp/uesp-wiki-esoitemlink | Self-host item-link assets used by `testBuild.php` / embeds |

**UespEsoSkills** (`uespesoskills.js`) is referenced in `testBuild.php` but is **not** clearly published as a sibling GitHub repo like item link; options: hotlink UESP, copy static files locally, or align with `esolog-static` bundles.

### Reference (not required for editor)

- **[esodata.uesp.net](https://esodata.uesp.net/current/index.html)** — ESO client Lua/API dumps; different from the build-editor rule tables.
- **Bitbucket `uesp/esoapps` (`parseGlobals`)** — cited on esodata for extraction tooling.

---

## Progress made in this workspace

### Exported live engine data (browser)

From the live build editor (after Cloudflare/JS loads), DevTools console:

- `g_EsoBuildRules` → **`rules.json`**
- `g_EsoComputedStats` → **`computedStats.json`** (optional reference; human-friendly parsed shape)

### SQL seed pipeline

| Artifact | Role |
|----------|------|
| **`generate_seed_sql.py`** | Reads **`rules.json`** only; pulls `computedStats` rows from the **`stats`** key inside that file; writes **`seed.sql`**. |
| **`seed.sql`** | Creates `esobuilddata` DB, `versions` / `rules` / `effects` / `computedStats` + empty build tables; inserts exported rule engine data. |

**Note:** `generate_seed_sql.py` declares a path to `computedStats.json` but **`main()` does not read it**; regeneration depends on `rules.json` including a complete **`stats`** section.

### Documentation

- **`README.md`** (repo root) — describes `rules.json`, `computedStats.json`, `seed.sql`, `generate_seed_sql.py`, and workflow.

### Browser automation note

Automated `curl` to UESP often hits **Cloudflare** challenges; a real browser session may be needed to export globals. An attempted `view-source:` navigation hung; manual console export is reliable.

---

## Setup checklist (what “building” means)

There is **no compile step** for the core app — **PHP + static assets + MySQL**.

1. Clone **`uesp-esochardata`** and **`uesp-esolog`** to predictable paths.
2. Create **local secrets** (equivalent to `esolog.secrets` / `esobuilddata.secrets`) with MySQL host, user, password, database name variables expected by `esoCommon.php` / callers.
3. **Replace** hardcoded `/home/uesp/...` `require_once` paths in PHP to point at your clones and secrets.
4. **`mysql < seed.sql`** (or import `seed.sql`) so `rules` / `effects` / `computedStats` / `versions` exist.
5. Serve **`uesp-esochardata`** with PHP (Apache/nginx/php-fpm or `php -S` for quick tests).
6. Open **`testBuild.php`** (or wiki embed once extension is installed).

**Optional:** Change JS URLs that still point at `esolog.uesp.net` / `esobuilds.uesp.net` if you want fully offline item/skill search and save endpoints.

---

## Outstanding / next steps

- [ ] Clone `uesp-esolog` and patch **all** `require_once("/home/uesp/...")` in this repo + esolog’s own includes (e.g. `viewCps.class.php` pulling secrets).
- [ ] Import **`seed.sql`** and verify `editBuild` loads version **49** (or adjust version in DB/UI).
- [ ] (Optional) Extend **`generate_seed_sql.py`** to consume **`computedStats.json`** if `rules.json` ever lacks `stats`.
- [ ] (Optional) Fork **`uesp-wiki-esochardata`** only if you need wiki `Special:` integration.

---

## Quick reference — file purposes

| File | Purpose |
|------|---------|
| `rules.json` | Export of `g_EsoBuildRules`; includes nested **`stats`** = DB-shaped `computedStats`; **input** to `generate_seed_sql.py`. |
| `computedStats.json` | Export of `g_EsoComputedStats` (parsed UI shape); reference / future tooling; **not** read by the generator today. |
| `generate_seed_sql.py` | Produces `seed.sql` from `rules.json`. |
| `seed.sql` | Local MySQL schema + inserts for the stat engine + empty build tables. |
| `README.md` | Project README focused on the above artifacts. |

---

## License

Upstream `uesp-esochardata` is MIT (see `LICENSE`). Exported rule data is UESP-curated; use locally and respect redistribution norms if you ever publish derivatives.

---

*Handoff generated from a Cursor chat thread — merge with your own notes as you implement.*
