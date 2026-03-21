#!/usr/bin/env python3
"""
Generate historical AIRAC changelog by comparing CSV data between two git commits.

Usage:
    python scripts/generate_historical_changelog.py \
        --from-commit 0e05f00 --to-commit ed17e17 \
        --from-cycle 2601 --to-cycle 2602 \
        [--no-db]
"""

import argparse
import csv
import io
import json
import math
import os
import subprocess
import sys
from collections import defaultdict
from datetime import datetime, timezone

CSV_PATHS = {
    'points': 'assets/data/points.csv',
    'navaids': 'assets/data/navaids.csv',
    'airways': 'assets/data/awys.csv',
    'cdrs': 'assets/data/cdrs.csv',
    'dps': 'assets/data/dp_full_routes.csv',
    'stars': 'assets/data/star_full_routes.csv',
    'playbook': 'assets/data/playbook_routes.csv',
}

TYPE_TO_TABLE = {
    'fix': 'nav_fixes',
    'navaid': 'nav_fixes',
    'airway': 'airways',
    'cdr': 'coded_departure_routes',
    'dp': 'nav_procedures',
    'star': 'nav_procedures',
    'playbook': 'playbook_routes',
}


def git_show(commit, path, cwd):
    """Extract file content from a git commit."""
    r = subprocess.run(
        ['git', 'show', f'{commit}:{path}'],
        capture_output=True, text=True, cwd=cwd
    )
    if r.returncode != 0:
        print(f"  Warning: {path} not found in {commit}")
        return ''
    return r.stdout


def is_old(name):
    """Check if a name is an _old_ / _OLD tagged entry."""
    low = name.lower()
    return '_old_' in low or low.endswith('_old')


def _norm_source(src):
    """Normalize source: NASR/nasr -> 'nasr', XP12/xp12/INTL/CIFP -> 'intl'."""
    return 'intl' if src.strip().upper() in ('XP12', 'INTL', 'CIFP') else 'nasr'


def parse_coords(text):
    """Parse headerless CSV: name,lat,lon[,source] -> {(name,source): [(lat,lon),...]}, total_count."""
    groups = defaultdict(list)
    total = 0
    for row in csv.reader(io.StringIO(text)):
        if len(row) < 3:
            continue
        total += 1
        name = row[0].strip()
        if is_old(name):
            continue
        try:
            source = _norm_source(row[3].strip()) if len(row) > 3 and row[3].strip() else 'nasr'
            groups[(name, source)].append((float(row[1]), float(row[2])))
        except ValueError:
            continue
    return dict(groups), total


def parse_routes(text):
    """Parse headerless CSV: name,route[,source] -> {(name,source): [route,...]}, total_count."""
    groups = defaultdict(list)
    total = 0
    for line in text.strip().split('\n'):
        line = line.strip()
        if not line:
            continue
        idx = line.find(',')
        if idx < 0:
            continue
        total += 1
        name = line[:idx].strip()
        if is_old(name):
            continue
        rest = line[idx + 1:].strip()
        # Check for trailing source field
        source = 'nasr'
        last_comma = rest.rfind(',')
        if last_comma > 0:
            candidate = rest[last_comma + 1:].strip().upper()
            if candidate in ('NASR', 'XP12'):
                source = _norm_source(candidate)
                rest = rest[:last_comma].strip()
        groups[(name, source)].append(rest)
    return dict(groups), total


def parse_procs(text):
    """Parse DP/STAR CSV with header -> {body.transition: [rows]}, total_count."""
    groups = defaultdict(list)
    total = 0
    lines = text.strip().split('\n')
    if len(lines) < 2:
        return groups, 0
    for row in csv.reader(lines[1:]):
        if len(row) < 7:
            continue
        total += 1
        body = row[5].strip()
        trans = row[6].strip()
        key = f"{body}.{trans}" if trans else body
        if is_old(key) or is_old(body):
            continue
        groups[key].append(','.join(row))
    return groups, total


def _extract_airports(group_str):
    """Extract airport codes from ORIG_GROUP/DEST_GROUP column.

    Format: "KJFK/01L|13R KLGA/04|22 KEWR"  ->  {"KJFK", "KLGA", "KEWR"}
    """
    airports = set()
    for token in group_str.split():
        code = token.split('/')[0].strip()
        if code and len(code) >= 3:
            airports.add(code)
    return airports


