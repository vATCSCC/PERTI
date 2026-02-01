/**
 * generate_acd_sql.js
 *
 * Converts FAA Aircraft Characteristics Database Excel file to SQL INSERT statements.
 *
 * Source: https://www.faa.gov/airports/engineering/aircraft_char_database/aircraft_data
 *
 * Usage: node generate_acd_sql.js
 * Output: sql_output/06_acd_data.sql
 */

const XLSX = require('xlsx');
const fs = require('fs');
const path = require('path');

// File paths
const inputFile = path.join(__dirname, 'faa_acd.xlsx');
const outputDir = path.join(__dirname, 'sql_output');
const outputFile = path.join(outputDir, '06_acd_data.sql');

// Ensure output directory exists
if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

// Read Excel file
console.log(`Reading ${inputFile}...`);
const workbook = XLSX.readFile(inputFile);
const sheet = workbook.Sheets['ACD_Data'];
const data = XLSX.utils.sheet_to_json(sheet);

console.log(`Found ${data.length} aircraft records`);

// Columns that should always be treated as strings (even if Excel stores as number)
const stringColumns = new Set([
    'ICAO_Code', 'FAA_Designator', 'Manufacturer', 'Model_FAA', 'Model_BADA',
    'Physical_Class_Engine', 'AAC', 'AAC_minimum', 'AAC_maximum', 'ADG', 'TDG',
    'MALW_lb', 'Main_Gear_Config', 'ICAO_WTC', 'Class', 'FAA_Weight', 'CWT',
    'One_Half_Wake_Category', 'Two_Wake_Category_Appx_A', 'Two_Wake_Category_Appx_B',
    'SRS', 'LAHSO', 'FAA_Registry', 'Remarks',
]);

// Numeric columns that should convert 'N/A' to NULL
const numericColumns = new Set([
    'Num_Engines', 'Approach_Speed_knot', 'Approach_Speed_minimum_knot', 'Approach_Speed_maximum_knot',
    'Wingspan_ft_without_winglets_sharklets', 'Wingspan_ft_with_winglets_sharklets',
    'Length_ft', 'Tail_Height_at_OEW_ft', 'Wheelbase_ft', 'Cockpit_to_Main_Gear_ft',
    'Main_Gear_Width_ft', 'MTOW_lb', 'Parking_Area_ft2', 'Rotor_Diameter_ft',
    'Registration_Count', 'TMFS_Operations_FY24',
]);

