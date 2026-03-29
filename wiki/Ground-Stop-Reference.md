# Ground Stop (GS) — TFMS FSM Reference

> **Source**: TFMS Flight Schedule Monitor User's Guide, Version 13.0 (February 26, 2016), Chapters 19-20, with supporting material from Chapters 2, 3, 6, 12, and Appendices A-B.
>
> This document captures the complete Ground Stop specification from the FAA's FSM system for use as a reference when implementing GS functionality in PERTI.

---

## 1. What Is a Ground Stop?

A Ground Stop is fundamentally different from a GDP:

> "Unlike GDPs, which delay flights because of a reduced AAR, the Ground Stop function prevents flights from departure until one minute after the End time of the Ground Stop." — Ch. 19, p. 19-1

| Aspect | Ground Stop | GDP |
|--------|------------|-----|
| **Mechanism** | Prevents departure entirely | Assigns delayed departure slots |
| **Inclusion check** | ETD vs. Start/End time | ETA vs. IGTA (Initial Gate Time of Arrival) |
| **Time assignment** | All included flights get same new ETD | Each flight gets unique EDCT/CTA |
| **Rate** | No program rate (all departures held) | Program rate controls flow |
| **Scope type** | Always Tier-based | Tier or Distance-based |
| **Applicability** | Airport data sets only | Airport and Airspace (FEA/FCA) |

> "GS programs are not applicable to Airspace data sets (FEAs and FCAs); therefore, they are not available for use for Airspace data sets." — Ch. 19, Note

---

## 2. Core Algorithm

### Flight Inclusion

During a GS, **ETD is checked against the Start time and End time** to determine inclusion:

> "During a GS, ETD is checked against the Start time and End time to determine whether to include the flight in the GS. (In a GDP, times are checked against a flight's IGTA)." — Ch. 19, p. 19-1

A flight is **included** if its ETD falls between the GS Start and GS End times.

### Time Reassignment

Included flights receive:

- **New ETD** = GS End Time + 1 minute
- **New ETA** = New ETD + Original ETE (Estimated Time Enroute)

> "Flights included in the GS program are assigned a new ETD one minute after the GS End time. The flight's new ETAs are assigned based on the flight's Original ETE. For example, if the GS period is 1320-1419, flights with an ETD between 1320 and 1419 have a new ETD of 1420. The new ETA is the New ETD + Orig. ETE." — Ch. 19, p. 19-1

### CTOP Interaction

> "If a flight is already controlled by a CTOP, FSM will shift control to the Ground Stop." — Ch. 19, p. 19-1

---

## 3. Parameters

### 3.1 Program Time Options

| Parameter | Description | GS-Specific Behavior |
|-----------|-------------|---------------------|
| **Start** | When the GS begins | Standard time entry |
| **End** | When the GS ends | Snaps to :00, :15, :30, :45 (not :14/:29/:44/:59 like GDP) |
| **Minimum Duration** | At least 1 hour | If Start=1902, default End=2015 |

> "The default End time for Ground Stops will adjust to 15-minute increments on the hour (00, 15, 30, and 45); conversely, all other programs have a default end time on the 14, 29, 44, or 59th minutes. The End time for Ground Stops will be a time increment that results in at least a one hour GS." — Ch. 19, p. 19-1

The slider also follows this convention:
> "If you are using the slider to select the End time for a Ground Stop, the slider will adjust to 15 minute increments on the hour." — Ch. 3, p. 3-65

### 3.2 Include Only Options

| Filter | Default | Description |
|--------|---------|-------------|
| **Arrival Fix** | ALL | Filter by specific arrival fix(es) |
| **Aircraft Type** | ALL | Filter by aircraft type |
| **Carrier (Major)** | ALL | Filter by carrier |

> "If you do not indicate changes for Arrival Fix, Aircraft Type, or Carrier (Major), the default is set to include All." — Ch. 19, p. 19-1

### 3.3 Program Rate

The Program Rate section is **disabled** for Ground Stops:

> "The Program Rate section is active for all Program Types except Compression, Ground Stop, Blanket, and Purge." — Ch. 3, p. 3-66

### 3.4 Purge Notification Times

| Category | Default (minutes) | Purpose |
|----------|--------------------|---------|
| Taxied | 20 | Notification time for flights in taxi status |
| GS | 20 | Notification time for GS-controlled flights |
| GDP/AFP | 45 | Notification time for GDP/AFP-controlled flights |

