"""
VATSWIM Connector for ECFMP

Bidirectional client for consuming and pushing ECFMP flow control data
via the VATSWIM API.

Read path:
  ECFMP API -> VATSWIM poll daemon -> tmi_flow_measures/events -> SWIM API

Push path:
  Provider -> VATSWIMConnector.push_flow_measures() -> /api/swim/v1/tmi/flow/ingest.php

Usage:
    from vatswim_connector import VATSWIMConnector

    connector = VATSWIMConnector("swim_par_your_key_here")

    # Read active measures
    measures = connector.get_flow_measures()

    # Push a new measure
    result = connector.push_flow_measures([{
        "external_id": "67890",
        "ctl_element": "EGTT",
        "measure_type": "MDI",
        "start_utc": "2026-03-30T14:00:00Z",
        "end_utc": "2026-03-30T18:00:00Z",
    }])
"""

import json
import logging
from typing import Dict, List
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

logger = logging.getLogger(__name__)

DEFAULT_BASE_URL = "https://perti.vatcscc.org"


class VATSWIMConnector:
    """Bidirectional client for ECFMP flow data via the VATSWIM API."""

    def __init__(self, api_key: str, base_url: str = DEFAULT_BASE_URL):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")

    def get_flow_events(self, active_only: bool = True) -> Dict:
        """
        Get ECFMP flow events from VATSWIM.

        Args:
            active_only: If True, only return currently active events

        Returns:
            API response with flow events
        """
        params = "?active=1" if active_only else ""
        return self._get(f"/api/swim/v1/tmi/flow/events.php{params}")

    def get_flow_measures(self, active_only: bool = True) -> Dict:
        """
        Get ECFMP flow measures from VATSWIM.

        Args:
            active_only: If True, only return currently active measures

        Returns:
            API response with flow measures
        """
        params = "?active=1" if active_only else ""
        return self._get(f"/api/swim/v1/tmi/flow/measures.php{params}")

    def get_flow_providers(self) -> Dict:
        """Get registered flow data providers."""
        return self._get("/api/swim/v1/tmi/flow/providers.php")

    def check_health(self) -> Dict:
        """Check VATSWIM connector health status."""
        return self._get("/api/swim/v1/connectors/health.php")

    # ── Push methods ────────────────────────────────────────────────────

    def push_flow_measures(self, measures: List[Dict]) -> Dict:
        """
        Push flow measures to VATSWIM.

        Args:
            measures: List of flow measure records, each containing:
                - external_id (str, required): Provider's measure ID
                - ident (str): Measure identifier (e.g., "EGLL_MDI_01")
                - ctl_element (str, required): FIR/facility code (e.g., "EGTT")
                - element_type (str): "FIR" (default) or "FACILITY"
                - measure_type (str, required): MDI, MIT, RATE, GS, REROUTE, OTHER
                - measure_value (str): Numeric value (e.g., "120")
                - measure_unit (str): SEC, PER_HOUR, NM, KTS, MACH
                - reason (str): Reason for the measure
                - filters_json (str): JSON string of applicability filters
                - mandatory_route_json (str): JSON string for REROUTE type
                - start_utc (str, required): ISO 8601 start time
                - end_utc (str, required): ISO 8601 end time
                - status (str): NOTIFIED, ACTIVE, EXPIRED, WITHDRAWN
                - withdrawn_at (str): ISO 8601 withdrawal time

        Returns:
            API response with processed/created/updated/errors counts

        Raises:
            ValueError: If batch exceeds 200 measures
        """
        if len(measures) > 200:
            raise ValueError(f"Batch exceeds max 200 measures (got {len(measures)})")
        return self._post("/api/swim/v1/tmi/flow/ingest.php", {"measures": measures})

    def withdraw_flow_measure(self, external_id: str, reason: str = "Withdrawn") -> Dict:
        """
        Withdraw a single flow measure by external ID.

        Convenience method that sends a status=WITHDRAWN update.

        Args:
            external_id: The provider's measure ID to withdraw
            reason: Withdrawal reason text

        Returns:
            API response from push_flow_measures
        """
        from datetime import datetime, timezone

        now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        return self.push_flow_measures([{
            "external_id": external_id,
            "status": "WITHDRAWN",
            "withdrawn_at": now,
            "reason": reason,
        }])

    # ── HTTP helpers ──────────────────────────────────────────────────

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
            return {"success": False, "status": e.code, "error": body}
        except URLError as e:
            return {"success": False, "error": str(e)}


# ── Example usage ──────────────────────────────────────────────────────

if __name__ == "__main__":
    import sys

    api_key = sys.argv[1] if len(sys.argv) > 1 else "swim_dev_test_key"
    mode = sys.argv[2] if len(sys.argv) > 2 else "read"
    connector = VATSWIMConnector(api_key)

    if mode == "push":
        print("=== Push Flow Measure ===")
        result = connector.push_flow_measures([{
            "external_id": "test_001",
            "ident": "EGLL_MDI_01",
            "ctl_element": "EGTT",
            "measure_type": "MDI",
            "measure_value": "120",
            "measure_unit": "SEC",
            "reason": "Test measure",
            "start_utc": "2026-03-30T14:00:00Z",
            "end_utc": "2026-03-30T18:00:00Z",
            "status": "NOTIFIED",
        }])
        print(json.dumps(result, indent=2))

    elif mode == "withdraw":
        ext_id = sys.argv[3] if len(sys.argv) > 3 else "test_001"
        print(f"=== Withdraw Measure {ext_id} ===")
        result = connector.withdraw_flow_measure(ext_id)
        print(json.dumps(result, indent=2))

    else:
        print("=== Flow Events ===")
        events = connector.get_flow_events()
        print(json.dumps(events, indent=2))

        print("\n=== Flow Measures ===")
        measures = connector.get_flow_measures()
        print(json.dumps(measures, indent=2))

        print("\n=== Providers ===")
        providers = connector.get_flow_providers()
        print(json.dumps(providers, indent=2))
