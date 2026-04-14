# fr-CA Localization Audit Findings for Claude

Date: 2026-02-17
Repo: PERTI
Scope: `assets/locales/fr-CA.json` cross-checked against `assets/locales/en-US.json` and runtime usage in JS.

## 1) Canonical term decision (confirmed)
Keep:
- `GDP = "Programme de retard au sol"`
- Reference key: `assets/locales/fr-CA.json:83`

## 2) Functional blockers (runtime interpolation defects)
These can break rendered strings at runtime because placeholder tokens in FR do not match what code passes.

1. `demand.flightDetail.title`
- FR value drops placeholders entirely.
- FR: `assets/locales/fr-CA.json:3506` ("Détails des vols")
- EN template: `assets/locales/en-US.json:3383` ("Flights: {start} - {end}")
- Runtime call expects `{start}`, `{end}`: `assets/js/demand.js:5636`

2. `gdt.gs.previewStatus`
- FR uses `{vols}` but runtime passes `{flights}`.
- FR: `assets/locales/fr-CA.json:1443`
- EN: `assets/locales/en-US.json:1204`
- Runtime call: `assets/js/gdt.js:5357`

3. `gdt.gs.simulatedStatus`
- FR uses `{vols}` but runtime passes `{flights}`.
- FR: `assets/locales/fr-CA.json:1453`
- EN: `assets/locales/en-US.json:1214`
- Runtime call: `assets/js/gdt.js:5440`

4. `reroute.refreshAgo`
- FR uses `{secondes}` but runtime passes `{seconds}`.
- FR: `assets/locales/fr-CA.json:659`
- EN: `assets/locales/en-US.json:654`
- Runtime call: `assets/js/reroute.js:64`

5. `splits.scheduled.inDaysHours`
- FR uses `{heures}` but runtime passes `{hours}`.
- FR: `assets/locales/fr-CA.json:3141`
- EN: `assets/locales/en-US.json:2712`
- Runtime call: `assets/js/splits.js:5778`

6. `splits.scheduled.inHoursMinutes`
- FR uses `{heures}` but runtime passes `{hours}`.
- FR: `assets/locales/fr-CA.json:3142`
- EN: `assets/locales/en-US.json:2713`
- Runtime call: `assets/js/splits.js:5780`

Note: `PERTII18n.t()` only replaces exact placeholder names, so renamed tokens will not interpolate.
- Interpolation logic: `assets/js/lib/i18n.js:90` to `assets/js/lib/i18n.js:99`

## 3) ATFM terminology consistency issues
Choose one canonical term per concept and apply globally.

### 3.1 Reroute terminology is inconsistent
Current variants in FR:
- `Réacheminement` (e.g., `assets/locales/fr-CA.json:88`)
- `AVIS DE DÉROUTEMENT` (e.g., `assets/locales/fr-CA.json:774`)
- `Reacheminement` (unaccented) at multiple keys (`assets/locales/fr-CA.json:662`, `assets/locales/fr-CA.json:3623`, `assets/locales/fr-CA.json:3649`, `assets/locales/fr-CA.json:4663`, `assets/locales/fr-CA.json:4592`)

### 3.2 MIT/MINIT terminology is inconsistent
Current variants:
- `MIT` at `assets/locales/fr-CA.json:89` and `assets/locales/fr-CA.json:467`
- `Espacement en milles` at `assets/locales/fr-CA.json:468` and `assets/locales/fr-CA.json:4552`
- `Minutes en route séquencées` at `assets/locales/fr-CA.json:90`
- `MINIT` at `assets/locales/fr-CA.json:469`
- `Espacement en minutes` at `assets/locales/fr-CA.json:470` and `assets/locales/fr-CA.json:4553`

### 3.3 TBM/flow wording inconsistent
- `tmiPublish.page.tbmDesc`: "Mesure temporelle" (`assets/locales/fr-CA.json:480`)
- `tmiActive.entryType.tbm`: "Régulation temporelle" (`assets/locales/fr-CA.json:4556`)
- `tmiPublish.page.stopDesc`: "Arrêt de flux" (`assets/locales/fr-CA.json:476`)

## 4) ATFM-adjacent mixed-language strings (quality defects)
These are in operational GS/GDP/EDCT/TMI contexts and should be fully FR.

- `assets/locales/fr-CA.json:1375` (`gdt.edct.enterNewEdctValidation`)
- `assets/locales/fr-CA.json:1381` (`gdt.edct.simulatedMessage`)
- `assets/locales/fr-CA.json:1384` (`gdt.edct.updateNote`)
- `assets/locales/fr-CA.json:1413` (`gdt.gs.activeStatus`)
- `assets/locales/fr-CA.json:1426` (`gdt.gs.gsActivated`)
- `assets/locales/fr-CA.json:1428` (`gdt.gs.gsIssued`)
- `assets/locales/fr-CA.json:1458` (`gdt.gs.activateTitle`)
- `assets/locales/fr-CA.json:1651` (`gdt.print.subheading`)
- `assets/locales/fr-CA.json:4592` (`tmiActive.batchCancel.postCancelDesc`)

## 5) Orthography/diacritic normalization needed in domain terms
- `Arret` should be `Arrêt` in ATFM strings:
  - `assets/locales/fr-CA.json:1426`
  - `assets/locales/fr-CA.json:1428`
  - `assets/locales/fr-CA.json:1458`
  - `assets/locales/fr-CA.json:1651`
  - `assets/locales/fr-CA.json:4661`

- `Reacheminement` should be `Réacheminement` where that term is retained:
  - `assets/locales/fr-CA.json:662`
  - `assets/locales/fr-CA.json:3623`
  - `assets/locales/fr-CA.json:3649`
  - `assets/locales/fr-CA.json:4592`
  - `assets/locales/fr-CA.json:4663`

## 6) Structural QA summary
- EN leaf keys: 3761
- FR leaf keys: 4119
- Missing FR keys vs EN: 0
- Extra FR-only keys: 358
- Placeholder mismatches detected: 10
- Placeholder mismatches currently exercised by runtime JS: 6 (listed in Section 2)

## 7) Source note for GDP term
`Programme de retard au sol` aligns with Transport Canada bilingual aviation terminology and should remain the canonical GDP translation for this project.

## 8) Priority order for remediation
1. Fix Section 2 placeholder mismatches (functional breakage).
2. Normalize ATFM term lexicon (Section 3 + Section 5) with one canonical term per concept.
3. Clean mixed EN/FR operational strings in Section 4.
4. Optionally prune/align FR-only extras that are not referenced.

## 9) Suggested instruction to Claude
"Use `docs/fr-ca-localization-audit-for-claude.md` as authoritative input. Keep GDP as `Programme de retard au sol`. Fix placeholder token mismatches exactly (do not rename runtime parameter tokens), then normalize ATFM vocabulary and diacritics consistently across fr-CA keys."
