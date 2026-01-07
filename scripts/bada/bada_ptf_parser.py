#!/usr/bin/env python3
"""
bada_ptf_parser.py
Parse EUROCONTROL BADA PTF (Performance Table Files) and generate SQL inserts

BADA PTF Format (13 columns):
FL | Climb TAS | Climb ROCD | Climb Fuel | Cruise TAS | Cruise Fuel | | | Descent TAS | Descent ROCD | Descent Fuel | | |

Usage:
    python bada_ptf_parser.py --input /path/to/bada/ptf --output bada_inserts.sql
    python bada_ptf_parser.py --input /path/to/bada/ptf --output bada_inserts.sql --revision 3.12
"""

import os
import re
import argparse
from pathlib import Path
from datetime import datetime


def parse_ptf_file(filepath):
    """
    Parse a single BADA PTF file and extract performance data.
    
    PTF files have:
    - Header section with aircraft info
    - Data section with FL-specific performance
    
    Returns dict with aircraft_icao and list of performance records
    """
    aircraft_icao = None
    records = []
    
    # Extract ICAO code from filename (e.g., B738__.PTF -> B738)
    filename = Path(filepath).stem
    aircraft_icao = filename.replace('_', '').strip()
    
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        lines = f.readlines()
    
    in_data_section = False
    
    for line in lines:
        line = line.strip()
        
        # Skip empty lines and comments
        if not line or line.startswith('CC') or line.startswith('CD'):
            continue
        
        # Check for BADA header to get aircraft code
        if 'BADA' in line and 'AC' in line:
            # Try to extract aircraft code from header
            match = re.search(r'AC/\s*(\w+)', line)
            if match:
                aircraft_icao = match.group(1)
            continue
        
        # Look for data lines (start with FL number)
        # Format: FL | TAS | ROCD | fuel | TAS | fuel | ... (13 columns)
        parts = line.split()
        
        if len(parts) >= 10:
            try:
                # First column should be FL (0-500 range typically)
                fl = int(parts[0])
                if 0 <= fl <= 600:
                    record = {
                        'flight_level': fl,
                        'climb_tas_kts': safe_int(parts[1]) if len(parts) > 1 else None,
                        'climb_rocd_fpm': safe_int(parts[2]) if len(parts) > 2 else None,
                        'climb_fuel_kg_min': safe_float(parts[3]) if len(parts) > 3 else None,
                        'cruise_tas_kts': safe_int(parts[4]) if len(parts) > 4 else None,
                        'cruise_fuel_kg_min': safe_float(parts[5]) if len(parts) > 5 else None,
                        'descent_tas_kts': safe_int(parts[8]) if len(parts) > 8 else None,
                        'descent_rocd_fpm': safe_int(parts[9]) if len(parts) > 9 else None,
                        'descent_fuel_kg_min': safe_float(parts[10]) if len(parts) > 10 else None,
                    }
                    records.append(record)
            except (ValueError, IndexError):
                continue
    
    return {
        'aircraft_icao': aircraft_icao,
        'records': records
    }


def safe_int(val):
    """Convert to int, return None if invalid"""
    try:
        return int(float(val))
    except (ValueError, TypeError):
        return None


def safe_float(val):
    """Convert to float, return None if invalid"""
    try:
        return float(val)
    except (ValueError, TypeError):
        return None


