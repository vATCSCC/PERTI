#!/usr/bin/env python3
"""
Automated Boundary Update Script (AIRAC-Aligned)

Updates CDM sector boundaries (from vIFF) and TRACON boundaries (from SimAware),
then regenerates hierarchy edges in boundary_hierarchy.json.

Data sources:
  - CDM sectors: rpuig2001/vIFF-Capacity-Availability-Document -> global/airblocks.geojson
  - TRACONs:     vatsimnetwork/simaware-tracon-project -> Boundaries/{facility}/*.json

Usage:
    python scripts/update_boundaries.py                  # Full update
    python scripts/update_boundaries.py --dry-run        # Preview changes
    python scripts/update_boundaries.py --cdm-only       # Only update CDM sectors
    python scripts/update_boundaries.py --tracon-only    # Only update TRACONs
    python scripts/update_boundaries.py --skip-hierarchy  # Skip hierarchy regen
    python scripts/update_boundaries.py --force          # Re-download even if cached

Requirements:
    - Python 3.8+
    - requests
    - shapely (for centroid/containment tests)
"""

import argparse
import io
import json
import os
import sys
import tarfile
import time
from datetime import datetime, timezone
from pathlib import Path

try:
    import requests
except ImportError:
    print("ERROR: 'requests' package required. Install with: pip install requests")
    sys.exit(1)

try:
    from shapely.geometry import shape, Point, MultiPolygon
    HAS_SHAPELY = True
except ImportError:
    HAS_SHAPELY = False


# ==============================================================================
# Configuration
# ==============================================================================

SCRIPT_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPT_DIR.parent
GEOJSON_DIR = PROJECT_ROOT / "assets" / "geojson"

VIFF_URL = (
    "https://raw.githubusercontent.com/rpuig2001/"
    "vIFF-Capacity-Availability-Document/main/global/airblocks.geojson"
)
SIMAWARE_TARBALL_URL = (
    "https://api.github.com/repos/vatsimnetwork/"
    "simaware-tracon-project/tarball"
)

CANONICAL_FILES = {
    "high": GEOJSON_DIR / "high.json",
    "low": GEOJSON_DIR / "low.json",
    "superhigh": GEOJSON_DIR / "superhigh.json",
}
TRACON_FILE = GEOJSON_DIR / "tracon.json"
HIERARCHY_FILE = GEOJSON_DIR / "boundary_hierarchy.json"
ARTCC_FILE = GEOJSON_DIR / "artcc.json"

# FL classification thresholds (derived from current data)
# LOW:       maxFL <= 245
# SUPERHIGH: minFL >= 355
# HIGH:      everything else


# ==============================================================================
# ARTCC Polygon Cache (for FIR / parent_fir resolution)
# ==============================================================================

_artcc_cache = None


def load_artcc_polygons():
    """Load artcc.json polygons for spatial containment tests."""
    global _artcc_cache
    if _artcc_cache is not None:
        return _artcc_cache

    if not HAS_SHAPELY:
        print("  WARNING: shapely not installed, FIR resolution will be limited")
        _artcc_cache = {}
        return _artcc_cache

    with open(ARTCC_FILE, "r", encoding="utf-8") as f:
        data = json.load(f)

    cache = {}
    for feat in data["features"]:
        props = feat["properties"]
        code = props.get("ICAOCODE", "")
        if not code:
            continue
        try:
            geom = shape(feat["geometry"])
            if not geom.is_valid:
                geom = geom.buffer(0)
            cache[code] = geom
        except Exception:
            continue

    _artcc_cache = cache
    print(f"  Loaded {len(cache)} ARTCC polygons for FIR resolution")
    return cache


def resolve_fir_for_point(lon, lat, artcc_polygons):
    """Find which ARTCC/FIR contains a given point. Returns ICAO code or None."""
    if not artcc_polygons:
        return None
    pt = Point(lon, lat)
    for code, geom in artcc_polygons.items():
        try:
            if geom.contains(pt):
                return code
        except Exception:
            continue
    return None


