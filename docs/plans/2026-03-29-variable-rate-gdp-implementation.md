# Variable Rate GDP — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a two-tier variable rate editor (hourly + per-15-min) for GDP programs in GDT, with synchronized rates and clear unit labels.

**Architecture:** Replace the existing Edit 15 container in `gdt.php` with a unified rate editor that has an hourly row (always visible for GDP) and an expandable quarter-hour row. All rate values are arrivals/hr. `rates_quarter_json` is the authoritative storage; hourly values are derived by averaging 4 quarters. The SP (`sp_TMI_GenerateSlots`, migration 053) already consumes `rates_quarter_json`.

**Tech Stack:** PHP 8.2, vanilla JS, jQuery 2.2.4, Bootstrap 4.5, sqlsrv, i18n via `PERTII18n.t()`

**Design doc:** `docs/plans/2026-03-29-variable-rate-gdp-design.md`

---

## Task 1: Add i18n Keys

**Files:**
- Modify: `assets/locales/en-US.json` (inside `gdt.page` section)

**Step 1: Add rate editor i18n keys**

Find the `gdt.page` section (around line 1690 where `acceptanceRateAar` lives) and add these keys nearby:

```json
"rateEditorHourlyLabel": "Hourly Rate (arr/hr)",
"rateEditorQuarterLabel": "Per-15-Min Rate (arr/hr)",
"rateEditorFillAll": "Fill All",
"rateEditorEdit15": "Edit 15",
"rateEditorClear": "Clear",
"rateEditorFillPlaceholder": "Rate"
```

**Step 2: Commit**

```bash
git add assets/locales/en-US.json
git commit -m "feat(gdt): add i18n keys for variable rate editor"
```

---

## Task 2: Replace Edit 15 HTML with Unified Rate Editor

**Files:**
- Modify: `gdt.php:740-761` (replace `gs_edit15_container` div)

**Step 1: Replace the Edit 15 container HTML**

Replace lines 740-761 (the `gs_edit15_container` div) with the unified rate editor:

```php
                    <!-- Variable Rate Editor (GDP only) -->
                    <div id="gs_rate_editor_container" style="display: none;">
                        <!-- Hourly rate table (always visible when container shown) -->
                        <div class="mb-1" style="overflow-x: auto;">
                            <table class="table table-sm table-bordered mb-0" style="font-size: 0.75rem; table-layout: fixed;">
                                <thead class="thead-light">
                                    <tr id="gs_hourly_header"><th style="min-width:70px; font-size:0.65rem;"><?= __('gdt.page.rateEditorHourlyLabel') ?></th></tr>
                                </thead>
                                <tbody>
                                    <tr id="gs_hourly_inputs"><td class="font-weight-bold small">AAR</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Toolbar: Edit 15 toggle + Fill controls -->
                        <div class="d-flex align-items-center mb-1">
                            <button type="button" class="btn btn-xs btn-outline-info mr-2" id="gs_edit15_toggle" title="Toggle per-15-minute rate editor">
                                <i class="fas fa-clock mr-1"></i><span id="gs_edit15_toggle_label"><?= __('gdt.page.rateEditorEdit15') ?></span>
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-secondary mr-2 d-none" id="gs_edit15_clear" title="Clear per-15-min rates">
                                <i class="fas fa-times mr-1"></i><?= __('gdt.page.rateEditorClear') ?>
                            </button>
                            <div class="d-inline-flex align-items-center">
                                <input type="number" class="form-control form-control-sm mr-1" id="gs_rate_fill_value"
                                       style="width:60px; font-size:0.75rem;" min="1" max="120" placeholder="<?= __('gdt.page.rateEditorFillPlaceholder') ?>">
                                <button type="button" class="btn btn-xs btn-outline-secondary" id="gs_rate_fill_btn">
                                    <?= __('gdt.page.rateEditorFillAll') ?>
                                </button>
                            </div>
                        </div>
                        <!-- Per-15-min rate table (hidden until Edit 15 toggled) -->
                        <div id="gs_quarter_grid" class="mb-2" style="display: none; overflow-x: auto;">
                            <table class="table table-sm table-bordered mb-0" style="font-size: 0.75rem; table-layout: fixed;">
                                <thead class="thead-light">
                                    <tr id="gs_quarter_header"><th style="min-width:70px; font-size:0.65rem;"><?= __('gdt.page.rateEditorQuarterLabel') ?></th></tr>
                                </thead>
                                <tbody>
                                    <tr id="gs_quarter_inputs"><td class="font-weight-bold small">Rate</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
```

