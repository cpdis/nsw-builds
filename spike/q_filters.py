"""Filter support per feed.

For each candidate filter, fetch 100 rows and compare TotalCount with the
unfiltered total, then check that every returned row satisfies the filter.
"honoured" = count changed and all rows conform.
"""

import json
import os

from lib import FEEDS, RAW, fetch, records


def date(r, *keys):
    for k in keys:
        v = r.get(k)
        if v:
            v = str(v)
            if "/" in v[:10]:  # DD/MM/YYYY
                d, m, y = v[:10].split("/")
                return f"{y}-{m}-{d}"
            return v[:10]
    return None


LODGE = ("LodgementDate", "DateSubmitted", "SubmissionDate")
CANDIDATES = {
    "LodgementDateFrom/To (Jan 2023)": (
        {"LodgementDateFrom": "2023-01-01", "LodgementDateTo": "2023-01-31"},
        lambda r: (d := date(r, *LODGE)) is not None and "2023-01-01" <= d <= "2023-01-31"),
    "DeterminationDateFrom/To (Jan 2023)": (
        {"DeterminationDateFrom": "2023-01-01", "DeterminationDateTo": "2023-01-31"},
        lambda r: (d := date(r, "DeterminationDate")) is not None and "2023-01-01" <= d <= "2023-01-31"),
    "CostOfDevelopmentFrom 1,000,000": (
        {"CostOfDevelopmentFrom": 1000000},
        lambda r: (r.get("CostOfDevelopment") or 0) >= 1000000),
    "CostOfDevelopmentTo 50,000": (
        {"CostOfDevelopmentTo": 50000},
        lambda r: r.get("CostOfDevelopment") is not None and r["CostOfDevelopment"] <= 50000),
    "CouncilName [SUTHERLAND SHIRE COUNCIL]": (
        {"CouncilName": ["SUTHERLAND SHIRE COUNCIL"]},
        lambda r: (r.get("Council") or {}).get("CouncilName", "").upper() == "SUTHERLAND SHIRE COUNCIL"),
    "ApplicationLastUpdatedFrom 2026-11-01": (
        {"ApplicationLastUpdatedFrom": "2026-11-01"},
        lambda r: (date(r, "DateLastUpdated") or "") >= "2026-11-01"),
    "DevelopmentCategory [Residential]": (
        {"DevelopmentCategory": ["Residential"]}, None),
    "ApplicationStatus [Determined]": (
        {"ApplicationStatus": ["Determined"]},
        lambda r: r.get("ApplicationStatus") == "Determined"),
    "Bogus filter name": ({"NotARealFilter": "x"}, None),
}


def main():
    out = {}
    for feed in FEEDS:
        base, _ = fetch(feed, 1, 1)
        total = base["TotalCount"]
        out[feed] = {"unfiltered": total}
        print(f"\n## {feed}: unfiltered TotalCount {total}")
        for name, (filt, check) in CANDIDATES.items():
            try:
                body, _ = fetch(feed, 1, 100, filt, retries=1)
            except RuntimeError as e:
                out[feed][name] = {"error": str(e)[:200]}
                print(f"- {name}: ERROR {e}")
                continue
            rows = records(body)
            n = body.get("TotalCount")
            ok = sum(1 for r in rows if check(r)) if check else None
            verdict = ("ignored" if n == total else
                       "honoured" if check and ok == len(rows) else
                       "count changed" if not check else f"partial ({ok}/{len(rows)} conform)")
            out[feed][name] = {"count": n, "rows": len(rows), "conform": ok, "verdict": verdict}
            print(f"- {name}: count {n}, {ok}/{len(rows)} conform -> {verdict}")
    with open(os.path.join(RAW, "filters_report.json"), "w") as fh:
        json.dump(out, fh, indent=1)


if __name__ == "__main__":
    main()
