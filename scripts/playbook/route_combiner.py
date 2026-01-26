"""
Two-table route combination logic.

Handles FAA Playbook plays that use two tables:
- Table 1: Origin routes (ORIGIN, FILTERS, ROUTE, REMARKS)
- Table 2: Destination routes (DESTINATION, ROUTE, REMARKS)

These need to be intelligently combined into complete routes.
"""

from typing import List, Optional


class TwoTableCombiner:
    """
    Combine origin and destination route segments intelligently.

    FAA two-table format example:
    - Table 1 (origins): "ZAU ARG NIIZZ CVE ABI"
    - Table 2 (destinations): "ABI CME.LZZRD4 KABQ"

    Challenge: The overlap point (ABI) may or may not be repeated.
    We need to detect overlap and avoid duplicating waypoints.
    """

    def combine(self, origin_route: str, dest_route: str) -> str:
        """
        Combine two route segments intelligently.

        Args:
            origin_route: Route string from origin table
            dest_route: Route string from destination table

        Returns:
            Combined route string with proper overlap handling

        Examples:
            combine("ROUTE1 ABI", "ABI ROUTE2") -> "ROUTE1 ABI ROUTE2"
            combine("ROUTE1 CME", "ABI CME ROUTE2") -> "ROUTE1 CME ABI CME ROUTE2" (no overlap)
            combine("ZAU ARG NIIZZ CVE ABI", "ABI CME.LZZRD4 KABQ")
                -> "ZAU ARG NIIZZ CVE ABI CME.LZZRD4 KABQ"
        """
        if not dest_route:
            return origin_route
        if not origin_route:
            return dest_route

        origin_tokens = origin_route.split()
        dest_tokens = dest_route.split()

        if not origin_tokens or not dest_tokens:
            return f"{origin_route} {dest_route}".strip()

        # Find overlap between end of origin and start of destination
        overlap_len = self._find_overlap(origin_tokens, dest_tokens)

        if overlap_len > 0:
            # Remove overlapping tokens from destination
            combined_tokens = origin_tokens + dest_tokens[overlap_len:]
        else:
            # No overlap - simple concatenation
            combined_tokens = origin_tokens + dest_tokens

        return ' '.join(combined_tokens)

    def _find_overlap(self, tokens1: List[str], tokens2: List[str]) -> int:
        """
        Find length of overlap where end of tokens1 matches start of tokens2.

        Args:
            tokens1: First token list (origin route)
            tokens2: Second token list (destination route)

        Returns:
            Number of tokens to skip in tokens2 (overlap length)
        """
        # Check up to 3 tokens of overlap (more than that is unlikely)
        max_overlap = min(len(tokens1), len(tokens2), 3)

        for overlap in range(max_overlap, 0, -1):
            # Compare end of tokens1 with start of tokens2 (case-insensitive)
            end_tokens = [t.upper() for t in tokens1[-overlap:]]
            start_tokens = [t.upper() for t in tokens2[:overlap]]

            if end_tokens == start_tokens:
                return overlap

        return 0

    def combine_all_combinations(
        self,
        origin_routes: List[dict],
        dest_routes: List[dict]
    ) -> List[dict]:
        """
        Generate valid combinations of origin and destination routes.

        For two-table format plays, only combines routes where:
        1. The end of the origin route matches the start of the dest route (overlap)
        2. Or the dest route starts with a waypoint that could connect to the origin

        Args:
            origin_routes: List of dicts with 'origin', 'route', 'filters', 'remarks'
            dest_routes: List of dicts with 'dest', 'route', 'remarks'

        Returns:
            List of combined route dicts with all fields populated
        """
        combined = []

        # Index dest routes by their starting waypoint for efficient matching
        dest_by_start = {}
        for dest_entry in dest_routes:
            dest_route_str = dest_entry.get('route', '').strip()
            if dest_route_str:
                # Get the first waypoint of the dest route
                first_token = dest_route_str.split()[0].upper()
                # Handle FIX.PROCEDURE format - extract the fix
                if '.' in first_token:
                    first_token = first_token.split('.')[0]
                if first_token not in dest_by_start:
                    dest_by_start[first_token] = []
                dest_by_start[first_token].append(dest_entry)

        for origin_entry in origin_routes:
            origin_codes = self._parse_multi_value(origin_entry.get('origin', ''))
            origin_route_str = origin_entry.get('route', '').strip()

            if not origin_route_str:
                continue

            origin_tokens = origin_route_str.split()
            if not origin_tokens:
                continue

            # Get the last waypoint of the origin route
            last_token = origin_tokens[-1].upper()
            # Handle FIX.PROCEDURE format - extract the fix
            if '.' in last_token:
                last_token = last_token.split('.')[0]

            # Find dest routes that start with this waypoint
            matching_dests = dest_by_start.get(last_token, [])

            for dest_entry in matching_dests:
                dest_codes = self._parse_multi_value(dest_entry.get('dest', ''))
                dest_route_str = dest_entry.get('route', '').strip()

                # Combine the route strings (overlap will be detected and handled)
                combined_route = self.combine(origin_route_str, dest_route_str)

                # Generate entry for each origin/dest combination
                for origin in (origin_codes if origin_codes else ['']):
                    for dest in (dest_codes if dest_codes else ['']):
                        combined.append({
                            'origin': origin,
                            'dest': dest,
                            'route': combined_route,
                            'filters': origin_entry.get('filters', ''),
                            'remarks': ' '.join(filter(None, [
                                origin_entry.get('remarks', ''),
                                dest_entry.get('remarks', '')
                            ])).strip()
                        })

        return combined

    def _parse_multi_value(self, value: str) -> List[str]:
        """
        Parse a multi-value field (space or comma separated).

        Examples:
            "ZAU ZBW ZDC" -> ["ZAU", "ZBW", "ZDC"]
            "KORD, KMDW" -> ["KORD", "KMDW"]
            "KORD" -> ["KORD"]
        """
        if not value:
            return []

        # Replace commas with spaces and split
        normalized = value.replace(',', ' ')
        codes = [c.strip() for c in normalized.split() if c.strip()]
        return codes


def deduplicate_routes(routes: List[dict]) -> List[dict]:
    """
    Remove duplicate route entries.

    Two routes are duplicates if they have the same:
    - route string
    - origin
    - destination
    """
    seen = set()
    unique = []

    for route in routes:
        # Create a key from the essential fields
        key = (
            route.get('route', '').upper(),
            route.get('origin', '').upper(),
            route.get('dest', '').upper()
        )

        if key not in seen:
            seen.add(key)
            unique.append(route)

    return unique
