"""
PDF-based parser for historical FAA National Severe Weather Playbook PDFs.

Supports all 14 known PDFs from 2001-2021. Extracts route tables and
produces ParsedTable/ParsedPlay objects compatible with the existing
playbook parser pipeline.

PDF structure (all eras):
  1. Cover page (p1): Title + effective dates
  2. Changelog section (p2-N): Modification summary tables
  3. Play pages (bulk): Route tables with "Impacted Flow/Resource" metadata
  4. Graphic pages: "GRAPHIC: NAME" (2004+) or blank pages (2001)
  5. Section dividers: Category headers

Table header formats:
  2004-2021: ORIGIN|FILTERS|ROUTE|DEST|REMARKS (single-table)
             ORIGIN|FILTERS|ROUTE|REMARKS + DESTINATION|ROUTE|REMARKS (two-table)
  2001 only: FACILITY|ROUTING|MIT|ALT|REMARKS (legacy)
             FACILITY|CORE ROUTING|MIT|ALT|REMARKS (transcon)
             DESTINATION|ROUTING FROM EXIT POINT|... (paired-column dest)
             OPTION NUMBER|ROUTE|REMARKS (non-standard)
"""

import re
import logging
from enum import Enum
from dataclasses import dataclass, field
from pathlib import Path
from typing import Dict, List, Optional, Tuple

try:
    import pdfplumber
except ImportError:
    pdfplumber = None

from .config import (
    ORIGIN_HEADERS, DEST_HEADERS, ROUTE_HEADERS,
    FILTER_HEADERS, REMARKS_HEADERS
)
from .html_extractor import ParsedTable, ParsedPlay

logger = logging.getLogger(__name__)


class PageType(Enum):
    """Classification of a PDF page."""
    COVER = 'cover'
    CHANGELOG = 'changelog'
    PLAY_START = 'play_start'
    PLAY_CONTINUATION = 'play_cont'
    GRAPHIC = 'graphic'
    DIVIDER = 'divider'


@dataclass
class PDFPlayGroup:
    """A play extracted from PDF pages (may span 1-4 pages)."""
    play_name: str
    effective_date: str
    tables: List[ParsedTable]
    metadata: dict = field(default_factory=dict)
    page_numbers: List[int] = field(default_factory=list)
    category: str = ''

    def to_parsed_play(self) -> ParsedPlay:
        """Convert to ParsedPlay for pipeline compatibility."""
        return ParsedPlay(
            play_name=self.play_name,
            tables=self.tables,
            metadata=self.metadata,
        )


