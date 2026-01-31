"""
TMI Compliance Analyzer - Database Connections
===============================================

Database connection handlers for Azure SQL (ADL) and PostGIS (GIS).
Uses environment variables for credentials.

Note: pyodbc and psycopg2 are imported lazily inside connect() methods
to avoid import errors when these packages aren't installed.
"""

import os
import logging

logger = logging.getLogger(__name__)


class ADLConnection:
    """Azure SQL connection for VATSIM_ADL (flight data)"""

    def __init__(self):
        self.conn = None

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.close()

    def connect(self):
        """Establish database connection"""
        import pyodbc  # Lazy import

        host = os.environ.get('ADL_SQL_HOST', 'vatsim.database.windows.net')
        database = os.environ.get('ADL_SQL_DATABASE', 'VATSIM_ADL')
        username = os.environ.get('ADL_SQL_USERNAME', 'adl_api_user')
        password = os.environ.get('ADL_SQL_PASSWORD', '')

        conn_str = (
            f"DRIVER={{ODBC Driver 18 for SQL Server}};"
            f"SERVER={host};"
            f"DATABASE={database};"
            f"UID={username};"
            f"PWD={password};"
            f"Encrypt=yes;TrustServerCertificate=yes;Connection Timeout=30"
        )

        logger.info(f"Connecting to ADL database at {host}")
        self.conn = pyodbc.connect(conn_str)
        logger.info("Connected to VATSIM_ADL")
        return self.conn

    def cursor(self):
        """Get database cursor"""
        return self.conn.cursor()

    def close(self):
        """Close database connection"""
        if self.conn:
            self.conn.close()
            logger.info("ADL connection closed")


class GISConnection:
    """PostgreSQL/PostGIS connection for spatial queries"""

    def __init__(self):
        self.conn = None

    def __enter__(self):
        self.connect()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.close()

    def connect(self):
        """Establish database connection"""
        import psycopg2  # Lazy import

        host = os.environ.get('GIS_SQL_HOST', 'vatcscc-gis.postgres.database.azure.com')
        port = int(os.environ.get('GIS_SQL_PORT', '5432'))
        database = os.environ.get('GIS_SQL_DATABASE', 'VATSIM_GIS')
        username = os.environ.get('GIS_SQL_USERNAME', 'GIS_admin')
        password = os.environ.get('GIS_SQL_PASSWORD', '')

        logger.info(f"Connecting to GIS database at {host}")
        self.conn = psycopg2.connect(
            host=host,
            port=port,
            database=database,
            user=username,
            password=password,
            sslmode='require'
        )
        logger.info("Connected to VATSIM_GIS (PostGIS)")
        return self.conn

    def cursor(self):
        """Get database cursor"""
        return self.conn.cursor()

    def close(self):
        """Close database connection"""
        if self.conn:
            self.conn.close()
            logger.info("GIS connection closed")
