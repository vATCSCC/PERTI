# Prong 3: TSD Display & Visualization Analysis

**Source**: TFMS Traffic Situation Display (TSD) Reference Manual, Version 9.5 (CSC/TFMM-13/1600)
**Scope**: Pages 1-1202 (chunks 1-3, 5-6)
**Purpose**: Extract exact display parameters, symbology, color coding, refresh rates, and configuration options to build equivalent displays in PERTI.

---

## 1. Map Display Features

### 1.1 Map Overlays

The TSD provides a comprehensive set of map overlays, each with three visibility states: **Show**, **Show on Browse**, or **Hidden**. Each overlay also has an independent label toggle.

| Overlay | Quick Key | Description |
|---------|-----------|-------------|
| Pacing Airports | `P` | FAA pacing airports |
| All Airports | `Y` | All airports in the database |
| High NAVAIDs | `F4` | High-altitude navigation aids |
| Low NAVAIDs | `F5` | Low-altitude navigation aids |
| Terminal NAVAIDs | `F6` | Terminal-area navigation aids |
| Other NAVAIDs | `F7` | Other navigation aids |
| Departure Fixes | `F3` | Standard departure fixes |
| Arrival Fixes | `F2` | Standard arrival fixes |
| Enroute Fixes | `F8` | Enroute navigation fixes |
| Jet Airways | `J` | High-altitude jet routes |
| Victor Airways | `V` | Low-altitude VOR routes |
| Boundaries | `B` | ARTCC/sector boundaries |
| Lat/Lon Grid | `[L` | Geographic coordinate grid |
| ARTCCs | `A` | Air Route Traffic Control Centers |
| Low Sectors | `L` | Low-altitude sectors |
| High Sectors | `H` | High-altitude sectors |
| Superhigh Sectors | `S` | Super-high-altitude sectors |
| Oceanic Sectors | `O` | Oceanic sectors |
| TRACONs | `^` | Terminal Radar Approach Control facilities |
| Alert Areas | `[A` | Alert areas (SUAs) |
| MOAs | `[M` | Military Operations Areas |
| Prohibited Areas | `[P` | Prohibited airspace |
| Restricted Areas | `[R` | Restricted airspace |
| Warning Areas | `[W` | Warning areas |
| All SUAs On | `[+` | Turn on all SUA types |
| All SUAs Off | `['` | Turn off all SUA types |

Additional overlay capabilities:
- **Show Map Item** (`#xxxx`): Display a specific Jet or Victor route by number (e.g., `#J80`)
- **Remove Map Item** (`#-xxxx`): Remove a specific route from display
- **Range Rings**: Configurable distance rings with labels (toggle: `<`/`-<`)
- **Runway Layout**: Airport runway configurations
- **Map Labels**: Toggle all shown overlay labels (`~`/`-~`)

### 1.2 Map Projection

Two projection modes:

| Mode | Description |
|------|-------------|
| **Dynamic** | Auto-adjusts projection based on the current display center |
| **Fixed** | Uses a preset projection for a specific geographic area |

Fixed projection options: CONUS, London, Canada, Atlantic, Alaskan, Chile.

### 1.3 Colors and Fonts

The **Colors/Fonts** dialog (Display menu) allows customization of map element colors, flight icon colors per flight set, weather overlay colors, and font sizes. Colors are selected from a **color palette** dialog. Colors referenced by name: "blue," "yellow," "red," "slate blue," "gray," "orange," "navy blue," "magenta," "white," "cyan," "dull blue."

Adaptation files (`Save Colors`/`Restore Colors` commands) persist color configurations.

---

## 2. Aircraft Symbology and Flight Display

### 2.1 Flight Icon Types

| Icon | Condition | Description |
|------|-----------|-------------|
| **Solid Airplane** | Active position data | 16 directional orientations based on heading |
| **Ghost (Hollow) Airplane** | No data > 7 minutes | Same shape, hollow/outline only |
| **Dot** | Simplified display | Small dot at flight position |
| **Automatic** | Type-based | Heavy/Jet/Prop symbols based on aircraft category |
| **Circle** | Ground-based flights | Used in NAS Monitor for examined flight display |

### 2.2 Data Blocks

