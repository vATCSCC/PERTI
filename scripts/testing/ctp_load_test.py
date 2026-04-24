#!/usr/bin/env python3
"""
CTP SWIM Endpoint Load Test
============================

Validates CTP Slot Engine SWIM API under realistic traffic patterns for CTP E26.
Tests request-slot, confirm-slot, release-slot lifecycle with realistic callsigns
and varying load levels.

Usage:
    python scripts/testing/ctp_load_test.py [--dry-run] [--duration SHORT|FULL]

Requires: pip install requests
"""

import argparse
import json
import os
import random
import string
import sys
import time
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

try:
    import requests
except ImportError:
    print("ERROR: 'requests' library required. Install with: pip install requests")
    sys.exit(1)

# ── Configuration ──────────────────────────────────────────────────────────

API_BASE = "https://perti.vatcscc.org/api/swim/v1/ctp/"
API_KEY = "swim_sys_ctp_engine_001"
SESSION_NAME = "CTPE26_TEST"
TRACKS = ["NAT-A", "NAT-B", "NAT-C"]

# Realistic flight data from 30-day ADL analysis
ORIGINS = [
    "KJFK", "KLAX", "KBOS", "KSFO", "KMIA", "KDFW", "KORD", "KIAD",
    "KATL", "KEWR", "KDEN", "KPHL", "KDCA", "KCLT", "KMSP"
]
DESTINATIONS = [
    "EGLL", "EDDF", "EHAM", "LFPG", "EDDM", "EGPH", "LEMD", "LIMC",
    "OERK", "GMTT", "LIRF", "EIDW", "EBBR", "LPPT", "LSZH"
]
AIRCRAFT_TYPES = [
    "B77W", "A359", "B772", "A35K", "A346", "A388", "A343", "B77L",
    "A339", "B789", "B763", "B764", "A332", "B744", "B788"
]
CALLSIGN_PREFIXES = [
    "BAW", "UAL", "DAL", "DLH", "AFR", "VIR", "JBU", "KLM",
    "IBE", "QTR", "AAL", "FDX", "SWA", "ACA", "EIN"
]

# ── Rate Phases ─────────────────────────────────────────────────────────

PHASES_FULL = [
    {"name": "warm-up",   "duration_sec": 300,  "rate_min": 2,  "rate_max": 3},
    {"name": "peak-1",    "duration_sec": 420,  "rate_min": 10, "rate_max": 15},
    {"name": "off-peak",  "duration_sec": 600,  "rate_min": 1,  "rate_max": 3},
    {"name": "peak-2",    "duration_sec": 420,  "rate_min": 10, "rate_max": 15},
    {"name": "cool-down", "duration_sec": 180,  "rate_min": 1,  "rate_max": 1},
]

PHASES_SHORT = [
    {"name": "warm-up",   "duration_sec": 60,   "rate_min": 2,  "rate_max": 3},
    {"name": "peak-1",    "duration_sec": 120,  "rate_min": 10, "rate_max": 15},
    {"name": "off-peak",  "duration_sec": 120,  "rate_min": 1,  "rate_max": 3},
    {"name": "peak-2",    "duration_sec": 120,  "rate_min": 10, "rate_max": 15},
    {"name": "cool-down", "duration_sec": 60,   "rate_min": 1,  "rate_max": 1},
]

# ── Scenario Weights ─────────────────────────────────────────────────────

SCENARIOS = [
    ("full_lifecycle",       30),  # request → confirm recommended
    ("alt_track",            10),  # request → confirm alternative
    ("release_cycle",        10),  # request → confirm → release → re-request
    ("flight_not_found",     10),  # request with fake callsign
    ("slot_race",             8),  # confirm already-taken slot
    ("session_status",       15),  # GET session-status
    ("sessions_list",         5),  # GET sessions
    ("release_nonexistent",   5),  # release with no slot
    ("release_invalid",       2),  # release with invalid reason
    ("all_consumed",          5),  # request after slots exhausted
]


