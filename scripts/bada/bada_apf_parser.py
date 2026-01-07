#!/usr/bin/env python3
"""
bada_apf_parser.py
Parse EUROCONTROL BADA APF (Airline Procedures Files) and generate SQL inserts

APF files contain speed schedules for climb, cruise, and descent phases.
These define the standard airline operating procedures (CAS/Mach schedules).

Usage:
    python bada_apf_parser.py --input /path/to/bada/apf --output bada_apf_inserts.sql
"""

import os
import re
import argparse
from pathlib import Path
from datetime import datetime


def parse_apf_file(filepath):
    """
    Parse a single BADA APF file and extract speed schedule data.
    
    APF format contains blocks for:
    - Climb schedule (CAS1, CAS2, Mach)
    - Cruise schedule (CAS, Mach)  
    - Descent schedule (Mach, CAS1, CAS2)
    
    Returns dict with aircraft_icao and speed schedules
    """
    aircraft_icao = None
    data = {
        'climb_cas_1_kts': None,    # Below 10,000 ft
        'climb_cas_2_kts': None,    # 10,000 to crossover
        'climb_mach': None,
        'cruise_cas_kts': None,
        'cruise_mach': None,
        'descent_mach': None,
        'descent_cas_1_kts': None,  # Crossover to 10,000
        'descent_cas_2_kts': None,  # Below 10,000
        'approach_cas_kts': None,
    }
    
    # Extract ICAO code from filename
    filename = Path(filepath).stem
    aircraft_icao = filename.replace('_', '').strip()
    
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()
    
    # Parse climb schedule
    # Format varies but typically: CD climb CAS1 CAS2 Mach
    climb_match = re.search(r'climb.*?(\d{3})\s+(\d{3})\s+(0\.\d+)', content, re.IGNORECASE)
    if climb_match:
        data['climb_cas_1_kts'] = int(climb_match.group(1))
        data['climb_cas_2_kts'] = int(climb_match.group(2))
        data['climb_mach'] = float(climb_match.group(3))
    
    # Alternative format: look for CAS/Mach values on separate lines
    cas_matches = re.findall(r'V\s*=\s*(\d{3})', content)
    mach_matches = re.findall(r'M\s*=\s*(0\.\d+)', content)
    
    if cas_matches and len(cas_matches) >= 2:
        if data['climb_cas_1_kts'] is None:
            data['climb_cas_1_kts'] = int(cas_matches[0])
        if data['climb_cas_2_kts'] is None and len(cas_matches) > 1:
            data['climb_cas_2_kts'] = int(cas_matches[1])
    
    if mach_matches:
        if data['climb_mach'] is None:
            data['climb_mach'] = float(mach_matches[0])
        if data['cruise_mach'] is None and len(mach_matches) > 1:
            data['cruise_mach'] = float(mach_matches[1]) if len(mach_matches) > 1 else float(mach_matches[0])
    
    # Parse cruise schedule
    cruise_match = re.search(r'cruise.*?(\d{3})\s+(0\.\d+)', content, re.IGNORECASE)
    if cruise_match:
        data['cruise_cas_kts'] = int(cruise_match.group(1))
        data['cruise_mach'] = float(cruise_match.group(2))
    
    # Parse descent schedule  
    descent_match = re.search(r'descent.*?(0\.\d+)\s+(\d{3})\s+(\d{3})', content, re.IGNORECASE)
    if descent_match:
        data['descent_mach'] = float(descent_match.group(1))
        data['descent_cas_1_kts'] = int(descent_match.group(2))
        data['descent_cas_2_kts'] = int(descent_match.group(3))
    
    # Use climb values for cruise if not found
    if data['cruise_mach'] is None:
        data['cruise_mach'] = data['climb_mach']
    if data['cruise_cas_kts'] is None:
        data['cruise_cas_kts'] = data['climb_cas_2_kts']
    
    # Use climb values for descent if not found
    if data['descent_mach'] is None:
        data['descent_mach'] = data['climb_mach']
    if data['descent_cas_1_kts'] is None:
        data['descent_cas_1_kts'] = data['climb_cas_2_kts']
    if data['descent_cas_2_kts'] is None:
        data['descent_cas_2_kts'] = data['climb_cas_1_kts']
    
    return {
        'aircraft_icao': aircraft_icao,
        'data': data
    }


