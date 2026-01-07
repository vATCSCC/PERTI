#!/usr/bin/env python3
"""
NASR Navigation Data Updater for vATCSCC

Downloads FAA NASR subscription data and updates navigation data files
for route.js compatibility. Supports current + next AIRAC cycles with
backwards compatibility (preserves old data with _OLD suffixes).

Usage:
    python3 nasr_navdata_updater.py [options]

Options:
    -o OUTPUT_DIR     Output directory (default: assets/data)
    -j JS_DIR         JavaScript directory for awys.js/procs.js (default: assets/js)
    -c CACHE_DIR      Cache directory (default: .nasr_cache)
    --force           Force re-download even if cached
    --no-backup       Skip creating backups
    --current-only    Only process current cycle (skip next)
    --verbose         Enable debug output
    --dry-run         Parse only, don't write files
"""

import os
import sys
import csv
import json
import re
import zipfile
import hashlib
import logging
import argparse
import tempfile
import shutil
from datetime import datetime, timedelta
from pathlib import Path
from collections import defaultdict
from typing import Dict, List, Tuple, Optional, Set, Any
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)


class AIRACCycle:
    """Calculates AIRAC cycle dates using 28-day intervals."""
    
    # AIRAC epoch - a known cycle start date
    EPOCH = datetime(2024, 1, 25)
    CYCLE_DAYS = 28
    
    @classmethod
    def get_current_cycle(cls) -> datetime:
        """Get the start date of the current AIRAC cycle."""
        today = datetime.now()
        days_since_epoch = (today - cls.EPOCH).days
        cycles_since_epoch = days_since_epoch // cls.CYCLE_DAYS
        return cls.EPOCH + timedelta(days=cycles_since_epoch * cls.CYCLE_DAYS)
    
    @classmethod
    def get_next_cycle(cls) -> datetime:
        """Get the start date of the next AIRAC cycle."""
        return cls.get_current_cycle() + timedelta(days=cls.CYCLE_DAYS)
    
    @classmethod
    def get_cycle_id(cls, dt: datetime) -> str:
        """Get the AIRAC cycle ID (YYNN format) for a given date."""
        # Calculate cycle number since epoch
        days_since_epoch = (dt - cls.EPOCH).days
        cycles_since_epoch = days_since_epoch // cls.CYCLE_DAYS
        # AIRAC 2401 was Jan 25, 2024
        cycle_start = 2401 + cycles_since_epoch
        # Wrap around year
        year = (cycle_start - 1) // 13 + 24  # Year starts at 24 (2024)
        cycle_in_year = ((cycle_start - 1) % 13) + 1
        return f"{year:02d}{cycle_in_year:02d}"
    
    @classmethod
    def format_date(cls, dt: datetime) -> str:
        """Format date for FAA URL (YYYY-MM-DD)."""
        return dt.strftime('%Y-%m-%d')


class NASRDownloader:
    """Downloads and caches NASR subscription data."""
    
    BASE_URL = "https://nfdc.faa.gov/webContent/28DaySub"
    
    def __init__(self, cache_dir: str, force: bool = False):
        self.cache_dir = Path(cache_dir)
        self.cache_dir.mkdir(parents=True, exist_ok=True)
        self.force = force
    
    def get_zip_filename(self, cycle_date: datetime) -> str:
        """Generate the ZIP filename for a cycle date."""
        return f"28DaySubscription_Effective_{cycle_date.strftime('%Y-%m-%d')}.zip"
    
    def get_zip_url(self, cycle_date: datetime) -> str:
        """Generate the download URL for a cycle."""
        filename = self.get_zip_filename(cycle_date)
        return f"{self.BASE_URL}/{filename}"
    
    def download_cycle(self, cycle_date: datetime) -> Optional[Path]:
        """Download NASR ZIP for a cycle, using cache if available."""
        filename = self.get_zip_filename(cycle_date)
        cache_path = self.cache_dir / filename
        
        if cache_path.exists() and not self.force:
            logger.info(f"Using cached {filename}")
            return cache_path
        
        url = self.get_zip_url(cycle_date)
        logger.info(f"Downloading {url}...")
        
        try:
            req = Request(url, headers={'User-Agent': 'vATCSCC-NavData-Updater/1.0'})
            with urlopen(req, timeout=300) as response:
                total_size = int(response.headers.get('content-length', 0))
                downloaded = 0
                
                with open(cache_path, 'wb') as f:
                    while True:
                        chunk = response.read(8192)
                        if not chunk:
                            break
                        f.write(chunk)
                        downloaded += len(chunk)
                        if total_size > 0:
                            pct = (downloaded / total_size) * 100
                            print(f"\r  Progress: {pct:.1f}% ({downloaded // 1024 // 1024}MB)", end='', flush=True)
                print()
            
            logger.info(f"Downloaded {filename} ({downloaded // 1024 // 1024}MB)")
            return cache_path
            
        except (URLError, HTTPError) as e:
            logger.error(f"Failed to download {filename}: {e}")
            if cache_path.exists():
                cache_path.unlink()
            return None
    
    def extract_csv_data(self, zip_path: Path) -> Optional[Path]:
        """Extract nested CSV_Data ZIP and return path to extracted files."""
        extract_dir = self.cache_dir / f"extracted_{zip_path.stem}"
        
        if extract_dir.exists() and not self.force:
            # Check if already extracted
            csv_files = list(extract_dir.glob("*.csv"))
            if csv_files:
                logger.info(f"Using cached extraction: {extract_dir}")
                return extract_dir
        
        extract_dir.mkdir(parents=True, exist_ok=True)
        
        try:
            # Extract main ZIP to find CSV_Data/*.zip
            with zipfile.ZipFile(zip_path, 'r') as main_zip:
                csv_data_zips = [n for n in main_zip.namelist() 
                               if n.startswith('CSV_Data/') and n.endswith('.zip')]
                
                if not csv_data_zips:
                    logger.error("No CSV_Data ZIP found in subscription archive")
                    return None
                
                csv_data_zip_name = csv_data_zips[0]
                logger.info(f"Extracting nested ZIP: {csv_data_zip_name}")
                
                # Extract the nested ZIP
                with main_zip.open(csv_data_zip_name) as nested_zip_file:
                    nested_zip_path = extract_dir / "csv_data.zip"
                    with open(nested_zip_path, 'wb') as f:
                        f.write(nested_zip_file.read())
                
                # Extract contents of nested ZIP
                with zipfile.ZipFile(nested_zip_path, 'r') as nested_zip:
                    for member in nested_zip.namelist():
                        if member.endswith('.csv'):
                            # Extract CSV files to flat directory
                            member_path = Path(member)
                            target_path = extract_dir / member_path.name
                            with nested_zip.open(member) as src:
                                with open(target_path, 'wb') as dst:
                                    dst.write(src.read())
                
                # Clean up nested zip
                nested_zip_path.unlink()
            
            csv_count = len(list(extract_dir.glob("*.csv")))
            logger.info(f"Extracted {csv_count} CSV files to {extract_dir}")
            return extract_dir
            
        except Exception as e:
            logger.error(f"Failed to extract CSV data: {e}")
            return None


