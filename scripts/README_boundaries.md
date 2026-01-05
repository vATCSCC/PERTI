# VATSIM Boundary Data Refresh Script

This script downloads and transforms boundary data from official VATSIM GitHub repositories to update the GeoJSON files used by the TSD map display.

## Data Sources

- **ARTCC/FIR Boundaries**: https://github.com/vatsimnetwork/vatspy-data-project (Boundaries.geojson)
- **TRACON Boundaries**: https://github.com/vatsimnetwork/simaware-tracon-project (TRACONBoundaries.geojson)

## Usage

```bash
# Run manually with verbose output
php refresh_vatsim_boundaries.php --verbose

# Dry run (doesn't save files, shows what would happen)
php refresh_vatsim_boundaries.php --dry-run --verbose

# Normal run (used by cron)
php refresh_vatsim_boundaries.php
```

## Output Files

- `assets/geojson/artcc.json` - FIR/ARTCC worldwide boundaries
- `assets/geojson/tracon.json` - TRACON/APP worldwide boundaries
- `assets/geojson/backup/` - Automatic backups (keeps last 5)

## Schema Transformation

### ARTCC/FIR (VATSpy → artcc.json)
| VATSpy Property | artcc.json Property |
|-----------------|---------------------|
| id | FIRname, ICAOCODE |
| region | VATSIM Reg |
| division | VATSIM Div |
| oceanic | oceanic (preserved) |
| label_lat, label_lon | label_lat, label_lon (preserved) |

### TRACON (SimAware → tracon.json)
| SimAware Property | tracon.json Property |
|-------------------|----------------------|
| id | sector, label |
| name | label |
| prefix[0] | artcc (derived) |
| geometry | Shape_Length, Shape_Area (calculated) |
| label_lat, label_lon | label_lat, label_lon (preserved) |

## Scheduling (Cron)

Recommended: Weekly updates aligned with AIRAC cycles

```cron
# Run every Sunday at 4 AM UTC
0 4 * * 0 /usr/bin/php /home/site/wwwroot/scripts/refresh_vatsim_boundaries.php >> /home/site/wwwroot/scripts/boundary_refresh.log 2>&1
```

## Notes

- The script creates backups before overwriting existing files
- Old backups are automatically cleaned up (keeps last 5)
- Both data sources update ~monthly following AIRAC cycles
- Exit code 0 = success, 1 = error
