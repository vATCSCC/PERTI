#!/usr/bin/env python3
"""
Phase 3: Boundary Hierarchy Classification Script

Enriches artcc.json and tracon.json with hierarchy metadata.
Generates boundary_hierarchy.json edge list for import into
boundary_hierarchy tables (Azure SQL + PostGIS).

Usage:
    python scripts/classify_boundary_hierarchy.py
    python scripts/classify_boundary_hierarchy.py --verbose --dry-run
    python scripts/classify_boundary_hierarchy.py --skip-spatial   # No Shapely needed

Requirements:
    pip install shapely   (optional, enables super-center + dashed nesting detection)

Pipeline position (run after refresh, before import):
    php scripts/refresh_vatsim_boundaries.php
    python scripts/classify_boundary_hierarchy.py       # <-- this script
    python scripts/postgis/import_boundaries.py
    php adl/php/import_boundaries.php --type=all
"""

import json
import csv
import os
import sys
import argparse
import time
from collections import defaultdict, Counter
from pathlib import Path

try:
    from shapely.geometry import shape
    from shapely import strtree

    HAS_SHAPELY = True
except ImportError:
    HAS_SHAPELY = False

SCRIPT_DIR = Path(__file__).parent
PROJECT_ROOT = SCRIPT_DIR.parent
GEOJSON_DIR = PROJECT_ROOT / "assets" / "geojson"
DATA_DIR = PROJECT_ROOT / "assets" / "data"

# Super-center: children must tile >= this fraction of parent area
SUPER_CENTER_COVERAGE = 0.50
# Child must have >= this fraction of its area inside parent
CHILD_CONTAINMENT = 0.50
# Minimum children to qualify as super-center
MIN_SUPER_CENTER_CHILDREN = 2

# Manual TRACON -> parent ARTCC overrides for edge cases that
# can't be resolved via apts.csv or spatial containment.
# Format: "TRACON_CODE": "PARENT_ARTCC_CODE"
TRACON_PARENT_OVERRIDES = {
    # International TRACONs without airport data in apts.csv
    # Add entries here as needed after first dry-run reveals unresolved TRACONs
}


def boxes_overlap(b1, b2):
    """Check if two bounding boxes (minx, miny, maxx, maxy) overlap."""
    return not (b1[2] < b2[0] or b2[2] < b1[0] or b1[3] < b2[1] or b2[3] < b1[1])


