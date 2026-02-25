# Route Plotter

The Route Plotter provides a TSD-style (Traffic Situation Display) flight visualization with route plotting, weather radar overlay, and integration with the Playbook and CDR systems.

**URL:** `/route.php`
**Access:** Authenticated

---

## Features

- Live flight display with VATSIM data
- Route plotting with waypoint visualization via PostGIS route expansion
- Weather radar overlay (IEM NEXRAD/MRMS tiles)
- Airway display with labeled segments
- CDR (Coded Departure Route) lookup and plotting
- DCC Playbook play expansion and route visualization
- Public route sharing with Discord coordination
- SUA/TFR overlay display
- ARTCC/sector boundary overlays
- Route symbology (colored lines, fix markers, distance labels)

---

## Map Controls

Built on **MapLibre GL JS** with WebGL rendering.

| Control | Function |
|---------|----------|
| Zoom | Mouse wheel or +/- buttons |
| Pan | Click and drag |
| Rotate | Right-click and drag |
| Pitch | Ctrl + drag |

---

## Plotting Routes

1. Enter origin airport (optional)
2. Enter destination airport (optional)
3. Enter route string (fixes, airways, or mixed)
4. Click "Plot"

The route is expanded via the GIS API (`/api/gis/boundaries.php?action=expand_route`), which resolves fix names to coordinates, expands airway segments, and identifies ARTCCs traversed. Waypoints are displayed as labeled markers along the route line.

### Route Syntax

The route parser supports:
- **Fixes**: `BNA MERIT HAAYS`
- **Airways**: `J48 Q100 V1`
- **Mixed**: `KDFW BNA J48 MERIT KJFK`
- **Procedures**: `RNAV2.ROBUC KJFK` (DP/STAR expansion)
- **Playbook codes**: `PB.PLAY.ORIG.DEST`
- **CDR codes**: Referenced by CDR ID

---

## Playbook Integration

DCC Playbook plays can be loaded and visualized on the route map:

1. Search for a play by name or browse the catalog
2. Select a play to load its routes
3. All routes in the play are plotted simultaneously with color coding
4. Route details (origin, destination, route string) display in the sidebar

The `playbook-dcc-loader.js` module handles play loading, and `playbook-cdr-search.js` provides CDR search functionality.

---

## Weather Radar

Toggle weather radar overlay from the layers panel. Available products:

| Product | Description |
|---------|-------------|
| NEXRAD N0Q | Base reflectivity (IEM tiles) |
| MRMS | Multi-radar multi-sensor composite |

Color tables can be customized in the layers settings. Opacity is adjustable via slider.

---

## Public Routes

Share routes with other users for coordination:

1. Plot your route
2. Click "Share"
3. Add description and validity period
4. Route appears in the public routes list and on NOD

Public routes are stored in `tmi_public_routes` (VATSIM_TMI) with GeoJSON geometry and map styling (color, line weight, line style).

---

## Frontend Architecture

| Module | Purpose |
|--------|---------|
| `route-maplibre.js` | Core MapLibre GL map initialization and layer management |
| `route-symbology.js` | Route display styling (colors, markers, labels) |
| `playbook-cdr-search.js` | CDR and Playbook route search |
| `playbook-dcc-loader.js` | DCC play loading and route expansion |
| `awys.js` | Airway data and display |
| `procs_enhanced.js` | DP/STAR procedure rendering |
| `weather_radar.js` | NEXRAD/MRMS tile overlay |
| `sua.js` | SUA/TFR overlay display |
| `fir-scope.js` | FIR boundary scope filtering |

---

## See Also

- [[Playbook]] - Playbook route catalog
- [[GDT Ground Delay Tool]] - Traffic management
- [[API Reference]] - Route and GIS APIs
- [[Splits]] - Sector boundary visualization
