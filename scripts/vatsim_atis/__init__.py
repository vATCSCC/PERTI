"""
VATSIM ATIS Processing Module

Fetches ATIS information from VATSIM and parses runway assignments.
Based on parsing logic from https://github.com/leftos/vatsim_control_recs
"""

from .atis_parser import (
    parse_runway_assignments,
    parse_approach_info,
    filter_atis_text,
    format_runway_summary,
)
from .vatsim_fetcher import (
    fetch_vatsim_data,
    extract_atis_controllers,
    get_atis_for_airports,
)

__all__ = [
    'parse_runway_assignments',
    'parse_approach_info',
    'filter_atis_text',
    'format_runway_summary',
    'fetch_vatsim_data',
    'extract_atis_controllers',
    'get_atis_for_airports',
]