| Line | Content | Notes |
|------|---------|-------|
| 1 | ACID (Aircraft Identifier) | Always shown when data blocks enabled |
| 2 | Altitude, Aircraft Type, Ground Speed, Time-to-Arrival | ETA-based countdown |
| 3 (optional) | Origin-Destination | Format: `ORG/DST` |
| 3 (optional) | Full Route Text | Alternative to Org/Dest |
| 3 (optional) | Beacon Code | Transponder squawk code |

Additional: Military formation count prefix, RVSM non-conformance indicator (square symbol), pending amendment indicator (`P`), global toggle (`|`/`-|`).

### 2.3 Lead Lines and Route Drawing

- **Lead Lines**: Default 5 minutes / 40 nm. Toggle: `/` / `-/`.
- **Draw Route**: Solid line, same color as icon. Toggle: `-` / `--`.
- **Route Text**: Toggle `&` / `-&`. **Org-Dest**: Toggle `+` / `-+`.
- **History Trails**: Dashed line with configurable interval.

### 2.4 Flight Update Rates

| Parameter | Rate |
|-----------|------|
| Position data transmission | At least every 5 minutes |
| Position estimation (interpolation) | Every 1 minute |
| Ghost threshold | 7 minutes without data |

### 2.5 Flight Filtering (Select Flights)

Filter criteria (AND across fields, OR within fields):
- Departure point(s), Destination(s), Airline(s), Fix(es) enroute, Sector(s) traversed, ARTCC(s) traversed, Airway(s) used, Aircraft type(s)/AC remarks, Lowest/Highest flight level (Filed/Reported/Both)
- `UNKW` for unknown departure/destination
- Each flight set gets distinct color and icon. Display modes: Replace or Add.

---

## 3. Traffic Displays

### 3.1 NAS Monitor

Tabular alert display showing peak traffic counts per 15-minute interval.

**Monitored Elements**: Airports, Sectors (Low/High/Superhigh), Fixes (Low/High/Superhigh), RVSM non-conformance.

**Alert Status Colors**:

| Color | Meaning |
|-------|---------|
| **Green** | No alerts; traffic within acceptable limits |
| **Yellow** | Alert for proposed (prefiled) flights exceeding MAP |
| **Red** | Alert for active flights exceeding MAP |
| **Green + yellow stripe** | Resolved proposed flight alert (Turn Green) |
| **Green + red stripe** | Resolved active flight alert (Turn Green) |
| **Uncolored (gray)** | No data available |

**MAP (Monitor Alert Parameters)**: MAP On (alert activates), MAP Off (alert clears, providing hysteresis). Alerts propagate to all TSDs within 5 minutes.

**Hierarchy**: Tier 1 (individual elements) -> Tier 2 (ARTCC grouping) -> System Summary (center-wide).

**Turn Green**: Operator acknowledgment changing alert to green-with-stripe.

### 3.2 Time in Sector Chart Colors

| Color | Condition |
|-------|-----------|
| **Red** | Active flights exceed MAP On |
| **Yellow** | Total flights exceed MAP On |
| **Green** | At or below MAP On/Off |

### 3.3 Airport Bar Chart (Examine Alerts)

| Element | Visual |
|---------|--------|
| Active flights | Red bars |
| Proposed flights | Yellow bars |
| MAP On line | Thick black line with red dots |
| MAP Off line | Thin red line with green diamonds |

### 3.4 Reroute Monitor

**Timeline**: Quarter-hour intervals, format `NC_count / C_count`.

**Conformance Status Codes**:

| Code | Meaning |
|------|---------|
| `C` | Conformant |
| `NC` | Non-Conformant |
| `NC/OK` | Non-Conformant but previously OK |
| `UNKN` | Unknown |
| `OK` | Exception Granted |
| `EXC` | Excluded |

**Visual Indicators**: Blue ACID = flight in multiple reroutes. Slate blue = protected segments. Solid circle around icon = non-conformant on map.

### 3.5 FEA/FCA Timeline

15-minute intervals with flight counts. Count types: Total occupancy (T), Peak (P), Entry time (E). Bar chart and filter dialogs available.

---

## 4. Weather Integration

### 4.1 CIWS Precipitation VIL Mosaic

**Resolution**: 1 km grid. **Standard Mode**: 6 levels (green lightest -> red darkest). **Winter Mode**: 8 levels including snow/rain-snow-mix. **Update**: 5 min CONUS, 15 min Canada/San Juan.

### 4.2 Echo Tops Mosaic

