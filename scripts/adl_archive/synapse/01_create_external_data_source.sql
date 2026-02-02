/*
    ADL Raw Data Lake - Synapse Serverless Setup

    Step 1: Create database, credentials, and external data source.

    Run this in Synapse Serverless SQL pool (Built-in).
    Execute each section separately (highlight and run).

    Prerequisites:
    - Storage account 'pertiadlarchive' created via setup_infrastructure.ps1
    - Storage account key or SAS token available

    Author: Claude (AI-assisted implementation)
    Date: 2026-02-02
*/

-- ============================================================================
-- STEP 1: Create database (run against 'master' database)
-- ============================================================================
-- Make sure "Connect to:" shows "Built-in" and "Use database:" shows "master"

CREATE DATABASE ADL_Archive;
GO

-- ============================================================================
-- STEP 2: Switch to ADL_Archive database
-- ============================================================================
-- Change "Use database:" dropdown to "ADL_Archive" before running the rest

USE ADL_Archive;
GO

-- ============================================================================
-- STEP 3: Create master key (required for credentials)
-- ============================================================================

CREATE MASTER KEY ENCRYPTION BY PASSWORD = 'ADL@rch!ve2026Secure';
GO

-- ============================================================================
-- STEP 4: Create credential with SAS token
-- ============================================================================

CREATE DATABASE SCOPED CREDENTIAL ADL_Archive_StorageCred
WITH IDENTITY = 'SHARED ACCESS SIGNATURE',
-- SAS token generated 2026-02-02, expires 2027-02-02
SECRET = 'se=2027-02-02&sp=rl&sv=2022-11-02&ss=b&srt=co&sig=gJfj7rIdDu2ljT68719VBMsEcra1GQlPCMxwIuPzi10%3D';
GO

-- ============================================================================
-- STEP 5: Create external data source
-- ============================================================================

CREATE EXTERNAL DATA SOURCE ADL_RawArchive
WITH (
    LOCATION = 'https://pertiadlarchive.blob.core.windows.net/adl-raw-archive',
    CREDENTIAL = ADL_Archive_StorageCred
);
GO

-- ============================================================================
-- STEP 6: Create external file format for Parquet
-- ============================================================================

CREATE EXTERNAL FILE FORMAT ParquetFormat
WITH (
    FORMAT_TYPE = PARQUET,
    DATA_COMPRESSION = 'org.apache.hadoop.io.compress.SnappyCodec'
);
GO

-- ============================================================================
-- STEP 7: Verify setup
-- ============================================================================

SELECT 'Data Source' as Type, name, location
FROM sys.external_data_sources
WHERE name = 'ADL_RawArchive';

SELECT 'File Format' as Type, name, format_type
FROM sys.external_file_formats
WHERE name = 'ParquetFormat';

SELECT 'Credential' as Type, name, credential_identity
FROM sys.database_scoped_credentials
WHERE name = 'ADL_Archive_StorageCred';
GO

PRINT 'External data source setup complete.';
PRINT 'Next: Run 02_create_external_tables.sql';
GO