class NASRParser:
    """Parses NASR CSV files into structured data."""
    
    def __init__(self, csv_dir: Path):
        self.csv_dir = csv_dir
    
    def _read_csv(self, filename: str) -> List[Dict]:
        """Read a CSV file and return list of row dicts."""
        filepath = self.csv_dir / filename
        if not filepath.exists():
            logger.warning(f"CSV file not found: {filename}")
            return []
        
        rows = []
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                # Handle different line endings
                content = f.read().replace('\r\n', '\n').replace('\r', '\n')
                reader = csv.DictReader(content.splitlines())
                for row in reader:
                    rows.append(row)
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return rows
    
    def parse_fixes(self) -> Dict[str, Tuple[float, float]]:
        """Parse FIX_BASE.csv -> {FIX_ID: (lat, lon)}"""
        fixes = {}
        for row in self._read_csv('FIX_BASE.csv'):
            fix_id = row.get('FIX_ID', '').strip()
            try:
                lat = float(row.get('LAT_DECIMAL', 0))
                lon = float(row.get('LONG_DECIMAL', 0))
                if fix_id and lat != 0 and lon != 0:
                    fixes[fix_id] = (lat, lon)
            except (ValueError, TypeError):
                continue
        logger.info(f"Parsed {len(fixes)} fixes")
        return fixes
    
    def parse_navaids(self) -> Dict[str, Tuple[float, float, str]]:
        """Parse NAV_BASE.csv -> {NAV_ID: (lat, lon, type)}"""
        navaids = {}
        for row in self._read_csv('NAV_BASE.csv'):
            nav_id = row.get('NAV_ID', '').strip()
            nav_type = row.get('NAV_TYPE', '').strip()
            try:
                lat = float(row.get('LAT_DECIMAL', 0))
                lon = float(row.get('LONG_DECIMAL', 0))
                if nav_id and lat != 0 and lon != 0:
                    # Use composite key if duplicates exist
                    key = nav_id
                    if key in navaids and navaids[key][0:2] != (lat, lon):
                        # Keep first occurrence (usually primary)
                        continue
                    navaids[key] = (lat, lon, nav_type)
            except (ValueError, TypeError):
                continue
        logger.info(f"Parsed {len(navaids)} navaids")
        return navaids
    
    def parse_airports_full(self) -> Dict[str, Dict]:
        """Parse APT_BASE.csv -> {ARPT_ID: full record dict} for native format."""
        airports = {}
        for row in self._read_csv('APT_BASE.csv'):
            arpt_id = row.get('ARPT_ID', '').strip()
            if arpt_id:
                try:
                    lat = float(row.get('LAT_DECIMAL', 0))
                    lon = float(row.get('LONG_DECIMAL', 0))
                    if lat != 0 and lon != 0:
                        airports[arpt_id] = {
                            'ARPT_ID': arpt_id,
                            'ICAO_ID': row.get('ICAO_ID', '').strip(),
                            'ARPT_NAME': row.get('ARPT_NAME', '').strip(),
                            'LAT_DECIMAL': lat,
                            'LONG_DECIMAL': lon,
                            'ELEV': row.get('ELEV', '').strip(),
                            'RESP_ARTCC_ID': row.get('RESP_ARTCC_ID', '').strip(),
                            # NASR military fields
                            'OWNERSHIP_TYPE_CODE': row.get('OWNERSHIP_TYPE_CODE', '').strip(),
                            'USE_CODE': row.get('USE_CODE', '').strip(),
                            'MIL_SVC_CODE': row.get('MIL_SVC_CODE', '').strip(),
                            'MIL_LAND_RIGHTS_CODE': row.get('MIL_LAND_RIGHTS_CODE', '').strip(),
                        }
                except (ValueError, TypeError):
                    continue
        logger.info(f"Parsed {len(airports)} airports (full format)")
        return airports
    
    def parse_airports(self) -> Dict[str, Tuple[float, float, str]]:
        """Parse APT_BASE.csv -> {ARPT_ID: (lat, lon, name)}"""
        airports = {}
        for row in self._read_csv('APT_BASE.csv'):
            arpt_id = row.get('ARPT_ID', '').strip()
            arpt_name = row.get('ARPT_NAME', '').strip()
            try:
                lat = float(row.get('LAT_DECIMAL', 0))
                lon = float(row.get('LONG_DECIMAL', 0))
                if arpt_id and lat != 0 and lon != 0:
                    airports[arpt_id] = (lat, lon, arpt_name)
            except (ValueError, TypeError):
                continue
        logger.info(f"Parsed {len(airports)} airports")
        return airports
    
    def parse_airways(self) -> Dict[str, List[str]]:
        """Parse AWY_SEG_ALT.csv -> {AWY_ID: [point1, point2, ...]}"""
        # Group segments by airway and sort by sequence
        awy_segments = defaultdict(list)
        
        for row in self._read_csv('AWY_SEG_ALT.csv'):
            awy_id = row.get('AWY_ID', '').strip()
            from_point = row.get('FROM_POINT', '').strip()
            try:
                seq = int(row.get('POINT_SEQ', 0))
            except ValueError:
                seq = 0
            
            if awy_id and from_point:
                awy_segments[awy_id].append((seq, from_point))
        
        # Sort by sequence and build point lists
        airways = {}
        for awy_id, segments in awy_segments.items():
            segments.sort(key=lambda x: x[0])
            points = [pt for _, pt in segments]
            # Remove consecutive duplicates
            unique_points = []
            for pt in points:
                if not unique_points or unique_points[-1] != pt:
                    unique_points.append(pt)
            if len(unique_points) >= 2:
                airways[awy_id] = unique_points
        
        logger.info(f"Parsed {len(airways)} airways")
        return airways
    
    def parse_cdrs(self) -> Dict[str, str]:
        """Parse CDR.csv -> {RCode: route_string}"""
        cdrs = {}
        for row in self._read_csv('CDR.csv'):
            rcode = row.get('RCode', '').strip()
            route = row.get('Route String', '').strip()
            if rcode and route:
                cdrs[rcode] = route
        logger.info(f"Parsed {len(cdrs)} CDRs")
        return cdrs
    
    def parse_dp_base(self) -> Dict[str, Dict]:
        """Parse DP_BASE.csv -> {DP_COMPUTER_CODE: metadata}"""
        dps = {}
        for row in self._read_csv('DP_BASE.csv'):
            code = row.get('DP_COMPUTER_CODE', '').strip()
            if code:
                dps[code] = {
                    'name': row.get('DP_NAME', '').strip(),
                    'artcc': row.get('ARTCC', '').strip(),
                    'eff_date': row.get('EFF_DATE', '').strip(),
                    'served_arpt': row.get('SERVED_ARPT', '').strip()
                }
        logger.info(f"Parsed {len(dps)} DP base records")
        return dps
    
    def parse_dp_routes(self) -> Dict[str, Dict[str, List]]:
        """Parse DP_RTE.csv -> {DP_COMPUTER_CODE: {bodies: [...], transitions: [...]}}"""
        routes = defaultdict(lambda: {'bodies': defaultdict(list), 'transitions': defaultdict(list)})
        
        for row in self._read_csv('DP_RTE.csv'):
            code = row.get('DP_COMPUTER_CODE', '').strip()
            portion = row.get('ROUTE_PORTION_TYPE', '').strip().upper()
            route_name = row.get('ROUTE_NAME', '').strip()
            trans_code = row.get('TRANSITION_COMPUTER_CODE', '').strip()
            point = row.get('POINT', '').strip()
            arpt_rwy = row.get('ARPT_RWY_ASSOC', '').strip()
            
            try:
                seq = int(row.get('POINT_SEQ', 0))
            except ValueError:
                seq = 0
            
            if code and point:
                if portion == 'BODY':
                    routes[code]['bodies'][route_name].append((seq, point, arpt_rwy))
                elif portion == 'TRANSITION':
                    routes[code]['transitions'][trans_code].append((seq, point, route_name))
        
        logger.info(f"Parsed DP routes for {len(routes)} procedures")
        return dict(routes)
    
    def parse_star_base(self) -> Dict[str, Dict]:
        """Parse STAR_BASE.csv -> {STAR_COMPUTER_CODE: metadata}"""
        stars = {}
        for row in self._read_csv('STAR_BASE.csv'):
            code = row.get('STAR_COMPUTER_CODE', '').strip()
            if code:
                stars[code] = {
                    'name': row.get('ARRIVAL_NAME', '').strip(),
                    'artcc': row.get('ARTCC', '').strip(),
                    'eff_date': row.get('EFF_DATE', '').strip(),
                    'served_arpt': row.get('SERVED_ARPT', '').strip()
                }
        logger.info(f"Parsed {len(stars)} STAR base records")
        return stars
    
    def parse_star_routes(self) -> Dict[str, Dict[str, List]]:
        """Parse STAR_RTE.csv -> {STAR_COMPUTER_CODE: {bodies: [...], transitions: [...]}}"""
        routes = defaultdict(lambda: {'bodies': defaultdict(list), 'transitions': defaultdict(list)})
        
        for row in self._read_csv('STAR_RTE.csv'):
            code = row.get('STAR_COMPUTER_CODE', '').strip()
            portion = row.get('ROUTE_PORTION_TYPE', '').strip().upper()
            route_name = row.get('ROUTE_NAME', '').strip()
            trans_code = row.get('TRANSITION_COMPUTER_CODE', '').strip()
            point = row.get('POINT', '').strip()
            arpt_rwy = row.get('ARPT_RWY_ASSOC', '').strip()
            
            try:
                seq = int(row.get('POINT_SEQ', 0))
            except ValueError:
                seq = 0
            
            if code and point:
                if portion == 'BODY':
                    routes[code]['bodies'][route_name].append((seq, point, arpt_rwy))
                elif portion == 'TRANSITION':
                    routes[code]['transitions'][trans_code].append((seq, point, route_name))
        
        logger.info(f"Parsed STAR routes for {len(routes)} procedures")
        return dict(routes)
    
    def parse_pfr(self) -> List[Dict]:
        """Parse PFR_BASE.csv -> list of preferred route records"""
        routes = []
        for row in self._read_csv('PFR_BASE.csv'):
            origin = row.get('ORIGIN_ID', '').strip()
            dest = row.get('DSTN_ID', '').strip()
            route_str = row.get('ROUTE_STRING', '').strip()
            pfr_type = row.get('PFR_TYPE_CODE', '').strip()
            
            if origin and dest and route_str:
                routes.append({
                    'origin': origin,
                    'dest': dest,
                    'route': route_str,
                    'type': pfr_type
                })
        
        logger.info(f"Parsed {len(routes)} preferred routes")
        return routes