These values are visible on the Parameters tab and on the Purge (CNX) coversheet. Changing them in the GDT Setup is **for modeling only**:

> "Changing Purge Notification (Minutes) values is for modeling only. The actual purge uses the default values." — Ch. 20, p. 20-2

---

## 4. Scope

### 4.1 Tier-Based Only

Ground Stops are **always Tier-based** — distance-based scope is not available:

> "Ground stops are always Tier-based; therefore, Select By Distance is not a valid exemption criterion." — Ch. 19, p. 19-2

The Tier panel contains three sections: **Centers**, **Airports-Origin**, and **Flights**.

### 4.2 Centers

- **Tier selection**: Tier 1, Tier 2, or Tier 3 (broader scope)
- **Manual Center Selection**: Checkbox to select/deselect individual centers

### 4.3 Airports - Origin

| Field | Limit | Description |
|-------|-------|-------------|
| **Exempt** | Max 24 airports | Departure airports excluded from the GS |
| **Non-Exempt** | Max 16 airports | Airports explicitly included beyond tier scope |

> "You can exempt certain departure airports from a TMI. Enter the three or four-letter airport code to exempt that airport. Separate multiple airports with a space or a comma. The maximum number of exempt airports you can enter is 24." — Ch. 3, p. 3-75

> "The maximum number of non-exempt airports you can enter is 16." — Ch. 3, p. 3-75

### 4.4 Flights

Three exemption options:

| Option | Default When | Description |
|--------|-------------|-------------|
| **Exempt Active Flights Only (By Status)** | GS in place | Only active flights exempted; all non-active flights are non-exempt |
| **Exempt All Flights Departing Within XX Minutes** | No GS in place | Flights with ETDs within XX min of Data Time are exempt |
| **Exempt Individual Flights** | Never default | Enter specific ACID(s) to exempt |

> "This option is selected by default when there is a Ground Stop in place." — Ch. 3, p. 3-75 (referring to Exempt Active Flights Only)

---

## 5. Modeling Options (Power Run)

Three GS-specific Power Run analyses (no other program types share these):

### 5.1 GS Center Group

> "This Power run allows you to see different statistics for all center groups. The Data Graph's X-axis and the Data Table's column header display the various center groups." — Ch. 3, p. 3-81

### 5.2 GS Time Period

> "This function shows you the effect of running a ground stop for various lengths of time. When you select GS Time Period, two text fields become active: Number of Start Times and Number of End Times. The default for both is set to 2, but you can manually enter in another value." — Ch. 3, p. 3-81

Warning for long durations:
> "If you decide to run a GS for longer than one hour, FSM provides a warning message to ensure you want the ground stop to last for that duration." — Ch. 3, p. 3-81

### 5.3 GS Center Group & Time Period

> "This Power Run allows you to see which combination of center groups and time periods put in the GS parameters would produce the best program. The Data Graph's X-axis and the Data Table's column headers show the various Time Periods and Center Group combinations." — Ch. 3, p. 3-81

### Comparison Table

| Program Type | Power Run Options |
|-------------|-------------------|
| **Ground Stop** | GS Center Group, GS Time Period, GS Center Group & Time Period |
| GDP-DAS | GDP Distance, GDP Data Time, GDP Data Time & Distance |
| GDP-GAAP | GDP Distance, GDP Data Time, GDP Data Time & Distance |
| AFP-DAS | AFP Percent Demand, AFP Percent Capacity, AFP Data Time |
| Compress Flights | Start Time |
| Blanket | Minutes of Adjustment |

---

## 6. GS Lifecycle

### 6.1 Issuing an Initial GS

Complete step-by-step procedure (Ch. 19, pp. 19-10 to 19-11):

1. Click **Open Data Set** → select the airport → Click **OK**
2. Time Line and Bar Graph components open for the airport
3. Click **GDT Mode** on the Control Panel → GDT components open
4. Select **Ground Stop** for the Program Type
5. Enter GS parameters on the GDT Setup Panel tabs (Parameters, Scope, Modeling Options)
6. Click **Model** — red border disappears, all GDT components reflect the modeled GS
7. If satisfactory, click **Run Actual** to generate the coversheet
8. Review each section of the coversheet; select the **Program Parameters** checkbox
9. Select the **Category** for the Ground Stop
10. Select the **Cause** related to the Category
11. Select the **Equipment** causing the GS (if Equipment was selected for Cause)
12. Select the **Probability of Extension** for the Ground Stop
13. Enter any **Comments** as needed
14. Select the **Advisory/Causal Factors** checkbox (red X changes to green checkmark)
15. From the Advisory/Causal Factors dropdown, click **Preview Advisory** to review
16. Click **Send Actual GS** to issue the Ground Stop
17. **Program Manager** window opens and activates the Autosend process