class BoundaryClassifier:
    def __init__(self, verbose=False, dry_run=False, skip_spatial=False):
        self.verbose = verbose
        self.dry_run = dry_run
        self.skip_spatial = skip_spatial or not HAS_SHAPELY
        self.edges = []
        self.stats = defaultdict(int)

        # Reference data
        self.apts = {}  # airport code -> RESP_ARTCC_ID
        self.tracon_to_artcc = {}  # TRACON code (from Approach ID) -> RESP_ARTCC_ID
        self.artcc_shapes = {}  # artcc_code -> Shapely shape (non-dashed only)

    def log(self, msg, level="INFO"):
        if level == "DEBUG" and not self.verbose:
            return
        prefix = f"  [{level}]" if level != "INFO" else "  "
        print(f"{prefix} {msg}")

    # =========================================================
    # Data Loading
    # =========================================================

    def load_geojson(self, filename):
        """Load a GeoJSON file from the assets/geojson directory."""
        path = GEOJSON_DIR / filename
        if not path.exists():
            self.log(f"{filename} not found at {path}", "ERROR")
            return None
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        count = len(data.get("features", []))
        self.log(f"Loaded {filename}: {count} features")
        return data

    def load_apts(self):
        """Load apts.csv for TRACON -> parent ARTCC lookup."""
        path = DATA_DIR / "apts.csv"
        if not path.exists():
            self.log("apts.csv not found - TRACON parent lookup limited", "WARN")
            return
        # utf-8-sig strips BOM that Excel/Windows tools prepend to CSV files
        with open(path, "r", encoding="utf-8-sig") as f:
            reader = csv.DictReader(f)
            for row in reader:
                arpt_id = (row.get("ARPT_ID") or "").strip()
                icao_id = (row.get("ICAO_ID") or "").strip()
                resp_artcc = (row.get("RESP_ARTCC_ID") or "").strip()
                if not resp_artcc:
                    continue
                if arpt_id:
                    self.apts[arpt_id.upper()] = resp_artcc
                if icao_id:
                    self.apts[icao_id.upper()] = resp_artcc
                # Build reverse lookup: TRACON code -> parent ARTCC
                # apts.csv has "Approach ID" column with TRACON facility codes
                for col in ("Approach ID", "Consolidated Approach ID"):
                    tracon_id = (row.get(col) or "").strip()
                    if tracon_id and tracon_id not in self.tracon_to_artcc:
                        self.tracon_to_artcc[tracon_id] = resp_artcc
        self.log(
            f"Loaded {len(self.apts)} airport mappings, "
            f"{len(self.tracon_to_artcc)} TRACON->ARTCC mappings"
        )

    # =========================================================
    # ARTCC Classification
    # =========================================================

    def classify_artcc(self, geojson):
        """Main ARTCC classification pipeline."""
        features = geojson.get("features", [])

        # Separate dashed vs non-dashed
        dashed = []
        non_dashed = []
        for f in features:
            code = self._artcc_code(f)
            if "-" in code:
                dashed.append(f)
            else:
                non_dashed.append(f)

        self.log(f"{len(non_dashed)} non-dashed, {len(dashed)} dashed features")

        # Super-center detection (spatial analysis)
        super_centers = set()
        sc_children = defaultdict(list)
        if not self.skip_spatial:
            super_centers, sc_children = self._detect_super_centers(non_dashed)
        else:
            self.log("Spatial analysis skipped (--skip-spatial or no Shapely)", "WARN")

        # Dashed containment tree
        dashed_tree = self._build_dashed_tree(dashed)

        # Build code -> feature lookup (for property inheritance)
        code_map = {}
        for f in features:
            code_map[self._artcc_code(f)] = f

        # Assign hierarchy to non-dashed features
        # Collect all super-center child codes for lookup
        all_sc_children = set()
        sc_parent_of = {}  # child_code -> parent super-center code
        for parent, children in sc_children.items():
            for child in children:
                all_sc_children.add(child)
                sc_parent_of[child] = parent

        for f in non_dashed:
            code = self._artcc_code(f)
            p = f["properties"]

            if code in super_centers:
                p["hierarchy_level"] = 0
                p["hierarchy_type"] = "SUPER_CENTER"
                p["is_detection_level"] = False
                p["child_firs"] = ",".join(sorted(sc_children[code]))
                p["child_count"] = len(sc_children[code])
                self.stats["super_centers"] += 1
            elif code in all_sc_children:
                p["hierarchy_level"] = 1
                p["hierarchy_type"] = "OPERATIONAL_FIR"
                p["is_detection_level"] = True
                p["parent_fir"] = sc_parent_of[code]
                self.stats["operational_firs"] += 1
            else:
                p["hierarchy_level"] = 1
                p["hierarchy_type"] = "FIR"
                p["is_detection_level"] = True
                self.stats["standalone_firs"] += 1

        # Assign hierarchy to dashed features
        for f in dashed:
            code = self._artcc_code(f)
            p = f["properties"]
            entry = dashed_tree.get(code, {})

            level = entry.get("level", 2)
            parent = entry.get("parent", code.split("-")[0])
            has_children = entry.get("has_children", False)
            parent_area = entry.get("parent_area")

            p["hierarchy_level"] = level
            p["is_detection_level"] = False
            p["parent_fir"] = parent
            if parent_area:
                p["parent_area"] = parent_area

            # Classify type
            if level == 2:
                if has_children:
                    p["hierarchy_type"] = "AREA_GROUP"
                    self.stats["area_groups"] += 1
                else:
                    p["hierarchy_type"] = "NAMED_SUB_AREA"
                    self.stats["named_sub_areas"] += 1
            else:  # level >= 3
                if has_children:
                    p["hierarchy_type"] = "SUB_AREA_GROUP"
                    self.stats["sub_area_groups"] += 1
                else:
                    p["hierarchy_type"] = "AREA_SECTOR"
                    self.stats["area_sectors"] += 1

            # Inherit VATSIM metadata from parent if missing
            self._inherit_parent_props(p, parent, code_map)

        # Generate ARTCC edges
        for parent, children in sc_children.items():
            for child in children:
                self.edges.append(
                    {"parent": parent, "child": child, "type": "TILES"}
                )

        for code, entry in dashed_tree.items():
            edge_parent = entry.get("parent_area") or entry.get("parent")
            if edge_parent:
                self.edges.append(
                    {"parent": edge_parent, "child": code, "type": "CONTAINS"}
                )

        return geojson

    def _artcc_code(self, feature):
        """Extract ARTCC code from a feature."""
        p = feature.get("properties", {})
        return p.get("ICAOCODE") or p.get("FIRname") or ""

    def _inherit_parent_props(self, props, parent_code, code_map):
        """Inherit VATSIM metadata from parent if missing on sub-area."""
        parent_f = code_map.get(parent_code)
        if not parent_f:
            return
        pp = parent_f["properties"]
        for key in ("VATSIM Reg", "VATSIM Div", "VATSIM Sub", "ICAOCODE"):
            if not props.get(key) and pp.get(key):
                # For ICAOCODE on sub-areas, keep the sub-area's own code
                if key == "ICAOCODE":
                    continue
                props[key] = pp[key]

    def _detect_super_centers(self, non_dashed):
        """Detect super-centers via Shapely spatial containment analysis.

        A super-center is a non-dashed ARTCC whose children (other non-dashed
        ARTCCs with >=50% of their area inside it) collectively tile >=50%
        of the parent's area. US ARTCCs (Z-prefix) are excluded.
        """
        self.log("Detecting super-centers...")

        # Build Shapely shapes
        shapes = {}
        for f in non_dashed:
            code = self._artcc_code(f)
            try:
                geom = shape(f["geometry"])
                if not geom.is_valid:
                    geom = geom.buffer(0)
                shapes[code] = geom
            except Exception as e:
                self.log(f"Bad geometry for {code}: {e}", "WARN")
        self.artcc_shapes = shapes

        self.log(f"Built {len(shapes)} shapes for spatial analysis", "DEBUG")

        # Pre-compute bounding boxes and areas
        bboxes = {}
        areas = {}
        for code, geom in shapes.items():
            bboxes[code] = geom.bounds
            areas[code] = geom.area

        codes = list(shapes.keys())
        super_centers = set()
        children_map = defaultdict(list)

        for parent_code in codes:
            parent_area = areas[parent_code]
            if parent_area <= 0:
                continue

            # Skip US ARTCCs as super-center candidates
            if len(parent_code) == 3 and parent_code.startswith("Z"):
                continue
            if parent_code.startswith("KZ"):
                continue

            parent_geom = shapes[parent_code]
            parent_bbox = bboxes[parent_code]
            total_child_area = 0
            child_list = []

            for child_code in codes:
                if child_code == parent_code:
                    continue
                child_area = areas[child_code]
                if child_area <= 0 or child_area >= parent_area:
                    continue

                # Bounding box pre-filter
                if not boxes_overlap(parent_bbox, bboxes[child_code]):
                    continue

                child_geom = shapes[child_code]
                try:
                    ix = parent_geom.intersection(child_geom)
                    ix_area = ix.area
                except Exception:
                    continue

                containment = ix_area / child_area
                if containment >= CHILD_CONTAINMENT:
                    child_list.append(child_code)
                    total_child_area += ix_area

            coverage = total_child_area / parent_area
            if coverage >= SUPER_CENTER_COVERAGE and len(child_list) >= MIN_SUPER_CENTER_CHILDREN:
                super_centers.add(parent_code)
                children_map[parent_code] = child_list
                self.log(
                    f"Super-center: {parent_code} "
                    f"({len(child_list)} children, coverage={coverage:.2f})",
                    "DEBUG",
                )

        self.log(f"Found {len(super_centers)} super-centers")
        return super_centers, children_map

    def _build_dashed_tree(self, dashed_features):
        """Build hierarchy tree for dashed ARTCC sub-area codes.

        Groups dashed features by base code (before first dash), then
        uses Shapely spatial containment to determine nesting within
        each group. Falls back to flat Level 2 if Shapely unavailable.
        """
        self.log("Building dashed containment tree...")

        # Group by base code
        by_base = defaultdict(list)
        for f in dashed_features:
            code = self._artcc_code(f)
            base = code.split("-")[0]
            by_base[base].append((code, f))

        tree = {}  # code -> {parent, level, has_children, parent_area}

        # Build Shapely shapes for dashed features if spatial enabled
        dashed_shapes = {}
        if not self.skip_spatial:
            for f in dashed_features:
                code = self._artcc_code(f)
                try:
                    geom = shape(f["geometry"])
                    if not geom.is_valid:
                        geom = geom.buffer(0)
                    dashed_shapes[code] = geom
                except Exception:
                    pass

        for base, items in by_base.items():
            codes_in_group = [c for c, _ in items]

            # Single sub-area: direct child of base
            if len(codes_in_group) == 1:
                tree[codes_in_group[0]] = {
                    "parent": base,
                    "level": 2,
                    "has_children": False,
                    "parent_area": None,
                }
                continue

            # Multiple sub-areas: determine nesting
            if dashed_shapes and all(c in dashed_shapes for c in codes_in_group):
                parent_map = self._spatial_nesting(codes_in_group, dashed_shapes)
            else:
                # No spatial data: all are flat Level 2 children of base
                parent_map = {c: None for c in codes_in_group}

            # Compute levels via recursive walk
            level_cache = {}

            def get_level(code):
                if code in level_cache:
                    return level_cache[code]
                p = parent_map.get(code)
                if not p:
                    level_cache[code] = 2  # direct child of base
                else:
                    level_cache[code] = get_level(p) + 1
                return level_cache[code]

            for code in codes_in_group:
                level = get_level(code)
                tree[code] = {
                    "parent": base,
                    "level": level,
                    "has_children": False,
                    "parent_area": parent_map.get(code),
                }

            # Mark nodes that have children
            for code in codes_in_group:
                pa = tree[code].get("parent_area")
                if pa and pa in tree:
                    tree[pa]["has_children"] = True

        self.log(
            f"Dashed tree: {len(tree)} entries across {len(by_base)} base codes"
        )
        return tree

    def _spatial_nesting(self, codes, shapes_dict):
        """Determine parent-child nesting within a group of dashed codes.

        For each code, find the smallest enclosing sibling (containment >=50%).
        Returns dict: child_code -> immediate_parent_code (or None if root).
        """
        areas = {c: shapes_dict[c].area for c in codes}
        parent_map = {}

        for child_code in codes:
            child_geom = shapes_dict[child_code]
            child_area = areas[child_code]
            if child_area <= 0:
                parent_map[child_code] = None
                continue

            best_parent = None
            best_parent_area = float("inf")

            for candidate in codes:
                if candidate == child_code:
                    continue
                cand_geom = shapes_dict[candidate]
                cand_area = areas[candidate]
                # Parent must be larger
                if cand_area <= child_area:
                    continue

                try:
                    ix = cand_geom.intersection(child_geom)
                    containment = ix.area / child_area
                except Exception:
                    continue

                if containment >= 0.50 and cand_area < best_parent_area:
                    best_parent = candidate
                    best_parent_area = cand_area

            parent_map[child_code] = best_parent

        return parent_map

    # =========================================================
    # TRACON Classification
    # =========================================================

    def classify_tracon(self, geojson):
        """TRACON field restructuring and hierarchy classification.

        Field swap:
            old sector (SimAware id, e.g. "A80") -> new tracon (facility code)
            old artcc  (derived, e.g. "atl")     -> new sector (subdivision, uppercased)
            old artcc field is removed

        For standalone TRACONs (single feature per facility):
            sector = tracon (same value, e.g. both "A11")

        For multi-feature TRACONs (e.g. A80 with 4 features):
            sector = uppercased old artcc (unique per feature: ATL, AHN, CSG, MCN)
        """
        features = geojson.get("features", [])
        self.log(f"Classifying {len(features)} TRACON features...")

        # Group by old sector (= TRACON facility code)
        by_tracon = defaultdict(list)
        for f in features:
            tracon_code = f["properties"].get("sector", "")
            by_tracon[tracon_code].append(f)

        multi_count = sum(1 for v in by_tracon.values() if len(v) > 1)
        self.log(f"{len(by_tracon)} unique TRACONs ({multi_count} multi-feature)")

        for tracon_code, tracon_features in by_tracon.items():
            is_multi = len(tracon_features) > 1

            # Determine parent ARTCC from any feature in the group
            # (all features in a group share the same TRACON, so parent is the same)
            # Use the first feature's old artcc for lookup
            first_old_artcc = tracon_features[0]["properties"].get("artcc", "")
            parent_fir = self._lookup_tracon_parent(
                tracon_code, first_old_artcc, tracon_features[0]["properties"]
            )

            for f in tracon_features:
                props = f["properties"]
                old_artcc = props.get("artcc", "")
                old_sector = props.get("sector", "")

                # Field restructuring
                new_tracon = old_sector
                if is_multi:
                    new_sector = old_artcc.upper() if old_artcc else old_sector
                else:
                    # Standalone: sector = tracon code (boundary_code = parent_artcc)
                    new_sector = new_tracon

                props["tracon"] = new_tracon
                props["sector"] = new_sector
                props["parent_fir"] = parent_fir
                props["hierarchy_level"] = 2

                if is_multi:
                    props["hierarchy_type"] = "TRACON_SECTOR"
                    self.stats["tracon_sectors"] += 1
                else:
                    props["hierarchy_type"] = "TRACON"
                    self.stats["tracon_standalone"] += 1

                # Remove old artcc field (replaced by sector with swapped semantics)
                props.pop("artcc", None)

            # Generate edges
            if is_multi:
                for f in tracon_features:
                    sector_code = f["properties"]["sector"]
                    self.edges.append(
                        {"parent": tracon_code, "child": sector_code, "type": "TRACON_OF"}
                    )

            if parent_fir:
                self.edges.append(
                    {"parent": parent_fir, "child": tracon_code, "type": "SECTOR_OF"}
                )

        return geojson

    def _lookup_tracon_parent(self, tracon_code, airport_code, props):
        """Multi-step pipeline to find parent ARTCC for a TRACON.

        Steps:
            0. Manual overrides
            1. apts.csv: prepend "K" to airport_code -> ICAO -> RESP_ARTCC_ID
            2. Prefix callsign parsing (if prefix property available)
            3. Try tracon_code itself as an airport code
            4. Spatial containment (if ARTCC shapes available)
        """
        # Step 0: Manual overrides
        if tracon_code in TRACON_PARENT_OVERRIDES:
            return TRACON_PARENT_OVERRIDES[tracon_code]

        # Step 0b: TRACON code -> ARTCC via Approach ID column in apts.csv
        if tracon_code in self.tracon_to_artcc:
            return self.tracon_to_artcc[tracon_code]

        # Step 1: apts.csv lookup via airport-derived code
        if airport_code:
            icao = f"K{airport_code.upper()}"
            if icao in self.apts:
                return self.apts[icao]
            faa = airport_code.upper()
            if faa in self.apts:
                return self.apts[faa]

        # Step 2: Parse prefix callsign
        prefix = props.get("prefix")
        if prefix:
            if isinstance(prefix, list):
                prefix = prefix[0] if prefix else None
            if prefix and isinstance(prefix, str):
                facility = prefix.split("_")[0] if "_" in prefix else prefix[:3]
                icao = f"K{facility.upper()}"
                if icao in self.apts:
                    return self.apts[icao]

        # Step 3: Try tracon_code as airport code
        icao = f"K{tracon_code}"
        if icao in self.apts:
            return self.apts[icao]
        if tracon_code in self.apts:
            return self.apts[tracon_code]

        # Step 4: Spatial containment
        if not self.skip_spatial and self.artcc_shapes:
            parent = self._spatial_tracon_parent(tracon_code, props)
            if parent:
                return parent

        self.log(
            f"No parent ARTCC for TRACON {tracon_code} (airport={airport_code})",
            "WARN",
        )
        self.stats["tracon_unresolved"] += 1
        return None

    def _spatial_tracon_parent(self, tracon_code, props):
        """Find parent ARTCC via spatial containment of TRACON label point."""
        label_lat = props.get("label_lat")
        label_lon = props.get("label_lon")
        if label_lat is None or label_lon is None:
            return None

        from shapely.geometry import Point

        pt = Point(float(label_lon), float(label_lat))
        best_code = None
        best_area = float("inf")

        for code, geom in self.artcc_shapes.items():
            if geom.contains(pt):
                area = geom.area
                if area < best_area:
                    best_code = code
                    best_area = area

        if best_code:
            self.log(
                f"Spatial match: TRACON {tracon_code} -> {best_code}", "DEBUG"
            )
        return best_code

    # =========================================================
    # Sector Classification (edges only)
    # =========================================================

    def classify_sectors(self, geojson, sector_type):
        """Generate sector -> ARTCC edges. No GeoJSON enrichment needed."""
        features = geojson.get("features", [])
        count = 0

        for f in features:
            props = f["properties"]
            artcc = (props.get("artcc") or props.get("ARTCC") or "").upper()
            sector = props.get("sector", "")
            sector_code = f"{artcc}{sector}" if artcc and sector else sector or "UNK"

            if artcc:
                self.edges.append(
                    {"parent": artcc, "child": sector_code, "type": "SECTOR_OF"}
                )
                count += 1

        self.log(f"{count} sector edges for {sector_type}")

    # =========================================================
    # Output
    # =========================================================

    def write_outputs(self, artcc_geojson, tracon_geojson):
        """Write enriched GeoJSON and boundary_hierarchy.json edge list."""
        if self.dry_run:
            self.log("DRY RUN - skipping file writes")
            return

        # Enriched artcc.json (compact, matches original format)
        artcc_path = GEOJSON_DIR / "artcc.json"
        with open(artcc_path, "w", encoding="utf-8") as f:
            json.dump(artcc_geojson, f, separators=(",", ":"))
        size_mb = artcc_path.stat().st_size / (1024 * 1024)
        self.log(f"Wrote {artcc_path.name} ({size_mb:.1f} MB)")

        # Enriched tracon.json (compact)
        tracon_path = GEOJSON_DIR / "tracon.json"
        with open(tracon_path, "w", encoding="utf-8") as f:
            json.dump(tracon_geojson, f, separators=(",", ":"))
        size_mb = tracon_path.stat().st_size / (1024 * 1024)
        self.log(f"Wrote {tracon_path.name} ({size_mb:.1f} MB)")

        # Hierarchy edge list (readable)
        edge_counts = Counter(e["type"] for e in self.edges)
        hierarchy = {
            "edges": self.edges,
            "metadata": {
                "computed_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
                "total_edges": len(self.edges),
                "edge_types": dict(edge_counts),
            },
        }
        hier_path = GEOJSON_DIR / "boundary_hierarchy.json"
        with open(hier_path, "w", encoding="utf-8") as f:
            json.dump(hierarchy, f, indent=2)
        self.log(f"Wrote {hier_path.name}: {len(self.edges)} edges")

    def print_summary(self):
        """Print classification summary."""
        print("\n" + "=" * 60)
        print("CLASSIFICATION SUMMARY")
        print("=" * 60)

        print("\nARTCC Hierarchy:")
        print(f"  Super-centers (L0):      {self.stats['super_centers']}")
        print(f"  Standalone FIRs (L1):    {self.stats['standalone_firs']}")
        print(f"  Operational FIRs (L1):   {self.stats['operational_firs']}")
        print(f"  Area Groups (L2):        {self.stats['area_groups']}")
        print(f"  Named Sub-Areas (L2+):   {self.stats['named_sub_areas']}")
        print(f"  Sub-Area Groups (L3+):   {self.stats['sub_area_groups']}")
        print(f"  Area Sectors (L3+):      {self.stats['area_sectors']}")

        total_artcc = sum(
            self.stats[k]
            for k in [
                "super_centers",
                "standalone_firs",
                "operational_firs",
                "area_groups",
                "named_sub_areas",
                "sub_area_groups",
                "area_sectors",
            ]
        )
        print(f"  Total:                   {total_artcc}")

        print("\nTRACON:")
        print(f"  Standalone TRACONs:      {self.stats['tracon_standalone']}")
        print(f"  TRACON Sectors:          {self.stats['tracon_sectors']}")
        print(f"  Unresolved parent:       {self.stats['tracon_unresolved']}")

        print("\nEdges:")
        edge_types = Counter(e["type"] for e in self.edges)
        for t, c in sorted(edge_types.items()):
            print(f"  {t:20s} {c}")
        print(f"  {'TOTAL':20s} {len(self.edges)}")


