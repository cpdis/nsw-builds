"""Q6 prefetch: cache every page the statewide rerun needs (spike/raw/cache).

- CCs determined in 2023 with cost >= $1M (the CC cohort, already cached by q4).
- All DAs and all CDCs lodged 2019-01-01 to 2026-12-31, no cost filter.
- All CCs lodged 2019 to 2026 (candidates for OC -> CC).
- OCs determined in 2024 (the OC cohort).
"""

from lib import fetch_all

SINCE_2019 = {"LodgementDateFrom": "2019-01-01", "LodgementDateTo": "2026-12-31"}
JOBS = [
    ("OnlineCC", {"DeterminationDateFrom": "2023-01-01", "DeterminationDateTo": "2023-12-31",
                  "CostOfDevelopmentFrom": 1000000}),
    ("OC", {"DeterminationDateFrom": "2024-01-01", "DeterminationDateTo": "2024-12-31"}),
    ("OnlineCDC", SINCE_2019),
    ("OnlineDA", SINCE_2019),
    ("OnlineCC", SINCE_2019),
]

if __name__ == "__main__":
    for feed, filt in JOBS:
        rows, total = fetch_all(feed, filt)
        print(f"done {feed} {filt}: {len(rows):,} of {total:,}", flush=True)
