"""Q5: share of builder names that are companies vs individuals.

Reads every cached CC and OC page (spike/raw/cache), dedupes by PAN, and
classifies BuilderLegalName. Company = legal-entity suffix or a business word.
Also checks whether OC PANs also appear as CC PANs (shared CFT- numbering).
"""

import glob
import json
import os
import re

from lib import CACHE

COMPANY = re.compile(r"\b(PTY|P/L|LTD|LIMITED|LTD\.|INC|CORP|CORPORATION|TRUST|TRUSTEE|GROUP|HOLDINGS|"
                     r"CONSTRUCTIONS?|BUILDERS?|BUILDING|HOMES|DEVELOPMENTS?|PROJECTS|SERVICES|"
                     r"ENTERPRISES|INDUSTRIES|CONTRACTING|ASSOCIATION|COUNCIL|DEPARTMENT)\b", re.I)


def load(feed):
    rows = {}
    for path in glob.glob(os.path.join(CACHE, f"{feed}_*.json")):
        with open(path) as fh:
            for r in json.load(fh).get("Application") or []:
                rows[r["PlanningPortalApplicationNumber"]] = r
    return rows


def main():
    cc, oc = load("OnlineCC"), load("OC")
    for feed, rows in (("OnlineCC", cc), ("OC", oc)):
        names = [r.get("BuilderLegalName") for r in rows.values()]
        filled = [n.strip() for n in names if n and n.strip()]
        comp = sum(1 for n in filled if COMPANY.search(n))
        print(f"\n{feed}: {len(rows):,} unique records, builder name on {len(filled):,} "
              f"({100 * len(filled) / len(rows):.1f}%); company {comp:,} ({100 * comp / len(filled):.1f}%), "
              f"individual/other {len(filled) - comp:,} ({100 * (len(filled) - comp) / len(filled):.1f}%)")
        print("  sample non-company names:", [n for n in filled if not COMPANY.search(n)][:12])
    shared = set(cc) & set(oc)
    print(f"\nPANs present in both CC and OC caches: {len(shared):,} "
          f"(of {len(oc):,} OCs, {len(cc):,} CCs). Examples: {sorted(shared)[:5]}")


if __name__ == "__main__":
    main()