10 altitude levels at 5K ft increments to 50K+ ft. 18 dBZ threshold. **Update**: 2.5 minutes.

### 4.3 Echo Tops Forecast

4 levels in purple shades: <30K, 30K, 35K, >40K ft. **Update**: 5 minutes.

### 4.4 Forecast Contours

30/60/120 minute predictions. Single-pixel lines. **Update**: 5 minutes.
- Standard contours: Level 3+ precipitation
- Winter contours: Level 1c+ precipitation
- Echo tops contours: 30K ft tops

### 4.5 Verification Contours

**Colors**: Blue (30 min), Magenta (60 min), White (120 min). Single-pixel lines. **Update**: 5 minutes.

### 4.6 Growth and Decay Trends

**Orange cross-hatching**: Growth. **Solid navy blue**: Decay. Detection window: 15-18 min. **Update**: 2.5 minutes.

### 4.7 Storm Motion

Direction arrows from storm center, speed in knots at tips. Solid leading edge line, dashed extrapolated positions at 10/20 minutes. Colors user-configurable. **Update**: 2.5 minutes.

### 4.8 Echo Top Tags

Text boxes with leader lines. Flight level format (e.g., 200 = 20K ft). Threshold: 20K-75K ft in 5K increments. Position: Surround/Left/Right. Filter button: green (no filter) / yellow (active). **Update**: 2.5 minutes.

### 4.9 Lightning

Cross icon (`+`), user-configurable color (default blue). Past/current only. **Update**: 5 minutes.

### 4.10 Jet Stream

Wind > 70 kt: Altitude labels (hundreds of ft), speed contours (70+ kt base, 20 kt intervals), streamline arrows. Colors configurable. **Update**: 3 hours.

### 4.11 CCFP (Collaborative Convective Forecast Product)

**Update**: 2 hours. **Expiry**: 2.5 hours. **Forecasts**: 4/6/8 hour.

**Coverage x Confidence Matrix**:

| Fill / Color | Coverage | Confidence |
|---|---|---|
| Solid / Slate Blue | High 75-100% | High 50-100% |
| Solid / Gray | High 75-100% | Low 25-49% |
| Medium / Slate Blue | Medium 40-74% | High 50-100% |
| Medium / Gray | Medium 40-74% | Low 25-49% |
| Sparse / Slate Blue | Low 25-39% | High 50-100% |
| Sparse / Gray | Low 25-39% | Low 25-49% |

Direction: Green arrow with speed in knots. Echo tops: 290/340/390/400 (400 = >=40K ft). Growth: up/down arrow. Lines of convection: solid purple (high), dashed purple (medium).

### 4.12 NCWF (National Convective Weather Forecast)

Polygons for Level 3+ storm cores. Criteria: tops >= 15K ft AND area >= 500 sq mi. Arrows: length proportional to speed (1 nm/kt). **Update**: 5 minutes.

### 4.13 NOWRAD (Legacy)

2 km grid. 6 intensity levels. CONUS 5 min, Canada/San Juan 15 min. Level toggle via legend.

### 4.14 Satellite Mosaic

Grayscale visible + infrared GOES. **Update**: 15 minutes.

---

## 5. TMI Visualization

### 5.1 RAPT (Route Availability Planning Tool)

Metroplex-based departure route weather assessment.

**Route Display**: Default blue, user-configurable. On hover: contrasting color + name + width (dashed). Route widths vary by flight phase: CLIMB, TRANSITION, NEAR, ENROUTE.

**RAPT Timeline Panel** (free-standing, resizable):

| Column | Content |
|--------|---------|
| Route Name | Clickable for Trend Chart |
| Blockage Trend | Up arrow (improving), Down (worsening), Dash (stable), Blank (no data) |
| PIG Timer | Minutes since clear (0-180), blank if expired/impacted |
| Blockage Grid | 5-minute bins, color-coded |

**Blockage Colors**:

| Color | Status |
|-------|--------|
| **Dark Green** | No significant weather blockage |
| **Light Green/Yellow** | Minor weather along path |
| **Yellow** | Partially blocked |
| **Red** | Blocked |
| **Gray** | Beyond prediction horizon |

Yellow/Red labels show flight phase + 2-digit echo top height (thousands of ft).

**Trend Chart**: Vertical bar chart per route. Height = cloud top (thousands ft). Color = median blockage. Current + up to 7 previous bins.