class LoadTestMetrics:
    """Collects and computes test metrics."""

    def __init__(self):
        self.requests = []
        self.phase_start_times = {}
        self.slot_snapshots = []
        self.errors_by_code = defaultdict(int)
        self.responses_by_status = defaultdict(int)

    def record(self, endpoint, method, status_code, latency_ms, response_body,
               scenario, phase, error_code=None):
        entry = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "endpoint": endpoint,
            "method": method,
            "status_code": status_code,
            "latency_ms": round(latency_ms, 1),
            "scenario": scenario,
            "phase": phase,
            "error_code": error_code,
            "response_size": len(json.dumps(response_body)) if response_body else 0,
        }
        self.requests.append(entry)
        self.responses_by_status[status_code] += 1
        if error_code:
            self.errors_by_code[error_code] += 1

    def record_snapshot(self, snapshot):
        self.slot_snapshots.append({
            "timestamp": datetime.now(timezone.utc).isoformat(),
            **snapshot,
        })

    def compute_summary(self):
        if not self.requests:
            return {"error": "no requests recorded"}

        latencies = [r["latency_ms"] for r in self.requests]
        latencies.sort()
        n = len(latencies)

        # Per-endpoint breakdown
        by_endpoint = defaultdict(list)
        for r in self.requests:
            by_endpoint[r["endpoint"]].append(r["latency_ms"])

        endpoint_stats = {}
        for ep, lats in by_endpoint.items():
            lats.sort()
            m = len(lats)
            endpoint_stats[ep] = {
                "count": m,
                "p50": lats[m // 2],
                "p95": lats[int(m * 0.95)] if m >= 20 else lats[-1],
                "p99": lats[int(m * 0.99)] if m >= 100 else lats[-1],
                "max": lats[-1],
                "avg": round(sum(lats) / m, 1),
            }

        # Per-phase breakdown
        by_phase = defaultdict(list)
        for r in self.requests:
            by_phase[r["phase"]].append(r)

        phase_stats = {}
        for phase, reqs in by_phase.items():
            lats = sorted([r["latency_ms"] for r in reqs])
            m = len(lats)
            duration_sec = max(1, (
                datetime.fromisoformat(reqs[-1]["timestamp"]) -
                datetime.fromisoformat(reqs[0]["timestamp"])
            ).total_seconds()) if m > 1 else 1
            phase_stats[phase] = {
                "request_count": m,
                "throughput_rpm": round(m / duration_sec * 60, 1),
                "p50": lats[m // 2],
                "p95": lats[int(m * 0.95)] if m >= 20 else lats[-1],
                "max": lats[-1],
                "success_count": sum(1 for r in reqs if r["status_code"] < 500),
                "error_5xx": sum(1 for r in reqs if r["status_code"] >= 500),
            }

        # Per-scenario breakdown
        by_scenario = defaultdict(lambda: {"count": 0, "success": 0, "latencies": []})
        for r in self.requests:
            s = by_scenario[r["scenario"]]
            s["count"] += 1
            s["latencies"].append(r["latency_ms"])
            if r["status_code"] < 500:
                s["success"] += 1

        scenario_stats = {}
        for sc, data in by_scenario.items():
            lats = sorted(data["latencies"])
            m = len(lats)
            scenario_stats[sc] = {
                "count": data["count"],
                "success_rate": round(data["success"] / data["count"] * 100, 1),
                "p50": lats[m // 2],
                "max": lats[-1],
            }

        return {
            "total_requests": n,
            "duration_sec": round((
                datetime.fromisoformat(self.requests[-1]["timestamp"]) -
                datetime.fromisoformat(self.requests[0]["timestamp"])
            ).total_seconds(), 1),
            "overall_latency": {
                "p50": latencies[n // 2],
                "p95": latencies[int(n * 0.95)] if n >= 20 else latencies[-1],
                "p99": latencies[int(n * 0.99)] if n >= 100 else latencies[-1],
                "max": latencies[-1],
                "avg": round(sum(latencies) / n, 1),
            },
            "status_distribution": dict(self.responses_by_status),
            "error_codes": dict(self.errors_by_code),
            "endpoints": endpoint_stats,
            "phases": phase_stats,
            "scenarios": scenario_stats,
            "slot_utilization_timeline": self.slot_snapshots,
        }


class CTPLoadTest:
    """Orchestrates the CTP slot engine load test."""

    def __init__(self, dry_run=False, phases=None):
        self.session = requests.Session()
        self.session.headers.update({
            "X-API-Key": API_KEY,
            "Content-Type": "application/json",
        })
        self.session.timeout = 30
        self.dry_run = dry_run
        self.phases = phases or PHASES_FULL
        self.metrics = LoadTestMetrics()

        # State tracking
        self.assigned_slots = {}     # callsign → {track, slot_time_utc, slot_id}
        self.used_callsigns = set()  # all callsigns we've used
        self.available_slots = {}    # track → list of slot_time_utc (populated at startup)
        self.real_callsigns = []     # actual active flights from SWIM
        self.slot_pool = {}          # track → deque of known open slot times

    def _api_url(self, endpoint):
        return API_BASE + endpoint

    def _generate_callsign(self, real=False):
        """Generate a realistic callsign."""
        if real and self.real_callsigns:
            cs = random.choice(self.real_callsigns)
            self.real_callsigns.remove(cs)
            return cs
        prefix = random.choice(CALLSIGN_PREFIXES)
        num = random.randint(1, 999)
        suffix = random.choice(["", random.choice(string.ascii_uppercase)])
        return f"{prefix}{num}{suffix}"

    def _generate_fake_callsign(self):
        """Generate a callsign that definitely won't exist."""
        return f"ZZZ{random.randint(9000, 9999)}"

    def _do_request(self, method, endpoint, json_body=None, scenario="", phase=""):
        """Execute an API request and record metrics."""
        url = self._api_url(endpoint)

        if self.dry_run:
            print(f"  [DRY-RUN] {method} {endpoint} body={json.dumps(json_body)[:80] if json_body else 'none'}")
            self.metrics.record(endpoint, method, 200, 0, {"dry_run": True}, scenario, phase)
            return {"success": True, "data": {"dry_run": True}}, 200

        start = time.monotonic()
        try:
            if method == "GET":
                resp = self.session.get(url, timeout=30)
            else:
                resp = self.session.post(url, json=json_body, timeout=30)
            latency_ms = (time.monotonic() - start) * 1000
            try:
                body = resp.json()
            except Exception:
                body = {"raw": resp.text[:500]}

            error_code = body.get("code") if body.get("error") else None
            self.metrics.record(
                endpoint, method, resp.status_code, latency_ms,
                body, scenario, phase, error_code
            )
            return body, resp.status_code

        except requests.exceptions.Timeout:
            latency_ms = (time.monotonic() - start) * 1000
            self.metrics.record(
                endpoint, method, 0, latency_ms,
                None, scenario, phase, "TIMEOUT"
            )
            return {"error": True, "code": "TIMEOUT"}, 0

        except requests.exceptions.ConnectionError as e:
            latency_ms = (time.monotonic() - start) * 1000
            self.metrics.record(
                endpoint, method, 0, latency_ms,
                None, scenario, phase, "CONNECTION_ERROR"
            )
            return {"error": True, "code": "CONNECTION_ERROR", "message": str(e)[:200]}, 0

    def _fetch_session_status(self, phase=""):
        """Fetch current session status and record slot snapshot."""
        body, status = self._do_request(
            "GET",
            f"session-status.php?session_name={SESSION_NAME}",
            scenario="session_status", phase=phase
        )
        if body.get("success") and body.get("data"):
            data = body["data"]
            snapshot = {
                "flights": data.get("flights", {}),
                "tracks": {},
            }
            for t in data.get("tracks", []):
                snapshot["tracks"][t["track_name"]] = {
                    "total": t["total_slots"],
                    "assigned": t["assigned"],
                    "frozen": t["frozen"],
                    "open": t["open"],
                }
            self.metrics.record_snapshot(snapshot)
        return body, status

    def _discover_flights(self):
        """Discover real active callsigns from SWIM for realistic testing."""
        print("Discovering active flights from SWIM API...")
        try:
            resp = self.session.get(
                "https://perti.vatcscc.org/api/swim/v1/flights.php?format=json&active=true&limit=100",
                timeout=30
            )
            if resp.status_code == 200:
                data = resp.json()
                flights = data.get("data", [])
                if isinstance(flights, list):
                    for f in flights:
                        cs = (f.get("identity", {}).get("aircraft_identification")
                              or f.get("callsign", ""))
                        if cs and len(cs) >= 3:
                            self.real_callsigns.append(cs)
                print(f"  Found {len(self.real_callsigns)} active callsigns")
        except Exception as e:
            print(f"  Flight discovery failed: {e}")
            # Fall back to synthetic callsigns — test still works

    def _discover_open_slots(self):
        """Query session status to understand available slots."""
        print("Checking session slot availability...")
        body, status = self._do_request(
            "GET",
            f"session-status.php?session_name={SESSION_NAME}",
            scenario="startup", phase="init"
        )
        if body.get("success"):
            for t in body["data"].get("tracks", []):
                name = t["track_name"]
                print(f"  {name}: {t['open']} open / {t['total_slots']} total "
                      f"({t['assigned']} assigned, {t['frozen']} frozen)")
        return body

    # ── Scenario Implementations ──────────────────────────────────────────

    def scenario_full_lifecycle(self, phase):
        """Request a slot, then confirm the recommended track."""
        callsign = self._generate_callsign()
        self.used_callsigns.add(callsign)

        body, status = self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
            "aircraft_type": random.choice(AIRCRAFT_TYPES),
        }, scenario="full_lifecycle", phase=phase)

        if not body.get("success") or not body.get("data"):
            return  # request failed (FLIGHT_NOT_FOUND etc.) — counted in metrics

        data = body["data"]
        rec = data.get("recommended")
        if not rec:
            return  # no slots available

        # Confirm the recommended slot
        confirm_body, confirm_status = self._do_request("POST", "confirm-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "track": rec["track"],
            "slot_time_utc": rec["slot_time_utc"],
        }, scenario="full_lifecycle", phase=phase)

        if confirm_body.get("success"):
            self.assigned_slots[callsign] = {
                "track": rec["track"],
                "slot_time_utc": rec["slot_time_utc"],
                "slot_id": rec.get("slot_id"),
            }

    def scenario_alt_track(self, phase):
        """Request a slot, then confirm an alternative track instead of recommended."""
        callsign = self._generate_callsign()
        self.used_callsigns.add(callsign)

        body, status = self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
            "aircraft_type": random.choice(AIRCRAFT_TYPES),
            "preferred_track": random.choice(TRACKS),
        }, scenario="alt_track", phase=phase)

        if not body.get("success") or not body.get("data"):
            return

        data = body["data"]
        alternatives = data.get("alternatives", [])
        if not alternatives:
            # No alternatives available — fall back to recommended
            rec = data.get("recommended")
            if rec:
                self._do_request("POST", "confirm-slot.php", {
                    "session_name": SESSION_NAME,
                    "callsign": callsign,
                    "track": rec["track"],
                    "slot_time_utc": rec["slot_time_utc"],
                }, scenario="alt_track", phase=phase)
            return

        # Pick a random alternative (not the recommended)
        alt = random.choice(alternatives)
        confirm_body, _ = self._do_request("POST", "confirm-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "track": alt["track"],
            "slot_time_utc": alt["slot_time_utc"],
        }, scenario="alt_track", phase=phase)

        if confirm_body.get("success"):
            self.assigned_slots[callsign] = {
                "track": alt["track"],
                "slot_time_utc": alt["slot_time_utc"],
                "slot_id": alt.get("slot_id"),
            }

    def scenario_release_cycle(self, phase):
        """Request → confirm → release → re-request."""
        callsign = self._generate_callsign()
        self.used_callsigns.add(callsign)

        # Request
        body, _ = self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
            "aircraft_type": random.choice(AIRCRAFT_TYPES),
        }, scenario="release_cycle", phase=phase)

        if not body.get("success") or not body.get("data"):
            return

        rec = body["data"].get("recommended")
        if not rec:
            return

        # Confirm
        confirm_body, _ = self._do_request("POST", "confirm-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "track": rec["track"],
            "slot_time_utc": rec["slot_time_utc"],
        }, scenario="release_cycle", phase=phase)

        if not confirm_body.get("success"):
            return

        # Brief pause to simulate real user behavior
        time.sleep(random.uniform(0.5, 2.0))

        # Release
        self._do_request("POST", "release-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "reason": "COORDINATOR_RELEASE",
        }, scenario="release_cycle", phase=phase)

        # Remove from tracking
        self.assigned_slots.pop(callsign, None)

        # Re-request (don't confirm — just check availability)
        self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": callsign,
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
            "aircraft_type": random.choice(AIRCRAFT_TYPES),
        }, scenario="release_cycle", phase=phase)

    def scenario_flight_not_found(self, phase):
        """Request slot with a callsign not in swim_flights."""
        fake_cs = self._generate_fake_callsign()
        self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": fake_cs,
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
        }, scenario="flight_not_found", phase=phase)

    def scenario_slot_race(self, phase):
        """Try to confirm a slot that another request just took (race condition)."""
        # Pick a random assigned slot to simulate a race
        if self.assigned_slots:
            victim_cs = random.choice(list(self.assigned_slots.keys()))
            slot_info = self.assigned_slots[victim_cs]
            # Another "user" tries to confirm the same slot
            racer_cs = self._generate_callsign()
            self._do_request("POST", "confirm-slot.php", {
                "session_name": SESSION_NAME,
                "callsign": racer_cs,
                "track": slot_info["track"],
                "slot_time_utc": slot_info["slot_time_utc"],
            }, scenario="slot_race", phase=phase)
        else:
            # No assigned slots yet — do a regular request instead
            self.scenario_flight_not_found(phase)

    def scenario_session_status(self, phase):
        """GET session-status health check."""
        self._fetch_session_status(phase)

    def scenario_sessions_list(self, phase):
        """GET sessions list."""
        self._do_request("GET", "sessions.php",
                         scenario="sessions_list", phase=phase)

    def scenario_release_nonexistent(self, phase):
        """Release slot for a callsign that has no assignment."""
        fake_cs = self._generate_fake_callsign()
        self._do_request("POST", "release-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": fake_cs,
            "reason": "COORDINATOR_RELEASE",
        }, scenario="release_nonexistent", phase=phase)

    def scenario_release_invalid(self, phase):
        """Release with invalid reason value."""
        self._do_request("POST", "release-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": self._generate_callsign(),
            "reason": "INVALID_REASON_XYZ",
        }, scenario="release_invalid", phase=phase)

    def scenario_all_consumed(self, phase):
        """Request slot when all should be consumed (tests empty response)."""
        self._do_request("POST", "request-slot.php", {
            "session_name": SESSION_NAME,
            "callsign": self._generate_callsign(),
            "origin": random.choice(ORIGINS),
            "destination": random.choice(DESTINATIONS),
        }, scenario="all_consumed", phase=phase)

    SCENARIO_MAP = {
        "full_lifecycle":    scenario_full_lifecycle,
        "alt_track":         scenario_alt_track,
        "release_cycle":     scenario_release_cycle,
        "flight_not_found":  scenario_flight_not_found,
        "slot_race":         scenario_slot_race,
        "session_status":    scenario_session_status,
        "sessions_list":     scenario_sessions_list,
        "release_nonexistent": scenario_release_nonexistent,
        "release_invalid":   scenario_release_invalid,
        "all_consumed":      scenario_all_consumed,
    }

    def _pick_scenario(self):
        """Weighted random scenario selection."""
        names, weights = zip(*SCENARIOS)
        return random.choices(names, weights=weights, k=1)[0]

    def _execute_phase(self, phase_config):
        """Run a single phase with the specified rate pattern."""
        name = phase_config["name"]
        duration = phase_config["duration_sec"]
        rate_min = phase_config["rate_min"]
        rate_max = phase_config["rate_max"]

        print(f"\n{'='*60}")
        print(f"Phase: {name} | Duration: {duration}s | Rate: {rate_min}-{rate_max} req/min")
        print(f"{'='*60}")

        phase_start = time.monotonic()
        request_count = 0

        while (time.monotonic() - phase_start) < duration:
            # Pick rate for this interval (with jitter)
            target_rpm = random.uniform(rate_min, rate_max)
            interval = 60.0 / target_rpm
            # Add ±20% jitter
            interval *= random.uniform(0.8, 1.2)

            # Pick and execute scenario
            scenario = self._pick_scenario()
            handler = self.SCENARIO_MAP.get(scenario)
            if handler:
                try:
                    handler(self, name)
                except Exception as e:
                    print(f"  ERROR in {scenario}: {e}")
                    self.metrics.record(
                        "error", "ERROR", 0, 0, None,
                        scenario, name, "EXCEPTION"
                    )

            request_count += 1
            elapsed = time.monotonic() - phase_start
            remaining = duration - elapsed

            # Status update every 10 requests
            if request_count % 10 == 0:
                status_count = len(self.metrics.requests)
                assigned = len(self.assigned_slots)
                print(f"  [{name}] {request_count} scenarios | "
                      f"{status_count} total requests | "
                      f"{assigned} assigned slots | "
                      f"{remaining:.0f}s remaining")

            # Periodic session status snapshot (every 30s)
            if request_count % max(1, int(target_rpm / 2)) == 0:
                self._fetch_session_status(name)

            # Wait for next request
            if remaining > 0:
                time.sleep(min(interval, remaining))

        print(f"  Phase {name} complete: {request_count} scenarios executed")

    def run(self):
        """Execute the full load test."""
        print("\n" + "=" * 70)
        print("  CTP SWIM Endpoint Load Test")
        print(f"  Session: {SESSION_NAME}")
        print(f"  Target: {API_BASE}")
        print(f"  Started: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')} UTC")
        total_duration = sum(p["duration_sec"] for p in self.phases)
        print(f"  Planned duration: {total_duration // 60}m {total_duration % 60}s")
        if self.dry_run:
            print("  MODE: DRY RUN (no actual API calls)")
        print("=" * 70)

        # Step 1: Discover flights and slots
        if not self.dry_run:
            self._discover_flights()
        self._discover_open_slots()

        # Step 2: Initial snapshot
        print("\nInitial session status captured.")

        # Step 3: Execute phases
        test_start = time.monotonic()
        for phase_config in self.phases:
            self._execute_phase(phase_config)

        test_duration = time.monotonic() - test_start

        # Step 4: Final snapshot
        print("\n" + "=" * 60)
        print("Collecting final metrics...")
        self._fetch_session_status("final")

        # Step 5: Compute and save results
        summary = self.metrics.compute_summary()
        summary["test_config"] = {
            "session_name": SESSION_NAME,
            "api_base": API_BASE,
            "dry_run": self.dry_run,
            "phases": self.phases,
            "actual_duration_sec": round(test_duration, 1),
        }
        summary["assigned_at_end"] = len(self.assigned_slots)
        summary["assigned_callsigns"] = list(self.assigned_slots.keys())

        return summary

    def cleanup(self):
        """Release all remaining assigned slots."""
        if not self.assigned_slots:
            print("No slots to clean up.")
            return

        print(f"\nCleaning up {len(self.assigned_slots)} assigned slots...")
        released = 0
        for callsign in list(self.assigned_slots.keys()):
            body, status = self._do_request("POST", "release-slot.php", {
                "session_name": SESSION_NAME,
                "callsign": callsign,
                "reason": "COORDINATOR_RELEASE",
            }, scenario="cleanup", phase="cleanup")
            if body.get("success"):
                released += 1
                del self.assigned_slots[callsign]
            else:
                print(f"  Failed to release {callsign}: {body.get('message', 'unknown')}")

        print(f"  Released {released} slots")