class PDFPlaybookParser:
    """
    Parse FAA National Severe Weather Playbook PDFs.

    Supports all formats from 2001-2021:
    - 2004+ modern: ORIGIN/FILTERS/ROUTE/DEST/REMARKS
    - 2001 legacy: FACILITY/ROUTING/MIT/ALT/REMARKS
    - Two-table plays (origin + destination tables)
    - Paired-column destination tables (2001)
    - Multi-page play continuations
    """

    # Mapping from PDF section titles to normalized category names
    CATEGORY_MAP = {
        'AIRPORTS': 'Airports',
        'AIRPORT': 'Airports',
        'EAST-TO-WEST TRANSCON ROUTES': 'East to West Transcon',
        'EAST TO WEST TRANSCON ROUTES': 'East to West Transcon',
        'EAST-TO-WEST TRANSCON': 'East to West Transcon',
        'EAST TO WEST TRANSCON': 'East to West Transcon',
        'WEST-TO-EAST TRANSCON ROUTES': 'West to East Transcon',
        'WEST TO EAST TRANSCON ROUTES': 'West to East Transcon',
        'WEST-TO-EAST TRANSCON': 'West to East Transcon',
        'WEST TO EAST TRANSCON': 'West to East Transcon',
        'REGIONAL ROUTES': 'Regional Routes',
        'REGIONAL': 'Regional Routes',
        'AIRWAY CLOSURES': 'Airway Closures',
        'EQUIPMENT': 'Equipment',
        'SNOWBIRD': 'Snowbird',
        'SNOWBIRD ROUTES': 'Snowbird',
        'SPACE OPS': 'Space Ops',
        'SPACE OPERATIONS': 'Space Ops',
        'SPECIAL OPS': 'Special Ops',
        'SPECIAL OPERATIONS': 'Special Ops',
        'SUA ACTIVITY': 'SUA Activity',
        'SUA': 'SUA Activity',
    }

    # Headers that indicate a changelog table
    CHANGELOG_HEADERS = {'#', 'PLAY NAME', 'MODIFICATION DESCRIPTION'}

    # All recognized route-data headers (union of all variants)
    ALL_ROUTE_HEADERS = set(
        h.upper() for h in
        ORIGIN_HEADERS + DEST_HEADERS + ROUTE_HEADERS +
        FILTER_HEADERS + REMARKS_HEADERS +
        ['MIT', 'ALT', 'OPTION NUMBER']
    )

    def __init__(self):
        if pdfplumber is None:
            raise ImportError(
                "pdfplumber is required for PDF parsing. "
                "Install with: pip install pdfplumber"
            )

        self.stats = {
            'pages_total': 0,
            'pages_cover': 0,
            'pages_changelog': 0,
            'pages_play_start': 0,
            'pages_play_cont': 0,
            'pages_graphic': 0,
            'pages_divider': 0,
            'plays_extracted': 0,
            'tables_extracted': 0,
            'paired_tables_unpacked': 0,
            'facility_cells_normalized': 0,
        }

    def _normalize_category(self, text: str) -> str:
        """
        Normalize a section title to a standard category name.

        Returns empty string if the text doesn't match any known category.
        """
        clean = re.sub(r'\s+', ' ', text).strip()
        # Strip trailing page numbers
        clean = re.sub(r'\s+\d+\s*$', '', clean).strip()
        upper = clean.upper()

        if upper in self.CATEGORY_MAP:
            return self.CATEGORY_MAP[upper]

        return ''

    def _extract_bookmark_categories(self, pdf) -> Dict[str, str]:
        """
        Extract play_name -> category mapping from PDF bookmarks.

        Handles two bookmark structures:
          2004+: L1 root -> L2 categories -> L3 play names
          2001:  L1 categories -> L2 play names

        Dynamically detects the pattern by tracking the level at which
        categories appear.
        """
        mapping: Dict[str, str] = {}
        try:
            outlines = list(pdf.doc.get_outlines())
        except Exception:
            logger.debug("  No bookmarks found in PDF")
            return mapping

        current_category = ''
        category_level = 0

        # Skip patterns for non-play bookmark entries
        skip_prefixes = (
            'CHANGE', 'GRAPHIC', 'ROUTE', 'DESTINATION ROUTE',
            'PLAY BOOK', 'NATIONAL',
        )

        for level, title, dest, a, se in outlines:
            title_clean = (title or '').strip()
            if not title_clean:
                continue

            # Try to match as a category
            cat = self._normalize_category(title_clean)
            if cat:
                current_category = cat
                category_level = level
                continue

            # Skip non-play entries (changelog, graphics, route sub-items)
            title_upper = title_clean.upper()
            if any(title_upper.startswith(p) for p in skip_prefixes):
                continue

            # Play name: deeper than category level
            if current_category and level > category_level:
                # Normalize whitespace for consistent lookup
                key = re.sub(r'\s+', ' ', title_upper).strip()
                mapping[key] = current_category

        if mapping:
            logger.info(f"  Bookmarks: {len(mapping)} plays mapped to "
                        f"{len(set(mapping.values()))} categories")
        return mapping

    def parse_pdf(self, pdf_path: Path) -> List[PDFPlayGroup]:
        """
        Parse a FAA Playbook PDF and extract all plays.

        Args:
            pdf_path: Path to the PDF file

        Returns:
            List of PDFPlayGroup objects with normalized ParsedTable objects
        """
        pdf_path = Path(pdf_path)
        size_mb = pdf_path.stat().st_size / 1024 / 1024
        logger.info(f"Opening PDF: {pdf_path.name} ({size_mb:.1f} MB)")

        with pdfplumber.open(str(pdf_path)) as pdf:
            self.stats['pages_total'] = len(pdf.pages)
            logger.info(f"  {len(pdf.pages)} pages")

            # Extract effective date from cover page
            effective_date = self._extract_effective_date(pdf.pages[0])
            logger.info(f"  Effective date: {effective_date}")

            # Extract bookmark-based category mapping
            bookmark_categories = self._extract_bookmark_categories(pdf)

            # Classify all pages
            classified = []
            for i, page in enumerate(pdf.pages):
                text = page.extract_text() or ''
                raw_tables = page.extract_tables() or []
                page_type = self._classify_page(i, text, raw_tables)
                classified.append((i, page_type, text, raw_tables))
                self.stats[f'pages_{page_type.value}'] += 1

            logger.info(
                f"  Classification: "
                f"{self.stats['pages_play_start']} play starts, "
                f"{self.stats['pages_play_cont']} continuations, "
                f"{self.stats['pages_graphic']} graphics, "
                f"{self.stats['pages_changelog']} changelog, "
                f"{self.stats['pages_divider']} dividers"
            )

            # Group pages into plays
            play_groups = self._group_pages_into_plays(
                classified, effective_date, bookmark_categories
            )
            self.stats['plays_extracted'] = len(play_groups)

            logger.info(f"  Extracted {len(play_groups)} plays")

        return play_groups

    def _extract_effective_date(self, cover_page) -> str:
        """Extract effective date range from cover page."""
        text = cover_page.extract_text() or ''

        # "Effective <date> to <date>"
        match = re.search(
            r'Effective\s+(.+?)\s+to\s+(.+?)(?:\n|$)',
            text, re.IGNORECASE
        )
        if match:
            return f"{match.group(1).strip()} to {match.group(2).strip()}"

        # Just "Effective <date>"
        match = re.search(
            r'Effective\s+(.+?)(?:\n|$)',
            text, re.IGNORECASE
        )
        if match:
            return match.group(1).strip()

        return ''

    def _classify_page(
        self,
        page_index: int,
        text: str,
        raw_tables: List[List[List[str]]]
    ) -> PageType:
        """
        Classify a PDF page by its content.

        Priority:
        1. Cover page (page 0)
        2. Very short text with no tables = blank graphic
        3. Tables present: check for route data vs changelog
        4. "GRAPHIC:" as primary content (not footer metadata)
        5. Divider/other
        """
        text_clean = text.strip()
        text_upper = text_clean.upper()

        # Cover page
        if page_index == 0:
            return PageType.COVER

        # Very short text with no tables = blank graphic page (2001) or divider
        if len(text_clean) < 50 and not raw_tables:
            return PageType.GRAPHIC

        # Examine tables for changelog vs route data.
        # This MUST come before the GRAPHIC: check because 2001 play pages
        # have "graphic: NAME" footer text referencing the associated diagram.
        has_route_tables = False
        has_changelog_tables = False

        for table in raw_tables:
            if not table or not table[0]:
                continue
            headers_upper = set(
                (cell or '').strip().upper() for cell in table[0]
            )

            # Changelog detection
            if headers_upper & self.CHANGELOG_HEADERS:
                has_changelog_tables = True
            elif headers_upper & {'NEW PLAYS', 'ORIGINAL', 'REVISED'}:
                has_changelog_tables = True

            # Route data detection
            if self._is_route_data_headers(headers_upper):
                has_route_tables = True

        # Play pages with route data (takes priority over everything else)
        if has_route_tables:
            has_metadata = bool(
                re.search(r'Impacted\s+(Flow|Resource)', text, re.IGNORECASE)
            )
            if has_metadata:
                return PageType.PLAY_START
            else:
                return PageType.PLAY_CONTINUATION

        # Pure changelog pages
        if has_changelog_tables and not has_route_tables:
            return PageType.CHANGELOG

        # Graphic pages: "GRAPHIC:" near the start of text (primary content),
        # not embedded as footer metadata in play pages.
        if 'GRAPHIC:' in text_upper:
            graphic_pos = text_upper.find('GRAPHIC:')
            if graphic_pos < 100 or len(text_clean) < 200:
                return PageType.GRAPHIC

        # Has metadata but no parseable tables (freeform play page)
        if re.search(r'Impacted\s+(Flow|Resource)', text, re.IGNORECASE):
            logger.warning(
                f"  Page {page_index + 1}: has play metadata but no route "
                f"tables (freeform?) — skipping"
            )
            return PageType.DIVIDER

        # Short text without tables = section divider
        return PageType.DIVIDER

    def _is_route_data_headers(self, headers_upper: set) -> bool:
        """Check if a set of headers indicates a route data table."""
        route_set = {h.upper() for h in ROUTE_HEADERS}
        has_route = bool(headers_upper & route_set)

        origin_set = {h.upper() for h in ORIGIN_HEADERS}
        dest_set = {h.upper() for h in DEST_HEADERS}
        has_origin_or_dest = bool(headers_upper & (origin_set | dest_set))

        # Special case: OPTION NUMBER tables (no origin/dest but has ROUTE)
        if has_route and 'OPTION NUMBER' in headers_upper:
            return True

        return has_route and has_origin_or_dest

    def _extract_play_name(self, text: str) -> str:
        """
        Extract play name from page text.

        The play name is the prominent text BEFORE "Impacted Flow"
        or "Impacted Resource".
        """
        # Find text before "Impacted"
        match = re.search(r'Impacted\s+(Flow|Resource)', text, re.IGNORECASE)
        if match:
            before = text[:match.start()].strip()
        else:
            # No metadata — use first portion of text
            before = text[:500]

        lines = before.split('\n')

        for line in lines:
            line = line.strip()

            if not line:
                continue

            # Skip page numbers
            if line.isdigit():
                continue

            # Skip known section divider text and category names
            line_upper = line.upper()
            if line_upper in (
                'NATIONAL SEVERE WEATHER PLAYBOOK',
            ):
                continue
            if line_upper in self.CATEGORY_MAP:
                continue

            # Skip lines that are just column headers
            words = line.split()
            if words and all(
                w.upper() in self.ALL_ROUTE_HEADERS for w in words
            ):
                continue

            return line.strip()

        return ''

    def _group_pages_into_plays(
        self,
        classified: List[Tuple],
        effective_date: str,
        bookmark_categories: Optional[Dict[str, str]] = None,
    ) -> List[PDFPlayGroup]:
        """
        Group classified pages into play groups.

        Handles multi-page plays by merging continuation pages
        with the preceding play start.

        Category assignment priority:
        1. PDF bookmarks (most reliable)
        2. Section divider/graphic page text (tracks current_category)
        """
        bookmark_categories = bookmark_categories or {}
        groups: List[PDFPlayGroup] = []
        current_group: Optional[PDFPlayGroup] = None
        current_category = ''

        for page_index, page_type, text, raw_tables in classified:

            if page_type in (PageType.GRAPHIC, PageType.DIVIDER):
                # Check if this page's text matches a category name
                text_clean = re.sub(r'\s+', ' ', text).strip()
                # Skip known non-category pages
                text_upper = text_clean.upper()
                if any(skip in text_upper for skip in (
                    'NATIONAL SEVERE WEATHER PLAYBOOK',
                    'GRAPHIC:',
                )):
                    continue
                # Strip page numbers at start/end
                text_clean = re.sub(r'^\d+\s*', '', text_clean)
                text_clean = re.sub(r'\s*\d+$', '', text_clean).strip()
                if text_clean:
                    cat = self._normalize_category(text_clean)
                    if cat:
                        current_category = cat
                        logger.debug(
                            f"  Page {page_index + 1}: category divider "
                            f"-> {current_category}"
                        )

            elif page_type == PageType.PLAY_START:
                # Finalize previous group
                if current_group:
                    groups.append(current_group)

                play_name = self._extract_play_name(text)
                tables = self._convert_tables(raw_tables)

                if not play_name:
                    logger.warning(
                        f"  Page {page_index + 1}: could not extract play name"
                    )
                    play_name = f"UNKNOWN_PAGE_{page_index + 1}"

                metadata = self._extract_metadata(text)

                # Category: bookmarks first, then divider-tracked
                bm_key = re.sub(r'\s+', ' ', play_name.upper()).strip()
                category = bookmark_categories.get(
                    bm_key, current_category
                )

                current_group = PDFPlayGroup(
                    play_name=play_name,
                    effective_date=effective_date,
                    tables=tables,
                    metadata=metadata,
                    page_numbers=[page_index + 1],
                    category=category,
                )

            elif page_type == PageType.PLAY_CONTINUATION:
                if current_group is not None:
                    cont_tables = self._convert_tables(raw_tables)
                    self._merge_continuation_tables(current_group, cont_tables)
                    current_group.page_numbers.append(page_index + 1)
                else:
                    # Orphan continuation — treat as new play
                    logger.warning(
                        f"  Page {page_index + 1}: continuation without "
                        f"prior play start"
                    )
                    play_name = (
                        self._extract_play_name(text)
                        or f"UNKNOWN_PAGE_{page_index + 1}"
                    )
                    tables = self._convert_tables(raw_tables)
                    bm_key = re.sub(r'\s+', ' ', play_name.upper()).strip()
                    category = bookmark_categories.get(
                        bm_key, current_category
                    )
                    current_group = PDFPlayGroup(
                        play_name=play_name,
                        effective_date=effective_date,
                        tables=tables,
                        page_numbers=[page_index + 1],
                        category=category,
                    )

            # COVER, CHANGELOG — skip silently

        # Don't forget the last group
        if current_group:
            groups.append(current_group)

        return groups

    def _convert_tables(
        self, raw_tables: List[List[List[Optional[str]]]]
    ) -> List[ParsedTable]:
        """
        Convert pdfplumber raw tables into ParsedTable objects.

        Handles:
        - Header normalization
        - Paired-column destination table unpacking (2001)
        - FACILITY column value normalization (2001)
        - Filtering non-route tables (changelog, etc.)
        """
        parsed_tables = []

        for raw_table in raw_tables:
            if not raw_table or len(raw_table) < 2:
                continue

            # Clean None values
            clean_table = [
                [(cell or '').strip() for cell in row]
                for row in raw_table
            ]

            headers = clean_table[0]
            headers_upper = [h.upper() for h in headers]
            rows = clean_table[1:]

            # Filter empty rows
            rows = [r for r in rows if any(cell.strip() for cell in r)]
            if not rows:
                continue

            # Skip changelog tables
            if set(headers_upper) & self.CHANGELOG_HEADERS:
                continue
            if set(headers_upper) & {'NEW PLAYS', 'ORIGINAL', 'REVISED'}:
                continue

            # Handle paired-column destination tables (2001)
            if self._is_paired_destination_table(headers_upper):
                unpacked = self._unpack_paired_destination_table(
                    headers, headers_upper, rows
                )
                if unpacked:
                    parsed_tables.append(unpacked)
                    self.stats['paired_tables_unpacked'] += 1
                continue

            # Skip tables with no recognized headers
            if not self._has_recognized_headers(headers_upper):
                continue

            # Normalize FACILITY column values (2001 legacy)
            origin_idx = self._find_header_index(headers_upper, ORIGIN_HEADERS)
            if origin_idx >= 0 and headers_upper[origin_idx] == 'FACILITY':
                for row in rows:
                    if origin_idx < len(row):
                        row[origin_idx] = self._normalize_facility_origin(
                            row[origin_idx]
                        )
                        self.stats['facility_cells_normalized'] += 1

            # Build ParsedTable
            table = ParsedTable(headers=headers, rows=rows)

            # Classify table type
            if table.has_origin_column() and table.has_dest_column():
                table.table_type = 'combined'
            elif table.has_origin_column():
                table.table_type = 'origin'
            elif table.has_dest_column():
                table.table_type = 'destination'

            parsed_tables.append(table)
            self.stats['tables_extracted'] += 1

        return parsed_tables

    def _has_recognized_headers(self, headers_upper: List[str]) -> bool:
        """Check if any headers match recognized route-data headers."""
        return any(h in self.ALL_ROUTE_HEADERS for h in headers_upper)

    @staticmethod
    def _find_header_index(
        headers_upper: List[str], candidates: List[str]
    ) -> int:
        """Find the index of the first matching header."""
        for candidate in candidates:
            cu = candidate.upper()
            if cu in headers_upper:
                return headers_upper.index(cu)
        return -1

    def _is_paired_destination_table(self, headers_upper: List[str]) -> bool:
        """
        Detect paired-column destination tables (2001 format).

        These have duplicate DESTINATION headers with route columns:
        [DESTINATION, ROUTING..., DESTINATION, ROUTING...]
        """
        dest_set = {h.upper() for h in DEST_HEADERS}
        dest_count = sum(1 for h in headers_upper if h in dest_set)
        return dest_count >= 2

    def _unpack_paired_destination_table(
        self,
        headers: List[str],
        headers_upper: List[str],
        rows: List[List[str]]
    ) -> Optional[ParsedTable]:
        """
        Unpack a paired-column destination table into standard format.

        Input:  [DEST1, ROUTE1, DEST2, ROUTE2] per row (4+ columns)
        Output: Standard [DESTINATION, ROUTE] with doubled rows
        """
        dest_set = {h.upper() for h in DEST_HEADERS}
        route_set = {h.upper() for h in ROUTE_HEADERS}

        dest_indices = []
        route_indices = []

        for i, h in enumerate(headers_upper):
            if h in dest_set:
                dest_indices.append(i)
            elif h in route_set:
                route_indices.append(i)

        if len(dest_indices) < 2 or len(route_indices) < 2:
            logger.warning(
                f"  Paired table has {len(dest_indices)} dest cols, "
                f"{len(route_indices)} route cols — skipping"
            )
            return None

        # Unpack each (dest, route) column pair into separate rows
        unpacked_rows = []
        for row in rows:
            for di, ri in zip(dest_indices, route_indices):
                dest = row[di].strip() if di < len(row) else ''
                route = row[ri].strip() if ri < len(row) else ''
                if dest or route:
                    unpacked_rows.append([dest, route])

        if not unpacked_rows:
            return None

        return ParsedTable(
            headers=['DESTINATION', 'ROUTE'],
            rows=unpacked_rows,
            table_type='destination',
        )

    @staticmethod
    def _normalize_facility_origin(facility_text: str) -> str:
        """
        Normalize a 2001-era FACILITY column value to plain facility codes.

        Transformations:
          ZDC (METROS)           ->  ZDC
          ZJX/ZMA                ->  ZJX ZMA
          ZNY (-PHL)             ->  ZNY
          ZOA/ZDV/\\nZLC (SLC)   ->  ZOA ZDV ZLC
          "Available with..."    ->  ''  (freeform note, skipped)
        """
        if not facility_text:
            return ''

        # Normalize whitespace (handles newlines in cells)
        text = re.sub(r'\s+', ' ', facility_text).strip()

        # Quick check: first token must be a short alphanumeric code.
        # Freeform notes like "Available with special coordination..."
        # start with long words and should be skipped entirely.
        first_word = text.split('/')[0].split()[0] if text else ''
        if not first_word or len(first_word) > 4 or not first_word.isalnum():
            return ''

        # Strip parenthetical qualifiers: (METROS), (-PHL), (SLC & SO.)
        text = re.sub(r'\s*\([^)]*\)', '', text).strip()

        # Replace slashes with spaces (multi-ARTCC: ZJX/ZMA -> ZJX ZMA)
        text = text.replace('/', ' ')

        # Filter to valid short codes
        codes = []
        for code in text.split():
            code = code.strip().upper()
            if code and len(code) <= 4 and code.isalnum():
                codes.append(code)

        return ' '.join(codes)

    @staticmethod
    def _merge_continuation_tables(
        group: PDFPlayGroup,
        cont_tables: List[ParsedTable]
    ):
        """
        Merge continuation page tables into existing play group.

        If a continuation table has the same headers as an existing table,
        its rows are appended. Otherwise, it's added as a new table.
        """
        for cont_table in cont_tables:
            merged = False
            cont_headers = [h.upper() for h in cont_table.headers]

            for existing in group.tables:
                existing_headers = [h.upper() for h in existing.headers]

                if cont_headers == existing_headers:
                    # Same headers — append rows
                    existing.rows.extend(cont_table.rows)
                    merged = True
                    break

            if not merged:
                group.tables.append(cont_table)

    @staticmethod
    def _extract_metadata(text: str) -> dict:
        """Extract play metadata (Impacted Flow, Facilities, etc.)."""
        metadata = {}

        # Impacted Flow
        match = re.search(
            r'Impacted\s+Flow[:\s]*(.+?)'
            r'(?=\n\s*(?:Impacted|Facilities|Instructions|Remarks|$))',
            text, re.IGNORECASE | re.DOTALL
        )
        if match:
            metadata['impacted_flow'] = match.group(1).strip()

        # Impacted Resource
        match = re.search(
            r'Impacted\s+Resource[:\s]*(.+?)'
            r'(?=\n\s*(?:Impacted|Facilities|Instructions|Remarks|$))',
            text, re.IGNORECASE | re.DOTALL
        )
        if match:
            metadata['impacted_resource'] = match.group(1).strip()

        # Facilities
        match = re.search(
            r'Facilities[:\s]*(.+?)'
            r'(?=\n\s*(?:Instructions|Remarks|ORIGIN|FACILITY|DEST|$))',
            text, re.IGNORECASE | re.DOTALL
        )
        if match:
            metadata['facilities'] = match.group(1).strip()

        return metadata

    def get_stats(self) -> dict:
        """Get parsing statistics."""
        return self.stats.copy()
