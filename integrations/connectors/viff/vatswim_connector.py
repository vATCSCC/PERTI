"""
VATSWIM Connector for vIFF (ATFCM System)

Pushes EU ATFCM data to VATSWIM:
- CDM milestones: CTOT, EOBT, TOBT, ATFCM status → /api/swim/v1/ingest/cdm.php
- Flow regulations: CTOT restrictions, ATFCM measures → /api/swim/v1/tmi/flow/ingest.php

Usage:
    from vatswim_connector import VIFFConnector

    connector = VIFFConnector("swim_sys_viff_key")

    # Push CDM milestones
    connector.push_cdm_milestones([{
        "callsign": "BAW123",
        "airport": "EGLL",
        "tobt": "2026-03-30T14:30:00Z",
        "readiness_state": "READY",
        "source": "VIFF_CDM"
    }])

    # Push flow regulation
    connector.push_flow_measures([{
        "external_id": "REG-EGLL-001",
        "ctl_element": "EGTT",
        "measure_type": "MDI",
        "measure_value": "120",
        "measure_unit": "SEC",
        "start_utc": "2026-03-30T14:00:00Z",
        "end_utc": "2026-03-30T18:00:00Z",
        "status": "ACTIVE"
    }])
"""

import json
import logging
from typing import Dict, List, Optional
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

logger = logging.getLogger(__name__)

DEFAULT_BASE_URL = "https://perti.vatcscc.org"
MAX_CDM_BATCH = 500
MAX_FLOW_BATCH = 200


