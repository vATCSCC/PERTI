#!/usr/bin/env python3
"""
OpenAP Aircraft Performance Import Script (V2)
===============================================

Downloads aircraft performance data from OpenAP (TU Delft) and generates SQL
for import into PERTI aircraft_performance_profiles table.

This version correctly parses the WRAP kinematic .txt files.

OpenAP is an open-source alternative to EUROCONTROL BADA.
Source: https://github.com/TUDelft-CNS-ATM/openap

Usage:
    python openap_import.py -o openap_import.sql

Requirements:
    - Python 3.7+
    - requests (pip install requests)
    - pyyaml (pip install pyyaml)

License: OpenAP is GPL-3.0 licensed
"""

import argparse
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional, Any

try:
    import requests
except ImportError:
    print("ERROR: requests library required. Install with: pip install requests")
    sys.exit(1)

try:
    import yaml
except ImportError:
    print("ERROR: pyyaml library required. Install with: pip install pyyaml")
    sys.exit(1)


# OpenAP GitHub raw content URLs
OPENAP_BASE_URL = "https://raw.githubusercontent.com/TUDelft-CNS-ATM/openap/master/openap/data"

# Known aircraft types in OpenAP (as of 2024)
OPENAP_AIRCRAFT = [
    'a19n', 'a20n', 'a21n',
    'a318', 'a319', 'a320', 'a321',
    'a332', 'a333', 'a338', 'a339',
    'a342', 'a343', 'a345', 'a346',
    'a359', 'a35k',
    'a388',
    'b37m', 'b38m', 'b39m',
    'b732', 'b733', 'b734', 'b735', 'b736', 'b737', 'b738', 'b739',
    'b742', 'b744', 'b748',
    'b752', 'b753',
    'b762', 'b763', 'b764',
    'b772', 'b77l', 'b77w', 'b778', 'b779',
    'b788', 'b789', 'b78x',
    'c56x',
    'e170', 'e175', 'e190', 'e195', 'e75l', 'e75s',
    'glf6',
]

# Unit conversions
MS_TO_FPM = 196.85  # m/s to ft/min
MS_TO_KTS = 1.944   # m/s to knots
KM_TO_FT = 3280.84  # km to ft


def fetch_url(url: str) -> Optional[str]:
    """Fetch content from URL with error handling."""
    try:
        response = requests.get(url, timeout=30)
        if response.status_code == 200:
            return response.text
        return None
    except requests.RequestException:
        return None


def parse_aircraft_yaml(yaml_content: str, icao: str) -> Optional[Dict]:
    """Parse OpenAP aircraft YAML file and extract performance data."""
    try:
        data = yaml.safe_load(yaml_content)
        if not data:
            return None
        
        # Extract key performance parameters
        result = {
            'icao': icao.upper(),
            'name': data.get('aircraft', icao.upper()),
            
            # Speed limits
            'vmo_kts': data.get('vmo'),
            'mmo': data.get('mmo'),
            
            # Altitude (OpenAP stores in meters)
            'ceiling_ft': int(data.get('ceiling', 0) * 3.28084) if data.get('ceiling') else None,
            
            # Cruise performance (OpenAP stores height in meters)
            'cruise_mach': data.get('cruise', {}).get('mach'),
            'cruise_height_m': data.get('cruise', {}).get('height'),
            
            # Engine info
            'engine_type': data.get('engine', {}).get('type', '').upper() or 'JET',
            'num_engines': data.get('engine', {}).get('number', 2),
            
            # Weight class (derive from MTOW)
            'mtow_kg': data.get('mtow'),
            'weight_class': derive_weight_class(data.get('mtow')),
        }
        
        return result
        
    except yaml.YAMLError:
        return None


def derive_weight_class(mtow_kg: Optional[int]) -> str:
    """Derive FAA weight class from MTOW."""
    if not mtow_kg:
        return 'L'
    
    if mtow_kg >= 300000:
        return 'J'  # Super (A380, AN225)
    elif mtow_kg >= 136000:
        return 'H'  # Heavy (B777, A350, B747)
    elif mtow_kg >= 41000:
        return 'L'  # Large (B737, A320)
    else:
        return 'S'  # Small (regional jets, props)


