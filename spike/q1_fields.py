"""Q1: field dump per feed, plus a grep for parent references.

Samples the oldest page (no filter) and a 2023 page (LodgementDateFrom/To),
so both old and current record shapes show. Writes spike/raw/q1_*.json and
prints a markdown table of every flattened path with fill rate and example.
"""

import json
import os
import re
import sys

from lib import FEEDS, RAW, fetch, flatten, records

SAMPLE = {"OnlineCC": 50, "OC": 50, "OnlineDA": 20, "OnlineCDC": 20}
F2023 = {"LodgementDateFrom": "2023-06-01", "LodgementDateTo": "2023-06-30"}
PATTERN = re.compile(r"\b(DA[-/ ]?\d|PAN-\d|CNR-\d|CDC[-/ ]?\d|CC[-/ ]?\d|consent)", re.I)


def main():
    report = {}
    for feed in FEEDS:
        n = SAMPLE[feed]
        old, _ = fetch(feed, 1, n)
        new, _ = fetch(feed, 1, n, F2023)
        rows = records(old) + records(new)
        with open(os.path.join(RAW, f"q1_{feed}.json"), "w") as fh:
            json.dump({"oldest": old, "lodged_2023_06": new}, fh, indent=1)

        paths = {}
        hits = []
        for r in rows:
            flat = flatten(r)
            for p, v in flat.items():
                e = paths.setdefault(p, {"filled": 0, "example": None})
                if v not in (None, ""):
                    e["filled"] += 1
                    if e["example"] is None:
                        e["example"] = v
                    # Look for parent-reference-looking values outside the record's own PAN.
                    if isinstance(v, str) and p != "PlanningPortalApplicationNumber" and PATTERN.search(v):
                        hits.append((r.get("PlanningPortalApplicationNumber"), p, v[:120]))
        envelope = {k: (v if not isinstance(v, list) else f"list[{len(v)}]") for k, v in new.items()}
        report[feed] = {"rows": len(rows), "envelope": envelope, "paths": paths, "hits": hits}

    with open(os.path.join(RAW, "q1_report.json"), "w") as fh:
        json.dump(report, fh, indent=1, default=str)

    for feed, rep in report.items():
        print(f"\n### {feed} ({rep['rows']} rows sampled)\n\nEnvelope: `{json.dumps(rep['envelope'])}`\n")
        print("| Field path | Filled | Example |\n|---|---|---|")
        for p, e in sorted(rep["paths"].items()):
            ex = str(e["example"]).replace("|", "/").replace("\n", " ")[:70]
            print(f"| `{p}` | {e['filled']}/{rep['rows']} | {ex} |")
        print(f"\nReference-pattern hits: {len(rep['hits'])}")
        for h in rep["hits"][:15]:
            print(f"- {h[0]} `{h[1]}`: {h[2]}")


if __name__ == "__main__":
    sys.exit(main())
