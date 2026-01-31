"""
TMI Compliance Analyzer - Database Connections
===============================================

Database connection handlers for Azure SQL (ADL) and PostGIS (GIS).
Uses environment variables for credentials.

Note: Uses pymssql for SQL Server (no ODBC driver required on Linux).
pyodbc is tried first as fallback for Windows.
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
        """Establish database connection using pymssql (or pyodbc fallback)"""
        host = os.environ.get('ADL_SQL_HOST', 'vatsim.database.windows.net')
        database = os.environ.get('ADL_SQL_DATABASE', 'VATSIM_ADL')
        username = os.environ.get('ADL_SQL_USERNAME', 'adl_api_user')
        password = os.environ.get('ADL_SQL_PASSWORD')

        if not password:
            raise ValueError("ADL_SQL_PASSWORD environment variable is required")

        logger.info(f"Connecting to ADL database at {host}")

        # Try pymssql first (works on Linux without ODBC driver)
        try:
            import pymssql
            self.conn = pymssql.connect(
                server=host,
                user=username,
                password=password,
                database=database,
                login_timeout=30,
                as_dict=False
            )
            logger.info("Connected to VATSIM_ADL via pymssql")
            return self.conn
        except ImportError:
            logger.info("pymssql not available, trying pyodbc")
        except Exception as e:
            logger.warning(f"pymssql connection failed: {e}, trying pyodbc")

        # Fallback to pyodbc (works on Windows)
        import pyodbc
        conn_str = (
            f"DRIVER={{ODBC Driver 18 for SQL Server}};"
            f"SERVER={host};"
            f"DATABASE={database};"
            f"UID={username};"
            f"PWD={password};"
            f"Encrypt=yes;TrustServerCertificate=yes;Connection Timeout=30"
        )
        self.conn = pyodbc.connect(conn_str)
        logger.info("Connected to VATSIM_ADL via pyodbc")
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
        password = os.environ.get('GIS_SQL_PASSWORD')

        if not password:
            raise ValueError("GIS_SQL_PASSWORD environment variable is required")

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
