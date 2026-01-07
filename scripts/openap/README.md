# OpenAP Aircraft Performance Import

Scripts for importing aircraft performance data from OpenAP (TU Delft) into PERTI.

## What is OpenAP?

[OpenAP](https://github.com/TUDelft-CNS-ATM/openap) is an open-source aircraft performance model developed by TU Delft. It provides:

- Aircraft properties (dimensions, weights, limits)
- Engine characteristics
- Drag polar models
- **WRAP kinematic data** (climb/descent rates derived from ADS-B data)

OpenAP is a free alternative to EUROCONTROL BADA, licensed under GPL-3.0.

## Data Sources

| Data Type | Source | Description |
|-----------|--------|-------------|
| Aircraft YAML | `/openap/data/aircraft/*.yml` | Dimensions, MTOW, VMO/MMO, cruise Mach |
| WRAP Kinematic | `/openap/data/wrap/*.csv` | Climb/descent rates, speed schedules |

## Quick Start

### Step 1: Install Dependencies

```bash
pip install requests pyyaml
```

### Step 2: Run the Import Script

```bash
python openap_import.py -o 047_openap_aircraft_import.sql
```

This downloads data from GitHub and generates a SQL file.

### Step 3: Run the SQL in SSMS

```sql
-- In SSMS, run the generated file:
-- adl/migrations/047_openap_aircraft_import.sql
```

## Command Line Options

| Option | Description |
|--------|-------------|
| `-o, --output` | Output SQL file path (default: `047_openap_aircraft_import.sql`) |
| `--json` | Also output raw data as JSON file |
| `-v, --verbose` | Show detailed output for each aircraft |

## Aircraft Covered

OpenAP includes ~50 aircraft types:

### Airbus
- A318, A319, A320, A321 (+ neo variants)
- A330-200/300/800/900
- A340-200/300/500/600
- A350-900/1000
- A380-800

### Boeing
- 737-200 through MAX 10
- 747-200/400/8
- 757-200/300
- 767-200/300/400
- 777-200/LR/300ER/8/9
- 787-8/9/10

### Other
- E170, E175, E190, E195
- Citation X (C56X)
- Gulfstream G650 (GLF6)

## Data Priority

The generated SQL uses MERGE with this priority:
1. **BADA** - Preserved (never overwritten)
2. **OPENAP** - Updated if not BADA
3. **SEED** - Manual seed data (045 migration)
4. **DEFAULT** - Category defaults

## WRAP Kinematic Data

The [WRAP model](https://github.com/junzis/wrap) provides real-world climb/descent performance derived from ADS-B surveillance data:

| Parameter | Description |
|-----------|-------------|
| `cl_v_cas_const` | Climb CAS (constant IAS phase) |
| `cl_v_mach_const` | Climb Mach (high altitude) |
| `cl_vs_avg_*` | Vertical speed in climb phases |
| `de_v_cas_const` | Descent CAS |
| `de_v_mach_const` | Descent Mach |
| `de_vs_avg_*` | Vertical speed in descent phases |
| `cr_v_mach_mean` | Cruise Mach (typical) |
| `fa_va_avg` | Final approach speed |

## Example Output

```
Processing A320... OK (✓ WRAP)
Processing B738... OK (✓ WRAP)
Processing B77W... OK (✓ WRAP)
...
Successfully processed: 48 aircraft
Generated SQL file: 047_openap_aircraft_import.sql
```

## Troubleshooting

### Network errors
- Ensure you have internet access
- GitHub may rate-limit requests; wait and retry

### Missing aircraft
- OpenAP doesn't cover all aircraft types
- Regional jets and turboprops have limited coverage
- Use SEED data (045 migration) for additional types

### WRAP data unavailable
- Not all aircraft have WRAP kinematic data
- Script uses aircraft YAML data alone for these

## References

- OpenAP GitHub: https://github.com/TUDelft-CNS-ATM/openap
- OpenAP Handbook: https://openap.dev
- WRAP Paper: Sun et al. (2019) "WRAP: An open-source kinematic aircraft performance model"
- OpenAP Paper: Sun et al. (2020) "OpenAP: An open-source aircraft performance model for air transportation studies and simulations"

## License

OpenAP is licensed under GPL-3.0. This import script is for internal PERTI use.