def parse_wrap_txt(content: str) -> Dict[str, float]:
    """
    Parse WRAP kinematic .txt file.
    
    Format: variable, flight_phase, name, opt, min, max, model, parameters
    
    Units in WRAP:
    - Vertical rates: m/s
    - Airspeeds: m/s
    - Altitudes: km
    - Mach: dimensionless
    """
    result = {}
    
    lines = content.strip().split('\n')
    for line in lines[1:]:  # Skip header
        if not line.strip():
            continue
        
        # Parse fixed-width-ish format (split on whitespace, join description)
        parts = line.split()
        if len(parts) < 5:
            continue
        
        variable = parts[0]
        # The 'opt' value is typically at index -4
        try:
            opt_value = float(parts[-4])
            result[variable] = opt_value
        except (ValueError, IndexError):
            continue
    
    return result


def fetch_wrap_kinematic(icao: str) -> Optional[Dict]:
    """Fetch and parse WRAP kinematic data for aircraft."""
    # WRAP data is stored as .txt files
    url = f"{OPENAP_BASE_URL}/wrap/{icao.lower()}.txt"
    content = fetch_url(url)
    
    if not content:
        return None
    
    wrap_raw = parse_wrap_txt(content)
    if not wrap_raw:
        return None
    
    # Convert to our units
    result = {
        # Climb parameters (convert m/s to fpm, m/s to kts)
        'climb_cas_kts': int(wrap_raw.get('cl_v_cas_const', 0) * MS_TO_KTS) or None,
        'climb_mach': wrap_raw.get('cl_v_mach_const'),
        'climb_rate_pre_cas_fpm': int(wrap_raw.get('cl_vs_avg_pre_cas', 0) * MS_TO_FPM) or None,
        'climb_rate_cas_fpm': int(wrap_raw.get('cl_vs_avg_cas_const', 0) * MS_TO_FPM) or None,
        'climb_rate_mach_fpm': int(wrap_raw.get('cl_vs_avg_mach_const', 0) * MS_TO_FPM) or None,
        'climb_crossover_ft': int(wrap_raw.get('cl_h_cas_const', 0) * 1000 * 3.28084) or None,  # km*1000 to ft
        
        # Cruise parameters
        'cruise_mach_wrap': wrap_raw.get('cr_v_mach_mean'),
        'cruise_alt_ft': int(wrap_raw.get('cr_h_mean', 0) * KM_TO_FT) or None,
        
        # Descent parameters (make rates positive)
        'descent_mach': wrap_raw.get('de_v_mach_const'),
        'descent_cas_kts': int(wrap_raw.get('de_v_cas_const', 0) * MS_TO_KTS) or None,
        'descent_rate_mach_fpm': abs(int(wrap_raw.get('de_vs_avg_mach_const', 0) * MS_TO_FPM)) or None,
        'descent_rate_cas_fpm': abs(int(wrap_raw.get('de_vs_avg_cas_const', 0) * MS_TO_FPM)) or None,
        'descent_crossover_ft': int(wrap_raw.get('de_h_mach_const', 0) * 1000 * 3.28084) or None,
        
        # Approach (m/s to kts)
        'approach_speed_kts': int(wrap_raw.get('fa_va_avg', 0) * MS_TO_KTS) or None,
    }
    
    return result


