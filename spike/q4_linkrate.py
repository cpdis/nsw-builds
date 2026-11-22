"""Q4: CC -> DA link rate for 2023 CCs over $1M.

Cohort A: CCs determined in 2023 (primary). Cohort B: CCs lodged in 2023.
Both use the server-side CostOfDevelopmentFrom filter, then cost > 1,000,000
client-side. Candidate DAs: lodged 2019-01-01 to 2026-12-31, cost >= $500k.

Rule 1: an explicit DA or consent reference on the CC that matches a DA.
Rule 2: same normalised Lot/Section/PlanLabel key, CC date on or after DA date.
Rule 3: same normalised FullAddress, CC date on or after DA date (context).
Rule 4: same coordinates to 5 dp (about 1 m), CC date on or after DA date (context).

Bias check: for the top council, refetch every DA without the cost filter and
rerun the rules for that council's CCs.
"""

import collections
import json
import os
import re

from lib import RAW, fetch_all

REF = re.compile(r"\b(DA[-/ ]?\d[\w/.-]*|PAN-\d+|CNR-\d+)", re.I)
STREET_TYPES = {"ST": "STREET", "RD": "ROAD", "AVE": "AVENUE", "AV": "AVENUE", "DR": "DRIVE",
                "PL": "PLACE", "CRES": "CRESCENT", "CR": "CRESCENT", "PDE": "PARADE",
                "HWY": "HIGHWAY", "LN": "LANE", "CT": "COURT", "TCE": "TERRACE", "CL": "CLOSE"}


def norm_date(v):
    if not v:
        return None
    v = str(v)
    if "/" in v[:10]:
        d, m, y = v[:10].split("/")
        return f"{y}-{m.zfill(2)}-{d.zfill(2)}"
    return v[:10]


def addr_key(a):
    if not a:
        return None
    words = re.sub(r"[^A-Z0-9 ]", " ", a.upper()).split()
    return " ".join(STREET_TYPES.get(w, w) for w in words) or None


def lot_keys(r):
    keys = set()
    for loc in r.get("Location") or []:
        for lot in loc.get("Lot") or []:
            lt, plan = (lot.get("Lot") or "").strip().upper(), (lot.get("PlanLabel") or "").replace(" ", "").upper()
            if lt and plan:
                sec = (lot.get("Section") or "").strip().upper()
                keys.add(f"{lt}/{sec}/{plan}" if sec else f"{lt}/{plan}")
    return keys


def addr_keys(r):
    return {k for loc in r.get("Location") or [] if (k := addr_key(loc.get("FullAddress")))}


def xy_keys(r):
    out = set()
    for loc in r.get("Location") or []:
        try:
            out.add((round(float(loc["X"]), 5), round(float(loc["Y"]), 5)))
        except (KeyError, TypeError, ValueError):
            pass
    return out


def da_date(r):
    return norm_date(r.get("DeterminationDate")) or norm_date(r.get("LodgementDate"))


def build_index(das):
    idx = {"lot": collections.defaultdict(list), "addr": collections.defaultdict(list),
           "xy": collections.defaultdict(list), "ref": collections.defaultdict(list)}
    for d in das:
        dd = da_date(d)
        for k in lot_keys(d):
            idx["lot"][k].append(dd)
        for k in addr_keys(d):
            idx["addr"][k].append(dd)
        for k in xy_keys(d):
            idx["xy"][k].append(dd)
        for f in ("PlanningPortalApplicationNumber", "CouncilApplicationNumber"):
            if d.get(f):
                idx["ref"][str(d[f]).upper().replace(" ", "")].append(dd)
    return idx


def hit(index, keys, cc_date):
    """True if any key matches a DA whose date is on or before the CC date."""
    return any(dd is None or cc_date is None or dd <= cc_date for k in keys for dd in index.get(k, []))


def explicit_refs(cc):
    refs = set()
    for k, v in cc.items():
        if k != "PlanningPortalApplicationNumber" and isinstance(v, str):
            refs.update(m.upper().replace(" ", "") for m in REF.findall(v))
    return refs


def evaluate(ccs, idx, date_field):
    res = []
    for cc in ccs:
        d = norm_date(cc.get(date_field))
        r1 = hit(idx["ref"], explicit_refs(cc), d)
        lots = lot_keys(cc)
        r2 = bool(lots) and hit(idx["lot"], lots, d)
        r3 = hit(idx["addr"], addr_keys(cc), d)
        r4 = hit(idx["xy"], xy_keys(cc), d)
        res.append({"council": (cc.get("Council") or {}).get("CouncilName"), "has_lot": bool(lots),
                    "r1": r1, "r2": r2, "r3": r3, "r4": r4})
    return res