class NavDataTransformer:
    """Transforms NASR data to route.js compatible formats."""
    
    # Border crossing point patterns to filter from airways (with suffixes like -1, -2, etc.)
    BORDER_PATTERNS = [
        r'U\.S\. MEXICAN BORDER-?\d*',
        r'U\.S\. CANADIAN BORDER-?\d*',
        r'US MEXICAN BORDER-?\d*',
        r'US CANADIAN BORDER-?\d*',
    ]
    
    def __init__(self, airports: Dict = None):
        self.airports = airports or {}
        # Compile regex for removing border phrases from full airway string
        self._border_regex = re.compile('|'.join(self.BORDER_PATTERNS), re.IGNORECASE)
    
    def _filter_border_points(self, airway_string: str) -> str:
        """Remove border crossing points from airway string."""
        # Remove border phrases
        result = self._border_regex.sub('', airway_string)
        # Clean up multiple spaces
        result = ' '.join(result.split())
        return result
    
    def transform_points(self, fixes: Dict, navaids: Dict, airports: Dict) -> List[Tuple[str, float, float]]:
        """Combine fixes, navaids, airports into points list."""
        points = []
        seen = set()
        
        # Add fixes
        for fix_id, (lat, lon) in fixes.items():
            if fix_id not in seen:
                points.append((fix_id, lat, lon))
                seen.add(fix_id)
        
        # Add navaids (may overlap with fixes)
        for nav_id, (lat, lon, _) in navaids.items():
            if nav_id not in seen:
                points.append((nav_id, lat, lon))
                seen.add(nav_id)
        
        # Add airports with K prefix for US airports
        for arpt_id, (lat, lon, _) in airports.items():
            k_id = f"K{arpt_id}" if not arpt_id.startswith('K') and len(arpt_id) == 3 else arpt_id
            if k_id not in seen:
                points.append((k_id, lat, lon))
                seen.add(k_id)
            # Also add without K prefix
            if arpt_id not in seen:
                points.append((arpt_id, lat, lon))
                seen.add(arpt_id)
        
        return points
    
    def transform_navaids(self, navaids: Dict) -> List[Tuple[str, float, float]]:
        """Transform navaids to output format."""
        return [(nav_id, lat, lon) for nav_id, (lat, lon, _) in navaids.items()]
    
    def transform_airports(self, airports: Dict) -> List[Tuple[str, float, float]]:
        """Transform airports to output format (K-prefixed)."""
        result = []
        for arpt_id, (lat, lon, _) in airports.items():
            k_id = f"K{arpt_id}" if not arpt_id.startswith('K') and len(arpt_id) == 3 else arpt_id
            result.append((k_id, lat, lon))
        return result
    
    def transform_airways(self, airways: Dict) -> Dict[str, str]:
        """Transform airways to route.js format, filtering border crossing points."""
        result = {}
        for awy_id, points in airways.items():
            # Join points and filter border crossings from the full string
            airway_string = ' '.join(points)
            filtered_string = self._filter_border_points(airway_string)
            # Only include if we have at least 2 points remaining
            remaining_points = filtered_string.split()
            if len(remaining_points) >= 2:
                result[awy_id] = filtered_string
        return result
    
    def transform_cdrs(self, cdrs: Dict) -> Dict[str, str]:
        """CDRs are already in correct format."""
        return cdrs
    
    def _format_airport_runway_group(self, arpt_rwy: str, add_k_prefix: bool = False) -> str:
        """
        Format airport/runway associations for output.
        
        Args:
            arpt_rwy: Raw airport/runway string from NASR
            add_k_prefix: If False, preserve original FAA LIDs without K prefix
        """
        if not arpt_rwy:
            return ''
        
        # Parse entries like "MKE/01L, MKE/01R, RAC, UES" or "SJU/08, SJU/10"
        entries = [e.strip() for e in arpt_rwy.split(',')]
        
        # Group by airport
        arpt_rwys = defaultdict(list)
        for entry in entries:
            if '/' in entry:
                arpt, rwy = entry.split('/', 1)
                arpt_rwys[arpt.strip()].append(rwy.strip())
            else:
                arpt_rwys[entry.strip()] = []
        
        # Format output - DON'T add K prefix (preserve original FAA LIDs)
        parts = []
        for arpt, rwys in arpt_rwys.items():
            if add_k_prefix and len(arpt) == 3 and not arpt.startswith('K'):
                arpt = 'K' + arpt
            
            if rwys:
                parts.append(f"{arpt}/{'|'.join(rwys)}")
            else:
                parts.append(arpt)
        
        return ' '.join(parts)
    
    def _dedupe_consecutive(self, points: List[str]) -> List[str]:
        """Remove consecutive duplicate waypoints from a route."""
        if not points:
            return points
        result = [points[0]]
        for pt in points[1:]:
            if pt != result[-1]:
                result.append(pt)
        return result
    
    def transform_dps(self, dp_base: Dict, dp_routes: Dict) -> List[Dict]:
        """
        Transform DPs to route.js dp_full_routes.csv format.
        Does NOT standardize airport codes (preserves FAA LIDs).
        """
        results = []
        
        for code, metadata in dp_base.items():
            routes = dp_routes.get(code, {'bodies': {}, 'transitions': {}})
            eff_date = metadata['eff_date'].replace('/', '-') if '/' in metadata.get('eff_date', '') else metadata.get('eff_date', '')
            
            # Convert to MM/DD/YYYY format
            if eff_date and len(eff_date) == 10 and '-' in eff_date:
                parts = eff_date.split('-')
                if len(parts) == 3:
                    eff_date = f"{parts[1]}/{parts[2]}/{parts[0]}"
            
            # Process bodies
            for body_name, body_points in routes['bodies'].items():
                body_points.sort(key=lambda x: x[0])
                points_list = [p[1] for p in body_points]
                points_list.reverse()
                
                arpt_rwy = body_points[0][2] if body_points else ''
                # DON'T add K prefix - preserve original FAA LIDs
                orig_group = self._format_airport_runway_group(arpt_rwy, add_k_prefix=False)
                
                matching_trans = []
                for trans_code, trans_points in routes['transitions'].items():
                    matching_trans.append((trans_code, trans_points))
                
                if matching_trans:
                    for trans_code, trans_points in matching_trans:
                        trans_points_sorted = sorted(trans_points, key=lambda x: x[0])
                        trans_pts = [p[1] for p in trans_points_sorted]
                        trans_pts.reverse()
                        trans_name = trans_points_sorted[0][2] if trans_points_sorted else ''
                        
                        combined = self._dedupe_consecutive(points_list + trans_pts)
                        route_points = ' '.join(combined)
                        full_from_orig = orig_group + ' ' + route_points if orig_group else route_points
                        
                        results.append({
                            'EFF_DATE': eff_date,
                            'DP_NAME': metadata['name'],
                            'DP_COMPUTER_CODE': code,
                            'ARTCC': metadata['artcc'],
                            'ORIG_GROUP': orig_group,
                            'BODY_NAME': body_name,
                            'TRANSITION_COMPUTER_CODE': trans_code,
                            'TRANSITION_NAME': trans_name,
                            'ROUTE_POINTS': route_points.strip(),
                            'ROUTE_FROM_ORIG_GROUP': full_from_orig.strip()
                        })
                else:
                    route_points = ' '.join(points_list)
                    full_from_orig = orig_group + ' ' + route_points if orig_group else route_points
                    results.append({
                        'EFF_DATE': eff_date,
                        'DP_NAME': metadata['name'],
                        'DP_COMPUTER_CODE': code,
                        'ARTCC': metadata['artcc'],
                        'ORIG_GROUP': orig_group,
                        'BODY_NAME': body_name,
                        'TRANSITION_COMPUTER_CODE': '',
                        'TRANSITION_NAME': '',
                        'ROUTE_POINTS': route_points.strip(),
                        'ROUTE_FROM_ORIG_GROUP': full_from_orig.strip()
                    })
        
        logger.info(f"Transformed {len(results)} DP full routes")
        return results
    
    def transform_stars(self, star_base: Dict, star_routes: Dict) -> List[Dict]:
        """
        Transform STARs to route.js star_full_routes.csv format.
        Does NOT standardize airport codes (preserves FAA LIDs).
        """
        results = []
        
        for code, metadata in star_base.items():
            routes = star_routes.get(code, {'bodies': {}, 'transitions': {}})
            eff_date = metadata['eff_date'].replace('/', '-') if '/' in metadata.get('eff_date', '') else metadata.get('eff_date', '')
            
            # Convert to MM/DD/YYYY format
            if eff_date and len(eff_date) == 10 and '-' in eff_date:
                parts = eff_date.split('-')
                if len(parts) == 3:
                    eff_date = f"{parts[1]}/{parts[2]}/{parts[0]}"
            
            for body_name, body_points in routes['bodies'].items():
                body_points.sort(key=lambda x: x[0])
                points_list = [p[1] for p in body_points]
                points_list.reverse()
                
                arpt_rwy = body_points[0][2] if body_points else ''
                if arpt_rwy:
                    # DON'T add K prefix - preserve original FAA LIDs
                    dest_group = self._format_airport_runway_group(arpt_rwy, add_k_prefix=False)
                else:
                    served = metadata.get('served_arpt', '').strip()
                    dest_group = served if served else ''
                
                matching_trans = []
                for trans_code, trans_points in routes['transitions'].items():
                    matching_trans.append((trans_code, trans_points))
                
                if matching_trans:
                    for trans_code, trans_points in matching_trans:
                        trans_points_sorted = sorted(trans_points, key=lambda x: x[0])
                        trans_pts = [p[1] for p in trans_points_sorted]
                        trans_pts.reverse()
                        trans_name = trans_points_sorted[0][2] if trans_points_sorted else ''
                        
                        combined = self._dedupe_consecutive(trans_pts + points_list)
                        route_points = ' '.join(combined)
                        full_from_dest = route_points + ' ' + dest_group if dest_group else route_points
                        
                        results.append({
                            'EFF_DATE': eff_date,
                            'ARRIVAL_NAME': metadata['name'],
                            'STAR_COMPUTER_CODE': code,
                            'ARTCC': metadata['artcc'],
                            'DEST_GROUP': dest_group,
                            'BODY_NAME': body_name,
                            'TRANSITION_COMPUTER_CODE': trans_code,
                            'TRANSITION_NAME': trans_name,
                            'ROUTE_POINTS': route_points.strip(),
                            'ROUTE_FROM_DEST_GROUP': full_from_dest.strip()
                        })
                else:
                    route_points = ' '.join(points_list)
                    full_from_dest = route_points + ' ' + dest_group if dest_group else route_points
                    results.append({
                        'EFF_DATE': eff_date,
                        'ARRIVAL_NAME': metadata['name'],
                        'STAR_COMPUTER_CODE': code,
                        'ARTCC': metadata['artcc'],
                        'DEST_GROUP': dest_group,
                        'BODY_NAME': body_name,
                        'TRANSITION_COMPUTER_CODE': '',
                        'TRANSITION_NAME': '',
                        'ROUTE_POINTS': route_points.strip(),
                        'ROUTE_FROM_DEST_GROUP': full_from_dest.strip()
                    })
        
        logger.info(f"Transformed {len(results)} STAR full routes")
        return results