def generate_merge_statement(aircraft_icao, records, bada_revision):
    """Generate a MERGE statement for batch import"""
    
    if not records:
        return ""
    
    values = []
    for r in records:
        def sql_val(v):
            if v is None:
                return 'NULL'
            elif isinstance(v, float):
                return f'{v:.2f}'
            else:
                return str(v)
        
        values.append(f"""    ('{aircraft_icao}', {r['flight_level']}, {sql_val(r['climb_tas_kts'])}, 
     {sql_val(r['climb_rocd_fpm'])}, {sql_val(r['climb_fuel_kg_min'])}, {sql_val(r['cruise_tas_kts'])}, 
     {sql_val(r['cruise_fuel_kg_min'])}, {sql_val(r['descent_tas_kts'])}, {sql_val(r['descent_rocd_fpm'])}, 
     {sql_val(r['descent_fuel_kg_min'])}, 'N', '{bada_revision}')""")
    
    values_sql = ',\n'.join(values)
    
    sql = f"""-- {aircraft_icao}
MERGE dbo.aircraft_performance_ptf AS target
USING (VALUES
{values_sql}
) AS source (aircraft_icao, flight_level, climb_tas_kts, climb_rocd_fpm, climb_fuel_kg_min,
             cruise_tas_kts, cruise_fuel_kg_min, descent_tas_kts, descent_rocd_fpm, 
             descent_fuel_kg_min, mass_category, bada_revision)
ON target.aircraft_icao = source.aircraft_icao 
   AND target.flight_level = source.flight_level 
   AND ISNULL(target.mass_category, 'N') = source.mass_category
WHEN MATCHED THEN UPDATE SET
    climb_tas_kts = source.climb_tas_kts, climb_rocd_fpm = source.climb_rocd_fpm,
    climb_fuel_kg_min = source.climb_fuel_kg_min, cruise_tas_kts = source.cruise_tas_kts,
    cruise_fuel_kg_min = source.cruise_fuel_kg_min, descent_tas_kts = source.descent_tas_kts,
    descent_rocd_fpm = source.descent_rocd_fpm, descent_fuel_kg_min = source.descent_fuel_kg_min,
    bada_revision = source.bada_revision, created_utc = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT 
    (aircraft_icao, flight_level, climb_tas_kts, climb_rocd_fpm, climb_fuel_kg_min,
     cruise_tas_kts, cruise_fuel_kg_min, descent_tas_kts, descent_rocd_fpm, 
     descent_fuel_kg_min, mass_category, bada_revision, source)
VALUES (source.aircraft_icao, source.flight_level, source.climb_tas_kts, source.climb_rocd_fpm,
        source.climb_fuel_kg_min, source.cruise_tas_kts, source.cruise_fuel_kg_min,
        source.descent_tas_kts, source.descent_rocd_fpm, source.descent_fuel_kg_min,
        source.mass_category, source.bada_revision, 'BADA');

"""
    return sql


def process_bada_directory(input_dir, output_file, bada_revision='3.12'):
    """
    Process all PTF files in a directory and generate SQL output
    """
    input_path = Path(input_dir)
    ptf_files = list(input_path.glob('*.PTF')) + list(input_path.glob('*.ptf'))
    
    print(f"Found {len(ptf_files)} PTF files in {input_dir}")
    
    all_sql = []
    all_sql.append(f"""-- ============================================================================
-- BADA PTF Import - Generated {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
-- BADA Revision: {bada_revision}
-- Files processed: {len(ptf_files)}
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT 'Importing BADA {bada_revision} PTF data...';
GO

""")
    
    aircraft_count = 0
    record_count = 0
    
    for ptf_file in sorted(ptf_files):
        try:
            result = parse_ptf_file(ptf_file)
            
            if result['aircraft_icao'] and result['records']:
                sql = generate_merge_statement(
                    result['aircraft_icao'], 
                    result['records'], 
                    bada_revision
                )
                all_sql.append(sql)
                aircraft_count += 1
                record_count += len(result['records'])
                print(f"  Processed: {result['aircraft_icao']} ({len(result['records'])} FLs)")
        except Exception as e:
            print(f"  Error processing {ptf_file}: {e}")
    
    # Add sync procedure call
    all_sql.append("""
-- ============================================================================
-- Sync PTF data to summary profiles
-- ============================================================================

EXEC dbo.sp_SyncBADA_ToProfiles;
GO

PRINT 'BADA PTF import complete.';
GO
""")
    
    # Write output
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write('\n'.join(all_sql))
    
    print(f"\nCompleted: {aircraft_count} aircraft, {record_count} FL records")
    print(f"Output written to: {output_file}")
    
    return aircraft_count, record_count


def main():
    parser = argparse.ArgumentParser(description='Parse BADA PTF files and generate SQL')
    parser.add_argument('--input', '-i', required=True, help='Input directory containing PTF files')
    parser.add_argument('--output', '-o', required=True, help='Output SQL file')
    parser.add_argument('--revision', '-r', default='3.12', help='BADA revision (default: 3.12)')
    
    args = parser.parse_args()
    
    if not os.path.isdir(args.input):
        print(f"Error: Input directory not found: {args.input}")
        return 1
    
    process_bada_directory(args.input, args.output, args.revision)
    return 0


if __name__ == '__main__':
    exit(main())
