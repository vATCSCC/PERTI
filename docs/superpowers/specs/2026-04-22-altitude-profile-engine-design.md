# Altitude Profile Engine — Design Specification

**Date**: 2026-04-22
**Status**: Draft
**Inspired by**: vIFF/CDM system at `cdm.vatsimspain.es` (rpuig2001)

---

## 1. Overview

### 1.1 What We're Building

A full kinematic altitude profile engine that:
1. **Computes** a planned vertical profile for every parsed flight (altitude at each waypoint)
2. **Stores** the profile in the existing `adl_flight_waypoints` table (`planned_alt_ft`, `is_toc`, `is_tod`)
3. **Compares** planned vs flown altitude using stored trajectory data (`adl_flight_trajectory`)
4. **Visualizes** both profiles in a Chart.js dual-axis chart with CDM-style projection
5. **Exposes** profile data via SWIM API endpoints

### 1.2 Design Decisions (Approved)

| Decision | Choice |
|----------|--------|
| Architecture | PHP `AltitudeProfileEngine` class in daemon pipeline |
| Computation timing | During route parsing (batch, in parse queue daemon) |
| Fidelity | Full kinematic: FL-specific ROCD + wind + ISA + speed schedules |
| UI surfaces | Both flight detail panel AND standalone page |
| Performance data | BADA PTF/APF (primary) with OpenAP/seed fallback |

### 1.3 Terminology

| Term | Meaning |
|------|---------|
| **TOC** | Top of Climb — waypoint where aircraft reaches cruise altitude |
| **TOD** | Top of Descent — waypoint where aircraft begins descent |
| **ROCD** | Rate of Climb/Descent (ft/min) — varies by flight level in BADA PTF |
| **CAS** | Calibrated Airspeed — what the pilot sees; differs from TAS with altitude |
| **TAS** | True Airspeed — CAS corrected for air density; increases with altitude |
| **GS** | Groundspeed — TAS adjusted for wind; used for time/distance calculations |
| **Crossover** | Altitude where constant-CAS climb transitions to constant-Mach climb |
| **ISA** | International Standard Atmosphere — temperature model for speed conversion |
| **PTF** | Performance Table File — BADA data with ROCD at each flight level |
| **APF** | Airline Procedure File — BADA speed schedules for climb/cruise/descent |

---

## 2. Data Inventory: What We Have

Every item below has been verified against actual source files and migration DDL.

### 2.1 Aircraft Performance Data (BADA)

**Table: `aircraft_performance_ptf`** (migration `performance/002_bada_import_infrastructure.sql`, line 27)
```sql
-- FL-specific performance — the core of the kinematic model
aircraft_icao     NVARCHAR(8)     -- B738, A320, etc.
flight_level      INT             -- 0, 10, 20, ... 450
climb_tas_kts     INT             -- TAS during climb at this FL
climb_rocd_fpm    INT             -- Rate of climb at this FL (ft/min)
climb_fuel_kg_min DECIMAL(8,2)    -- Fuel burn during climb
cruise_tas_kts    INT             -- Cruise TAS at this FL
cruise_fuel_kg_min DECIMAL(8,2)   -- Cruise fuel burn
descent_tas_kts   INT             -- TAS during descent at this FL
descent_rocd_fpm  INT             -- Rate of descent at this FL (ft/min, negative)
descent_fuel_kg_min DECIMAL(8,2)  -- Descent fuel burn
mass_category     NCHAR(1)        -- L=low, N=nominal, H=high
```
**Parser**: `scripts/bada/bada_ptf_parser.py` — `parse_ptf_file()` extracts `climb_rocd_fpm` from parts[2], `descent_rocd_fpm` from parts[9].

**Table: `aircraft_performance_apf`** (migration `performance/002_bada_import_infrastructure.sql`, line 123)
```sql
-- Speed schedules — determines CAS/Mach at each altitude band
climb_cas_1_kts     INT     -- Below 10,000 ft (typically 250 KIAS)
climb_cas_2_kts     INT     -- 10,000 ft to crossover altitude
climb_mach          DECIMAL(3,2) -- Above crossover altitude
climb_crossover_ft  INT     -- CAS→Mach transition altitude
cruise_cas_kts      INT     -- Cruise CAS (below crossover)
cruise_mach         DECIMAL(3,2) -- Cruise Mach
descent_mach        DECIMAL(3,2) -- High-altitude descent Mach
descent_cas_1_kts   INT     -- Descent CAS (crossover → 10,000 ft)
descent_cas_2_kts   INT     -- Below 10,000 ft (typically 250 KIAS)
descent_crossover_ft INT    -- Descent Mach→CAS transition altitude
approach_cas_kts    INT     -- Final approach speed
```
**Parser**: `scripts/bada/bada_apf_parser.py`

**Table: `aircraft_performance_opf`** (migration `performance/002_bada_import_infrastructure.sql`, line 70)
```sql
-- Aircraft limits and coefficients
vmo_kts         INT          -- Max operating CAS
mmo             DECIMAL(3,2) -- Max operating Mach
max_altitude_ft INT          -- Service ceiling
vstall_cr       INT          -- Clean stall speed
mass_ref_kg     INT          -- Reference mass (for BADA computations)
```

**Table: `aircraft_performance_profiles`** (migration `performance/001_aircraft_performance_seed.sql`)
```sql
-- Summary profiles (200+ aircraft, fallback hierarchy)
climb_rate_fpm     INT          -- Average climb rate
climb_speed_kias   INT
climb_speed_mach   DECIMAL(3,2)
descent_rate_fpm   INT          -- Average descent rate
descent_speed_kias INT
optimal_fl         INT
climb_crossover_ft INT          -- Added via ALTER TABLE in migration 002
descent_crossover_ft INT
weight_class       CHAR(1)      -- J, H, L, S
engine_type        NVARCHAR(8)  -- JET, TURBOPROP, PISTON, HELO
source             NVARCHAR(32) -- BADA, OPENAP, SEED, DEFAULT
```
**Fallback hierarchy**: `fn_GetAircraftPerformance(type)` → exact match → weight class default → _DEF_JL.

### 2.2 Wind Data

**Table: `wind_grid`** (migration `wind/001_wind_grid_schema.sql`)
```sql
lat              DECIMAL(5,2)   -- Grid location (5-degree resolution)
lon              DECIMAL(6,2)
pressure_hpa     INT            -- 200, 250, 300, 500, 700, 850, 925, 1000 hPa
wind_speed_kts   DECIMAL(5,1)
wind_dir_deg     SMALLINT       -- 0-360
wind_u_kts       DECIMAL(6,2)   -- East-west component
wind_v_kts       DECIMAL(6,2)   -- North-south component
valid_time_utc   DATETIME2(0)   -- When forecast is valid
```
**Refresh**: Every 6 hours via `services/` Python NOAA GFS fetcher.
**Coverage**: Global, 5-degree grid, 8 pressure levels.
**Lookup SP**: `sp_GetFlightWindAdjustment` — bilinear interpolation at grid point.

### 2.3 Waypoint Storage (Target)

**Table: `adl_flight_waypoints`** (migration `core/003_adl_waypoints_stepclimbs.sql`, line 25)
```sql
-- These columns EXIST but are NOT POPULATED — this feature fills them:
planned_alt_ft      INT NULL         -- line 43: altitude in feet at this waypoint
planned_speed_kts   INT NULL         -- line 44: planned speed at this waypoint
planned_speed_mach  DECIMAL(4,3) NULL -- line 45
is_step_climb_point BIT DEFAULT 0    -- line 48
is_toc              BIT DEFAULT 0    -- line 49: top of climb marker
is_tod              BIT DEFAULT 0    -- line 50: top of descent marker
is_constraint       BIT DEFAULT 0    -- line 51
constraint_type     NVARCHAR(16) NULL -- line 52: AT, AT_OR_ABOVE, AT_OR_BELOW, BETWEEN
```

### 2.4 Step Climb Data

**Table: `adl_flight_stepclimbs`** (migration `core/003_adl_waypoints_stepclimbs.sql`)
```sql
-- Parsed from route remarks (e.g., F390F410 or STEP/F390/F410)
altitude_ft      INT NOT NULL
waypoint_fix     NVARCHAR(64) NULL
dist_from_dep_nm DECIMAL(8,2) NULL
source           NVARCHAR(16)    -- ROUTE, REMARKS, COMPUTED
```