// SQL escape helper
function sqlEscape(val, columnName) {
    if (val === null || val === undefined || val === '') {
        return 'NULL';
    }

    // Handle 'N/A' in numeric columns - convert to NULL
    if (numericColumns.has(columnName)) {
        if (typeof val === 'string' && (val.toUpperCase() === 'N/A' || val.toUpperCase() === 'NOWGT')) {
            return 'NULL';
        }
        if (typeof val === 'number') {
            return isNaN(val) ? 'NULL' : val.toString();
        }
        // Try to parse as number
        const num = parseFloat(val);
        return isNaN(num) ? 'NULL' : num.toString();
    }

    // Force string columns to be quoted even if numeric
    if (stringColumns.has(columnName)) {
        const strVal = String(val).replace(/'/g, "''").trim();
        return strVal === '' ? 'NULL' : `'${strVal}'`;
    }

    if (typeof val === 'number') {
        return isNaN(val) ? 'NULL' : val.toString();
    }
    if (typeof val === 'string') {
        // Escape single quotes
        return `'${val.replace(/'/g, "''").trim()}'`;
    }
    return 'NULL';
}

// Convert Excel column names to SQL column names
const columnMap = {
    'ICAO_Code': 'ICAO_Code',
    'FAA_Designator': 'FAA_Designator',
    'Manufacturer': 'Manufacturer',
    'Model_FAA': 'Model_FAA',
    'Model_BADA': 'Model_BADA',
    'Physical_Class_Engine': 'Physical_Class_Engine',
    'Num_Engines': 'Num_Engines',
    'AAC': 'AAC',
    'AAC_minimum': 'AAC_minimum',
    'AAC_maximum': 'AAC_maximum',
    'ADG': 'ADG',
    'TDG': 'TDG',
    'Approach_Speed_knot': 'Approach_Speed_knot',
    'Approach_Speed_minimum_knot': 'Approach_Speed_minimum_knot',
    'Approach_Speed_maximum_knot': 'Approach_Speed_maximum_knot',
    'Wingspan_ft_without_winglets_sharklets': 'Wingspan_ft_without_winglets',
    'Wingspan_ft_with_winglets_sharklets': 'Wingspan_ft_with_winglets',
    'Length_ft': 'Length_ft',
    'Tail_Height_at_OEW_ft': 'Tail_Height_at_OEW_ft',
    'Wheelbase_ft': 'Wheelbase_ft',
    'Cockpit_to_Main_Gear_ft': 'Cockpit_to_Main_Gear_ft',
    'Main_Gear_Width_ft': 'Main_Gear_Width_ft',
    'MTOW_lb': 'MTOW_lb',
    'MALW_lb': 'MALW_lb',
    'Main_Gear_Config': 'Main_Gear_Config',
    'ICAO_WTC': 'ICAO_WTC',
    'Parking_Area_ft2': 'Parking_Area_ft2',
    'Class': 'Class',
    'FAA_Weight': 'FAA_Weight',
    'CWT': 'CWT',
    'One_Half_Wake_Category': 'One_Half_Wake_Category',
    'Two_Wake_Category_Appx_A': 'Two_Wake_Category_Appx_A',
    'Two_Wake_Category_Appx_B': 'Two_Wake_Category_Appx_B',
    'Rotor_Diameter_ft': 'Rotor_Diameter_ft',
    'SRS': 'SRS',
    'LAHSO': 'LAHSO',
    'FAA_Registry': 'FAA_Registry',
    'Registration_Count': 'Registration_Count',
    'TMFS_Operations_FY24': 'TFMS_Operations_FY24',
    'Remarks': 'Remarks',
    'LastUpdate': 'LastUpdate',
};

// Generate SQL
let sql = `-- ============================================================================
-- ACD_Data Seed Data
--
-- FAA Aircraft Characteristics Database
-- Source: https://www.faa.gov/airports/engineering/aircraft_char_database
-- Generated: ${new Date().toISOString()}
-- Records: ${data.length}
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT 'Loading ACD_Data seed data...';
GO

-- Clear existing data
DELETE FROM dbo.ACD_Data;
GO

-- Insert new data
`;

// Build INSERT statements in batches
const batchSize = 50;
const sqlColumns = Object.values(columnMap);

for (let i = 0; i < data.length; i += batchSize) {
    const batch = data.slice(i, i + batchSize);

    sql += `-- Batch ${Math.floor(i / batchSize) + 1}\n`;
    sql += `INSERT INTO dbo.ACD_Data (\n    ${sqlColumns.join(',\n    ')}\n)\nVALUES\n`;

    const values = batch.map((row, idx) => {
        const rowValues = Object.keys(columnMap).map(excelCol => {
            const val = row[excelCol];

            // Handle Excel date serial numbers for LastUpdate
            if (excelCol === 'LastUpdate' && typeof val === 'number') {
                // Excel date serial to JavaScript date
                const date = new Date((val - 25569) * 86400 * 1000);
                return `'${date.toISOString().split('T')[0]}'`;
            }

            return sqlEscape(val, excelCol);
        });

        return `    (${rowValues.join(', ')})`;
    });

    sql += values.join(',\n') + ';\nGO\n\n';
}

// Add summary
sql += `
PRINT '';
PRINT 'ACD_Data seed complete.';

DECLARE @cnt INT;
SELECT @cnt = COUNT(*) FROM dbo.ACD_Data;
PRINT 'Total records: ' + CAST(@cnt AS VARCHAR);
GO

-- Verification queries
SELECT
    FAA_Weight,
    COUNT(*) AS Count
FROM dbo.ACD_Data
GROUP BY FAA_Weight
ORDER BY Count DESC;

SELECT
    Physical_Class_Engine,
    COUNT(*) AS Count
FROM dbo.ACD_Data
GROUP BY Physical_Class_Engine
ORDER BY Count DESC;

SELECT
    Class,
    COUNT(*) AS Count
FROM dbo.ACD_Data
GROUP BY Class
ORDER BY Count DESC;
GO
`;

// Write output
fs.writeFileSync(outputFile, sql, 'utf8');
console.log(`Generated ${outputFile}`);
console.log(`Total records: ${data.length}`);

// Print summary
const summary = {
    total: data.length,
    byWeight: {},
    byEngine: {},
    byClass: {},
};

data.forEach(row => {
    const weight = row.FAA_Weight || 'Unknown';
    const engine = row.Physical_Class_Engine || 'Unknown';
    const cls = row.Class || 'Unknown';

    summary.byWeight[weight] = (summary.byWeight[weight] || 0) + 1;
    summary.byEngine[engine] = (summary.byEngine[engine] || 0) + 1;
    summary.byClass[cls] = (summary.byClass[cls] || 0) + 1;
});

console.log('\n=== Summary ===');
console.log('\nBy FAA Weight Class:');
Object.entries(summary.byWeight)
    .sort((a, b) => b[1] - a[1])
    .forEach(([k, v]) => console.log(`  ${k}: ${v}`));

console.log('\nBy Engine Type:');
Object.entries(summary.byEngine)
    .sort((a, b) => b[1] - a[1])
    .forEach(([k, v]) => console.log(`  ${k}: ${v}`));

console.log('\nBy Aircraft Class:');
Object.entries(summary.byClass)
    .sort((a, b) => b[1] - a[1])
    .forEach(([k, v]) => console.log(`  ${k}: ${v}`));