**Flight Progress Indicators**: Numbers (minutes past hour) marching along routes during forecast animation. Color matches timeline bin. Gray beyond horizon.

**Forecast Animation**: 5-min increments. Start At (0 default), Increment (5 default, 5-120), End At (90 default, 5-120). Shared with CIWS animation control.

### 5.2 Reroute Display

- Route segments: **Solid 4-pixel line** in selected color
- Airport origins: **Solid circles**
- ARTCC origins: **Solid squares** at geographic center
- Dashed lines from airports to reroute segments
- Protected segments: **Slate blue** highlight

Domains: Public (all sites), Shared (selected), Local (facility), Private (user).

### 5.3 Reroute Impact Assessment (RRIA)

**Preview Mode**: Flight counts only, no model. Faster.

**Model Mode**: Background color changes. Shows projected NAS state with reroute applied. Static flight snapshot (weather stays live). Includes NAS Monitor, FEA/FCA Timeline/Bar Chart in model mode. With/Without Reroute comparison bars. MIT Status toggle. TMI warning triangle icon.

### 5.4 FEA/FCA

Creation: Polygon, Line Segment, or Circle. FEAs local, FCAs public (ATCSCC).

Examine: Timeline (15-min intervals), Bar Chart, Dynamic List, Primary/Secondary Filters, RVSM tracking.

### 5.5 SUA Display

| State | Visual |
|-------|--------|
| 15 min before activation | Orange outline |
| During active time | Orange fill |

Data block: Name, schedule type (D=Dynamic, S=Static), altitude.

---

## 6. User Interface Controls

### 6.1 Main Menu Bar

Display, Maps, Flights, Alerts, Weather, RAPT, Reroute, FEA/FCA, CTOP, Tools, Help.

### 6.2 Command Methods

1. **Menu**: Mouse or Alt+key navigation
2. **Quick Key**: Single keystrokes on TSD
3. **Semicolon**: `;COMMAND` in command line (e.g., `;PROJ LONDON`)

### 6.3 Script Automation

ASCII file with semicolon/quick key commands. Comments in `{...}`. Utilities: `;WAIT n`, `;CONFIGWAIT n`, `;PAUSE text`. Max 256 chars/line. Case-insensitive.

### 6.4 Multi-Workspace

Red Hat Linux GNOME, up to 32 workspaces with independent TSD instances.

---

## 7. Replay / Playback

**Parameters**: From/To Date/Time (`mmdd/hhmm` UTC), Replay Time Increment (default 1 min), Time Between Updates (default 10 sec).

**Replayable Sources**: Flights, Alerts, Sector Boundaries, CONUS/Canada/San Juan 2km Radar, CCFP, NCWF, Legacy TOPS, Jet Stream, Lightning, Satellite, CIWS Precip/Echo Tops/Tags, Growth/Decay, Storm Motion, VIL/Echo Tops Forecast Contours, Accuracy Scores.

**Controls**: Forward/Backward, Pause/Resume, Step Forward/Back, Speed control.

**Restrictions**: RAPT disabled, Reroute modeling disabled during replay.

---

## 8. Data Refresh Rates Summary

| Data Source | Update Interval |
|-------------|----------------|
| Flight positions (transmitted) | 5 minutes |
| Flight positions (estimated) | 1 minute |
| Ghost threshold | 7 minutes |
| CIWS Precipitation | 5 min (CONUS), 15 min (Canada/San Juan) |
| Echo Tops Mosaic | 2.5 minutes |
| Echo Tops Forecast | 5 minutes |
| Radar Coverage | 2.5 minutes |
| Satellite | 15 minutes |
| Forecast/Verification Contours | 5 minutes |
| Growth and Decay | 2.5 minutes |
| Storm Motion | 2.5 minutes |
| Echo Top Tags | 2.5 minutes |
| Lightning | 5 minutes |
| Jet Stream | 3 hours |
| CCFP | 2 hours |
| NCWF | 5 minutes |
| NAS Monitor alerts | Within 5 minutes |
| RAPT Timeline | 5 minutes |

---

## 9. PERTI Implementation Mapping

### 9.1 Key Implementation Gaps