### 2.5 Trajectory Data (Flown Profile Source)

**Table: `adl_flight_trajectory`** (migration `core/002_adl_times_trajectory.sql`, line 170)
```sql
flight_uid       BIGINT
timestamp_utc    DATETIME2(0)
lat              DECIMAL(10,7)
lon              DECIMAL(11,7)
altitude_ft      INT NULL        -- ACTUAL altitude in feet
vertical_rate_fpm INT NULL       -- ACTUAL vertical rate
groundspeed_kts  INT NULL
heading_deg      SMALLINT NULL
```
**Resolution**: 15-second intervals.
**Retention**: Live → `adl_trajectory_archive` (downsampled) → blob storage via archival daemon.

### 2.6 Flight Math Utilities (Existing)

**File: `simulator/engine/src/math/flightMath.js`** — JavaScript, needs PHP port:
```javascript
// ISA temperature model (line 168-173)
function getIsaTemp(altitude) {
    if (altitude <= 36089) return 15 - (altitude * 0.001981);
    return -56.5;  // Tropopause constant
}

// Ground speed from TAS + wind (line 183-209)
function calculateGroundSpeed(tas, heading, windSpeed, windDir) { ... }

// Required vertical speed (line 214-223)
function requiredVerticalSpeed(currentAlt, targetAlt, distanceToTarget, groundSpeed) { ... }

// Top of descent distance (line 228-232)
function topOfDescentDistance(cruiseAlt, targetAlt, descentRate, groundSpeed) { ... }
```

### 2.7 What We Do NOT Have (Gaps to Fill)

| Gap | Impact | Solution |
|-----|--------|----------|
| `AltitudeProfileEngine` class | No altitude computation exists | Build it (this spec) |
| Waypoint `planned_alt_ft` population | Columns exist but are NULL | Engine fills them |
| SID/STAR altitude leg data | `nav_procedures` has route strings but no per-leg altitude constraints | Phase 2: Parse ARINC 424 altitude coding |
| Altitude profile API endpoint | No endpoint exists | New `api/adl/altitude-profile.php` |
| Altitude profile chart component | No visualization exists | New `assets/js/altitude-profile.js` |
| Standalone profile page | No page exists | New `flight-profile.php` |
| PHP ISA/speed conversion functions | Only exist in JS (`flightMath.js`) | Port to PHP in engine class |
| Pressure→altitude conversion | Wind grid uses hPa, profile uses feet | Standard atmosphere formula in engine |

---

## 3. Architecture

### 3.1 Processing Pipeline

```
Flight plan filed / route updated
        │
        ▼
┌─────────────────────────┐
│   adl_parse_queue       │  (existing)
│   status = PENDING      │
└───────────┬─────────────┘
            │
            ▼
┌─────────────────────────┐
│  parse_queue_gis_daemon │  (existing)
│  PostGIS expand_route() │
│  → inserts waypoints    │
└───────────┬─────────────┘
            │
            ▼  NEW STEP
┌─────────────────────────────────────────┐
│  AltitudeProfileEngine::compute()       │
│                                         │
│  1. Load aircraft performance (PTF/APF) │
│  2. Load wind grid at waypoint coords   │
│  3. Forward pass: climb profile         │
│  4. Backward pass: descent profile      │
│  5. Merge + step climbs                 │
│  6. UPDATE adl_flight_waypoints         │
│     SET planned_alt_ft, is_toc, is_tod, │
│         planned_speed_kts               │
└─────────────────────────────────────────┘
            │
            ▼
┌─────────────────────────┐
│  waypoint_eta_daemon    │  (existing, enhanced)
│  Now uses planned_alt   │
│  for altitude-aware ETA │
└─────────────────────────┘
```

### 3.2 File Layout

```
lib/
  AltitudeProfileEngine.php      # Core computation engine (NEW)
  FlightMath.php                 # ISA, CAS↔TAS, GS calculation (NEW, ported from flightMath.js)

api/adl/
  altitude-profile.php           # REST endpoint: planned + flown profile data (NEW)

api/swim/v1/flights/
  profile.php                    # SWIM API: altitude profile for consumers (NEW)

assets/js/
  altitude-profile.js            # Chart.js visualization component (NEW)

flight-profile.php               # Standalone profile page (NEW)

adl/php/
  parse_queue_gis_daemon.php     # MODIFIED: call AltitudeProfileEngine after waypoint insert
```

### 3.3 Data Flow

```
               ┌─────────────┐
               │ BADA PTF    │ FL-specific ROCD
               │ BADA APF    │ Speed schedules
               │ BADA OPF    │ Aircraft limits
               └──────┬──────┘
                      │
    ┌─────────────────┼─────────────────────┐
    │                 │                     │
    ▼                 ▼                     ▼
┌─────────┐   ┌─────────────┐      ┌────────────┐
│ Flight  │   │ Altitude    │      │ wind_grid  │
│ Plan    │   │ Profile     │◄─────│ (GFS data) │
│ (route, │   │ Engine      │      └────────────┘
│  type,  │──►│             │
│  FL)    │   │ Outputs:    │
└─────────┘   │ alt_ft[]    │
              │ is_toc      │
    ┌─────────│ is_tod      │
    │         │ speed_kts[] │
    │         └──────┬──────┘
    │                │
    ▼                ▼
┌─────────┐   ┌──────────────┐     ┌──────────────────┐
│ adl_    │   │ adl_flight_  │     │ API / Chart.js   │
│ flight_ │   │ waypoints    │────►│ Visualization    │
│ step-   │   │ (planned_    │     │ (planned + flown │
│ climbs  │   │  alt_ft)     │     │  overlay)        │
│         │   └──────────────┘     └──────────────────┘
└─────────┘         ▲
                    │ flown comparison
              ┌──────────────┐
              │ adl_flight_  │
              │ trajectory   │
              │ (altitude_ft)│
              └──────────────┘
```

---

## 4. Algorithm: Full Kinematic Altitude Profile

### 4.1 Speed Schedule Model

The speed at each altitude follows the BADA APF speed schedule:

```
Altitude Band              Speed Source           Typical Value
─────────────────────────  ─────────────────────  ─────────────
0 → 1,500 ft AGL          V2 + 10 (vstall_to)    ~150-170 KIAS
1,500 ft → 10,000 ft      climb_cas_1_kts        250 KIAS (FAA limit)
10,000 ft → crossover_ft  climb_cas_2_kts        280-310 KIAS
crossover_ft → cruise FL   climb_mach             M0.78-0.84
Cruise                     cruise_mach            M0.78-0.85
Cruise → crossover_ft     descent_mach           M0.78-0.84
crossover_ft → 10,000 ft  descent_cas_1_kts      280-310 KIAS
10,000 ft → 3,000 ft      descent_cas_2_kts      250 KIAS (FAA limit)
3,000 ft → 0              approach_cas_kts       ~130-150 KIAS
```

### 4.2 CAS ↔ TAS ↔ Mach Conversion

CAS increases to TAS as air density decreases with altitude. All methods are static on the `FlightMath` class (namespace `PERTI\Lib`).

