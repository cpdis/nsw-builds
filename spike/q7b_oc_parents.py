"""Q7b (cache only): do OCs that miss a CC link to a CDC instead?

OCs determined in 2024; parents are CCs and CDCs lodged 2019+, same location
rule as q7 (rule 3 or 4, parent date on or before the OC date).
"""
from lib import fetch_all
from q4_linkrate import norm_date
from q7_statewide import SINCE_2019, candidates, index, pct

ocs, _ = fetch_all("OC", {"DeterminationDateFrom": "2024-01-01", "DeterminationDateTo": "2024-12-31"})
cc_idx = index(fetch_all("OnlineCC", SINCE_2019)[0], "CC")
cdc_idx = index(fetch_all("OnlineCDC", SINCE_2019)[0], "CDC")
n = cc = cdc_only = none = 0
for oc in ocs:
    d = norm_date(oc.get("DeterminationDate"))
    if not d:
        continue
    n += 1
    if candidates(oc, d, cc_idx):
        cc += 1
    elif candidates(oc, d, cdc_idx):
        cdc_only += 1
    else:
        none += 1
print(f"\nOCs {n:,}: link to a CC {cc:,} ({pct(cc, n)}); CDC only {cdc_only:,} ({pct(cdc_only, n)}); "
      f"CC or CDC {cc + cdc_only:,} ({pct(cc + cdc_only, n)}); none {none:,} ({pct(none, n)})")