def pct(n, d):
    return f"{100 * n / d:.1f}%" if d else "n/a"


def summarise(name, res):
    n = len(res)
    c = lambda k: sum(1 for r in res if r[k])
    either = sum(1 for r in res if r["r1"] or r["r2"])
    print(f"\n#### {name}: {n} CCs over $1M\n")
    print("| Measure | Count | Share |\n|---|---|---|")
    for label, v in [("Rule 1 explicit reference", c("r1")), ("Rule 2 lot/plan", c("r2")),
                     ("Rule 1 or 2 (pass mark 70%)", either), ("Rule 3 address only", c("r3")),
                     ("Rule 4 same coordinates", c("r4")),
                     ("Rule 3 or 4", sum(1 for r in res if r["r3"] or r["r4"])),
                     ("CCs with lot data", c("has_lot"))]:
        print(f"| {label} | {v:,} | {pct(v, n)} |")
    by = collections.defaultdict(list)
    for r in res:
        by[r["council"]].append(r)
    print("\nTop 10 councils:\n\n| Council | CCs | Rule 1 or 2 | Rule 3 address | Rule 4 coords |\n|---|---|---|---|---|")
    for council, rs in sorted(by.items(), key=lambda kv: -len(kv[1]))[:10]:
        m = len(rs)
        print(f"| {council} | {m} | {pct(sum(1 for r in rs if r['r1'] or r['r2']), m)} | "
              f"{pct(sum(1 for r in rs if r['r3']), m)} | {pct(sum(1 for r in rs if r['r4']), m)} |")
    return {"n": n, "r1": c("r1"), "r2": c("r2"), "either": either, "r3": c("r3"), "r4": c("r4"),
            "has_lot": c("has_lot"), "top_council": max(by, key=lambda k: len(by[k])) if by else None}


def main():
    big = lambda rows: [r for r in rows if (r.get("CostOfDevelopment") or 0) > 1_000_000]
    cohort_a, _ = fetch_all("OnlineCC", {"DeterminationDateFrom": "2023-01-01", "DeterminationDateTo": "2023-12-31",
                                         "CostOfDevelopmentFrom": 1000000})
    cohort_b, _ = fetch_all("OnlineCC", {"LodgementDateFrom": "2023-01-01", "LodgementDateTo": "2023-12-31",
                                         "CostOfDevelopmentFrom": 1000000})
    cohort_a, cohort_b = big(cohort_a), big(cohort_b)
    das, _ = fetch_all("OnlineDA", {"LodgementDateFrom": "2019-01-01", "LodgementDateTo": "2026-12-31",
                                    "CostOfDevelopmentFrom": 500000})
    idx = build_index(das)
    print(f"\nDA index: {len(das):,} DAs, {len(idx['lot']):,} lot keys, {len(idx['addr']):,} addresses")

    out = {"da_count": len(das)}
    out["A"] = summarise("Cohort A, determined 2023", evaluate(cohort_a, idx, "DeterminationDate"))
    out["B"] = summarise("Cohort B, lodged 2023", evaluate(cohort_b, idx, "LodgementDate"))

    # Bias check: all DAs (no cost filter) for the top council in cohort A.
    council = out["A"]["top_council"]
    all_das, _ = fetch_all("OnlineDA", {"LodgementDateFrom": "2019-01-01", "LodgementDateTo": "2026-12-31",
                                        "CouncilName": [council.upper()]})
    sub = [c for c in cohort_a if (c.get("Council") or {}).get("CouncilName") == council]
    full = summarise(f"Bias check, {council}, all DAs (no cost floor)", evaluate(sub, build_index(all_das), "DeterminationDate"))
    capped = summarise(f"Bias check, {council}, DAs >= $500k", evaluate(sub, idx, "DeterminationDate"))
    out["bias"] = {"council": council, "all": full, "capped": capped, "all_da_count": len(all_das)}

    with open(os.path.join(RAW, "q4_linkrate.json"), "w") as fh:
        json.dump(out, fh, indent=1)
    with open(os.path.join(RAW, "q4_cc_cohort_a.json"), "w") as fh:
        json.dump(cohort_a, fh)


if __name__ == "__main__":
    main()