class VIFFConnector:
    """Client for pushing vIFF ATFCM data to VATSWIM."""

    def __init__(self, api_key: str, base_url: str = DEFAULT_BASE_URL):
        if not api_key:
            raise ValueError("API key is required")
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")

    def push_cdm_milestones(self, updates: List[Dict]) -> Dict:
        """
        Push CDM milestone updates (CTOT, EOBT, TOBT, ATFCM status) to VATSWIM.

        Args:
            updates: List of CDM update records, each containing:
                - callsign (str, required): Aircraft callsign
                - airport (str): Departure airport ICAO
                - tobt (str): Target Off-Block Time (ISO 8601)
                - tsat (str): Target Startup Approval Time
                - ttot (str): Target Takeoff Time
                - asat (str): Actual Startup Approval Time
                - exot (int): Expected Taxi Out Time (minutes, 0-120)
                - readiness_state (str): PLANNING|BOARDING|READY|TAXIING|CANCELLED
                - atfcm_excluded (bool): ATFCM exclusion flag
                - atfcm_ready (bool): ATFCM readiness flag
                - atfcm_slot_improvement (bool): Slot Improvement Request flag
                - source (str): Source identifier (default: VIFF_CDM)

        Returns:
            API response with processed/updated/not_found/errors counts
        """
        if len(updates) > MAX_CDM_BATCH:
            raise ValueError(f"Batch exceeds max {MAX_CDM_BATCH} updates (got {len(updates)})")

        # Ensure source defaults to VIFF_CDM
        for u in updates:
            u.setdefault("source", "VIFF_CDM")

        return self._post("/api/swim/v1/ingest/cdm.php", {"updates": updates})

    def push_flow_measures(self, measures: List[Dict]) -> Dict:
        """
        Push ATFCM flow measures (regulations, restrictions) to VATSWIM.

        Args:
            measures: List of flow measure records, each containing:
                - external_id (str, required): vIFF regulation ID
                - ident (str): Regulation identifier
                - ctl_element (str, required): FIR code (e.g., "EGTT")
                - element_type (str): "FIR" (default)
                - measure_type (str, required): MDI, MIT, RATE, GS, REROUTE, OTHER
                - measure_value (str): Numeric value
                - measure_unit (str): SEC, PER_HOUR, NM, KTS, MACH
                - reason (str): Regulation reason
                - filters_json (str): JSON string of applicability filters
                - start_utc (str, required): ISO 8601 start time
                - end_utc (str, required): ISO 8601 end time
                - status (str): NOTIFIED, ACTIVE, EXPIRED, WITHDRAWN

        Returns:
            API response with processed/created/updated/errors counts
        """
        if len(measures) > MAX_FLOW_BATCH:
            raise ValueError(f"Batch exceeds max {MAX_FLOW_BATCH} measures (got {len(measures)})")
        return self._post("/api/swim/v1/tmi/flow/ingest.php", {"measures": measures})

    def convert_etfms_to_cdm(self, flights: List[Dict], midnight_rollover_hour: int = 6) -> List[Dict]:
        """
        Convert vIFF /etfms/relevant response to VATSWIM CDM format.

        Handles HHMM/HHMMSS time format conversion to ISO 8601.

        Args:
            flights: Raw vIFF etfms/relevant response array
            midnight_rollover_hour: Hours behind current time before assuming next day

        Returns:
            List of CDM update records ready for push_cdm_milestones()
        """
        from datetime import datetime, timezone, timedelta

        now = datetime.now(timezone.utc)
        updates = []

        for f in flights:
            if not f.get("isCdm"):
                continue
            callsign = f.get("callsign", "").strip()
            if not callsign:
                continue

            record = {
                "callsign": callsign,
                "airport": f.get("departure", ""),
                "source": "VIFF_CDM",
            }

            # Convert HHMM/HHMMSS times to ISO 8601
            for field, key in [("tobt", "tobt"), ("eobt", "tobt")]:
                val = f.get(field)
                if val and key not in record:
                    converted = self._convert_hhmm(val, now, midnight_rollover_hour)
                    if converted:
                        record[key] = converted

            for field, key in [("ctot", "tsat"), ("aobt", "asat")]:
                val = f.get(field)
                if val:
                    converted = self._convert_hhmm(val, now, midnight_rollover_hour)
                    if converted:
                        record[key] = converted

            taxi = f.get("taxi")
            if taxi is not None and isinstance(taxi, (int, float)) and 0 <= taxi <= 120:
                record["exot"] = int(taxi)

            # ATFCM flags
            atfcm = f.get("atfcmData") or {}
            if "excluded" in atfcm:
                record["atfcm_excluded"] = bool(atfcm["excluded"])
            if "isRea" in atfcm:
                record["atfcm_ready"] = bool(atfcm["isRea"])
            if "SIR" in atfcm:
                record["atfcm_slot_improvement"] = bool(atfcm["SIR"])

            updates.append(record)

        return updates

    def convert_restrictions_to_flow(self, restrictions: List[Dict], fir_code: str = "EURC") -> List[Dict]:
        """
        Convert vIFF /etfms/restricted response to VATSWIM flow measure format.

        Args:
            restrictions: Raw vIFF restriction array with callsign + ctot + mostPenalizingAirspace
            fir_code: Default FIR code if not derivable from restriction data

        Returns:
            List of flow measure records ready for push_flow_measures()
        """
        from datetime import datetime, timezone

        now_str = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        measures_by_airspace = {}

        for r in restrictions:
            airspace = r.get("mostPenalizingAirspace", "UNKNOWN")
            if airspace not in measures_by_airspace:
                measures_by_airspace[airspace] = {
                    "external_id": f"VIFF-REG-{airspace}",
                    "ident": airspace,
                    "ctl_element": fir_code,
                    "element_type": "FIR",
                    "measure_type": "MDI",
                    "reason": f"ATFCM regulation: {airspace}",
                    "start_utc": now_str,
                    "end_utc": now_str,
                    "status": "ACTIVE",
                    "filters_json": json.dumps({"regulation": airspace}),
                }

        return list(measures_by_airspace.values())

    def check_health(self) -> Dict:
        """Check VATSWIM connector health status."""
        return self._get("/api/swim/v1/connectors/health.php")

    @staticmethod
    def _convert_hhmm(val: str, now, rollover_hour: int = 6) -> Optional[str]:
        """Convert HHMM or HHMMSS to ISO 8601 UTC, with midnight rollover."""
        from datetime import datetime, timezone, timedelta

        val = str(val).strip()
        if len(val) == 4:
            h, m, s = int(val[:2]), int(val[2:4]), 0
        elif len(val) == 6:
            h, m, s = int(val[:2]), int(val[2:4]), int(val[4:6])
        else:
            return None

        if not (0 <= h <= 23 and 0 <= m <= 59 and 0 <= s <= 59):
            return None

        dt = now.replace(hour=h, minute=m, second=s, microsecond=0)
        if h < (now.hour - rollover_hour):
            dt += timedelta(days=1)

        return dt.strftime("%Y-%m-%dT%H:%M:%SZ")

    def _post(self, path: str, payload: Dict) -> Dict:
        """POST JSON to VATSWIM API."""
        url = f"{self.base_url}{path}"
        data = json.dumps(payload).encode("utf-8")
        req = Request(url, data=data, method="POST")
        req.add_header("Authorization", f"Bearer {self.api_key}")
        req.add_header("Content-Type", "application/json")
        req.add_header("Accept", "application/json")

        try:
            with urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except HTTPError as e:
            body = e.read().decode("utf-8", errors="replace")
            logger.error("VATSWIM push failed: %d %s", e.code, body[:200])
            return {"success": False, "status": e.code, "error": body}
        except URLError as e:
            logger.error("VATSWIM connection failed: %s", e)
            return {"success": False, "error": str(e)}

    def _get(self, path: str) -> Dict:
        """GET JSON from VATSWIM API."""
        url = f"{self.base_url}{path}"
        req = Request(url, method="GET")
        req.add_header("Authorization", f"Bearer {self.api_key}")
        req.add_header("Accept", "application/json")

        try:
            with urlopen(req, timeout=15) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except HTTPError as e:
            body = e.read().decode("utf-8", errors="replace")
            return {"success": False, "status": e.code, "error": body}
        except URLError as e:
            return {"success": False, "error": str(e)}


# ── Example usage ──────────────────────────────────────────────────────

if __name__ == "__main__":
    import sys

    api_key = sys.argv[1] if len(sys.argv) > 1 else "swim_sys_test_key"
    connector = VIFFConnector(api_key)

    # Push CDM milestones
    print("=== Push CDM Milestones ===")
    result = connector.push_cdm_milestones([{
        "callsign": "BAW123",
        "airport": "EGLL",
        "tobt": "2026-03-30T14:30:00Z",
        "readiness_state": "READY",
    }])
    print(json.dumps(result, indent=2))

    # Push flow regulation
    print("\n=== Push Flow Measure ===")
    result = connector.push_flow_measures([{
        "external_id": "VIFF-REG-EGLL001",
        "ident": "EGLL_MDI_01",
        "ctl_element": "EGTT",
        "measure_type": "MDI",
        "measure_value": "120",
        "measure_unit": "SEC",
        "reason": "Weather at EGLL",
        "start_utc": "2026-03-30T14:00:00Z",
        "end_utc": "2026-03-30T18:00:00Z",
        "status": "ACTIVE",
    }])
    print(json.dumps(result, indent=2))

    # Health check
    print("\n=== Health ===")
    print(json.dumps(connector.check_health(), indent=2))