| TSD Feature | Priority | Complexity |
|-------------|----------|------------|
| 16-direction airplane icons | Medium | Low |
| Ghost icon (stale >7 min) | Medium | Low |
| RAPT-style route blockage timeline | High | High |
| CCFP polygon visualization | Medium | Medium |
| NAS Monitor exact color scheme | High | Low |
| Flight progress indicators | Medium | High |
| Trend charts per route | Medium | Medium |
| Storm motion vectors | Medium | Medium |
| Reroute Impact Assessment (Model mode) | High | High |
| Replay/playback | Medium | High |

### 9.2 Color Reference for PERTI

| Context | Color Name | Suggested Hex |
|---------|-----------|---------------|
| NAS Monitor - No Alert | Green | `#00C000` |
| NAS Monitor - Proposed Alert | Yellow | `#FFD700` |
| NAS Monitor - Active Alert | Red | `#FF0000` |
| NAS Monitor - No Data | Gray | `#808080` |
| RAPT - No Blockage | Dark Green | `#006400` |
| RAPT - Minor Weather | Light Green | `#ADFF2F` |
| RAPT - Partial Blockage | Yellow | `#FFD700` |
| RAPT - Blocked | Red | `#FF0000` |
| RAPT - Beyond Horizon | Gray | `#808080` |
| RAPT - Routes Default | Blue | `#0000FF` |
| CCFP - High Confidence | Slate Blue | `#6A5ACD` |
| CCFP - Low Confidence | Gray | `#808080` |
| CCFP - Direction Arrow | Green | `#00FF00` |
| CCFP - Convection Lines | Purple | `#800080` |
| Weather - Growth | Orange | `#FFA500` |
| Weather - Decay | Navy Blue | `#000080` |
| Verification - 30 min | Blue | `#0000FF` |
| Verification - 60 min | Magenta | `#FF00FF` |
| Verification - 120 min | White | `#FFFFFF` |
| SUA - Pre-activation/Active | Orange | `#FFA500` |
| Reroute - Protected Segments | Slate Blue | `#6A5ACD` |
| Reroute - Multi-reroute ACID | Blue | `#0000FF` |
| Time in Sector - Over MAP (Active) | Red | `#FF0000` |
| Time in Sector - Over MAP (Total) | Yellow | `#FFD700` |
| Time in Sector - Normal | Green | `#00C000` |
| Bar Chart - MAP On | Black + Red dots | `#000000` + `#FF0000` |
| Bar Chart - MAP Off | Red + Green diamonds | `#FF0000` + `#00C000` |
| Lightning Default | Blue | `#0000FF` |

**Note**: The TSD manual specifies colors by name only, not RGB. The hex values above are suggested approximations.

---

## 10. Complete Quick Key Reference

| Key | Function (On/Off) |
|-----|-------------------|
| `%` / `-%` | Alerts display |
| `f` / `-f` | Flights |
| `*` / `-*` | Last Time Zone |
| `/` / `-/` | Lead Lines |
| `-` / `--` | Draw Routes |
| `&` / `-&` | Route Text in data blocks |
| `+` / `-+` | Org-Dest text in data blocks |
| `\|` / `-\|` | All Data Blocks |
| `~` / `-~` | Map Labels |
| `<` / `-<` | Range Ring Labels |
| `#xxxx` / `#-xxxx` | Specific Jet/Victor route |
| `^` / `-^` | TRACONs |
| `a` / `-a` | ARTCCs |
| `b` / `-b` | Boundaries |
| `h` / `-h` | High Sectors |
| `j` / `-j` | Jet Routes |
| `l` / `-l` | Low Sectors |
| `n` / `-n` | All NAVAIDs |
| `o` / `-o` | Oceanic Sectors |
| `p` / `-p` | Pacing Airports |
| `s` / `-s` | Superhigh Sectors |
| `t` / `-t` | All Airports |
| `v` / `-v` | Victor Routes |
| `[a` / `-[a` | Alert Areas |
| `[m` / `-[m` | MOAs |
| `[p` / `-[p` | Prohibited Areas |
| `[r` / `-[r` | Restricted Areas |
| `[w` / `-[w` | Warning Areas |
| `[+` / `-[+` | All SUAs |
| `[1` / `-[1` | Lat/Lon Grid |
| `arr` / `-arr` | Arrival Fixes |
| `dep` / `-dep` | Departure Fixes |
| `pop` | Maximize Window |
| `=` | Toggle reroute flights |