def resolve_fir_for_geometry(geom, artcc_polygons):
    """Find which ARTCC/FIR contains a geometry's centroid. Returns ICAO code or None."""
    if not artcc_polygons:
        return None
    try:
        centroid = geom.centroid
        return resolve_fir_for_point(centroid.x, centroid.y, artcc_polygons)
    except Exception:
        return None


# ==============================================================================
# HTTP Helpers
# ==============================================================================

def download_with_retry(url, max_retries=3, timeout=60, stream=False):
    """Download URL with exponential backoff retry."""
    headers = {"User-Agent": "PERTI-BoundaryUpdater/1.0"}
    # Use GitHub token if available
    gh_token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if gh_token:
        headers["Authorization"] = f"token {gh_token}"

    for attempt in range(max_retries):
        try:
            resp = requests.get(url, headers=headers, timeout=timeout, stream=stream)
            resp.raise_for_status()
            return resp
        except requests.RequestException as e:
            if attempt < max_retries - 1:
                wait = 2 ** (attempt + 1)
                print(f"  Retry {attempt + 1}/{max_retries} after {wait}s: {e}")
                time.sleep(wait)
            else:
                raise


# ==============================================================================
# CDM Sector Update
# ==============================================================================

def classify_fl(min_fl, max_fl):
    """Classify a sector into LOW/HIGH/SUPERHIGH based on FL range."""
    if max_fl <= 245:
        return "low"
    if min_fl >= 355:
        return "superhigh"
    return "high"


def parse_viff_id(viff_id):
    """
    Parse a vIFF sector ID into (prefix, suffix).

    Examples:
      "EDGG-HEF1U" -> ("EDGG", "HEF1U")
      "EISN-ALL"   -> ("EISN", "ALL")
      "EI-1AU"     -> ("EI", "1AU")
    """
    if "-" in viff_id:
        idx = viff_id.index("-")
        return viff_id[:idx], viff_id[idx + 1:]
    # No dash - treat entire ID as prefix (edge case)
    return viff_id, ""


def transform_viff_feature(feat, obj_id, artcc_polygons):
    """
    Transform a vIFF airblocks feature to canonical CDM format.

    Returns (tier, transformed_feature) or (None, None) if invalid.
    """
    props = feat.get("properties", {})
    geom = feat.get("geometry")

    if not geom or not props.get("id"):
        return None, None

    viff_id = props["id"]
    min_fl = props.get("minFL", 0)
    max_fl = props.get("maxFL", 999)

    prefix, suffix = parse_viff_id(viff_id)
    tier = classify_fl(min_fl, max_fl)

    # Determine FIR code
    fir_code = prefix.upper()

    # If prefix is < 4 chars, try spatial resolution
    if len(prefix) < 4 and HAS_SHAPELY and artcc_polygons:
        try:
            sector_geom = shape(geom)
            if not sector_geom.is_valid:
                sector_geom = sector_geom.buffer(0)
            resolved = resolve_fir_for_geometry(sector_geom, artcc_polygons)
            if resolved:
                fir_code = resolved
            else:
                print(f"  WARNING: Could not resolve FIR for short prefix '{prefix}' "
                      f"(sector {viff_id}), using '{fir_code}'")
        except Exception as e:
            print(f"  WARNING: Spatial resolution failed for {viff_id}: {e}")

    canonical_props = {
        "OBJECTID": obj_id,
        "artcc": fir_code.lower(),
        "sector": suffix if suffix else viff_id,
        "label": fir_code + suffix,
        "l1_fir": fir_code,
        "min_fl": min_fl,
        "max_fl": max_fl,
        "Shape_Length": 0,
        "Shape_Area": 0,
    }

    return tier, {
        "type": "Feature",
        "properties": canonical_props,
        "geometry": geom,
    }