```php
namespace PERTI\Lib;

class FlightMath
{
    // ISA sea-level constants
    const P0    = 101325.0;  // Pa
    const T0    = 288.15;    // K (15 C)
    const A0    = 340.294;   // m/s (speed of sound at sea level)
    const GAMMA = 1.4;       // Ratio of specific heats (air)
    const R_AIR = 287.05;    // J/(kg*K) specific gas constant

    /**
     * ISA temperature at altitude (Kelvin).
     * Below tropopause: lapse rate 0.0065 K/m = 0.0019812 K/ft.
     * Above tropopause (36,089 ft): constant 216.65 K (-56.5 C).
     */
    public static function isaTemperature(float $altitude_ft): float
    {
        if ($altitude_ft <= 36089) {
            return self::T0 - (0.0019812 * $altitude_ft);
        }
        return 216.65;
    }

    /**
     * ISA density ratio (sigma = rho/rho0) at altitude.
     */
    public static function isaDensityRatio(float $altitude_ft): float
    {
        $T = self::isaTemperature($altitude_ft);
        if ($altitude_ft <= 36089) {
            $delta = pow($T / self::T0, 5.2559);  // Pressure ratio
        } else {
            $delta = 0.22336 * exp(-0.0000480634 * ($altitude_ft - 36089));
        }
        return $delta * (self::T0 / $T);
    }

    /**
     * Speed of sound at altitude (knots).
     */
    public static function speedOfSound(float $altitude_ft): float
    {
        $T = self::isaTemperature($altitude_ft);
        // a = sqrt(gamma * R * T), convert m/s to knots (* 1.94384)
        return sqrt(self::GAMMA * self::R_AIR * $T) * 1.94384;
    }

    /**
     * Convert CAS to TAS at given altitude.
     * TAS = CAS / sqrt(sigma)
     */
    public static function casToTas(float $cas_kts, float $altitude_ft): float
    {
        $sigma = self::isaDensityRatio($altitude_ft);
        return ($sigma > 0) ? $cas_kts / sqrt($sigma) : $cas_kts;
    }

    /**
     * Convert TAS to CAS at given altitude.
     * CAS = TAS * sqrt(sigma)
     */
    public static function tasToCas(float $tas_kts, float $altitude_ft): float
    {
        $sigma = self::isaDensityRatio($altitude_ft);
        return $tas_kts * sqrt($sigma);
    }

    /**
     * Convert Mach number to TAS at given altitude.
     * TAS = Mach * speed_of_sound
     */
    public static function machToTas(float $mach, float $altitude_ft): float
    {
        return $mach * self::speedOfSound($altitude_ft);
    }

    /**
     * Convert Mach number to CAS at given altitude.
     * Used when speed schedule transitions from CAS to Mach above crossover.
     */
    public static function machToCas(float $mach, float $altitude_ft): float
    {
        $tas = self::machToTas($mach, $altitude_ft);
        return self::tasToCas($tas, $altitude_ft);
    }

    /**
     * Compute groundspeed given TAS, track, and wind.
     * Returns associative array with 'groundSpeed' and 'headwind' keys.
     * Ported from simulator/engine/src/math/flightMath.js lines 183-209.
     */
    public static function calculateGroundSpeed(
        float $tas, float $track_deg, float $wind_speed, float $wind_dir_deg
    ): array {
        if ($wind_speed <= 0) {
            return ['groundSpeed' => $tas, 'headwind' => 0.0];
        }

        $trackRad   = deg2rad($track_deg);
        $windDirRad = deg2rad($wind_dir_deg);

        // Wind components (from-direction convention)
        $windX = -$wind_speed * sin($windDirRad);
        $windY = -$wind_speed * cos($windDirRad);

        $gsX = $tas * sin($trackRad) + $windX;
        $gsY = $tas * cos($trackRad) + $windY;

        $gs = sqrt($gsX * $gsX + $gsY * $gsY);

        return [
            'groundSpeed' => $gs,
            'headwind'    => $tas - $gs,  // Positive = headwind, negative = tailwind
        ];
    }

    // --- Altitude/pressure conversion methods from Section 4.3 go here ---
    // pressureToAltitude(), altitudeToNearestPressureLevel()
}
```

### 4.3 Pressure Level ↔ Altitude Conversion

For wind_grid lookups (grid uses pressure_hpa, profile uses feet):

```php
/**
 * Standard atmosphere: pressure (hPa) → altitude (ft).
 * Used to match wind_grid pressure levels to flight altitudes.
 */
public static function pressureToAltitude(float $pressure_hpa): float
{
    // Below tropopause (> 226.32 hPa, < 36,089 ft)
    if ($pressure_hpa > 226.32) {
        return 145442.16 * (1.0 - pow($pressure_hpa / 1013.25, 0.190263));
    }
    // Above tropopause
    return 36089.24 + 48312.0 * log(226.32 / $pressure_hpa);
}

/**
 * Altitude (ft) → nearest pressure level for wind_grid lookup.
 * Returns one of: 200, 250, 300, 500, 700, 850, 925, 1000 hPa.
 */
public static function altitudeToNearestPressureLevel(float $altitude_ft): int
{
    // Standard atmosphere altitude↔pressure mapping
    $levels = [
        200 => 38662,  // ~FL387
        250 => 33999,  // ~FL340
        300 => 30065,  // ~FL301
        500 => 18289,  // ~FL183
        700 => 9882,   // ~FL099
        850 => 4781,   // ~FL048
        925 => 2500,   // ~FL025
        1000 => 364,   // ~FL004
    ];

    $bestLevel = 1000;
    $bestDiff = PHP_INT_MAX;
    foreach ($levels as $hpa => $alt) {
        $diff = abs($altitude_ft - $alt);
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $bestLevel = $hpa;
        }
    }
    return $bestLevel;
}
```

### 4.4 Core Algorithm: Two-Pass Profile Computation

```php
class AltitudeProfileEngine
{
    private $conn_adl;          // Azure SQL connection (sqlsrv)
    private array $ptfCache = [];
    private array $apfCache = [];
    private array $windCache = [];

    /**
     * Compute altitude profile for a flight.
     *
     * @param int $flight_uid Flight UID
     * @param array $waypoints Ordered waypoint array from DB
     * @param string $aircraft_icao ICAO type designator
     * @param int $filed_alt_ft Filed cruise altitude in feet
     * @param array $stepClimbs Optional step climb array
     * @return array Modified waypoints with planned_alt_ft populated
     */
    public function compute(
        int $flight_uid,
        array $waypoints,
        string $aircraft_icao,
        int $filed_alt_ft,
        array $stepClimbs = []
    ): array {
        if (count($waypoints) < 2) return $waypoints;

        // 1. Load performance data (with fallback chain)
        $ptf  = $this->loadPTF($aircraft_icao);
        $apf  = $this->loadAPF($aircraft_icao);
        $perf = $this->loadSummaryProfile($aircraft_icao);

        // 2. Compute inter-waypoint distances (great circle, nm)
        $distances = $this->computeDistances($waypoints);
        $totalDist = array_sum($distances);

        // 3. Forward pass: CLIMB profile
        $climbProfile = $this->computeClimbProfile(
            $waypoints, $distances, $ptf, $apf, $perf, $filed_alt_ft
        );

        // 4. Backward pass: DESCENT profile
        $descentProfile = $this->computeDescentProfile(
            $waypoints, $distances, $ptf, $apf, $perf, $filed_alt_ft
        );

        // 5. Merge climb + cruise + descent
        $merged = $this->mergeProfiles(
            $waypoints, $climbProfile, $descentProfile,
            $filed_alt_ft, $stepClimbs
        );

        // 6. Apply wind adjustments to speeds
        $merged = $this->applyWindAdjustments($merged);

        return $merged;
    }
```

### 4.5 Forward Pass: Climb Profile