def merge_aircraft_data(aircraft: Dict, wrap: Optional[Dict]) -> Dict:
    """Merge aircraft YAML data with WRAP kinematic data."""
    result = aircraft.copy()
    
    if wrap:
        # Use average of WRAP climb rates
        climb_rates = [r for r in [wrap.get('climb_rate_pre_cas_fpm'), 
                                   wrap.get('climb_rate_cas_fpm'),
                                   wrap.get('climb_rate_mach_fpm')] if r]
        if climb_rates:
            result['climb_rate_fpm'] = int(sum(climb_rates) / len(climb_rates))
        
        # Use average of WRAP descent rates
        descent_rates = [r for r in [wrap.get('descent_rate_mach_fpm'),
                                     wrap.get('descent_rate_cas_fpm')] if r]
        if descent_rates:
            result['descent_rate_fpm'] = int(sum(descent_rates) / len(descent_rates))
        
        # Speeds
        if wrap.get('climb_cas_kts'):
            result['climb_speed_kias'] = wrap['climb_cas_kts']
        if wrap.get('climb_mach'):
            result['climb_speed_mach'] = wrap['climb_mach']
        if wrap.get('descent_cas_kts'):
            result['descent_speed_kias'] = wrap['descent_cas_kts']
        
        # Crossover altitudes
        if wrap.get('climb_crossover_ft'):
            result['climb_crossover_ft'] = wrap['climb_crossover_ft']
        if wrap.get('descent_crossover_ft'):
            result['descent_crossover_ft'] = wrap['descent_crossover_ft']
        
        # Approach speed
        if wrap.get('approach_speed_kts'):
            result['approach_speed_kias'] = wrap['approach_speed_kts']
        
        # Use WRAP cruise data (preferred - from real ADS-B data)
        if wrap.get('cruise_mach_wrap'):
            result['cruise_mach'] = wrap['cruise_mach_wrap']
        if wrap.get('cruise_alt_ft'):
            result['optimal_fl'] = int(wrap['cruise_alt_ft'] / 100)
    
    # Calculate cruise TAS from Mach (at typical cruise altitude, M0.78 ≈ 450 KTAS)
    if result.get('cruise_mach'):
        result['cruise_speed_ktas'] = int(result['cruise_mach'] * 573)
    
    # Set optimal FL from YAML if not from WRAP
    if not result.get('optimal_fl') and result.get('cruise_height_m'):
        result['optimal_fl'] = int(result['cruise_height_m'] * 3.28084 / 100)
    
    return result