**Step 2: Commit**

```bash
git add gdt.php
git commit -m "feat(gdt): replace Edit 15 HTML with unified rate editor layout"
```

---

## Task 3: Implement Rate Editor JS — Core Functions

**Files:**
- Modify: `assets/js/gdt.js:6059-6169` (replace existing Edit 15 functions)

**Step 1: Replace the Edit 15 section (lines 6059-6169) with the unified rate editor**

Replace from `// Edit 15: Per-15-Minute Rate Editor` through the end of `loadEdit15FromProgram()` (line 6169) with:

```javascript
    // =========================================================================
    // Unified Variable Rate Editor
    // =========================================================================

    var rateEditor = {
        hourlyRates: {},    // { "14": 30, "15": 25, ... } keyed by hour string
        quarterRates: {},   // { "14:00": 30, "14:15": 30, ... } keyed by HH:MM
        edit15Visible: false,
        startHour: 0,
        endHour: 0,
        hours: []           // ordered list of hour keys within the program window
    };

    /**
     * Build both hourly and quarter rate grids from program start/end times.
     * Called when GDP type is selected or times change.
     */
    function buildRateEditor() {
        var startEl = document.getElementById('gs_start');
        var endEl = document.getElementById('gs_end');
        if (!startEl || !endEl || !startEl.value || !endEl.value) return;

        var start = new Date(startEl.value + 'Z');
        var end = new Date(endEl.value + 'Z');
        if (isNaN(start.getTime()) || isNaN(end.getTime()) || start >= end) return;

        var defaultRate = parseInt(document.getElementById('gs_program_rate').value) || 30;

        // Determine hour range
        rateEditor.hours = [];
        var t = new Date(start.getTime());
        t.setUTCMinutes(0, 0, 0); // align to hour boundary
        while (t < end) {
            var hh = String(t.getUTCHours()).padStart(2, '0');
            rateEditor.hours.push(hh);
            t.setTime(t.getTime() + 3600000);
        }

        // Build hourly grid
        var hourlyHeader = document.getElementById('gs_hourly_header');
        var hourlyInputs = document.getElementById('gs_hourly_inputs');
        if (!hourlyHeader || !hourlyInputs) return;

        // Keep the first <th>/<td> label cell, clear the rest
        while (hourlyHeader.children.length > 1) hourlyHeader.removeChild(hourlyHeader.lastChild);
        while (hourlyInputs.children.length > 1) hourlyInputs.removeChild(hourlyInputs.lastChild);

        rateEditor.hours.forEach(function(hh) {
            // Header cell
            var th = document.createElement('th');
            th.textContent = hh + 'Z';
            th.style.textAlign = 'center';
            th.style.minWidth = '50px';
            hourlyHeader.appendChild(th);

            // Input cell
            var td = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm p-1';
            input.style.cssText = 'width:50px; text-align:center; font-size:0.75rem;';
            input.min = '1';
            input.max = '120';
            input.dataset.hour = hh;

            // Seed from existing data or default
            var existingHourly = rateEditor.hourlyRates[hh];
            input.value = existingHourly != null ? existingHourly : defaultRate;
            if (existingHourly == null) rateEditor.hourlyRates[hh] = defaultRate;

            input.addEventListener('change', function() {
                var h = this.dataset.hour;
                var val = parseInt(this.value) || defaultRate;
                rateEditor.hourlyRates[h] = val;
                syncQuartersFromHourly(h, val);
            });
            td.appendChild(input);
            hourlyInputs.appendChild(td);
        });

        // Build quarter grid
        buildQuarterGrid(start, end, defaultRate);

        // Populate fill value
        var fillEl = document.getElementById('gs_rate_fill_value');
        if (fillEl && !fillEl.value) fillEl.value = defaultRate;
    }

    /**
     * Build the per-15-min quarter grid.
     */
    function buildQuarterGrid(start, end, defaultRate) {
        var quarterHeader = document.getElementById('gs_quarter_header');
        var quarterInputs = document.getElementById('gs_quarter_inputs');
        if (!quarterHeader || !quarterInputs) return;

        while (quarterHeader.children.length > 1) quarterHeader.removeChild(quarterHeader.lastChild);
        while (quarterInputs.children.length > 1) quarterInputs.removeChild(quarterInputs.lastChild);

        // Align to quarter boundary
        var t = new Date(start.getTime());
        var mins = t.getUTCMinutes();
        t.setUTCMinutes(Math.floor(mins / 15) * 15, 0, 0);

        var colCount = 0;
        while (t < end && colCount < 96) {
            var hh = String(t.getUTCHours()).padStart(2, '0');
            var mm = String(t.getUTCMinutes()).padStart(2, '0');
            var key = hh + ':' + mm;

            var th = document.createElement('th');
            th.textContent = key;
            th.style.textAlign = 'center';
            th.style.minWidth = '50px';
            quarterHeader.appendChild(th);

            var td = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'number';
            input.className = 'form-control form-control-sm p-1';
            input.style.cssText = 'width:50px; text-align:center; font-size:0.75rem;';
            input.min = '1';
            input.max = '120';
            input.dataset.quarterKey = key;
            input.dataset.parentHour = hh;

            // Seed from existing quarter data, then hourly, then default
            var existing = rateEditor.quarterRates[key];
            if (existing != null) {
                input.value = existing;
            } else {
                var hourlyVal = rateEditor.hourlyRates[hh];
                var seedVal = hourlyVal != null ? hourlyVal : defaultRate;
                input.value = seedVal;
                rateEditor.quarterRates[key] = seedVal;
            }

            input.addEventListener('change', function() {
                var k = this.dataset.quarterKey;
                var h = this.dataset.parentHour;
                var v = parseInt(this.value);
                if (v > 0) {
                    rateEditor.quarterRates[k] = v;
                    syncHourlyFromQuarters(h);
                }
            });
            td.appendChild(input);
            quarterInputs.appendChild(td);

            t.setUTCMinutes(t.getUTCMinutes() + 15);
            colCount++;
        }
    }

    /**
     * When a quarter cell changes, update its parent hourly cell to the
     * average of its 4 quarter values.
     */
    function syncHourlyFromQuarters(hour) {
        var quarters = [':00', ':15', ':30', ':45'];
        var sum = 0, count = 0;
        quarters.forEach(function(suffix) {
            var key = hour + suffix;
            var val = rateEditor.quarterRates[key];
            if (val != null) { sum += val; count++; }
        });
        if (count === 0) return;
        var avg = Math.round(sum / count);
        rateEditor.hourlyRates[hour] = avg;

        // Update the hourly input DOM
        var hourlyInput = document.querySelector('#gs_hourly_inputs input[data-hour="' + hour + '"]');
        if (hourlyInput) hourlyInput.value = avg;
    }

    /**
     * When an hourly cell changes, set all 4 of its quarter cells to the
     * new hourly value.
     */
    function syncQuartersFromHourly(hour, value) {
        var quarters = [':00', ':15', ':30', ':45'];
        quarters.forEach(function(suffix) {
            var key = hour + suffix;
            rateEditor.quarterRates[key] = value;

            // Update quarter input DOM if visible
            var qInput = document.querySelector('#gs_quarter_inputs input[data-quarter-key="' + key + '"]');
            if (qInput) qInput.value = value;
        });
    }

    /**
     * Toggle the Edit 15 quarter grid visibility.
     */
    function toggleEdit15() {
        rateEditor.edit15Visible = !rateEditor.edit15Visible;
        var grid = document.getElementById('gs_quarter_grid');
        var clearBtn = document.getElementById('gs_edit15_clear');
        if (grid) grid.style.display = rateEditor.edit15Visible ? '' : 'none';
        if (clearBtn) {
            if (rateEditor.edit15Visible) clearBtn.classList.remove('d-none');
            else clearBtn.classList.add('d-none');
        }
    }

    /**
     * Clear Edit 15 quarter rates — reverts all quarters to their parent
     * hourly value and hides the grid.
     */
    function clearEdit15() {
        rateEditor.edit15Visible = false;
        var grid = document.getElementById('gs_quarter_grid');
        var clearBtn = document.getElementById('gs_edit15_clear');
        if (grid) grid.style.display = 'none';
        if (clearBtn) clearBtn.classList.add('d-none');

        // Revert each quarter to its parent hourly value
        rateEditor.hours.forEach(function(hh) {
            var val = rateEditor.hourlyRates[hh];
            if (val != null) syncQuartersFromHourly(hh, val);
        });
    }

    /**
     * Fill all hourly and quarter cells with a single value.
     */
    function fillAllRates(value) {
        if (!value || value <= 0) return;
        rateEditor.hours.forEach(function(hh) {
            rateEditor.hourlyRates[hh] = value;
            syncQuartersFromHourly(hh, value);
            var hourlyInput = document.querySelector('#gs_hourly_inputs input[data-hour="' + hh + '"]');
            if (hourlyInput) hourlyInput.value = value;
        });
    }

    /**
     * Collect rates for API payload. Returns the rates_quarter_json object
     * only if rates vary (not all identical to program_rate). Returns null
     * if uniform.
     */
    function collectRatesJson() {
        var keys = Object.keys(rateEditor.quarterRates);
        if (keys.length === 0) return null;

        // Check if all values are the same as flat rate — skip sending if uniform
        var flatRate = parseInt(document.getElementById('gs_program_rate').value) || 30;
        var allSame = keys.every(function(k) { return rateEditor.quarterRates[k] === flatRate; });
        if (allSame) return null;

        return rateEditor.quarterRates;
    }

    /**
     * Load rate editor state from an existing program record.
     */
    function loadRatesFromProgram(program) {
        // Reset state
        rateEditor.hourlyRates = {};
        rateEditor.quarterRates = {};
        rateEditor.edit15Visible = false;

        if (program && program.rates_quarter_json) {
            var parsed = typeof program.rates_quarter_json === 'string'
                ? JSON.parse(program.rates_quarter_json)
                : program.rates_quarter_json;
            if (parsed && typeof parsed === 'object') {
                rateEditor.quarterRates = parsed;

                // Derive hourly rates from quarter averages
                var hourBuckets = {};
                Object.keys(parsed).forEach(function(key) {
                    var hh = key.substring(0, 2);
                    if (!hourBuckets[hh]) hourBuckets[hh] = [];
                    hourBuckets[hh].push(parsed[key]);
                });
                Object.keys(hourBuckets).forEach(function(hh) {
                    var vals = hourBuckets[hh];
                    rateEditor.hourlyRates[hh] = Math.round(vals.reduce(function(a, b) { return a + b; }, 0) / vals.length);
                });

                // Check if quarters vary — if so, show Edit 15
                var vals = Object.values(parsed);
                var allSame = vals.every(function(v) { return v === vals[0]; });
                if (!allSame) {
                    rateEditor.edit15Visible = true;
                }
            }
        }

        buildRateEditor();

        // Show Edit 15 grid if rates varied
        if (rateEditor.edit15Visible) {
            var grid = document.getElementById('gs_quarter_grid');
            var clearBtn = document.getElementById('gs_edit15_clear');
            if (grid) grid.style.display = '';
            if (clearBtn) clearBtn.classList.remove('d-none');
        }
    }
```