### 6.2 Proposed vs. Actual

- **Send Proposed GS**: Only the proposed parameters and Advisory are sent
- **Send Actual GS**: FADT files sent to TPC, distributed via ADL; emails sent; Advisory published

Critical ADL distribution difference:
> "If the parameters are for a Proposed Advisory, the Hub site sends out the parameters immediately in the next ADL. When you send parameters for an Actual Advisory, the Hub site ensures that it has received the associated FADT file with flight control times for the program before sending any parameters through the ADL." — Ch. 3

Both trigger separate alert types: "Alerts > Actual GS Parameters" and "Alerts > Proposed GS Parameters"

The **Respond By** field is only available during a proposed event.

### 6.3 Autosend Process

> "The FSM client interfaces with TFMS Autosend Server and sends the web coversheet XML file to NTML and the FADT file to the Hub site. The Autosend Server also invokes TFMS E-mail and sends the Advisory to the specified address list. TFMS E-mail puts the ATCSCC position number and phone number in the Advisory signature line." — Ch. 19, p. 19-9

### 6.4 GDT Setup Panel State Machine

The GDT Setup Panel uses a **red border** visual indicator to track state:

```
[Parameters Modified] --> RED BORDER
    |
    v (click Model)
[Model Applied] --> NO BORDER (all GDT components reflect modeled data)
    |
    v (click Run Proposed / Run Actual)
[Coversheet Generated] --> params saved to config file; FADT/Analysis/Carrier Stats generated
    |
    v (click Send Actual GS / Send Proposed GS)
[Autosend] --> Program Manager opens; sequential actions execute
```

> "Click Model. The red border outlining the GDT Setup Panel no longer is displayed and all the GDT components reflect the modeled Ground Stop." — Ch. 19

Any further modification after Model reintroduces the red border. Additional transitions:
- **Reload**: Loads the latest ADL data for re-modeling
- **Reset Parameters**: Clears modeled data, returns settings to defaults

### 6.5 Communication Architecture

```
CDM AOCs (Airlines)
    |
    v (flight schedules, SCS requests)
TPC / Hub Site
    |
    v (ADL broadcast, ~5 min cycle)
FSM Clients (ATCSCC + 80+ FAA facilities + AOCs)
    |
    v (Send Actual/Proposed GS)
TFMS Autosend Server
    |--- Coversheet XML --> NTML
    |--- FADT file -------> Hub Site --> (gating for Actual) --> ADL --> All Users
    |--- TFMS E-mail -----> Advisory address list
```

For **Proposed**: Hub site sends parameters immediately in the next ADL (no FADT dependency).
For **Actual**: Hub site waits until the FADT file (with flight control times) is received before distributing via ADL.

### 6.6 Stale Data Warning

> "If you click Send Proposed GS or Send Actual GS more than 15 minutes after modeling your program, it is recommended that you close your coversheet and Reload to get the most recent data from an updated ADL before continuing with the Ground Delay Operation." — Ch. 19, p. 19-10

---

## 7. Coversheet

### 7.1 Visual Appearance

| Program Type | Border Color |
|-------------|-------------|
| **Ground Stop** | **Yellow** |
| GDP | Standard (no special border) |
| Purge/CNX | **Red** |
| Compress Slots | Standard |
| Blanket | Standard |

The coversheet heading format: `[Airport] / GS / [Actual or Proposed]`
Example: `LAX / GS / ACTUAL`

### 7.2 Summary Section

Contents:
- **Start Time** and **End Time**
- **Model Time**
- **Exempt Active Flights Only (By Status)** or other flight exemption setting
- **Scope Selected By Tier** of [tier level]
- **Centers - Origin (Non-Exempt)**: List of included centers with checkboxes

### 7.3 Advisory/Causal Factors Section

