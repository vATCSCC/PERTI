# Route Plotting Walkthrough

This guide covers using the PERTI Route Plotter to visualize flight routes, weather, special use airspace, and live traffic on an interactive TSD-style map.

---

## Overview

The Route Plotter is a Traffic Situation Display (TSD) style tool for visualizing:
- Flight routes and airways
- Live VATSIM traffic
- Weather radar (NEXRAD/MRMS)
- Special Use Airspace (MOAs, Restricted, TFRs)
- Playbook routes and CDRs

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
| **Legend** | Bottom left | Flight status colors |
| **Layer Controls** | Top right | Toggle map layers |

---

## Plotting Routes

### Basic Route Entry

1. Click the **Route Input** textarea (top left)
2. Enter a route string in standard format:

```
KJFK DCT MERIT J60 PSB STOEL3
```

3. Press **Enter** or click **Plot**
4. The route appears on the map as a colored line

### Route String Format

| Element | Example | Description |
|---------|---------|-------------|
| Airport | `KJFK` | Origin/destination ICAO |
| Fix | `MERIT` | Named waypoint |
| Navaid | `PSB` | VOR/NDB identifier |
| Airway | `J60` | Jet route or Victor airway |
| DCT | `DCT` | Direct routing |
| SID | `DEEZZ5` | Standard Instrument Departure |
| STAR | `STOEL3` | Standard Terminal Arrival |

### Example Routes

**New York to Los Angeles:**
```
KJFK HAPIE Q436 FEWWW ANJLL4 KLAX
```

**Chicago to Atlanta (with airway):**
```
KORD BENKY J146 SAWED PLLOT CHPPR3 KATL
```

**Simple direct route:**
```
KBOS DCT ACK DCT MVY DCT KMVY
```

### Multi-Route Plotting

Plot multiple routes simultaneously:

1. Enter each route on a separate line in the input box
2. Click **Plot All**
3. Each route displays in a different color
4. Toggle individual route visibility in the layer controls

---

## Using Playbook Routes

Playbook routes are pre-defined FAA procedures for common traffic flows.

### Step 1: Open Playbook Search

1. Click the **Playbook** button or press `P`
2. The Playbook Search panel appears

### Step 2: Search for Routes

1. Type in the **Search** field:
   - Play name (e.g., "JUDDS")
   - Origin airport (e.g., "KJFK")
   - Destination (e.g., "KLAX")
2. Results filter in real-time

### Step 3: Load a Playbook Route

1. Click on a result to expand details:
   - Full route string
   - Origin airports/TRACONs/ARTCCs
   - Destination airports
2. Click **Load Route** to plot on map
3. Or click **Copy** to copy route string

---

## Using CDR (Coded Departure Routes)

CDRs are coded route identifiers used for traffic management.

### Step 1: Open CDR Search

1. Click the **CDR** tab in the Playbook panel
2. Or press `C` to toggle CDR search

### Step 2: Search CDRs

1. Enter the CDR code (e.g., "KJFK3")
2. Or search by origin/destination
3. View the decoded route string

### Step 3: Load CDR

1. Select the CDR from results
2. Click **Load** to plot the full route
3. The decoded route appears on map

---

## Live Traffic Display

View real-time VATSIM flights on the map.

### Enable Live Traffic

1. Click the **Flights** toggle in layer controls
2. Or press `F` to toggle flights
3. Aircraft symbols appear on map

### Flight Symbology

| Symbol Color | Aircraft Type |
|--------------|---------------|
| **Red** | Heavy/Jumbo (B747, A380, etc.) |
| **Orange** | Large Jet (B737, A320, etc.) |
| **Yellow** | Small Jet (CRJ, E175, etc.) |
| **Green** | Turboprop (DHC8, ATR, etc.) |
| **Blue** | Prop/GA (C172, PA28, etc.) |

| Symbol Shape | Flight Phase |
|--------------|--------------|
| Diamond | Prefile |
| Triangle (hollow) | Taxiing |
| Triangle (filled) | Airborne |
| Square | Arrived |

### Filter Flights

1. Click **Filter** button or press `Shift+F`
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
3. Click **Track** to follow the flight
4. Click **Route** to plot the flight's route

---

## Weather Radar

Display real-time weather radar imagery.

### Enable Weather Layer

1. Click **Weather** toggle in layer controls
2. Or press `W` to toggle weather
3. NEXRAD/MRMS radar tiles overlay the map

### Weather Display Options

