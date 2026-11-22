"""Q7: statewide CC -> consent link rate (no cost floor, DA + CDC), tie-breaks, lag,
and OC -> CC by the same location rule. Reads pages cached by q6_prefetch.py.

Match rules (consent date = determination, else lodgement; must be <= child date):
  rule 4: same Location X/Y rounded to 5 dp (about 1 m)
  rule 3: same normalised FullAddress
Tie-breaks, applied to the union of rule 3 and rule 4 candidates. A step never
empties the set: if no candidate passes, the step is skipped for that CC.
  a  determined (DeterminationDate <= CC date) and not withdrawn/refused/cancelled
  b  closest preceding consent date (keeps same-day ties)
  c  development type compatible (coarse classes, generic types match anything)
  d  consent cost within a factor of 3 of the CC cost
  e  original application over modification / review
"""

import collections
import datetime as dt
import json
import os
import statistics

from lib import RAW, fetch_all
from q4_linkrate import addr_keys, norm_date, xy_keys

SINCE_2019 = {"LodgementDateFrom": "2019-01-01", "LodgementDateTo": "2026-12-31"}
DEAD = {"Withdrawn", "Rejected", "Cancelled", "Refused", "Declined", "Surrendered",
        "Operational consent declined", "Pending Lodgement"}
MOD_TYPES = {"Modification Application", "Modification to Complying Development Certificate",
             "Review of determination"}
CLASSES = {  # keyword -> class; types matching none are generic (match anything)
    "RES": ("dwelling", "residential", "dual occupancy", "housing", "boarding", "seniors", "co-living",
            "group home", "independent living", "manufactured home", "attached", "semi-detached",
            "residential flat", "shop top", "terrace", "secondary"),
    "COM": ("commercial", "retail", "office", "business", "shop", "food", "restaurant", "cafe", "pub",
            "hotel", "motel", "club", "bar", "child", "education", "school", "health", "medical",
            "tourist", "entertainment", "recreation", "worship", "community", "service station",
            "mixed use", "car park", "signage", "sign"),
    "IND": ("industr", "warehouse", "factory", "storage", "self storage", "depot", "freight"),
    "SUB": ("subdivision",),
}


def classes(r):
    out = set()
    for d in r.get("DevelopmentType") or []:
        t = (d.get("DevelopmentType") or "").lower()
        for cls, words in CLASSES.items():
            if any(w in t for w in words):
                out.add(cls)
    return out - {"SUB"} if out - {"SUB"} else out


def days(a, b):
    return (dt.date.fromisoformat(a) - dt.date.fromisoformat(b)).days


def index(rows, kind):
    """Location indexes over candidate parents. Each entry is a slim parent dict."""
    xy, ad = collections.defaultdict(list), collections.defaultdict(list)
    for r in rows:
        p = {"kind": kind, "pan": r.get("PlanningPortalApplicationNumber"),
             "date": norm_date(r.get("DeterminationDate")) or norm_date(r.get("LodgementDate")),
             "det": norm_date(r.get("DeterminationDate")), "status": r.get("ApplicationStatus"),
             "type": r.get("ApplicationType"), "cost": r.get("CostOfDevelopment"), "cls": classes(r)}
        for k in xy_keys(r):
            xy[k].append(p)
        for k in addr_keys(r):
            ad[k].append(p)
    return xy, ad


def candidates(child, child_date, idx):
    xy, ad = idx
    seen = {}
    for k in xy_keys(child):
        for p in xy.get(k, []):
            if p["date"] and p["date"] <= child_date:
                seen.setdefault(p["pan"], dict(p, r4=False, r3=False))["r4"] = True
    for k in addr_keys(child):
        for p in ad.get(k, []):
            if p["date"] and p["date"] <= child_date:
                seen.setdefault(p["pan"], dict(p, r4=False, r3=False))["r3"] = True
    return list(seen.values())


def step(cands, keep):
    kept = [p for p in cands if keep(p)]
    return kept or cands


def tiebreak(cands, cc, cc_date, order):
    c_cls, c_cost = classes(cc), cc.get("CostOfDevelopment") or 0
    rules = {
        "a": lambda cs: step(cs, lambda p: p["det"] and p["det"] <= cc_date and p["status"] not in DEAD),
        "b": lambda cs: step(cs, lambda p: p["date"] == max(q["date"] for q in cs)),
        "c": lambda cs: step(cs, lambda p: not p["cls"] or not c_cls or bool(p["cls"] & c_cls)),
        "d": lambda cs: step(cs, lambda p: bool(p["cost"]) and c_cost / 3 <= p["cost"] <= c_cost * 3),
        "e": lambda cs: step(cs, lambda p: p["type"] not in MOD_TYPES),
    }
    resolved_at = None
    for key in order:
        if len(cands) == 1:
            break
        cands = rules[key](cands)
        if len(cands) == 1:
            resolved_at = key
    return cands, resolved_at