def generate_sql(aircraft_data: List[Dict], output_path: str):
    """Generate SQL migration file for importing aircraft data."""
    
    timestamp = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    
    sql_lines = [
        "-- ============================================================================",
        "-- 047_openap_aircraft_import.sql",
        "-- Import aircraft performance data from OpenAP (TU Delft)",
        "-- ",
        "-- Source: https://github.com/TUDelft-CNS-ATM/openap",
        "-- License: GPL-3.0",
        f"-- Generated: {timestamp}",
        "-- ",
        "-- OpenAP provides open-source aircraft performance models derived from",
        "-- ADS-B surveillance data (WRAP kinematic model).",
        "-- ============================================================================",
        "",
        "SET NOCOUNT ON;",
        "GO",
        "",
        "PRINT '=== Importing OpenAP Aircraft Performance Data ===';",
        f"PRINT 'Generated: {timestamp}';",
        f"PRINT 'Aircraft profiles: {len(aircraft_data)}';",
        "GO",
        "",
        "-- ============================================================================",
        "-- MERGE OpenAP data into aircraft_performance_profiles",
        "-- Uses COALESCE to fill gaps - preserves existing SEED/BADA values where OpenAP is NULL",
        "-- ============================================================================",
        "",
        "MERGE dbo.aircraft_performance_profiles AS target",
        "USING (VALUES",
    ]
    
    # Generate VALUES rows
    value_rows = []
    for ac in aircraft_data:
        icao = ac.get('icao', '').upper()
        if not icao:
            continue
            
        # Build values tuple
        values = [
            f"'{icao}'",
            str(ac.get('climb_rate_fpm') or 'NULL'),
            str(ac.get('climb_speed_kias') or 'NULL'),
            f"{ac.get('climb_speed_mach'):.3f}" if ac.get('climb_speed_mach') else 'NULL',
            str(ac.get('cruise_speed_ktas') or 'NULL'),
            f"{ac.get('cruise_mach'):.3f}" if ac.get('cruise_mach') else 'NULL',
            str(ac.get('descent_rate_fpm') or 'NULL'),
            str(ac.get('descent_speed_kias') or 'NULL'),
            str(ac.get('optimal_fl') or 'NULL'),
            f"'{ac.get('weight_class', 'L')}'",
            f"'{ac.get('engine_type', 'JET')}'",
            str(ac.get('climb_crossover_ft') or 'NULL'),
            str(ac.get('descent_crossover_ft') or 'NULL'),
            str(ac.get('ceiling_ft') or 'NULL'),
            str(ac.get('vmo_kts') or 'NULL'),
            f"{ac.get('mmo'):.2f}" if ac.get('mmo') else 'NULL',
            str(ac.get('approach_speed_kias') or 'NULL'),
        ]
        
        comment = f"-- {ac.get('name', icao)}"
        value_rows.append(f"    ({', '.join(values)}) {comment}")
    
    sql_lines.append(",\n".join(value_rows))
    
    sql_lines.extend([
        "",
        ") AS source (",
        "    aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach,",
        "    cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias,",
        "    optimal_fl, weight_class, engine_type, climb_crossover_ft,",
        "    descent_crossover_ft, max_altitude_ft, vmo_kts, mmo, approach_speed_kias",
        ")",
        "ON target.aircraft_icao = source.aircraft_icao",
        "",
        "-- Only update if current source is NOT BADA (preserve BADA data)",
        "WHEN MATCHED AND target.source NOT IN ('BADA') THEN",
        "    UPDATE SET",
        "        climb_rate_fpm = COALESCE(source.climb_rate_fpm, target.climb_rate_fpm),",
        "        climb_speed_kias = COALESCE(source.climb_speed_kias, target.climb_speed_kias),",
        "        climb_speed_mach = COALESCE(source.climb_speed_mach, target.climb_speed_mach),",
        "        cruise_speed_ktas = COALESCE(source.cruise_speed_ktas, target.cruise_speed_ktas),",
        "        cruise_mach = COALESCE(source.cruise_mach, target.cruise_mach),",
        "        descent_rate_fpm = COALESCE(source.descent_rate_fpm, target.descent_rate_fpm),",
        "        descent_speed_kias = COALESCE(source.descent_speed_kias, target.descent_speed_kias),",
        "        optimal_fl = COALESCE(source.optimal_fl, target.optimal_fl),",
        "        weight_class = COALESCE(source.weight_class, target.weight_class),",
        "        engine_type = COALESCE(source.engine_type, target.engine_type),",
        "        climb_crossover_ft = COALESCE(source.climb_crossover_ft, target.climb_crossover_ft),",
        "        descent_crossover_ft = COALESCE(source.descent_crossover_ft, target.descent_crossover_ft),",
        "        max_altitude_ft = COALESCE(source.max_altitude_ft, target.max_altitude_ft),",
        "        vmo_kts = COALESCE(source.vmo_kts, target.vmo_kts),",
        "        mmo = COALESCE(source.mmo, target.mmo),",
        "        approach_speed_kias = COALESCE(source.approach_speed_kias, target.approach_speed_kias),",
        "        source = 'OPENAP',",
        "        created_utc = SYSUTCDATETIME()",
        "",
        "WHEN NOT MATCHED THEN",
        "    INSERT (aircraft_icao, climb_rate_fpm, climb_speed_kias, climb_speed_mach,",
        "            cruise_speed_ktas, cruise_mach, descent_rate_fpm, descent_speed_kias,",
        "            optimal_fl, weight_class, engine_type, climb_crossover_ft,",
        "            descent_crossover_ft, max_altitude_ft, vmo_kts, mmo, approach_speed_kias,",
        "            source, created_utc)",
        "    VALUES (source.aircraft_icao, source.climb_rate_fpm, source.climb_speed_kias,",
        "            source.climb_speed_mach, source.cruise_speed_ktas, source.cruise_mach,",
        "            source.descent_rate_fpm, source.descent_speed_kias, source.optimal_fl,",
        "            source.weight_class, source.engine_type, source.climb_crossover_ft,",
        "            source.descent_crossover_ft, source.max_altitude_ft, source.vmo_kts,",
        "            source.mmo, source.approach_speed_kias, 'OPENAP', SYSUTCDATETIME());",
        "",
        "DECLARE @rows_affected INT = @@ROWCOUNT;",
        "PRINT CONCAT('OpenAP aircraft profiles: ', @rows_affected, ' rows affected');",
        "GO",
        "",
        "-- ============================================================================",
        "-- Verification",
        "-- ============================================================================",
        "",
        "PRINT '';",
        "PRINT '=== Profile Source Summary ===';",
        "",
        "SELECT ",
        "    source,",
        "    COUNT(*) AS profile_count,",
        "    AVG(cruise_speed_ktas) AS avg_cruise_kts,",
        "    AVG(climb_rate_fpm) AS avg_climb_fpm,",
        "    AVG(descent_rate_fpm) AS avg_descent_fpm",
        "FROM dbo.aircraft_performance_profiles",
        "GROUP BY source",
        "ORDER BY ",
        "    CASE source ",
        "        WHEN 'BADA' THEN 1",
        "        WHEN 'OPENAP' THEN 2", 
        "        WHEN 'SEED' THEN 3",
        "        WHEN 'DEFAULT' THEN 4",
        "        ELSE 5",
        "    END;",
        "",
        "PRINT '';",
        "PRINT '=== OpenAP Aircraft Performance Details ===';",
        "",
        "SELECT ",
        "    aircraft_icao,",
        "    climb_rate_fpm,",
        "    climb_speed_kias,",
        "    cruise_speed_ktas,",
        "    cruise_mach,",
        "    descent_rate_fpm,",
        "    descent_speed_kias,",
        "    optimal_fl,",
        "    approach_speed_kias,",
        "    weight_class",
        "FROM dbo.aircraft_performance_profiles",
        "WHERE source = 'OPENAP'",
        "ORDER BY aircraft_icao;",
        "",
        "PRINT '';",
        "PRINT '=== OpenAP Import Complete ===';",
        "GO",
    ])
    
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(sql_lines))
    
    print(f"\nGenerated SQL file: {output_path}")


