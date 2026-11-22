"""Q4b (cache only): how ambiguous are coordinate matches, and how far apart in time?

For cohort A CCs (determined 2023, > $1M) matched by coordinates to a DA
(>= $500k, lodged 2019+), count candidate DAs per CC and the gap in days
between the latest qualifying DA date and the CC determination date.
"""
import datetime as dt
import json
import os
import statistics

from lib import RAW, fetch_all
from q4_linkrate import build_index, norm_date, xy_keys

das, _ = fetch_all("OnlineDA", {"LodgementDateFrom": "2019-01-01", "LodgementDateTo": "2026-12-31",
                                "CostOfDevelopmentFrom": 500000})
idx = build_index(das)["xy"]
with open(os.path.join(RAW, "q4_cc_cohort_a.json")) as fh:
    ccs = json.load(fh)
cands, gaps = [], []
for cc in ccs:
    d = norm_date(cc.get("DeterminationDate"))
    dates = [x for k in xy_keys(cc) for x in idx.get(k, []) if x and d and x <= d]
    if dates:
        cands.append(len(dates))
        gaps.append((dt.date.fromisoformat(d) - dt.date.fromisoformat(max(dates))).days)
n = len(cands)
print(f"matched CCs: {n}")
print(f"1 candidate DA: {sum(c == 1 for c in cands)} ({100*sum(c == 1 for c in cands)/n:.1f}%), "
      f"2-3: {sum(2 <= c <= 3 for c in cands)}, 4+: {sum(c >= 4 for c in cands)}")
q = statistics.quantiles(gaps, n=4)
print(f"days from latest DA to CC: median {statistics.median(gaps):.0f}, p25 {q[0]:.0f}, p75 {q[2]:.0f}, "
      f"over 3 years {sum(g > 1095 for g in gaps)}")