class NavDataMerger:
    """Merges new data with existing data, preserving old entries with _OLD suffix."""
    
    def __init__(self, current_airac: str = None):
        self.current_airac = current_airac or AIRACCycle.get_cycle_id(datetime.now())
        self.changes = {
            'points': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'navaids': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'airways': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'cdrs': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'dps': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'stars': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0},
            'playbook': {'added': 0, 'modified': 0, 'preserved': 0, 'renamed': 0}
        }
        self.detailed_changes = defaultdict(list)
    
    def _get_old_name(self, name: str) -> str:
        """
        Generate the _OLD name for an element.
        If it already has _OLD, add AIRAC cycle: _OLD[AIRAC]
        """
        if '_OLD' in name:
            # Already has _OLD, check if it has a cycle number
            match = re.match(r'^(.+)_OLD(\d*)$', name)
            if match:
                base_name = match.group(1)
                # Add current AIRAC cycle
                return f"{base_name}_OLD{self.current_airac}"
        # No _OLD suffix, add it
        return f"{name}_OLD"
    
    def merge_points(self, existing: List[Tuple], new: List[Tuple], 
                    data_type: str = 'points') -> List[Tuple]:
        """
        Merge point lists with _OLD suffix for modified entries.
        - Add unique new points
        - Keep old points
        - If a point is updated (coordinates changed), rename old to _OLD
        """
        existing_dict = {pt[0]: (pt[1], pt[2]) for pt in existing}
        result = []
        processed_ids = set()
        
        # First pass: process new points
        for pt_id, lat, lon in new:
            if pt_id in existing_dict:
                old_lat, old_lon = existing_dict[pt_id]
                # Check for coordinate changes (threshold: 0.0001 degrees ≈ 11m)
                if abs(old_lat - lat) > 0.0001 or abs(old_lon - lon) > 0.0001:
                    # Rename old entry to _OLD
                    old_name = self._get_old_name(pt_id)
                    result.append((old_name, old_lat, old_lon))
                    result.append((pt_id, lat, lon))
                    self.changes[data_type]['modified'] += 1
                    self.changes[data_type]['renamed'] += 1
                    self.detailed_changes[data_type].append(
                        f"Modified {pt_id}: ({old_lat:.6f},{old_lon:.6f}) -> ({lat:.6f},{lon:.6f}), old renamed to {old_name}"
                    )
                else:
                    # Keep existing (no change)
                    result.append((pt_id, old_lat, old_lon))
                    self.changes[data_type]['preserved'] += 1
                processed_ids.add(pt_id)
            else:
                # New point
                result.append((pt_id, lat, lon))
                self.changes[data_type]['added'] += 1
                self.detailed_changes[data_type].append(f"Added {pt_id}: ({lat:.6f},{lon:.6f})")
                processed_ids.add(pt_id)
        
        # Second pass: keep existing points not in new data
        for pt_id, (lat, lon) in existing_dict.items():
            if pt_id not in processed_ids:
                result.append((pt_id, lat, lon))
                self.changes[data_type]['preserved'] += 1
        
        return result
    
    def merge_dict_data(self, existing: Dict[str, str], new: Dict[str, str],
                       data_type: str) -> Dict[str, str]:
        """
        Merge dictionary data (airways, CDRs) with _OLD suffix for modified entries.
        """
        result = {}
        processed_keys = set()
        
        # First pass: process new entries
        for key, value in new.items():
            if key in existing:
                if existing[key] != value:
                    # Rename old entry to _OLD
                    old_name = self._get_old_name(key)
                    result[old_name] = existing[key]
                    result[key] = value
                    self.changes[data_type]['modified'] += 1
                    self.changes[data_type]['renamed'] += 1
                    self.detailed_changes[data_type].append(f"Modified {key}, old renamed to {old_name}")
                else:
                    result[key] = existing[key]
                    self.changes[data_type]['preserved'] += 1
                processed_keys.add(key)
            else:
                result[key] = value
                self.changes[data_type]['added'] += 1
                self.detailed_changes[data_type].append(f"Added {key}")
                processed_keys.add(key)
        
        # Second pass: keep existing entries not in new data
        for key, value in existing.items():
            if key not in processed_keys:
                result[key] = value
                self.changes[data_type]['preserved'] += 1
        
        return result
    
    def merge_list_records(self, existing: List[Dict], new: List[Dict],
                          key_fields: List[str], data_type: str) -> List[Dict]:
        """Merge list of records by composite key with _OLD suffix for modified."""
        def make_key(record):
            return tuple(record.get(f, '') for f in key_fields)
        
        existing_dict = {make_key(r): r for r in existing}
        result = []
        processed_keys = set()
        
        for record in new:
            key = make_key(record)
            if key in existing_dict:
                old = existing_dict[key]
                if record != old:
                    # Modified - keep old with _OLD suffix in a way that makes sense
                    # For procedures, we'll just update to new version
                    result.append(record)
                    self.changes[data_type]['modified'] += 1
                else:
                    result.append(old)
                    self.changes[data_type]['preserved'] += 1
                processed_keys.add(key)
            else:
                result.append(record)
                self.changes[data_type]['added'] += 1
                processed_keys.add(key)
        
        # Keep existing records not in new data
        for key, record in existing_dict.items():
            if key not in processed_keys:
                result.append(record)
                self.changes[data_type]['preserved'] += 1
        
        return result
    
    def remove_duplicates_points(self, points: List[Tuple]) -> List[Tuple]:
        """Remove exact duplicate points."""
        seen = set()
        unique = []
        for pt in points:
            key = (pt[0], round(pt[1], 6), round(pt[2], 6))
            if key not in seen:
                seen.add(key)
                unique.append(pt)
        removed = len(points) - len(unique)
        if removed > 0:
            logger.info(f"Removed {removed} duplicate points")
        return unique
    
    def remove_duplicates_records(self, records: List[Dict]) -> List[Dict]:
        """Remove exact duplicate records using hash."""
        seen = set()
        unique = []
        for record in records:
            record_str = json.dumps(record, sort_keys=True)
            record_hash = hashlib.md5(record_str.encode()).hexdigest()
            if record_hash not in seen:
                seen.add(record_hash)
                unique.append(record)
        removed = len(records) - len(unique)
        if removed > 0:
            logger.info(f"Removed {removed} duplicate records")
        return unique


