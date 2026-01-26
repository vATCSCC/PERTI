"""
SID/STAR procedure detection and formatting.

Handles:
- Detecting procedure names in route strings
- Formatting routes with proper dot notation (FIX.PROCEDURE)
- Loading known procedures from dp_full_routes.csv and star_full_routes.csv
"""

import re
import csv
from typing import Set, Optional
from pathlib import Path


class ProcedureDetector:
    """
    Detect and format SID/STAR procedure references in route strings.

    Naming conventions supported:
    - Standard: ONDRE1, GLAVN2, JJEDI4 (letters + single digit)
    - RNAV: CWRLD6, ANJLL3 (letters + single digit)
    - Extended: TERPZ8, SCOOB (4-6 letters)
    - With revision: BORDR1 -> BORDR2 (same base, different digit)

    Format rules:
    - Procedure at end of route segment should connect to prior fix
    - "SKWKR JJEDI4" -> "SKWKR.JJEDI4"
    - "HINTZ ONDRE1" -> "HINTZ.ONDRE1"
    """

    # Pattern: 3-6 uppercase letters followed by single digit (1-9)
    PROCEDURE_PATTERN = re.compile(r'^[A-Z]{3,6}[1-9]$')

    # Pattern for procedure with transition: FIX.PROCEDURE
    TRANSITION_PATTERN = re.compile(r'^([A-Z0-9]+)\.([A-Z]{3,6}[1-9])$')

    def __init__(self, dp_csv: Optional[Path] = None, star_csv: Optional[Path] = None):
        """
        Initialize detector with optional procedure databases.

        Args:
            dp_csv: Path to dp_full_routes.csv for known SIDs
            star_csv: Path to star_full_routes.csv for known STARs
        """
        self.known_procedures: Set[str] = set()

        if dp_csv and dp_csv.exists():
            self._load_procedures_from_csv(dp_csv, 'DP_COMPUTER_CODE')
        if star_csv and star_csv.exists():
            self._load_procedures_from_csv(star_csv, 'STAR_COMPUTER_CODE')

    def _load_procedures_from_csv(self, csv_path: Path, code_column: str):
        """Load procedure names from a CSV file."""
        try:
            with open(csv_path, 'r', encoding='utf-8-sig') as f:
                reader = csv.DictReader(f)
                for row in reader:
                    code = row.get(code_column, '').strip()
                    if code:
                        # Extract base procedure name (before any dot)
                        if '.' in code:
                            # Code like "ONDRE1.ONDRE" -> extract "ONDRE1"
                            base = code.split('.')[0]
                        else:
                            base = code
                        self.known_procedures.add(base.upper())
        except Exception:
            pass  # Silently fail if file can't be read

    def is_procedure(self, token: str) -> bool:
        """
        Check if a token is a procedure name.

        Args:
            token: A single token from a route string

        Returns:
            True if token looks like a procedure name
        """
        token = token.upper().strip()

        # Must be at least 4 characters
        if len(token) < 4:
            return False

        # Check if it's already in FIX.PROCEDURE format
        if '.' in token:
            match = self.TRANSITION_PATTERN.match(token)
            if match:
                return True
            return False

        # Check against known procedures first (authoritative)
        if token in self.known_procedures:
            return True

        # Pattern match: letters followed by single digit
        if self.PROCEDURE_PATTERN.match(token):
            return True

        return False

    def format_route(self, route_str: str) -> str:
        """
        Format a route string with proper dot notation for procedures.

        Rules:
        1. If fix is followed by procedure, connect with dot: FIX.PROCEDURE
        2. Skip if already has dot notation
        3. Don't connect airports to procedures
        4. Don't connect airways to procedures

        Args:
            route_str: Raw route string

        Returns:
            Formatted route string with dot notation
        """
        if not route_str:
            return route_str

        tokens = route_str.split()
        if len(tokens) < 2:
            return route_str

        result = []
        i = 0

        while i < len(tokens):
            current = tokens[i]

            # Look ahead to see if next token is a procedure
            if i + 1 < len(tokens):
                next_token = tokens[i + 1]

                if self.is_procedure(next_token) and self._can_connect(current):
                    # Connect fix to procedure
                    result.append(f"{current}.{next_token}")
                    i += 2
                    continue

            result.append(current)
            i += 1

        return ' '.join(result)

    def _can_connect(self, token: str) -> bool:
        """
        Check if a token can be connected to a procedure (is a valid fix).

        Returns False for:
        - Airport codes (4 letters starting with K, C, M, P, T)
        - Airways (J, Q, V, T followed by digits)
        - Procedures (already a procedure name)
        """
        token = token.upper().strip()

        if not token:
            return False

        # Skip if already has dot notation
        if '.' in token:
            return False

        # Skip airport codes (4 letters starting with common prefixes)
        if len(token) == 4 and token[0] in 'KCMPT' and token.isalpha():
            return False

        # Skip airways (J, Q, V, T followed by digits)
        if len(token) >= 2 and token[0] in 'JQVT' and token[1:].isdigit():
            return False

        # Skip if this is already a procedure
        if self.is_procedure(token):
            return False

        return True

    def extract_procedures(self, route_str: str) -> list:
        """
        Extract all procedure names from a route string.

        Args:
            route_str: Route string to analyze

        Returns:
            List of procedure names found
        """
        procedures = []

        for token in route_str.split():
            token = token.upper().strip()

            # Handle FIX.PROCEDURE format
            if '.' in token:
                match = self.TRANSITION_PATTERN.match(token)
                if match:
                    procedures.append(match.group(2))
            elif self.is_procedure(token):
                procedures.append(token)

        return procedures


def format_all_routes(routes: list, detector: ProcedureDetector) -> list:
    """
    Format all routes in a list with proper procedure notation.

    Args:
        routes: List of route dicts with 'route' key
        detector: ProcedureDetector instance

    Returns:
        Updated route list with formatted route strings
    """
    for route in routes:
        if 'route' in route:
            route['route'] = detector.format_route(route['route'])
    return routes
