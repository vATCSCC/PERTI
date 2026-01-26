"""
Robust HTML table extraction for FAA Playbook pages.

Uses structure-based extraction (not style-dependent) to handle:
- Play name extraction from various header formats
- Single-table format (ORIGIN, FILTERS, ROUTE, DEST, REMARKS)
- Two-table format (origin table + destination table)
- Nested tables and layout tables (ignored)
"""

import re
from dataclasses import dataclass, field
from typing import List, Dict, Optional, Tuple
from html.parser import HTMLParser

from .config import (
    ORIGIN_HEADERS, DEST_HEADERS, ROUTE_HEADERS,
    FILTER_HEADERS, REMARKS_HEADERS
)


@dataclass
class ParsedTable:
    """Represents a parsed HTML table with headers and rows."""
    headers: List[str]
    rows: List[List[str]]
    table_type: str = 'unknown'  # 'origin', 'destination', or 'combined'

    @property
    def column_count(self) -> int:
        return len(self.headers)

    @property
    def row_count(self) -> int:
        return len(self.rows)

    def get_column_index(self, *possible_names: str) -> int:
        """Find column index by checking multiple possible header names."""
        headers_upper = [h.upper().strip() for h in self.headers]
        for name in possible_names:
            name_upper = name.upper().strip()
            if name_upper in headers_upper:
                return headers_upper.index(name_upper)
        return -1

    def has_origin_column(self) -> bool:
        return self.get_column_index(*ORIGIN_HEADERS) >= 0

    def has_dest_column(self) -> bool:
        return self.get_column_index(*DEST_HEADERS) >= 0

    def has_route_column(self) -> bool:
        return self.get_column_index(*ROUTE_HEADERS) >= 0


@dataclass
class ParsedPlay:
    """Represents a fully parsed play page."""
    play_name: str
    tables: List[ParsedTable]
    metadata: Dict[str, str] = field(default_factory=dict)

    @property
    def has_two_table_format(self) -> bool:
        """Check if this play uses the two-table format."""
        if len(self.tables) < 2:
            return False
        # Two-table format: one table has ORIGIN, other has DESTINATION
        has_origin_table = any(t.has_origin_column() and not t.has_dest_column() for t in self.tables)
        has_dest_table = any(t.has_dest_column() and not t.has_origin_column() for t in self.tables)
        return has_origin_table and has_dest_table

    def get_origin_table(self) -> Optional[ParsedTable]:
        """Get the origin routes table."""
        for table in self.tables:
            if table.has_origin_column():
                return table
        return None

    def get_dest_table(self) -> Optional[ParsedTable]:
        """Get the destination routes table (for two-table format)."""
        for table in self.tables:
            if table.has_dest_column() and not table.has_origin_column():
                return table
        return None


