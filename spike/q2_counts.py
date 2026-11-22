"""Q2: row counts per feed per year, 2019 to 2026, from the envelope TotalCount.

Counts by LodgementDate and by DeterminationDate (both filters are honoured
on every feed, see q_filters.py). One PageSize=1 request per cell.
"""

import json
import os

from lib import FEEDS, RAW, fetch

YEARS = range(2019, 2027)


def count(feed, field, year):
    body, _ = fetch(feed, 1, 1, {f"{field}From": f"{year}-01-01", f"{field}To": f"{year}-12-31"})
    return body["TotalCount"]


def main():
    out = {}
    for field in ("LodgementDate", "DeterminationDate"):
        out[field] = {f: {y: count(f, field, y) for y in YEARS} for f in FEEDS}
        print(f"\n### By {field}\n\n| Year | " + " | ".join(FEEDS) + " |\n|---|" + "---|" * len(FEEDS))
        for y in YEARS:
            print(f"| {y} | " + " | ".join(f"{out[field][f][y]:,}" for f in FEEDS) + " |")
        print("| Total | " + " | ".join(f"{sum(out[field][f].values()):,}" for f in FEEDS) + " |")
    out["unfiltered"] = {f: fetch(f, 1, 1)[0]["TotalCount"] for f in FEEDS}
    print("\nUnfiltered totals: " + ", ".join(f"{f} {n:,}" for f, n in out["unfiltered"].items()))
    with open(os.path.join(RAW, "q2_counts.json"), "w") as fh:
        json.dump(out, fh, indent=1)


if __name__ == "__main__":
    main()
