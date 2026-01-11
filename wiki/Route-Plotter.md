# Route Plotter

The Route Plotter provides TSD-style flight visualization with route plotting and weather radar overlay.

**URL:** `/route.php`
**Access:** Authenticated

---

## Features

- Live flight display with VATSIM data
- Route plotting with waypoint visualization
- Weather radar overlay (NEXRAD/MRMS)
- Public route sharing
- SUA/TFR display
- Export to GeoJSON, KML, GeoPackage

---

## Map Controls

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
3. Enter route string
4. Click "Plot"

The route will display with waypoints resolved from the navigation database.

---

## Weather Radar

Toggle weather radar overlay from the layers panel. Available products:

| Product | Description |
|---------|-------------|
| NEXRAD N0Q | Base reflectivity |
| MRMS | Multi-radar composite |

Color tables can be customized in settings.

---

## Public Routes

Share routes with other users:

1. Plot your route
2. Click "Share"
3. Add description and validity period
4. Route appears in public routes list

---

## See Also

- [[GDT Ground Delay Tool]] - Traffic management
- [[API Reference]] - Route APIs
