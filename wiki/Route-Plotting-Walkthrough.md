# Route Plotting Walkthrough

This guide covers using the PERTI Route Plotter to visualize flight routes and live traffic on an interactive TSD-style map.

---

## Overview

The Route Plotter is a Traffic Situation Display (TSD) style tool for visualizing:
- Flight routes and airways
- Live VATSIM traffic
- Playbook routes and CDRs
- Public route advisories

**Access URL:** https://perti.vatcscc.org/route.php

---

## Getting Started

### Step 1: Access the Route Plotter

1. Navigate to `https://perti.vatcscc.org/route.php`
2. Login with VATSIM if required
3. The map loads centered on the continental US

### Step 2: Interface Overview

| Element | Location | Purpose |
|---------|----------|---------|
| **Route Input** | Top left | Enter route strings |
| **Map Canvas** | Center | Interactive map display |
| **Public Routes Panel** | Right side | Shared route advisories |
| **Playbook/CDR Search** | Floating panel | Search standard routes |
| **Symbology Panel** | Floating panel | Customize route styling |
| **ADL Filter Panel** | Left side | Filter live traffic |

---

## Plotting Routes

### Basic Route Entry

1. Click the **Route Input** textarea (top left)
2. Enter a route string in standard format
3. Click **Plot Routes**
4. The route appears on the map as a colored line

### Route String Format

| Element | Example | Description |
|---------|---------|-------------|
| Airport | `KABQ` | Origin/destination ICAO |
| Fix | `LARGO` | Named waypoint |
| Navaid | `CME` | VOR/NDB identifier |
| Airway | `J15` | Jet route or Victor airway |
| DCT | `DCT` | Direct routing |
| SID | `LARGO3` | Standard Instrument Departure |
| STAR | `LZZRD4` | Standard Terminal Arrival |

### Example Routes

**Albuquerque to Phoenix (Playbook ABI):**
```
KABQ CNX J15 CME BGS SAT Q24 LSU SHYRE HOBTT3 KATL
```

**Charlotte to Albuquerque (CDR):**
```
KABE LRP EMI GVE AIROW CHSLY6 KCLT
```

**Chicago to Los Angeles (Playbook ABI):**
```
ZAU ARG NIIZZ CVE ABI EWM J4 WLVRN ESTWD.HLYWD1 KLAX
```

**Boston to Las Vegas (Playbook ABI):**
```
ZBW BAF Q448 PTW J48 CSN FANPO Q40 BFOLO FIBER HRISN Q30 IZAAC EIC J4 ABI CME J15 ABQ J72 GUP HAHAA.RKSTR4 KLAS
```

### Multi-Route Plotting

Plot multiple routes simultaneously:

1. Enter each route on a separate line in the input box
2. Click **Plot Routes**
3. Each route displays in a different color

### Route Actions

| Button | Action |
|--------|--------|
| **Plot Routes** | Plot entered route(s) on map |
| **Copy Routes** | Copy route string to clipboard |
| **Clear Routes** | Remove all plotted routes |
| **Toggle Labels** | Show/hide route labels |

---

## Using Playbook Routes

Playbook routes are pre-defined FAA procedures for common traffic flows.

### Step 1: Open Playbook Search

1. Click the **Playbook/CDR** button in the toolbar
2. The search panel appears
3. Select the **Playbook** tab

### Step 2: Search for Routes

1. Type in the **Search** field:
   - Play name (e.g., "ABI")
   - Origin ARTCC (e.g., "ZAU", "ZBW")
   - Destination (e.g., "KLAX", "KLAS")
2. Results filter in real-time

### Step 3: Load a Playbook Route

1. Click on a result to expand details:
   - Full route string
   - Origin ARTCCs
   - Destination airports and ARTCCs
2. Click to load the route into the input
3. Click **Plot Routes** to display on map

### Example Playbook Searches

| Search | Finds |
|--------|-------|
| `ABI` | All ABI playbook routes |
| `ZAU` | Routes originating from Chicago Center |
| `KLAX` | Routes to Los Angeles |
| `ZAU KLAX` | Chicago to Los Angeles routes |

---

## Using CDR (Coded Departure Routes)

CDRs are coded route identifiers used for traffic management.

### Step 1: Open CDR Search

1. Click the **Playbook/CDR** button
2. Select the **CDR** tab

### Step 2: Search CDRs

