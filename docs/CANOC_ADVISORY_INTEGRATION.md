# CANOC Advisory Integration into PERTI

## Overview

Integrate Canadian advisory functionality (GDP, Ground Stop) into PERTI's existing TMI Publisher. This replaces the standalone CANTMU Laravel app. PERTI already has the template generators, multi-org infrastructure, and i18n support — the work is exposing GDP/GS as advisory type options when org=canoc.

**Key Decision**: Use PERTI's existing advisory formats exactly as-is. The only format change is the header prefix: `vNAVCAN` → `CANOC`. Do NOT create new Canadian-specific template generators — the existing `generateGDPAdvisory()` and `generateGroundStopAdvisory()` in `advisory-templates.js` are correct and already use `AdvisoryConfig.getPrefix()`.

---

## What's Already Done

### `assets/js/advisory-config.js` — COMPLETED

The NOC prefix has been changed from `vNAVCAN` to `CANOC`:

```js
const ORG_TYPES = {
    DCC: { prefix: 'vATCSCC', facility: 'DCC', name: 'US DCC' },
    NOC: { prefix: 'CANOC', facility: 'NOC', name: 'Canadian NOC' },
};
```

Auto-detection from session org context is already in place:

```js
function getOrgType() {
    if (window.PERTI_ORG && window.PERTI_ORG.code === 'canoc') {
        return 'NOC';
    }
    return localStorage.getItem(STORAGE_KEY) || DEFAULT_ORG;
}
```

### `advisory-templates.js` — NO CHANGES NEEDED

The existing generators already produce the correct output. They use `AdvisoryConfig.getPrefix()` which now returns `CANOC` when org=canoc. Key functions:

- `generateGDPAdvisory(params)` → produces GDP advisory text (line 371)
- `generateGroundStopAdvisory(params)` → produces GS advisory text (line 275)
- `generateGDPCancelAdvisory(params)` → GDP cancellation (line 443)
- `generateGroundStopCancelAdvisory(params)` → GS cancellation (line 331)

---

## What Remains To Do

### 1. Add Locale Strings — `assets/locales/en-CA.json` + `fr-CA.json`

Add keys under the existing `tmiPublish` section for GDP/GS form labels. The `en-CA.json` file already has a `tmiPublish` section (around line 164) with Canadian terminology overrides.

**New keys to add under `tmiPublish`:**

```json
{
  "tmiPublish": {
    "advisoryTypes": {
      "gdp": "Ground Delay Programme",
      "gdpDesc": "CDM Ground Delay Programme advisory",
      "gs": "Ground Stop",
      "gsDesc": "CDM Ground Stop advisory",
      "gdpCancel": "GDP Cancellation",
      "gdpCancelDesc": "Cancel an active Ground Delay Programme",
      "gsCancel": "GS Cancellation",
      "gsCancelDesc": "Cancel an active Ground Stop"
    },
    "gdpForm": {
      "title": "Ground Delay Programme",
      "airport": "Airport",
      "airportPlaceholder": "e.g. CYYZ",
      "fir": "FIR",
      "adlTime": "ADL Time",
      "delayMode": "Delay Assignment Mode",
      "arrivalsStart": "Arrivals Estimated Start",
      "arrivalsEnd": "Arrivals Estimated End",
      "programStart": "Programme Start",
      "programEnd": "Programme End",
      "programRate": "Programme Rate",
      "programRatePlaceholder": "e.g. 16/16/16/8",
      "flightInclusion": "Flight Inclusion",
      "flightInclusionDefault": "ALL CNDN AND CONTIGUOUS US DEP",
      "depScope": "DEP Scope",
      "depScopePlaceholder": "e.g. 2NDTIER",
      "additionalDepFacilities": "Additional DEP Facilities",
      "exemptDepFacilities": "Exempt DEP Facilities",
      "maxDelay": "Maximum Delay (min)",
      "avgDelay": "Average Delay (min)",
      "impactingCondition": "Impacting Condition",
      "specificImpact": "Specific Impact",
      "comments": "Comments"
    },
    "gsForm": {
      "title": "Ground Stop",
      "airport": "Airport",
      "airportPlaceholder": "e.g. CYYZ",
      "fir": "FIR",
      "adlTime": "ADL Time",
      "gsStart": "Ground Stop Start",
      "gsEnd": "Ground Stop End",
      "depFacilitiesKeyword": "DEP Facilities Keyword",
      "depFacilitiesKeywordPlaceholder": "e.g. 2ndTier",
      "depFacilities": "DEP Facilities Included",
      "depFacilitiesPlaceholder": "Space-separated, e.g. CZYZ CZUL CZEG",
      "previousDelays": "Previous Delays",
      "newDelays": "New Delays",
      "totalDelays": "Total",
      "maxDelays": "Maximum",
      "avgDelays": "Average",
      "probExtension": "Probability of Extension",
      "impactingCondition": "Impacting Condition",
      "specificImpact": "Specific Impact",
      "comments": "Comments"
    },
    "cancelForm": {
      "title": "Cancellation",
      "cnxStart": "CNX Period Start",
      "cnxEnd": "CNX Period End",
      "hasActiveAfp": "Active AFP exists (flights may receive new EDCTs)",
      "comments": "Comments"
    }
  }
}
```

