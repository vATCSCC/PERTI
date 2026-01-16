-- ============================================================================
-- VATSIM_ADL Schema Export Query
-- Run this against the VATSIM_ADL Azure SQL database to get current schema
-- Output can be used to update /mnt/project/VATSIM_ADL_tree.json
-- ============================================================================

-- Option 1: JSON format (for direct replacement of VATSIM_ADL_tree.json)
SELECT (
    SELECT 
        DB_NAME() AS DatabaseName,
        s.name AS SchemaName,
        t.name AS TableName,
        c.name AS ColumnName,
        ty.name AS DataType,
        CASE 
            WHEN ty.name IN ('nvarchar', 'varchar', 'char', 'nchar') 
            THEN CASE WHEN c.max_length = -1 THEN -1 ELSE c.max_length / (CASE WHEN ty.name LIKE 'n%' THEN 2 ELSE 1 END) END
            WHEN ty.name IN ('decimal', 'numeric') 
            THEN NULL  -- Precision/scale handled separately if needed
            ELSE NULL 
        END AS MaxLength,
        c.is_nullable AS IsNullable,
        c.column_id AS OrdinalPosition
    FROM sys.tables t
    INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
    INNER JOIN sys.columns c ON t.object_id = c.object_id
    INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
    WHERE s.name = 'dbo'
    ORDER BY t.name, c.column_id
    FOR JSON PATH
) AS JsonSchema;

-- Option 2: Tabular format (easier to review)
SELECT 
    t.name AS TableName,
    c.name AS ColumnName,
    ty.name AS DataType,
    CASE 
        WHEN ty.name IN ('nvarchar', 'varchar', 'char', 'nchar') 
        THEN CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length / (CASE WHEN ty.name LIKE 'n%' THEN 2 ELSE 1 END) AS VARCHAR) END
        WHEN ty.name IN ('decimal', 'numeric') 
        THEN CAST(c.precision AS VARCHAR) + ',' + CAST(c.scale AS VARCHAR)
        ELSE NULL 
    END AS Length_Precision,
    CASE WHEN c.is_nullable = 1 THEN 'YES' ELSE 'NO' END AS Nullable,
    CASE WHEN pk.column_id IS NOT NULL THEN 'PK' ELSE '' END AS PK,
    c.column_id AS Position
FROM sys.tables t
INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
INNER JOIN sys.columns c ON t.object_id = c.object_id
INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
LEFT JOIN (
    SELECT ic.object_id, ic.column_id
    FROM sys.index_columns ic
    INNER JOIN sys.indexes i ON ic.object_id = i.object_id AND ic.index_id = i.index_id
    WHERE i.is_primary_key = 1
) pk ON t.object_id = pk.object_id AND c.column_id = pk.column_id
WHERE s.name = 'dbo'
ORDER BY t.name, c.column_id;

-- Option 3: Summary by table (quick overview)
SELECT 
    t.name AS TableName,
    COUNT(c.column_id) AS ColumnCount,
    STRING_AGG(c.name, ', ') WITHIN GROUP (ORDER BY c.column_id) AS Columns
FROM sys.tables t
INNER JOIN sys.schemas s ON t.schema_id = s.schema_id
INNER JOIN sys.columns c ON t.object_id = c.object_id
WHERE s.name = 'dbo'
GROUP BY t.name
ORDER BY t.name;

-- Option 4: adl_flights columns only (for SWIM API verification)
SELECT 
    c.name AS ColumnName,
    ty.name AS DataType,
    CASE 
        WHEN ty.name IN ('nvarchar', 'varchar', 'char', 'nchar') 
        THEN CASE WHEN c.max_length = -1 THEN 'MAX' ELSE CAST(c.max_length / (CASE WHEN ty.name LIKE 'n%' THEN 2 ELSE 1 END) AS VARCHAR) END
        ELSE NULL 
    END AS MaxLength,
    c.column_id AS Position
FROM sys.tables t
INNER JOIN sys.columns c ON t.object_id = c.object_id
INNER JOIN sys.types ty ON c.user_type_id = ty.user_type_id
WHERE t.name = 'adl_flights'
ORDER BY c.column_id;