def _versioned_name(comp_code, proc_name):
    """Extract versioned procedure name from computer code.

    DP format:   DEEZZ6.DEEZZ  -> DEEZZ6   (version before dot)
    STAR format: BAINY.BAINY3  -> BAINY3   (version after dot)
    Intl DP:     BPK5K.BPK     -> BPK5K    (ends with letter, not digit)
    Intl STAR:   BPK.BPK5K     -> BPK5K    (ends with letter, not digit)
    Heuristic: pick the part starting with proc_name that contains a digit.
    Falls back to proc_name.
    """
    if '.' not in comp_code:
        # No dot: return as-is if it contains a digit, else proc_name
        return comp_code if any(c.isdigit() for c in comp_code) else proc_name
    parts = comp_code.split('.', 1)
    pn = proc_name.upper()
    # Prefer part starting with proc_name + ending with digit (NASR standard)
    for p in parts:
        if p.upper().startswith(pn) and p[-1:].isdigit():
            return p
    # Intl pattern: part starting with proc_name + containing digit + longer (BPK5K, AGOP6A)
    for p in parts:
        if p.upper().startswith(pn) and any(c.isdigit() for c in p) and len(p) > len(pn):
            return p
    # Fallback: any part ending with a digit
    for p in parts:
        if p[-1:].isdigit():
            return p
    # Fallback: any part containing a digit and longer than proc_name
    for p in parts:
        if any(c.isdigit() for c in p) and len(p) > len(pn):
            return p
    return proc_name


def parse_procs_enriched(text):
    """Parse DP/STAR CSV grouped by (proc_name, artcc) with transition detail.

    Returns ({(proc_name, artcc): {...}}, total_count).
    """
    groups = {}
    total = 0
    lines = text.strip().split('\n')
    if len(lines) < 2:
        return groups, 0
    for row in csv.reader(lines[1:]):
        if len(row) < 8:
            continue
        total += 1
        comp_code = row[2].strip()
        if is_old(comp_code):
            continue
        proc_name = row[1].strip()
        artcc = row[3].strip()
        airport_group = row[4].strip()
        body_name = row[5].strip()
        trans_code = row[6].strip()
        trans_name = row[7].strip()
        route_points = row[8].strip() if len(row) > 8 else ''
        full_route = row[9].strip() if len(row) > 9 else ''

        key = (proc_name, artcc)
        if key not in groups:
            groups[key] = {
                'proc_name': proc_name,
                'artcc': artcc,
                'airports': set(),
                'computer_codes': set(),
                'versioned_name': proc_name,
                'transitions': {},
            }
        g = groups[key]
        g['airports'] |= _extract_airports(airport_group)

        # Extract versioned name and base computer code
        vname = _versioned_name(comp_code, proc_name)
        if vname != proc_name:
            g['versioned_name'] = vname
        base_code = comp_code.split('.')[0] if '.' in comp_code else comp_code
        g['computer_codes'].add(base_code)

        # Key transitions by (body_name, trans_name) for stable matching
        tkey = (body_name, trans_name)
        g['transitions'][tkey] = {
            'trans_code': trans_code,
            'trans_name': trans_name,
            'body_name': body_name,
            'route_points': route_points,
            'full_route': full_route,
        }

    return groups, total


