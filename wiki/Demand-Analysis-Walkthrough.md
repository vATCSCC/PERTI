# Demand Analysis Walkthrough

This guide covers using the PERTI Demand Analysis tool to visualize airport traffic demand, manage arrival/departure rates, and make informed traffic flow decisions.

---

## Overview

The Demand Analysis tool provides FSM/TBFM-style visualization of:
- Airport arrival and departure demand
- Flight phase breakdown by time period
- Weather-aware rate suggestions
- Rate overrides for traffic management
- Multiple drill-down analysis views

**Access URL:** https://perti.vatcscc.org/demand.php

---

## Getting Started

### Step 1: Access Demand Analysis

1. Navigate to `https://perti.vatcscc.org/demand.php`
2. Login with VATSIM if required
3. The interface loads with the default airport view

### Step 2: Interface Overview

| Element | Location | Purpose |
|---------|----------|---------|
| **Info Bar** | Top | UTC clock, airport, config, ATIS, stats |
| **Filter Panel** | Left side | Airport selection and filters |
| **Demand Chart** | Center | Stacked bar chart of demand |
| **View Toggles** | Above chart | Switch analysis views |
| **Legend** | Left panel | Flight phase colors |

---

## Selecting an Airport

### Using the Filter Panel

1. Click the **Airport** dropdown in the left panel
2. Search by:
   - ICAO code (e.g., "KJFK")
   - Airport name (e.g., "Kennedy")
3. Click to select the airport
4. Chart updates automatically

### Quick Category Filters

Filter airports by category:

| Category | Description |
|----------|-------------|
| **All** | All airports in database |
| **Core30** | 30 busiest US airports |
| **OEP35** | 35 Operational Evolution Partnership airports |
| **OPSNET45** | 45 FAA Operations Network airports |
| **ASPM82** | 82 ASPM-tracked airports |

### ARTCC Filter

1. Select an ARTCC from the dropdown
2. Only airports within that center display
3. Useful for regional analysis

---

## Understanding the Demand Chart

### Chart Layout

The main chart displays a **stacked bar chart** showing:
- **X-axis**: Time periods (hourly by default)
- **Y-axis**: Number of flights
- **Bars**: Stacked by flight phase
- **Lines**: AAR (solid) and ADR (dashed) rates

### Flight Phase Colors

| Phase | Color | Description |
|-------|-------|-------------|
| **Prefile** | Light Blue | Flight plan filed, not departed |
| **Taxiing** | Yellow | On ground, moving |
| **Departed** | Orange | Recently airborne |
| **Enroute** | Green | Cruising |
| **Descending** | Purple | Approaching destination |
| **Arrived** | Gray | On ground at destination |

### Reading the Chart

1. **Arrivals**: Displayed above the center line
2. **Departures**: Displayed below (with diagonal hatching)
3. **Rate Lines**:
   - Solid line = AAR (Airport Arrival Rate)
   - Dashed line = ADR (Airport Departure Rate)
4. **Excess demand**: When bars exceed rate line

---

## Info Bar Cards

The horizontal info bar provides real-time data.

### UTC Clock

- Displays current UTC time
- Updates every second
- Reference for all timestamps

### Airport Card

- Shows selected airport ICAO
- Airport name
- Primary ARTCC

### Configuration Card

Shows the **suggested runway configuration**:

| Element | Meaning |
|---------|---------|
| **Config Name** | e.g., "South Flow" |
| **Weather Badge** | VMC, LVMC, IMC, LIMC, VLIMC |
| **Runways** | Departure and arrival runways |
| **AAR/ADR** | Current rates |
| **Source** | Where rate came from |

Click **Override** to modify rates (privileged users).

### ATIS Card

Displays latest VATSIM ATIS:

- **ATIS Code** - Current information letter
- **Age** - Minutes since update
- **Runways** - Active configuration
- **Approach** - ILS, Visual, RNAV, etc.
- **Full Text** - Click to expand

### Stats Cards

**Arrivals**:
- Total arriving flights
- Active (airborne inbound)
- Scheduled (filed, not departed)
- Proposed (system calculated)

**Departures**:
- Total departing flights
- Active (recently departed)
- Scheduled (ready to depart)
- Proposed (awaiting slot)

---

## Adjusting Time Parameters

### Time Range

1. Find **Time Range** in the filter panel
2. Select preset:
   - ±2 hours
   - ±4 hours
   - ±6 hours (default)
   - ±12 hours
   - Custom
3. Chart updates to show selected window

### Granularity

Control time bin size:

| Setting | Bars Show |
|---------|-----------|
| **15 min** | Quarter-hour periods |
| **30 min** | Half-hour periods |
| **60 min** | Hourly periods (default) |

### Direction Filter

- **Both** - Show arrivals and departures
- **Arrivals** - Arrivals only
- **Departures** - Departures only

---

## Analysis View Modes

Click view toggle buttons above the chart to change analysis perspective.

### Status View (Default)

Breaks down by flight phase:
- Prefile, Taxiing, Departed, Enroute, Descending, Arrived

### Origin View