def update_cdm_sectors(dry_run=False):
    """
    Download vIFF airblocks and merge CDM sectors into canonical files.

    Returns dict of {tier: (added, removed, total)} or None on failure.
    """
    print("\n  --- CDM Sector Update ---")

    # Download vIFF data
    print(f"  Downloading vIFF airblocks from GitHub...")
    try:
        resp = download_with_retry(VIFF_URL)
    except Exception as e:
        print(f"  ERROR: Failed to download vIFF data: {e}")
        return None

    try:
        viff_data = resp.json()
    except json.JSONDecodeError as e:
        print(f"  ERROR: Invalid JSON in vIFF response: {e}")
        return None

    viff_features = viff_data.get("features", [])
    print(f"  Downloaded {len(viff_features)} features from vIFF")

    # Load ARTCC polygons for FIR resolution
    artcc_polygons = load_artcc_polygons()

    # Classify and transform
    classified = {"high": [], "low": [], "superhigh": []}
    skipped = 0

    for feat in viff_features:
        try:
            tier, transformed = transform_viff_feature(feat, 0, artcc_polygons)
            if tier and transformed:
                classified[tier].append(transformed)
            else:
                skipped += 1
        except Exception as e:
            print(f"  WARNING: Skipping malformed feature: {e}")
            skipped += 1

    print(f"  Classified: HIGH={len(classified['high'])}, "
          f"LOW={len(classified['low'])}, "
          f"SUPERHIGH={len(classified['superhigh'])}, "
          f"skipped={skipped}")

    # Merge into each canonical file
    results = {}
    for tier, new_cdm in classified.items():
        filepath = CANONICAL_FILES[tier]
        if not filepath.exists():
            print(f"  WARNING: {filepath.name} not found, skipping")
            continue

        with open(filepath, "r", encoding="utf-8") as f:
            data = json.load(f)

        existing = data.get("features", [])
        # Strip old CDM features (have l1_fir property)
        us_features = [f for f in existing if "l1_fir" not in f.get("properties", {})]
        old_cdm_count = len(existing) - len(us_features)

        # Re-number OBJECTID sequentially
        combined = us_features + new_cdm
        for i, feat in enumerate(combined, 1):
            feat["properties"]["OBJECTID"] = i

        results[tier] = (len(new_cdm), old_cdm_count, len(combined))

        print(f"  {tier}.json: US={len(us_features)}, "
              f"CDM {old_cdm_count}->{len(new_cdm)}, "
              f"total={len(combined)}")

        if not dry_run:
            data["features"] = combined
            with open(filepath, "w", encoding="utf-8") as f:
                json.dump(data, f)
            print(f"    Written: {filepath.name}")

    return results


# ==============================================================================
# TRACON Boundary Update
# ==============================================================================

def download_simaware_boundaries():
    """
    Download SimAware TRACON boundaries via GitHub tarball API.

    Returns list of (facility_dir, filename_stem, feature_json) tuples.
    """
    print(f"  Downloading SimAware tarball from GitHub...")

    try:
        resp = download_with_retry(SIMAWARE_TARBALL_URL, timeout=120, stream=True)
    except Exception as e:
        print(f"  ERROR: Failed to download SimAware tarball: {e}")
        return None

    # Extract Boundaries/ files from tarball
    features = []
    try:
        raw = io.BytesIO(resp.content)
        with tarfile.open(fileobj=raw, mode="r:gz") as tar:
            for member in tar.getmembers():
                # Path format: {repo-prefix}/Boundaries/{facility}/{sector}.json
                parts = member.name.split("/")
                # Find the "Boundaries" segment
                try:
                    b_idx = parts.index("Boundaries")
                except ValueError:
                    continue

                # Must have facility dir and .json file after Boundaries/
                if len(parts) < b_idx + 3:
                    continue
                if not parts[-1].endswith(".json"):
                    continue

                facility_dir = parts[b_idx + 1]
                filename_stem = parts[-1].rsplit(".", 1)[0]

                try:
                    fobj = tar.extractfile(member)
                    if fobj is None:
                        continue
                    content = json.loads(fobj.read().decode("utf-8"))
                    features.append((facility_dir, filename_stem, content))
                except (json.JSONDecodeError, Exception) as e:
                    print(f"  WARNING: Skipping {member.name}: {e}")

    except tarfile.TarError as e:
        print(f"  ERROR: Failed to extract tarball: {e}")
        return None

    print(f"  Extracted {len(features)} TRACON boundary files")
    return features


