# Getting `minedItemSummary` and `minedItem` locally

The build editor uses **MySQL** for item search (`minedItemSummary`) and per-slot item details (`minedItem`). The public `exportJson.php` API is easy to use for **`minedItemSummary`**, but **`minedItem`** is often **not** returned for bulk `table=minedItem` requests (the server requires `id` / `ids` for that table). So you may end up with only the summary table after `import-mined-data.php --only-item-search`.

Use one of these approaches (personal / local use; respect UESP terms and do not redistribute full dumps without permission).

---

## 1. Browser JSON (try first)

1. While logged into the wiki (if Cloudflare blocks bare `curl`), open:

   `https://esolog.uesp.net/exportJson.php?version=49&table%5B%5D=minedItemSummary&table%5B%5D=minedItem`

2. Wait for the full JSON to finish loading. **Check** the file: you need a top-level `"minedItem": [ ... ]` array with **many** objects, not only `"minedItemSummary"`.

3. Save the response body as `data/uesp-export/items.json` (create the folder if needed).

4. Import:

   `php scripts/import-mined-data.php --only-item-search --from-file=data/uesp-export/items.json`

If `minedItem` is missing or empty in the saved file, the API is not giving you a full dump this way; use another option below.

---

## 2. Backfill `minedItem` using IDs from `minedItemSummary` (scripted)

`exportJson.php` accepts **`ids=`** (comma-separated `itemId` values) for **`minedItem`**. If **`minedItemSummary`** is already in MySQL, the import script can query distinct `itemId`s and fetch `minedItem` in batches:

```bash
php scripts/import-mined-data.php --backfill-mined-item-from-summary
```

Use the same **`--version=`** as your summary data (default `49`). Tune batch size if requests fail (URL length, timeouts):

- `--backfill-batch-size=80` (default; env `UESP_ESO_MINED_ITEM_ID_BATCH`)
- `--backfill-sleep-ms=200` pause between chunks (env `UESP_ESO_MINED_ITEM_BACKFILL_SLEEP_MS`)

This issues **many** HTTP requests and can take a long time for a full summary. If a run stops halfway, re-run after fixing connectivity; the first chunk that writes data **truncates** `minedItem` before inserting (full refresh behavior).

---

## 3. MySQL dump / restore (most reliable offline)

If you have **any** MySQL instance that already contains these tables (your own backup, another dev machine, etc.):

**Export (on the machine that has the data):**

```bash
mysqldump -u USER -p --no-tablespaces YOUR_DB minedItem minedItemSummary > mined_items.sql
```

**Import (local `esobuilddata` or the DB name in `secrets/esolog.secrets.php`):**

```bash
mysql -u USER -p esobuilddata < mined_items.sql
```

Table names must match what the app expects (no suffix for default live version, or whatever `GetEsoItemTableSuffix` uses for your `version=`).

---

## 4. Improve scripted HTTP import

On Windows, enable the **PHP curl** and **openssl** extensions so the import script does not rely only on system `curl.exe`. Then run:

`php scripts/import-mined-data.php --only-item-search`

Tune env vars if needed: `UESP_ESO_EXPORT_HTTP_TIMEOUT`, `UESP_ESO_EXPORT_CURL_RETRIES`.

This still only loads **`minedItem`** if the **HTTP JSON** actually contains that table.

---

## 5. After tables exist

Restart your PHP server and use the editor as usual. Local `exportJson.php` reads from **your** database; no UESP round-trip is required for items already in MySQL.