def diff_procs_enriched(old, new, typ):
    """Diff enriched procedure groups, producing transition-level detail.

    Returns list of change entries with consolidated transitions.
    """
    changes = []
    old_keys = set(old.keys())
    new_keys = set(new.keys())

    # Changed procedures (exist in both)
    for key in sorted(old_keys & new_keys):
        old_g = old[key]
        new_g = new[key]
        old_trans = old_g['transitions']
        new_trans = new_g['transitions']

        if old_trans == new_trans:
            continue

        old_tkeys = set(old_trans.keys())
        new_tkeys = set(new_trans.keys())

        modified = []
        for tk in sorted(old_tkeys & new_tkeys):
            if old_trans[tk]['route_points'] != new_trans[tk]['route_points']:
                modified.append({
                    'name': new_trans[tk]['trans_name'] or new_trans[tk]['body_name'],
                    'code': new_trans[tk]['trans_code'],
                    'old_route': old_trans[tk]['route_points'],
                    'new_route': new_trans[tk]['route_points'],
                })

        added_t = []
        for tk in sorted(new_tkeys - old_tkeys):
            t = new_trans[tk]
            added_t.append({
                'name': t['trans_name'] or t['body_name'],
                'code': t['trans_code'],
                'route_points': t['route_points'],
            })

        removed_t = []
        for tk in sorted(old_tkeys - new_tkeys):
            t = old_trans[tk]
            removed_t.append({
                'name': t['trans_name'] or t['body_name'],
                'code': t['trans_code'],
                'route_points': t['route_points'],
            })

        if not modified and not added_t and not removed_t:
            continue

        # Compute fix-level diff summary across all modified transitions
        all_old_fixes = set()
        all_new_fixes = set()
        for m in modified:
            all_old_fixes |= set(m['old_route'].split())
            all_new_fixes |= set(m['new_route'].split())
        fixes_added = sorted(all_new_fixes - all_old_fixes)
        fixes_removed = sorted(all_old_fixes - all_new_fixes)

        parts = []
        if modified:
            parts.append(f"~{len(modified)} modified")
        if added_t:
            parts.append(f"+{len(added_t)} new")
        if removed_t:
            parts.append(f"-{len(removed_t)} removed")

        change = {
            'type': typ,
            'name': new_g['versioned_name'],
            'action': 'changed',
            'detail': ', '.join(parts),
            'source': 'intl' if len(new_g['artcc']) == 4 and ' ' not in new_g['artcc'] else 'nasr',
            'artccs': [new_g['artcc']],
            'airports': sorted(new_g['airports']),
            'computer_codes': sorted(new_g['computer_codes']),
            'transition_count': len(new_trans),
        }
        if fixes_added:
            change['fixes_added'] = fixes_added
        if fixes_removed:
            change['fixes_removed'] = fixes_removed
        if modified:
            change['modified_transitions'] = modified
        if added_t:
            change['added_transitions'] = added_t
        if removed_t:
            change['removed_transitions'] = removed_t
        changes.append(change)

    # Removed procedures
    for key in sorted(old_keys - new_keys):
        g = old[key]
        trans_list = []
        for tk in sorted(g['transitions'].keys()):
            t = g['transitions'][tk]
            trans_list.append({
                'name': t['trans_name'] or t['body_name'],
                'code': t['trans_code'],
                'route_points': t['route_points'],
            })
        changes.append({
            'type': typ,
            'name': g['versioned_name'],
            'action': 'removed',
            'detail': f"{len(g['transitions'])} transitions removed",
            'source': 'intl' if len(g['artcc']) == 4 and ' ' not in g['artcc'] else 'nasr',
            'artccs': [g['artcc']],
            'airports': sorted(g['airports']),
            'computer_codes': sorted(g['computer_codes']),
            'transition_count': len(g['transitions']),
            'removed_transitions': trans_list,
        })

    # Added procedures
    for key in sorted(new_keys - old_keys):
        g = new[key]
        trans_list = []
        for tk in sorted(g['transitions'].keys()):
            t = g['transitions'][tk]
            trans_list.append({
                'name': t['trans_name'] or t['body_name'],
                'code': t['trans_code'],
                'route_points': t['route_points'],
            })
        changes.append({
            'type': typ,
            'name': g['versioned_name'],
            'action': 'added',
            'detail': f"{len(g['transitions'])} transitions",
            'source': 'intl' if len(g['artcc']) == 4 and ' ' not in g['artcc'] else 'nasr',
            'artccs': [g['artcc']],
            'airports': sorted(g['airports']),
            'computer_codes': sorted(g['computer_codes']),
            'transition_count': len(g['transitions']),
            'added_transitions': trans_list,
        })

    return changes


def parse_playbook(text):
    """Parse playbook_routes.csv -> {play_name: {route: {artcc info}}}, total_count."""
    plays = defaultdict(dict)
    total = 0
    lines = text.strip().split('\n')
    if len(lines) < 2:
        return plays, 0
    for row in csv.reader(lines[1:]):
        if len(row) < 2:
            continue
        total += 1
        play = row[0].strip()
        route = row[1].strip()
        if is_old(play):
            continue
        plays[play][route] = {
            'origin_artccs': row[4].strip() if len(row) > 4 else '',
            'dest_artccs': row[7].strip() if len(row) > 7 else '',
        }
    return plays, total


def _get_artccs(play_routes):
    """Collect all unique ARTCCs from a play's routes."""
    artccs = set()
    for info in play_routes.values():
        for a in info.get('origin_artccs', '').split(','):
            a = a.strip()
            if a:
                artccs.add(a)
        for a in info.get('dest_artccs', '').split(','):
            a = a.strip()
            if a:
                artccs.add(a)
    return sorted(artccs)


def _route_key(route):
    """Extract (origin_artcc, destination) from a route string for matching."""
    parts = route.split()
    if len(parts) < 2:
        return (route, route)
    return (parts[0], parts[-1])


def _word_similarity(a, b):
    """Jaccard similarity of word sets between two route strings."""
    wa = set(a.split())
    wb = set(b.split())
    if not wa or not wb:
        return 0.0
    return len(wa & wb) / len(wa | wb)