For **arrivals**, shows breakdown by origin ARTCC:
- See where inbound traffic originates
- Identify major feeder centers

### Destination View

For **departures**, shows breakdown by destination ARTCC:
- See where outbound traffic is going
- Plan downstream impacts

### Carrier View

Breakdown by airline:
- Top carriers highlighted
- Useful for coordination with airlines

### Weight View

Breakdown by aircraft weight class:
- Heavy (B747, B777, A380, etc.)
- Large (B737, A320, etc.)
- Small (Regional jets, props)

### Equipment View

Breakdown by aircraft type:
- B738, A320, E75L, etc.
- Identify fleet mix

### Rule View

IFR vs VFR distribution:
- Useful for approach planning
- VFR traffic impacts

### Fix Views

**Dep Fix** - Departures by departure fix
**Arr Fix** - Arrivals by arrival fix
- Identify busy corner posts
- Plan MIT or flow control

### Procedure Views

**DP** - Departures by SID
**STAR** - Arrivals by STAR
- See procedure loading
- Identify over-used routes

---

## Rate Management

### Understanding Rates

| Term | Definition |
|------|------------|
| **AAR** | Airport Arrival Rate - arrivals per hour |
| **ADR** | Airport Departure Rate - departures per hour |
| **Strategic Rate** | Baseline rate for configuration |
| **Dynamic Rate** | Adjusted rate due to conditions |
| **Override** | Manually set rate |

### Rate Sources

The configuration card shows rate source:

| Source | Meaning |
|--------|---------|
| **Config** | From runway configuration table |
| **ATIS** | Matched from VATSIM ATIS |
| **Override** | Manually overridden |
| **Default** | Fallback rate |

### Creating a Rate Override (Privileged Users)

1. Click **Override** button on Configuration card
2. The **Rate Override Modal** opens

#### Step 1: Select Configuration

- Choose from dropdown of available configs
- Or select "Custom Rates" for manual entry
- Config details show current rates by weather

#### Step 2: Set Rates

| Field | Description |
|-------|-------------|
| **AAR** | New arrival rate |
| **ADR** | New departure rate |

Rate labels indicate:
- **(Strategic)** - Matches baseline
- **(Dynamic)** - Differs from baseline
- **(Custom)** - Manually entered

#### Step 3: Set Time Range

- **Start Time** - When override begins (UTC)
- **End Time** - When override ends (UTC)
- Default: Now to +4 hours

#### Step 4: Add Reason (Optional)

Enter explanation:
- "Weather" - Reduced visibility
- "Event traffic" - Special event
- "Staffing" - Limited positions
- "Runway closure" - Maintenance

#### Step 5: Apply Override

1. Review settings
2. Click **Apply Override**
3. Override takes effect immediately
4. Chart updates with new rate lines

### Canceling an Override

1. Click **Override** button
2. View active overrides
3. Click **Cancel Override**
4. Rates revert to strategic baseline

---

## Auto-Refresh

### Enable/Disable

- Toggle **Auto-Refresh** in the info bar
- Default: Enabled (15-second interval)
- Status shows: "Active" or "Paused"

### Manual Refresh

- Click **Refresh** button anytime
- Forces immediate data update

---

## Practical Workflows

### Pre-Event Planning

1. Select event airport
2. Set time range to cover event duration
3. Review projected demand vs rates
4. Identify hours where demand exceeds capacity
5. Plan Ground Stops or GDPs for peak periods

### Real-Time Monitoring

1. Enable auto-refresh
2. Monitor arrivals approaching rate
3. Watch for demand spikes
4. Create override if rates need adjustment
5. Switch views to identify problem sources

### Post-Event Analysis

1. Set time range to past event window
2. Review actual vs planned demand
3. Analyze by carrier, origin, procedure
4. Document lessons learned

---

## Weather Categories

Rate suggestions vary by weather:

| Category | Visibility | Ceiling | Typical Impact |
|----------|------------|---------|----------------|
| **VMC** | ≥5 SM | ≥3000 ft | Full capacity |
| **LVMC** | 3-5 SM | 1500-3000 ft | Slightly reduced |
| **IMC** | 1-3 SM | 500-1500 ft | Reduced rates |
| **LIMC** | ½-1 SM | 200-500 ft | Significantly reduced |
| **VLIMC** | <½ SM | <200 ft | Minimum rates |

The system automatically suggests appropriate rates based on ATIS weather.

---

## Troubleshooting

### No Data Showing

- Verify airport is selected
- Check time range includes current time
- Ensure filters aren't too restrictive
- Try manual refresh

### Rates Not Updating

- Check ATIS age (may be stale)
- No matching configuration for weather
- Override may be active

### Chart Loading Slowly

- Reduce time range
- Use 60-min granularity
- Disable auto-refresh temporarily

### Override Not Applying

- Verify you have DCC privileges
- Check time range is valid (end > start)
- Ensure AAR/ADR values are positive numbers

---

## See Also

- [[GDT Ground Delay Tool]] - Implementing TMIs
- [[TMI API]] - API for traffic management
- [[Creating PERTI Plans]] - Event planning
- [[FAQ]] - Common questions