```php
    /**
     * Compute climb profile from departure to cruise altitude.
     * Uses FL-specific ROCD from BADA PTF when available.
     */
    private function computeClimbProfile(
        array $waypoints,
        array $distances,
        ?array $ptf,
        ?array $apf,
        array $perf,
        int $filed_alt_ft
    ): array {
        $profile = [];
        $current_alt = 0;    // Start at ground level
        $profile[0] = ['alt' => 0, 'speed_kts' => 0, 'phase' => 'GROUND'];

        // Speed schedule from APF (or fallback to summary profile)
        $cas1 = $apf['climb_cas_1_kts'] ?? 250;          // Below 10,000 ft
        $cas2 = $apf['climb_cas_2_kts'] ?? $perf['climb_speed_kias'];
        $mach = $apf['climb_mach'] ?? $perf['climb_speed_mach'];
        $crossover = $apf['climb_crossover_ft']
                     ?? $perf['climb_crossover_ft']
                     ?? 28000;

        for ($i = 1; $i < count($waypoints); $i++) {
            if ($current_alt >= $filed_alt_ft) {
                // Already at cruise — mark TOC at previous waypoint
                if (!isset($tocIndex)) {
                    $tocIndex = $i - 1;
                    $profile[$tocIndex]['is_toc'] = true;
                }
                $profile[$i] = [
                    'alt'   => $filed_alt_ft,
                    'speed_kts' => $this->getCruiseSpeed($apf, $perf, $filed_alt_ft),
                    'phase' => 'CRUISE',
                ];
                continue;
            }

            $seg_dist_nm = $distances[$i - 1];

            // Get speed for current altitude band
            if ($current_alt < 10000) {
                $cas = $cas1;
            } elseif ($current_alt < $crossover) {
                $cas = $cas2;
            } else {
                // Above crossover: use Mach → convert to CAS at current alt
                $cas = FlightMath::machToCas($mach, $current_alt);
            }

            $tas = FlightMath::casToTas($cas, $current_alt);

            // Get climb rate at current FL (from PTF or summary)
            $rocd = $this->getClimbROCD($ptf, $perf, $current_alt);

            // Time to traverse this segment
            $seg_time_min = ($seg_dist_nm / $tas) * 60;

            // Altitude gain during this segment
            $alt_gain = $rocd * $seg_time_min;

            // Don't exceed filed altitude
            $new_alt = min($current_alt + $alt_gain, $filed_alt_ft);

            $profile[$i] = [
                'alt'       => (int) round($new_alt),
                'speed_kts' => (int) round($tas),
                'speed_cas' => (int) round($cas),
                'phase'     => ($new_alt >= $filed_alt_ft) ? 'CRUISE' : 'CLIMB',
            ];

            // Mark TOC if we just reached cruise
            if ($new_alt >= $filed_alt_ft && !isset($tocIndex)) {
                $tocIndex = $i;
                $profile[$i]['is_toc'] = true;
            }

            $current_alt = $new_alt;
        }

        return $profile;
    }

    /**
     * Get climb ROCD at a specific altitude.
     * PTF gives FL-specific values; falls back to summary profile average.
     */
    private function getClimbROCD(?array $ptf, array $perf, float $altitude_ft): float
    {
        if ($ptf !== null) {
            $fl = (int) round($altitude_ft / 100);
            // Find closest FL in PTF data
            $bestFl = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($ptf as $row) {
                $diff = abs($row['flight_level'] - $fl);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestFl = $row;
                }
            }
            if ($bestFl && $bestFl['climb_rocd_fpm'] > 0) {
                return (float) $bestFl['climb_rocd_fpm'];
            }
        }
        // Fallback: summary profile average rate
        return (float) ($perf['climb_rate_fpm'] ?? 2500);
    }
```

### 4.6 Backward Pass: Descent Profile

```php
    /**
     * Compute descent profile from destination backward to find TOD.
     * Mirror of climb logic but working in reverse.
     */
    private function computeDescentProfile(
        array $waypoints,
        array $distances,
        ?array $ptf,
        ?array $apf,
        array $perf,
        int $filed_alt_ft
    ): array {
        $profile = [];
        $n = count($waypoints);
        $current_alt = 0;   // Start at destination ground level
        $profile[$n - 1] = ['alt' => 0, 'speed_kts' => 0, 'phase' => 'GROUND'];

        // Descent speed schedule
        $mach_desc  = $apf['descent_mach'] ?? $perf['climb_speed_mach'] ?? 0.78;
        $cas1_desc  = $apf['descent_cas_1_kts'] ?? $perf['descent_speed_kias'] ?? 280;
        $cas2_desc  = $apf['descent_cas_2_kts'] ?? 250;
        $crossover  = $apf['descent_crossover_ft']
                      ?? $perf['descent_crossover_ft']
                      ?? 28000;
        $approach   = $apf['approach_cas_kts'] ?? 140;

        $todIndex = null;

        for ($i = $n - 2; $i >= 0; $i--) {
            if ($current_alt >= $filed_alt_ft) {
                // Reached cruise altitude from below — mark TOD
                if ($todIndex === null) {
                    $todIndex = $i + 1;
                    $profile[$i + 1]['is_tod'] = true;
                }
                $profile[$i] = [
                    'alt'   => $filed_alt_ft,
                    'speed_kts' => $this->getCruiseSpeed($apf, $perf, $filed_alt_ft),
                    'phase' => 'CRUISE',
                ];
                continue;
            }

            $seg_dist_nm = $distances[$i];  // distance from waypoint i to i+1

            // Speed at current altitude
            if ($current_alt < 3000) {
                $cas = $approach;
            } elseif ($current_alt < 10000) {
                $cas = $cas2_desc;
            } elseif ($current_alt < $crossover) {
                $cas = $cas1_desc;
            } else {
                $cas = FlightMath::machToCas($mach_desc, $current_alt);
            }

            $tas = FlightMath::casToTas($cas, $current_alt);

            // Get descent rate at current FL
            $rocd = $this->getDescentROCD($ptf, $perf, $current_alt);

            // Time to traverse this segment (backwards)
            $seg_time_min = ($seg_dist_nm / $tas) * 60;

            // Altitude gain (climbing backwards = ascending from destination)
            $alt_gain = $rocd * $seg_time_min;
            $new_alt = min($current_alt + $alt_gain, $filed_alt_ft);

            $profile[$i] = [
                'alt'       => (int) round($new_alt),
                'speed_kts' => (int) round($tas),
                'phase'     => ($new_alt >= $filed_alt_ft) ? 'CRUISE' : 'DESCENT',
            ];

            if ($new_alt >= $filed_alt_ft && $todIndex === null) {
                $todIndex = $i;
                $profile[$i]['is_tod'] = true;
            }

            $current_alt = $new_alt;
        }

        return $profile;
    }
```

### 4.7 Profile Merge

```php
    /**
     * Merge climb and descent profiles.
     * Rule: at each waypoint, use the LOWER of climb and descent altitudes.
     * This naturally produces the correct profile shape:
     *   - Where climb < cruise: climbing
     *   - Where descent < cruise: descending
     *   - Where both >= cruise: cruising
     */
    private function mergeProfiles(
        array $waypoints,
        array $climbProfile,
        array $descentProfile,
        int $filed_alt_ft,
        array $stepClimbs
    ): array {
        $merged = [];

        for ($i = 0; $i < count($waypoints); $i++) {
            $climb_alt   = $climbProfile[$i]['alt']   ?? $filed_alt_ft;
            $descent_alt = $descentProfile[$i]['alt']  ?? $filed_alt_ft;

            // Use the LOWER altitude (still climbing or already descending)
            $alt = min($climb_alt, $descent_alt);

            // Determine phase
            if ($alt < $filed_alt_ft && isset($climbProfile[$i]) && $climbProfile[$i]['phase'] === 'CLIMB') {
                $phase = 'CLIMB';
                $speed = $climbProfile[$i]['speed_kts'] ?? 0;
            } elseif ($alt < $filed_alt_ft && isset($descentProfile[$i]) && $descentProfile[$i]['phase'] === 'DESCENT') {
                $phase = 'DESCENT';
                $speed = $descentProfile[$i]['speed_kts'] ?? 0;
            } else {
                $phase = 'CRUISE';
                $speed = $climbProfile[$i]['speed_kts']
                    ?? $descentProfile[$i]['speed_kts']
                    ?? 0;
            }

            $merged[$i] = [
                'waypoint_id'       => $waypoints[$i]['waypoint_id'],
                'fix_name'          => $waypoints[$i]['fix_name'],
                'lat'               => $waypoints[$i]['lat'],
                'lon'               => $waypoints[$i]['lon'],
                'sequence_num'      => $waypoints[$i]['sequence_num'],
                'planned_alt_ft'    => (int) $alt,
                'planned_speed_kts' => (int) $speed,
                'is_toc'            => ($climbProfile[$i]['is_toc'] ?? false)
                                       && $phase !== 'DESCENT',
                'is_tod'            => ($descentProfile[$i]['is_tod'] ?? false)
                                       && $phase !== 'CLIMB',
                'phase'             => $phase,
            ];
        }

        // Apply step climbs: override cruise altitude at specified waypoints
        foreach ($stepClimbs as $sc) {
            if ($sc['waypoint_fix']) {
                foreach ($merged as &$wp) {
                    if ($wp['fix_name'] === $sc['waypoint_fix'] && $wp['phase'] === 'CRUISE') {
                        $wp['planned_alt_ft'] = $sc['altitude_ft'];
                        $wp['is_step_climb_point'] = true;
                        break;
                    }
                }
            }
        }

        return $merged;
    }
```

### 4.8 Wind Adjustment