| Field | Description | GS-Specific |
|-------|-------------|-------------|
| **Charge To** | Facility Type + ID | No |
| **Not Charged To FAA** | Checkbox | No |
| **Impacting Condition: Category** | Dropdown | No |
| **Cause** | Dropdown (related to Category) | No |
| **Equipment** | FAA / Non-FAA radio buttons | No |
| **Scheduled / Non-Scheduled** | Radio buttons | No |
| **Respond By** | Time (proposed only) | No |
| **Valid Until** | Auto-calculated | No |
| **Probability of Extension** | Low / Medium / High dropdown | **Yes — GS only** |
| **Comments** | Free text | No |

> "Probability of Extension - Likelihood a Ground Stop will be extended past its current end time (Ground Stop Advisory only). The default is Medium." — Ch. 3, p. 3-106

### 7.4 Generated Reports

Clicking Run Proposed/Actual generates three reports:
1. **FADT Report** (Flight Aggregate Delay Time)
2. **Analysis Report**
3. **Carrier Statistics Report**

Accessible via: `View > FADT`, `View > Analysis`, `View > Carrier Statistics` from the coversheet menu.

### 7.5 Program Results Overlay

Available via `Program Parameters > View Program Results`:

| Metric Category | Fields |
|----------------|--------|
| **Flight Metrics** | Total Flights, Total Affected Flights, Flights in Stack |
| **Delay Metrics** | Min/Avg/Max Delay Before/After/Difference, Total Delay Before/After/Difference |

---

## 8. Monitoring GS Flights

### 8.1 Bar Graph

- **Yellow** bars = GS-controlled flights
- Hover over yellow bar to see count of GS flights in that hour
- Double-click a bar to open Flight List
- To view only GS flights: open color legend → uncheck all except yellow → click bar
- **GS Time Indicators**: Yellow vertical lines mark Start and End times of active GS
  > "View > GS Indicators - Displays yellow vertical lines to indicate the start time and end time of a current GS. The GS time indicators are displayed automatically when a GS goes into effect." (GDP/AFP indicators are brown.)

### 8.2 Flight List

Columns displayed for GS flights: ACID, ETD, ETA, DCENTR (departure center), ORIG, AFIX (arrival fix), DFIX (departure fix), DEST

The filter reads: `NOT(Arrived) AND NOT(Flight Active) AND Ground Stopped AND ETA between [start] and [end]`

### 8.3 Query Manager

> "The Query Manager already contains a built-in filter for GS flights. To get a list of GS flights at any open airport, select the AND Ground_Stopped Filter and then click Flight List." — Ch. 19, p. 19-13

The Query Manager Flight List shows **all** Ground Stopped flights at the airport, unlike the Bar Graph Flight List which only shows flights for the selected hour.

### 8.4 GS Parameters Alert

> "Alerts > GS Parameters Active is highlighted in red when the FAA issues a Ground Stop and FSM receives its parameters through the ADL. First-time GS Parameters, new GS Parameters, and deleted GS Parameters all trigger this Alert." — Ch. 12, p. 12-14

---

## 9. Flight Status & Flags

### 9.1 Flight Status

| Status | Definition |
|--------|-----------|
| **Ground Stopped** | "Delayed because of inclusion in a Ground Stop Program" — Appendix A |

### 9.2 Delay Flag

| Flag | Meaning |
|------|---------|
| **GSD** | "Delayed by Ground Stop" — Appendix B |

### 9.3 Exemption/Exclusion Status

Displayed in the Flight Information window during GDT mode:

| Status | Definition |
|--------|-----------|
| **Excluded by Departure Time (GS Only)** | "The flight is cancelled or the ETD is before the start time or after the end time" |
| **Exempted by Departing Center** | Program did not include this flight's center |
| **Exempted by Distance** | Departure airport outside the distance parameter |
| **Exempted by Departing Airport** | Airport specifically exempted |
| **Exempted by Specific Flight** | Flight specifically excluded |
| **Exempted by Departure Status** | ETD prefix is A or E |
| **Exempted by Departure Time** | Revised EDCT comes before Current Time + Now_Plus |
| **Excluded by Aircraft Type** | Program did not include aircraft of this type |
| **Excluded by Arrival Fix** | Program did not include this arrival fix |
| **Excluded by Arrival Time** | Flight's ETA did not fall within program time limits |
| **Not Exempted** | Flight is eligible for inclusion |

Note: "Excluded by Departure Time (GS Only)" is the only exclusion status unique to Ground Stops.

### 9.4 Bar Graph Color Coding

From the Program Delay color tab (Table 3-4):