**Step 2: Commit**

```bash
git add assets/js/gdt.js
git commit -m "feat(gdt): implement unified rate editor core functions"
```

---

## Task 4: Wire Up Event Listeners and Update References

**Files:**
- Modify: `assets/js/gdt.js` — multiple locations

**Step 1: Update program type change handler (line ~7649-7651)**

Replace the Edit 15 container toggle:

```javascript
                // Show/hide Edit 15 container (GDP only)
                var edit15Container = document.getElementById('gs_edit15_container');
                if (edit15Container) edit15Container.style.display = isGDP ? '' : 'none';
```

With:

```javascript
                // Show/hide rate editor container (GDP only)
                var rateEditorContainer = document.getElementById('gs_rate_editor_container');
                if (rateEditorContainer) {
                    rateEditorContainer.style.display = isGDP ? '' : 'none';
                    if (isGDP) buildRateEditor();
                }
```

**Step 2: Add flat rate sync + fill button + Edit 15 toggle listeners**

In the `DOMContentLoaded` init section (near line 7640 where `programTypeSelect` listener lives), add after the existing listeners:

```javascript
        // Flat rate → sync to hourly/quarter grids
        var flatRateEl = document.getElementById('gs_program_rate');
        if (flatRateEl) {
            flatRateEl.addEventListener('change', function() {
                var val = parseInt(this.value);
                if (val > 0) fillAllRates(val);
            });
        }

        // Fill All button
        var fillBtn = document.getElementById('gs_rate_fill_btn');
        if (fillBtn) {
            fillBtn.addEventListener('click', function() {
                var fillEl = document.getElementById('gs_rate_fill_value');
                var val = parseInt(fillEl ? fillEl.value : 0);
                if (val > 0) fillAllRates(val);
            });
        }

        // Edit 15 toggle button
        var edit15ToggleBtn = document.getElementById('gs_edit15_toggle');
        if (edit15ToggleBtn) {
            edit15ToggleBtn.addEventListener('click', function() {
                toggleEdit15();
            });
        }

        // Edit 15 clear button
        var edit15ClearBtn = document.getElementById('gs_edit15_clear');
        if (edit15ClearBtn) {
            edit15ClearBtn.addEventListener('click', function() {
                clearEdit15();
            });
        }

        // Rebuild rate editor when times change
        var gsStartEl = document.getElementById('gs_start');
        var gsEndEl = document.getElementById('gs_end');
        if (gsStartEl) gsStartEl.addEventListener('change', function() {
            var typeEl = document.getElementById('gs_program_type');
            if (typeEl && typeEl.value !== 'GS') buildRateEditor();
        });
        if (gsEndEl) gsEndEl.addEventListener('change', function() {
            var typeEl = document.getElementById('gs_program_type');
            if (typeEl && typeEl.value !== 'GS') buildRateEditor();
        });
```