```php
    /**
     * Apply wind adjustments to compute groundspeed at each waypoint.
     * Uses bilinear interpolation from wind_grid table.
     */
    private function applyWindAdjustments(array $merged): array
    {
        foreach ($merged as &$wp) {
            if ($wp['planned_alt_ft'] <= 0) continue;

            // Get wind at this position and altitude
            $wind = $this->getWindAtPosition(
                $wp['lat'], $wp['lon'], $wp['planned_alt_ft']
            );

            if ($wind === null) continue;

            // Compute track from this waypoint to next (or use heading)
            $track = $wp['track_deg'] ?? 0;

            // Compute groundspeed
            $gs = FlightMath::calculateGroundSpeed(
                $wp['planned_speed_kts'],  // TAS
                $track,
                $wind['speed_kts'],
                $wind['dir_deg']
            );

            $wp['groundspeed_kts'] = (int) round($gs['groundSpeed']);
            $wp['wind_speed_kts']  = $wind['speed_kts'];
            $wp['wind_dir_deg']    = $wind['dir_deg'];
            $wp['headwind_kts']    = (int) round(
                $wp['planned_speed_kts'] - $gs['groundSpeed']
            );
        }

        return $merged;
    }

    /**
     * Bilinear interpolation from wind_grid at given position and altitude.
     */
    private function getWindAtPosition(
        float $lat, float $lon, float $alt_ft
    ): ?array {
        $pressure = FlightMath::altitudeToNearestPressureLevel($alt_ft);

        // Snap to 5-degree grid
        $lat0 = floor($lat / 5) * 5;
        $lon0 = floor($lon / 5) * 5;

        $cacheKey = "{$lat0}_{$lon0}_{$pressure}";
        if (isset($this->windCache[$cacheKey])) {
            return $this->windCache[$cacheKey];
        }

        // Query 4 surrounding grid points
        $sql = "
            SELECT lat, lon, wind_u_kts, wind_v_kts, wind_speed_kts, wind_dir_deg
            FROM dbo.wind_grid WITH (NOLOCK)
            WHERE lat BETWEEN ? AND ?
              AND lon BETWEEN ? AND ?
              AND pressure_hpa = ?
              AND valid_time_utc = (
                  SELECT MAX(valid_time_utc)
                  FROM dbo.wind_grid WITH (NOLOCK)
                  WHERE valid_time_utc <= SYSUTCDATETIME()
                    AND pressure_hpa = ?
              )
        ";
        $params = [$lat0, $lat0 + 5, $lon0, $lon0 + 5, $pressure, $pressure];
        $stmt = sqlsrv_query($this->conn_adl, $sql, $params);

        if (!$stmt) return null;

        $points = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $points[] = $row;
        }
        sqlsrv_free_stmt($stmt);

        if (empty($points)) return null;

        // Simple average of available grid points (bilinear if 4 points)
        $u = $v = 0;
        foreach ($points as $p) {
            $u += $p['wind_u_kts'];
            $v += $p['wind_v_kts'];
        }
        $u /= count($points);
        $v /= count($points);

        $result = [
            'speed_kts' => sqrt($u * $u + $v * $v),
            'dir_deg'   => (int) round(fmod(atan2(-$u, -$v) * 180 / M_PI + 360, 360)),
        ];

        $this->windCache[$cacheKey] = $result;
        return $result;
    }
```

### 4.9 Database Persistence

```php
    /**
     * Persist computed altitude profile to adl_flight_waypoints.
     * Uses per-waypoint UPDATEs (typically 5-80 per flight).
     * Could be optimized with a TVP batch pattern if profiling shows need.
     */
    public function persist(int $flight_uid, array $profile): void
    {
        // Batch update: one statement per waypoint
        foreach ($profile as $wp) {
            $sql = "
                UPDATE dbo.adl_flight_waypoints
                SET planned_alt_ft    = ?,
                    planned_speed_kts = ?,
                    is_toc            = ?,
                    is_tod            = ?,
                    is_step_climb_point = ?
                WHERE waypoint_id = ?
            ";
            $params = [
                $wp['planned_alt_ft'],
                $wp['planned_speed_kts'],
                ($wp['is_toc'] ?? false) ? 1 : 0,
                ($wp['is_tod'] ?? false) ? 1 : 0,
                ($wp['is_step_climb_point'] ?? false) ? 1 : 0,
                $wp['waypoint_id'],
            ];
            sqlsrv_query($this->conn_adl, $sql, $params);
        }
    }
```

### 4.10 Helper Methods (Signatures)

The following methods are used by `compute()` but their implementation is straightforward DB lookups. Signatures shown here; full implementation left to the developer.

```php
    // --- Data loading (all use $this->conn_adl) ---

    /** Load ordered waypoints for a flight. */
    public function loadWaypoints(int $flight_uid): array
    // SELECT waypoint_id, sequence_num, fix_name, lat, lon, ...
    // FROM dbo.adl_flight_waypoints WHERE flight_uid = ? ORDER BY sequence_num

    /** Load flight metadata (aircraft type, filed altitude). */
    public function loadFlightInfo(int $flight_uid): ?array
    // SELECT fp.aircraft_icao, ft.cruise_alt_ft AS filed_alt_ft, ...
    // FROM adl_flight_plan fp JOIN adl_flight_times ft ON ...
    // NOTE: filed altitude may be in adl_flight_plan.altitude (string "FL390")
    //       or adl_flight_times depending on schema. Verify column name at impl time.

    /** Load step climbs parsed from route/remarks. */
    public function loadStepClimbs(int $flight_uid): array
    // SELECT * FROM dbo.adl_flight_stepclimbs WHERE flight_uid = ? ORDER BY step_sequence

    /** Load BADA PTF data for aircraft type (cached per type). */
    private function loadPTF(string $aircraft_icao): ?array
    // SELECT * FROM dbo.aircraft_performance_ptf WHERE aircraft_icao = ? ORDER BY flight_level

    /** Load BADA APF speed schedule (cached per type). */
    private function loadAPF(string $aircraft_icao): ?array
    // SELECT * FROM dbo.aircraft_performance_apf WHERE aircraft_icao = ?

    /** Load summary profile with fallback chain. */
    private function loadSummaryProfile(string $aircraft_icao): array
    // Uses fn_GetAircraftPerformance or manual fallback:
    // 1. Exact aircraft_icao match
    // 2. Weight-class default (_DEF_JH, _DEF_JL, etc.)
    // 3. Hardcoded _DEF_JL fallback

    // --- Computation helpers ---

    /** Great-circle distances between consecutive waypoints (nm). */
    private function computeDistances(array $waypoints): array
    // Haversine formula for each consecutive pair

    /** Cruise TAS from APF Mach or summary profile. */
    private function getCruiseSpeed(?array $apf, array $perf, int $alt_ft): float
    // machToTas(cruise_mach, alt_ft) or summary cruise_speed_ktas

    /** Descent ROCD at altitude (mirrors getClimbROCD). */
    private function getDescentROCD(?array $ptf, array $perf, float $alt_ft): float
    // Same pattern as getClimbROCD but uses descent_rocd_fpm column

    /** Find waypoint index by fix name. */
    private function findWaypointByFix(array $merged, string $fix_name): ?int
```

---

## 5. Daemon Integration

### 5.1 Parse Queue Daemon Modification

In `adl/php/parse_queue_gis_daemon.php`, after waypoints are inserted:

```php
// --- EXISTING CODE (after GIS parsing completes) ---
$this->log("Parsed {$waypointCount} waypoints for flight {$flight_uid}");

// --- NEW: Compute altitude profile ---
try {
    $engine = new AltitudeProfileEngine($this->conn_adl);

    // Load waypoints we just inserted
    $waypoints = $engine->loadWaypoints($flight_uid);

    // Get flight info for profile computation
    $flightInfo = $engine->loadFlightInfo($flight_uid);

    if ($flightInfo && count($waypoints) >= 2) {
        // Load step climbs if any
        $stepClimbs = $engine->loadStepClimbs($flight_uid);

        // Compute profile
        $profile = $engine->compute(
            $flight_uid,
            $waypoints,
            $flightInfo['aircraft_icao'],
            $flightInfo['filed_alt_ft'],
            $stepClimbs
        );

        // Persist to DB
        $engine->persist($flight_uid, $profile);

        $tocIdx = null;
        $todIdx = null;
        foreach ($profile as $i => $wp) {
            if ($wp['is_toc'] ?? false) $tocIdx = $i;
            if ($wp['is_tod'] ?? false) $todIdx = $i;
        }

        $this->log(sprintf(
            "  Altitude profile: %d waypoints, TOC at #%s (%d ft), TOD at #%s, cruise FL%d",
            count($profile),
            $tocIdx ?? '?',
            $profile[$tocIdx ?? 0]['planned_alt_ft'] ?? 0,
            $todIdx ?? '?',
            $flightInfo['filed_alt_ft'] / 100
        ));
    }
} catch (\Throwable $e) {
    $this->log("  Altitude profile error: " . $e->getMessage());
    // Non-fatal: flight still parsed, just without altitude profile
}
```