1. Click **Weather Settings** (gear icon)
2. Options:
   - **Product**: Base Reflectivity (N0Q), Composite, etc.
   - **Opacity**: Adjust transparency (0-100%)
   - **Animation**: Enable radar loop
3. Weather updates automatically every 5 minutes

### Radar Legend

| Color | Intensity | Meaning |
|-------|-----------|---------|
| Green | Light | Light rain/precip |
| Yellow | Moderate | Moderate rain |
| Orange | Heavy | Heavy rain |
| Red | Severe | Severe precipitation |
| Purple | Extreme | Extreme (hail possible) |

---

## Special Use Airspace (SUA)

Display restricted areas, MOAs, and TFRs.

### Enable SUA Layer

1. Click **SUA** toggle in layer controls
2. Or press `S` to toggle SUA display

### SUA Types

| Type | Color | Description |
|------|-------|-------------|
| **MOA** | Tan/Brown | Military Operating Area |
| **Restricted** | Red | Restricted airspace |
| **Prohibited** | Red (solid) | Prohibited area |
| **Alert** | Magenta | Alert area |
| **Warning** | Yellow | Warning area |
| **TFR** | Orange | Temporary Flight Restriction |

### Filter SUA Types

1. Click **SUA Filter** button
2. Toggle individual SUA types:
   - MOAs
   - Restricted/Prohibited
   - Alert Areas
   - Military Routes
   - TFRs
3. Filter by status:
   - **Active Now** - Currently active
   - **Scheduled** - Upcoming activation
   - **All** - Show all regardless of status

### SUA Information

1. Click on any SUA polygon
2. Popup shows:
   - Airspace name and type
   - Altitude range (floor/ceiling)
   - Active times (if scheduled)
   - Controlling agency
   - Special instructions

---

## Public Routes

View and load routes shared by other users.

### Public Routes Panel

1. The panel appears on the right side
2. Routes are color-coded by status:
   - **Green border** - Currently active
   - **Blue border** - Future (not yet active)
   - **Gray border** - Expired

### Load a Public Route

1. Find the route in the panel
2. Click the **eye icon** to show/hide on map
3. Click **Load** to copy route to input
4. Click **Expand** to view full details

### Create a Public Route

1. Plot a route on the map
2. Click **Share Route** button
3. Fill in the form:
   - **Title** - Brief description
   - **Effective Time** - Start/end (UTC)
   - **Remarks** - Additional notes
4. Click **Publish**
5. Route appears in Public Routes panel for all users

---

## Customizing Route Display

### Symbology Panel

1. Click **Symbology** button or press `Y`
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

### Departure/Arrival Styling

- **Departures**: Diagonal hatching pattern (FAA style)
- **Arrivals**: Solid fill
- Toggle hatching in Symbology panel

---

## Map Controls

### Navigation

| Action | Method |
|--------|--------|
| Pan | Click and drag |
| Zoom | Scroll wheel or +/- buttons |
| Reset view | Click **Home** button |
| Full screen | Press `F11` or click expand |

### Keyboard Shortcuts

| Key | Action |
|-----|--------|
| `F` | Toggle flights |
| `W` | Toggle weather |
| `S` | Toggle SUA |
| `P` | Open Playbook search |
| `C` | Open CDR search |
| `Y` | Open Symbology panel |
| `R` | Clear all routes |
| `Esc` | Close panels |
| `+` / `-` | Zoom in/out |

---

## Exporting Routes

### Copy Route String

1. Plot the route
2. Click **Copy** button
3. Route string copied to clipboard

### Export as GeoJSON

1. Plot route(s)
2. Click **Export** → **GeoJSON**
3. Download file for use in other GIS tools

### Export as KML

1. Plot route(s)
2. Click **Export** → **KML**
3. Download for Google Earth or other viewers

---

## Troubleshooting

### Route Not Plotting

- Verify fix/navaid names are correct
- Check for typos in route string
- Ensure airways exist (some are decommissioned)
- Try breaking into smaller segments

### Flights Not Showing

- Check that Flights layer is enabled
- Verify filters aren't hiding all traffic
- VATSIM data updates every 15 seconds
- Try refreshing the page

### Weather Not Loading

- Weather tiles may have temporary outages
- Check IEM (Iowa Environmental Mesonet) status
- Try toggling weather off and on

### Map Running Slowly

- Reduce number of displayed routes
- Disable weather animation
- Hide SUA layer if not needed
- Filter flights to reduce symbols

---

## See Also

- [[Route Plotter]] - Technical reference
- [[ADL API]] - Flight data API
- [[Creating PERTI Plans]] - Planning with routes
- [[FAQ]] - Common questions