| Color | Description |
|-------|-------------|
| White | Non-controlled |
| Orange | Controlled Other Element |
| Green | 0-29 minutes delay |
| Blue | 30-59 minutes |
| Cyan | 60-89 minutes |
| Gray | 90-119 minutes |
| Pink | 120-149 minutes |
| Yellow | 150-179 minutes |

**Note**: In the Status color tab, **Yellow = GS Flights** specifically. The color meaning depends on which tab is active.

---

## 10. GS Lifecycle Operations (Chapter 20)

### 10.1 Purging a GS

**No user input required** — select Purge from Program Type, Model, then Run.

ETD recalculation on purge follows this priority (Ch. 20, p. 20-1):

**Notification time selection**: Taxi status overrides all other control types:
> "If a controlled flight is in a taxi status, without regard to the type of control, then FSM sets the minimum notification time to the value of the minimum notification time for flights in a taxi status."

1. If flight is **active or completed** → ETD = actual departure time
2. If **CTD is within minimum notification time** from current time → ETD remains set to CTD
3. If **CTD > current time + minimum notification** → calculate candidate ETD from:
   1. Earliest Runway Departure Time
   2. Earliest Runway Arrival Time minus Estimated Time Enroute
   3. Original Estimated Departure Time
   4. Initial Gate Departure Time plus taxi time
4. If candidate > current time + min notification → ETD = candidate
5. If candidate < current time + min notification → ETD = current time + min notification

Purge coversheet:
- **Red border**
- Header: `[Airport] / PURGE / ACTUAL`
- Button: **Send Actual Purge** or **Send Proposed Purge**

> "If you click Send Proposed Purge, only the Purge Advisory is sent. If you click Send Actual Purge, all control times are purged from the TFMS system and the Actual Advisory is sent to all users." — Ch. 20, p. 20-4

### 10.2 Reducing GS Scope

This is now a revision, not a separate purge-and-reissue:

> "GDP processing enhancements now allow for you to reduce the scope of a Ground Stop. You no longer need to purge the current Ground Stop and reissue a new Ground Stop with a reduced scope. Reducing the scope can now be done as a simple Ground Stop revision." — Ch. 20, p. 20-4

When scope is reduced:
- FSM purges control times from flights no longer included
- TFMS notifies all users of de-controlled flights

### 10.3 Extending a GS

1. Ensure the Ground Stopped airport is active
2. Click **GDT Mode** on the Control Panel
3. Select **GS** from Program Type dropdown
4. Select **File > Load Actual Parameters > Ground Stop**
5. Adjust parameters and extend GS time
6. Click **Model** to view delay statistics
7. **Turn substitutions off** (SUB OFF) before sending
8. Click **Run Proposed** or **Run Actual** → coversheet opens
9. Review and send

### 10.4 Moving from GS to GDP

Critical transition workflow (Ch. 20, pp. 20-7 to 20-9):

1. Open GDT mode for the airport
2. Select **GDP-DAS** from Program Type dropdown
3. Click **Scope** tab
4. In Flights section, select **"Exempt Active Flights Only (By Status)"**
5. **Turn SUBS OFF**:
   - Via TFMS Tools > EDCT Commands > EDCT Sub Off, OR
   - Click **SUB OFF** button on GDT Setup Panel
6. Click **Reload** and verify **SUBS: ALL OFF** indicator
7. Model the GDP
8. Click **Run Proposed** or **Run Actual** to open coversheet

### 10.5 Substitution Management

> "When moving from a GS to a GDP, you need to suspend, temporarily, the acceptance of airline substitutions and Slot Credit Substitutions (SCS) messages." — Ch. 20, p. 20-7

**Substitution status indicators**:
- `SUBS: ALL ON` — all substitutions enabled
- `SUBS: ALL OFF` — all substitutions disabled
- `SCS OFF/ADPT ON` — SCS off, adaptive compression on
- `SCS ON/ADPT OFF` — SCS on, adaptive compression off
- `SCS OFF/ADPT OFF` — both off

**Warning behavior**: If SUBS are ON when clicking Autosend during compression/revision/extension, FSM warns: "Turn SUBS OFF, reload, and remodel the program." Can be bypassed with Ignore.

---

## 11. Demand By Center Component

The Demand By Center component shows the scope of the modeled TMI with three columns:

| Column | Content |
|--------|---------|
| **Centers** | All centers + top 5 airports per center (by Non-Exempt count, then Exempt count) |
| **Non-Exempt** | Count of non-exempt flights |
| **Exempt** | Count of exempt flights |

