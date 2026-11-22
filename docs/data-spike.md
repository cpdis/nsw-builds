# Milestone 1: data spike findings

**Verdict: FAIL on the 70% pass mark as defined.** Rules 1 and 2 link **0%** of 2023 CCs over $1M to a DA. CC records carry no DA or consent reference and no lot data at all (0 of 12,262 CCs fetched). A coordinate match (same geocoded point, CC on or after DA) links **70.7%** with a $500k DA cost floor, and **88.1%** in the City of Sydney check without the floor. So a link is possible, but only by location, not by reference or title.

**Update, statewide rerun (2026-11-22): YES, the 70% mark is cleared by the location rule.** With every DA and CDC since 2019 as a candidate and no cost floor, **86.6%** of the 5,317 CCs link by same coordinates (86.8% by coordinates or address). **70.8%** link as "probable": one candidate left after the filters, and a lag of 3 years or less. Rules 1 and 2 as first written still score 0%. See [Statewide rerun](#statewide-rerun).

Run on 2026-11-22 from Colin's Mac. Scripts are in `spike/`; raw responses are cached in `spike/raw/` (gitignored). Rerun any script with `python3 spike/<script>.py`; cached pages are not fetched again.

## Headline numbers

| Measure | Value |
|---|---|
| 2023-determined CCs over $1M | 5,317 |
| Rule 1 (explicit reference) | 0 (0.0%) |
| Rule 2 (lot + plan) | 0 (0.0%) |
| Rule 1 or 2 | 0 (0.0%) |
| Rule 3 (normalised address) | 3,539 (66.6%) |
| Rule 4 (same coordinates, 5 dp) | 3,761 (70.7%) |
| Rule 3 or 4 | 3,769 (70.9%) |
| Rule 4, City of Sydney, no DA cost floor | 297 of 337 (88.1%) |
| Throttling at 1,000 rows a page | none seen (10 of 10 pages 200, 3.3 to 3.6 s) |
| Licence | "Creative Commons Attribution" (`cc-by`), no version stated |
| CC builder names that are companies | 97.4% |

## Envelope and filters

Every feed answers with the same envelope. Rows are under `Application`, and the envelope has a total count, so no binary search is needed:

```json
{"PageSize": 20, "PageNumber": 1, "TotalPages": 274, "TotalCount": 5465, "Application": [ ... ]}
```

Filter support, tested by comparing `TotalCount` with the unfiltered total and checking every returned row (`spike/q_filters.py`):

| Filter | OnlineDA | OnlineCDC | OnlineCC | OC |
|---|---|---|---|---|
| `LodgementDateFrom` / `To` | yes | yes | yes | yes |
| `DeterminationDateFrom` / `To` | yes | yes | yes | yes |
| `CostOfDevelopmentFrom` / `To` | yes | yes | yes | yes |
| `CouncilName` (upper-case list) | yes | yes | yes | yes |
| `ApplicationLastUpdatedFrom` | yes | yes | yes | yes |
| `ApplicationStatus` (list) | yes | yes, but `Determined` gives 0 (CDC uses other status values) | yes | yes |
| `DevelopmentCategory` `["Residential"]` | changes count (297,640) | changes count (143,878) | 0 rows | 0 rows |
| Unknown filter name | ignored silently | ignored | ignored | ignored |

Silent ignore is a trap: a misspelt filter returns the whole feed. The client should check that `TotalCount` changed when it sends a filter.

## Q1: fields per feed

Samples: the oldest page plus a page lodged in June 2023 (50 CC, 50 OC, 20 DA, 20 CDC each). Field coverage was then checked against every cached row (127,045 DAs, 12,262 CCs, 917 OCs, 857 CDCs). Fill counts below are from the Q1 sample; raw dumps are in `spike/raw/q1_*.json`.

### OnlineCC (construction certificates)

| Field path | Filled | Example |
|---|---|---|
| `PlanningPortalApplicationNumber` | 100/100 | CFT-134626 |
| `ApplicationStatus` | 100/100 | Withdrawn |
| `BuilderLegalName` | 39/100 | GRANITE WAY PTY. LTD. |
| `BuilderTradingName` | 12/100 | NEIL TOLLEY MASTER BUILDER |
| `BuildingCodeClass[].BuildingCodeClass` | 100/100 | Class 1a |
| `BuildingCodeClass[].BuildingCodeDescription` | 100/100 | A single dwelling being a detached house ... |
| `CostOfDevelopment` | 91/100 | 4167306.0 |
| `Council.CouncilName` | 100/100 | Sutherland Shire Council |
| `CurrentBuildingUse` | 85/100 | NBV |
| `ProposedBuildingUse` | 85/100 | JHG |
| `DateLastUpdated` | 100/100 | 2022-11-19T19:18:15.467 |
| `DateSubmitted` | 50/100 | 2023-06-01 |
| `LodgementDate` | 50/100 | 2023-06-01 |
| `DeterminationDate` | 48/100 | 2023-06-01 |
| `DevelopmentType[].DevelopmentType` | 89/100 | Pools / decks / fencing |
| `ExistingGrossFloorArea` | 87/100 | 4.0 |
| `ProposedGrossFloorArea` | 83/100 | 6.0 |
| `LandArea` | 85/100 | 12.0 |
| `ProposedLots` | 35/100 | 1 |
| `StoreysProposed` | 88/100 | 0 |
| `UnitsProposed` | 76/100 | 1 |
| `Location[].FullAddress` | 100/100 | 129 HALL DRIVE MENAI 2234 |
| `Location[].StreetNumber1`, `StreetNumber2`, `StreetName`, `StreetType`, `StreetSuffix`, `Suburb`, `Postcode`, `State` | 93 to 95/100 | 129, HALL, DRIVE, MENAI, 2234 |
| `Location[].X` / `Location[].Y` | 100/100 | 151.004799033 / -34.020673514 |

No `Location[].Lot` on any of 12,262 CCs. No DA, consent or parent field.

### OC (occupation certificates)

| Field path | Filled | Example |
|---|---|---|
| `PlanningPortalApplicationNumber` | 100/100 | CFT-59783 |
| `ApplicationStatus` | 100/100 | Cancelled |
| `BuilderLegalName` | 66/100 | HIBBARDS PTY LTD |
| `BuilderTradingName` | 33/100 | HIBBARD HOMES |
| `SearchBusinessBy` | 84/100 | ABN |
| `BuildingCodeClass[].BuildingCodeClass` | 100/100 | Class 1a (no description on OC) |
| `CostOfDevelopment` | 62/100 | 40000.0 |
| `Council.CouncilName` | 100/100 | Armidale Regional Council |
| `DateLastUpdated` | 100/100 | 2022-10-22T15:31:34.807 |
| `LodgementDate` / `SubmissionDate` / `DeterminationDate` | 50, 50, 47/100 | 2023-06-01 |
| `DevPurpose` | 85/100 | Single Storey Dwelling with Attached Double Garage ... |
| `DevelopmentType[].DevelopmentType` | 85/100 | Dwelling |
| `Location[]` (same address fields and X/Y as CC) | 96 to 100/100 | 3 WILLOW WAY ARMIDALE 2350 |

No `Location[].Lot`, no CC reference, no DA reference. OC and CC numbers both use the `CFT-` prefix, but none of 703 cached OC numbers appeared among 7,548 cached CC numbers, so the PAN is not a shared key (small sample, not conclusive).

### OnlineDA (development applications)

| Field path | Filled | Example |
|---|---|---|
| `PlanningPortalApplicationNumber` | 40/40 | PAN-186640 |
| `CouncilApplicationNumber` | 20/40 | DA2300758, DA/2023/342:1, DA 16-2023-306-1 |
| `ModificationApplicationNumber` | 7/40 | D394/15 (the parent DA of a modification) |
| `ApplicationType` | 40/40 | Development Application |
| `ApplicationStatus` | 40/40 | Cancelled |
| `CostOfDevelopment` | 37/40 | 140000.0 |
| `Council.CouncilName` | 40/40 | Northern Beaches Council |
| `DateLastUpdated` | 40/40 | 2022-07-30T23:23:26.060 |
| `LodgementDate` / `SubmissionDate` / `DeterminationDate` | 20, 20, 19/40 | 2023-06-01 |
| `DeterminationAuthority` | 40/40 | Unknown |
| `DevelopmentType[].DevelopmentType` | 39/40 | Alterations and additions to residential development |
| `NumberOfNewDwellings` / `NumberOfStoreys` / `NumberOfExistingLots` / `NumberOfProposedLots` | 37, 19, 13, 1/40 | 1, 3, 1, 2 |
| `SubdivisionProposedFlag`, `SubdivisionType[].SubdivisionType` | 31, 1/40 | N, Torrens Title |
| `AccompaniedByVPAFlag`, `VPAStatus[].VPAStatus` | 31/40, 717 in full set | N, Executed |
| `EPIVariationProposedFlag`, `VariationToDevelopmentStandardsApprovedFlag`, `DevelopmentSubjectToSICFlag` | 30, 6, 19/40 | N |
| `AssessmentExhibitionStartDate` / `EndDate` | about 60% of full set | 2022-02-01T00:00:00 |
| `Location[].Lot[].Lot` / `PlanLabel` / `Section` | 36, 36, 4/40 | 44 / DP235700 / 4 |
| `Location[]` address fields and X/Y | 36 to 40/40 | 2 MARCUS PLACE FRENCHS FOREST 2086 |

### OnlineCDC (complying development certificates)

| Field path | Filled | Example |
|---|---|---|
| `PlanningPortalApplicationNumber` | 40/40 | CDC-95161 |
| `CertifierApplicationNumber` / `CertifierAccreditationNumber` | 20, 14/40 | 230178 / BDC0016 |
| `ApplicationType`, `ApplicationStatus`, `CDCAcceptedDisplay` | 40/40 | Complying Development Certificate Application, Cancelled, CDC Rejected |
| `RejectReason` | rare | free text |
| `ModificationApplicationNumber`, `ProposedModificationDescription` | 2/40 | CFT 112339 |
| `BuildingCodeClass[].BuildingCodeClass` / `Description` | 19/40 | Class 10b |
| `CostOfDevelopment` | 39/40 | 2200000.0 |
| `LodgementDate` / `SubmissionDate` / `DeterminationDate` | 20, 20, 8/40 | 2023-06-06T16:10:00.287 |
| `NumberOfNewDwellings` / `NumberOfDemolitionDwellings` / `NumberOfPreExistDwellings` / `NumberOfStoreys` | 8/40 | 2, 1, 1, 2 |
| `Council.CouncilName`, `DateLastUpdated`, `DevelopmentType[].DevelopmentType` | 39 to 40/40 | Shellharbour City Council |
| `Location[].Lot[]` and address fields, X/Y | 33 to 40/40 | 1 / DP1009804 |

### Reference grep

Pattern `DA-`, `DA/`, `DA <digit>`, `PAN-`, `CNR-`, `CDC-`, `CC-`, "consent" over every non-PAN value:

- OnlineCC: 0 hits. OC: 0 hits.
- OnlineDA: 18 hits, all in `CouncilApplicationNumber` (the council's own DA number) and `ModificationApplicationNumber` (the parent DA of a modification).
- OnlineCDC: 2 hits in `CertifierApplicationNumber` (certifier's own number).

## Q2: rows per feed per year

From `TotalCount` (`spike/q2_counts.py`). The portal became mandatory for councils on 1 July 2021 (dataset notes), so 2019 and 2020 are thin.

By lodgement date:

| Year | OnlineDA | OnlineCDC | OnlineCC | OC |
|---|---|---|---|---|
| 2019 | 2,843 | 30 | 226 | 778 |
| 2020 | 26,732 | 628 | 2,682 | 3,390 |
| 2021 | 75,610 | 23,315 | 31,355 | 27,177 |
| 2022 | 73,325 | 35,606 | 44,164 | 44,442 |
| 2023 | 60,370 | 36,325 | 40,625 | 51,744 |
| 2024 | 55,227 | 37,014 | 37,352 | 52,783 |
| 2025 | 55,775 | 39,700 | 37,346 | 50,659 |
| 2026 (to 22 Nov) | 49,669 | 38,981 | 33,191 | 44,639 |
| Total 2019 to 2026 | 399,551 | 211,599 | 226,941 | 275,612 |
| All rows, no filter | 448,150 | 253,233 | 299,358 | 339,426 |

By determination date:

| Year | OnlineDA | OnlineCDC | OnlineCC | OC |
|---|---|---|---|---|
| 2019 | 1,696 | 22 | 38 | 5 |
| 2020 | 17,652 | 458 | 1,607 | 382 |
| 2021 | 60,746 | 19,795 | 24,646 | 14,915 |
| 2022 | 69,574 | 32,622 | 40,666 | 35,228 |
| 2023 | 60,745 | 33,126 | 38,813 | 50,855 |
| 2024 | 55,720 | 34,725 | 36,560 | 56,339 |
| 2025 | 54,079 | 37,843 | 36,630 | 53,965 |
| 2026 (to 22 Nov) | 47,970 | 36,568 | 33,428 | 47,607 |

The gap between "all rows" and "2019 to 2026 by lodgement" (about 49k DAs, 72k CCs) is rows with no lodgement date (probably withdrawn or cancelled drafts; not checked). Total volume is about 1.34 million rows across the four feeds, so the map needs PMTiles or viewport queries, not one GeoJSON file.

## Q3: throttling

10 consecutive 1,000-row pages of OnlineCC (lodged 2022), no delay, no cache (`spike/q3_throttle.py`):

| Page | Status | Rows | Latency s | Payload KB |
|---|---|---|---|---|
| 1 | 200 | 1000 | 3.43 | 1118 |
| 2 | 200 | 1000 | 3.38 | 1104 |
| 3 | 200 | 1000 | 3.28 | 1118 |
| 4 | 200 | 1000 | 3.32 | 1097 |
| 5 | 200 | 1000 | 3.38 | 1098 |
| 6 | 200 | 1000 | 3.43 | 1089 |
| 7 | 200 | 1000 | 3.51 | 1107 |
| 8 | 200 | 1000 | 3.45 | 1116 |
| 9 | 200 | 1000 | 3.52 | 1112 |
| 10 | 200 | 1000 | 3.62 | 1091 |

No 429s and no slowdown (latency drifts up by 0.2 s at most). About 1.1 KB a row. A full backfill of 1.34M rows is about 1,340 pages, about 1.3 hours at 3.5 s a page sequentially, and about 1.5 GB of JSON.

## Q4: CC to DA link rate

`spike/q4_linkrate.py`. Cohort A is CCs determined in 2023; cohort B is CCs lodged in 2023. Both use the server-side `CostOfDevelopmentFrom: 1000000` filter, then cost over $1,000,000 client-side. Candidate DAs: lodged 2019 to 2026, `CostOfDevelopmentFrom: 500000` (113,063 DAs, 101,430 lot keys, 93,334 addresses). For every rule the DA date (determination, else lodgement) must be on or before the CC date.

- Rule 1: an explicit DA or consent reference on the CC that matches a DA's PAN or council number.
- Rule 2: same normalised lot key (`12/DP123456`, or `12/4/DP123456` with a section).
- Rule 3: same normalised `FullAddress` (upper case, punctuation removed, street-type abbreviations expanded).
- Rule 4: same `Location[].X` / `Y` rounded to 5 decimal places (about 1 m). Not in the brief; added because rules 1 and 2 have no input data.

| Measure | Cohort A (determined 2023) | Cohort B (lodged 2023) |
|---|---|---|
| CCs over $1M | 5,317 | 5,750 |
| Rule 1 explicit reference | 0.0% | 0.0% |
| Rule 2 lot/plan | 0.0% | 0.0% |
| **Rule 1 or 2 (pass mark 70%)** | **0.0%** | **0.0%** |
| Rule 3 address only | 66.6% | 66.6% |
| Rule 4 same coordinates | 70.7% | 70.6% |
| Rule 3 or 4 | 70.9% | 70.7% |
| CCs with lot data | 0 (0.0%) | 0 (0.0%) |

Top 10 councils, cohort A (rule 1 or 2 is 0.0% for all of them):

| Council | CCs | Rule 3 address | Rule 4 coords |
|---|---|---|---|
| Council of the City of Sydney | 337 | 64.4% | 72.1% |
| Northern Beaches Council | 283 | 79.9% | 83.4% |
| The Hills Shire Council | 205 | 74.1% | 76.6% |
| Woollahra Municipal Council | 196 | 70.4% | 75.0% |
| Blacktown City Council | 168 | 38.1% | 41.7% |
| Sutherland Shire Council | 158 | 67.7% | 74.7% |
| City of Parramatta Council | 152 | 65.8% | 67.1% |
| Cumberland Council | 152 | 50.7% | 59.9% |
| Penrith City Council | 141 | 53.9% | 61.7% |
| Randwick City Council | 135 | 55.6% | 60.0% |

Blacktown and Penrith are low. Greenfield estates are a likely cause: the DA is a subdivision at the parent lot's address, and the CC is for a new lot with a new address. A CDC (not a DA) may also be the consent for many CCs; CDCs were not in the candidate set.

**The $500k cost floor biases the rate down, by a lot.** City of Sydney with every DA from 2019 (13,025 DAs, no cost floor): rule 3 80.7%, rule 4 88.1%, against 64.4% and 72.1% with the floor. DAs often state a lower cost than the CC, or a cost of 0. The full-state, no-floor rate is probably well above 70% for rule 4, but it was measured for one council only.

**Rule 4 matches are ambiguous** (`spike/q4b_ambiguity.py`, cohort A, capped DA set): 58.8% of matched CCs have exactly 1 candidate DA, 32.0% have 2 or 3, and 9.2% have 4 or more (modifications, staged DAs, strata sites). The median gap from the latest candidate DA to the CC is 179 days (p25 77, p75 362); 17 gaps are over 3 years. A real linker needs a tie-break: prefer the original DA over its modifications (`ModificationApplicationNumber`), then the latest determined DA before the CC, and expose the match as "probable" in the UI.

## Statewide rerun

**Verdict: yes, the location rule clears 70%.** Rule 4 links 86.6% of 2023 CCs over $1M. The strict "probable" tier alone links 70.8%. There is no ground truth, so these are match rates, not accuracy: precision is not measured yet.

Scripts: `spike/q6_prefetch.py` caches the data, `spike/q7_statewide.py` runs the analysis, and `spike/q7b_oc_parents.py` runs the OC check. Output is in `spike/raw/q7_out.md`.

- Child cohort: the same 5,317 CCs determined in 2023 with cost over $1M.
- Candidate consents: all 399,551 DAs and 211,599 CDCs lodged 2019 to 2026, no cost filter.
- A consent qualifies if its date (determination, else lodgement) is on or before the CC determination date.

### CC to consent

| Measure | CCs | Share |
|---|---|---|
| Rule 4 same coordinates (DA or CDC) | 4,606 | 86.6% |
| Rule 3 normalised address (DA or CDC) | 4,325 | 81.3% |
| **Rule 3 or 4** | **4,614** | **86.8%** |
| Rule 4 to a DA | 4,536 | 85.3% |
| Rule 4 to a CDC | 734 | 13.8% |
| Rule 3 or 4 to a CDC only (no DA) | 71 | 1.3% |
| No match | 703 | 13.2% |

Removing the cost floor added 16 points (70.7% to 86.6%). CDCs add only 1.3 points statewide, because most CCs that match a CDC also match a DA at the same site.

Top 15 councils by CC count. Blacktown and Penrith are both in the top 15.

| Council | CCs | Rule 4 | Rule 3 | Either | Either, DA | Either, CDC only |
|---|---|---|---|---|---|---|
| Council of the City of Sydney | 337 | 89.9% | 82.2% | 89.9% | 88.4% | 1.5% |
| Northern Beaches Council | 283 | 91.2% | 88.0% | 91.5% | 91.2% | 0.4% |
| The Hills Shire Council | 205 | 90.2% | 84.4% | 90.2% | 87.3% | 2.9% |
| Woollahra Municipal Council | 196 | 95.9% | 92.3% | 95.9% | 95.9% | 0.0% |
| **Blacktown City Council** | 168 | 78.0% | 61.9% | 78.0% | 70.2% | 7.7% |
| Sutherland Shire Council | 158 | 88.0% | 81.6% | 89.2% | 89.2% | 0.0% |
| City of Parramatta Council | 152 | 88.8% | 87.5% | 89.5% | 88.8% | 0.7% |
| Cumberland Council | 152 | 78.9% | 67.1% | 78.9% | 78.3% | 0.7% |
| **Penrith City Council** | 141 | 76.6% | 72.3% | 76.6% | 73.0% | 3.5% |
| Randwick City Council | 135 | 83.7% | 75.6% | 83.7% | 83.0% | 0.7% |
| Central Coast Council | 131 | 87.0% | 83.2% | 87.8% | 87.8% | 0.0% |
| Waverley Council | 130 | 93.8% | 85.4% | 93.8% | 93.1% | 0.8% |
| Inner West Council | 127 | 88.2% | 85.0% | 89.8% | 89.8% | 0.0% |
| Lake Macquarie City Council | 123 | 87.8% | 82.9% | 87.8% | 87.8% | 0.0% |
| Canterbury-Bankstown Council | 117 | 79.5% | 71.8% | 80.3% | 75.2% | 5.1% |

Blacktown rose from 41.7% to 78.0%, and Penrith from 61.7% to 76.6%. Both are now above 70%, but still the lowest in the top 15. CDCs count for more there: 7.7% of Blacktown CCs and 3.5% of Penrith CCs match only a CDC, because greenfield houses often go through complying development. In both councils, address matching (rule 3) is much weaker than coordinate matching. My hypothesis is that new-estate addresses change between consent and CC; I did not check this.

The 703 unmatched CCs are not analysed. Likely causes are consents lodged before 2019, or before councils had to use the portal (1 July 2021), and sites whose geocode or address changed.

### Ambiguity and tie-breaks

Of the 4,614 matched CCs, 1,933 (41.9%) have exactly one candidate consent, and 2,681 (58.1%) have several. Most of the several are modifications and reviews of the same DA, or a DA and a CDC on the same site.

Tie-breaks run in order on the ambiguous set. A step never empties the set; if no candidate passes, the step is skipped.

- a: determined on or before the CC date, and not withdrawn, rejected, cancelled, refused or surrendered.
- b: closest preceding consent date.
- c: compatible development type (coarse classes: residential, commercial, industrial, subdivision; generic types such as "Erection of a new structure" match anything).
- d: consent cost within a factor of 3 of the CC cost.
- e: original application over a modification or review.

Order as briefed (a, b, c, d, e):

| Step | Resolves | Cumulative | Still ambiguous |
|---|---|---|---|
| a | 148 | 148 (5.5%) | 2,533 (94.5%) |
| b | 2,489 | 2,637 (98.4%) | 44 (1.6%) |
| c | 5 | 2,642 (98.5%) | 39 (1.5%) |
| d | 6 | 2,648 (98.8%) | 33 (1.2%) |
| e | 3 | 2,651 (98.9%) | 30 (1.1%) |

Step b resolves almost everything on its own, because two consents rarely share a date. That makes it a guess with no evidence behind it, and it hides what c, d and e can do. So I also ran the filters first, with b last:

| Step | Resolves | Cumulative | Still ambiguous |
|---|---|---|---|
| a | 148 | 148 (5.5%) | 2,533 (94.5%) |
| c | 82 | 230 (8.6%) | 2,451 (91.4%) |
| d | 745 | 975 (36.4%) | 1,706 (63.6%) |
| e | 902 | 1,877 (70.0%) | 804 (30.0%) |
| b | 764 | 2,641 (98.5%) | 40 (1.5%) |

The evidence-based filters (a, c, d, e) leave one candidate for 3,810 of the 4,614 matched CCs (82.6%). The cost and original-over-modification filters do most of the work; type compatibility adds little, because CC and DA types are often generic.

### Lag from consent to CC

Lag is the CC determination date minus the chosen consent's determination date (else its lodgement date):

| Set | p10 | p50 | p90 |
|---|---|---|---|
| All matched CCs (final pick) | 49 days | 261 days | 687 days |
| CCs with one candidate only | 49 days | 233 days | 644 days |

Only 31 of the 4,614 lags are over 3 years, and 3 are over 5 years. **Use a lookback window of 3 years.** It keeps 99.3% of matches. The 2-year p90 window would push about 400 more CCs from "probable" to "possible".

### OC to CC

OCs determined in 2024 (56,339). Candidates: all 226,941 CCs lodged 2019 to 2026, with the same location rule and the CC on or before the OC.

| Cohort | OCs | Rule 4 | Rule 3 | Either | One candidate | Lag p10 / p50 / p90 |
|---|---|---|---|---|---|---|
| All OCs | 56,339 | 52.1% | 49.3% | 52.2% | 35.6% | 70 / 342 / 799 days |
| OCs over $1M | 669 | 99.1% | 99.1% | 99.1% | 46.3% | 24 / 308 / 727 days |

OC to CC alone looks weak, but the reason is that many OCs follow a CDC, not a CC. A CDC acts as both the consent and the construction certificate. With CDCs added as parents (`spike/q7b_oc_parents.py`), 29,389 OCs (52.2%) link to a CC, 19,671 (34.9%) link only to a CDC, **49,060 (87.1%) link to a CC or a CDC**, and 7,279 (12.9%) have no parent. Only 669 OCs are over $1M, because OC cost is often empty (62% fill in Q1).

### Recommended linking rules

1. **Parents.** A CC's parent is a DA or a CDC. An OC's parent is a CC or a CDC.
2. **Match.** Use the same coordinates (5 dp), or the same normalised address. The parent's date must be on or before the child's date, within a 3-year lookback window.
3. **Narrow.** Apply filters a, c, d, e in that order, and never empty the set.
4. **Pick.** If more than one candidate is left, choose the closest preceding date (b), but mark the link as lower confidence.
5. **Store.** Record the matched rule, the number of candidates before and after the filters, and the confidence on the link, so it can be shown and audited later.

| Confidence | Definition | CCs | Share of cohort |
|---|---|---|---|
| **Probable** | Rule 4 match, one candidate left after a, c, d, e, lag 3 years or less | 3,766 | 70.8% |
| **Possible** | Any other match: several candidates left (picked by b), address only, or lag over 3 years | 848 | 15.9% |
| None | No location match | 703 | 13.2% |

In the UI, show a "probable" link as the project's consent. Show a "possible" link as "may relate to" with the other candidates listed.

**Before launch:** spot-check 50 probable and 50 possible links against council DA trackers to measure precision. This spike measures only how many CCs match, not whether the matches are right.

## Q5: licence and builder names

- Licence: both https://data.nsw.gov.au/data/dataset/online-da-data-api and `online-pcc-data-api` say "Creative Commons Attribution" (CKAN `license_id: cc-by`, linking to the generic opendefinition.org page). Neither page states 3.0 or 4.0. Attribute as CC BY and ask the data broker for the version before launch.
- Builder names (`spike/q5_builders.py`, all cached rows, biased towards CCs over $1M):
  - OnlineCC: `BuilderLegalName` on 5,934 of 7,548 records (78.6%). Companies (Pty, Ltd, Trust, Group, Builders, Constructions and similar): 97.4%. Individuals or other: 2.6% (for example "SAMUEL GROSSER", "D LYMBERY & S LYMBERY", "tbc").
  - OC: on 456 of 703 (64.9%). Companies 95.8%. Individuals 4.2%, including first names only ("STEPHEN", "LUKE").
  - Individuals' names are personal data. Show them only with care, or hide names that do not look like a company.

## Surprises

1. No lot data on CCs or OCs at all, although DAs and CDCs have it. This alone kills rule 2.
2. No parent reference of any kind on CCs or OCs. The only explicit links in the data are DA to DA (`ModificationApplicationNumber`) and CDC modification to CDC.
3. Unknown filter names are ignored silently and return the full feed.
4. Dates are ISO in every date field across about 141,000 cached rows. DD/MM/YYYY appears only inside council and modification numbers, not in date fields. Some dates have a time and some do not (`2023-06-01` and `2023-06-06T16:10:00.287`).
5. `Location[].StreetNumber2` and `StreetSuffix` sometimes hold the string `"null"`, not JSON null.
6. CC and OC numbers share the `CFT-` prefix but did not overlap in the sample.
7. `CurrentBuildingUse` / `ProposedBuildingUse` are codes (`NBV`, `JHG`) with no lookup in the feed.

## Effect on the code (not changed yet)

- `EplanningClient::extractRecords()`: the envelope is always an object with rows under `Application`. Read `$body['Application']` only, and throw on anything else. Use `TotalCount` and `TotalPages` for paging instead of stopping on an empty page. When filters are sent, warn if `TotalCount` equals the unfiltered total.
- `ApplicationMapper::PARENT_REFERENCE_FIELDS`: none of the five guessed names exist in any feed. Empty it for CC and OC. For DAs and CDCs, `ModificationApplicationNumber` is the one real parent reference (a modification's parent DA or CDC).
- `ApplicationMapper::toAttributes()`:
  - `lot_keys` is always empty for CC and OC. The CC to DA link must use coordinates (`lng`/`lat`) and `address_key`, not lot keys.
  - Add `CouncilApplicationNumber` (DA) and `CertifierApplicationNumber` (CDC); users search by these.
  - `units`: DA and CDC use `NumberOfNewDwellings`, not `UnitsProposed` (CC only).
  - `lodged_at`: OK (`LodgementDate` exists on all four feeds). `DateSubmitted` (CC) and `SubmissionDate` (others) also exist.
  - Add `DevPurpose` (OC only) as the description.
  - `development_types` and `building_classes`: the list items are objects like `{"DevelopmentType": "Dwelling"}`, so the current `Arr::first($item)` works. `BuildingCodeClass` has a second key, `BuildingCodeDescription`, on CC and CDC.
  - `Normaliser::addressKey()`: treat the string `"null"` as empty.
- The linker should also take CDCs as candidate consents for CCs, and needs a tie-break for the 41% of coordinate matches with more than one candidate DA.