def transform_tracon_feature(facility_dir, filename_stem, content, obj_id, artcc_polygons):
    """
    Transform a SimAware TRACON feature to canonical format.

    Returns transformed feature dict or None.
    """
    geom = content.get("geometry")
    if not geom:
        return None

    # SimAware properties
    sa_id = content.get("id", facility_dir)
    sa_prefix = content.get("prefix", [filename_stem])
    sa_name = content.get("name", "")
    sa_label_lat = content.get("label_lat")
    sa_label_lon = content.get("label_lon")

    # Determine sector identifier
    sector_id = sa_prefix[0] if sa_prefix else filename_stem

    # Determine hierarchy type
    if sector_id == sa_id:
        hierarchy_type = "TRACON"
    else:
        hierarchy_type = "TRACON_SECTOR"

    # Resolve parent FIR via spatial containment
    parent_fir = None
    if HAS_SHAPELY and artcc_polygons:
        try:
            tracon_geom = shape(geom)
            if not tracon_geom.is_valid:
                tracon_geom = tracon_geom.buffer(0)
            parent_fir = resolve_fir_for_geometry(tracon_geom, artcc_polygons)
        except Exception:
            pass

    return {
        "type": "Feature",
        "properties": {
            "OBJECTID": obj_id,
            "sector": sector_id,
            "label": sa_name,
            "prefix": sa_prefix,
            "Shape_Length": 0,
            "Shape_Area": 0,
            "label_lat": sa_label_lat,
            "label_lon": sa_label_lon,
            "tracon": sa_id,
            "parent_fir": parent_fir,
            "hierarchy_level": 2,
            "hierarchy_type": hierarchy_type,
        },
        "geometry": geom,
    }