**Step 3: Update `loadEdit15FromProgram` call (line ~435)**

Change:

```javascript
            loadEdit15FromProgram(p);
```

To:

```javascript
            loadRatesFromProgram(p);
```

**Step 4: Update `clearEdit15` call (line ~437)**

The `clearEdit15()` call on the else branch (GS type) is fine — the function still exists and clears quarter state.

**Step 5: Update create payload (lines ~5715-5717)**

Replace:

```javascript
            // Edit 15: per-15-minute rates
            var quarterRates = getEdit15Json();
            if (quarterRates) createPayload.rates_quarter_json = quarterRates;
```

With:

```javascript
            // Variable rates: per-15-minute rates from rate editor
            var quarterRates = collectRatesJson();
            if (quarterRates) createPayload.rates_quarter_json = quarterRates;
```

**Step 6: Commit**

```bash
git add assets/js/gdt.js
git commit -m "feat(gdt): wire rate editor events, sync, and payload integration"
```

---

## Task 5: Remove Inline onclick Attributes from HTML

**Files:**
- Modify: `gdt.php` (the new HTML from Task 2)

**Step 1: Remove onclick attributes from the Edit 15 toggle and clear buttons**

The HTML in Task 2 already uses `id`-based buttons without `onclick` — the listeners are wired in Task 4 via `addEventListener`. Verify the HTML has no `onclick` attributes. If any remain from copy-paste, remove them.