def pct(n, d):
    return f"{100 * n / d:.1f}%" if d else "n/a"


def quantiles(xs):
    if len(xs) < 10:
        return "n/a"
    q = statistics.quantiles(xs, n=10)
    return f"p10 {q[0]:.0f}, p50 {statistics.median(xs):.0f}, p90 {q[8]:.0f}"


def cc_to_consent():
    ccs, _ = fetch_all("OnlineCC", {"DeterminationDateFrom": "2023-01-01", "DeterminationDateTo": "2023-12-31",
                                    "CostOfDevelopmentFrom": 1000000})
    ccs = [c for c in ccs if (c.get("CostOfDevelopment") or 0) > 1_000_000]
    das, _ = fetch_all("OnlineDA", SINCE_2019)
    cdcs, _ = fetch_all("OnlineCDC", SINCE_2019)
    print(f"\nCC cohort {len(ccs):,}; candidate DAs {len(das):,}; candidate CDCs {len(cdcs):,}")
    da_idx, cdc_idx = index(das, "DA"), index(cdcs, "CDC")
    idx = ({**da_idx[0]}, {**da_idx[1]})
    for src, dst in ((cdc_idx[0], idx[0]), (cdc_idx[1], idx[1])):
        for k, v in src.items():
            dst.setdefault(k, []).extend(v)

    res = []
    for cc in ccs:
        d = norm_date(cc.get("DeterminationDate"))
        cs = candidates(cc, d, idx)
        r = {"council": (cc.get("Council") or {}).get("CouncilName"), "n": len(cs),
             "r4": any(p["r4"] for p in cs), "r3": any(p["r3"] for p in cs),
             "r4_da": any(p["r4"] and p["kind"] == "DA" for p in cs),
             "r4_cdc": any(p["r4"] and p["kind"] == "CDC" for p in cs),
             "any_da": any(p["kind"] == "DA" for p in cs), "any_cdc": any(p["kind"] == "CDC" for p in cs)}
        if cs:
            a_res, a_at = tiebreak(cs, cc, d, "abcde")
            b_res, b_at = tiebreak(cs, cc, d, "acdeb")
            filt, _ = tiebreak(cs, cc, d, "acde")
            pick = b_res[0]
            r.update(a_at=a_at, a_left=len(a_res), b_at=b_at, b_left=len(b_res), filt_left=len(filt),
                     lag=days(d, pick["det"] or pick["date"]), pick_kind=pick["kind"],
                     pick_r4=pick["r4"], single=len(cs) == 1)
        res.append(r)
    return res