def _match_routes(removed, added):
    """Match removed routes to added routes by origin+dest, then similarity.

    Returns (modified_pairs, unmatched_added, unmatched_removed) where
    modified_pairs is [(old_route, new_route), ...].
    """
    # Group by (origin_artcc, destination)
    rem_by_key = defaultdict(list)
    add_by_key = defaultdict(list)
    for r in removed:
        rem_by_key[_route_key(r)].append(r)
    for r in added:
        add_by_key[_route_key(r)].append(r)

    modified = []
    used_added = set()
    used_removed = set()

    for key in rem_by_key:
        if key not in add_by_key:
            continue
        rems = rem_by_key[key]
        adds = [a for a in add_by_key[key] if a not in used_added]
        for old_r in rems:
            if not adds:
                break
            # Find best match by word similarity
            best_sim = 0.0
            best_idx = -1
            for i, new_r in enumerate(adds):
                sim = _word_similarity(old_r, new_r)
                if sim > best_sim:
                    best_sim = sim
                    best_idx = i
            if best_sim >= 0.3 and best_idx >= 0:
                modified.append((old_r, adds[best_idx]))
                used_removed.add(old_r)
                used_added.add(adds[best_idx])
                adds.pop(best_idx)

    unmatched_added = sorted(r for r in added if r not in used_added)
    unmatched_removed = sorted(r for r in removed if r not in used_removed)
    return modified, unmatched_added, unmatched_removed


def diff_playbook(old, new):
    """Diff playbook plays with route-level detail and pair matching."""
    changes = []

    for play in sorted(set(old) & set(new)):
        old_routes = set(old[play].keys())
        new_routes = set(new[play].keys())
        if old_routes == new_routes:
            continue
        added_r = sorted(new_routes - old_routes)
        removed_r = sorted(old_routes - new_routes)

        # Match removed/added routes by origin+dest to find modifications
        modified, unmatched_added, unmatched_removed = _match_routes(
            removed_r, added_r
        )

        parts = []
        if modified:
            parts.append(f"~{len(modified)} modified")
        if unmatched_added:
            parts.append(f"+{len(unmatched_added)} new")
        if unmatched_removed:
            parts.append(f"-{len(unmatched_removed)} removed")

        change = {
            'type': 'playbook', 'name': play, 'action': 'changed',
            'detail': ', '.join(parts),
            'old_name': play,
            'old_value': f"{len(old_routes)} routes",
            'new_value': f"{len(new_routes)} routes",
            'artccs': _get_artccs(new[play]),
            'route_count': len(new_routes),
        }
        if modified:
            change['modified_routes'] = [
                {'old': o, 'new': n} for o, n in
                sorted(modified, key=lambda x: x[1])
            ]
        if unmatched_added:
            change['added_routes'] = unmatched_added
        if unmatched_removed:
            change['removed_routes'] = unmatched_removed
        changes.append(change)

    for play in sorted(set(old) - set(new)):
        changes.append({
            'type': 'playbook', 'name': play, 'action': 'removed',
            'detail': f'{len(old[play])} routes removed',
            'old_name': play,
            'old_value': f"{len(old[play])} routes",
            'artccs': _get_artccs(old[play]),
            'route_count': len(old[play]),
            'removed_routes': sorted(old[play].keys()),
        })

    for play in sorted(set(new) - set(old)):
        changes.append({
            'type': 'playbook', 'name': play, 'action': 'added',
            'detail': f'{len(new[play])} routes',
            'new_value': f"{len(new[play])} routes",
            'artccs': _get_artccs(new[play]),
            'route_count': len(new[play]),
            'added_routes': sorted(new[play].keys()),
        })

    return changes


def haversine_nm(lat1, lon1, lat2, lon2):
    """Haversine distance in nautical miles."""
    R_NM = 3440.065
    d_lat = math.radians(lat2 - lat1)
    d_lon = math.radians(lon2 - lon1)
    a = (math.sin(d_lat / 2) ** 2 +
         math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) *
         math.sin(d_lon / 2) ** 2)
    return round(R_NM * 2 * math.asin(math.sqrt(a)), 1)