Visual indicators:
- **Red dot** = at least one Non-Exempt flight in that center/airport
- **Green dot** = all flights are Exempt in that center/airport
- If >5 airports in a center have included flights, remaining are combined in an "Others" row
- Note: "All counts are for Non-Active, Included flights only"

---

## 12. IPM Mode Differences

Ground Stop is available in IPM (Integrated Program Modeling) mode with these differences:

- IPM supports GS modeling with the same Parameters, Scope, and Modeling Options tabs
- IPM adds **Scenario Manager** for comparing multiple TMI scenarios
- IPM has **Model All** button (replaces Model)
- **Run** and **Subs OFF** buttons are **not available** in IPM mode:
  > "The Run and Subs OFF buttons found in GDT Setup are not part of the current functionality of IPM Setup." — Ch. 3, p. 3-140
- IPM does not support Blanket, Compress Flights, Compress Slots, or Airborne Holding

---

## 13. GS Data Elements (Appendix B)

### Calculations

| Element | Formula |
|---------|---------|
| **Absolute Delay** | `Max(0, ETA - (IGTA - taxi))` |
| **Schedule Variation** | `ETA - (IGTA - taxi)` |
| **Program Delay** | `Max(0, CTA - BETA)` |

> "Program Delay changes anytime a flight's CTA changes, this includes airline substitutions and SCS messages and well as EDCT updates." — Appendix B

### Alarm Codes

| Code | Meaning |
|------|---------|
| CC | CTA non-compliance (>5 min before/after Control Time of Arrival) |
| EC | EDCT non-compliance (>5 min before/after EDCT) |
| EA | ETE vs Actual Value exceeds threshold (default 15 min) |
| SF | Spurious Flight |
| CF | Cancelled but Flew |

---

## 14. GS Advisory Format

The Advisory is generated from the coversheet and includes:

- Program parameters (airport, start/end, scope)
- Category and Cause
- Probability of Extension (GS-only)
- Comments
- ATCSCC position number and phone number (auto-added by TFMS Email)

The advisory can be previewed before sending via **Advisory/Causal Factors dropdown > Preview Advisory**.

**Respond By** field (proposed only): If Respond By = 1845, the message is valid until 1959 (1 hour 14 minutes). The message could also expire when the program ends.

---

## 15. Resending Information

If parameters or advisory were not properly sent:

1. Open coversheet from GDT Setup: **File > Open Coversheet**
2. This opens a file selection window for the Reports directory
3. All coversheets are named `covr.xxx` where `xxx` = airport 3-letter ID
4. File names include Date, ADL time, and type (GS, GDP, or CNX)

---

## 16. PERTI Implementation Notes

Key differences and considerations for implementing GS in PERTI vs. the real FSM:

| FSM Feature | PERTI Consideration |
|-------------|-------------------|
| FADT file generation | Not needed — PERTI uses direct DB updates |
| Hub site distribution via ADL | Not needed — PERTI uses real-time daemon sync |
| TFMS Autosend Server | Replace with direct API calls + Discord notifications |
| Airline substitutions (SUBS) | Simplified — VATSIM doesn't have airline CDM |
| Slot Credit Substitutions | Not applicable to VATSIM |
| EDCT Sub Off messages | Not needed |
| Historical mode replay | Possible via `adl_flight_archive` |
| Power Run analysis | Could implement Data Graph equivalents in GDT UI |
| NTML Advisory | Implemented via `tmi_advisories` + Discord |
| Tier-based scope | Use existing `artcc_facilities` + `artcc_adjacencies` |
| Coversheet workflow | Implement as confirmation dialog in GDT UI |

### Existing PERTI GS Implementation

PERTI already has GS support via:
- `tmi_programs` table with program_type = 'GS'
- `api/mgt/tmi/ground_stops/` API endpoints
- `gdt.php` UI with GS creation/activation
- TMI→ADL sync via `executeDeferredTMISync()` in ADL daemon
- Discord notification via `process_discord_queue.php`

### Key Algorithm Details to Preserve

1. **ETD = GS End + 1 minute** for all included flights (not staggered like GDP)
2. **ETA = New ETD + Original ETE** (preserves enroute time)
3. **Always Tier-based** scope
4. **End time snaps to quarter-hour** (:00/:15/:30/:45)
5. **Minimum 1-hour duration**
6. **Purge candidate ETD priority list** (4-level cascade)

