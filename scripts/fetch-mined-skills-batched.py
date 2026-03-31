#!/usr/bin/env python3
"""
Download esolog minedSkills in batches from UESP exportJson (avoids one giant response).

Site / endpoint (live):
  https://esolog.uesp.net/exportJson.php

Strategy (matches uesp-esolog/exportJson.php):
  1) GET  ?version=49&table=minedSkills&fields=id     -> all skill ids (small payload)
  2) POST batches with table=minedSkills&ids=1,2,3,... -> full rows per batch

Cloudflare: if you get HTML ("Just a moment"), pass cookies from a logged-in browser session:
  set UESP_ESOLOG_COOKIE to the raw Cookie header string, or use --cookie

Resume: if the run stops (SSL blip, timeout), run the same command again — progress is saved next to the
output file as <name>.fetch_progress.json and <name>.fetch_batches.jsonl

Examples:
  python scripts/fetch-mined-skills-batched.py -o minedSkills.json
  python scripts/fetch-mined-skills-batched.py --batch-size 300 --delay 0.7 -o minedSkills.json
  python scripts/fetch-mined-skills-batched.py --retries 8 --retry-base 3 -o minedSkills.json
  set UESP_ESOLOG_COOKIE=cf_clearance=...; python scripts/fetch-mined-skills-batched.py -o minedSkills.json
"""

from __future__ import annotations

import argparse
import json
import os
import random
import socket
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

DEFAULT_BASE = "https://esolog.uesp.net/exportJson.php"
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0"
)

RETRYABLE_HTTP = frozenset({408, 425, 429, 500, 502, 503, 504})


def is_likely_json(body: str) -> bool:
    t = body.lstrip()
    return len(t) > 0 and t[0] in "{["