def diff_coords(old, new, typ):
    """Diff coordinate-based data (points/navaids).

    Keys are (name, source) tuples; values are lists of (lat, lon).
    Uses greedy nearest-match within each key group.
    """
    changes = []
    all_keys = sorted(set(old) | set(new))

    for key in all_keys:
        name, source = key
        old_pts = old.get(key, [])
        new_pts = new.get(key, [])

        # Match old↔new by nearest distance (greedy)
        matched_old = set()
        matched_new = set()
        for i, (olat, olon) in enumerate(old_pts):
            best_j, best_dist = -1, float('inf')
            for j, (nlat, nlon) in enumerate(new_pts):
                if j in matched_new:
                    continue
                d = haversine_nm(olat, olon, nlat, nlon)
                if d < best_dist:
                    best_dist, best_j = d, j
            if best_j >= 0 and best_dist < 500:
                matched_old.add(i)
                matched_new.add(best_j)
                nlat, nlon = new_pts[best_j]
                if abs(olat - nlat) > 0.0001 or abs(olon - nlon) > 0.0001:
                    changes.append({
                        'type': typ, 'name': name, 'action': 'moved',
                        'source': source,
                        'detail': f"({olat:.6f},{olon:.6f}) -> ({nlat:.6f},{nlon:.6f})",
                        'old_name': name,
                        'lat': nlat, 'lon': nlon,
                        'old_lat': olat, 'old_lon': olon,
                        'delta_nm': haversine_nm(olat, olon, nlat, nlon)
                    })

        # Unmatched old → removed
        for i, (olat, olon) in enumerate(old_pts):
            if i not in matched_old:
                changes.append({
                    'type': typ, 'name': name, 'action': 'removed',
                    'source': source,
                    'detail': 'No longer in source',
                    'old_name': name, 'old_lat': olat, 'old_lon': olon
                })

        # Unmatched new → added
        for j, (nlat, nlon) in enumerate(new_pts):
            if j not in matched_new:
                changes.append({
                    'type': typ, 'name': name, 'action': 'added',
                    'source': source,
                    'detail': f"({nlat:.6f},{nlon:.6f})",
                    'lat': nlat, 'lon': nlon
                })

    return changes


def diff_routes(old, new, typ):
    """Diff route-based data (airways/cdrs).

    Keys are (name, source) tuples; values are lists of route strings.
    Uses greedy word-similarity matching within each key group.
    """
    changes = []
    all_keys = sorted(set(old) | set(new))

    for key in all_keys:
        name, source = key
        old_routes = old.get(key, [])
        new_routes = new.get(key, [])

        # Match old↔new by word similarity (greedy)
        matched_old = set()
        matched_new = set()
        for i, old_r in enumerate(old_routes):
            best_j, best_sim = -1, 0.0
            for j, new_r in enumerate(new_routes):
                if j in matched_new:
                    continue
                sim = _word_similarity(old_r, new_r)
                if sim > best_sim:
                    best_sim, best_j = sim, j
            if best_j >= 0 and best_sim >= 0.3:
                matched_old.add(i)
                matched_new.add(best_j)
                if old_r != new_routes[best_j]:
                    changes.append({
                        'type': typ, 'name': name, 'action': 'changed',
                        'source': source,
                        'detail': 'Content modified', 'old_name': name,
                        'new_value': new_routes[best_j], 'old_value': old_r
                    })

        # Unmatched old → removed
        for i, old_r in enumerate(old_routes):
            if i not in matched_old:
                changes.append({
                    'type': typ, 'name': name, 'action': 'removed',
                    'source': source,
                    'detail': 'No longer in source', 'old_name': name,
                    'old_value': old_r
                })

        # Unmatched new → added
        for j, new_r in enumerate(new_routes):
            if j not in matched_new:
                changes.append({
                    'type': typ, 'name': name, 'action': 'added',
                    'source': source,
                    'detail': 'New entry', 'new_value': new_r
                })

    return changes


def diff_procs(old, new, typ):
    """Diff procedure data (dps/stars)."""
    changes = []

    for k in sorted(set(old) & set(new)):
        if sorted(old[k]) != sorted(new[k]):
            changes.append({
                'type': typ, 'name': k, 'action': 'changed',
                'detail': 'Content modified', 'old_name': k,
                'new_value': k
            })

    for k in sorted(set(old) - set(new)):
        changes.append({
            'type': typ, 'name': k, 'action': 'removed',
            'detail': 'No longer in NASR source', 'old_name': k
        })

    for k in sorted(set(new) - set(old)):
        changes.append({
            'type': typ, 'name': k, 'action': 'added',
            'detail': 'New procedure'
        })

    return changes


