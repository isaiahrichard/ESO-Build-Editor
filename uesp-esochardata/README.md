# UESP ESO character & build data (local notes)

Upstream: [uesp/uesp-esochardata](https://github.com/uesp/uesp-esochardata) — PHP/JavaScript build editor, viewers, and parsers used on UESP.

This README documents **local artifacts** for running the **computed-stats / rules engine** offline: exported JSON, the MySQL seed, and the generator script.

---

## `rules.json`

**What it is:** A snapshot of `g_EsoBuildRules` as returned from the live UESP build editor page (browser DevTools → copy `JSON.stringify(g_EsoBuildRules, null, 2)`).

**Structure (top level):**

| Key | Meaning |
|-----|--------|
| `buff`, `set`, `cp`, `passive`, `mundus`, `active`, `armorenchant`, `weaponenchant`, `offhandweaponenchant`, `abilitydesc` | Rule buckets. Each value is an object keyed by numeric rule id → full rule row plus nested `effects[]`. |
| `effects` | Flat list mirroring DB `effects` (also embedded per-rule). |
| `stats` | **Database-shaped** `computedStats` rows: `compute` and `dependsOn` are **strings** (JSON text), matching what MySQL stores. |

**Used by:** `generate_seed_sql.py` — this file is the **only** input the script reads today.

**Size:** Very large (~tens of thousands of lines). Treat as data, not hand-edited source.

**License / redistribution:** Game-adjacent curated data; use for personal/local tooling. Do not assume you may republish UESP’s full rule dump as a competing public dataset without their permission.

---

## `computedStats.json`

**What it is:** A snapshot of `g_EsoComputedStats` from the same page (browser → `JSON.stringify(g_EsoComputedStats, null, 2)`).

**Structure:** An object keyed by stat name or section labels (e.g. `"Basic Stats": "StartSection"`). Each real stat is an object with `statId`, `version`, `title`, `compute` as a **JavaScript array** of formula tokens, `depends`, `round`, runtime `value`, etc. — i.e. the **in-memory** shape after the page has loaded.

**Relationship to the DB:** Logically the same stats as the `computedStats` table / the `stats` section inside `rules.json`, but **not** the same JSON encoding (DB uses stringified `compute` / `dependsOn`).

**Used by:** Human inspection, diffing, or custom tools. **`generate_seed_sql.py` does not read this file** (it uses the `stats` block inside `rules.json` for SQL generation). If you regenerate `rules.json` and the `stats` section is missing or stale, keep `computedStats.json` as reference or extend the script to merge from it.

---

## `seed.sql`

**What it is:** Generated MySQL script that:

1. Creates database `esobuilddata` (utf8mb4).
2. Creates tables: `versions`, `rules`, `effects`, `computedStats`, plus empty **build** tables (`characters`, `stats`, `skills`, `buffs`, `championPoints`, `actionBars`, `inventory`, `equipSlots`, `combatActions`, `screenshots`, `cache`) for local saves.
3. Inserts rows for the current rules version (e.g. `49`), all `computedStats`, all `rules`, and all `effects`.

**How to apply:**

```bash
mysql -u root -p < seed.sql
```

**How the editor uses it:** PHP (`editBuild.class.php` / `viewBuildData.class.php`) loads `rules`, `effects`, `computedStats`, and `versions` for the active version, then embeds them into the page as JSON for `esoEditBuild.js` to evaluate when gear, skills, or buffs change.

**Regeneration:** Run `python generate_seed_sql.py` after updating `rules.json`.

---

## `generate_seed_sql.py`

**Purpose:** Convert exported `rules.json` into `seed.sql` (schema + inserts).

**Inputs:**

- **`rules.json`** (required) — must include the top-level **`stats`** object for `computedStats` inserts.

**Outputs:**

- **`seed.sql`** (overwritten each run).

**Usage:**

```bash
python generate_seed_sql.py
```

**Implementation notes:**

- Rule types written to SQL are limited to the set `buff`, `set`, `cp`, `passive`, `mundus`, `active`, `armorenchant`, `weaponenchant`, `offhandweaponenchant`, `abilitydesc` (other top-level keys in `rules.json` are ignored).
- Inserts are chunked (500 rows) to keep statements manageable.
- A module-level `COMPUTED_STATS_FILE` constant exists for clarity; **`main()` currently does not load `computedStats.json`** — if you need the seed to follow that file instead, extend the script or ensure `rules.json`’s `stats` section stays in sync with your export workflow.

---

## Typical workflow

1. Open [UESP ESO Build Editor](https://en.uesp.net/wiki/Special:EsoBuildEditor) in a browser (must complete any bot challenges).
2. In DevTools console, save:
   - `g_EsoBuildRules` → `rules.json`
   - optionally `g_EsoComputedStats` → `computedStats.json` (for reference)
3. Run `python generate_seed_sql.py`.
4. Import `seed.sql` into local MySQL and point your local PHP config at that database (see upstream repo and `uesp-esolog` for `require_once` / secrets layout).

---

## Local run (this workspace)

1. Import DB: `mysql -u root -p < seed.sql` (creates `esobuilddata`).
2. Adjust credentials in the workspace `secrets/*.secrets.php` files if needed (defaults: `127.0.0.1`, `root`, empty password).
3. From this directory: `php -S localhost:8080` then open `http://localhost:8080/testBuild.php`.

With **`UESP_ESO_LOCAL_MINIMAL`** left `true` in `secrets/esolog.secrets.php`, CP/skill **server** panels stay empty (no mined esolog tables); the **stat engine** still loads from `rules` / `computedStats`. Set that constant to `false` and import a full esolog schema into the same DB if you need those widgets locally.

## See also

- [uesp/uesp-esolog](https://github.com/uesp/uesp-esolog) — shared PHP (`esoCommon.php`, CP/skill viewers) expected by this project’s `require_once` paths on production.
- [UESP ESO Data (API dumps)](https://esodata.uesp.net/current/index.html) — raw client exports; different from the build-editor rules tables.