def generate_report(summary, output_path):
    """Generate markdown report from test results."""
    s = summary
    ol = s.get("overall_latency", {})
    status_dist = s.get("status_distribution", {})
    error_codes = s.get("error_codes", {})
    endpoints = s.get("endpoints", {})
    phases = s.get("phases", {})
    scenarios = s.get("scenarios", {})
    slot_timeline = s.get("slot_utilization_timeline", [])

    report = []
    report.append("# CTP SWIM Endpoint Load Test Results\n")
    report.append(f"**Date**: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M')} UTC")
    report.append(f"**Session**: {s.get('test_config', {}).get('session_name', 'N/A')}")
    report.append(f"**Duration**: {s.get('duration_sec', 0):.0f}s "
                  f"({s.get('duration_sec', 0) / 60:.1f} min)")
    report.append(f"**Total Requests**: {s.get('total_requests', 0)}")
    report.append(f"**Slots Assigned at End**: {s.get('assigned_at_end', 0)}")
    report.append("")

    # Overall latency
    report.append("## Overall Latency\n")
    report.append("| Metric | Value |")
    report.append("|--------|-------|")
    report.append(f"| p50 | {ol.get('p50', 0):.0f} ms |")
    report.append(f"| p95 | {ol.get('p95', 0):.0f} ms |")
    report.append(f"| p99 | {ol.get('p99', 0):.0f} ms |")
    report.append(f"| Max | {ol.get('max', 0):.0f} ms |")
    report.append(f"| Avg | {ol.get('avg', 0):.0f} ms |")
    report.append("")

    # Status distribution
    report.append("## HTTP Status Distribution\n")
    report.append("| Status | Count | Percentage |")
    report.append("|--------|-------|------------|")
    total = s.get("total_requests", 1)
    for code in sorted(status_dist.keys()):
        count = status_dist[code]
        pct = count / total * 100
        report.append(f"| {code} | {count} | {pct:.1f}% |")
    report.append("")

    # Error codes
    if error_codes:
        report.append("## Error Codes\n")
        report.append("| Code | Count |")
        report.append("|------|-------|")
        for code, count in sorted(error_codes.items(), key=lambda x: -x[1]):
            report.append(f"| {code} | {count} |")
        report.append("")

    # Endpoint breakdown
    report.append("## Endpoint Latency Breakdown\n")
    report.append("| Endpoint | Count | Avg (ms) | p50 | p95 | Max |")
    report.append("|----------|-------|----------|-----|-----|-----|")
    for ep in sorted(endpoints.keys()):
        es = endpoints[ep]
        report.append(f"| {ep} | {es['count']} | {es['avg']:.0f} | "
                      f"{es['p50']:.0f} | {es['p95']:.0f} | {es['max']:.0f} |")
    report.append("")

    # Phase breakdown
    report.append("## Phase Performance\n")
    report.append("| Phase | Requests | Throughput (RPM) | p50 (ms) | p95 (ms) | Max (ms) | 5xx |")
    report.append("|-------|----------|-----------------|----------|----------|----------|-----|")
    for phase_name in ["warm-up", "peak-1", "off-peak", "peak-2", "cool-down", "final"]:
        if phase_name in phases:
            ps = phases[phase_name]
            report.append(f"| {phase_name} | {ps['request_count']} | "
                          f"{ps['throughput_rpm']:.1f} | {ps['p50']:.0f} | "
                          f"{ps['p95']:.0f} | {ps['max']:.0f} | {ps['error_5xx']} |")
    report.append("")

    # Scenario breakdown
    report.append("## Scenario Results\n")
    report.append("| Scenario | Count | Success Rate | p50 (ms) | Max (ms) |")
    report.append("|----------|-------|-------------|----------|----------|")
    for sc in sorted(scenarios.keys()):
        ss = scenarios[sc]
        report.append(f"| {sc} | {ss['count']} | {ss['success_rate']:.1f}% | "
                      f"{ss['p50']:.0f} | {ss['max']:.0f} |")
    report.append("")

    # Slot utilization timeline
    if slot_timeline:
        report.append("## Slot Utilization Timeline\n")
        report.append("| Time | NAT-A Open | NAT-A Assigned | NAT-B Open | NAT-B Assigned | NAT-C Open | NAT-C Assigned |")
        report.append("|------|-----------|---------------|-----------|---------------|-----------|---------------|")
        for snap in slot_timeline:
            ts = snap["timestamp"][:19]
            tracks = snap.get("tracks", {})
            a = tracks.get("NAT-A", {})
            b = tracks.get("NAT-B", {})
            c = tracks.get("NAT-C", {})
            report.append(f"| {ts} | {a.get('open', '-')} | {a.get('assigned', '-')} | "
                          f"{b.get('open', '-')} | {b.get('assigned', '-')} | "
                          f"{c.get('open', '-')} | {c.get('assigned', '-')} |")
        report.append("")

    # Key findings
    report.append("## Key Findings\n")

    # Check for 5xx errors
    error_5xx = sum(v for k, v in status_dist.items() if int(k) >= 500)
    if error_5xx == 0:
        report.append("- **No 5xx errors** observed during the test")
    else:
        report.append(f"- **{error_5xx} 5xx errors** detected — investigate infrastructure issues")

    # Check confirm-slot latency (CTOT cascade)
    confirm_stats = endpoints.get("confirm-slot.php", {})
    if confirm_stats:
        p95 = confirm_stats.get("p95", 0)
        if p95 < 5000:
            report.append(f"- Confirm-slot p95 latency: **{p95:.0f}ms** (within 5s target)")
        else:
            report.append(f"- Confirm-slot p95 latency: **{p95:.0f}ms** (EXCEEDS 5s target)")

    # Timeout count
    timeout_count = error_codes.get("TIMEOUT", 0)
    if timeout_count > 0:
        report.append(f"- **{timeout_count} timeouts** detected")
    else:
        report.append("- **Zero timeouts** during the test")

    report.append("")
    report.append("---")
    report.append(f"*Generated by `scripts/testing/ctp_load_test.py` at "
                  f"{datetime.now(timezone.utc).isoformat()}*")

    report_text = "\n".join(report)

    output_path = Path(output_path)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(report_text, encoding="utf-8")
    print(f"\nReport written to: {output_path}")
    return report_text


