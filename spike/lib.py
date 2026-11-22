"""Shared helpers for the NSW eplanning data spike (stdlib only).

The API is header-driven: PageSize, PageNumber and a JSON `filters` header.
Raw responses are cached under spike/raw/ so reruns do not hit the API again.
"""

import hashlib
import json
import os
import time
import urllib.error
import urllib.request

BASE = "https://api.apps1.nsw.gov.au/eplanning/data/v0/"
FEEDS = ["OnlineDA", "OnlineCDC", "OnlineCC", "OC"]
HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(HERE, "raw")
CACHE = os.path.join(RAW, "cache")
PACE = 2.0  # seconds between live requests

_last = [0.0]


def fetch(feed, page=1, size=100, filters=None, pace=True, cache=True, retries=4):
    """Fetch one page. Returns (body, meta) where meta has status, latency, bytes."""
    filt = json.dumps({"filters": filters or {}}, separators=(",", ":"))
    key = hashlib.sha1(f"{feed}|{page}|{size}|{filt}".encode()).hexdigest()[:16]
    path = os.path.join(CACHE, f"{feed}_{key}.json")
    if cache and os.path.exists(path):
        with open(path) as fh:
            return json.load(fh), {"status": 200, "cached": True}

    os.makedirs(CACHE, exist_ok=True)
    for attempt in range(retries + 1):
        if pace:
            wait = PACE - (time.time() - _last[0])
            if wait > 0:
                time.sleep(wait)
        req = urllib.request.Request(
            BASE + feed,
            headers={"PageSize": str(size), "PageNumber": str(page), "filters": filt,
                     "Accept": "application/json", "User-Agent": "nsw-builds-spike/0.1"},
        )
        t0 = time.time()
        try:
            with urllib.request.urlopen(req, timeout=180) as resp:
                raw = resp.read()
                status = resp.status
        except urllib.error.HTTPError as e:
            raw, status = e.read(), e.code
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            raw, status = str(e).encode(), -1
        _last[0] = time.time()
        meta = {"status": status, "latency": round(_last[0] - t0, 3), "bytes": len(raw)}
        if status == 200:
            body = json.loads(raw)
            if cache:
                with open(path, "w") as fh:
                    json.dump(body, fh)
            return body, meta
        print(f"  ! {feed} p{page} status {status}: {raw[:200]!r}; retry {attempt}")
        time.sleep(2 ** attempt * 2)
    raise RuntimeError(f"{feed} page {page} failed: {meta}")


def records(body):
    return body.get("Application") or []


def fetch_all(feed, filters, size=1000, label=""):
    """Fetch every page for a filter set. Returns (rows, total_count)."""
    body, _ = fetch(feed, 1, size, filters)
    total, pages = body.get("TotalCount", 0), body.get("TotalPages", 1)
    rows = list(records(body))
    print(f"{label}{feed} {filters}: {total} rows, {pages} pages")
    for p in range(2, (pages or 1) + 1):
        b, _ = fetch(feed, p, size, filters)
        rows.extend(records(b))
        if p % 10 == 0:
            print(f"  page {p}/{pages}")
    return rows, total


def flatten(obj, prefix="", out=None):
    """Flatten nested dicts/lists to paths like Location[].Lot[].PlanLabel."""
    if out is None:
        out = {}
    if isinstance(obj, dict):
        for k, v in obj.items():
            flatten(v, f"{prefix}.{k}" if prefix else k, out)
    elif isinstance(obj, list):
        for v in obj:
            flatten(v, f"{prefix}[]", out)
    else:
        out.setdefault(prefix, obj)
    return out