def update_tracon_boundaries(dry_run=False):
    """
    Download SimAware TRACON boundaries and write to tracon.json.

    Returns (feature_count, tracon_count, tracon_sector_count) or None on failure.
    """
    print("\n  --- TRACON Boundary Update ---")

    raw_features = download_simaware_boundaries()
    if raw_features is None:
        return None

    # Load ARTCC polygons for parent FIR resolution
    artcc_polygons = load_artcc_polygons()

    # Transform all features
    transformed = []
    skipped = 0
    for facility_dir, filename_stem, content in raw_features:
        try:
            feat = transform_tracon_feature(
                facility_dir, filename_stem, content, 0, artcc_polygons
            )
            if feat:
                transformed.append(feat)
            else:
                skipped += 1
        except Exception as e:
            print(f"  WARNING: Skipping {facility_dir}/{filename_stem}: {e}")
            skipped += 1

    # Re-number OBJECTID
    for i, feat in enumerate(transformed, 1):
        feat["properties"]["OBJECTID"] = i

    tracon_count = sum(
        1 for f in transformed
        if f["properties"]["hierarchy_type"] == "TRACON"
    )
    sector_count = sum(
        1 for f in transformed
        if f["properties"]["hierarchy_type"] == "TRACON_SECTOR"
    )

    print(f"  Transformed: {len(transformed)} features "
          f"(TRACON={tracon_count}, TRACON_SECTOR={sector_count}), "
          f"skipped={skipped}")

    if not dry_run:
        # Load existing to preserve GeoJSON wrapper structure
        if TRACON_FILE.exists():
            with open(TRACON_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
        else:
            data = {"type": "FeatureCollection", "features": []}

        old_count = len(data.get("features", []))
        data["features"] = transformed

        with open(TRACON_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f)
        print(f"    Written: {TRACON_FILE.name} ({old_count} -> {len(transformed)} features)")

    return len(transformed), tracon_count, sector_count


# ==============================================================================
# Hierarchy Edge Update
# ==============================================================================

def collect_cdm_labels():
    """Collect all CDM labels across canonical sector files."""
    labels = {}  # label -> l1_fir
    for tier, filepath in CANONICAL_FILES.items():
        if not filepath.exists():
            continue
        with open(filepath, "r", encoding="utf-8") as f:
            data = json.load(f)
        for feat in data["features"]:
            props = feat["properties"]
            if "l1_fir" in props:
                labels[props["label"]] = props["l1_fir"]
    return labels


def update_hierarchy(dry_run=False, cdm_updated=True, tracon_updated=True):
    """
    Regenerate CDM SECTOR_OF and TRACON_OF edges in boundary_hierarchy.json.

    Returns (total_edges, edge_type_counts) or None on failure.
    """
    print("\n  --- Hierarchy Edge Update ---")

    if not HIERARCHY_FILE.exists():
        print(f"  ERROR: {HIERARCHY_FILE.name} not found")
        return None

    with open(HIERARCHY_FILE, "r", encoding="utf-8") as f:
        hierarchy = json.load(f)

    edges = hierarchy.get("edges", [])

    # Collect current CDM labels for identification
    cdm_labels = collect_cdm_labels()
    print(f"  CDM labels collected: {len(cdm_labels)}")

    # Separate edges by type
    tiles_edges = [e for e in edges if e["type"] == "TILES"]
    contains_edges = [e for e in edges if e["type"] == "CONTAINS"]

    if cdm_updated:
        # Remove old CDM SECTOR_OF edges and regenerate
        us_sector_of = [
            e for e in edges
            if e["type"] == "SECTOR_OF" and e["parent"] not in cdm_labels
        ]
        # Generate new CDM SECTOR_OF edges (deduplicated by label)
        new_cdm_sector_of = [
            {"parent": label, "child": fir, "type": "SECTOR_OF"}
            for label, fir in sorted(cdm_labels.items())
        ]
        sector_of_edges = us_sector_of + new_cdm_sector_of
        print(f"  SECTOR_OF: US={len(us_sector_of)}, CDM={len(new_cdm_sector_of)}")
    else:
        sector_of_edges = [e for e in edges if e["type"] == "SECTOR_OF"]
        print(f"  SECTOR_OF: unchanged ({len(sector_of_edges)})")

    if tracon_updated:
        # Regenerate all TRACON_OF edges from tracon.json
        if TRACON_FILE.exists():
            with open(TRACON_FILE, "r", encoding="utf-8") as f:
                tracon_data = json.load(f)
            tracon_of_edges = []
            for feat in tracon_data.get("features", []):
                props = feat["properties"]
                if props.get("hierarchy_type") == "TRACON_SECTOR":
                    tracon_of_edges.append({
                        "parent": props["tracon"],
                        "child": props["sector"],
                        "type": "TRACON_OF",
                    })
            print(f"  TRACON_OF: regenerated {len(tracon_of_edges)} edges")
        else:
            tracon_of_edges = [e for e in edges if e["type"] == "TRACON_OF"]
            print(f"  TRACON_OF: file missing, kept {len(tracon_of_edges)} existing")
    else:
        tracon_of_edges = [e for e in edges if e["type"] == "TRACON_OF"]
        print(f"  TRACON_OF: unchanged ({len(tracon_of_edges)})")

    # Combine all edges
    all_edges = tiles_edges + contains_edges + sector_of_edges + tracon_of_edges

    edge_counts = {}
    for e in all_edges:
        edge_counts[e["type"]] = edge_counts.get(e["type"], 0) + 1

    print(f"  Total edges: {len(all_edges)} "
          f"(TILES={edge_counts.get('TILES', 0)}, "
          f"CONTAINS={edge_counts.get('CONTAINS', 0)}, "
          f"SECTOR_OF={edge_counts.get('SECTOR_OF', 0)}, "
          f"TRACON_OF={edge_counts.get('TRACON_OF', 0)})")

    if not dry_run:
        hierarchy["edges"] = all_edges
        hierarchy["metadata"] = {
            "computed_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "total_edges": len(all_edges),
            "edge_types": edge_counts,
        }
        with open(HIERARCHY_FILE, "w", encoding="utf-8") as f:
            json.dump(hierarchy, f, indent=2)
        print(f"    Written: {HIERARCHY_FILE.name}")

    return len(all_edges), edge_counts


# ==============================================================================
# Main
# ==============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Update CDM sector and TRACON boundaries from upstream repos",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python scripts/update_boundaries.py                   # Full update
  python scripts/update_boundaries.py --dry-run         # Preview changes
  python scripts/update_boundaries.py --cdm-only        # Only CDM sectors
  python scripts/update_boundaries.py --tracon-only     # Only TRACONs
  python scripts/update_boundaries.py --skip-hierarchy  # Skip hierarchy regen
""",
    )

    parser.add_argument("--dry-run", action="store_true",
                        help="Report what would change without writing files")
    parser.add_argument("--cdm-only", action="store_true",
                        help="Only update CDM sectors")
    parser.add_argument("--tracon-only", action="store_true",
                        help="Only update TRACON boundaries")
    parser.add_argument("--skip-hierarchy", action="store_true",
                        help="Skip hierarchy edge regeneration")
    parser.add_argument("--force", action="store_true",
                        help="Force download (no caching)")

    args = parser.parse_args()

    print()
    print("=" * 60)
    print("     Boundary Update (CDM Sectors + TRACONs)")
    print("=" * 60)
    print(f"  Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    if args.dry_run:
        print("  Mode: DRY RUN (no files will be written)")
    print()

    run_cdm = not args.tracon_only
    run_tracon = not args.cdm_only
    run_hierarchy = not args.skip_hierarchy

    start_time = time.time()
    results = {}

    # CDM Sector Update
    cdm_ok = False
    if run_cdm:
        cdm_result = update_cdm_sectors(args.dry_run)
        if cdm_result is not None:
            results["CDM Sectors"] = "SUCCESS"
            cdm_ok = True
        else:
            results["CDM Sectors"] = "FAILED"
    else:
        print("\n  --- CDM Sector Update: SKIPPED ---")

    # TRACON Boundary Update
    tracon_ok = False
    if run_tracon:
        tracon_result = update_tracon_boundaries(args.dry_run)
        if tracon_result is not None:
            results["TRACON Boundaries"] = "SUCCESS"
            tracon_ok = True
        else:
            results["TRACON Boundaries"] = "FAILED"
    else:
        print("\n  --- TRACON Boundary Update: SKIPPED ---")

    # Hierarchy Edge Update
    if run_hierarchy and (cdm_ok or tracon_ok):
        hierarchy_result = update_hierarchy(
            args.dry_run,
            cdm_updated=cdm_ok,
            tracon_updated=tracon_ok,
        )
        if hierarchy_result is not None:
            results["Hierarchy Edges"] = "SUCCESS"
        else:
            results["Hierarchy Edges"] = "FAILED"
    elif run_hierarchy:
        print("\n  --- Hierarchy Edge Update: SKIPPED (no upstream changes) ---")
    else:
        print("\n  --- Hierarchy Edge Update: SKIPPED ---")

    # Summary
    elapsed = time.time() - start_time
    print()
    print("=" * 60)
    print("                  BOUNDARY UPDATE COMPLETE")
    print("=" * 60)
    print()
    print("  Results:")
    for step_name, status in results.items():
        icon = "+" if status == "SUCCESS" else "X"
        print(f"    [{icon}] {step_name}: {status}")
    print()
    print(f"  Duration: {int(elapsed)}s")
    print(f"  Finished: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")

    failed = [k for k, v in results.items() if v == "FAILED"]
    if failed:
        print()
        print("  WARNINGS:")
        for step in failed:
            print(f"    - {step} failed. Check output above for errors.")
        print()
        sys.exit(1)

    print()
    print("  NEXT STEPS:")
    print("    1. Review GeoJSON changes in assets/geojson/")
    print("    2. Re-import to databases:")
    print("       - PostGIS: python scripts/postgis/import_boundaries.py")
    print("       - Azure SQL: hit import_boundaries.php?type=all")
    print("    3. Commit and deploy")
    print()
    print("=" * 60)


if __name__ == "__main__":
    main()
