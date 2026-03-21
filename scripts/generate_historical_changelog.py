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


def parse_coords(text):
    """Parse headerless CSV: name,lat,lon -> {name: (lat,lon)}, total_count."""
    active = {}
    total = 0
    for row in csv.reader(io.StringIO(text)):
        if len(row) < 3:
            continue
        total += 1
        name = row[0].strip()
        if is_old(name):
            continue
        try:
            active[name] = (float(row[1]), float(row[2]))
        except ValueError:
            continue
    return active, total


def parse_routes(text):
    """Parse headerless CSV: name,route -> {name: route}, total_count."""
    active = {}
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
        active[name] = line[idx + 1:].strip()
    return active, total


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


def diff_playbook(old, new):
    """Diff playbook plays with route-level detail."""
    changes = []
    MAX_ROUTES = 10

    for play in sorted(set(old) & set(new)):
        old_routes = set(old[play].keys())
        new_routes = set(new[play].keys())
        if old_routes == new_routes:
            continue
        added_r = sorted(new_routes - old_routes)
        removed_r = sorted(old_routes - new_routes)
        parts = []
        if added_r:
            parts.append(f"+{len(added_r)} routes")
        if removed_r:
            parts.append(f"-{len(removed_r)} routes")
        change = {
            'type': 'playbook', 'name': play, 'action': 'changed',
            'detail': ', '.join(parts),
            'old_name': play,
            'old_value': f"{len(old_routes)} routes",
            'new_value': f"{len(new_routes)} routes",
            'artccs': _get_artccs(new[play]),
            'route_count': len(new_routes),
        }
        if added_r:
            change['added_routes'] = added_r[:MAX_ROUTES]
            if len(added_r) > MAX_ROUTES:
                change['added_routes_total'] = len(added_r)
        if removed_r:
            change['removed_routes'] = removed_r[:MAX_ROUTES]
            if len(removed_r) > MAX_ROUTES:
                change['removed_routes_total'] = len(removed_r)
        changes.append(change)

    for play in sorted(set(old) - set(new)):
        changes.append({
            'type': 'playbook', 'name': play, 'action': 'removed',
            'detail': f'{len(old[play])} routes removed',
            'old_name': play,
            'old_value': f"{len(old[play])} routes",
            'artccs': _get_artccs(old[play]),
            'route_count': len(old[play]),
        })

    for play in sorted(set(new) - set(old)):
        sample = sorted(new[play].keys())[:5]
        changes.append({
            'type': 'playbook', 'name': play, 'action': 'added',
            'detail': f'{len(new[play])} routes',
            'new_value': f"{len(new[play])} routes",
            'artccs': _get_artccs(new[play]),
            'route_count': len(new[play]),
            'sample_routes': sample,
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
    """Diff coordinate-based data (points/navaids)."""
    changes = []
    common = sorted(set(old) & set(new))
    removed_names = sorted(set(old) - set(new))
    added_names = sorted(set(new) - set(old))

    for n in common:
        olat, olon = old[n]
        nlat, nlon = new[n]
        if abs(olat - nlat) > 0.0001 or abs(olon - nlon) > 0.0001:
            changes.append({
                'type': typ, 'name': n, 'action': 'moved',
                'detail': f"({olat:.6f},{olon:.6f}) -> ({nlat:.6f},{nlon:.6f})",
                'old_name': n,
                'lat': nlat, 'lon': nlon,
                'old_lat': olat, 'old_lon': olon,
                'delta_nm': haversine_nm(olat, olon, nlat, nlon)
            })

    for n in removed_names:
        olat, olon = old[n]
        changes.append({
            'type': typ, 'name': n, 'action': 'removed',
            'detail': 'No longer in NASR source',
            'old_name': n, 'old_lat': olat, 'old_lon': olon
        })

    for n in added_names:
        nlat, nlon = new[n]
        changes.append({
            'type': typ, 'name': n, 'action': 'added',
            'detail': f"({nlat:.6f},{nlon:.6f})",
            'lat': nlat, 'lon': nlon
        })

    return changes


def diff_routes(old, new, typ):
    """Diff route-based data (airways/cdrs)."""
    changes = []

    for n in sorted(set(old) & set(new)):
        if old[n] != new[n]:
            changes.append({
                'type': typ, 'name': n, 'action': 'changed',
                'detail': 'Content modified', 'old_name': n,
                'new_value': new[n], 'old_value': old[n]
            })

    for n in sorted(set(old) - set(new)):
        changes.append({
            'type': typ, 'name': n, 'action': 'removed',
            'detail': 'No longer in source', 'old_name': n,
            'old_value': old[n]
        })

    for n in sorted(set(new) - set(old)):
        changes.append({
            'type': typ, 'name': n, 'action': 'added',
            'detail': 'New entry', 'new_value': new[n]
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
    """Build summary dict from changes list."""
    s = defaultdict(lambda: defaultdict(int))
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
    return {k: dict(v) for k, v in s.items() if v}


def write_json(changes, summary, totals, from_c, to_c, path):
    """Write changelog JSON matching existing format."""
    obj = {
        'meta': {
            'from_cycle': from_c,
            'to_cycle': to_c,
            'generated_utc': datetime.now(timezone.utc).isoformat() + 'Z',
            'totals': totals
        },
        'summary': summary,
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
            old_v = c.get('old_name')
            new_v = c.get('new_value')
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
        old_text = git_show(args.from_commit, p, root)
        new_text = git_show(args.to_commit, p, root)
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
    old_dp, old_dp_t = parse_procs(raw['dps'][0])
    new_dp, new_dp_t = parse_procs(raw['dps'][1])
    old_st, old_st_t = parse_procs(raw['stars'][0])
    new_st, new_st_t = parse_procs(raw['stars'][1])
    old_pb, old_pb_t = parse_playbook(raw['playbook'][0])
    new_pb, new_pb_t = parse_playbook(raw['playbook'][1])

    print(f"  Points:   {len(old_pts):,} -> {len(new_pts):,} active"
          f"  ({old_pts_t:,} / {new_pts_t:,} total)")
    print(f"  Navaids:  {len(old_nav):,} -> {len(new_nav):,} active"
          f"  ({old_nav_t:,} / {new_nav_t:,} total)")
    print(f"  Airways:  {len(old_awy):,} -> {len(new_awy):,} active"
          f"  ({old_awy_t:,} / {new_awy_t:,} total)")
    print(f"  CDRs:     {len(old_cdr):,} -> {len(new_cdr):,} active"
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
    all_changes += diff_procs(old_dp, new_dp, 'dp')
    all_changes += diff_procs(old_st, new_st, 'star')
    all_changes += diff_playbook(old_pb, new_pb)

    summary = summarize(all_changes)
    totals = {
        'points': new_pts_t, 'navaids': new_nav_t, 'airways': new_awy_t,
        'cdrs': new_cdr_t, 'dps': new_dp_t, 'stars': new_st_t,
        'playbook': new_pb_t
    }
    active_counts = {
        'points': len(new_pts), 'navaids': len(new_nav),
        'airways': len(new_awy), 'cdrs': len(new_cdr),
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
    write_json(all_changes, summary, totals, args.from_cycle, args.to_cycle, jp)
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
