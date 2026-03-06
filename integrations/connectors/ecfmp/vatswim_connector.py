"""
VATSWIM Connector for ECFMP

Read-only client for consuming ECFMP flow control data from the VATSWIM API.
VATSWIM polls the ECFMP API server-side and exposes the data via REST endpoints.

Note: This is a READ connector. ECFMP data flows:
  ECFMP API -> VATSWIM poll daemon -> tmi_flow_measures/events -> SWIM API

Usage:
    from vatswim_connector import VATSWIMConnector

    connector = VATSWIMConnector("swim_dev_your_key_here")
    events = connector.get_flow_events()
    measures = connector.get_flow_measures()
"""

import json
import logging
from typing import Dict, List, Optional
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

logger = logging.getLogger(__name__)

DEFAULT_BASE_URL = "https://perti.vatcscc.org"


class VATSWIMConnector:
    """Client for reading ECFMP flow data from the VATSWIM API."""

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

    api_key = sys.argv[1] if len(sys.argv) > 1 else "swim_dev_test_key"
    connector = VATSWIMConnector(api_key)

    print("=== Flow Events ===")
    events = connector.get_flow_events()
    print(json.dumps(events, indent=2))

    print("\n=== Flow Measures ===")
    measures = connector.get_flow_measures()
    print(json.dumps(measures, indent=2))

    print("\n=== Providers ===")
    providers = connector.get_flow_providers()
    print(json.dumps(providers, indent=2))