### 5.2 Performance Considerations

| Metric | Estimate | Basis |
|--------|----------|-------|
| Waypoints per flight | 5-80 (median ~25) | From `adl_flight_waypoints` statistics |
| PTF lookup | ~1ms (cached) | Single query per aircraft type, cached in memory |
| Wind lookup | ~2ms per waypoint | 5-degree grid, indexed, cached |
| Profile computation | ~1-5ms | Two passes over waypoints, basic math |
| DB update | ~5-15ms | 25 individual UPDATEs per flight |
| **Total overhead** | **~10-25ms per flight** | Negligible vs parse time (~200ms PostGIS) |

The engine adds <15% overhead to the existing parse queue cycle. Wind lookups are the most expensive part and are cached aggressively (5-degree grid = few unique keys per flight).

---

## 6. API Endpoints

### 6.1 Altitude Profile Endpoint

**`api/adl/altitude-profile.php`**

```php
<?php
/**
 * Altitude Profile API
 *
 * GET /api/adl/altitude-profile.php?flight_uid=12345
 *
 * Returns planned altitude profile + flown trajectory for Chart.js visualization.
 */

include("../../load/config.php");
include("../../load/connect.php");

header('Content-Type: application/json');

$flight_uid = (int) ($_GET['flight_uid'] ?? 0);
if ($flight_uid <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'flight_uid required']);
    exit;
}

$conn = get_conn_adl();

// --- 1. Load planned profile (waypoints with altitude) ---
$sql = "
    SELECT w.sequence_num, w.fix_name, w.lat, w.lon,
           w.planned_alt_ft, w.planned_speed_kts,
           w.is_toc, w.is_tod, w.is_step_climb_point,
           w.eta_utc
    FROM dbo.adl_flight_waypoints w
    WHERE w.flight_uid = ?
    ORDER BY w.sequence_num
";
$stmt = sqlsrv_query($conn, $sql, [$flight_uid]);
$planned = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $planned[] = [
        'name'  => $row['fix_name'],
        'lat'   => (float) $row['lat'],
        'lon'   => (float) $row['lon'],
        'alt'   => $row['planned_alt_ft'] !== null ? (int) $row['planned_alt_ft'] : null,
        'speed' => $row['planned_speed_kts'] !== null ? (int) $row['planned_speed_kts'] : null,
        'is_toc' => (bool) $row['is_toc'],
        'is_tod' => (bool) $row['is_tod'],
        'is_step' => (bool) $row['is_step_climb_point'],
        'eta'   => $row['eta_utc'] ? $row['eta_utc']->format('Y-m-d\TH:i:s\Z') : null,
    ];
}
sqlsrv_free_stmt($stmt);

// --- 2. Load flown trajectory ---
$sql = "
    SELECT t.lat, t.lon, t.altitude_ft, t.groundspeed_kts,
           t.vertical_rate_fpm, t.timestamp_utc
    FROM dbo.adl_flight_trajectory t
    WHERE t.flight_uid = ?
      AND t.altitude_ft IS NOT NULL
    ORDER BY t.timestamp_utc
";
$stmt = sqlsrv_query($conn, $sql, [$flight_uid]);
$flown = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $flown[] = [
        (float) $row['lat'],
        (float) $row['lon'],
        (int) $row['altitude_ft'],
        $row['timestamp_utc']->format('Y-m-d\TH:i:s\Z'),
    ];
}
sqlsrv_free_stmt($stmt);

// --- 3. Load aircraft performance info ---
$sql = "
    SELECT fp.aircraft_icao, ft.filed_alt_ft,
           p.climb_rate_fpm, p.descent_rate_fpm,
           p.cruise_mach, p.optimal_fl, p.source AS perf_source
    FROM dbo.adl_flight_plan fp
    LEFT JOIN dbo.adl_flight_times ft ON ft.flight_uid = fp.flight_uid
    LEFT JOIN dbo.aircraft_performance_profiles p ON p.aircraft_icao = fp.aircraft_icao
    WHERE fp.flight_uid = ?
";
$stmt = sqlsrv_query($conn, $sql, [$flight_uid]);
$perfRow = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($stmt);

$performance = $perfRow ? [
    'aircraft'        => $perfRow['aircraft_icao'],
    'cruise_fl'       => $perfRow['filed_alt_ft'] ? (int) $perfRow['filed_alt_ft'] / 100 : null,
    'climb_rate_fpm'  => $perfRow['climb_rate_fpm'] ? (int) $perfRow['climb_rate_fpm'] : null,
    'descent_rate_fpm'=> $perfRow['descent_rate_fpm'] ? (int) $perfRow['descent_rate_fpm'] : null,
    'cruise_mach'     => $perfRow['cruise_mach'] ? (float) $perfRow['cruise_mach'] : null,
    'source'          => $perfRow['perf_source'],
] : null;

echo json_encode([
    'flight_uid'  => $flight_uid,
    'planned'     => $planned,
    'flown'       => $flown,
    'performance' => $performance,
    'computed_utc' => date('Y-m-d\TH:i:s\Z'),
]);
```

### 6.2 SWIM API Endpoint

**`api/swim/v1/flights/profile.php`**

```php
<?php
/**
 * SWIM Flight Altitude Profile
 * GET /api/swim/v1/flights/profile.php?callsign=UAL123
 *
 * FIXM-aligned altitude profile for SWIM consumers.
 */
include("../../../../load/config.php");
include("../../../../load/connect.php");
include("../../../../load/swim_config.php");

// SWIM API key auth
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!validateSwimApiKey($apiKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

header('Content-Type: application/json');

$callsign = $_GET['callsign'] ?? '';
$flight_uid = (int) ($_GET['flight_uid'] ?? 0);

$conn = get_conn_adl();

// Resolve callsign to flight_uid if needed
if ($callsign && !$flight_uid) {
    $sql = "SELECT TOP 1 flight_uid FROM dbo.adl_flight_core
            WHERE callsign = ? AND is_active = 1
            ORDER BY first_seen_utc DESC";
    $stmt = sqlsrv_query($conn, $sql, [$callsign]);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $flight_uid = $row ? (int) $row['flight_uid'] : 0;
    sqlsrv_free_stmt($stmt);
}

if (!$flight_uid) {
    http_response_code(404);
    echo json_encode(['error' => 'Flight not found']);
    exit;
}

// Delegate to internal API
$_GET['flight_uid'] = $flight_uid;
include(__DIR__ . '/../../../adl/altitude-profile.php');
```

---

## 7. Frontend Visualization

### 7.1 Altitude Profile Chart Component

**`assets/js/altitude-profile.js`**