def main():
    parser = argparse.ArgumentParser(
        description='Download OpenAP aircraft performance data and generate SQL import'
    )
    parser.add_argument('-o', '--output', default='047_openap_aircraft_import.sql',
                        help='Output SQL file path')
    parser.add_argument('--json', help='Also output raw data as JSON file')
    parser.add_argument('-v', '--verbose', action='store_true', help='Verbose output')
    
    args = parser.parse_args()
    
    print("=" * 60)
    print("OpenAP Aircraft Performance Import (V2)")
    print("=" * 60)
    print(f"Source: https://github.com/TUDelft-CNS-ATM/openap")
    print(f"Aircraft to process: {len(OPENAP_AIRCRAFT)}")
    print()
    
    all_aircraft = []
    wrap_count = 0
    
    for icao in sorted(OPENAP_AIRCRAFT):
        print(f"Processing {icao.upper()}...", end=' ')
        
        # Fetch aircraft YAML
        yaml_url = f"{OPENAP_BASE_URL}/aircraft/{icao}.yml"
        yaml_content = fetch_url(yaml_url)
        
        if not yaml_content:
            print("SKIP (no YAML)")
            continue
        
        aircraft_data = parse_aircraft_yaml(yaml_content, icao)
        if not aircraft_data:
            print("SKIP (parse failed)")
            continue
        
        # Fetch WRAP kinematic data
        wrap_data = fetch_wrap_kinematic(icao)
        has_wrap = wrap_data is not None
        if has_wrap:
            wrap_count += 1
        
        # Merge data
        merged = merge_aircraft_data(aircraft_data, wrap_data)
        all_aircraft.append(merged)
        
        wrap_status = "✓ WRAP" if has_wrap else "no WRAP"
        print(f"OK ({wrap_status})")
        
        if args.verbose:
            print(f"    Cruise: M{merged.get('cruise_mach', '?')} / {merged.get('cruise_speed_ktas', '?')} KTAS @ FL{merged.get('optimal_fl', '?')}")
            print(f"    Climb: {merged.get('climb_rate_fpm', '?')} fpm @ {merged.get('climb_speed_kias', '?')} KIAS")
            print(f"    Descent: {merged.get('descent_rate_fpm', '?')} fpm @ {merged.get('descent_speed_kias', '?')} KIAS")
            print(f"    Approach: {merged.get('approach_speed_kias', '?')} KIAS")
    
    print()
    print(f"Successfully processed: {len(all_aircraft)} aircraft")
    print(f"With WRAP kinematic data: {wrap_count}")
    
    # Generate SQL
    generate_sql(all_aircraft, args.output)
    
    # Optionally output JSON
    if args.json:
        with open(args.json, 'w', encoding='utf-8') as f:
            json.dump(all_aircraft, f, indent=2)
        print(f"Generated JSON file: {args.json}")
    
    print()
    print("Done! Run the generated SQL file in SSMS to import data.")


if __name__ == '__main__':
    main()