def summarize(changes):
    """Build summary dicts (overall, nasr, intl) from changes list."""
    s = defaultdict(lambda: defaultdict(int))
    nasr_s = defaultdict(lambda: defaultdict(int))
    intl_s = defaultdict(lambda: defaultdict(int))
    type_map = {
        'fix': 'fixes', 'navaid': 'navaids', 'airway': 'airways',
        'cdr': 'cdrs', 'dp': 'dps', 'star': 'stars',
        'playbook': 'playbook'
    }
    act_map = {
        'added': 'added', 'removed': 'removed',
        'moved': 'modified', 'changed': 'modified'
    }
    for c in changes:
        cat = type_map.get(c['type'], c['type'])
        act = act_map.get(c['action'], c['action'])
        s[cat][act] += 1
        src = c.get('source')
        if src == 'nasr':
            nasr_s[cat][act] += 1
        elif src == 'intl':
            intl_s[cat][act] += 1
    return (
        {k: dict(v) for k, v in s.items() if v},
        {k: dict(v) for k, v in nasr_s.items() if v},
        {k: dict(v) for k, v in intl_s.items() if v},
    )


def write_json(changes, summary, nasr_summary, intl_summary,
               totals, from_c, to_c, path):
    """Write changelog JSON matching existing format."""
    obj = {
        'meta': {
            'from_cycle': from_c,
            'to_cycle': to_c,
            'generated_utc': datetime.now(timezone.utc).isoformat() + 'Z',
            'totals': totals
        },
        'summary': summary,
        'nasr_summary': nasr_summary,
        'intl_summary': intl_summary,
        'changes': changes
    }
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(obj, f, indent=2, ensure_ascii=False)


def write_md(changes, summary, totals, active_counts, from_c, to_c, path):
    """Write changelog markdown matching existing format."""
    MAX = 50
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%SZ')
    lines = [
        f'# AIRAC Cycle Update: {from_c} -> {to_c}', '',
        f'**Generated**: {now}', '',
        '## Summary', '',
        '| Data Type | Added | Modified | Removed | Preserved | Total |',
        '|-----------|-------|----------|---------|-----------|-------|',
    ]

    row_defs = [
        ('Points', 'fixes', 'points'), ('Navaids', 'navaids', 'navaids'),
        ('Airways', 'airways', 'airways'), ('Cdrs', 'cdrs', 'cdrs'),
        ('Dps', 'dps', 'dps'), ('Stars', 'stars', 'stars'),
        ('Playbook', 'playbook', 'playbook'),
    ]
    for label, skey, tkey in row_defs:
        s = summary.get(skey, {})
        added = s.get('added', 0)
        modified = s.get('modified', 0)
        removed = s.get('removed', 0)
        tot = totals.get(tkey, 0)
        active = active_counts.get(tkey, 0)
        preserved = active - added - modified
        lines.append(f'| {label} | {added} | {modified} | {removed} | {preserved} | {tot} |')

    lines.append('')

    sections = [
        ('Points', 'fix'), ('Navaids', 'navaid'), ('Airways', 'airway'),
        ('Cdrs', 'cdr'), ('Dps', 'dp'), ('Stars', 'star'),
        ('Playbook', 'playbook')
    ]
    action_order = [
        ('added', 'Added'), ('moved', 'Moved'),
        ('changed', 'Changed'), ('removed', 'Removed')
    ]

    for sec_label, typ in sections:
        typed = [c for c in changes if c['type'] == typ]
        if not typed:
            continue
        lines += [f'## {sec_label}', '']
        by_action = defaultdict(list)
        for c in typed:
            by_action[c['action']].append(c)
        for act, alabel in action_order:
            if act not in by_action:
                continue
            items = by_action[act]
            lines.append(f'### {alabel} ({len(items)})')
            lines.append('')
            for i, c in enumerate(items):
                if i >= MAX:
                    lines += ['', f'... and {len(items) - MAX} more']
                    break
                lines.append(f'- **{c["name"]}**: {c.get("detail", "Content modified")}')
            lines.append('')
        lines.append('')

    with open(path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))