**`fr-CA.json`** — Add French equivalents under the same structure. Key translations:

| English | French |
|---------|--------|
| Ground Delay Programme | Programme de retard au sol |
| Ground Stop | Arrêt au sol |
| Airport | Aéroport |
| FIR | RIF (Région d'information de vol) |
| Delay Assignment Mode | Mode d'attribution des retards |
| Arrivals Estimated | Arrivées estimées |
| Programme Rate | Taux du programme |
| Flight Inclusion | Inclusion des vols |
| DEP Scope | Portée des départs |
| Maximum Delay | Retard maximal |
| Average Delay | Retard moyen |
| Impacting Condition | Condition d'impact |
| Probability of Extension | Probabilité de prolongation |
| Cancellation | Annulation |

---

### 2. Modify TMI Publisher — `assets/js/tmi-publish.js`

This is the main work. The file is ~8900 lines. Here are the specific modifications:

#### a) Org-Based Advisory Type Filtering

**Where**: `initAdvisoryTypeSelector()` at line 334, and `init()` at line 241.

**Current behavior**: Advisory type cards are static HTML in the page (OPS_PLAN, FREE_FORM, HOTLINE, SWAP). The JS just binds click handlers.

**New behavior**: When `window.PERTI_ORG?.code === 'canoc'`:
- Show GDP, GS, GDP_CANCEL, GS_CANCEL type cards (replacing OPS_PLAN, FREE_FORM, HOTLINE, SWAP)
- Default selection: GDP (instead of OPS_PLAN)

When org is NOT canoc: no change (existing US types).

**Implementation approach**: Either:
1. Dynamically generate the advisory type cards in JS based on org (recommended), or
2. Have all cards in the HTML and show/hide based on org

Option 1 is better because the type cards for Canadian vs US are completely different sets. Add a function like:

```js
function getAdvisoryTypesForOrg() {
    if (window.PERTI_ORG && window.PERTI_ORG.code === 'canoc') {
        return [
            { type: 'GDP', icon: 'fa-clock', label: PERTII18n.t('tmiPublish.advisoryTypes.gdp'), desc: PERTII18n.t('tmiPublish.advisoryTypes.gdpDesc') },
            { type: 'GS', icon: 'fa-hand-paper', label: PERTII18n.t('tmiPublish.advisoryTypes.gs'), desc: PERTII18n.t('tmiPublish.advisoryTypes.gsDesc') },
            { type: 'GDP_CANCEL', icon: 'fa-times-circle', label: PERTII18n.t('tmiPublish.advisoryTypes.gdpCancel'), desc: PERTII18n.t('tmiPublish.advisoryTypes.gdpCancelDesc') },
            { type: 'GS_CANCEL', icon: 'fa-ban', label: PERTII18n.t('tmiPublish.advisoryTypes.gsCancel'), desc: PERTII18n.t('tmiPublish.advisoryTypes.gsCancelDesc') },
        ];
    }
    // Default US types (existing behavior)
    return [
        { type: 'OPS_PLAN', icon: 'fa-clipboard-check', label: 'Operations Plan', desc: 'Daily ops plan advisory' },
        { type: 'FREE_FORM', icon: 'fa-file-alt', label: 'Free Form', desc: 'Custom advisory text' },
        { type: 'HOTLINE', icon: 'fa-phone', label: 'Hotline', desc: 'Hotline activation/termination' },
        { type: 'SWAP', icon: 'fa-cloud-sun-rain', label: 'SWAP', desc: 'Severe Weather Avoidance Plan' },
    ];
}
```

Then in `initAdvisoryTypeSelector()`, dynamically render the cards into the container and bind click handlers.

In `init()`, change the default advisory type:
```js
const defaultAdvType = (window.PERTI_ORG?.code === 'canoc') ? 'GDP' : 'OPS_PLAN';
state.selectedAdvisoryType = defaultAdvType;
loadAdvisoryForm(defaultAdvType);
```

#### b) Add GDP Form Builder

**Where**: After `buildSwapForm()` (around line 1755).

**Function**: `buildGdpForm()` — returns HTML string.

**Form fields** (mapped to `AdvisoryTemplates.generateGDPAdvisory()` params):

| Form Field | Input Type | Maps to Param | Default |
|------------|-----------|---------------|---------|
| Advisory # | text (readonly) | `advisoryNumber` | auto from `getNextAdvisoryNumber('GDP')` |
| Airport | text (uppercase) | `airport` | empty, placeholder "CYYZ" |
| FIR | dropdown | `artcc` | Canadian FIRs only (see list below) |
| ADL Time | datetime-local | `adlTime` | current UTC |
| Delay Mode | dropdown | `delayMode` | DAS, GAAP, UDP options |
| Arrivals Start | datetime-local | `arrivalsStart` | current UTC |
| Arrivals End | datetime-local | `arrivalsEnd` | +4 hours |
| Programme Start | datetime-local | `programStart` | current UTC |
| Programme End | datetime-local | `programEnd` | +4 hours |
| Programme Rate | text | `programRates` | placeholder "16/16/16/8" |
| Flight Inclusion | text | `flightInclusion` | "ALL CNDN AND CONTIGUOUS US DEP" |
| DEP Scope | text | `depScope` | placeholder "2NDTIER" |
| Addl DEP Facilities | text | `additionalDepFacilities` | empty |
| Exempt DEP Facilities | text | `exemptDepFacilities` | empty |
| Max Delay | number | `maxDelay` | 0 |
| Avg Delay | number | `avgDelay` | 0 |
| Impacting Condition | dropdown | `impactingCondition` | WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER |
| Specific Impact | text | `impactingText` | empty |
| Comments | textarea | `comments` | empty |

**Canadian FIRs dropdown** (already defined at lines 1731-1738 in tmi-publish.js):
```js
{ code: 'CZEG', name: 'Edmonton FIR' },
{ code: 'CZQM', name: 'Moncton FIR' },
{ code: 'CZQX', name: 'Gander FIR' },
{ code: 'CZVR', name: 'Vancouver FIR' },
{ code: 'CZWG', name: 'Winnipeg FIR' },
{ code: 'CZYZ', name: 'Toronto FIR' },
{ code: 'CZUL', name: 'Montreal FIR' },
```

**Follow existing patterns**: Use the same Bootstrap 4.5 card structure, `.form-control`, `.form-label.small.text-muted`, `.row.mb-3` / `.col-md-*` layout that `buildOpsPlanForm()` uses.

#### c) Add GS Form Builder

**Function**: `buildGsForm()` — returns HTML string.

**Form fields** (mapped to `AdvisoryTemplates.generateGroundStopAdvisory()` params):

| Form Field | Input Type | Maps to Param | Default |
|------------|-----------|---------------|---------|
| Advisory # | text (readonly) | `advisoryNumber` | auto |
| Airport | text (uppercase) | `airport` | empty |
| FIR | dropdown | `artcc` | Canadian FIRs |
| ADL Time | datetime-local | `adlTime` | current UTC |
| GS Start | datetime-local | `gsStart` | current UTC |
| GS End | datetime-local | `gsEnd` | +2 hours |
| DEP Facilities Keyword | text | `depFacilitiesKeyword` | placeholder "2ndTier" |
| DEP Facilities | text | `depFacilitiesIncluded` | placeholder "CZYZ CZUL CZEG" |
| Previous Total | number | `previousDelays.total` | 0 |
| Previous Max | number | `previousDelays.max` | 0 |
| Previous Avg | number | `previousDelays.avg` | 0 |
| New Total | number | `newDelays.total` | 0 |
| New Max | number | `newDelays.max` | 0 |
| New Avg | number | `newDelays.avg` | 0 |
| Prob. of Extension | dropdown | `probExtension` | NONE, LOW, MEDIUM, HIGH |
| Impacting Condition | dropdown | `impactingCondition` | WEATHER, VOLUME, RUNWAY, EQUIPMENT, OTHER |
| Specific Impact | text | `impactingText` | empty |
| Comments | textarea | `comments` | empty |

#### d) Add GDP/GS Cancel Form Builders

**Functions**: `buildGdpCancelForm()`, `buildGsCancelForm()` — simpler forms.

**Fields** (mapped to `generateGDPCancelAdvisory()` / `generateGroundStopCancelAdvisory()`):

| Form Field | Input Type | Maps to Param |
|------------|-----------|---------------|
| Advisory # | text (readonly) | `advisoryNumber` |
| Airport | text (uppercase) | `airport` |
| FIR | dropdown | `artcc` |
| ADL Time | datetime-local | `adlTime` |
| CNX Start | datetime-local | `cnxStart` |
| CNX End | datetime-local | `cnxEnd` |
| Active AFP | checkbox | `hasActiveAFP` |
| Comments | textarea | `comments` |

#### e) Add Preview Builders

**Functions**: `buildGdpPreview()`, `buildGsPreview()`, `buildGdpCancelPreview()`, `buildGsCancelPreview()`

Each collects form values and calls the corresponding `AdvisoryTemplates.generate*()` function.

**Example for GDP**:
```js
function buildGdpPreview() {
    const num = $('#adv_number').val() || '001';
    const airport = $('#adv_airport').val() || '';
    const artcc = $('#adv_fir').val() || '';
    const adlTime = new Date($('#adv_adl_time').val());
    const delayMode = $('#adv_delay_mode').val() || 'DAS';
    const arrivalsStart = new Date($('#adv_arrivals_start').val());
    const arrivalsEnd = new Date($('#adv_arrivals_end').val());
    const programStart = new Date($('#adv_program_start').val());
    const programEnd = new Date($('#adv_program_end').val());
    const programRates = ($('#adv_program_rate').val() || '').split('/').filter(Boolean);
    const flightInclusion = $('#adv_flight_inclusion').val() || 'ALL CNDN AND CONTIGUOUS US DEP';
    const depScope = $('#adv_dep_scope').val() || '';
    const additionalDep = ($('#adv_addl_dep').val() || '').split(/\s+/).filter(Boolean);
    const exemptDep = ($('#adv_exempt_dep').val() || '').split(/\s+/).filter(Boolean);
    const maxDelay = parseInt($('#adv_max_delay').val()) || 0;
    const avgDelay = parseInt($('#adv_avg_delay').val()) || 0;
    const impactingCondition = $('#adv_impacting_condition').val() || 'WEATHER';
    const impactingText = $('#adv_impacting_text').val() || '';
    const comments = $('#adv_comments').val() || '';

    return AdvisoryTemplates.generateGDPAdvisory({
        advisoryNumber: num,
        airport,
        artcc,
        adlTime,
        delayMode,
        arrivalsStart,
        arrivalsEnd,
        programStart,
        programEnd,
        programRates,
        flightInclusion,
        depScope,
        additionalDepFacilities: additionalDep,
        exemptDepFacilities: exemptDep,
        maxDelay,
        avgDelay,
        impactingCondition,
        impactingText,
        comments,
    });
}
```

#### f) Update `loadAdvisoryForm()` Switch Statement

**Where**: Line 1406.

Add cases:
```js
case 'GDP':
    formHtml = buildGdpForm();
    break;
case 'GS':
    formHtml = buildGsForm();
    break;
case 'GDP_CANCEL':
    formHtml = buildGdpCancelForm();
    break;
case 'GS_CANCEL':
    formHtml = buildGsCancelForm();
    break;
```

#### g) Update `updateAdvisoryPreview()` Switch Statement

**Where**: Line 2344.

Add cases:
```js
case 'GDP':
    preview = buildGdpPreview();
    break;
case 'GS':
    preview = buildGsPreview();
    break;
case 'GDP_CANCEL':
    preview = buildGdpCancelPreview();
    break;
case 'GS_CANCEL':
    preview = buildGsCancelPreview();
    break;
```

#### h) Update `collectAdvisoryFormData()`

**Where**: Line 3200.

The current implementation already collects all form fields generically by iterating `#adv_form_container input, textarea, select`. This should work for GDP/GS forms without modification, but verify that the container ID matches (currently `advisoryFormContainer` at line 1428).

#### i) Wire Up Live Preview on Input Changes

In the existing pattern, `initAdvisoryFormHandlers(type)` binds `input`/`change` events to `updateAdvisoryPreview()`. Ensure the GDP/GS form fields use the same event delegation. Look at how `buildHotlineForm()` does this (lines 1848-1889) — it attaches handlers after the form is rendered.

For GDP/GS forms, bind `input` on all text/number/textarea fields and `change` on all select/datetime-local fields to `updateAdvisoryPreview()`.

---

### 3. Verify Publish API — `api/mgt/tmi/publish.php`

**What to check**:

1. `saveAdvisoryToDatabase()` — ensure it accepts `advisory_type` values of `GDP`, `GS`, `GDP_CANCEL`, `GS_CANCEL`
2. The `org_code` should be set from the session org context (`get_org_code()`)
3. The `body_text` arrives pre-formatted from the client (the JS template generators produce the final text)
4. Advisory number assignment via `AdvisoryNumber::reserve()` — verify it works for Canadian advisory types

**Expected**: Minimal or no changes. The publish API is type-agnostic — it saves whatever `advisory_type` and `body_text` the client sends. The advisory text is generated client-side by `AdvisoryTemplates.generate*()`.

---

## Architecture Reference

### How Advisory Generation Works (End-to-End)

1. User selects advisory type card in TMI Publisher
2. `loadAdvisoryForm(type)` renders type-specific form HTML
3. User fills form fields; each change triggers `updateAdvisoryPreview()`
4. Preview builder collects form values and calls `AdvisoryTemplates.generate*(params)`
5. `AdvisoryTemplates` uses `AdvisoryConfig.getPrefix()` for the header (vATCSCC or CANOC)
6. Generated text displayed in preview panel (monospace `<pre>` block)
7. User clicks "Add to Queue" → `collectAdvisoryFormData()` saves form state
8. User clicks "Publish" → POST to `api/mgt/tmi/publish.php`
9. Server saves to `tmi_advisories` table, posts to Discord via webhooks

### Key Files

| File | Role |
|------|------|
| `assets/js/advisory-config.js` | Org prefix switching (DONE) |
| `advisory-templates.js` | Template generators (NO CHANGE) |
| `assets/js/tmi-publish.js` | TMI Publisher controller (~8900 lines) |
| `assets/locales/en-CA.json` | Canadian English locale overrides |
| `assets/locales/fr-CA.json` | Canadian French translations |
| `api/mgt/tmi/publish.php` | Server-side publish endpoint |
| `load/org_context.php` | Session org helpers: `get_org_code()`, `is_org_privileged()` |

### Existing Patterns to Follow

- **Form HTML**: Bootstrap 4.5 cards with `.card-header.bg-primary.text-white`, `.card-body`, `.row.mb-3`, `.col-md-*`, `.form-control`, `.form-label.small.text-muted`
- **Locale strings**: `PERTII18n.t('tmiPublish.keyName')` for all user-facing text
- **Uppercase enforcement**: `.text-uppercase` CSS class on facility/airport inputs
- **Advisory numbers**: `getNextAdvisoryNumber(type)` auto-increments per day
- **Live preview**: Bind `input`/`change` events to `updateAdvisoryPreview()`
- **Config fallbacks**: `(typeof PERTI !== 'undefined' && PERTI.X) ? PERTI.X : hardcodedFallback`

### Canadian FIRs (already in tmi-publish.js at line 1731)

| Code | Name |
|------|------|
| CZEG | Edmonton FIR |
| CZQM | Moncton FIR |
| CZQX | Gander FIR |
| CZVR | Vancouver FIR |
| CZWG | Winnipeg FIR |
| CZYZ | Toronto FIR |
| CZUL | Montreal FIR |

### Org Detection

```js
// Client-side: window.PERTI_ORG is set by PHP in the page template
if (window.PERTI_ORG && window.PERTI_ORG.code === 'canoc') {
    // Canadian mode
}
```

---

## Expected Output Examples

### GDP Advisory (org=canoc)

```
CANOC ADVZY 001 CYYZ/CZYZ 02/18/2026 CDM GROUND DELAY PROGRAM
CTL ELEMENT: CYYZ
ELEMENT TYPE: APT
ADL TIME: 1430Z
DELAY ASSIGNMENT MODE: UDP
ARRIVALS ESTIMATED FOR: 18/1500Z – 18/2359Z
CUMULATIVE PROGRAM PERIOD: 18/1500Z – 18/2359Z
PROGRAM RATE: 16/16/16/8
FLT INCL: ALL CNDN AND CONTIGUOUS US DEP
DEP SCOPE: 2NDTIER
MAXIMUM DELAY: 45
AVERAGE DELAY: 22
IMPACTING CONDITION: WEATHER LOW CEILINGS AND FOG
COMMENTS: CYYZ ILS 05 APPROACH ONLY
181500-182359
26/02/18 14:30
```

### Ground Stop Advisory (org=canoc)

```
CANOC ADVZY 002 CYYZ/CZYZ 02/18/2026 CDM GROUND STOP
CTL ELEMENT: CYYZ
ELEMENT TYPE: APT
ADL TIME: 1430Z
GROUND STOP PERIOD: 18/1500Z – 18/1700Z
DEP FACILITIES INCLUDED: (2ndTier) CZYZ CZUL CZEG
PREVIOUS TOTAL, MAXIMUM, AVERAGE DELAYS: 12 / 45 / 22
NEW TOTAL, MAXIMUM, AVERAGE DELAYS: 8 / 60 / 35
PROBABILITY OF EXTENSION: MEDIUM
IMPACTING CONDITION: WEATHER THUNDERSTORMS
COMMENTS: CYYZ CLOSED DUE TO SEVERE WEATHER
181500-181700
26/02/18 14:30
```

### GDP Advisory (org=vatcscc, unchanged)

```
vATCSCC ADVZY 001 JFK/ZNY 02/18/2026 CDM GROUND DELAY PROGRAM
CTL ELEMENT: JFK
ELEMENT TYPE: APT
...
```

---

## Implementation Order

1. **Locale strings** (`en-CA.json` + `fr-CA.json`) — Add the keys listed above
2. **`tmi-publish.js`** — The main work:
   - Add `getAdvisoryTypesForOrg()` and dynamic type card rendering
   - Add `buildGdpForm()`, `buildGsForm()`, `buildGdpCancelForm()`, `buildGsCancelForm()`
   - Add `buildGdpPreview()`, `buildGsPreview()`, `buildGdpCancelPreview()`, `buildGsCancelPreview()`
   - Update `loadAdvisoryForm()` switch
   - Update `updateAdvisoryPreview()` switch
   - Update `init()` default advisory type for canoc
3. **`api/mgt/tmi/publish.php`** — Verify GDP/GS/GDP_CANCEL/GS_CANCEL types work (likely no changes needed)

---

## Verification Checklist

- [ ] Set org to `canoc` via org switcher
- [ ] TMI Publisher shows GDP, GS, GDP Cancel, GS Cancel type cards (not OPS_PLAN, FREE_FORM, etc.)
- [ ] GDP form renders with all fields
- [ ] GDP preview generates correctly with `CANOC` prefix
- [ ] GS form renders with all fields
- [ ] GS preview generates correctly
- [ ] GDP Cancel and GS Cancel forms work
- [ ] Switch org to `vatcscc` → only US type cards appear (OPS_PLAN, FREE_FORM, HOTLINE, SWAP)
- [ ] Publish a GDP advisory → saves with `advisory_type='GDP'` and `org_code='canoc'`
- [ ] French locale (`fr-CA`) shows translated form labels
- [ ] Live preview updates on every field change
- [ ] Advisory number auto-increments correctly for Canadian types