1. Enter the CDR code (e.g., "ABQATL")
2. Or search by origin/destination pair
3. View the decoded route string

### Example CDRs

| CDR Code | Route |
|----------|-------|
| `ABECLTGV` | KABE LRP EMI GVE AIROW CHSLY6 KCLT |
| `ABQATLER` | KABQ CNX J15 CME BGS SAT Q24 LSU SHYRE HOBTT3 KATL |
| `ABQBWICE` | KABQ CNX J15 CME FST J86 IAH J2 CEW ALLMA TEEEM Q99 OGRAE GOOOB THHMP RAVNN8 KBWI |

### Step 3: Load CDR

1. Select the CDR from results
2. Route string loads into input
3. Click **Plot Routes** to display on map

---

## Live Traffic Display

View real-time VATSIM flights on the map.

### Enable Live Traffic

1. Toggle the **ADL** switch to enable flight display
2. Aircraft symbols appear on map
3. Data refreshes automatically

### Flight Symbology

| Symbol Color | Aircraft Type |
|--------------|---------------|
| **Red** | Heavy/Jumbo (B747, A380, etc.) |
| **Orange** | Large Jet (B737, A320, etc.) |
| **Yellow** | Small Jet (CRJ, E175, etc.) |
| **Green** | Turboprop (DHC8, ATR, etc.) |
| **Blue** | Prop/GA (C172, PA28, etc.) |

### Filter Flights

1. Open the **ADL Filter Panel** on the left
2. Filter options:
   - **Status**: Prefile, Taxiing, Departed, Enroute, Descending, Arrived
   - **Origin**: Filter by departure airport
   - **Destination**: Filter by arrival airport
   - **Carrier**: Filter by airline (e.g., "UAL", "DAL")
   - **Aircraft**: Filter by type (e.g., "B738")
   - **Rule**: IFR or VFR
   - **Weight**: Heavy, Large, Small
3. Apply filters to focus on specific traffic

### Flight Information Popup

1. Click on any aircraft symbol
2. Popup displays:
   - Callsign and airline
   - Origin → Destination
   - Aircraft type and equipment
   - Current altitude and ground speed
   - Flight phase
   - Full route string

---

## Public Routes

View and load routes shared by other users.

### Public Routes Panel

1. Toggle the **Public Routes** switch to show the panel
2. Routes are color-coded by status:
   - **Green border** - Currently active
   - **Blue border** - Future (not yet active)
   - **Gray border** - Expired

### Load a Public Route

1. Find the route in the panel
2. Click the **eye icon** to show/hide on map
3. Click to load route string into input
4. Click **Expand** to view full details

---

## Customizing Route Display

### Symbology Panel

1. Click the **Symbology** button in the toolbar
2. The styling panel appears (draggable)

### Route Line Options

| Setting | Options |
|---------|---------|
| **Color** | Color picker or preset |
| **Width** | 1-10 pixels |
| **Style** | Solid, Dashed, Dotted |
| **Opacity** | 0-100% transparency |

### Apply Styles

1. Select a route from the list (or "All Routes")
2. Adjust settings
3. Changes apply in real-time
4. Click **Reset** to restore defaults

---

## Map Controls

### Navigation

| Action | Method |
|--------|--------|
| Pan | Click and drag |
| Zoom | Scroll wheel or +/- buttons |
| Reset view | Double-click |

---

## Exporting Routes

### Copy Route String

1. Plot the route
2. Click **Copy Routes** button
3. Route string copied to clipboard

### Export Formats

Click the **Export** dropdown to select format:

| Format | Use Case |
|--------|----------|
| **GeoJSON** | Web mapping, GIS tools |
| **KML** | Google Earth |
| **GPKG** | GeoPackage for desktop GIS |

---

## Troubleshooting

### Route Not Plotting

- Verify fix/navaid names are correct
- Check for typos in route string
- Ensure airways exist (some are decommissioned)
- Try breaking into smaller segments

### Flights Not Showing

- Check that ADL toggle is enabled
- Verify filters aren't hiding all traffic
- VATSIM data updates every 15 seconds
- Try refreshing the page

### Map Running Slowly

- Reduce number of displayed routes
- Filter flights to reduce symbols
- Close unused panels

---

## See Also

- [[Route Plotter]] - Technical reference
- [[ADL API]] - Flight data API
- [[Creating PERTI Plans]] - Planning with routes
- [[FAQ]] - Common questions