def main():
    parser = argparse.ArgumentParser(
        description="Classify boundary hierarchy and enrich GeoJSON files"
    )
    parser.add_argument("--verbose", "-v", action="store_true", help="Verbose output")
    parser.add_argument(
        "--dry-run", action="store_true", help="Analyze without writing files"
    )
    parser.add_argument(
        "--skip-spatial",
        action="store_true",
        help="Skip Shapely spatial analysis (no super-center detection)",
    )
    args = parser.parse_args()

    print("=" * 60)
    print("PERTI Boundary Hierarchy Classification")
    print("=" * 60)

    if not HAS_SHAPELY and not args.skip_spatial:
        print(
            "\nWARNING: Shapely not installed. Super-center detection disabled."
        )
        print("Install with: pip install shapely\n")

    classifier = BoundaryClassifier(
        verbose=args.verbose,
        dry_run=args.dry_run,
        skip_spatial=args.skip_spatial,
    )

    # Load reference data
    print("\nLoading reference data...")
    classifier.load_apts()

    # Classify ARTCC boundaries
    print("\n--- ARTCC Classification ---")
    artcc = classifier.load_geojson("artcc.json")
    if artcc:
        artcc = classifier.classify_artcc(artcc)

    # Classify TRACON boundaries
    print("\n--- TRACON Classification ---")
    tracon = classifier.load_geojson("tracon.json")
    if tracon:
        tracon = classifier.classify_tracon(tracon)

    # Classify sectors (edges only, no GeoJSON enrichment)
    print("\n--- Sector Classification ---")
    for sector_type in ["high", "low", "superhigh"]:
        path = GEOJSON_DIR / f"{sector_type}.json"
        if path.exists():
            sectors = classifier.load_geojson(f"{sector_type}.json")
            if sectors:
                classifier.classify_sectors(sectors, sector_type)

    # Write outputs
    print("\n--- Writing Outputs ---")
    if artcc and tracon:
        classifier.write_outputs(artcc, tracon)

    classifier.print_summary()
    print("\nDone!")


if __name__ == "__main__":
    main()