def generate_merge_statement(aircraft_data, bada_revision):
    """Generate SQL MERGE statement for APF data"""
    
    def sql_val(v):
        if v is None:
            return 'NULL'
        elif isinstance(v, float):
            return f'{v:.2f}'
        else:
            return str(v)
    
    icao = aircraft_data['aircraft_icao']
    d = aircraft_data['data']
    
    sql = f"""-- {icao}
MERGE dbo.aircraft_performance_apf AS target
USING (SELECT '{icao}' AS aircraft_icao) AS source
ON target.aircraft_icao = source.aircraft_icao
WHEN MATCHED THEN UPDATE SET
    climb_cas_1_kts = {sql_val(d['climb_cas_1_kts'])},
    climb_cas_2_kts = {sql_val(d['climb_cas_2_kts'])},
    climb_mach = {sql_val(d['climb_mach'])},
    cruise_cas_kts = {sql_val(d['cruise_cas_kts'])},
    cruise_mach = {sql_val(d['cruise_mach'])},
    descent_mach = {sql_val(d['descent_mach'])},
    descent_cas_1_kts = {sql_val(d['descent_cas_1_kts'])},
    descent_cas_2_kts = {sql_val(d['descent_cas_2_kts'])},
    bada_revision = '{bada_revision}',
    created_utc = SYSUTCDATETIME()
WHEN NOT MATCHED THEN INSERT 
    (aircraft_icao, climb_cas_1_kts, climb_cas_2_kts, climb_mach, cruise_cas_kts, cruise_mach,
     descent_mach, descent_cas_1_kts, descent_cas_2_kts, bada_revision, source)
VALUES ('{icao}', {sql_val(d['climb_cas_1_kts'])}, {sql_val(d['climb_cas_2_kts'])}, 
        {sql_val(d['climb_mach'])}, {sql_val(d['cruise_cas_kts'])}, {sql_val(d['cruise_mach'])},
        {sql_val(d['descent_mach'])}, {sql_val(d['descent_cas_1_kts'])}, {sql_val(d['descent_cas_2_kts'])},
        '{bada_revision}', 'BADA');

"""
    return sql


def process_bada_directory(input_dir, output_file, bada_revision='3.12'):
    """Process all APF files in a directory"""
    input_path = Path(input_dir)
    apf_files = list(input_path.glob('*.APF')) + list(input_path.glob('*.apf'))
    
    print(f"Found {len(apf_files)} APF files in {input_dir}")
    
    all_sql = [f"""-- ============================================================================
-- BADA APF Import - Generated {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
-- BADA Revision: {bada_revision}
-- Files processed: {len(apf_files)}
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT 'Importing BADA {bada_revision} APF (speed schedule) data...';
GO

"""]
    
    aircraft_count = 0
    
    for apf_file in sorted(apf_files):
        try:
            result = parse_apf_file(apf_file)
            
            if result['aircraft_icao']:
                sql = generate_merge_statement(result, bada_revision)
                all_sql.append(sql)
                aircraft_count += 1
                
                d = result['data']
                mach_str = f"M{d['climb_mach']:.2f}" if d['climb_mach'] else "N/A"
                cas_str = f"{d['climb_cas_2_kts']}kt" if d['climb_cas_2_kts'] else "N/A"
                print(f"  Processed: {result['aircraft_icao']} (CAS: {cas_str}, Mach: {mach_str})")
        except Exception as e:
            print(f"  Error processing {apf_file}: {e}")
    
    all_sql.append("""
PRINT 'BADA APF import complete.';
GO
""")
    
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write('\n'.join(all_sql))
    
    print(f"\nCompleted: {aircraft_count} aircraft")
    print(f"Output written to: {output_file}")
    
    return aircraft_count


def main():
    parser = argparse.ArgumentParser(description='Parse BADA APF files and generate SQL')
    parser.add_argument('--input', '-i', required=True, help='Input directory containing APF files')
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