def report_cc(res):
    n = len(res)
    c = lambda f: sum(1 for r in res if f(r))
    print(f"\n### CC -> consent, {n:,} CCs determined 2023 over $1M\n")
    print("| Measure | CCs | Share |\n|---|---|---|")
    rows = [("Rule 4 same coordinates (DA or CDC)", c(lambda r: r["r4"])),
            ("Rule 3 address (DA or CDC)", c(lambda r: r["r3"])),
            ("Rule 3 or 4", c(lambda r: r["n"])),
            ("Rule 4 to a DA", c(lambda r: r["r4_da"])), ("Rule 4 to a CDC", c(lambda r: r["r4_cdc"])),
            ("Rule 4 to a CDC only (no DA)", c(lambda r: r["r4_cdc"] and not r["r4_da"])),
            ("Rule 3 or 4 to a DA", c(lambda r: r["any_da"])), ("Rule 3 or 4 to a CDC", c(lambda r: r["any_cdc"])),
            ("Rule 3 or 4 to a CDC only", c(lambda r: r["any_cdc"] and not r["any_da"])),
            ("No match", c(lambda r: not r["n"]))]
    for label, v in rows:
        print(f"| {label} | {v:,} | {pct(v, n)} |")

    by = collections.defaultdict(list)
    for r in res:
        by[r["council"]].append(r)
    top = sorted(by.items(), key=lambda kv: -len(kv[1]))[:15]
    names = {k for k, _ in top} | {"Blacktown City Council", "Penrith City Council"}
    print("\n| Council | CCs | Rule 4 | Rule 3 | Either | Either, DA | Either, CDC only |\n|---|---|---|---|---|---|---|")
    for council, rs in sorted(((k, by[k]) for k in names if k in by), key=lambda kv: -len(kv[1])):
        m = len(rs)
        f = lambda g: pct(sum(1 for r in rs if g(r)), m)
        print(f"| {council} | {m} | {f(lambda r: r['r4'])} | {f(lambda r: r['r3'])} | {f(lambda r: r['n'])} | "
              f"{f(lambda r: r['any_da'])} | {f(lambda r: r['any_cdc'] and not r['any_da'])} |")

    matched = [r for r in res if r["n"]]
    m = len(matched)
    multi = [r for r in matched if r["n"] > 1]
    print(f"\nMatched {m:,}; one candidate {m - len(multi):,} ({pct(m - len(multi), m)}); "
          f"several {len(multi):,} ({pct(len(multi), m)})")
    for name, at, order in (("Order a, b, c, d, e (as briefed)", "a_at", "abcde"),
                            ("Order a, c, d, e, then b last", "b_at", "acdeb")):
        print(f"\n{name}, over {len(multi):,} ambiguous CCs:\n\n| Step | Resolves | Cumulative | Still ambiguous |\n|---|---|---|---|")
        cum = 0
        for k in order:
            got = sum(1 for r in multi if r[at] == k)
            cum += got
            print(f"| {k} | {got:,} | {cum:,} ({pct(cum, len(multi))}) | {len(multi) - cum:,} ({pct(len(multi) - cum, len(multi))}) |")
    filt_one = sum(1 for r in matched if r["filt_left"] == 1)
    print(f"\nFilters a, c, d, e only (no date pick): one candidate left for {filt_one:,} of {m:,} matched ({pct(filt_one, m)})")

    lags = [r["lag"] for r in matched]
    single = [r["lag"] for r in matched if r["single"]]
    print(f"\nLag consent -> CC, days (final pick): {quantiles(lags)}; single-candidate CCs only: {quantiles(single)}")
    print(f"Lag over 3 years: {sum(1 for x in lags if x > 1095):,}; over 5 years: {sum(1 for x in lags if x > 1825):,}")
    return {"n": n, "matched": m, "r4": c(lambda r: r["r4"]), "r3": c(lambda r: r["r3"]), "filt_one": filt_one,
            "lags": lags, "res": res}


def confidence(res, window):
    """probable: rule 4, one candidate after filters a/c/d/e, lag within window. possible: other matches."""
    n = len(res)
    prob = sum(1 for r in res if r["n"] and r["pick_r4"] and r["filt_left"] == 1 and 0 <= r["lag"] <= window)
    poss = sum(1 for r in res if r["n"]) - prob
    print(f"\nConfidence (window {window} days): probable {prob:,} ({pct(prob, n)}), possible {poss:,} ({pct(poss, n)}), "
          f"none {n - prob - poss:,} ({pct(n - prob - poss, n)})")
    return prob, poss


def oc_to_cc():
    ocs, _ = fetch_all("OC", {"DeterminationDateFrom": "2024-01-01", "DeterminationDateTo": "2024-12-31"})
    ccs, _ = fetch_all("OnlineCC", SINCE_2019)
    idx = index(ccs, "CC")
    out = []
    for oc in ocs:
        d = norm_date(oc.get("DeterminationDate"))
        if not d:
            continue
        cs = candidates(oc, d, idx)
        pick = max(cs, key=lambda p: p["date"]) if cs else None
        out.append({"big": (oc.get("CostOfDevelopment") or 0) > 1_000_000, "n": len(cs),
                    "r4": any(p["r4"] for p in cs), "r3": any(p["r3"] for p in cs),
                    "lag": days(d, pick["det"] or pick["date"]) if pick else None})
    print(f"\n### OC -> CC, OCs determined 2024 ({len(ocs):,}); candidate CCs lodged 2019+ ({len(ccs):,})\n")
    print("| Cohort | OCs | Rule 4 | Rule 3 | Either | One candidate | Lag days |\n|---|---|---|---|---|---|---|")
    for label, rs in (("All OCs", out), ("OCs over $1M", [r for r in out if r["big"]])):
        m = len(rs)
        f = lambda g: pct(sum(1 for r in rs if g(r)), m)
        print(f"| {label} | {m:,} | {f(lambda r: r['r4'])} | {f(lambda r: r['r3'])} | {f(lambda r: r['n'])} | "
              f"{f(lambda r: r['n'] == 1)} | {quantiles([r['lag'] for r in rs if r['lag'] is not None])} |")
    return out


def main():
    cc = report_cc(cc_to_consent())
    q = statistics.quantiles(cc["lags"], n=10)
    for w in (round(q[8]), 1095, 1825):
        confidence(cc["res"], w)
    oc = oc_to_cc()
    with open(os.path.join(RAW, "q7_statewide.json"), "w") as fh:
        json.dump({"cc": cc["res"], "oc": oc}, fh)


if __name__ == "__main__":
    main()