This task is a verification step only — if Task 2 HTML is correct, no changes needed.

**Step 2: Commit (if changes made)**

```bash
git add gdt.php
git commit -m "fix(gdt): remove inline onclick handlers from rate editor buttons"
```

---

## Task 6: Test End-to-End and Final Commit

**Step 1: Verify the full flow manually**

Test on `https://perti.vatcscc.org/gdt.php`:

1. Select GDP-DAS program type → rate editor container appears with hourly grid
2. Set times (e.g., 14:00-18:00Z) → hourly grid shows 14Z-17Z columns
3. Change flat rate to 25 → all hourly cells update to 25
4. Edit hourly 15Z to 30 → only 15Z cell changes
5. Click Edit 15 → quarter grid appears with correct values
6. Edit 15:15 to 20 → 15Z hourly updates to avg(30,20,30,30) = 28
7. Click Clear → quarters revert to hourly values, grid hides
8. Fill All: enter 35, click Apply → all hourly and quarter cells = 35
9. Click Preview → program creates successfully, API shows `rates_quarter_json`
10. Switch to GS type → rate editor hides
11. Load an existing GDP program from dashboard → rates populate correctly

**Step 2: Squash and push**

```bash
git add -A
git commit -m "feat(gdt): unified variable rate editor with hourly + Edit 15 tiers (#260)"
git push origin main
```

Deploy is automatic via GitHub Actions.