```javascript
/**
 * AltitudeProfile — Chart.js component for planned vs flown altitude profiles.
 *
 * Inspired by CDM (cdm.vatsimspain.es) dual-axis approach:
 *   - xCat (category): planned waypoint names on top axis
 *   - xLin (linear):   projected flown positions on hidden axis
 *   - y:               Flight Level
 *
 * Usage:
 *   const profile = new AltitudeProfile('canvas-id');
 *   const data = await fetch(`/api/adl/altitude-profile.php?flight_uid=${uid}`);
 *   profile.render(await data.json());
 */

class AltitudeProfile {
    constructor(canvasId, options = {}) {
        this.canvas = document.getElementById(canvasId);
        this.chart = null;
        this.options = {
            showRestrictions: options.showRestrictions ?? false,
            theme: options.theme ?? 'dark',
            ...options,
        };
    }

    // ----------------------------------------------------------------
    // Projection: map flown lat/lon onto planned route's x-axis
    // (Ported directly from CDM — verified against source)
    // ----------------------------------------------------------------

    _toXY(lat, lon, lat0Rad) {
        const R = 6371000;
        const x = R * (lon * Math.PI / 180) * Math.cos(lat0Rad);
        const y = R * (lat * Math.PI / 180);
        return [x, y];
    }

    _projectPointToSegment(px, py, ax, ay, bx, by) {
        const vx = bx - ax, vy = by - ay;
        const wx = px - ax, wy = py - ay;
        const vv = vx * vx + vy * vy;
        let t = vv > 0 ? (wx * vx + wy * vy) / vv : 0;
        t = Math.max(0, Math.min(1, t));
        const qx = ax + t * vx, qy = ay + t * vy;
        const dx = px - qx, dy = py - qy;
        return { t, d2: dx * dx + dy * dy };
    }

    _projectFlownToPlanned(planned, flown) {
        if (!planned.length || !flown.length) return [];

        const lat0 = planned[0].lat;
        const lat0Rad = lat0 * Math.PI / 180;

        // Convert planned waypoints to XY
        const plannedXY = planned.map(p => {
            const [x, y] = this._toXY(p.lat, p.lon, lat0Rad);
            return { x, y };
        });

        // Project each flown point
        const projected = [];
        for (const fp of flown) {
            const [px, py] = this._toXY(fp[0], fp[1], lat0Rad);
            let best = { seg: -1, t: 0, d2: Infinity };

            for (let i = 0; i < plannedXY.length - 1; i++) {
                const A = plannedXY[i], B = plannedXY[i + 1];
                const pr = this._projectPointToSegment(px, py, A.x, A.y, B.x, B.y);
                if (pr.d2 < best.d2) best = { seg: i, t: pr.t, d2: pr.d2 };
            }

            if (best.seg >= 0) {
                projected.push({
                    x: best.seg + best.t,
                    y: fp[2] / 100,  // Convert feet to FL
                });
            }
        }
        return projected;
    }

    // ----------------------------------------------------------------
    // Chart rendering
    // ----------------------------------------------------------------

    render(data) {
        if (this.chart) this.chart.destroy();

        const { planned, flown, performance } = data;
        if (!planned || !planned.length) return;

        // Extract arrays
        const labels = planned.map(p => p.name);
        const altitudes = planned.map(p => p.alt !== null ? p.alt / 100 : null); // FL

        // Project flown points onto planned route
        const flownProjected = this._projectFlownToPlanned(planned, flown || []);

        // Find TOC/TOD indices
        const tocIdx = planned.findIndex(p => p.is_toc);
        const todIdx = planned.findIndex(p => p.is_tod);

        // Build datasets
        const datasets = [
            {
                label: PERTII18n.t('altitudeProfile.planned'),
                data: altitudes,
                borderColor: '#6aa6ff',
                backgroundColor: 'rgba(106, 166, 255, 0.12)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                pointHoverRadius: 5,
                xAxisID: 'xCat',
                order: 2,
            },
        ];

        if (flownProjected.length > 0) {
            datasets.push({
                label: PERTII18n.t('altitudeProfile.flown'),
                data: flownProjected,
                borderColor: '#f6c343',
                backgroundColor: 'rgba(246, 195, 67, 0.12)',
                tension: 0.3,
                showLine: true,
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 6,
                xAxisID: 'xLin',
                order: 1,
            });
        }

        // TOC/TOD annotation points
        if (tocIdx >= 0) {
            datasets.push({
                label: 'TOC',
                data: [{ x: tocIdx, y: altitudes[tocIdx] }],
                borderColor: '#4caf50',
                backgroundColor: '#4caf50',
                pointStyle: 'triangle',
                pointRadius: 8,
                showLine: false,
                xAxisID: 'xLin',
                order: 0,
            });
        }
        if (todIdx >= 0) {
            datasets.push({
                label: 'TOD',
                data: [{ x: todIdx, y: altitudes[todIdx] }],
                borderColor: '#ff9800',
                backgroundColor: '#ff9800',
                pointStyle: 'rectRot',
                pointRadius: 8,
                showLine: false,
                xAxisID: 'xLin',
                order: 0,
            });
        }

        // Create chart
        const isDark = this.options.theme === 'dark';
        const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
        const textColor = isDark ? '#ccc' : '#333';

        this.chart = new Chart(this.canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                animation: false,
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        labels: { color: textColor },
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const fl = ctx.parsed.y;
                                if (fl === null || fl === undefined) return '';
                                return `${ctx.dataset.label}: FL${Math.round(fl)} (${Math.round(fl * 100)} ft)`;
                            },
                        },
                    },
                },
                scales: {
                    xCat: {
                        type: 'category',
                        position: 'top',
                        ticks: {
                            color: textColor,
                            maxRotation: 90,
                            minRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 30,
                        },
                        grid: { color: gridColor },
                    },
                    xLin: {
                        type: 'linear',
                        display: false,
                        min: 0,
                        max: labels.length - 1,
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: PERTII18n.t('altitudeProfile.yAxisLabel'),
                            color: textColor,
                        },
                        ticks: { color: textColor },
                        grid: { color: gridColor },
                    },
                },
            },
        });
    }

    destroy() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
```

### 7.2 i18n Keys

Add to `assets/locales/en-US.json`:

```json
{
    "altitudeProfile": {
        "title": "Altitude Profile",
        "planned": "Planned Profile",
        "flown": "Flown Profile",
        "yAxisLabel": "Flight Level (FL)",
        "noData": "No altitude profile available",
        "noFlown": "No flown data available",
        "toc": "Top of Climb",
        "tod": "Top of Descent",
        "cruise": "Cruise",
        "climb": "Climb",
        "descent": "Descent",
        "aircraft": "Aircraft",
        "filedFL": "Filed FL",
        "perfSource": "Performance Source",
        "windAdj": "Wind Adjusted"
    }
}
```

### 7.3 Standalone Page

**`flight-profile.php`**

```php
<?php
/**
 * Standalone altitude profile page.
 * URL: /flight-profile.php?uid=12345 or ?callsign=UAL123
 */
include("load/config.php");
include("sessions/handler.php");
include("load/connect.php");
include("load/header.php");
?>

<div class="container-fluid mt-3">
    <div class="row">
        <div class="col-12">
            <div class="card bg-dark">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0" id="profile-title">
                        <?= htmlspecialchars($_GET['callsign'] ?? 'Flight Profile') ?>
                    </h5>
                    <div>
                        <span id="perf-badge" class="badge badge-secondary mr-2"></span>
                        <span id="cruise-badge" class="badge badge-info mr-2"></span>
                    </div>
                </div>
                <div class="card-body p-2">
                    <div style="height: 400px; position: relative;">
                        <canvas id="altitude-profile-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-8">
            <div class="card bg-dark">
                <div class="card-header">Route Map</div>
                <div class="card-body p-0">
                    <div id="profile-map" style="height: 400px;"></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark">
                <div class="card-header">Waypoint Details</div>
                <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-sm table-dark table-striped mb-0" id="waypoint-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fix</th>
                                <th>FL</th>
                                <th>Speed</th>
                                <th>Phase</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/altitude-profile.js"></script>
<script>
(function() {
    const uid = new URLSearchParams(location.search).get('uid');
    const cs  = new URLSearchParams(location.search).get('callsign');
    const param = uid ? `flight_uid=${uid}` : `callsign=${cs}`;

    fetch(`/api/adl/altitude-profile.php?${param}`)
        .then(r => r.json())
        .then(data => {
            // Render chart
            const profile = new AltitudeProfile('altitude-profile-chart', { theme: 'dark' });
            profile.render(data);

            // Update header badges
            if (data.performance) {
                document.getElementById('perf-badge').textContent =
                    `${data.performance.aircraft} (${data.performance.source || 'SEED'})`;
                document.getElementById('cruise-badge').textContent =
                    `FL${data.performance.cruise_fl || '???'}`;
            }

            // Populate waypoint table
            const tbody = document.querySelector('#waypoint-table tbody');
            (data.planned || []).forEach((wp, i) => {
                const fl = wp.alt !== null ? Math.round(wp.alt / 100) : '—';
                const phase = wp.is_toc ? 'TOC' : wp.is_tod ? 'TOD' : wp.is_step ? 'STEP' : '';
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${i + 1}</td>
                    <td>${wp.name}</td>
                    <td>${fl}</td>
                    <td>${wp.speed || '—'}</td>
                    <td><span class="badge badge-${phase === 'TOC' ? 'success' : phase === 'TOD' ? 'warning' : 'secondary'}">${phase}</span></td>
                `;
                tbody.appendChild(tr);
            });

            // Render map (uses MapLibre from existing route-maplibre.js patterns)
            if (typeof maplibregl !== 'undefined' && data.planned.length > 0) {
                const map = new maplibregl.Map({
                    container: 'profile-map',
                    style: 'https://basemaps.cartocdn.com/gl/dark-matter-gl-style/style.json',
                    center: [data.planned[0].lon, data.planned[0].lat],
                    zoom: 4,
                });

                map.on('load', () => {
                    // Planned route (blue)
                    const plannedCoords = data.planned
                        .filter(p => p.lat && p.lon)
                        .map(p => [p.lon, p.lat]);
                    map.addSource('planned-route', {
                        type: 'geojson',
                        data: { type: 'Feature', geometry: { type: 'LineString', coordinates: plannedCoords } },
                    });
                    map.addLayer({
                        id: 'planned-route-line',
                        type: 'line',
                        source: 'planned-route',
                        paint: { 'line-color': '#6aa6ff', 'line-width': 3 },
                    });

                    // Flown route (yellow)
                    if (data.flown && data.flown.length > 0) {
                        const flownCoords = data.flown.map(f => [f[1], f[0]]);
                        map.addSource('flown-route', {
                            type: 'geojson',
                            data: { type: 'Feature', geometry: { type: 'LineString', coordinates: flownCoords } },
                        });
                        map.addLayer({
                            id: 'flown-route-line',
                            type: 'line',
                            source: 'flown-route',
                            paint: { 'line-color': '#f6c343', 'line-width': 2.5 },
                        });
                    }

                    // Fit bounds
                    const bounds = new maplibregl.LngLatBounds();
                    plannedCoords.forEach(c => bounds.extend(c));
                    map.fitBounds(bounds, { padding: 40 });
                });
            }
        })
        .catch(err => console.error('Profile load failed:', err));
})();
</script>