def main():
    parser = argparse.ArgumentParser(description="CTP SWIM Endpoint Load Test")
    parser.add_argument("--dry-run", action="store_true",
                        help="Simulate without making actual API calls")
    parser.add_argument("--duration", choices=["SHORT", "FULL"], default="FULL",
                        help="Test duration profile (SHORT=~8min, FULL=~32min)")
    parser.add_argument("--output-dir", type=str, default=None,
                        help="Output directory for results")
    parser.add_argument("--no-cleanup", action="store_true",
                        help="Skip cleanup of assigned slots after test")
    args = parser.parse_args()

    phases = PHASES_SHORT if args.duration == "SHORT" else PHASES_FULL

    # Determine output paths
    if args.output_dir:
        out_dir = Path(args.output_dir)
    else:
        # Default to docs/testing/ relative to project root
        script_dir = Path(__file__).resolve().parent
        out_dir = script_dir.parent.parent / "docs" / "testing"

    timestamp = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    json_path = out_dir / f"{timestamp}-ctp-load-test-results.json"
    md_path = out_dir / f"{timestamp}-ctp-load-test-results.md"

    # Run test
    test = CTPLoadTest(dry_run=args.dry_run, phases=phases)
    try:
        summary = test.run()
    except KeyboardInterrupt:
        print("\n\nTest interrupted! Computing partial results...")
        summary = test.metrics.compute_summary()
        summary["interrupted"] = True

    # Save JSON results
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path.write_text(json.dumps(summary, indent=2, default=str), encoding="utf-8")
    print(f"\nJSON results: {json_path}")

    # Generate markdown report
    generate_report(summary, md_path)

    # Cleanup
    if not args.no_cleanup and not args.dry_run:
        test.cleanup()

    # Final status
    final_body, _ = test._fetch_session_status("post-cleanup")
    if final_body.get("success"):
        print("\nFinal session state:")
        for t in final_body["data"].get("tracks", []):
            print(f"  {t['track_name']}: {t['open']} open / "
                  f"{t['assigned']} assigned / {t['total_slots']} total")

    # Summary stats
    total = summary.get("total_requests", 0)
    dur = summary.get("duration_sec", 0)
    p50 = summary.get("overall_latency", {}).get("p50", 0)
    p95 = summary.get("overall_latency", {}).get("p95", 0)
    err_5xx = sum(v for k, v in summary.get("status_distribution", {}).items()
                  if int(k) >= 500)

    print(f"\n{'='*60}")
    print(f"  RESULTS SUMMARY")
    print(f"  Total requests: {total}")
    print(f"  Duration: {dur:.0f}s ({dur/60:.1f} min)")
    print(f"  Overall p50: {p50:.0f}ms | p95: {p95:.0f}ms")
    print(f"  5xx errors: {err_5xx}")
    print(f"{'='*60}")


if __name__ == "__main__":
    main()