def db_insert(changes, to_cycle):
    """Insert changelog entries into VATSIM_REF.dbo.navdata_changelogs."""
    try:
        import pyodbc
    except ImportError:
        print("  pyodbc not available, skipping DB insert")
        return 0

    conn = pyodbc.connect(
        'DRIVER={ODBC Driver 18 for SQL Server};'
        'SERVER=tcp:vatsim.database.windows.net,1433;'
        'DATABASE=VATSIM_REF;UID=jpeterson;PWD=Jhp21012;'
        'Encrypt=yes;TrustServerCertificate=no;'
    )
    cur = conn.cursor()

    cur.execute(
        "SELECT COUNT(*) FROM dbo.navdata_changelogs WHERE airac_cycle=?",
        to_cycle
    )
    existing = cur.fetchone()[0]
    if existing > 0:
        print(f"  {existing} entries already exist for cycle {to_cycle}, skipping")
        conn.close()
        return 0

    n = 0
    for c in changes:
        tbl = TYPE_TO_TABLE.get(c['type'], c['type'])
        old_v = new_v = delta = None

        if c['type'] in ('fix', 'navaid'):
            if 'old_lat' in c:
                old_v = json.dumps({'lat': c['old_lat'], 'lon': c['old_lon']})
            if 'lat' in c:
                new_v = json.dumps({'lat': c['lat'], 'lon': c['lon']})
            if 'delta_nm' in c:
                delta = f"moved {c['delta_nm']}nm"
        elif c['type'] in ('airway', 'cdr'):
            old_v = c.get('old_value')
            new_v = c.get('new_value')
            if c['action'] == 'changed':
                delta = 'route modified'
        elif c['type'] in ('dp', 'star'):
            # Enriched format: store transition summary
            parts = []
            if c.get('airports'):
                parts.append('Airports: ' + ', '.join(c['airports']))
            if c.get('artccs'):
                parts.append('ARTCCs: ' + ', '.join(c['artccs']))
            old_v = '; '.join(parts) if parts else c.get('old_name')
            new_v = c.get('detail')
            if c['action'] == 'changed':
                delta = c.get('detail')
        elif c['type'] == 'playbook':
            old_v = c.get('old_value')
            new_v = c.get('new_value')
            if c['action'] == 'changed':
                delta = c.get('detail')

        cur.execute(
            "INSERT INTO dbo.navdata_changelogs"
            "(airac_cycle,table_name,entry_name,change_type,"
            "old_value,new_value,delta_detail) "
            "VALUES(?,?,?,?,?,?,?)",
            to_cycle, tbl, c['name'], c['action'],
            old_v, new_v, delta
        )
        n += 1

    conn.commit()
    conn.close()
    return n


