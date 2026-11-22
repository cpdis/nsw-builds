# Milestone 1: data spike

**Status: running.** The cloud sandbox can't reach `api.apps1.nsw.gov.au` (the egress proxy refuses the CONNECT with 403), so the spike runs on Colin's Mac. This file is replaced with the findings when it finishes.

## Questions

1. Do CC or OC records name their DA or consent? Dump every field on 50 CCs and 50 OCs.
2. Row counts per feed per year, 2019 to 2026.
3. Any throttling at 1,000 rows a page?
4. **Pass mark:** at least 70% of 2023 CCs over $1M link to a DA by rule 1 (explicit reference) or rule 2 (same lot and plan, CC after DA).

## Effect on the code

- `ApplicationMapper::PARENT_REFERENCE_FIELDS` lists guessed field names for an explicit parent reference. Replace with the real ones, or empty it, once question 1 is answered.
- `EplanningClient::extractRecords()` accepts several envelope shapes until the real one is known.
- Volume decides the map: above about 100,000 projects, nightly PMTiles or viewport queries rather than GeoJSON. The plan already assumes this.
