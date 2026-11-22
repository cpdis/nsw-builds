"""Q3: throttle test. 10 consecutive 1,000-row pages of OnlineCC, no delay, no cache.

Uses lodged-in-2022 CCs (44k rows) so all 10 pages are full. Records status,
latency and payload size per page.
"""

import json
import os

from lib import RAW, fetch, records


def main():
    filt = {"LodgementDateFrom": "2022-01-01", "LodgementDateTo": "2022-12-31"}
    rows = []
    for p in range(1, 11):
        body, meta = fetch("OnlineCC", p, 1000, filt, pace=False, cache=False, retries=0)
        meta.update(page=p, rows=len(records(body)))
        rows.append(meta)
        print(meta)
    with open(os.path.join(RAW, "q3_throttle.json"), "w") as fh:
        json.dump(rows, fh, indent=1)
    lat = [r["latency"] for r in rows]
    print(f"\n| Page | Status | Rows | Latency s | Payload KB |\n|---|---|---|---|---|")
    for r in rows:
        print(f"| {r['page']} | {r['status']} | {r['rows']} | {r['latency']} | {r['bytes'] // 1024} |")
    print(f"\nmin {min(lat)} s, max {max(lat)} s, mean {sum(lat) / len(lat):.2f} s")


if __name__ == "__main__":
    main()