class NavDataIO:
    """Handles reading and writing navigation data files."""
    
    # Native apts.csv header columns (31 columns - added military fields)
    APTS_HEADER = [
        'ARPT_ID', 'ICAO_ID', 'ARPT_NAME', 'LAT_DECIMAL', 'LONG_DECIMAL', 'ELEV',
        'RESP_ARTCC_ID', 'COMPUTER_ID', 'ARTCC_NAME', 'TWR_TYPE_CODE', 'DCC REGION',
        'ASPM77', 'OEP35', 'Core30', 'Tower', 'Approach', 'Secondary Approach',
        'Departure', 'Secondary Departure', 'Approach/Departure', 'Approach ID',
        'Secondary Approach ID', 'Departure ID', 'Secondary Departure ID',
        'Approach/Departure ID', 'Consolidated Approach', 'Consolidated Approach ID',
        # NASR military fields
        'OWNERSHIP_TYPE_CODE', 'USE_CODE', 'MIL_SVC_CODE', 'MIL_LAND_RIGHTS_CODE'
    ]
    
    def __init__(self, data_dir: Path):
        self.data_dir = Path(data_dir)
    
    def read_points_csv(self, filename: str) -> List[Tuple[str, float, float]]:
        """Read points/navaids CSV (ID,LAT,LON format)."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return []
        
        points = []
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                for line in f:
                    line = line.strip().rstrip('\r')
                    if not line or line.startswith('#'):
                        continue
                    # Skip header line if present
                    if line.startswith('ARPT_ID') or line.startswith('point_id'):
                        continue
                    parts = line.split(',')
                    if len(parts) >= 3:
                        try:
                            pt_id = parts[0].strip()
                            lat = float(parts[1])
                            lon = float(parts[2])
                            points.append((pt_id, lat, lon))
                        except ValueError:
                            continue
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return points
    
    def read_airports_csv(self, filename: str = 'apts.csv') -> List[Dict]:
        """Read native format airports CSV (27 columns with header)."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return []
        
        records = []
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                content = f.read().replace('\r\n', '\n').replace('\r', '\n')
                reader = csv.DictReader(content.splitlines())
                for row in reader:
                    records.append(dict(row))
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return records
    
    def read_airways_csv(self, filename: str = 'awys.csv') -> Dict[str, str]:
        """Read airways CSV (AWY_ID,POINTS format)."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return {}
        
        airways = {}
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                for line in f:
                    line = line.strip().rstrip('\r')
                    if not line or line.startswith('#'):
                        continue
                    parts = line.split(',', 1)
                    if len(parts) == 2:
                        airways[parts[0].strip()] = parts[1].strip()
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return airways
    
    def read_cdrs_csv(self, filename: str = 'cdrs.csv') -> Dict[str, str]:
        """Read CDRs CSV (RCODE,ROUTE format)."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return {}
        
        cdrs = {}
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                for line in f:
                    line = line.strip().rstrip('\r')
                    if not line or line.startswith('#'):
                        continue
                    parts = line.split(',', 1)
                    if len(parts) == 2:
                        cdrs[parts[0].strip()] = parts[1].strip()
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return cdrs
    
    def read_structured_csv(self, filename: str) -> List[Dict]:
        """Read CSV with headers."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return []
        
        records = []
        try:
            with open(filepath, 'r', encoding='utf-8-sig', errors='replace') as f:
                content = f.read().replace('\r\n', '\n').replace('\r', '\n')
                reader = csv.DictReader(content.splitlines())
                for row in reader:
                    records.append(dict(row))
        except Exception as e:
            logger.error(f"Error reading {filename}: {e}")
        
        return records
    
    def write_points_csv(self, filename: str, points: List[Tuple[str, float, float]]):
        """Write points CSV (no header)."""
        filepath = self.data_dir / filename
        with open(filepath, 'w', encoding='utf-8', newline='') as f:
            for pt_id, lat, lon in points:
                f.write(f"{pt_id},{lat},{lon}\n")
        logger.info(f"Wrote {len(points)} points to {filename}")
    
    def write_airports_csv(self, filename: str, airports: List[Dict]):
        """Write native format airports CSV (27 columns with header)."""
        filepath = self.data_dir / filename
        with open(filepath, 'w', encoding='utf-8', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=self.APTS_HEADER, extrasaction='ignore')
            writer.writeheader()
            writer.writerows(airports)
        logger.info(f"Wrote {len(airports)} airports to {filename}")
    
    def write_airways_csv(self, filename: str, airways: Dict[str, str]):
        """Write airways CSV (no header)."""
        filepath = self.data_dir / filename
        with open(filepath, 'w', encoding='utf-8', newline='') as f:
            for awy_id, points in sorted(airways.items()):
                f.write(f"{awy_id},{points}\n")
        logger.info(f"Wrote {len(airways)} airways to {filename}")
    
    def write_cdrs_csv(self, filename: str, cdrs: Dict[str, str]):
        """Write CDRs CSV (no header)."""
        filepath = self.data_dir / filename
        with open(filepath, 'w', encoding='utf-8', newline='') as f:
            for rcode, route in sorted(cdrs.items()):
                f.write(f"{rcode},{route}\n")
        logger.info(f"Wrote {len(cdrs)} CDRs to {filename}")
    
    def write_structured_csv(self, filename: str, records: List[Dict], fieldnames: List[str]):
        """Write CSV with headers."""
        filepath = self.data_dir / filename
        with open(filepath, 'w', encoding='utf-8', newline='') as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(records)
        logger.info(f"Wrote {len(records)} records to {filename}")
    
    def backup_file(self, filename: str, backup_dir: Path):
        """Create timestamped backup of a file."""
        filepath = self.data_dir / filename
        if not filepath.exists():
            return

        backup_dir.mkdir(parents=True, exist_ok=True)
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        backup_path = backup_dir / f"{filepath.stem}_{timestamp}{filepath.suffix}"
        shutil.copy2(filepath, backup_path)
        logger.debug(f"Backed up {filename} to {backup_path}")

    def cleanup_old_backups(self, backup_dir: Path, keep_count: int = 2):
        """
        Remove old backup files, keeping only the most recent N sets.

        Groups backups by base filename (before timestamp) and keeps
        the most recent `keep_count` backups of each type.
        """
        if not backup_dir.exists():
            return

        # Group backup files by base name (filename without timestamp)
        backup_groups = defaultdict(list)
        timestamp_pattern = re.compile(r'^(.+)_(\d{8}_\d{6})(\..+)$')

        for backup_file in backup_dir.glob('*'):
            if not backup_file.is_file():
                continue
            match = timestamp_pattern.match(backup_file.name)
            if match:
                base_name = match.group(1)
                timestamp = match.group(2)
                backup_groups[base_name].append((timestamp, backup_file))

        # For each group, keep only the most recent N backups
        total_removed = 0
        for base_name, backups in backup_groups.items():
            # Sort by timestamp (newest first)
            backups.sort(key=lambda x: x[0], reverse=True)

            # Remove old backups beyond keep_count
            for timestamp, backup_path in backups[keep_count:]:
                try:
                    backup_path.unlink()
                    total_removed += 1
                    logger.debug(f"Removed old backup: {backup_path.name}")
                except Exception as e:
                    logger.warning(f"Failed to remove old backup {backup_path.name}: {e}")

        if total_removed > 0:
            logger.info(f"Cleaned up {total_removed} old backup files (keeping {keep_count} most recent of each type)")


class JSFileUpdater:
    """Updates awys.js and procs.js JavaScript files."""
    
    def __init__(self, js_dir: Path):
        self.js_dir = Path(js_dir)
    
    def update_awys_js(self, airways: Dict[str, str]):
        """
        Update awys.js from airways dictionary.
        Format: var awys = [["AWY_ID","POINT1 POINT2 ..."], ...]
        """
        filepath = self.js_dir / 'awys.js'
        
        # Build the array entries
        entries = []
        for awy_id in sorted(airways.keys()):
            points = airways[awy_id]
            # Escape any quotes in the data
            awy_id_escaped = awy_id.replace('"', '\\"')
            points_escaped = points.replace('"', '\\"')
            entries.append(f'["{awy_id_escaped}","{points_escaped}"]')
        
        # Add null terminator entry
        entries.append('["",null]')
        
        js_content = f"var awys = [{','.join(entries)}]"
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(js_content)
        
        logger.info(f"Wrote {len(airways)} airways to awys.js")
    
    def update_procs_js(self, dp_routes: List[Dict], star_routes: List[Dict]):
        """
        Update procs.js from DP and STAR routes.
        Format: var procs = [["PROC_NAME","POINT1 POINT2 ..."], ...]
        
        Extracts unique procedure names with their route points.
        """
        filepath = self.js_dir / 'procs.js'
        
        # Collect all procedures (DPs and STARs)
        procs = {}
        
        # Process DPs - use DP_COMPUTER_CODE base (before .) as key
        for dp in dp_routes:
            code = dp.get('DP_COMPUTER_CODE', '')
            route_points = dp.get('ROUTE_POINTS', '')
            if code and route_points:
                # Extract base code (e.g., "ACCRA5" from "ACCRA5.ACCRA")
                base_code = code.split('.')[0] if '.' in code else code
                # Keep longest route for each procedure
                if base_code not in procs or len(route_points) > len(procs[base_code]):
                    procs[base_code] = route_points
        
        # Process STARs - use STAR_COMPUTER_CODE base as key
        for star in star_routes:
            code = star.get('STAR_COMPUTER_CODE', '')
            route_points = star.get('ROUTE_POINTS', '')
            if code and route_points:
                base_code = code.split('.')[0] if '.' in code else code
                if base_code not in procs or len(route_points) > len(procs[base_code]):
                    procs[base_code] = route_points
        
        # Build the array entries
        entries = []
        for proc_name in sorted(procs.keys()):
            points = procs[proc_name]
            proc_name_escaped = proc_name.replace('"', '\\"')
            points_escaped = points.replace('"', '\\"')
            entries.append(f'["{proc_name_escaped}","{points_escaped}"]')
        
        # Add null terminator entry
        entries.append('["",null]')
        
        js_content = f"var procs = [{','.join(entries)}]"
        
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(js_content)
        
        logger.info(f"Wrote {len(procs)} procedures to procs.js")
    
    def read_awys_js(self) -> Dict[str, str]:
        """Read existing awys.js and parse to dictionary."""
        filepath = self.js_dir / 'awys.js'
        if not filepath.exists():
            return {}
        
        airways = {}
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            
            # Parse the JavaScript array
            # Format: var awys = [[...], ...]
            match = re.search(r'var\s+awys\s*=\s*\[(.*)\]', content, re.DOTALL)
            if match:
                array_content = match.group(1)
                # Parse each entry
                for entry_match in re.finditer(r'\["([^"]*)",\s*"([^"]*)"\]', array_content):
                    awy_id = entry_match.group(1)
                    points = entry_match.group(2)
                    if awy_id:
                        airways[awy_id] = points
        except Exception as e:
            logger.error(f"Error reading awys.js: {e}")
        
        return airways
    
    def read_procs_js(self) -> Dict[str, str]:
        """Read existing procs.js and parse to dictionary."""
        filepath = self.js_dir / 'procs.js'
        if not filepath.exists():
            return {}
        
        procs = {}
        try:
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            
            match = re.search(r'var\s+procs\s*=\s*\[(.*)\]', content, re.DOTALL)
            if match:
                array_content = match.group(1)
                for entry_match in re.finditer(r'\["([^"]*)",\s*"([^"]*)"\]', array_content):
                    proc_name = entry_match.group(1)
                    points = entry_match.group(2)
                    if proc_name:
                        procs[proc_name] = points
        except Exception as e:
            logger.error(f"Error reading procs.js: {e}")
        
        return procs


class ChangeLogger:
    """Generates change reports."""
    
    def __init__(self, log_dir: Path):
        self.log_dir = Path(log_dir)
        self.log_dir.mkdir(parents=True, exist_ok=True)
    
    def generate_report(self, merger: NavDataMerger, cycles: List[datetime]) -> Tuple[str, str]:
        """Generate text and JSON change reports."""
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        
        # Text report
        text_path = self.log_dir / f"navdata_changes_{timestamp}.txt"
        with open(text_path, 'w') as f:
            f.write("NASR NavData Update Report\n")
            f.write("=" * 60 + "\n\n")
            f.write(f"Generated: {datetime.now().isoformat()}\n")
            f.write(f"AIRAC Cycles: {', '.join(AIRACCycle.get_cycle_id(c) for c in cycles)}\n\n")
            
            for data_type, counts in merger.changes.items():
                f.write(f"\n{data_type.upper()}:\n")
                f.write(f"  Added: {counts['added']}\n")
                f.write(f"  Modified: {counts['modified']}\n")
                f.write(f"  Preserved: {counts['preserved']}\n")
                f.write(f"  Renamed to _OLD: {counts['renamed']}\n")
                
                if merger.detailed_changes[data_type]:
                    f.write(f"  Details:\n")
                    for change in merger.detailed_changes[data_type][:50]:  # Limit details
                        f.write(f"    - {change}\n")
                    if len(merger.detailed_changes[data_type]) > 50:
                        f.write(f"    ... and {len(merger.detailed_changes[data_type]) - 50} more\n")
        
        # JSON report
        json_path = self.log_dir / f"navdata_changes_{timestamp}.json"
        report_data = {
            'timestamp': datetime.now().isoformat(),
            'cycles': [AIRACCycle.get_cycle_id(c) for c in cycles],
            'summary': merger.changes,
            'details': {k: v[:100] for k, v in merger.detailed_changes.items()}
        }
        with open(json_path, 'w') as f:
            json.dump(report_data, f, indent=2)
        
        return str(text_path), str(json_path)


class NASRNavDataUpdater:
    """Main orchestrator for NASR NavData updates."""
    
    def __init__(self, output_dir: str = 'assets/data', js_dir: str = 'assets/js',
                 cache_dir: str = '.nasr_cache', force: bool = False, 
                 no_backup: bool = False, current_only: bool = False,
                 skip_playbook: bool = True, verbose: bool = False,
                 dry_run: bool = False):
        self.output_dir = Path(output_dir)
        self.js_dir = Path(js_dir)
        self.cache_dir = Path(cache_dir)
        self.force = force
        self.no_backup = no_backup
        self.current_only = current_only
        self.skip_playbook = skip_playbook
        self.verbose = verbose
        self.dry_run = dry_run
        
        if verbose:
            logging.getLogger().setLevel(logging.DEBUG)
        
        self.downloader = NASRDownloader(cache_dir, force)
        self.io = NavDataIO(self.output_dir)
        self.js_updater = JSFileUpdater(self.js_dir)
        self.merger = NavDataMerger()
        self.change_logger = ChangeLogger(self.output_dir / 'logs')
    
    def run(self) -> bool:
        """Execute the full update process."""
        logger.info("Starting NASR NavData Update")
        logger.info("=" * 60)
        
        # Get cycles to process
        current_cycle = AIRACCycle.get_current_cycle()
        cycles = [current_cycle]
        
        if not self.current_only:
            next_cycle = AIRACCycle.get_next_cycle()
            cycles.append(next_cycle)
        
        logger.info(f"Processing cycles: {', '.join(AIRACCycle.get_cycle_id(c) for c in cycles)}")
        
        # Download and parse each cycle
        all_parsed_data = []
        for cycle in cycles:
            cycle_id = AIRACCycle.get_cycle_id(cycle)
            logger.info(f"\nProcessing AIRAC {cycle_id} (effective {AIRACCycle.format_date(cycle)})")
            
            zip_path = self.downloader.download_cycle(cycle)
            if not zip_path:
                logger.warning(f"Skipping cycle {cycle_id} - download failed")
                continue
            
            csv_dir = self.downloader.extract_csv_data(zip_path)
            if not csv_dir:
                logger.warning(f"Skipping cycle {cycle_id} - extraction failed")
                continue
            
            parser = NASRParser(csv_dir)
            parsed = {
                'cycle': cycle,
                'fixes': parser.parse_fixes(),
                'navaids': parser.parse_navaids(),
                'airports': parser.parse_airports(),
                'airways': parser.parse_airways(),
                'cdrs': parser.parse_cdrs(),
                'dp_base': parser.parse_dp_base(),
                'dp_routes': parser.parse_dp_routes(),
                'star_base': parser.parse_star_base(),
                'star_routes': parser.parse_star_routes(),
                'pfr': parser.parse_pfr() if not self.skip_playbook else []
            }
            all_parsed_data.append(parsed)
        
        if not all_parsed_data:
            logger.error("No cycles successfully processed")
            return False
        
        # Merge cycle data
        logger.info("\nMerging cycle data...")
        merged = all_parsed_data[0]
        for data in all_parsed_data[1:]:
            merged['fixes'].update(data['fixes'])
            merged['navaids'].update(data['navaids'])
            merged['airports'].update(data['airports'])
            merged['airways'].update(data['airways'])
            merged['cdrs'].update(data['cdrs'])
            merged['dp_base'].update(data['dp_base'])
            merged['dp_routes'].update(data['dp_routes'])
            merged['star_base'].update(data['star_base'])
            merged['star_routes'].update(data['star_routes'])
            merged['pfr'].extend(data['pfr'])
        
        # Transform to route.js formats
        logger.info("\nTransforming data to route.js formats...")
        transformer = NavDataTransformer(merged['airports'])
        
        new_points = transformer.transform_points(
            merged['fixes'], merged['navaids'], merged['airports']
        )
        new_navaids = transformer.transform_navaids(merged['navaids'])
        new_airways = transformer.transform_airways(merged['airways'])
        new_cdrs = transformer.transform_cdrs(merged['cdrs'])
        new_dps = transformer.transform_dps(merged['dp_base'], merged['dp_routes'])
        new_stars = transformer.transform_stars(merged['star_base'], merged['star_routes'])
        
        if self.dry_run:
            logger.info("\n[DRY RUN] Skipping file writes")
            logger.info(f"  Would write {len(new_points)} points")
            logger.info(f"  Would write {len(new_navaids)} navaids")
            logger.info(f"  Would write {len(new_airways)} airways")
            logger.info(f"  Would write {len(new_cdrs)} CDRs")
            logger.info(f"  Would write {len(new_dps)} DP routes")
            logger.info(f"  Would write {len(new_stars)} STAR routes")
            return True
        
        # Create backups
        if not self.no_backup:
            logger.info("\nCreating backups...")
            backup_dir = self.output_dir / 'backups'
            backup_files = ['points.csv', 'navaids.csv', 'awys.csv',
                           'cdrs.csv', 'dp_full_routes.csv', 'star_full_routes.csv']
            for filename in backup_files:
                self.io.backup_file(filename, backup_dir)
            # Clean up old backups, keeping only the 2 most recent of each type
            self.io.cleanup_old_backups(backup_dir, keep_count=2)
        
        # Read existing data
        logger.info("\nReading existing data...")
        existing_points = self.io.read_points_csv('points.csv')
        existing_navaids = self.io.read_points_csv('navaids.csv')
        existing_airways = self.io.read_airways_csv('awys.csv')
        existing_cdrs = self.io.read_cdrs_csv('cdrs.csv')
        existing_dps = self.io.read_structured_csv('dp_full_routes.csv')
        existing_stars = self.io.read_structured_csv('star_full_routes.csv')
        
        # Merge with existing
        logger.info("\nMerging with existing data...")
        final_points = self.merger.merge_points(existing_points, new_points, 'points')
        final_navaids = self.merger.merge_points(existing_navaids, new_navaids, 'navaids')
        final_airways = self.merger.merge_dict_data(existing_airways, new_airways, 'airways')
        final_cdrs = self.merger.merge_dict_data(existing_cdrs, new_cdrs, 'cdrs')
        final_dps = self.merger.merge_list_records(
            existing_dps, new_dps, 
            ['DP_COMPUTER_CODE', 'TRANSITION_COMPUTER_CODE'], 'dps'
        )
        final_stars = self.merger.merge_list_records(
            existing_stars, new_stars,
            ['STAR_COMPUTER_CODE', 'TRANSITION_COMPUTER_CODE'], 'stars'
        )
        
        # Remove duplicates
        logger.info("\nRemoving duplicates...")
        final_points = self.merger.remove_duplicates_points(final_points)
        final_navaids = self.merger.remove_duplicates_points(final_navaids)
        final_dps = self.merger.remove_duplicates_records(final_dps)
        final_stars = self.merger.remove_duplicates_records(final_stars)
        
        # Write output files
        logger.info("\nWriting output files...")
        self.output_dir.mkdir(parents=True, exist_ok=True)
        
        self.io.write_points_csv('points.csv', final_points)
        self.io.write_points_csv('navaids.csv', final_navaids)
        self.io.write_airways_csv('awys.csv', final_airways)
        self.io.write_cdrs_csv('cdrs.csv', final_cdrs)
        
        dp_fields = ['EFF_DATE', 'DP_NAME', 'DP_COMPUTER_CODE', 'ARTCC', 'ORIG_GROUP',
                    'BODY_NAME', 'TRANSITION_COMPUTER_CODE', 'TRANSITION_NAME',
                    'ROUTE_POINTS', 'ROUTE_FROM_ORIG_GROUP']
        self.io.write_structured_csv('dp_full_routes.csv', final_dps, dp_fields)
        
        star_fields = ['EFF_DATE', 'ARRIVAL_NAME', 'STAR_COMPUTER_CODE', 'ARTCC', 'DEST_GROUP',
                      'BODY_NAME', 'TRANSITION_COMPUTER_CODE', 'TRANSITION_NAME',
                      'ROUTE_POINTS', 'ROUTE_FROM_DEST_GROUP']
        self.io.write_structured_csv('star_full_routes.csv', final_stars, star_fields)
        
        # Update JavaScript files
        logger.info("\nUpdating JavaScript files...")
        self.js_dir.mkdir(parents=True, exist_ok=True)
        self.js_updater.update_awys_js(final_airways)
        self.js_updater.update_procs_js(final_dps, final_stars)
        
        # Generate change report
        logger.info("\nGenerating change report...")
        text_report, json_report = self.change_logger.generate_report(
            self.merger, [d['cycle'] for d in all_parsed_data]
        )
        
        # Summary
        logger.info("\n" + "=" * 60)
        logger.info("UPDATE COMPLETE")
        logger.info("=" * 60)
        logger.info(f"Points: {len(final_points)}")
        logger.info(f"Navaids: {len(final_navaids)}")
        logger.info(f"Airways: {len(final_airways)}")
        logger.info(f"CDRs: {len(final_cdrs)}")
        logger.info(f"DP Routes: {len(final_dps)}")
        logger.info(f"STAR Routes: {len(final_stars)}")
        logger.info(f"\nChange report: {text_report}")
        
        return True


def main():
    parser = argparse.ArgumentParser(
        description='Update vATCSCC navigation data from FAA NASR subscriptions'
    )
    parser.add_argument('-o', '--output', default='assets/data',
                       help='Output directory for CSV files (default: assets/data)')
    parser.add_argument('-j', '--js-dir', default='assets/js',
                       help='Output directory for JS files (default: assets/js)')
    parser.add_argument('-c', '--cache', default='.nasr_cache',
                       help='Cache directory (default: .nasr_cache)')
    parser.add_argument('--force', action='store_true',
                       help='Force re-download even if cached')
    parser.add_argument('--no-backup', action='store_true',
                       help='Skip creating backups')
    parser.add_argument('--current-only', action='store_true',
                       help='Only process current cycle (skip next)')
    parser.add_argument('--skip-playbook', action='store_true', default=True,
                       help='Skip updating playbook_routes.csv (default: True)')
    parser.add_argument('--include-playbook', action='store_true',
                       help='Include NASR Preferred Routes in playbook_routes.csv')
    parser.add_argument('--verbose', action='store_true',
                       help='Enable debug output')
    parser.add_argument('--dry-run', action='store_true',
                       help='Parse only, don\'t write files')
    
    args = parser.parse_args()
    
    skip_playbook = not args.include_playbook
    
    updater = NASRNavDataUpdater(
        output_dir=args.output,
        js_dir=args.js_dir,
        cache_dir=args.cache,
        force=args.force,
        no_backup=args.no_backup,
        current_only=args.current_only,
        skip_playbook=skip_playbook,
        verbose=args.verbose,
        dry_run=args.dry_run
    )
    
    success = updater.run()
    sys.exit(0 if success else 1)


if __name__ == '__main__':
    main()