class HTMLTableExtractor(HTMLParser):
    """
    Extract tables from FAA Playbook HTML using structure-based detection.

    Does NOT rely on:
    - Font colors (no more #005a86)
    - Font sizes (no more size="6")
    - Specific CSS classes or IDs

    Instead uses:
    - Table structure (thead/tbody or first row as header)
    - Column header text to identify data tables
    - Text prominence (largest text block before tables = play name)
    """

    def __init__(self):
        super().__init__()
        self.reset_state()

    def reset_state(self):
        """Reset all state for parsing a new document."""
        self.tables: List[ParsedTable] = []
        self.play_name = ""
        self.metadata: Dict[str, str] = {}

        # Text extraction state
        self._all_text_blocks: List[Tuple[str, int]] = []  # (text, font_size_hint)
        self._current_text: List[str] = []
        self._current_font_size = 3  # Default font size

        # Table parsing state
        self._in_table = False
        self._table_depth = 0
        self._current_table: List[List[str]] = []
        self._current_row: List[str] = []
        self._current_cell: List[str] = []
        self._in_row = False
        self._in_cell = False
        self._in_header_cell = False

        # Track if we're in nested table (to ignore)
        self._nested_table_depth = 0

    def feed(self, data: str):
        """Parse HTML and extract tables."""
        self.reset_state()
        # Clean up the HTML a bit
        data = data.replace('\r\n', '\n').replace('\r', '\n')
        super().feed(data)
        self._finalize()

    def handle_starttag(self, tag: str, attrs: List[Tuple[str, str]]):
        attrs_dict = dict(attrs)
        tag_lower = tag.lower()

        # Track font size for play name detection
        if tag_lower == 'font':
            size = attrs_dict.get('size', '')
            if size.isdigit():
                self._current_font_size = int(size)

        # Track headings for play name
        if tag_lower in ('h1', 'h2', 'h3'):
            self._current_font_size = 7 - int(tag_lower[1])  # h1=6, h2=5, h3=4

        # Table handling
        if tag_lower == 'table':
            self._table_depth += 1
            if self._table_depth == 1:
                # Top-level table - start collecting
                self._in_table = True
                self._current_table = []
            else:
                # Nested table - track but don't collect separately
                self._nested_table_depth += 1

        if tag_lower == 'tr' and self._in_table and self._nested_table_depth == 0:
            self._in_row = True
            self._current_row = []

        if tag_lower in ('td', 'th') and self._in_row:
            self._in_cell = True
            self._in_header_cell = (tag_lower == 'th')
            self._current_cell = []

    def handle_endtag(self, tag: str):
        tag_lower = tag.lower()

        # Reset font size after closing font/heading
        if tag_lower in ('font', 'h1', 'h2', 'h3'):
            # Save current text block with font size
            text = ''.join(self._current_text).strip()
            if text:
                self._all_text_blocks.append((text, self._current_font_size))
            self._current_text = []
            self._current_font_size = 3

        # End cell
        if tag_lower in ('td', 'th') and self._in_cell:
            self._in_cell = False
            cell_text = self._clean_cell_text(''.join(self._current_cell))
            self._current_row.append(cell_text)
            self._current_cell = []
            self._in_header_cell = False

        # End row
        if tag_lower == 'tr' and self._in_row:
            self._in_row = False
            if self._current_row:
                self._current_table.append(self._current_row)
            self._current_row = []

        # End table
        if tag_lower == 'table':
            if self._table_depth == 1 and self._current_table:
                # Finalize top-level table
                parsed = self._parse_table_structure(self._current_table)
                if parsed and self._is_data_table(parsed):
                    self.tables.append(parsed)
                self._current_table = []

            if self._nested_table_depth > 0:
                self._nested_table_depth -= 1

            self._table_depth -= 1
            if self._table_depth == 0:
                self._in_table = False

    def handle_data(self, data: str):
        # Collect text for play name detection
        if not self._in_table:
            self._current_text.append(data)

        # Collect cell content
        if self._in_cell:
            self._current_cell.append(data)

    def handle_entityref(self, name: str):
        char = ''
        if name == 'nbsp':
            char = ' '
        elif name == 'amp':
            char = '&'
        elif name == 'lt':
            char = '<'
        elif name == 'gt':
            char = '>'
        elif name == 'quot':
            char = '"'

        if char:
            if self._in_cell:
                self._current_cell.append(char)
            else:
                self._current_text.append(char)

    def handle_charref(self, name: str):
        try:
            if name.startswith('x'):
                char = chr(int(name[1:], 16))
            else:
                char = chr(int(name))
            if self._in_cell:
                self._current_cell.append(char)
            else:
                self._current_text.append(char)
        except (ValueError, OverflowError):
            pass

    def _clean_cell_text(self, text: str) -> str:
        """Clean and normalize cell text."""
        # Replace multiple whitespace with single space
        text = re.sub(r'\s+', ' ', text)
        return text.strip()

    def _parse_table_structure(self, rows: List[List[str]]) -> Optional[ParsedTable]:
        """Convert raw rows into ParsedTable with headers."""
        if not rows or len(rows) < 2:
            return None

        # First row is header
        headers = rows[0]
        data_rows = rows[1:]

        # Filter out empty rows
        data_rows = [row for row in data_rows if any(cell.strip() for cell in row)]

        if not data_rows:
            return None

        return ParsedTable(headers=headers, rows=data_rows)

    def _is_data_table(self, table: ParsedTable) -> bool:
        """Check if table contains route data (not navigation/layout)."""
        # Must have at least one recognized column
        if not table.has_route_column():
            return False

        # Must have either origin or destination column
        if not (table.has_origin_column() or table.has_dest_column()):
            return False

        return True

    def _finalize(self):
        """Finalize parsing and extract play name."""
        # Find play name from largest text before tables
        self.play_name = self._extract_play_name()

        # Classify tables
        for table in self.tables:
            if table.has_origin_column() and table.has_dest_column():
                table.table_type = 'combined'
            elif table.has_origin_column():
                table.table_type = 'origin'
            elif table.has_dest_column():
                table.table_type = 'destination'

    def _extract_play_name(self) -> str:
        """
        Extract play name using multiple strategies.

        Priority:
        1. Largest font size text that looks like a play name
        2. Text in h1/h2/h3 tags
        3. First prominent text block
        """
        # Sort by font size (descending)
        sorted_blocks = sorted(self._all_text_blocks, key=lambda x: -x[1])

        for text, size in sorted_blocks:
            if self._looks_like_play_name(text):
                return text.strip()

        return ""

    def _looks_like_play_name(self, text: str) -> bool:
        """Check if text looks like a valid play name."""
        if not text or len(text) < 2 or len(text) > 100:
            return False

        text_lower = text.lower()

        # Skip navigation/header text
        skip_patterns = [
            'playbook', 'menu', 'logout', 'login', 'faa', 'navigation',
            'home', 'search', 'help', 'contact', 'about', 'copyright',
            'active plays', 'inactive plays', 'all plays'
        ]
        if any(p in text_lower for p in skip_patterns):
            return False

        # Play names typically contain airport codes, directions, or route names
        # Examples: "ABI", "CAPE LAUNCH 1", "CARIBBEAN ARVLS VIA FUNDI", "YYZ NO LINNG"
        # They're usually uppercase or title case
        if text.isupper() or text.istitle():
            return True

        # Check for common play name patterns
        play_patterns = [
            r'^[A-Z]{2,4}$',  # Airport codes: ABI, DFW, ORD
            r'^[A-Z]{2,4}\s+(NO|VIA|TO|FROM|NORTH|SOUTH|EAST|WEST)',  # ABI NO CHPPR
            r'ARRIVAL|DEPARTURE|FLOW|ROUTE|LAUNCH',  # Common keywords
        ]
        for pattern in play_patterns:
            if re.search(pattern, text, re.IGNORECASE):
                return True

        return False

    def get_parsed_play(self) -> ParsedPlay:
        """Get the fully parsed play data."""
        return ParsedPlay(
            play_name=self.play_name,
            tables=self.tables,
            metadata=self.metadata
        )


def extract_routes_from_table(table: ParsedTable) -> List[Dict[str, str]]:
    """
    Extract route entries from a parsed table.

    Returns list of dicts with keys: origin, filters, route, dest, remarks
    """
    routes = []

    # Find column indices
    origin_idx = table.get_column_index(*ORIGIN_HEADERS)
    dest_idx = table.get_column_index(*DEST_HEADERS)
    route_idx = table.get_column_index(*ROUTE_HEADERS)
    filter_idx = table.get_column_index(*FILTER_HEADERS)
    remarks_idx = table.get_column_index(*REMARKS_HEADERS)

    if route_idx < 0:
        return routes  # No route column found

    for row in table.rows:
        # Safely get cell values
        def get_cell(idx: int) -> str:
            if idx >= 0 and idx < len(row):
                return row[idx].strip()
            return ''

        route_entry = {
            'origin': get_cell(origin_idx),
            'dest': get_cell(dest_idx),
            'route': get_cell(route_idx),
            'filters': get_cell(filter_idx),
            'remarks': get_cell(remarks_idx),
        }

        # Only include if we have a route string
        if route_entry['route']:
            routes.append(route_entry)

    return routes
