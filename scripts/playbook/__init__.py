"""
FAA Playbook Routes Parser

A robust, modular parser for scraping and updating FAA Playbook route data.
"""

from .config import *
from .html_extractor import HTMLTableExtractor, ParsedTable
from .route_combiner import TwoTableCombiner
from .procedure_detector import ProcedureDetector
from .parser import PlaybookParser, calculate_airac_cycle
from .pdf_parser import PDFPlaybookParser, PDFPlayGroup

__version__ = '2.1.0'
__all__ = [
    'PlaybookParser',
    'HTMLTableExtractor',
    'ParsedTable',
    'TwoTableCombiner',
    'ProcedureDetector',
    'PDFPlaybookParser',
    'PDFPlayGroup',
    'calculate_airac_cycle',
]