def main():
    parser = argparse.ArgumentParser(
        description='Generate historical AIRAC changelog'
    )
    parser.add_argument('--from-commit', required=True,
                        help='Git commit hash for the FROM cycle')
    parser.add_argument('--to-commit', required=True,
                        help='Git commit hash for the TO cycle')
    parser.add_argument('--from-cycle', required=True,
                        help='AIRAC cycle number (e.g., 2601)')
    parser.add_argument('--to-cycle', required=True,
                        help='AIRAC cycle number (e.g., 2602)')
    parser.add_argument('--no-db', action='store_true',
                        help='Skip database insert')
    parser.add_argument('--playbook-from-commit',
                        help='Override FROM commit for playbook CSV only')
    parser.add_argument('--playbook-to-commit',
                        help='Override TO commit for playbook CSV only')
    args = parser.parse_args()

    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    logs = os.path.join(root, 'assets', 'data', 'logs')
    os.makedirs(logs, exist_ok=True)

    print(f"AIRAC Changelog: {args.from_cycle} -> {args.to_cycle}")
    print(f"  Commits: {args.from_commit} -> {args.to_commit}\n")

    # Extract CSVs from git
    print("Extracting CSVs from git...")
    raw = {}
    for k, p in CSV_PATHS.items():
        from_c = args.from_commit
        to_c = args.to_commit
        if k == 'playbook':
            if args.playbook_from_commit:
                from_c = args.playbook_from_commit
            if args.playbook_to_commit:
                to_c = args.playbook_to_commit
        old_text = git_show(from_c, p, root)
        new_text = git_show(to_c, p, root)
        raw[k] = (old_text, new_text)
        print(f"  {k}: {len(old_text):,} / {len(new_text):,} bytes")

    # Parse
    print("\nParsing...")
    old_pts, old_pts_t = parse_coords(raw['points'][0])
    new_pts, new_pts_t = parse_coords(raw['points'][1])
    old_nav, old_nav_t = parse_coords(raw['navaids'][0])
    new_nav, new_nav_t = parse_coords(raw['navaids'][1])
    old_awy, old_awy_t = parse_routes(raw['airways'][0])
    new_awy, new_awy_t = parse_routes(raw['airways'][1])
    old_cdr, old_cdr_t = parse_routes(raw['cdrs'][0])
    new_cdr, new_cdr_t = parse_routes(raw['cdrs'][1])
    old_dp, old_dp_t = parse_procs_enriched(raw['dps'][0])
    new_dp, new_dp_t = parse_procs_enriched(raw['dps'][1])
    old_st, old_st_t = parse_procs_enriched(raw['stars'][0])
    new_st, new_st_t = parse_procs_enriched(raw['stars'][1])
    old_pb, old_pb_t = parse_playbook(raw['playbook'][0])
    new_pb, new_pb_t = parse_playbook(raw['playbook'][1])

    # Count active entries (groups may have multiple entries per key)
    def _count_entries(groups):
        return sum(len(v) for v in groups.values())

    print(f"  Points:   {_count_entries(old_pts):,} -> {_count_entries(new_pts):,} active"
          f"  ({old_pts_t:,} / {new_pts_t:,} total)")
    print(f"  Navaids:  {_count_entries(old_nav):,} -> {_count_entries(new_nav):,} active"
          f"  ({old_nav_t:,} / {new_nav_t:,} total)")
    print(f"  Airways:  {_count_entries(old_awy):,} -> {_count_entries(new_awy):,} active"
          f"  ({old_awy_t:,} / {new_awy_t:,} total)")
    print(f"  CDRs:     {_count_entries(old_cdr):,} -> {_count_entries(new_cdr):,} active"
          f"  ({old_cdr_t:,} / {new_cdr_t:,} total)")
    print(f"  DPs:      {len(old_dp):,} -> {len(new_dp):,} active"
          f"  ({old_dp_t:,} / {new_dp_t:,} total)")
    print(f"  STARs:    {len(old_st):,} -> {len(new_st):,} active"
          f"  ({old_st_t:,} / {new_st_t:,} total)")
    print(f"  Playbook: {len(old_pb):,} -> {len(new_pb):,} active"
          f"  ({old_pb_t:,} / {new_pb_t:,} total)")

    # Diff
    print("\nComputing diffs...")
    all_changes = []
    all_changes += diff_coords(old_pts, new_pts, 'fix')
    all_changes += diff_coords(old_nav, new_nav, 'navaid')
    all_changes += diff_routes(old_awy, new_awy, 'airway')
    all_changes += diff_routes(old_cdr, new_cdr, 'cdr')
    all_changes += diff_procs_enriched(old_dp, new_dp, 'dp')
    all_changes += diff_procs_enriched(old_st, new_st, 'star')
    all_changes += diff_playbook(old_pb, new_pb)

    summary, nasr_summary, intl_summary = summarize(all_changes)
    totals = {
        'points': new_pts_t, 'navaids': new_nav_t, 'airways': new_awy_t,
        'cdrs': new_cdr_t, 'dps': new_dp_t, 'stars': new_st_t,
        'playbook': new_pb_t
    }
    active_counts = {
        'points': _count_entries(new_pts), 'navaids': _count_entries(new_nav),
        'airways': _count_entries(new_awy), 'cdrs': _count_entries(new_cdr),
        'dps': len(new_dp), 'stars': len(new_st),
        'playbook': len(new_pb),
    }

    print(f"\nTotal changes: {len(all_changes)}")
    for k, v in sorted(summary.items()):
        print(f"  {k}: {v}")

    # Write JSON
    jp = os.path.join(
        logs, f'AIRAC_CHANGELOG_{args.from_cycle}_{args.to_cycle}.json'
    )
    write_json(all_changes, summary, nasr_summary, intl_summary,
               totals, args.from_cycle, args.to_cycle, jp)
    print(f"\nJSON: {jp}")

    # Write Markdown
    mp = os.path.join(
        logs, f'AIRAC_CHANGELOG_{args.from_cycle}_{args.to_cycle}.md'
    )
    write_md(
        all_changes, summary, totals, active_counts,
        args.from_cycle, args.to_cycle, mp
    )
    print(f"MD:   {mp}")

    # Update index
    ix = os.path.join(logs, 'changelog_index.json')
    if os.path.exists(ix):
        with open(ix) as f:
            idx = json.load(f)
    else:
        idx = {'cycles': []}
    entry = {'from': args.from_cycle, 'to': args.to_cycle}
    if entry not in idx['cycles']:
        idx['cycles'].append(entry)
        with open(ix, 'w', encoding='utf-8') as f:
            json.dump(idx, f, indent=2)
            f.write('\n')
        print(f"Index updated: {ix}")

    # Database insert
    if not args.no_db:
        print("\nInserting into VATSIM_REF.dbo.navdata_changelogs...")
        try:
            n = db_insert(all_changes, args.to_cycle)
            print(f"  {n} entries inserted")
        except Exception as e:
            print(f"  DB error: {e}")
            print("  Use --no-db to skip database insert")
    else:
        print("\nDB insert skipped (--no-db)")

    print("\nDone!")


if __name__ == '__main__':
    main()