---

## 17. Program Interactions & Precedence

### GS + CTOP
> "If a flight is already controlled by a CTOP, FSM will shift control to the Ground Stop." — Ch. 19

GS takes precedence over CTOP.

### GS + GDP (Simultaneous)
A GS can exist **on top of** a GAAP GDP. From Ch. 16 (p. 3-61):
> "a Ground Stop has been put into place over a GAAP GDP."

This implies GS takes precedence over GDP for controlled flights.

### GS + Blanket
> "You should not use Blanket in conjunction with a GS." — Ch. 3, p. 3-61

### GS + AFP
GS is airport-only; AFP is airspace-only. They operate on different scope types and cannot directly conflict within the same data set.

### Map Status Colors for GS
| Color | Meaning |
|-------|---------|
| **Red** | An actual GDP and/or GS is in effect |
| **Yellow** | A proposed GDP or GS, or a proposed GDP and an actual GS |

### Program Cancellation Time
> "The Program Cancellation Time is available for all Power Runs for GDP and AFP program types, but not for the GS program types." — Ch. 3

### Control Exempt Flag
> "Control Exempt Flag (Ctl Exempt) - Flight was exempt from departure delay in the most recent TMI event." — Appendix B

---

## 18. Document Gaps & Ambiguities

The following details are **not explicitly specified** in the FSM User's Guide and may need to be determined from other sources:

1. **Default Purge Notification minute values**: The text says "The actual purge uses the default values" but never states the numerical defaults. (Screenshot evidence from Fig. 20-2 shows: Taxied=20, GS=20, GDP/AFP=45.)

2. **Natural GS termination behavior**: The document says flights get new ETD of "one minute after the GS End time" but does not explicitly describe what happens systemically when the GS End time passes — whether it auto-terminates or requires manual purge.

3. **GS + GDP explicit precedence rules**: While the document shows GS can exist on top of a GDP and GS takes control from CTOP, the exact precedence hierarchy when a flight is controlled by both a GDP and GS simultaneously is not formally documented.

4. **ECR (EDCT Change Request) during GS**: Chapter 14 describes ECR for updating EDCTs during a GDP. Whether ECR functionality is available during a pure GS (where flights have a uniform release time, not individual EDCTs) is not clarified.

5. **GS revision scope**: The document describes extending (time change) and scope reduction separately. Whether other parameters can be modified mid-GS (e.g., adding/removing center groups while keeping the same time) is not explicitly addressed as a distinct operation.

6. **Slot-based reports during GS**: Reports like Slot List, Slot Hold Report, and Sub Opportunities are listed as available, but whether they are meaningful or disabled during a pure GS (which has no slot assignments) is not clarified.

---

## Appendix: Figure Reference

| Figure | Page | Description |
|--------|------|-------------|
| 19-1 | 19-2 | GDT Setup Parameters Tab with Ground Stop selected |
| 19-2 | 19-3 | GDT Setup GS Scope Tab (Tier-based) |
| 19-3 | 19-4 | GDT Setup GS Power Run By Options |
| 19-4 | 19-5 | Power Run Data Graph by GS Center Group |
| 19-5 | 19-6 | Demand By Center Component |
| 19-6 | 19-7 | Ground Stop Coversheet (yellow border) |
| 19-7 | 19-8 | Coversheet Advisory/Causal Factors Section |
| 19-8 | 19-9 | GS Advisory Preview (text format) |
| 19-9 | 19-10 | Program Manager (Autosend progress) |
| 19-10 | 19-12 | Live Mode — Yellow GS flights in Bar Graph |
| 19-11 | 19-13 | GS Flight List for 1-Hour Bar |
| 19-12 | 19-14 | Query Manager with Ground_Stopped filter |
| 20-1 | 20-2 | Purge Notifications (Minutes) Values |
| 20-2 | 20-3 | GS CNX Coversheet (red border) |
| 20-3 | 20-5 | Load Actual Parameters > Ground Stop |
| 20-4 | 20-6 | GS Revision Coversheet |
| 20-5 | 20-7 | Scope Tab Setup for Moving From GS to GDP |
| 20-6 | 20-8 | Turn Subs OFF Dialog |
| 3-87 | 3-109 | Completed Actual GS Coversheet (Yellow Border) |
| 12-12 | 12-14 | Actual GS Parameters Window |