def http_request_once(
    url: str,
    *,
    data: dict[str, str] | None = None,
    cookie: str | None,
    timeout: int,
) -> str:
    headers = {
        "User-Agent": USER_AGENT,
        "Accept": "application/json, */*;q=0.8",
        "Connection": "close",
    }
    if cookie:
        headers["Cookie"] = cookie

    encoded: bytes | None = None
    if data is not None:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
        headers["Content-Type"] = "application/x-www-form-urlencoded"

    req = urllib.request.Request(url, data=encoded, headers=headers, method="POST" if encoded else "GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        if e.code in RETRYABLE_HTTP:
            raise _RetryableHTTP(e.code, body) from e
        raise RuntimeError(f"HTTP {e.code}: {body[:500]}") from e


class _RetryableHTTP(Exception):
    def __init__(self, code: int, body: str) -> None:
        super().__init__(f"HTTP {code}")
        self.code = code
        self.body = body


def http_request(
    url: str,
    *,
    data: dict[str, str] | None = None,
    cookie: str | None,
    timeout: int,
    retries: int,
    retry_base: float,
) -> str:
    last_err: BaseException | None = None
    for attempt in range(retries + 1):
        try:
            return http_request_once(url, data=data, cookie=cookie, timeout=timeout)
        except _RetryableHTTP as e:
            last_err = e
        except (ssl.SSLError, OSError, urllib.error.URLError, socket.timeout, TimeoutError) as e:
            last_err = e
        if attempt >= retries:
            break
        # Exponential backoff + small jitter (helps with BAD_RECORD_MAC / flaky TLS)
        wait = retry_base * (2**attempt) + random.uniform(0, 0.35)
        print(f"  ... retry {attempt + 1}/{retries} in {wait:.1f}s ({type(last_err).__name__}: {last_err})", flush=True)
        time.sleep(wait)
    assert last_err is not None
    raise RuntimeError(f"Request failed after {retries + 1} attempts: {last_err}") from last_err


def parse_export(body: str) -> dict:
    if not is_likely_json(body):
        preview = body[:200].replace("\n", " ")
        raise RuntimeError(
            "Response is not JSON (Cloudflare or error page?). "
            f"Preview: {preview!r} ... "
            "Try --cookie or env UESP_ESOLOG_COOKIE from your browser after opening exportJson in a tab."
        )
    data = json.loads(body)
    if not isinstance(data, dict):
        raise RuntimeError("Top-level JSON is not an object")
    if data.get("error"):
        raise RuntimeError(f"API error: {data['error']!r}")
    return data


def progress_paths(output: Path) -> tuple[Path, Path]:
    return (
        output.parent / f"{output.stem}.fetch_progress.json",
        output.parent / f"{output.stem}.fetch_batches.jsonl",
    )


def main() -> int:
    p = argparse.ArgumentParser(description="Batch-download minedSkills from esolog.uesp.net/exportJson.php")
    p.add_argument("--base", default=os.environ.get("UESP_EXPORT_JSON_URL", DEFAULT_BASE), help="exportJson.php URL")
    p.add_argument("--version", default="49", help="ESO log version (e.g. 49)")
    p.add_argument("--batch-size", type=int, default=400, help="max ids per batch (POST body); keep same when resuming")
    p.add_argument("--delay", type=float, default=0.5, help="seconds between batch requests")
    p.add_argument("--timeout", type=int, default=300, help="per-request timeout seconds")
    p.add_argument("-o", "--output", default="minedSkills.json", help="output file (exportJson shape)")
    p.add_argument(
        "--cookie",
        default=os.environ.get("UESP_ESOLOG_COOKIE", ""),
        help="Cookie header value (or set UESP_ESOLOG_COOKIE)",
    )
    p.add_argument("--ids-only", action="store_true", help="only fetch and print id count, then exit")
    p.add_argument("--retries", type=int, default=6, help="retries per request after failure (SSL, 5xx, etc.)")
    p.add_argument("--retry-base", type=float, default=2.0, help="base seconds for exponential backoff")
    p.add_argument(
        "--fresh",
        action="store_true",
        help="ignore saved progress and start from scratch (deletes .fetch_progress.json / .fetch_batches.jsonl)",
    )
    args = p.parse_args()

    base = args.base.rstrip("?&")
    cookie = args.cookie.strip() or None
    out_path = Path(args.output)
    progress_path, batches_path = progress_paths(out_path)

    if args.fresh:
        for path in (progress_path, batches_path):
            if path.is_file():
                path.unlink()

    # Phase 1: ids (or resume)
    ids: list[int]
    next_index = 0

    if not args.ids_only and progress_path.is_file() and batches_path.is_file() and not args.fresh:
        with open(progress_path, encoding="utf-8") as f:
            st = json.load(f)
        if st.get("version") != args.version:
            print("Progress file version mismatch; use --fresh to restart", file=sys.stderr)
            return 1
        ids = [int(x) for x in st["ids"]]
        next_index = int(st["next_index"])
        print(f"Resuming from id index {next_index}/{len(ids)} ({batches_path.name})", flush=True)
    else:
        q1 = urllib.parse.urlencode(
            {"version": args.version, "table": "minedSkills", "fields": "id"}
        )
        url_ids = f"{base}?{q1}"
        print(f"Fetching id list: {url_ids[:90]}...", flush=True)
        body1 = http_request(
            url_ids,
            data=None,
            cookie=cookie,
            timeout=args.timeout,
            retries=args.retries,
            retry_base=args.retry_base,
        )
        data1 = parse_export(body1)
        rows_id = data1.get("minedSkills")
        if not isinstance(rows_id, list):
            raise RuntimeError("Missing minedSkills array in id response")
        ids = []
        for r in rows_id:
            if isinstance(r, dict) and "id" in r:
                try:
                    i = int(r["id"])
                except (TypeError, ValueError):
                    continue
                if i > 0:
                    ids.append(i)
        ids = sorted(set(ids))
        print(f"Found {len(ids)} skill ids", flush=True)
        if args.ids_only:
            return 0
        if not ids:
            print("No ids; aborting", file=sys.stderr)
            return 1
        next_index = 0
        batches_path.write_text("", encoding="utf-8")
        with open(progress_path, "w", encoding="utf-8") as f:
            json.dump({"version": args.version, "ids": ids, "next_index": 0}, f)

    if args.ids_only:
        return 0

    if not ids:
        print("No ids; aborting", file=sys.stderr)
        return 1

    batch_size = max(1, args.batch_size)
    total_batches = (len(ids) + batch_size - 1) // batch_size

    # When resuming, append to jsonl; when fresh start we already truncated jsonl above
    if next_index > 0 and not batches_path.is_file():
        print("Missing batches file; use --fresh", file=sys.stderr)
        return 1

    mode = "a" if next_index > 0 else "w"
    batch_file = open(batches_path, mode, encoding="utf-8")

    try:
        for start in range(next_index, len(ids), batch_size):
            chunk = ids[start : start + batch_size]
            bn = start // batch_size + 1
            ids_str = ",".join(str(i) for i in chunk)
            form = {
                "version": args.version,
                "table": "minedSkills",
                "ids": ids_str,
            }
            print(f"Batch {bn}/{total_batches} ({len(chunk)} ids)...", flush=True)
            body = http_request(
                base,
                data=form,
                cookie=cookie,
                timeout=args.timeout,
                retries=args.retries,
                retry_base=args.retry_base,
            )
            chunk_data = parse_export(body)
            part = chunk_data.get("minedSkills")
            if not isinstance(part, list):
                raise RuntimeError("Missing minedSkills array in batch response")
            batch_file.write(json.dumps(part, ensure_ascii=False) + "\n")
            batch_file.flush()
            next_index = start + len(chunk)
            with open(progress_path, "w", encoding="utf-8") as f:
                json.dump({"version": args.version, "ids": ids, "next_index": next_index}, f)
            if args.delay > 0 and next_index < len(ids):
                time.sleep(args.delay)
    finally:
        batch_file.close()

    # Assemble final JSON from jsonl
    all_rows: list[dict] = []
    with open(batches_path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            all_rows.extend(json.loads(line))

    if len(all_rows) != len(ids):
        print(
            f"Warning: row count {len(all_rows)} != id count {len(ids)} (duplicates or API mismatch?)",
            file=sys.stderr,
        )

    out_obj = {"minedSkills": all_rows, "numRecords": len(all_rows)}
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(out_obj, f, ensure_ascii=False)
    print(f"Wrote {len(all_rows)} rows to {out_path}", flush=True)

    for path in (progress_path, batches_path):
        if path.is_file():
            path.unlink()
    print("Removed progress files (.fetch_progress.json, .fetch_batches.jsonl)", flush=True)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BrokenPipeError:
        raise SystemExit(0)
    except RuntimeError as e:
        print(e, file=sys.stderr)
        raise SystemExit(1)