<?php include("load/footer.php"); ?>
```

---

## 8. Phase 2: SID/STAR Altitude Constraints (Future)

### 8.1 What This Would Add

Currently the profile engine uses aircraft performance for smooth climb/descent curves. Real SID/STAR procedures have altitude constraints at specific waypoints (e.g., "cross DUCEN at or above FL150", "cross OXMAN at FL100").

### 8.2 Data Source

The ARINC 424 CIFP data that PERTI already parses for nav_procedures contains altitude coding per leg, but the current parser discards it. The `XP12Parser.parse_cifp_procedures()` function in `nasr_navdata_updater.py` would need to extract:

- **Altitude 1/2 fields** (columns 84-89 in ARINC 424 format)
- **Altitude description** (column 83): `+` (at or above), `-` (at or below), `@` (at), `B` (between)

### 8.3 Schema Addition

```sql
-- New table for procedure altitude/speed constraints
CREATE TABLE dbo.nav_procedure_legs (
    leg_id          INT IDENTITY PRIMARY KEY,
    procedure_id    INT NOT NULL,             -- FK to nav_procedures
    sequence_num    INT NOT NULL,
    fix_name        NVARCHAR(32) NOT NULL,

    -- Altitude constraints
    alt_desc        CHAR(1) NULL,             -- +, -, @, B
    altitude_1_ft   INT NULL,                 -- Primary altitude
    altitude_2_ft   INT NULL,                 -- Secondary (for BETWEEN)

    -- Speed constraints
    speed_limit_kts INT NULL,
    speed_desc      CHAR(1) NULL,             -- +, -, @ (above, below, at)

    -- Leg type (ARINC 424)
    leg_type        CHAR(2) NULL,             -- IF, TF, CF, DF, etc.

    CONSTRAINT FK_proc_legs FOREIGN KEY (procedure_id)
        REFERENCES dbo.nav_procedures(procedure_id)
);
```

### 8.4 Engine Integration

The altitude profile engine would add a constraint enforcement pass after the climb/descent merge:

```php
// After mergeProfiles(), apply SID/STAR constraints
if ($depProcLegs) {
    foreach ($depProcLegs as $leg) {
        $wpIdx = $this->findWaypointByFix($merged, $leg['fix_name']);
        if ($wpIdx !== null) {
            $merged[$wpIdx] = $this->enforceConstraint(
                $merged[$wpIdx], $leg['alt_desc'],
                $leg['altitude_1_ft'], $leg['altitude_2_ft']
            );
        }
    }
}
```

---

## 9. Testing Strategy

### 9.1 Unit Testing (Manual)

Since PERTI has no automated test suite, validation is via manual API testing:

```bash
# Test altitude profile for a known flight
curl -s "https://perti.vatcscc.org/api/adl/altitude-profile.php?flight_uid=12345" | jq .

# Validate: planned array has alt values, is_toc/is_tod exist
# Validate: flown array has trajectory points
# Validate: performance shows correct aircraft type and source
```

### 9.2 Smoke Tests

| Test | Expected | How to Verify |
|------|----------|---------------|
| Short flight (KLAX-KSFO, ~300nm) | TOC and TOD may overlap (short cruise or none) | Check profile: no cruise segment |
| Long flight (KJFK-EGLL, ~3000nm) | Clear climb/cruise/descent phases | Check profile: TOC < 20% of route, TOD < 20% from end |
| Step climb (F370/F390/F410) | Altitude steps at specified waypoints | Check profile: is_step_climb_point = true |
| Turboprop (ATR72, FL250) | Lower cruise, slower climb/descent | Check profile: cruise FL250, lower speeds |
| Heavy (B777, FL390) | Slow climb rate, long climb | Check profile: TOC further from departure |
| Unknown aircraft | Fallback to _DEF_JL profile | Check performance.source = 'DEFAULT' |
| No waypoints | Engine returns empty, no crash | Check: empty planned array |

### 9.3 Comparison Validation

Compare PERTI profiles against CDM profiles for the same flights:
1. Pick a flight visible on both systems (e.g., European flight with CDM coverage)
2. Fetch PERTI profile via API
3. Fetch CDM profile from `cdm.vatsimspain.es/dashboard/cdm_apps/cdm-pilotRequest/index.php?callsign=X`
4. Compare: cruise FL should match, climb/descent shape should be similar (within 5-10% due to different performance models)

---

## 10. Rollout Plan

| Phase | Scope | Files | Effort |
|-------|-------|-------|--------|
| **1** | Core engine + daemon integration | `lib/FlightMath.php`, `lib/AltitudeProfileEngine.php`, daemon modification | 2-3 sessions |
| **2** | API endpoints | `api/adl/altitude-profile.php`, `api/swim/v1/flights/profile.php` | 1 session |
| **3** | Chart.js visualization | `assets/js/altitude-profile.js`, i18n keys | 1 session |
| **4** | Standalone page + flight detail integration | `flight-profile.php`, route.php integration | 1 session |
| **5** | SID/STAR constraints (future) | `nav_procedure_legs` table, CIFP parser extension | 2-3 sessions |

---

## 11. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| BADA PTF data not populated | Medium | Falls back to summary profile (less accurate) | Seed data + OpenAP provide reasonable defaults |
| Wind grid stale (>6h old) | Low | Slightly wrong GS estimates | Engine works without wind (TAS = GS fallback) |
| Profile computation too slow | Very Low | <25ms per flight | Cached performance lookups, simple math |
| Non-fatal: engine crash | Low | Flight parsed without altitude profile | try/catch in daemon, logged, non-blocking |
| Waypoint count > 200 | Rare | Slightly longer computation | Still <50ms; cap at 500 waypoints |
| PTF/APF tables empty in prod | Medium | Falls back to summary profile; kinematic benefit lost | Run BADA import before feature launch; seed data provides baseline |

---

## 12. Source Reference: CDM vs PERTI Comparison

| Capability | CDM (cdm.vatsimspain.es) | PERTI (this design) |
|-----------|--------------------------|---------------------|
| Performance model | EuroScope type-average | BADA FL-specific ROCD + APF speed schedules |
| Wind integration | None (filed TAS only, GS reconciliation) | GFS wind grid at 8 pressure levels |
| Speed schedule | Not documented | Full 4-phase: CAS1/CAS2/Mach/approach |
| Trajectory storage | None (live only) | 15-second resolution, archived |
| Charting library | Chart.js | Chart.js (same) |
| Projection algorithm | Equirectangular, segment-nearest | Same algorithm (ported) |
| Restriction bands | Airspace volume overlays | Phase 2 (SID/STAR constraints) |
| Step climbs | Not supported | Parsed from route remarks |
| Historical comparison | Not possible (no trajectory storage) | Compare planned vs flown days/weeks later |
| SID/STAR constraints | Via profile_restrictions.txt | Phase 2: ARINC 424 altitude coding |
