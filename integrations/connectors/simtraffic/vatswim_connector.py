"""
VATSWIM Connector for SimTraffic

Pushes departure/arrival timing and metering data to the VATSWIM API.
SimTraffic is the Priority 1 source for metering and flight timing data.

Usage:
    from vatswim_connector import VATSWIMConnector

    connector = VATSWIMConnector("swim_sys_your_key_here")
    result = connector.send_flight_times([
        {
            "callsign": "UAL123",
            "departure": {"takeoff_time": "2026-03-06T14:45:00Z"},
            "arrival": {"eta": "2026-03-06T17:15:00Z", "metering_fix": "CAMRN"}
        }
    ])
"""

import json
import time
import logging
from typing import List, Dict, Optional
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError

logger = logging.getLogger(__name__)

MAX_BATCH_SIZE = 500
DEFAULT_BASE_URL = "https://perti.vatcscc.org"
MAX_RETRIES = 3
RETRY_BACKOFF = [1, 3, 10]  # seconds


class VATSWIMConnector:
    """Client for pushing SimTraffic data to VATSWIM."""

    def __init__(self, api_key: str, base_url: str = DEFAULT_BASE_URL):
        self.api_key = api_key
        self.base_url = base_url.rstrip("/")

    def send_flight_times(
        self,
        flights: List[Dict],
        mode: str = "push",
    ) -> Dict:
        """
        Send flight timing data to VATSWIM.

        Args:
            flights: List of flight timing updates (max 500 per batch)
            mode: "push" (SimTraffic sends) or "pull" (VATSWIM fetches)

        Returns:
            API response dict with processed/updated/error counts
        """
        if len(flights) > MAX_BATCH_SIZE:
            raise ValueError(f"Batch exceeds max {MAX_BATCH_SIZE} flights")

        payload = {"mode": mode, "flights": flights}
        return self._post("/api/swim/v1/ingest/simtraffic.php", payload)

    def check_health(self) -> Dict:
        """Check VATSWIM connector health status."""
        return self._get("/api/swim/v1/connectors/health.php")

    def _post(self, path: str, payload: Dict) -> Dict:
        """POST JSON to VATSWIM with retry logic."""
        url = f"{self.base_url}{path}"
        data = json.dumps(payload).encode("utf-8")

        for attempt in range(MAX_RETRIES):
            try:
                req = Request(url, data=data, method="POST")
                req.add_header("Authorization", f"Bearer {self.api_key}")
                req.add_header("Content-Type", "application/json")
                req.add_header("Accept", "application/json")

                with urlopen(req, timeout=30) as resp:
                    body = resp.read().decode("utf-8")
                    return json.loads(body)

            except HTTPError as e:
                status = e.code
                body = e.read().decode("utf-8", errors="replace")

                if status == 429:
                    # Rate limited — backoff and retry
                    wait = RETRY_BACKOFF[min(attempt, len(RETRY_BACKOFF) - 1)]
                    logger.warning(f"Rate limited (429), retrying in {wait}s")
                    time.sleep(wait)
                    continue

                if status >= 500:
                    wait = RETRY_BACKOFF[min(attempt, len(RETRY_BACKOFF) - 1)]
                    logger.warning(f"Server error ({status}), retrying in {wait}s")
                    time.sleep(wait)
                    continue

                return {"success": False, "status": status, "error": body}

            except URLError as e:
                if attempt < MAX_RETRIES - 1:
                    wait = RETRY_BACKOFF[attempt]
                    logger.warning(f"Connection error: {e}, retrying in {wait}s")
                    time.sleep(wait)
                else:
                    return {"success": False, "error": str(e)}

        return {"success": False, "error": "Max retries exceeded"}

    def _get(self, path: str) -> Dict:
        """GET JSON from VATSWIM."""
        url = f"{self.base_url}{path}"
        req = Request(url, method="GET")
        req.add_header("Authorization", f"Bearer {self.api_key}")
        req.add_header("Accept", "application/json")

        try:
            with urlopen(req, timeout=15) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except (HTTPError, URLError) as e:
            return {"success": False, "error": str(e)}


# ── Example usage ──────────────────────────────────────────────────────

if __name__ == "__main__":
    import sys

    api_key = sys.argv[1] if len(sys.argv) > 1 else "swim_sys_test_key"
    connector = VATSWIMConnector(api_key)

    result = connector.send_flight_times([
        {
            "callsign": "UAL123",
            "departure_afld": "KORD",
            "arrival_afld": "KJFK",
            "departure": {
                "push_time": "2026-03-06T14:30:00Z",
                "takeoff_time": "2026-03-06T14:45:00Z",
            },
            "arrival": {
                "eta": "2026-03-06T17:15:00Z",
                "metering_fix": "CAMRN",
                "rwy_assigned": "31L",
            },
        }
    ])

    print(json.dumps(result, indent=2))
