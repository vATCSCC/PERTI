-- ============================================================================
-- VATUSA Event Statistics - Airport Summaries (Part 2)
-- Generated: 2026-01-07 05:44:36
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT 'Importing 1200 airport summaries...';
GO

INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003062359T202003070400/FNO/FNO1', N'SEA', 1,
    159, 76, 235,
    40, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003062359T202003070400/FNO/FNO1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003072359T202003080400/SAT/FOR1', N'RSW', 1,
    95, 45, 140,
    32, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003072359T202003080400/SAT/FOR1' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003132300T202003140300/FNO/FRI1', N'MCI', 1,
    186, 98, 284,
    52, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003132300T202003140300/FNO/FRI1' AND airport_icao = N'MCI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003142359T202003150300/SAT/MEM1', N'MEM', 1,
    82, 40, 122,
    78, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003142359T202003150300/SAT/MEM1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003202300T202003210300/FNO/A B1', N'PIT', 1,
    175, 91, 266,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003202300T202003210300/FNO/A B1' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003212300T202003220300/SAT/SOU4', N'DFW', 1,
    105, 110, 215,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003212300T202003220300/SAT/SOU4' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003212300T202003220300/SAT/SOU4', N'DAL', 1,
    31, 33, 64,
    33, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003212300T202003220300/SAT/SOU4' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003212300T202003220300/SAT/SOU4', N'IAH', 1,
    109, 84, 193,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003212300T202003220300/SAT/SOU4' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003212300T202003220300/SAT/SOU4', N'HOU', 1,
    63, 43, 106,
    32, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003212300T202003220300/SAT/SOU4' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003272300T202003280300/FNO/LET3', N'FAT', 1,
    97, 14, 111,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003272300T202003280300/FNO/LET3' AND airport_icao = N'FAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003272300T202003280300/FNO/LET3', N'RNO', 1,
    107, 28, 135,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003272300T202003280300/FNO/LET3' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003272300T202003280300/FNO/LET3', N'SJC', 1,
    33, 22, 55,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003272300T202003280300/FNO/LET3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202003282330T202003290330/SAT/ZDC1', N'DCA', 1,
    182, 73, 255,
    28, N'2200Z',
    6, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202003282330T202003290330/SAT/ZDC1' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004032359T202004040400/FNO/RIS1', N'DEN', 1,
    258, 122, 380,
    90, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004032359T202004040400/FNO/RIS1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004041100T202004050200/CTP/CRO6', N'BOS', 1,
    248, 112, 360,
    54, N'1600Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004041100T202004050200/CTP/CRO6' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004041100T202004050200/CTP/CRO6', N'JFK', 1,
    335, 88, 423,
    48, N'1600Z',
    5, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004041100T202004050200/CTP/CRO6' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004041100T202004050200/CTP/CRO6', N'MIA', 1,
    216, 97, 313,
    64, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004041100T202004050200/CTP/CRO6' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004041100T202004050200/CTP/CRO6', N'ORD', 1,
    189, 51, 240,
    92, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004041100T202004050200/CTP/CRO6' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004041100T202004050200/CTP/CRO6', N'SFO', 1,
    211, 83, 294,
    40, N'1600Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004041100T202004050200/CTP/CRO6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004102300T202004110300/FNO/SPR1', N'PHL', 1,
    282, 94, 376,
    51, N'2200Z',
    5, 4, 3
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004102300T202004110300/FNO/SPR1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004112200T202004120300/SAT/CAT2', N'CLE', 1,
    38, 62, 100,
    43, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004112200T202004120300/SAT/CAT2' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004112200T202004120300/SAT/CAT2', N'MCO', 1,
    163, 81, 244,
    76, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004112200T202004120300/SAT/CAT2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004140100T202004140400/MWK/BLO1', N'LAS', 1,
    124, 70, 194,
    60, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004140100T202004140400/MWK/BLO1' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004172300T202004180400/FNO/HON3', N'BDL', 1,
    80, 37, 117,
    25, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004172300T202004180400/FNO/HON3' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004172300T202004180400/FNO/HON3', N'BOS', 1,
    264, 159, 423,
    40, N'2200Z',
    4, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004172300T202004180400/FNO/HON3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004172300T202004180400/FNO/HON3', N'YYZ', 1,
    105, 116, 221,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004172300T202004180400/FNO/HON3' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004182300T202004190300/SAT/SHA1', N'ATL', 1,
    366, 206, 572,
    90, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004182300T202004190300/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004242300T202004250300/FNO/FLY3', N'FLL', 1,
    90, 65, 155,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004242300T202004250300/FNO/FLY3' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004242300T202004250300/FNO/FLY3', N'MIA', 1,
    222, 101, 323,
    72, N'0100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004242300T202004250300/FNO/FLY3' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202004251600T202004251900/SAT/GOO1', N'PDX', 1,
    70, 64, 134,
    50, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202004251600T202004251900/SAT/GOO1' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005022300T202005030400/SAT/THE2', N'ATL', 1,
    113, 146, 259,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005022300T202005030400/SAT/THE2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005022300T202005030400/SAT/THE2', N'IAH', 1,
    115, 51, 166,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005022300T202005030400/SAT/THE2' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005032100T202005040100/SUN/STA1', N'SEA', 1,
    90, 69, 159,
    40, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005032100T202005040100/SUN/STA1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005092300T202005100300/SAT/I-72', N'MCI', 1,
    70, 48, 118,
    52, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005092300T202005100300/SAT/I-72' AND airport_icao = N'MCI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005092300T202005100300/SAT/I-72', N'STL', 1,
    55, 60, 115,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005092300T202005100300/SAT/I-72' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'ATL', 1,
    114, 120, 234,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'AUS', 1,
    19, 25, 44,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'CLT', 1,
    15, 20, 35,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'DAL', 1,
    5, 11, 16,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'DFW', 1,
    45, 44, 89,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'FLL', 1,
    35, 36, 71,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'IAH', 1,
    32, 40, 72,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'JAX', 1,
    16, 37, 53,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'MCO', 1,
    31, 51, 82,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'MEM', 1,
    22, 39, 61,
    78, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'MIA', 1,
    93, 80, 173,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'MSY', 1,
    9, 7, 16,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'PHX', 1,
    29, 33, 62,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005152300T202005160400/FNO/SOU14', N'STL', 1,
    6, 9, 15,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005152300T202005160400/FNO/SOU14' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005162300T202005170300/SAT/LIG4', N'LGA', 1,
    83, 34, 117,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005162300T202005170300/SAT/LIG4' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005162300T202005170300/SAT/LIG4', N'TEB', 1,
    10, 8, 18,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005162300T202005170300/SAT/LIG4' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005162300T202005170300/SAT/LIG4', N'HPN', 1,
    4, 5, 9,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005162300T202005170300/SAT/LIG4' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005162300T202005170300/SAT/LIG4', N'EWR', 1,
    42, 35, 77,
    51, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005162300T202005170300/SAT/LIG4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005222300T202005230300/FNO/NOR3', N'DTW', 1,
    58, 79, 137,
    54, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005222300T202005230300/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005222300T202005230300/FNO/NOR3', N'MSP', 1,
    84, 113, 197,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005222300T202005230300/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005222300T202005230300/FNO/NOR3', N'ORD', 1,
    132, 81, 213,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005222300T202005230300/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'SFO', 1,
    134, 145, 279,
    48, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'OAK', 1,
    24, 19, 43,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'SJC', 1,
    20, 25, 45,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'LAX', 1,
    152, 143, 295,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'SAN', 1,
    43, 71, 114,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005232300T202005240300/RLOP/CAL6', N'LAS', 1,
    66, 61, 127,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005302300T202005310400/SAT/DAL2', N'DFW', 1,
    108, 78, 186,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005302300T202005310400/SAT/DAL2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005302300T202005310400/SAT/DAL2', N'DAL', 1,
    20, 13, 33,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005302300T202005310400/SAT/DAL2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202005312300T202006010300/SUN/GOO1', N'ATL', 1,
    172, 127, 299,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202005312300T202006010300/SUN/GOO1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006052300T202006060300/FNO/HCF3', N'HNL', 1,
    113, 91, 204,
    56, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006052300T202006060300/FNO/HCF3' AND airport_icao = N'HNL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006052300T202006060300/FNO/HCF3', N'OGG', 1,
    72, 53, 125,
    32, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006052300T202006060300/FNO/HCF3' AND airport_icao = N'OGG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006072300T202006080300/SUN/THR1', N'MCO', 1,
    120, 50, 170,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006072300T202006080300/SUN/THR1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006122359T202006130400/FNO/DEN3', N'DEN', 1,
    132, 60, 192,
    90, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006122359T202006130400/FNO/DEN3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006122359T202006130400/FNO/DEN3', N'SEA', 1,
    67, 26, 93,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006122359T202006130400/FNO/DEN3' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006122359T202006130400/FNO/DEN3', N'SLC', 1,
    102, 50, 152,
    62, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006122359T202006130400/FNO/DEN3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006192330T202006200400/FNO/FRE2', N'IND', 1,
    95, 53, 148,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006192330T202006200400/FNO/FRE2' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006192330T202006200400/FNO/FRE2', N'MEM', 1,
    126, 114, 240,
    78, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006192330T202006200400/FNO/FRE2' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006270000T202006270400/FNO/THE1', N'PHX', 1,
    133, 132, 265,
    66, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006270000T202006270400/FNO/THE1' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202006272300T202006280400/SAT/GET1', N'EWR', 1,
    124, 101, 225,
    30, N'2200Z',
    4, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202006272300T202006280400/SAT/GET1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007032300T202007040400/FNO/ZDC3', N'DCA', 1,
    127, 61, 188,
    34, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007032300T202007040400/FNO/ZDC3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007052300T202007060300/SUN/SOU2', N'ATL', 1,
    94, 182, 276,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007052300T202007060300/SUN/SOU2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007052300T202007060300/SUN/SOU2', N'MCO', 1,
    135, 57, 192,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007052300T202007060300/SUN/SOU2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007102300T202007110300/FNO/FOR2', N'DFW', 1,
    219, 107, 326,
    85, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007102300T202007110300/FNO/FOR2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007102300T202007110300/FNO/FOR2', N'DAL', 1,
    35, 18, 53,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007102300T202007110300/FNO/FOR2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007111830T202007112230/SAT/MIA2', N'MIA', 1,
    136, 61, 197,
    64, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007111830T202007112230/SAT/MIA2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007122359T202007130400/SUN/WIS1', N'DEN', 1,
    121, 96, 217,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007122359T202007130400/SUN/WIS1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007172300T202007180300/FNO/THE1', N'ATL', 1,
    280, 134, 414,
    92, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007172300T202007180300/FNO/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007182300T202007190300/SAT/IN 1', N'PBI', 1,
    77, 25, 102,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007182300T202007190300/SAT/IN 1' AND airport_icao = N'PBI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007242330T202007250400/FNO/NIG1', N'JFK', 1,
    165, 124, 289,
    60, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007242330T202007250400/FNO/NIG1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'MCO', 1,
    73, 64, 137,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'JAX', 1,
    34, 39, 73,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'PNS', 1,
    7, 27, 34,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'PNS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'MIA', 1,
    109, 81, 190,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'EYW', 1,
    35, 30, 65,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'EYW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007312300T202008010300/FNO/FLO6', N'RSW', 1,
    29, 16, 45,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007312300T202008010300/FNO/FLO6' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008012300T202008020300/SAT/SEA1', N'SEA', 1,
    109, 94, 203,
    40, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008012300T202008020300/SAT/SEA1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202007182300T202007190300/SAT/IN 1', N'BOS', 1,
    179, 140, 319,
    40, N'1900Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202007182300T202007190300/SAT/IN 1' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008142300T202008150300/FNO/SUM1', N'CVG', 1,
    157, 84, 241,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008142300T202008150300/FNO/SUM1' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008152300T202008160300/SAT/SWE2', N'MEM', 1,
    99, 86, 185,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008152300T202008160300/SAT/SWE2' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008152300T202008160300/SAT/SWE2', N'ATL', 1,
    116, 134, 250,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008152300T202008160300/SAT/SWE2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008212300T202008220300/FNO/NEW4', N'MSY', 1,
    33, 57, 90,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008212300T202008220300/FNO/NEW4' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008212300T202008220300/FNO/NEW4', N'AUS', 1,
    20, 20, 40,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008212300T202008220300/FNO/NEW4' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008212300T202008220300/FNO/NEW4', N'IAH', 1,
    79, 62, 141,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008212300T202008220300/FNO/NEW4' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008212300T202008220300/FNO/NEW4', N'HOU', 1,
    34, 12, 46,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008212300T202008220300/FNO/NEW4' AND airport_icao = N'HOU');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202008282359T202008290500/FNO/NOR1', N'MSP', 1,
    159, 78, 237,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202008282359T202008290500/FNO/NOR1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202009042300T202009050300/FNO/THE3', N'CLT', 1,
    105, 87, 192,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202009042300T202009050300/FNO/THE3' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202009042300T202009050300/FNO/THE3', N'MCO', 1,
    80, 101, 181,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202009042300T202009050300/FNO/THE3' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202009042300T202009050300/FNO/THE3', N'BWI', 1,
    74, 71, 145,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202009042300T202009050300/FNO/THE3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202009182300T202009190300/FNO/1001', N'STL', 1,
    120, 82, 202,
    64, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202009182300T202009190300/FNO/1001' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202009262300T202009270300/SAT/RET1', N'LGA', 1,
    76, 83, 159,
    32, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202009262300T202009270300/SAT/RET1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010022300T202010030300/FNO/ZAB5', N'DFW', 1,
    103, 79, 182,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010022300T202010030300/FNO/ZAB5' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010022300T202010030300/FNO/ZAB5', N'MEM', 1,
    25, 60, 85,
    78, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010022300T202010030300/FNO/ZAB5' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010022300T202010030300/FNO/ZAB5', N'PHX', 1,
    69, 100, 169,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010022300T202010030300/FNO/ZAB5' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010032300T202010040300/SAT/LIB2', N'JFK', 1,
    96, 50, 146,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010032300T202010040300/SAT/LIB2' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010032300T202010040300/SAT/LIB2', N'LGA', 1,
    40, 25, 65,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010032300T202010040300/SAT/LIB2' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010092300T202010100300/FNO/PAL2', N'FLL', 1,
    65, 39, 104,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010092300T202010100300/FNO/PAL2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010092300T202010100300/FNO/PAL2', N'MIA', 1,
    152, 94, 246,
    66, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010092300T202010100300/FNO/PAL2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010112000T202010120000/SUN/SPO1', N'SEA', 1,
    52, 71, 123,
    37, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010112000T202010120000/SUN/SPO1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010162359T202010170400/FNO/EMM3', N'ASE', 1,
    26, 32, 58,
    14, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010162359T202010170400/FNO/EMM3' AND airport_icao = N'ASE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010162359T202010170400/FNO/EMM3', N'COS', 1,
    13, 21, 34,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010162359T202010170400/FNO/EMM3' AND airport_icao = N'COS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010162359T202010170400/FNO/EMM3', N'DEN', 1,
    158, 109, 267,
    110, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010162359T202010170400/FNO/EMM3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010172300T202010180300/SAT/BRA1', N'BDL', 1,
    90, 62, 152,
    25, N'2200Z',
    3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010172300T202010180300/SAT/BRA1' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010232300T202010240300/FNO/IT''2', N'MDW', 1,
    42, 34, 76,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010232300T202010240300/FNO/IT''2' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010232300T202010240300/FNO/IT''2', N'ORD', 1,
    187, 166, 353,
    76, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010232300T202010240300/FNO/IT''2' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010302300T202010310300/FNO/A N2', N'JAX', 1,
    38, 38, 76,
    26, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010302300T202010310300/FNO/A N2' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202010302300T202010310300/FNO/A N2', N'MCO', 1,
    149, 76, 225,
    86, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202010302300T202010310300/FNO/A N2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011062359T202011070400/FNO/COR3', N'DTW', 1,
    75, 83, 158,
    86, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011062359T202011070400/FNO/COR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011062359T202011070400/FNO/COR3', N'ORD', 1,
    159, 104, 263,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011062359T202011070400/FNO/COR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011062359T202011070400/FNO/COR3', N'CVG', 1,
    59, 63, 122,
    72, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011062359T202011070400/FNO/COR3' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011140100T202011140500/FNO/ZLA4', N'LAS', 1,
    72, 109, 181,
    45, N'0000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011140100T202011140500/FNO/ZLA4' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011140100T202011140500/FNO/ZLA4', N'LAX', 1,
    143, 117, 260,
    68, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011140100T202011140500/FNO/ZLA4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011140100T202011140500/FNO/ZLA4', N'SFO', 1,
    109, 104, 213,
    25, N'0000Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011140100T202011140500/FNO/ZLA4' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011140100T202011140500/FNO/ZLA4', N'SJC', 1,
    28, 13, 41,
    40, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011140100T202011140500/FNO/ZLA4' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'ATL', 1,
    14, 129, 143,
    84, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'BOS', 1,
    18, 157, 175,
    40, N'1000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'JFK', 1,
    24, 161, 185,
    36, N'1000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'MCO', 1,
    3, 89, 92,
    76, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'ORD', 1,
    6, 138, 144,
    92, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011141100T202011142300/CTP/CRO8', N'SJU', 1,
    3, 68, 71,
    40, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011141100T202011142300/CTP/CRO8' AND airport_icao = N'SJU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011202359T202011210400/FNO/NEV1', N'IAD', 1,
    206, 125, 331,
    60, N'2300Z',
    3, 2, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011202359T202011210400/FNO/NEV1' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202011282359T202011290400/SAT/ZHU1', N'AUS', 1,
    73, 56, 129,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202011282359T202011290400/SAT/ZHU1' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012042359T202012050400/FNO/ZSE2', N'SEA', 1,
    172, 85, 257,
    40, N'2300Z',
    4, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012042359T202012050400/FNO/ZSE2' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012052359T202012060400/SAT/MIN1', N'ATL', 1,
    162, 92, 254,
    90, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012052359T202012060400/SAT/MIN1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012112359T202012120400/FNO/WIN4', N'BDL', 1,
    31, 17, 48,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012112359T202012120400/FNO/WIN4' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012112359T202012120400/FNO/WIN4', N'BOS', 1,
    226, 129, 355,
    40, N'2200Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012112359T202012120400/FNO/WIN4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012112359T202012120400/FNO/WIN4', N'EWR', 1,
    44, 36, 80,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012112359T202012120400/FNO/WIN4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012112359T202012120400/FNO/WIN4', N'PHL', 1,
    47, 44, 91,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012112359T202012120400/FNO/WIN4' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012182300T202012190300/FNO/END3', N'HNL', 1,
    118, 96, 214,
    50, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012182300T202012190300/FNO/END3' AND airport_icao = N'HNL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012182300T202012190300/FNO/END3', N'OGG', 1,
    48, 42, 90,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012182300T202012190300/FNO/END3' AND airport_icao = N'OGG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012182300T202012190300/FNO/END3', N'ITO', 1,
    22, 45, 67,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012182300T202012190300/FNO/END3' AND airport_icao = N'ITO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202012192359T202012200400/SAT/ZDC1', N'DCA', 1,
    126, 57, 183,
    30, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202012192359T202012200400/SAT/ZDC1' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101020200T202101020600/FNO/NEW4', N'SEA', 1,
    262, 181, 443,
    40, N'2300Z',
    8, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101020200T202101020600/FNO/NEW4' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101020200T202101020600/FNO/NEW4', N'OAK', 1,
    49, 45, 94,
    45, N'0200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101020200T202101020600/FNO/NEW4' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101020200T202101020600/FNO/NEW4', N'PDX', 1,
    62, 66, 128,
    40, N'0200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101020200T202101020600/FNO/NEW4' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101020200T202101020600/FNO/NEW4', N'RNO', 1,
    72, 64, 136,
    40, N'0200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101020200T202101020600/FNO/NEW4' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101082359T202101090400/FNO/TEX4', N'DAL', 1,
    42, 35, 77,
    36, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101082359T202101090400/FNO/TEX4' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101082359T202101090400/FNO/TEX4', N'DFW', 1,
    157, 131, 288,
    85, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101082359T202101090400/FNO/TEX4' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101082359T202101090400/FNO/TEX4', N'HOU', 1,
    59, 57, 116,
    28, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101082359T202101090400/FNO/TEX4' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101082359T202101090400/FNO/TEX4', N'IAH', 1,
    119, 82, 201,
    70, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101082359T202101090400/FNO/TEX4' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101102359T202101110400/SUN/KSE2', N'DEN', 1,
    97, 144, 241,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101102359T202101110400/SUN/KSE2' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101152359T/FNO/HON1', N'ATL', 1,
    340, 151, 491,
    92, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101152359T/FNO/HON1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101162200T202101170400/RLOP/ZLA1', N'LAX', 1,
    235, 200, 435,
    68, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101162200T202101170400/RLOP/ZLA1' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101172100T202101180100/SUN/ZDC1', N'DCA', 1,
    134, 62, 196,
    27, N'2000Z',
    4, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101172100T202101180100/SUN/ZDC1' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101222359T202101230500/FNO/ZMP1', N'MSP', 1,
    325, 163, 488,
    72, N'2200Z',
    5, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101222359T202101230500/FNO/ZMP1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101292300T202101300300/FNO/ESC2', N'ABQ', 1,
    85, 76, 161,
    30, N'0100Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101292300T202101300300/FNO/ESC2' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202101292300T202101300300/FNO/ESC2', N'PHX', 1,
    246, 143, 389,
    55, N'0300Z',
    5, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202101292300T202101300300/FNO/ESC2' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'BDL', 1,
    55, 25, 80,
    22, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'BOS', 1,
    195, 151, 346,
    32, N'2300Z',
    4, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'BWI', 1,
    59, 52, 111,
    28, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'HPN', 1,
    24, 30, 54,
    32, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'IAD', 1,
    97, 69, 166,
    50, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'LGA', 1,
    110, 67, 177,
    36, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'PVD', 1,
    29, 33, 62,
    29, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102052359T202102060500/FNO/NOR8', N'RDU', 1,
    53, 72, 125,
    45, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102052359T202102060500/FNO/NOR8' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102122359T202102130400/FNO/ZFW2', N'DAL', 1,
    94, 39, 133,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102122359T202102130400/FNO/ZFW2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102122359T202102130400/FNO/ZFW2', N'DFW', 1,
    199, 81, 280,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102122359T202102130400/FNO/ZFW2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102192359T202102200400/FNO/DEN2', N'COS', 1,
    27, 26, 53,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102192359T202102200400/FNO/DEN2' AND airport_icao = N'COS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102192359T202102200400/FNO/DEN2', N'DEN', 1,
    323, 214, 537,
    110, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102192359T202102200400/FNO/DEN2' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102202359T202102210400/SAT/ZLA1', N'LAS', 1,
    247, 119, 366,
    60, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102202359T202102210400/SAT/ZLA1' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102270100T202102270500/FNO/STO3', N'SFO', 1,
    200, 115, 315,
    40, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102270100T202102270500/FNO/STO3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102270100T202102270500/FNO/STO3', N'SJC', 1,
    189, 44, 233,
    40, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102270100T202102270500/FNO/STO3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202102270100T202102270500/FNO/STO3', N'SMF', 1,
    111, 28, 139,
    50, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202102270100T202102270500/FNO/STO3' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103052345T202103060400/FNO/THE2', N'MCI', 1,
    163, 79, 242,
    52, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103052345T202103060400/FNO/THE2' AND airport_icao = N'MCI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103052345T202103060400/FNO/THE2', N'STL', 1,
    109, 73, 182,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103052345T202103060400/FNO/THE2' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103122359T202103130400/FNO/PCF1', N'ANC', 1,
    142, 93, 235,
    50, N'2300Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103122359T202103130400/FNO/PCF1' AND airport_icao = N'ANC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103201000T202103202300/SAT/CRO6', N'ANC', 1,
    182, 103, 285,
    50, N'1600Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103201000T202103202300/SAT/CRO6' AND airport_icao = N'ANC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103201000T202103202300/SAT/CRO6', N'SEA', 1,
    89, 70, 159,
    40, N'1600Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103201000T202103202300/SAT/CRO6' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103201000T202103202300/SAT/CRO6', N'SFO', 1,
    162, 72, 234,
    48, N'1600Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103201000T202103202300/SAT/CRO6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103282100T202103290100/SUN/ZDC3', N'BWI', 1,
    33, 29, 62,
    32, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103282100T202103290100/SUN/ZDC3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103282100T202103290100/SUN/ZDC3', N'DCA', 1,
    116, 78, 194,
    34, N'2000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103282100T202103290100/SUN/ZDC3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202103282100T202103290100/SUN/ZDC3', N'IAD', 1,
    46, 23, 69,
    50, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202103282100T202103290100/SUN/ZDC3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'LAS', 1,
    111, 94, 205,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'LAX', 1,
    152, 149, 301,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'OAK', 1,
    31, 34, 65,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'PDX', 1,
    44, 57, 101,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'SEA', 1,
    70, 74, 144,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'SFO', 1,
    147, 121, 268,
    48, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104092300T202104100400/FNO/SPR7', N'SLC', 1,
    41, 46, 87,
    62, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104092300T202104100400/FNO/SPR7' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'BOS', 1,
    61, 159, 220,
    40, N'1700Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'IAD', 1,
    12, 22, 34,
    72, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'JFK', 1,
    89, 69, 158,
    36, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'ORF', 1,
    6, 27, 33,
    32, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'ORF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'PHL', 1,
    12, 18, 30,
    32, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104101800T202104102230/SAT/CIR6', N'TXKF', 1,
    87, 18, 105,
    20, N'1700Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104101800T202104102230/SAT/CIR6' AND airport_icao = N'TXKF');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104232300T202104240300/FNO/NOR3', N'DTW', 1,
    53, 84, 137,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104232300T202104240300/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104232300T202104240300/FNO/NOR3', N'ORD', 1,
    126, 91, 217,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104232300T202104240300/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104232300T202104240300/FNO/NOR3', N'MSP', 1,
    97, 112, 209,
    60, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104232300T202104240300/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'DEN', 1,
    96, 59, 155,
    96, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'MIA', 1,
    121, 60, 181,
    64, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'ORD', 1,
    139, 50, 189,
    96, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'IAD', 1,
    131, 30, 161,
    72, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'BOS', 1,
    157, 84, 241,
    40, N'1700Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'SFO', 1,
    115, 65, 180,
    36, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202104241100T202104242300/CTP/CRO9', N'JFK', 1,
    155, 66, 221,
    44, N'1700Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202104241100T202104242300/CTP/CRO9' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105012300T202105020300/SAT/ZMP1', N'OMA', 1,
    82, 27, 109,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105012300T202105020300/SAT/ZMP1' AND airport_icao = N'OMA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105082300T202105090300/SAT/SHA1', N'ATL', 1,
    172, 121, 293,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105082300T202105090300/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'SFO', 1,
    106, 113, 219,
    36, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'OAK', 1,
    33, 40, 73,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'SJC', 1,
    20, 15, 35,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'LAX', 1,
    101, 114, 215,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'SAN', 1,
    50, 69, 119,
    24, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105152300T202105160300/RLOP/CAL6', N'LAS', 1,
    51, 64, 115,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105212300T202105220300/FNO/ZMA8', N'JAX', 1,
    18, 25, 43,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105212300T202105220300/FNO/ZMA8', N'MCO', 1,
    60, 57, 117,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105212300T202105220300/FNO/ZMA8', N'FLL', 1,
    45, 40, 85,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105212300T202105220300/FNO/ZMA8', N'MIA', 1,
    142, 124, 266,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105212300T202105220300/FNO/ZMA8', N'TPA', 1,
    47, 22, 69,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105292300T202105300300/SAT/HON7', N'DCA', 1,
    54, 60, 114,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105292300T202105300300/SAT/HON7' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105292300T202105300300/SAT/HON7', N'PHL', 1,
    60, 42, 102,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105292300T202105300300/SAT/HON7' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105292300T202105300300/SAT/HON7', N'BOS', 1,
    136, 120, 256,
    36, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105292300T202105300300/SAT/HON7' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202105292300T202105300300/SAT/HON7', N'DTW', 1,
    20, 58, 78,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202105292300T202105300300/SAT/HON7' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106052200T202106060200/SAT/MAN2', N'HPN', 1,
    13, 15, 28,
    32, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106052200T202106060200/SAT/MAN2' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106052200T202106060200/SAT/MAN2', N'LGA', 1,
    121, 94, 215,
    36, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106052200T202106060200/SAT/MAN2' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106112300T202106120300/FNO/TRI3', N'ATL', 1,
    143, 128, 271,
    76, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106112300T202106120300/FNO/TRI3' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106112300T202106120300/FNO/TRI3', N'ORD', 1,
    77, 111, 188,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106112300T202106120300/FNO/TRI3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106112300T202106120300/FNO/TRI3', N'DFW', 1,
    63, 96, 159,
    85, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106112300T202106120300/FNO/TRI3' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106252359T202106260400/FNO/FAJ5', N'PHX', 1,
    59, 87, 146,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106252359T202106260400/FNO/FAJ5', N'OAK', 1,
    24, 27, 51,
    54, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106252359T202106260400/FNO/FAJ5', N'SFO', 1,
    88, 105, 193,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106252359T202106260400/FNO/FAJ5', N'LAS', 1,
    78, 82, 160,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202106252359T202106260400/FNO/FAJ5', N'LAX', 1,
    147, 115, 262,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107032359T202107040400/SAT/4TH3', N'BWI', 1,
    25, 8, 33,
    32, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107032359T202107040400/SAT/4TH3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107032359T202107040400/SAT/4TH3', N'DCA', 1,
    100, 67, 167,
    34, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107032359T202107040400/SAT/4TH3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107032359T202107040400/SAT/4TH3', N'IAD', 1,
    54, 35, 89,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107032359T202107040400/SAT/4TH3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107092300T202107100300/FNO/GUL3', N'MCO', 1,
    123, 68, 191,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107092300T202107100300/FNO/GUL3' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107092300T202107100300/FNO/GUL3', N'MIA', 1,
    86, 89, 175,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107092300T202107100300/FNO/GUL3' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107092300T202107100300/FNO/GUL3', N'IAH', 1,
    40, 64, 104,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107092300T202107100300/FNO/GUL3' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107102359T202107110400/SAT/SAY2', N'SFO', 1,
    161, 93, 254,
    48, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107102359T202107110400/SAT/SAY2' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107162300T202107170300/FNO/FED3', N'IAH', 1,
    56, 53, 109,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107162300T202107170300/FNO/FED3' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107162300T202107170300/FNO/FED3', N'IND', 1,
    47, 69, 116,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107162300T202107170300/FNO/FED3' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107162300T202107170300/FNO/FED3', N'MEM', 1,
    143, 106, 249,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107162300T202107170300/FNO/FED3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107172300T202107180300/SAT/CEL1', N'ATL', 1,
    184, 151, 335,
    88, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107172300T202107180300/SAT/CEL1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107232300T202107240300/FNO/SUM3', N'DEN', 1,
    76, 96, 172,
    96, N'0200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107232300T202107240300/FNO/SUM3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107232300T202107240300/FNO/SUM3', N'ATL', 1,
    79, 103, 182,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107232300T202107240300/FNO/SUM3' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107232300T202107240300/FNO/SUM3', N'DFW', 1,
    97, 108, 205,
    85, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107232300T202107240300/FNO/SUM3' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202107312359T202108010400/SAT/DEN1', N'DEN', 1,
    159, 111, 270,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202107312359T202108010400/SAT/DEN1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108062300T202108070300/FNO/HOT5', N'SLC', 1,
    44, 53, 97,
    62, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108062300T202108070300/FNO/HOT5' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108062300T202108070300/FNO/HOT5', N'PDX', 1,
    61, 68, 129,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108062300T202108070300/FNO/HOT5' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108062300T202108070300/FNO/HOT5', N'SEA', 1,
    74, 96, 170,
    38, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108062300T202108070300/FNO/HOT5' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108062300T202108070300/FNO/HOT5', N'RNO', 1,
    40, 25, 65,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108062300T202108070300/FNO/HOT5' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108062300T202108070300/FNO/HOT5', N'SFO', 1,
    139, 136, 275,
    36, N'2200Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108062300T202108070300/FNO/HOT5' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108071600T202108072300/SAT/22N5', N'BOS', 1,
    212, 212, 424,
    40, N'1500Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108071600T202108072300/SAT/22N5' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108071600T202108072300/SAT/22N5', N'BDL', 1,
    62, 57, 119,
    25, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108071600T202108072300/SAT/22N5' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'FLL', 1,
    42, 50, 92,
    44, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'MIA', 1,
    86, 66, 152,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'MCO', 1,
    67, 50, 117,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'JAX', 1,
    39, 56, 95,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'RDU', 1,
    41, 49, 90,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108132300T202108140300/FNO/ATL6', N'IAD', 1,
    34, 57, 91,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108132300T202108140300/FNO/ATL6' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108142300T202108150200/SAT/PHI1', N'PHL', 1,
    111, 119, 230,
    25, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108142300T202108150200/SAT/PHI1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108212359T202108220130/SAT/PEA2', N'ATL', 1,
    59, 161, 220,
    92, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108212359T202108220130/SAT/PEA2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108212359T202108220130/SAT/PEA2', N'DEN', 1,
    155, 41, 196,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108212359T202108220130/SAT/PEA2' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108272300T202108280300/FNO/ZMP4', N'DAL', 1,
    12, 30, 42,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108272300T202108280300/FNO/ZMP4' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108272300T202108280300/FNO/ZMP4', N'DFW', 1,
    95, 111, 206,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108272300T202108280300/FNO/ZMP4' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108272300T202108280300/FNO/ZMP4', N'MCI', 1,
    93, 75, 168,
    26, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108272300T202108280300/FNO/ZMP4' AND airport_icao = N'MCI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108272300T202108280300/FNO/ZMP4', N'MSP', 1,
    101, 85, 186,
    44, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108272300T202108280300/FNO/ZMP4' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108282300T202108290300/SAT/NOR1', N'MSP', 1,
    142, 108, 250,
    44, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108282300T202108290300/SAT/NOR1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202108291500T202108300600/MWK/MSY1', N'MSY', 1,
    147, 118, 265,
    25, N'1500Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202108291500T202108300600/MWK/MSY1' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109041100T202109042300/SAT/CRO6', N'SEA', 1,
    74, 52, 126,
    40, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109041100T202109042300/SAT/CRO6' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109042300T202109050300/SAT/THE1', N'ORD', 1,
    197, 98, 295,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109042300T202109050300/SAT/THE1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109102300T202109110300/FNO/THE3', N'ORF', 1,
    19, 21, 40,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109102300T202109110300/FNO/THE3' AND airport_icao = N'ORF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109102300T202109110300/FNO/THE3', N'ALB', 1,
    13, 18, 31,
    27, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109102300T202109110300/FNO/THE3' AND airport_icao = N'ALB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109102300T202109110300/FNO/THE3', N'JFK', 1,
    248, 133, 381,
    54, N'2200Z',
    6, 4, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109102300T202109110300/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109182359T202109190400/SAT/TOG2', N'SJC', 1,
    112, 64, 176,
    40, N'0200Z',
    3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109182359T202109190400/SAT/TOG2' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109242300T202109250300/FNO/FNO2', N'TUL', 1,
    35, 24, 59,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109242300T202109250300/FNO/FNO2' AND airport_icao = N'TUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109242300T202109250300/FNO/FNO2', N'OKC', 1,
    114, 46, 160,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109242300T202109250300/FNO/FNO2' AND airport_icao = N'OKC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202109262359T202109270400/SUN/ZLA9', N'SAN', 1,
    57, 58, 115,
    24, N'0000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202109262359T202109270400/SUN/ZLA9' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110022200T202110030400/SAT/ZTL2', N'CLT', 1,
    92, 67, 159,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110022200T202110030400/SAT/ZTL2' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110022200T202110030400/SAT/ZTL2', N'ATL', 1,
    112, 137, 249,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110022200T202110030400/SAT/ZTL2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110092300T202110100300/SAT/AUT1', N'LGA', 1,
    126, 89, 215,
    34, N'2200Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110092300T202110100300/SAT/AUT1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110222300T202110230300/FNO/B&O3', N'DTW', 1,
    57, 65, 122,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110222300T202110230300/FNO/B&O3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110222300T202110230300/FNO/B&O3', N'CVG', 1,
    59, 50, 109,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110222300T202110230300/FNO/B&O3' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110222300T202110230300/FNO/B&O3', N'IAD', 1,
    94, 68, 162,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110222300T202110230300/FNO/B&O3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110241800T202110250200/SUN/SFO2', N'SFO', 1,
    89, 158, 247,
    20, N'1800Z',
    7, 5, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110241800T202110250200/SUN/SFO2' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110241800T202110250200/SUN/SFO2', N'SEA', 1,
    91, 50, 141,
    35, N'1800Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110241800T202110250200/SUN/SFO2' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'ELP', 1,
    15, 18, 33,
    28, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'ELP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'PHX', 1,
    47, 50, 97,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'SAT', 1,
    18, 18, 36,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'SAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'IAH', 1,
    46, 52, 98,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'JAX', 1,
    30, 48, 78,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110292300T202110300300/FNO/NOT6', N'MCO', 1,
    94, 88, 182,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110292300T202110300300/FNO/NOT6' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'ORD', 1,
    31, 193, 224,
    90, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'ATL', 1,
    27, 169, 196,
    50, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'MIA', 1,
    25, 163, 188,
    60, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'MIA');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'IAD', 1,
    23, 149, 172,
    60, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'BOS', 1,
    40, 212, 252,
    26, N'1000Z',
    5, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202110300900T202110310300/CTP/CRO7', N'JFK', 1,
    31, 190, 221,
    30, N'1000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202110300900T202110310300/CTP/CRO7' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111132300T202111140300/FNO/THE3', N'DEN', 1,
    78, 106, 184,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111132300T202111140300/FNO/THE3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111132300T202111140300/FNO/THE3', N'MSP', 1,
    63, 77, 140,
    56, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111132300T202111140300/FNO/THE3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111132300T202111140300/FNO/THE3', N'ORD', 1,
    80, 82, 162,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111132300T202111140300/FNO/THE3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111141900T202111142200/SUN/NEW1', N'EWR', 1,
    133, 93, 226,
    30, N'1900Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111141900T202111142200/SUN/NEW1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111192359T202111200400/FNO/60 1', N'BOS', 1,
    193, 156, 349,
    40, N'2300Z',
    5, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111192359T202111200400/FNO/60 1' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111202300T202111210300/SAT/STU2', N'PHX', 1,
    50, 57, 107,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111202300T202111210300/SAT/STU2' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202111202300T202111210300/SAT/STU2', N'ABQ', 1,
    38, 28, 66,
    44, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202111202300T202111210300/SAT/STU2' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112040000T202112040400/FNO/WIN4', N'SEA', 1,
    53, 65, 118,
    38, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112040000T202112040400/FNO/WIN4' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112040000T202112040400/FNO/WIN4', N'SLC', 1,
    32, 65, 97,
    72, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112040000T202112040400/FNO/WIN4' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112040000T202112040400/FNO/WIN4', N'SFO', 1,
    121, 113, 234,
    36, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112040000T202112040400/FNO/WIN4' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112040000T202112040400/FNO/WIN4', N'LAX', 1,
    109, 155, 264,
    68, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112040000T202112040400/FNO/WIN4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112042359T202112050400/LIVE/ZDC3', N'BWI', 1,
    19, 7, 26,
    32, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112042359T202112050400/LIVE/ZDC3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112042359T202112050400/LIVE/ZDC3', N'DCA', 1,
    86, 43, 129,
    34, N'0000Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112042359T202112050400/LIVE/ZDC3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112042359T202112050400/LIVE/ZDC3', N'IAD', 1,
    39, 34, 73,
    76, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112042359T202112050400/LIVE/ZDC3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112102359T202112110400/FNO/RIV3', N'SDF', 1,
    86, 83, 169,
    48, N'0000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112102359T202112110400/FNO/RIV3' AND airport_icao = N'SDF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112102359T202112110400/FNO/RIV3', N'MSY', 1,
    52, 70, 122,
    45, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112102359T202112110400/FNO/RIV3' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112102359T202112110400/FNO/RIV3', N'MEM', 1,
    75, 67, 142,
    64, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112102359T202112110400/FNO/RIV3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112112359T202112120400/SAT/CHR2', N'BOS', 1,
    132, 130, 262,
    34, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112112359T202112120400/SAT/CHR2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112112359T202112120400/SAT/CHR2', N'MCO', 1,
    112, 122, 234,
    60, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112112359T202112120400/SAT/CHR2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112172359T202112180400/FNO/PCF5', N'HNL', 1,
    103, 90, 193,
    56, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112172359T202112180400/FNO/PCF5' AND airport_icao = N'HNL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202112182300T202112190300/SAT/ZMP2', N'MSP', 1,
    153, 73, 226,
    48, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202112182300T202112190300/SAT/ZMP2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201012359T202201020400/SAT/NEW3', N'JFK', 1,
    139, 70, 209,
    40, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201012359T202201020400/SAT/NEW3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201012359T202201020400/SAT/NEW3', N'EWR', 1,
    73, 51, 124,
    36, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201012359T202201020400/SAT/NEW3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201012359T202201020400/SAT/NEW3', N'LGA', 1,
    98, 85, 183,
    32, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201012359T202201020400/SAT/NEW3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201082300T202201090300/SAT/HOB1', N'HOU', 1,
    110, 69, 179,
    24, N'2200Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201082300T202201090300/SAT/HOB1' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201282359T202201290400/FNO/ESC3', N'MSP', 1,
    57, 100, 157,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201282359T202201290400/FNO/ESC3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201282359T202201290400/FNO/ESC3', N'SLC', 1,
    140, 107, 247,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201282359T202201290400/FNO/ESC3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201282359T202201290400/FNO/ESC3', N'PHX', 1,
    200, 127, 327,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201282359T202201290400/FNO/ESC3' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202201292359T202201300400/SAT/ZMP1', N'MSP', 1,
    145, 89, 234,
    53, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202201292359T202201300400/SAT/ZMP1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202052359T202202060400/SAT/NEV1', N'IAD', 1,
    204, 122, 326,
    76, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202052359T202202060400/SAT/NEV1' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202061800T202202062359/SUN/EST3', N'TXKF', 1,
    118, 35, 153,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202061800T202202062359/SUN/EST3' AND airport_icao = N'TXKF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202061800T202202062359/SUN/EST3', N'LGA', 1,
    39, 108, 147,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202061800T202202062359/SUN/EST3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202061800T202202062359/SUN/EST3', N'HPN', 1,
    5, 26, 31,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202061800T202202062359/SUN/EST3' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202112300T202202120300/FNO/ZFW3', N'DAL', 1,
    60, 52, 112,
    24, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202112300T202202120300/FNO/ZFW3' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202112300T202202120300/FNO/ZFW3', N'IAH', 1,
    59, 60, 119,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202112300T202202120300/FNO/ZFW3' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202112300T202202120300/FNO/ZFW3', N'PHX', 1,
    110, 96, 206,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202112300T202202120300/FNO/ZFW3' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202130100T202202130400/SAT/MIL1', N'DEN', 1,
    144, 95, 239,
    96, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202130100T202202130400/SAT/MIL1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202182359T202202190400/FNO/8TH3', N'DCA', 1,
    112, 84, 196,
    34, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202182359T202202190400/FNO/8TH3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202182359T202202190400/FNO/8TH3', N'JFK', 1,
    119, 97, 216,
    58, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202182359T202202190400/FNO/8TH3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202202182359T202202190400/FNO/8TH3', N'BOS', 1,
    166, 131, 297,
    40, N'2300Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202202182359T202202190400/FNO/8TH3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'LAX', 1,
    128, 170, 298,
    68, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'SJC', 1,
    62, 48, 110,
    40, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'SMF', 1,
    34, 34, 68,
    60, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'SBA', 1,
    26, 46, 72,
    30, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'SBA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'PDX', 1,
    50, 39, 89,
    44, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203050100T202203050500/FNO/COA9', N'SEA', 1,
    58, 92, 150,
    38, N'0000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203050100T202203050500/FNO/COA9' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203052359T202203060400/SAT/PHI1', N'PHL', 1,
    147, 73, 220,
    50, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203052359T202203060400/SAT/PHI1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203110100T202203110400/MWK/TRO1', N'TEB', 1,
    50, 29, 79,
    32, N'0000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203110100T202203110400/MWK/TRO1' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203182300T202203190300/FNO/POT4', N'SEA', 1,
    67, 71, 138,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203182300T202203190300/FNO/POT4' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203182300T202203190300/FNO/POT4', N'PDX', 1,
    75, 78, 153,
    44, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203182300T202203190300/FNO/POT4' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203182300T202203190300/FNO/POT4', N'YVR', 1,
    51, 62, 113,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203182300T202203190300/FNO/POT4' AND airport_icao = N'YVR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203182300T202203190300/FNO/POT4', N'ANC', 1,
    68, 50, 118,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203182300T202203190300/FNO/POT4' AND airport_icao = N'ANC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203252300T202203260300/FNO/NOR3', N'MSP', 1,
    122, 96, 218,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203252300T202203260300/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203252300T202203260300/FNO/NOR3', N'ORD', 1,
    131, 133, 264,
    NULL, NULL,
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203252300T202203260300/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203252300T202203260300/FNO/NOR3', N'DTW', 1,
    75, 100, 175,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203252300T202203260300/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203262300T202203270300/SAT/CHE3', N'DCA', 1,
    151, 68, 219,
    34, N'2200Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203262300T202203270300/SAT/CHE3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203262300T202203270300/SAT/CHE3', N'IAD', 1,
    62, 40, 102,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203262300T202203270300/SAT/CHE3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203262300T202203270300/SAT/CHE3', N'BWI', 1,
    31, 25, 56,
    35, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203262300T202203270300/SAT/CHE3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202203272000T202203280000/SUN/ZLA4', N'LAX', 1,
    126, 124, 250,
    68, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202203272000T202203280000/SUN/ZLA4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204082359T202204090400/FNO/SKI3', N'DEN', 1,
    129, 133, 262,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204082359T202204090400/FNO/SKI3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204082359T202204090400/FNO/SKI3', N'LAX', 1,
    111, 132, 243,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204082359T202204090400/FNO/SKI3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204082359T202204090400/FNO/SKI3', N'SLC', 1,
    84, 77, 161,
    60, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204082359T202204090400/FNO/SKI3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204100030T202204100300/SAT/TPC4', N'DCA', 1,
    85, 42, 127,
    30, N'0000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204100030T202204100300/SAT/TPC4' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204152300T202204160300/FNO/SPR4', N'IAD', 1,
    91, 99, 190,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204152300T202204160300/FNO/SPR4' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204152300T202204160300/FNO/SPR4', N'ORD', 1,
    129, 113, 242,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204152300T202204160300/FNO/SPR4' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204152300T202204160300/FNO/SPR4', N'CVG', 1,
    43, 47, 90,
    62, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204152300T202204160300/FNO/SPR4' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204152300T202204160300/FNO/SPR4', N'CLE', 1,
    55, 53, 108,
    43, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204152300T202204160300/FNO/SPR4' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204241900T202204242200/SUN/IT''1', N'HPN', 1,
    58, 26, 84,
    32, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204241900T202204242200/SUN/IT''1' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'ATL', 1,
    257, 131, 388,
    92, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'BOS', 1,
    180, 124, 304,
    40, N'1700Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'EWR', 1,
    108, 44, 152,
    30, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'PHL', 1,
    103, 38, 141,
    48, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'IAH', 1,
    122, 46, 168,
    90, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204021700T202204030000/CTP/CRO12', N'TPA', 1,
    102, 42, 144,
    58, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204021700T202204030000/CTP/CRO12' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204162359T202204170400/SAT/SHA1', N'ATL', 1,
    218, 176, 394,
    92, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204162359T202204170400/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204292300T202204300300/FNO/GUL3', N'MCO', 1,
    111, 109, 220,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204292300T202204300300/FNO/GUL3' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204292300T202204300300/FNO/GUL3', N'MSY', 1,
    84, 107, 191,
    45, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204292300T202204300300/FNO/GUL3' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202204292300T202204300300/FNO/GUL3', N'RSW', 1,
    67, 59, 126,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202204292300T202204300300/FNO/GUL3' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205062300T202205070300/FNO/DER3', N'PIT', 1,
    66, 76, 142,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205062300T202205070300/FNO/DER3' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205062300T202205070300/FNO/DER3', N'MEM', 1,
    51, 88, 139,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205062300T202205070300/FNO/DER3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205062300T202205070300/FNO/DER3', N'SDF', 1,
    112, 103, 215,
    50, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205062300T202205070300/FNO/DER3' AND airport_icao = N'SDF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205072300T202205080300/SAT/HON8', N'BOS', 1,
    134, 127, 261,
    54, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205072300T202205080300/SAT/HON8' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205072300T202205080300/SAT/HON8', N'BDL', 1,
    25, 36, 61,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205072300T202205080300/SAT/HON8' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205072300T202205080300/SAT/HON8', N'JFK', 1,
    90, 76, 166,
    32, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205072300T202205080300/SAT/HON8' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205072300T202205080300/SAT/HON8', N'CLE', 1,
    29, 38, 67,
    43, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205072300T202205080300/SAT/HON8' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205102300T202205110100/MWK/TUE1', N'LGA', 1,
    67, 77, 144,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205102300T202205110100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205142300T202205150300/SAT/PRE1', N'BWI', 1,
    126, 86, 212,
    32, N'2200Z',
    3, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205142300T202205150300/SAT/PRE1' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205172300T202205180100/MWK/TUE1', N'JFK', 1,
    67, 72, 139,
    58, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205172300T202205180100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'SFO', 1,
    84, 120, 204,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'OAK', 1,
    38, 33, 71,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'SJC', 1,
    30, 35, 65,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'LAX', 1,
    123, 109, 232,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'SAN', 1,
    49, 63, 112,
    24, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205212300T202205220300/RLOP/CAL6', N'LAS', 1,
    93, 113, 206,
    44, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205211900T202205212300/LIVE/MIA3', N'MIA', 1,
    92, 103, 195,
    64, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205211900T202205212300/LIVE/MIA3' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205211900T202205212300/LIVE/MIA3', N'FLL', 1,
    36, 27, 63,
    48, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205211900T202205212300/LIVE/MIA3' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205211900T202205212300/LIVE/MIA3', N'TPA', 1,
    18, 12, 30,
    58, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205211900T202205212300/LIVE/MIA3' AND airport_icao = N'TPA');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205242300T202205250100/MWK/TUE1', N'EWR', 1,
    56, 66, 122,
    40, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205242300T202205250100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'MCO', 1,
    103, 101, 204,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'JAX', 1,
    51, 50, 101,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'DCA', 1,
    45, 28, 73,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'BWI', 1,
    7, 18, 25,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'RDU', 1,
    55, 79, 134,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205272300T202205280300/FNO/ATL7', N'ORF', 1,
    36, 57, 93,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205272300T202205280300/FNO/ATL7' AND airport_icao = N'ORF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202205282100T202205290100/SAT/MEM1', N'MEM', 1,
    83, 99, 182,
    66, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202205282100T202205290100/SAT/MEM1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206032300T202206040300/FNO/SAN5', N'BFL', 1,
    17, 17, 34,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206032300T202206040300/FNO/SAN5' AND airport_icao = N'BFL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206032300T202206040300/FNO/SAN5', N'FAT', 1,
    25, 31, 56,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206032300T202206040300/FNO/SAN5' AND airport_icao = N'FAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206032300T202206040300/FNO/SAN5', N'SCK', 1,
    6, 5, 11,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206032300T202206040300/FNO/SAN5' AND airport_icao = N'SCK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206032300T202206040300/FNO/SAN5', N'SMF', 1,
    90, 84, 174,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206032300T202206040300/FNO/SAN5' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206032300T202206040300/FNO/SAN5', N'BOI', 1,
    54, 57, 111,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206032300T202206040300/FNO/SAN5' AND airport_icao = N'BOI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206041400T202206042200/LIVE/LIV4', N'EWR', 1,
    45, 92, 137,
    51, N'1400Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206041400T202206042200/LIVE/LIV4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206041400T202206042200/LIVE/LIV4', N'LGA', 1,
    102, 85, 187,
    36, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206041400T202206042200/LIVE/LIV4' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206041400T202206042200/LIVE/LIV4', N'JFK', 1,
    94, 68, 162,
    60, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206041400T202206042200/LIVE/LIV4' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206041400T202206042200/LIVE/LIV4', N'PHL', 1,
    43, 51, 94,
    48, N'1400Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206041400T202206042200/LIVE/LIV4' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206052000T202206052300/SUN/CIR4', N'ASE', 1,
    67, 33, 100,
    14, N'1900Z',
    3, 2, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206052000T202206052300/SUN/CIR4' AND airport_icao = N'ASE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206072300T202206080100/MWK/TUE1', N'LGA', 1,
    85, 62, 147,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206072300T202206080100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206102359T202206110400/FNO/MUS4', N'BNA', 1,
    135, 80, 215,
    56, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206102359T202206110400/FNO/MUS4' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206102359T202206110400/FNO/MUS4', N'MSY', 1,
    92, 103, 195,
    45, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206102359T202206110400/FNO/MUS4' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206102359T202206110400/FNO/MUS4', N'IND', 1,
    43, 44, 87,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206102359T202206110400/FNO/MUS4' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206121900T202206122200/SUN/NY''1', N'LGA', 1,
    94, 125, 219,
    36, N'1800Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206121900T202206122200/SUN/NY''1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206142300T202206150100/MWK/TUE1', N'JFK', 1,
    66, 55, 121,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206142300T202206150100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206162300T202206170200/MWK/NIG1', N'EWR', 1,
    71, 46, 117,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206162300T202206170200/MWK/NIG1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206182300T202206190300/SAT/JOU1', N'DTW', 1,
    67, 117, 184,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206182300T202206190300/SAT/JOU1' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'EYW', 1,
    24, 39, 63,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'EYW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'PBI', 1,
    21, 41, 62,
    28, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'PBI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'JAX', 1,
    29, 24, 53,
    34, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'MYR', 1,
    30, 26, 56,
    15, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'MYR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'ILM', 1,
    16, 13, 29,
    26, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'ILM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'MHT', 1,
    13, 16, 29,
    29, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'MHT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'PVD', 1,
    35, 22, 57,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'ISP', 1,
    10, 21, 31,
    31, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'ISP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'MDT', 1,
    12, 20, 32,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'MDT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202206242300T202206250300/FNO/LIG10', N'ORF', 1,
    39, 57, 96,
    20, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202206242300T202206250300/FNO/LIG10' AND airport_icao = N'ORF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207022300T202207030300/SAT/4TH3', N'IAD', 1,
    59, 60, 119,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207022300T202207030300/SAT/4TH3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207022300T202207030300/SAT/4TH3', N'DCA', 1,
    128, 58, 186,
    34, N'2200Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207022300T202207030300/SAT/4TH3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207022300T202207030300/SAT/4TH3', N'BWI', 1,
    19, 12, 31,
    35, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207022300T202207030300/SAT/4TH3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207032300T202207040300/SUN/AMO4', N'SEA', 1,
    91, 116, 207,
    40, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207032300T202207040300/SUN/AMO4' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207032300T202207040300/SUN/AMO4', N'PDX', 1,
    40, 34, 74,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207032300T202207040300/SUN/AMO4' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207052300T202207060100/MWK/TUE1', N'LGA', 1,
    55, 56, 111,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207052300T202207060100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207072200T202207080100/MWK/ZJX4', N'DAB', 1,
    57, 44, 101,
    24, N'2100Z',
    1, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207072200T202207080100/MWK/ZJX4' AND airport_icao = N'DAB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207082300T202207090300/FNO/THE2', N'DFW', 1,
    141, 158, 299,
    100, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207082300T202207090300/FNO/THE2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207082300T202207090300/FNO/THE2', N'IAH', 1,
    90, 81, 171,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207082300T202207090300/FNO/THE2' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207102300T202207110300/SUN/FRE2', N'OAK', 1,
    88, 101, 189,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207102300T202207110300/SUN/FRE2' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207122300T202207130100/MWK/TUE1', N'JFK', 1,
    103, 58, 161,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207122300T202207130100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207171900T202207172200/SUN/THE1', N'EWR', 1,
    95, 104, 199,
    30, N'1800Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207171900T202207172200/SUN/THE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207192300T202207200100/MWK/TUE1', N'EWR', 1,
    56, 57, 113,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207192300T202207200100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207202200T202207210100/MWK/VAT1', N'OSH', 1,
    78, 10, 88,
    30, N'2100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207202200T202207210100/MWK/VAT1' AND airport_icao = N'OSH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207212200T202207220200/MWK/VAT1', N'OSH', 1,
    94, 21, 115,
    30, N'2100Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207212200T202207220200/MWK/VAT1' AND airport_icao = N'OSH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'SEA', 1,
    23, 65, 88,
    35, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'DEN', 1,
    20, 95, 115,
    64, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'SFO', 1,
    45, 93, 138,
    36, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'LAX', 1,
    39, 134, 173,
    68, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'BOS', 1,
    101, 56, 157,
    36, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'BDL', 1,
    17, 20, 37,
    25, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'DTW', 1,
    60, 46, 106,
    70, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'JFK', 1,
    63, 41, 104,
    44, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'DCA', 1,
    76, 36, 112,
    32, N'2000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'ATL', 1,
    69, 50, 119,
    48, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'MCO', 1,
    40, 25, 65,
    26, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'IAH', 1,
    14, 48, 62,
    90, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'DFW', 1,
    38, 62, 100,
    96, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207231530T202207232330/SAT/VAT14', N'STL', 1,
    14, 25, 39,
    64, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207231530T202207232330/SAT/VAT14' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202207232300T202207240300/SAT/MIN1', N'MSP', 1,
    93, 83, 176,
    53, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202207232300T202207240300/SAT/MIN1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208022300T202208030100/MWK/TUE1', N'LGA', 1,
    74, 46, 120,
    27, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208022300T202208030100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208052359T202208060400/FNO/HIG3', N'LAS', 1,
    166, 140, 306,
    48, N'2300Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208052359T202208060400/FNO/HIG3' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208052359T202208060400/FNO/HIG3', N'RNO', 1,
    103, 111, 214,
    30, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208052359T202208060400/FNO/HIG3' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208052359T202208060400/FNO/HIG3', N'PHX', 1,
    100, 136, 236,
    66, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208052359T202208060400/FNO/HIG3' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208061600T202208062300/LIVE/23R1', N'BOS', 1,
    200, 204, 404,
    40, N'1500Z',
    5, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208061600T202208062300/LIVE/23R1' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208092300T202208100100/MWK/TUE1', N'JFK', 1,
    109, 112, 221,
    35, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208092300T202208100100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208122359T202208130400/FNO/THE3', N'SMF', 1,
    70, 96, 166,
    60, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208122359T202208130400/FNO/THE3' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208122359T202208130400/FNO/THE3', N'PDX', 1,
    120, 107, 227,
    50, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208122359T202208130400/FNO/THE3' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208122359T202208130400/FNO/THE3', N'SLC', 1,
    76, 86, 162,
    62, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208122359T202208130400/FNO/THE3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208192300T202208200200/FNO/FRI3', N'DTW', 1,
    81, 74, 155,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208192300T202208200200/FNO/FRI3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208192300T202208200200/FNO/FRI3', N'CLT', 1,
    126, 154, 280,
    54, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208192300T202208200200/FNO/FRI3' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208192300T202208200200/FNO/FRI3', N'SDF', 1,
    82, 79, 161,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208192300T202208200200/FNO/FRI3' AND airport_icao = N'SDF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208232300T202208240100/MWK/TUE1', N'EWR', 1,
    70, 98, 168,
    30, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208232300T202208240100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202208272300T202208280300/SAT/PHI1', N'PHL', 1,
    152, 93, 245,
    30, N'2200Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202208272300T202208280300/SAT/PHI1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209041900T202209042200/SUN/THE2', N'LGA', 1,
    73, 89, 162,
    36, N'1800Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209041900T202209042200/SUN/THE2' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209041900T202209042200/SUN/THE2', N'BOS', 1,
    103, 143, 246,
    54, N'1800Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209041900T202209042200/SUN/THE2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209062300T202209070100/MWK/TUE1', N'LGA', 1,
    61, 58, 119,
    34, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209062300T202209070100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209102300T202209110300/SAT/HOO1', N'PHX', 1,
    111, 97, 208,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209102300T202209110300/SAT/HOO1' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209132300T202209140100/MWK/TUE1', N'JFK', 1,
    66, 78, 144,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209132300T202209140100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209172200T202209180100/SAT/ZFW1', N'DFW', 1,
    94, 107, 201,
    85, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209172200T202209180100/SAT/ZFW1' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209182359T202209190400/SUN/WHO4', N'LAX', 1,
    93, 90, 183,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209182359T202209190400/SUN/WHO4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209202300T202209210100/MWK/TUE1', N'EWR', 1,
    58, 54, 112,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209202300T202209210100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209232300T202209240300/FNO/THE3', N'EWR', 1,
    39, 35, 74,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209232300T202209240300/FNO/THE3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209232300T202209240300/FNO/THE3', N'LGA', 1,
    106, 51, 157,
    30, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209232300T202209240300/FNO/THE3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209232300T202209240300/FNO/THE3', N'JFK', 1,
    131, 76, 207,
    44, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209232300T202209240300/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209252200T202209260200/SUN/SOC3', N'SAN', 1,
    121, 88, 209,
    24, N'2100Z',
    2, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209252200T202209260200/SUN/SOC3' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209272300T202209280100/MWK/TUE1', N'LGA', 1,
    50, 57, 107,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209272300T202209280100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202209302300T202210010300/FNO/FOR1', N'DFW', 1,
    192, 133, 325,
    92, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202209302300T202210010300/FNO/FOR1' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210012359T202210020300/SAT/MEM2', N'MEM', 1,
    77, 81, 158,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210012359T202210020300/SAT/MEM2' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210012359T202210020300/SAT/MEM2', N'IND', 1,
    66, 41, 107,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210012359T202210020300/SAT/MEM2' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210022200T202210030100/SUN/HAL1', N'MCO', 1,
    117, 107, 224,
    66, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210022200T202210030100/SUN/HAL1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210082300T202210090300/SAT/THE1', N'ATL', 1,
    132, 146, 278,
    60, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210082300T202210090300/SAT/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210162200T202210170200/SUN/SOC3', N'LAS', 1,
    143, 121, 264,
    48, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210162200T202210170200/SUN/SOC3' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210221000T202210222355/CTP/CRO9', N'ATL', 1,
    25, 141, 166,
    60, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210221000T202210222355/CTP/CRO9' AND airport_icao = N'ATL');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210221000T202210222355/CTP/CRO9', N'BOS', 1,
    48, 231, 279,
    40, N'1000Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210221000T202210222355/CTP/CRO9' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210221000T202210222355/CTP/CRO9', N'IAD', 1,
    19, 223, 242,
    64, N'1000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210221000T202210222355/CTP/CRO9' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210221000T202210222355/CTP/CRO9', N'SEA', 1,
    15, 123, 138,
    40, N'1000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210221000T202210222355/CTP/CRO9' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210232300T202210240300/SUN/NIG1', N'DEN', 1,
    133, 108, 241,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210232300T202210240300/SUN/NIG1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210252300T202210260100/MWK/TUE1', N'LGA', 1,
    58, 68, 126,
    34, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210252300T202210260100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210282300T202210290300/FNO/EVE1', N'STL', 1,
    168, 120, 288,
    64, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210282300T202210290300/FNO/EVE1' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210292300T202210300300/SAT/TET1', N'TEB', 1,
    88, 75, 163,
    30, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210292300T202210300300/SAT/TET1' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210301600T202210302000/SUN/FLI3', N'CLT', 1,
    58, 87, 145,
    54, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210301600T202210302000/SUN/FLI3' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210301600T202210302000/SUN/FLI3', N'JFK', 1,
    131, 146, 277,
    40, N'1500Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210301600T202210302000/SUN/FLI3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202210301600T202210302000/SUN/FLI3', N'DTW', 1,
    38, 77, 115,
    76, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202210301600T202210302000/SUN/FLI3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211061700T202211062100/SUN/BRA5', N'BDL', 1,
    43, 66, 109,
    25, N'1600Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211061700T202211062100/SUN/BRA5' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211082359T202211090200/MWK/TUE1', N'JFK', 1,
    70, 54, 124,
    36, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211082359T202211090200/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211122359T202211130400/SAT/THE1', N'JFK', 1,
    189, 106, 295,
    44, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211122359T202211130400/SAT/THE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211140100T202211140500/SUN/TOG2', N'SJC', 1,
    77, 61, 138,
    30, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211140100T202211140500/SUN/TOG2' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211192359T202211200400/SAT/TUR4', N'DTW', 1,
    69, 66, 135,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211192359T202211200400/SAT/TUR4' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211192359T202211200400/SAT/TUR4', N'CLE', 1,
    33, 39, 72,
    43, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211192359T202211200400/SAT/TUR4' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211192359T202211200400/SAT/TUR4', N'BDL', 1,
    21, 12, 33,
    25, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211192359T202211200400/SAT/TUR4' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211192359T202211200400/SAT/TUR4', N'BOS', 1,
    126, 118, 244,
    36, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211192359T202211200400/SAT/TUR4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211252300T202211260300/FNO/STU2', N'ABQ', 1,
    83, 53, 136,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211252300T202211260300/FNO/STU2' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211252300T202211260300/FNO/STU2', N'PHX', 1,
    99, 112, 211,
    66, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211252300T202211260300/FNO/STU2' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211262300T202211270300/SAT/COU3', N'IAH', 1,
    68, 91, 159,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211262300T202211270300/SAT/COU3' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211262300T202211270300/SAT/COU3', N'AUS', 1,
    41, 74, 115,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211262300T202211270300/SAT/COU3' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202211262300T202211270300/SAT/COU3', N'BNA', 1,
    97, 98, 195,
    52, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202211262300T202211270300/SAT/COU3' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212012359T202212020300/MWK/CAN1', N'ATL', 1,
    119, 117, 236,
    84, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212012359T202212020300/MWK/CAN1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212022359T202212030400/FNO/WIN4', N'BOS', 1,
    194, 137, 331,
    40, N'2300Z',
    5, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212022359T202212030400/FNO/WIN4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212022359T202212030400/FNO/WIN4', N'BDL', 1,
    29, 27, 56,
    25, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212022359T202212030400/FNO/WIN4' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212022359T202212030400/FNO/WIN4', N'JFK', 1,
    57, 77, 134,
    60, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212022359T202212030400/FNO/WIN4' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212022359T202212030400/FNO/WIN4', N'DCA', 1,
    85, 80, 165,
    30, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212022359T202212030400/FNO/WIN4' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212092359T202212100400/FNO/HOL4', N'DEN', 1,
    130, 154, 284,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212092359T202212100400/FNO/HOL4' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212092359T202212100400/FNO/HOL4', N'LAX', 1,
    106, 136, 242,
    74, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212092359T202212100400/FNO/HOL4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212092359T202212100400/FNO/HOL4', N'APA', 1,
    30, 24, 54,
    36, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212092359T202212100400/FNO/HOL4' AND airport_icao = N'APA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212092359T202212100400/FNO/HOL4', N'SNA', 1,
    46, 60, 106,
    24, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212092359T202212100400/FNO/HOL4' AND airport_icao = N'SNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212102000T202212110500/SAT/NIN5', N'SFO', 1,
    224, 190, 414,
    36, N'2000Z',
    5, 4, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212102000T202212110500/SAT/NIN5' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212102000T202212110500/SAT/NIN5', N'OAK', 1,
    21, 28, 49,
    35, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212102000T202212110500/SAT/NIN5' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212102000T202212110500/SAT/NIN5', N'SJC', 1,
    25, 15, 40,
    25, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212102000T202212110500/SAT/NIN5' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212111800T202212112200/SUN/THE3', N'TXKF', 1,
    68, 37, 105,
    30, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212111800T202212112200/SUN/THE3' AND airport_icao = N'TXKF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212111800T202212112200/SUN/THE3', N'SJU', 1,
    25, 51, 76,
    40, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212111800T202212112200/SUN/THE3' AND airport_icao = N'SJU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212162359T202212170400/FNO/THE5', N'MEM', 1,
    23, 41, 64,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212162359T202212170400/FNO/THE5' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212162359T202212170400/FNO/THE5', N'BNA', 1,
    40, 29, 69,
    52, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212162359T202212170400/FNO/THE5' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212162359T202212170400/FNO/THE5', N'CVG', 1,
    48, 44, 92,
    72, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212162359T202212170400/FNO/THE5' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212162359T202212170400/FNO/THE5', N'ATL', 1,
    164, 117, 281,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212162359T202212170400/FNO/THE5' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212162359T202212170400/FNO/THE5', N'CLT', 1,
    56, 78, 134,
    84, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212162359T202212170400/FNO/THE5' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212172000T202212172200/SAT/HIS1', N'BOS', 1,
    201, 113, 314,
    36, N'1800Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212172000T202212172200/SAT/HIS1' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212172359T202212180400/SAT/CAP3', N'DCA', 1,
    96, 74, 170,
    34, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212172359T202212180400/SAT/CAP3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212172359T202212180400/SAT/CAP3', N'IAD', 1,
    61, 45, 106,
    64, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212172359T202212180400/SAT/CAP3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212172359T202212180400/SAT/CAP3', N'BWI', 1,
    34, 29, 63,
    32, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212172359T202212180400/SAT/CAP3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212182359T202212190400/SUN/ZMP2', N'MSP', 1,
    102, 62, 164,
    53, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212182359T202212190400/SUN/ZMP2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202212192359T202212200300/MWK/MIL1', N'MKE', 1,
    104, 68, 172,
    32, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202212192359T202212200300/MWK/MIL1' AND airport_icao = N'MKE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301072359T202301080400/SAT/NEW3', N'EWR', 1,
    82, 54, 136,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301072359T202301080400/SAT/NEW3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301072359T202301080400/SAT/NEW3', N'LGA', 1,
    81, 42, 123,
    36, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301072359T202301080400/SAT/NEW3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301072359T202301080400/SAT/NEW3', N'JFK', 1,
    139, 102, 241,
    44, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301072359T202301080400/SAT/NEW3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301082200T202301090200/SUN/HOM1', N'ORD', 1,
    134, 134, 268,
    96, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301082200T202301090200/SUN/HOM1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301102359T202301110200/MWK/TUE1', N'LGA', 1,
    49, 36, 85,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301102359T202301110200/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301132359T202301140400/FNO/HON1', N'ATL', 1,
    377, 241, 618,
    88, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301132359T202301140400/FNO/HON1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301162359T202301170400/MWK/MID1', N'MDW', 1,
    79, 62, 141,
    28, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301162359T202301170400/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301172359T202301180200/MWK/TUE1', N'JFK', 1,
    70, 80, 150,
    36, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301172359T202301180200/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301202359T202301210400/FNO/9TH3', N'DCA', 1,
    127, 103, 230,
    34, N'2300Z',
    3, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301202359T202301210400/FNO/9TH3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301202359T202301210400/FNO/9TH3', N'LGA', 1,
    117, 70, 187,
    30, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301202359T202301210400/FNO/9TH3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301202359T202301210400/FNO/9TH3', N'BOS', 1,
    148, 161, 309,
    34, N'2300Z',
    4, 4, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301202359T202301210400/FNO/9TH3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301212359T202301220400/SAT/MC-2', N'MCO', 1,
    129, 64, 193,
    72, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301212359T202301220400/SAT/MC-2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301242359T202301250200/MWK/TUE1', N'EWR', 1,
    78, 70, 148,
    30, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301242359T202301250200/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301272345T202301280400/FNO/FIR1', N'STL', 1,
    177, 133, 310,
    64, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301272345T202301280400/FNO/FIR1' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301290000T202301290400/SAT/OPP3', N'SFO', 1,
    129, 91, 220,
    34, N'2300Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301290000T202301290400/SAT/OPP3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301290000T202301290400/SAT/OPP3', N'OAK', 1,
    27, 22, 49,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301290000T202301290400/SAT/OPP3' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202301290000T202301290400/SAT/OPP3', N'SJC', 1,
    31, 31, 62,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202301290000T202301290400/SAT/OPP3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302042300T202302050400/LIVE/OPE1', N'MSP', 1,
    225, 131, 356,
    54, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302042300T202302050400/LIVE/OPE1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302052100T202302052359/SUN/THE3', N'EYW', 1,
    75, 59, 134,
    24, N'2000Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302052100T202302052359/SUN/THE3' AND airport_icao = N'EYW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302052100T202302052359/SUN/THE3', N'RSW', 1,
    68, 33, 101,
    32, N'2000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302052100T202302052359/SUN/THE3' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302052100T202302052359/SUN/THE3', N'PBI', 1,
    41, 35, 76,
    32, N'2000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302052100T202302052359/SUN/THE3' AND airport_icao = N'PBI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302072359T202302080200/MWK/TUE1', N'EWR', 1,
    63, 56, 119,
    30, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302072359T202302080200/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302102359T202302110400/FNO/THE2', N'LGA', 1,
    100, 72, 172,
    30, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302102359T202302110400/FNO/THE2' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302102359T202302110400/FNO/THE2', N'JFK', 1,
    132, 90, 222,
    44, N'2300Z',
    3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302102359T202302110400/FNO/THE2' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302132359T202302140400/MWK/MID1', N'MDW', 1,
    95, 77, 172,
    36, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302132359T202302140400/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302142359T202302150200/MWK/TUE1', N'JFK', 1,
    73, 72, 145,
    36, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302142359T202302150200/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302172359T202302180400/FNO/ZFW1', N'DAL', 1,
    143, 71, 214,
    33, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302172359T202302180400/FNO/ZFW1' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302172359T202302180400/FNO/ZFW1', N'DFW', 1,
    105, 112, 217,
    92, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302172359T202302180400/FNO/ZFW1' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302182000T202302182300/SAT/DAY1', N'DAB', 1,
    97, 41, 138,
    24, N'1900Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302182000T202302182300/SAT/DAY1' AND airport_icao = N'DAB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302190100T202302190500/SAT/SFO1', N'SFO', 1,
    187, 142, 329,
    45, N'0000Z',
    4, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302190100T202302190500/SAT/SFO1' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302191900T202302192200/SUN/THE1', N'EWR', 1,
    137, 69, 206,
    40, N'1800Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302191900T202302192200/SUN/THE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302212359T202302220300/MWK/MON2', N'BOS', 1,
    114, 129, 243,
    40, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302212359T202302220300/MWK/MON2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302232359T202302240300/MWK/BIR1', N'BHM', 1,
    76, 59, 135,
    31, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302232359T202302240300/MWK/BIR1' AND airport_icao = N'BHM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302242359T202302250400/FNO/THE3', N'SDF', 1,
    96, 84, 180,
    50, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302242359T202302250400/FNO/THE3' AND airport_icao = N'SDF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302242359T202302250400/FNO/THE3', N'BWI', 1,
    103, 71, 174,
    35, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302242359T202302250400/FNO/THE3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302242359T202302250400/FNO/THE3', N'JFK', 1,
    114, 104, 218,
    44, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302242359T202302250400/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302252200T202302260200/SAT/MAR1', N'MSY', 1,
    110, 60, 170,
    45, N'2100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302252200T202302260200/SAT/MAR1' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202302272359T202302280400/MWK/MIL1', N'MKE', 1,
    53, 39, 92,
    21, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202302272359T202302280400/MWK/MIL1' AND airport_icao = N'MKE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303041800T202303050000/SAT/24 4', N'ATL', 1,
    48, 185, 233,
    90, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303041800T202303050000/SAT/24 4' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303041800T202303050000/SAT/24 4', N'LAX', 1,
    119, 128, 247,
    68, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303041800T202303050000/SAT/24 4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303041800T202303050000/SAT/24 4', N'SFO', 1,
    74, 99, 173,
    30, N'1900Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303041800T202303050000/SAT/24 4' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303041800T202303050000/SAT/24 4', N'IAH', 1,
    16, 65, 81,
    90, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303041800T202303050000/SAT/24 4' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303072359T202303080200/MWK/TUE1', N'LGA', 1,
    55, 72, 127,
    30, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303072359T202303080200/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303102359T202303110400/FNO/NOR3', N'DTW', 1,
    123, 104, 227,
    70, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303102359T202303110400/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303102359T202303110400/FNO/NOR3', N'ORD', 1,
    138, 120, 258,
    92, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303102359T202303110400/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303102359T202303110400/FNO/NOR3', N'MSP', 1,
    74, 88, 162,
    44, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303102359T202303110400/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303132359T202303140300/MWK/SPR3', N'LAX', 1,
    93, 96, 189,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303132359T202303140300/MWK/SPR3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303132359T202303140300/MWK/SPR3', N'SAN', 1,
    32, 45, 77,
    24, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303132359T202303140300/MWK/SPR3' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303172359T202303180400/FNO/GRE3', N'SJC', 1,
    106, 76, 182,
    25, N'2300Z',
    3, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303172359T202303180400/FNO/GRE3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303172359T202303180400/FNO/GRE3', N'RNO', 1,
    87, 58, 145,
    25, N'2300Z',
    3, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303172359T202303180400/FNO/GRE3' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303172359T202303180400/FNO/GRE3', N'FAT', 1,
    50, 25, 75,
    25, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303172359T202303180400/FNO/GRE3' AND airport_icao = N'FAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303182300T202303190300/SAT/CHE1', N'DCA', 1,
    170, 98, 268,
    34, N'2200Z',
    4, 4, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303182300T202303190300/SAT/CHE1' AND airport_icao = N'DCA');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303192200T202303200100/SUN/SUN1', N'LAL', 1,
    63, 15, 78,
    24, N'2100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303192200T202303200100/SUN/SUN1' AND airport_icao = N'LAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303202300T202303210300/MWK/SHA1', N'ORD', 1,
    119, 122, 241,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303202300T202303210300/MWK/SHA1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303212359T202303220300/MWK/BVA1', N'PVD', 1,
    58, 49, 107,
    32, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303212359T202303220300/MWK/BVA1' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303252300T202303260300/SAT/DEN1', N'DEN', 1,
    105, 124, 229,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303252300T202303260300/SAT/DEN1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202303302359T202303310200/MWK/MIS1', N'ISP', 1,
    58, 43, 101,
    25, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202303302359T202303310200/MWK/MIS1' AND airport_icao = N'ISP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304011800T202304020100/CTP/CRO4', N'ATL', 1,
    170, 89, 259,
    96, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304011800T202304020100/CTP/CRO4' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304011800T202304020100/CTP/CRO4', N'BOS', 1,
    176, 122, 298,
    32, N'1800Z',
    5, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304011800T202304020100/CTP/CRO4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304011800T202304020100/CTP/CRO4', N'JFK', 1,
    174, 65, 239,
    44, N'1800Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304011800T202304020100/CTP/CRO4' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304011800T202304020100/CTP/CRO4', N'ORD', 1,
    194, 60, 254,
    92, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304011800T202304020100/CTP/CRO4' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304042300T202304050100/MWK/TUE1', N'EWR', 1,
    54, 71, 125,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304042300T202304050100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304072300T202304080300/FNO/FEN3', N'PIT', 1,
    80, 82, 162,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304072300T202304080300/FNO/FEN3' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304072300T202304080300/FNO/FEN3', N'BOS', 1,
    186, 151, 337,
    40, N'2200Z',
    5, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304072300T202304080300/FNO/FEN3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304072300T202304080300/FNO/FEN3', N'LGA', 1,
    95, 85, 180,
    30, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304072300T202304080300/FNO/FEN3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304082300T202304090300/SAT/REV1', N'ATL', 1,
    253, 161, 414,
    76, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304082300T202304090300/SAT/REV1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304152300T202304160300/SAT/SHA1', N'ATL', 1,
    230, 154, 384,
    96, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304152300T202304160300/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304162200T202304170100/SUN/THE3', N'DCA', 1,
    105, 53, 158,
    30, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304162200T202304170100/SUN/THE3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304182300T202304190100/MWK/TUE1', N'JFK', 1,
    112, 94, 206,
    54, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304182300T202304190100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304212300T202304220300/FNO/GUL3', N'MSY', 1,
    81, 101, 182,
    45, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304212300T202304220300/FNO/GUL3' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304212300T202304220300/FNO/GUL3', N'TPA', 1,
    105, 75, 180,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304212300T202304220300/FNO/GUL3' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304212300T202304220300/FNO/GUL3', N'MCO', 1,
    84, 98, 182,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304212300T202304220300/FNO/GUL3' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304222300T202304230300/SAT/BIN2', N'SFO', 1,
    73, 71, 144,
    40, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304222300T202304230300/SAT/BIN2' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304282300T202304290300/FNO/ADO3', N'DTW', 1,
    111, 105, 216,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304282300T202304290300/FNO/ADO3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304282300T202304290300/FNO/ADO3', N'MSP', 1,
    111, 79, 190,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304282300T202304290300/FNO/ADO3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202304282300T202304290300/FNO/ADO3', N'SLC', 1,
    42, 74, 116,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202304282300T202304290300/FNO/ADO3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305022300T202305030100/MWK/TUE1', N'LGA', 1,
    60, 42, 102,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305022300T202305030100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305052300T202305060300/FNO/MEM1', N'MEM', 1,
    189, 90, 279,
    56, N'2200Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305052300T202305060300/FNO/MEM1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305062300T202305070300/SAT/HON5', N'BOS', 1,
    137, 128, 265,
    40, N'2200Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305062300T202305070300/SAT/HON5' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305062300T202305070300/SAT/HON5', N'CLE', 1,
    55, 66, 121,
    43, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305062300T202305070300/SAT/HON5' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305062300T202305070300/SAT/HON5', N'CVG', 1,
    27, 38, 65,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305062300T202305070300/SAT/HON5' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305062300T202305070300/SAT/HON5', N'EWR', 1,
    61, 73, 134,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305062300T202305070300/SAT/HON5' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305132300T202305140300/SAT/THE2', N'CLE', 1,
    48, 41, 89,
    43, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305132300T202305140300/SAT/THE2' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305132300T202305140300/SAT/THE2', N'DTW', 1,
    92, 89, 181,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305132300T202305140300/SAT/THE2' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305142359T202305150300/SUN/IT''1', N'HOU', 1,
    110, 79, 189,
    32, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305142359T202305150300/SUN/IT''1' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305162300T202305170100/MWK/TUE1', N'EWR', 1,
    63, 64, 127,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305162300T202305170100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305192300T202305200300/FNO/ALO1', N'HNL', 1,
    81, 99, 180,
    56, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305192300T202305200300/FNO/ALO1' AND airport_icao = N'HNL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'SFO', 1,
    98, 97, 195,
    35, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'OAK', 1,
    37, 32, 69,
    35, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'SJC', 1,
    19, 26, 45,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'SMF', 1,
    21, 20, 41,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'RNO', 1,
    24, 25, 49,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'LAX', 1,
    116, 121, 237,
    45, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'LAS', 1,
    77, 93, 170,
    28, N'2200Z',
    2, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305212300T202305220300/RLOP/CAL8', N'SAN', 1,
    57, 52, 109,
    17, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305262300T202305270300/FNO/RAC3', N'IND', 1,
    83, 75, 158,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305262300T202305270300/FNO/RAC3' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305262300T202305270300/FNO/RAC3', N'CLT', 1,
    139, 135, 274,
    72, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305262300T202305270300/FNO/RAC3' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305262300T202305270300/FNO/RAC3', N'DAB', 1,
    82, 67, 149,
    23, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305262300T202305270300/FNO/RAC3' AND airport_icao = N'DAB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305302300T202305310100/MWK/TUE1', N'JFK', 1,
    67, 50, 117,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305302300T202305310100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202305312300T202306010200/MWK/TAX1', N'MCO', 1,
    120, 99, 219,
    50, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202305312300T202306010200/MWK/TAX1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306022359T202306030400/FNO/CAC4', N'LAS', 1,
    137, 119, 256,
    48, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306022359T202306030400/FNO/CAC4' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306022359T202306030400/FNO/CAC4', N'SAN', 1,
    101, 100, 201,
    24, N'2300Z',
    4, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306022359T202306030400/FNO/CAC4' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306022359T202306030400/FNO/CAC4', N'PHX', 1,
    110, 112, 222,
    66, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306022359T202306030400/FNO/CAC4' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306022359T202306030400/FNO/CAC4', N'ELP', 1,
    12, 30, 42,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306022359T202306030400/FNO/CAC4' AND airport_icao = N'ELP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306032300T202306040300/SAT/A N1', N'HSV', 1,
    91, 39, 130,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306032300T202306040300/SAT/A N1' AND airport_icao = N'HSV');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306092300T202306100300/FNO/NAV1', N'ORD', 1,
    223, 142, 365,
    96, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306092300T202306100300/FNO/NAV1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'LAX', 1,
    159, 134, 293,
    55, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'SAN', 1,
    43, 77, 120,
    15, N'2300Z',
    4, 4, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'SNA', 1,
    32, 20, 52,
    15, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'SNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'BUR', 1,
    29, 24, 53,
    20, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'BUR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'ONT', 1,
    28, 21, 49,
    20, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'ONT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306102359T202306110400/LIVE/ZLA6', N'PSP', 1,
    12, 14, 26,
    15, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6' AND airport_icao = N'PSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306122300T202306130200/MWK/BVA2', N'BOS', 1,
    104, 141, 245,
    40, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306122300T202306130200/MWK/BVA2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306122300T202306130200/MWK/BVA2', N'SYR', 1,
    77, 56, 133,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306122300T202306130200/MWK/BVA2' AND airport_icao = N'SYR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306132300T202306140100/MWK/TUE1', N'LGA', 1,
    86, 45, 131,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306132300T202306140100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306152300T202306160200/MWK/ANC1', N'ANC', 1,
    62, 57, 119,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306152300T202306160200/MWK/ANC1' AND airport_icao = N'ANC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306162300T202306170300/FNO/THE2', N'MIA', 1,
    195, 137, 332,
    64, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306162300T202306170300/FNO/THE2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306162300T202306170300/FNO/THE2', N'FLL', 1,
    85, 66, 151,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306162300T202306170300/FNO/THE2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306172300T202306180300/LIVE/STO3', N'SFO', 1,
    177, 108, 285,
    45, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306172300T202306180300/LIVE/STO3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306172300T202306180300/LIVE/STO3', N'OAK', 1,
    40, 26, 66,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306172300T202306180300/LIVE/STO3' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306172300T202306180300/LIVE/STO3', N'SJC', 1,
    25, 18, 43,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306172300T202306180300/LIVE/STO3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306232300T202306240300/LIVE/ZHU1', N'IAH', 1,
    185, 107, 292,
    90, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306232300T202306240300/LIVE/ZHU1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306241900T202306242200/SAT/THE1', N'ATL', 1,
    136, 122, 258,
    60, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306241900T202306242200/SAT/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306272300T202306280100/MWK/TUE1', N'EWR', 1,
    90, 79, 169,
    30, N'2200Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306272300T202306280100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306292359T202306300300/MWK/BVA1', N'PWM', 1,
    67, 32, 99,
    30, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306292359T202306300300/MWK/BVA1' AND airport_icao = N'PWM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306302300T202307010300/FNO/FOU3', N'IAD', 1,
    84, 39, 123,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306302300T202307010300/FNO/FOU3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306302300T202307010300/FNO/FOU3', N'DCA', 1,
    144, 50, 194,
    30, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306302300T202307010300/FNO/FOU3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202306302300T202307010300/FNO/FOU3', N'BWI', 1,
    52, 27, 79,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202306302300T202307010300/FNO/FOU3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307072359T202307080400/LIVE/DEN3', N'DEN', 1,
    250, 165, 415,
    64, N'2300Z',
    4, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307072359T202307080400/LIVE/DEN3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307072359T202307080400/LIVE/DEN3', N'COS', 1,
    51, 35, 86,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307072359T202307080400/LIVE/DEN3' AND airport_icao = N'COS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307082300T202307090300/SAT/THE1', N'PHL', 1,
    166, 104, 270,
    45, N'2200Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307082300T202307090300/SAT/THE1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307091800T202307092200/SUN/VAT1', N'OSH', 1,
    117, 22, 139,
    30, N'1700Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307091800T202307092200/SUN/VAT1' AND airport_icao = N'OSH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307112300T202307120100/MWK/TUE1', N'JFK', 1,
    102, 97, 199,
    44, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307112300T202307120100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307161700T202307162000/SUN/NEW2', N'EWR', 1,
    66, 61, 127,
    28, N'1600Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307161700T202307162000/SUN/NEW2' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307161700T202307162000/SUN/NEW2', N'PHL', 1,
    53, 75, 128,
    40, N'1600Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307161700T202307162000/SUN/NEW2' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307212300T202307220300/FNO/SUM2', N'DFW', 1,
    175, 138, 313,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307212300T202307220300/FNO/SUM2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307212300T202307220300/FNO/SUM2', N'DAL', 1,
    74, 48, 122,
    32, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307212300T202307220300/FNO/SUM2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307222200T202307230300/LIVE/THE1', N'ATL', 1,
    271, 209, 480,
    96, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307222200T202307230300/LIVE/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307242300T202307250300/MWK/MID1', N'MDW', 1,
    106, 79, 185,
    28, N'2200Z',
    3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307242300T202307250300/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307252300T202307260100/MWK/TUE1', N'LGA', 1,
    90, 63, 153,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307252300T202307260100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307292300T202307300300/LIVE/NOR1', N'MSP', 1,
    145, 113, 258,
    56, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307292300T202307300300/LIVE/NOR1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202307301700T202307302000/SUN/OPE1', N'BUF', 1,
    76, 76, 152,
    34, N'1600Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202307301700T202307302000/SUN/OPE1' AND airport_icao = N'BUF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'CLT', 1,
    127, 115, 242,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'CVG', 1,
    30, 36, 66,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'RDU', 1,
    35, 36, 71,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'SAV', 1,
    27, 31, 58,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'SAV');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'TPA', 1,
    101, 85, 186,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'MSY', 1,
    43, 51, 94,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308042300T202308050300/FNO/SUN7', N'BNA', 1,
    51, 81, 132,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308042300T202308050300/FNO/SUN7' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308051600T202308052300/LIVE/24T1', N'BOS', 1,
    233, 263, 496,
    54, N'1500Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308051600T202308052300/LIVE/24T1' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308082300T202308090100/MWK/TUE1', N'EWR', 1,
    77, 55, 132,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308082300T202308090100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308112359T202308120400/FNO/SEA1', N'SEA', 1,
    169, 149, 318,
    38, N'2300Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308112359T202308120400/FNO/SEA1' AND airport_icao = N'SEA');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308122300T202308130300/SAT/RET1', N'TEB', 1,
    117, 66, 183,
    32, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308122300T202308130300/SAT/RET1' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308201700T202308202000/SUN/US 1', N'LGA', 1,
    104, 85, 189,
    36, N'1600Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308201700T202308202000/SUN/US 1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308222300T202308230100/MWK/TUE1', N'JFK', 1,
    103, 101, 204,
    36, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308222300T202308230100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308252300T202308260300/FNO/MY 2', N'MSP', 1,
    135, 96, 231,
    60, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308252300T202308260300/FNO/MY 2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308252300T202308260300/FNO/MY 2', N'DFW', 1,
    116, 122, 238,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308252300T202308260300/FNO/MY 2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308261800T202308262200/LIVE/MIA2', N'MIA', 1,
    144, 131, 275,
    64, N'1700Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308261800T202308262200/LIVE/MIA2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308261800T202308262200/LIVE/MIA2', N'FLL', 1,
    47, 47, 94,
    48, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308261800T202308262200/LIVE/MIA2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202308282300T202308290300/MWK/MID1', N'MDW', 1,
    90, 78, 168,
    36, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202308282300T202308290300/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309012359T202309020400/FNO/BRA2', N'LAX', 1,
    153, 133, 286,
    50, N'2300Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309012359T202309020400/FNO/BRA2' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309012359T202309020400/FNO/BRA2', N'SAN', 1,
    69, 69, 138,
    20, N'2300Z',
    1, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309012359T202309020400/FNO/BRA2' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309022300T202309030300/SAT/THE1', N'ATL', 1,
    213, 152, 365,
    60, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309022300T202309030300/SAT/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309052300T202309060100/MWK/TUE1', N'LGA', 1,
    80, 37, 117,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309052300T202309060100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309082300T202309090300/FNO/BES3', N'MCI', 1,
    76, 84, 160,
    52, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309082300T202309090300/FNO/BES3' AND airport_icao = N'MCI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309082300T202309090300/FNO/BES3', N'MEM', 1,
    114, 94, 208,
    78, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309082300T202309090300/FNO/BES3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309082300T202309090300/FNO/BES3', N'AUS', 1,
    111, 95, 206,
    48, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309082300T202309090300/FNO/BES3' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309092300T202309100300/SAT/HOO1', N'PHX', 1,
    116, 116, 232,
    66, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309092300T202309100300/SAT/HOO1' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309142300T202309150200/MWK/SOU1', N'BHM', 1,
    68, 48, 116,
    31, N'2200Z',
    1, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309142300T202309150200/MWK/SOU1' AND airport_icao = N'BHM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309152300T202309160300/FNO/SEP4', N'SFO', 1,
    186, 96, 282,
    40, N'2200Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309152300T202309160300/FNO/SEP4' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309152300T202309160300/FNO/SEP4', N'SMF', 1,
    31, 26, 57,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309152300T202309160300/FNO/SEP4' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309152300T202309160300/FNO/SEP4', N'SJC', 1,
    56, 59, 115,
    20, N'2200Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309152300T202309160300/FNO/SEP4' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309152300T202309160300/FNO/SEP4', N'FAT', 1,
    24, 19, 43,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309152300T202309160300/FNO/SEP4' AND airport_icao = N'FAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309192300T202309200100/MWK/TUE1', N'EWR', 1,
    83, 78, 161,
    40, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309192300T202309200100/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309222300T202309230300/FNO/KMI1', N'MIA', 1,
    200, 128, 328,
    64, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309222300T202309230300/FNO/KMI1' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309292300T202309300300/FNO/THE3', N'EWR', 1,
    75, 34, 109,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309292300T202309300300/FNO/THE3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309292300T202309300300/FNO/THE3', N'LGA', 1,
    84, 42, 126,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309292300T202309300300/FNO/THE3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309292300T202309300300/FNO/THE3', N'JFK', 1,
    138, 87, 225,
    32, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309292300T202309300300/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309302300T202310010300/SAT/MLB2', N'ATL', 1,
    123, 145, 268,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309302300T202310010300/SAT/MLB2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202309302300T202310010300/SAT/MLB2', N'TPA', 1,
    82, 65, 147,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202309302300T202310010300/SAT/MLB2' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310032300T202310040100/MWK/TUE1', N'JFK', 1,
    79, 80, 159,
    36, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310032300T202310040100/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310052230T202310060130/MWK/CIT1', N'HPN', 1,
    45, 21, 66,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310052230T202310060130/MWK/CIT1' AND airport_icao = N'HPN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310072300T202310080300/SAT/SPE2', N'CLT', 1,
    145, 84, 229,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310072300T202310080300/SAT/SPE2' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310081700T202310082000/SUN/BOS2', N'JFK', 1,
    126, 134, 260,
    58, N'1600Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310081700T202310082000/SUN/BOS2' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310081700T202310082000/SUN/BOS2', N'BOS', 1,
    152, 202, 354,
    36, N'1600Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310081700T202310082000/SUN/BOS2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310132300T202310140300/FNO/HAL1', N'MCO', 1,
    189, 137, 326,
    60, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310132300T202310140300/FNO/HAL1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310152300T202310160300/SUN/THE1', N'ORD', 1,
    162, 89, 251,
    88, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310152300T202310160300/SUN/THE1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310172300T202310180100/MWK/TUE1', N'LGA', 1,
    59, 54, 113,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310172300T202310180100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310212300T202310220300/SAT/RET1', N'PIT', 1,
    110, 79, 189,
    68, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310212300T202310220300/SAT/RET1' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310281000T202310281600/CTP/CRO12', N'ATL', 1,
    20, 201, 221,
    96, N'0900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310281000T202310281600/CTP/CRO12' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310281000T202310281600/CTP/CRO12', N'BOS', 1,
    24, 166, 190,
    36, N'0900Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310281000T202310281600/CTP/CRO12' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310281000T202310281600/CTP/CRO12', N'JFK', 1,
    42, 208, 250,
    48, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310281000T202310281600/CTP/CRO12' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202310281000T202310281600/CTP/CRO12', N'MCO', 1,
    20, 197, 217,
    80, N'0900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202310281000T202310281600/CTP/CRO12' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311032300T202311040300/FNO/AN 3', N'DCA', 1,
    146, 53, 199,
    30, N'2200Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311032300T202311040300/FNO/AN 3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311032300T202311040300/FNO/AN 3', N'IAD', 1,
    75, 37, 112,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311032300T202311040300/FNO/AN 3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311032300T202311040300/FNO/AN 3', N'BWI', 1,
    40, 25, 65,
    35, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311032300T202311040300/FNO/AN 3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311042300T202311050300/SAT/CAR1', N'MIA', 1,
    163, 118, 281,
    64, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311042300T202311050300/SAT/CAR1' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311052200T202311060100/SUN/THE1', N'LGA', 1,
    110, 75, 185,
    36, N'2100Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311052200T202311060100/SUN/THE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311061700T202311062300/MWK/WF11', N'IAH', 1,
    101, 156, 257,
    90, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311061700T202311062300/MWK/WF11' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311062000T202311070200/MWK/WF21', N'ATL', 1,
    164, 199, 363,
    96, N'1900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311062000T202311070200/MWK/WF21' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311062300T202311070400/MWK/WF31', N'BOS', 1,
    158, 141, 299,
    40, N'2300Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311062300T202311070400/MWK/WF31' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311102359T202311110400/FNO/FNO2', N'TUL', 1,
    33, 38, 71,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311102359T202311110400/FNO/FNO2' AND airport_icao = N'TUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311102359T202311110400/FNO/FNO2', N'OKC', 1,
    118, 63, 181,
    40, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311102359T202311110400/FNO/FNO2' AND airport_icao = N'OKC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311112359T202311120400/SAT/RET1', N'ATL', 1,
    228, 113, 341,
    76, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311112359T202311120400/SAT/RET1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311121700T202311130100/SUN/HCF2', N'HNL', 1,
    26, 82, 108,
    56, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311121700T202311130100/SUN/HCF2' AND airport_icao = N'HNL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311142359T202311150200/MWK/TUE1', N'JFK', 1,
    74, 76, 150,
    35, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311142359T202311150200/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311172359T202311180400/FNO/OVE3', N'DTW', 1,
    78, 107, 185,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311172359T202311180400/FNO/OVE3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311172359T202311180400/FNO/OVE3', N'MSP', 1,
    163, 129, 292,
    54, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311172359T202311180400/FNO/OVE3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311172359T202311180400/FNO/OVE3', N'MEM', 1,
    46, 45, 91,
    62, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311172359T202311180400/FNO/OVE3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311190000T202311190400/SAT/TOG3', N'SJC', 1,
    101, 81, 182,
    40, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311190000T202311190400/SAT/TOG3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311192200T202311200100/SUN/TUR1', N'TEB', 1,
    39, 42, 81,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311192200T202311200100/SUN/TUR1' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311242300T202311250300/FNO/STU2', N'ABQ', 1,
    77, 67, 144,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311242300T202311250300/FNO/STU2' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202311282359T202311290200/MWK/TUE1', N'LGA', 1,
    63, 53, 116,
    32, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202311282359T202311290200/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312012359T202312020400/FNO/2023', N'BOS', 1,
    279, 187, 466,
    40, N'2200Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312012359T202312020400/FNO/2023' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312012359T202312020400/FNO/2023', N'BDL', 1,
    37, 41, 78,
    25, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312012359T202312020400/FNO/2023' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312012359T202312020400/FNO/2023', N'PWM', 1,
    47, 30, 77,
    18, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312012359T202312020400/FNO/2023' AND airport_icao = N'PWM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312082359T202312090400/FNO/COR3', N'ORD', 1,
    139, 108, 247,
    90, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312082359T202312090400/FNO/COR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312082359T202312090400/FNO/COR3', N'DTW', 1,
    76, 96, 172,
    70, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312082359T202312090400/FNO/COR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312082359T202312090400/FNO/COR3', N'CVG', 1,
    76, 83, 159,
    72, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312082359T202312090400/FNO/COR3' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312092359T202312100400/LIVE/NEV1', N'IAD', 1,
    174, 97, 271,
    64, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312092359T202312100400/LIVE/NEV1' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312152359T202312160400/FNO/THE4', N'LAS', 1,
    156, 155, 311,
    60, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312152359T202312160400/FNO/THE4' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312152359T202312160400/FNO/THE4', N'DEN', 1,
    158, 129, 287,
    64, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312152359T202312160400/FNO/THE4' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312172300T202312180200/SUN/HOL1', N'MEM', 1,
    92, 83, 175,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312172300T202312180200/SUN/HOL1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312222359T202312230400/FNO/HOM2', N'ORD', 1,
    220, 121, 341,
    80, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312222359T202312230400/FNO/HOM2' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312222359T202312230400/FNO/HOM2', N'MDW', 1,
    72, 47, 119,
    28, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312222359T202312230400/FNO/HOM2' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312232358T202312240359/SAT/SAT1', N'CLT', 1,
    114, 99, 213,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312232358T202312240359/SAT/SAT1' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312232359T202312240400/SAT/HAV1', N'SFO', 1,
    135, 98, 233,
    26, N'2300Z',
    4, 4, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312232359T202312240400/SAT/HAV1' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312242359T202312250400/SUN/LAS2', N'MSP', 1,
    121, 75, 196,
    43, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312242359T202312250400/SUN/LAS2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202312302300T202312310300/SAT/GTO1', N'MCO', 1,
    231, 177, 408,
    72, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202312302300T202312310300/SAT/GTO1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401060100T202401060500/FNO/GRE2', N'PDX', 1,
    170, 129, 299,
    50, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401060100T202401060500/FNO/GRE2' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401062359T202401070400/SAT/NEW3', N'EWR', 1,
    89, 53, 142,
    29, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401062359T202401070400/SAT/NEW3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401062359T202401070400/SAT/NEW3', N'LGA', 1,
    93, 50, 143,
    32, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401062359T202401070400/SAT/NEW3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401062359T202401070400/SAT/NEW3', N'JFK', 1,
    186, 120, 306,
    44, N'2300Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401062359T202401070400/SAT/NEW3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401092359T202401100200/MWK/TUE1', N'LGA', 1,
    95, 59, 154,
    24, N'2300Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401092359T202401100200/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401122359T202401130400/FNO/HON1', N'ATL', 1,
    342, 220, 562,
    76, N'2300Z',
    4, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401122359T202401130400/FNO/HON1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401132359T202401140400/SAT/MEE1', N'BNA', 1,
    169, 116, 285,
    52, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401132359T202401140400/SAT/MEE1' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401142200T202401150100/SUN/PHR1', N'PHL', 1,
    171, 82, 253,
    32, N'2100Z',
    3, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401142200T202401150100/SUN/PHR1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401152359T202401160300/MWK/MID1', N'MDW', 1,
    116, 77, 193,
    36, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401152359T202401160300/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401200000T202401200400/FNO/NCT6', N'SFO', 1,
    151, 151, 302,
    28, N'2300Z',
    6, 5, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401200000T202401200400/FNO/NCT6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401200000T202401200400/FNO/NCT6', N'OAK', 1,
    79, 50, 129,
    22, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401200000T202401200400/FNO/NCT6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401200000T202401200400/FNO/NCT6', N'SJC', 1,
    39, 30, 69,
    22, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401200000T202401200400/FNO/NCT6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401262359T202401270400/FNO/10T4', N'PHL', 1,
    97, 71, 168,
    32, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401262359T202401270400/FNO/10T4' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401262359T202401270400/FNO/10T4', N'EWR', 1,
    93, 72, 165,
    51, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401262359T202401270400/FNO/10T4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401262359T202401270400/FNO/10T4', N'PVD', 1,
    51, 48, 99,
    29, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401262359T202401270400/FNO/10T4' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401262359T202401270400/FNO/10T4', N'BOS', 1,
    193, 179, 372,
    32, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401262359T202401270400/FNO/10T4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202401312359T202402010300/MWK/VZD1', N'RDU', 1,
    87, 64, 151,
    50, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202401312359T202402010300/MWK/VZD1' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402012359T202402020400/MWK/WIN2', N'MSP', 1,
    97, 92, 189,
    54, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402012359T202402020400/MWK/WIN2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402022359T202402030400/FNO/GAT2', N'STL', 1,
    152, 124, 276,
    64, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402022359T202402030400/FNO/GAT2' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402022359T202402030400/FNO/GAT2', N'CVG', 1,
    71, 54, 125,
    60, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402022359T202402030400/FNO/GAT2' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402040100T202402040500/SUN/LIO2', N'SFO', 1,
    119, 109, 228,
    40, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402040100T202402040500/SUN/LIO2' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402092359T202402100400/FNO/SUN4', N'JAX', 1,
    114, 92, 206,
    30, N'2300Z',
    3, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402092359T202402100400/FNO/SUN4' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402092359T202402100400/FNO/SUN4', N'DAB', 1,
    59, 44, 103,
    30, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402092359T202402100400/FNO/SUN4' AND airport_icao = N'DAB');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402092359T202402100400/FNO/SUN4', N'PNS', 1,
    46, 53, 99,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402092359T202402100400/FNO/SUN4' AND airport_icao = N'PNS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402092359T202402100400/FNO/SUN4', N'MYR', 1,
    52, 37, 89,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402092359T202402100400/FNO/SUN4' AND airport_icao = N'MYR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402102359T202402110400/SAT/SUP3', N'LAS', 1,
    281, 137, 418,
    60, N'2300Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402102359T202402110400/SAT/SUP3' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402132359T202402140200/MWK/TUE1', N'EWR', 1,
    84, 69, 153,
    40, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402132359T202402140200/MWK/TUE1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402162359T202402170400/FNO/FEE2', N'DFW', 1,
    133, 96, 229,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402162359T202402170400/FNO/FEE2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402162359T202402170400/FNO/FEE2', N'DAL', 1,
    122, 76, 198,
    20, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402162359T202402170400/FNO/FEE2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402172359T202402180400/SAT/DE-1', N'DTW', 1,
    168, 117, 285,
    76, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402172359T202402180400/SAT/DE-1' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402232359T202402240400/FNO/PAR3', N'BWI', 1,
    87, 68, 155,
    35, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402232359T202402240400/FNO/PAR3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402232359T202402240400/FNO/PAR3', N'JFK', 1,
    129, 95, 224,
    48, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402232359T202402240400/FNO/PAR3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402232359T202402240400/FNO/PAR3', N'BOS', 1,
    146, 117, 263,
    32, N'2300Z',
    5, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402232359T202402240400/FNO/PAR3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402262359T202402270300/MWK/MIL1', N'MKE', 1,
    49, 50, 99,
    32, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402262359T202402270300/MWK/MIL1' AND airport_icao = N'MKE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402272359T202402280300/MWK/BVA2', N'BOS', 1,
    101, 108, 209,
    40, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402272359T202402280300/MWK/BVA2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202402272359T202402280300/MWK/BVA2', N'ALB', 1,
    39, 32, 71,
    24, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202402272359T202402280300/MWK/BVA2' AND airport_icao = N'ALB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403052359T202403060200/MWK/TUE1', N'JFK', 1,
    89, 63, 152,
    32, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403052359T202403060200/MWK/TUE1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403090100T202403090500/FNO/THE3', N'LAX', 1,
    212, 156, 368,
    74, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403090100T202403090500/FNO/THE3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403090100T202403090500/FNO/THE3', N'BUR', 1,
    71, 53, 124,
    36, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403090100T202403090500/FNO/THE3' AND airport_icao = N'BUR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403090100T202403090500/FNO/THE3', N'SAN', 1,
    85, 64, 149,
    27, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403090100T202403090500/FNO/THE3' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403092359T202403100400/LIVE/HOU1', N'IAH', 1,
    183, 127, 310,
    75, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403092359T202403100400/LIVE/HOU1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403101800T202403102200/SUN/IN 1', N'EWR', 1,
    116, 74, 190,
    40, N'1700Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403101800T202403102200/SUN/IN 1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403112300T202403120200/MWK/MID1', N'MDW', 1,
    84, 71, 155,
    36, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403112300T202403120200/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403142300T202403150200/MWK/FLY2', N'BOS', 1,
    91, 91, 182,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403142300T202403150200/MWK/FLY2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403142300T202403150200/MWK/FLY2', N'BTV', 1,
    34, 38, 72,
    25, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403142300T202403150200/MWK/FLY2' AND airport_icao = N'BTV');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'ATL', 1,
    131, 196, 327,
    50, N'2200Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'CLT', 1,
    63, 72, 135,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'MCO', 1,
    106, 72, 178,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'JAX', 1,
    41, 43, 84,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'MIA', 1,
    143, 124, 267,
    64, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403152300T202403160300/FNO/SPR6', N'FLL', 1,
    69, 45, 114,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403152300T202403160300/FNO/SPR6' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403222300T202403230300/FNO/NOR3', N'DTW', 1,
    78, 106, 184,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403222300T202403230300/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403222300T202403230300/FNO/NOR3', N'ORD', 1,
    169, 101, 270,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403222300T202403230300/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403222300T202403230300/FNO/NOR3', N'MSP', 1,
    108, 117, 225,
    53, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403222300T202403230300/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403292259T202403300300/FNO/BLA3', N'DEN', 1,
    140, 134, 274,
    96, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403292259T202403300300/FNO/BLA3' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403292259T202403300300/FNO/BLA3', N'PHX', 1,
    104, 125, 229,
    66, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403292259T202403300300/FNO/BLA3' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403292259T202403300300/FNO/BLA3', N'ABQ', 1,
    41, 69, 110,
    44, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403292259T202403300300/FNO/BLA3' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403302329T202403310330/SAT/VZD1', N'DCA', 1,
    140, 71, 211,
    30, N'2200Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403302329T202403310330/SAT/VZD1' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202403302330T202403310330/SAT/SPR1', N'SEA', 1,
    82, 89, 171,
    38, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202403302330T202403310330/SAT/SPR1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'MIA', 1,
    54, 74, 128,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'TPA', 1,
    43, 45, 88,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'MCO', 1,
    39, 42, 81,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'ATL', 1,
    99, 117, 216,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'MEM', 1,
    25, 39, 64,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'IAH', 1,
    43, 32, 75,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'DFW', 1,
    27, 43, 70,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'PHX', 1,
    35, 62, 97,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'LAX', 1,
    75, 98, 173,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404052300T202404060400/FNO/SEA10', N'SAN', 1,
    44, 47, 91,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404052300T202404060400/FNO/SEA10' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'BOS', 1,
    98, 93, 191,
    36, N'2200Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'CLE', 1,
    20, 22, 42,
    43, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'JFK', 1,
    45, 39, 84,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'EWR', 1,
    26, 27, 53,
    51, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'LGA', 1,
    48, 26, 74,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'PHL', 1,
    9, 13, 22,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'DCA', 1,
    51, 42, 93,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'BWI', 1,
    15, 9, 24,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'IAD', 1,
    13, 17, 30,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'YYZ', 1,
    43, 44, 87,
    62, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404062300T202404070300/SAT/HON11', N'YUL', 1,
    25, 27, 52,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404062300T202404070300/SAT/HON11' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404102300T202404110200/MWK/TRA3', N'FLL', 1,
    41, 41, 82,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404102300T202404110200/MWK/TRA3' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404102300T202404110200/MWK/TRA3', N'TPA', 1,
    45, 32, 77,
    58, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404102300T202404110200/MWK/TRA3' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404102300T202404110200/MWK/TRA3', N'RSW', 1,
    23, 25, 48,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404102300T202404110200/MWK/TRA3' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404112330T202404120230/MWK/MAS2', N'AGS', 1,
    52, 29, 81,
    16, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404112330T202404120230/MWK/MAS2' AND airport_icao = N'AGS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404112330T202404120230/MWK/MAS2', N'ATL', 1,
    68, 92, 160,
    84, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404112330T202404120230/MWK/MAS2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404122300T202404130300/FNO/ORD2', N'ORD', 1,
    209, 144, 353,
    90, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404122300T202404130300/FNO/ORD2' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404122300T202404130300/FNO/ORD2', N'MDW', 1,
    34, 32, 66,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404122300T202404130300/FNO/ORD2' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404131200T202404140500/RLOP/ATL1', N'ATL', 1,
    348, 395, 743,
    60, N'1100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404131200T202404140500/RLOP/ATL1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'YUL', 1,
    135, 40, 175,
    42, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'YYZ', 1,
    154, 53, 207,
    66, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'BOS', 1,
    164, 113, 277,
    36, N'1600Z',
    5, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'IAD', 1,
    201, 48, 249,
    64, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'JFK', 1,
    174, 83, 257,
    58, N'1600Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'ORD', 1,
    195, 78, 273,
    96, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404201800T202404210200/CTP/CRO11', N'SJU', 1,
    52, 40, 92,
    40, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404201800T202404210200/CTP/CRO11' AND airport_icao = N'SJU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404272300T202404280300/LIVE/ORL1', N'MCO', 1,
    259, 139, 398,
    76, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404272300T202404280300/LIVE/ORL1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'SFO', 1,
    92, 70, 162,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'OAK', 1,
    26, 22, 48,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'SJC', 1,
    17, 30, 47,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'LAX', 1,
    133, 107, 240,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'SAN', 1,
    44, 68, 112,
    24, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202404282300T202404290300/RLOP/CAL6', N'LAS', 1,
    82, 87, 169,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'DFW', 1,
    91, 88, 179,
    85, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'DAL', 1,
    31, 28, 59,
    33, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'IAH', 1,
    60, 55, 115,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'HOU', 1,
    28, 24, 52,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'AUS', 1,
    36, 29, 65,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'SAT', 1,
    14, 23, 37,
    39, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'SAT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'PHX', 1,
    28, 50, 78,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405032300T202405040300/FNO/LON8', N'ABQ', 1,
    29, 43, 72,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405032300T202405040300/FNO/LON8' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405042000T202405050000/SAT/MAY2', N'ATL', 1,
    129, 87, 216,
    88, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405042000T202405050000/SAT/MAY2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405042000T202405050000/SAT/MAY2', N'MCO', 1,
    46, 132, 178,
    80, N'1900Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405042000T202405050000/SAT/MAY2' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405072300T202405080100/MWK/TUE1', N'LGA', 1,
    89, 57, 146,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405072300T202405080100/MWK/TUE1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405182300T202405190300/SAT/SHA1', N'ATL', 1,
    197, 175, 372,
    88, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405182300T202405190300/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405242300T202405250300/FNO/MEM1', N'MEM', 1,
    183, 140, 323,
    66, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405242300T202405250300/FNO/MEM1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405312300T202406010300/FNO/FLO4', N'MCO', 1,
    88, 60, 148,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405312300T202406010300/FNO/FLO4' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405312300T202406010300/FNO/FLO4', N'MIA', 1,
    155, 153, 308,
    64, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405312300T202406010300/FNO/FLO4' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405312300T202406010300/FNO/FLO4', N'JAX', 1,
    53, 48, 101,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405312300T202406010300/FNO/FLO4' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202405312300T202406010300/FNO/FLO4', N'RSW', 1,
    22, 25, 47,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202405312300T202406010300/FNO/FLO4' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406011900T202406012300/SAT/MAT4', N'SFO', 1,
    113, 121, 234,
    33, N'1800Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406011900T202406012300/SAT/MAT4' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406011900T202406012300/SAT/MAT4', N'OAK', 1,
    10, 28, 38,
    45, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406011900T202406012300/SAT/MAT4' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406011900T202406012300/SAT/MAT4', N'LAX', 1,
    76, 95, 171,
    68, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406011900T202406012300/SAT/MAT4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406011900T202406012300/SAT/MAT4', N'SAN', 1,
    39, 60, 99,
    24, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406011900T202406012300/SAT/MAT4' AND airport_icao = N'SAN');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406012300T202406020300/LIVE/ZFW2', N'DFW', 1,
    128, 93, 221,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406012300T202406020300/LIVE/ZFW2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406012300T202406020300/LIVE/ZFW2', N'DAL', 1,
    52, 48, 100,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406012300T202406020300/LIVE/ZFW2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406072300T202406080300/FNO/EMP6', N'MSP', 1,
    72, 67, 139,
    48, N'0100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406072300T202406080300/FNO/EMP6' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406072300T202406080300/FNO/EMP6', N'SEA', 1,
    79, 114, 193,
    38, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406072300T202406080300/FNO/EMP6' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406081400T202406082100/LIVE/MAN4', N'JFK', 1,
    172, 202, 374,
    58, N'1300Z',
    6, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406081400T202406082100/LIVE/MAN4' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406081400T202406082100/LIVE/MAN4', N'EWR', 1,
    36, 56, 92,
    38, N'1400Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406081400T202406082100/LIVE/MAN4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406081400T202406082100/LIVE/MAN4', N'LGA', 1,
    85, 77, 162,
    36, N'1300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406081400T202406082100/LIVE/MAN4' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406081400T202406082100/LIVE/MAN4', N'PHL', 1,
    13, 24, 37,
    48, N'1300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406081400T202406082100/LIVE/MAN4' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406142300T202406150300/FNO/WE 2', N'IAH', 1,
    185, 130, 315,
    90, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406142300T202406150300/FNO/WE 2' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406142300T202406150300/FNO/WE 2', N'HOU', 1,
    48, 25, 73,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406142300T202406150300/FNO/WE 2' AND airport_icao = N'HOU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406152300T202406160300/LIVE/ZLA6', N'LAX', 1,
    151, 142, 293,
    68, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406152300T202406160300/LIVE/ZLA6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406152300T202406160300/LIVE/ZLA6', N'SAN', 1,
    43, 55, 98,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406152300T202406160300/LIVE/ZLA6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406282300T202406290300/FNO/MIL1', N'DEN', 1,
    192, 132, 324,
    96, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406282300T202406290300/FNO/MIL1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406292330T202406300330/SAT/VZD3', N'DCA', 1,
    80, 41, 121,
    30, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406292330T202406300330/SAT/VZD3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406292330T202406300330/SAT/VZD3', N'BWI', 1,
    29, 25, 54,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406292330T202406300330/SAT/VZD3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202406292330T202406300330/SAT/VZD3', N'IAD', 1,
    102, 59, 161,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202406292330T202406300330/SAT/VZD3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407022300T202407030200/MWK/NTH1', N'LGA', 1,
    82, 60, 142,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407022300T202407030200/MWK/NTH1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'LAX', 1,
    152, 154, 306,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'SAN', 1,
    48, 43, 91,
    24, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'LAS', 1,
    60, 74, 134,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'DEN', 1,
    47, 88, 135,
    96, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'SLC', 1,
    47, 51, 98,
    62, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407122359T202407130400/FNO/MOU4', N'PHX', 1,
    36, 56, 92,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407122359T202407130400/FNO/MOU4' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407132200T202407140300/SAT/THE1', N'ATL', 1,
    231, 165, 396,
    80, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407132200T202407140300/SAT/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407202300T202407210300/SAT/ZMP1', N'MSP', 1,
    188, 109, 297,
    56, N'0200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407202300T202407210300/SAT/ZMP1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407262300T202407270200/FNO/SUM2', N'DFW', 1,
    178, 123, 301,
    92, N'0200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407262300T202407270200/FNO/SUM2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407262300T202407270200/FNO/SUM2', N'DAL', 1,
    73, 46, 119,
    32, N'0100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407262300T202407270200/FNO/SUM2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202407312300T202408010200/MWK/THE1', N'MCO', 1,
    138, 105, 243,
    72, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202407312300T202408010200/MWK/THE1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408022300T202408030300/FNO/THI5', N'CVG', 1,
    52, 55, 107,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408022300T202408030300/FNO/THI5' AND airport_icao = N'CVG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408022300T202408030300/FNO/THI5', N'CLE', 1,
    99, 79, 178,
    43, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408022300T202408030300/FNO/THI5' AND airport_icao = N'CLE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408032300T202408040300/LIVE/ZOA6', N'SFO', 1,
    188, 129, 317,
    40, N'2200Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408032300T202408040300/LIVE/ZOA6' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408032300T202408040300/LIVE/ZOA6', N'OAK', 1,
    41, 27, 68,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408032300T202408040300/LIVE/ZOA6' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408032300T202408040300/LIVE/ZOA6', N'SJC', 1,
    24, 11, 35,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408032300T202408040300/LIVE/ZOA6' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408101600T202408102200/LIVE/BOS4', N'BOS', 1,
    209, 185, 394,
    40, N'1500Z',
    6, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408101600T202408102200/LIVE/BOS4' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408142300T202408150200/MWK/WIN3', N'ORD', 1,
    106, 122, 228,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408142300T202408150200/MWK/WIN3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408172300T202408180300/SAT/END1', N'LAX', 1,
    174, 141, 315,
    68, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408172300T202408180300/SAT/END1' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408232300T202408240300/FNO/SUN4', N'JAX', 1,
    75, 48, 123,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408232300T202408240300/FNO/SUN4' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408232300T202408240300/FNO/SUN4', N'TLH', 1,
    15, 11, 26,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408232300T202408240300/FNO/SUN4' AND airport_icao = N'TLH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408232300T202408240300/FNO/SUN4', N'SAV', 1,
    40, 10, 50,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408232300T202408240300/FNO/SUN4' AND airport_icao = N'SAV');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408232300T202408240300/FNO/SUN4', N'DAB', 1,
    35, 31, 66,
    35, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408232300T202408240300/FNO/SUN4' AND airport_icao = N'DAB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408262300T202408270200/MWK/GT 1', N'ATL', 1,
    82, 81, 163,
    84, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408262300T202408270200/MWK/GT 1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408282300T202408290300/MWK/FAI2', N'MSP', 1,
    90, 74, 164,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408282300T202408290300/MWK/FAI2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202408312359T202409010300/SAT/HAY2', N'DTW', 1,
    107, 64, 171,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202408312359T202409010300/SAT/HAY2' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409062300T202409070300/FNO/THE1', N'ATL', 1,
    253, 191, 444,
    88, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409062300T202409070300/FNO/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409071900T202409072300/LIVE/AUS4', N'AUS', 1,
    115, 132, 247,
    40, N'1800Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409071900T202409072300/LIVE/AUS4' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'SEA', 1,
    41, 61, 102,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'PDX', 1,
    37, 49, 86,
    50, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'SFO', 1,
    97, 118, 215,
    36, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'LAX', 1,
    127, 130, 257,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'SAN', 1,
    45, 62, 107,
    24, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409132359T202409140400/FNO/LIG3', N'LAS', 1,
    75, 55, 130,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409132359T202409140400/FNO/LIG3' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409202300T202409210300/FNO/NAS2', N'BNA', 1,
    115, 134, 249,
    52, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409202300T202409210300/FNO/NAS2' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409202300T202409210300/FNO/NAS2', N'CLT', 1,
    154, 138, 292,
    80, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409202300T202409210300/FNO/NAS2' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409272259T202409280300/FNO/THE3', N'EWR', 1,
    53, 49, 102,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409272259T202409280300/FNO/THE3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409272259T202409280300/FNO/THE3', N'JFK', 1,
    139, 114, 253,
    48, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409272259T202409280300/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409272259T202409280300/FNO/THE3', N'LGA', 1,
    90, 44, 134,
    34, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409272259T202409280300/FNO/THE3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202409272300T202409280300/FNO/SLA1', N'SLC', 1,
    45, 40, 85,
    62, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202409272300T202409280300/FNO/SLA1' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410032300T202410040300/MWK/HAL1', N'MCO', 1,
    101, 102, 203,
    72, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410032300T202410040300/MWK/HAL1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410062300T202410070300/SUN/THE1', N'ORD', 1,
    126, 112, 238,
    90, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410062300T202410070300/SUN/THE1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410112300T202410120300/FNO/HIG3', N'LAX', 1,
    113, 132, 245,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410112300T202410120300/FNO/HIG3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410112300T202410120300/FNO/HIG3', N'SAN', 1,
    49, 79, 128,
    24, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410112300T202410120300/FNO/HIG3' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410112300T202410120300/FNO/HIG3', N'SFO', 1,
    145, 111, 256,
    35, N'2200Z',
    4, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410112300T202410120300/FNO/HIG3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410112300T202410120300/FNO/HIG3', N'SEA', 1,
    44, 56, 100,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410112300T202410120300/FNO/HIG3' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410121900T202410122300/SAT/THA2', N'MIA', 1,
    127, 127, 254,
    64, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410121900T202410122300/SAT/THA2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410121900T202410122300/SAT/THA2', N'FLL', 1,
    25, 26, 51,
    48, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410121900T202410122300/SAT/THA2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410162300T202410170200/MWK/FED1', N'IND', 1,
    86, 77, 163,
    48, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410162300T202410170200/MWK/FED1' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'YHZ', 1,
    14, 92, 106,
    24, N'0900Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'YHZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'YWG', 1,
    2, 117, 119,
    36, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'YWG');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'YYZ', 1,
    28, 187, 215,
    66, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'BOS', 1,
    43, 181, 224,
    54, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'EWR', 1,
    19, 127, 146,
    40, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410191000T202410191800/CTP/CRO10', N'MCO', 1,
    19, 160, 179,
    22, N'0900Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410191000T202410191800/CTP/CRO10' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410272300T202410280300/SUN/TRI1', N'SJC', 1,
    90, 40, 130,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410272300T202410280300/SUN/TRI1' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202410292359T202410300200/MWK/FLI1', N'PIT', 1,
    65, 40, 105,
    68, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202410292359T202410300200/MWK/FLI1' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411021700T202411022100/SAT/COA2', N'MIA', 1,
    104, 174, 278,
    64, N'1600Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411021700T202411022100/SAT/COA2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411022300T202411030300/SAT/CAL3', N'LAX', 1,
    165, 168, 333,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411022300T202411030300/SAT/CAL3' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411022300T202411030300/SAT/CAL3', N'ONT', 1,
    49, 16, 65,
    28, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411022300T202411030300/SAT/CAL3' AND airport_icao = N'ONT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411022300T202411030300/SAT/CAL3', N'SBD', 1,
    7, 3, 10,
    10, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411022300T202411030300/SAT/CAL3' AND airport_icao = N'SBD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411082359T202411090400/FNO/NIG1', N'DEN', 1,
    272, 181, 453,
    64, N'2300Z',
    4, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411082359T202411090400/FNO/NIG1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411092359T202411100400/SAT/SNO2', N'OKC', 1,
    116, 75, 191,
    50, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411092359T202411100400/SAT/SNO2' AND airport_icao = N'OKC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411101800T202411102100/SUN/BAK1', N'PHL', 1,
    78, 84, 162,
    44, N'1700Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411101800T202411102100/SUN/BAK1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411152359T202411160400/FNO/JOU1', N'DTW', 1,
    152, 122, 274,
    70, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411152359T202411160400/FNO/JOU1' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411222359T202411230400/FNO/UND8', N'MIA', 1,
    95, 140, 235,
    64, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411222359T202411230400/FNO/UND8' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411222359T202411230400/FNO/UND8', N'FLL', 1,
    36, 41, 77,
    48, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411222359T202411230400/FNO/UND8' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411232359T202411240400/LIVE/LIV3', N'ORD', 1,
    188, 121, 309,
    80, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411232359T202411240400/LIVE/LIV3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411232359T202411240400/LIVE/LIV3', N'MDW', 1,
    47, 29, 76,
    36, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411232359T202411240400/LIVE/LIV3' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411292300T202411300300/FNO/STU2', N'PHX', 1,
    132, 86, 218,
    66, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411292300T202411300300/FNO/STU2' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411292300T202411300300/FNO/STU2', N'ABQ', 1,
    83, 64, 147,
    44, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411292300T202411300300/FNO/STU2' AND airport_icao = N'ABQ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411302359T202412010400/SAT/RAC2', N'CLT', 1,
    103, 87, 190,
    60, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411302359T202412010400/SAT/RAC2' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202411302359T202412010400/SAT/RAC2', N'ATL', 1,
    149, 170, 319,
    76, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202411302359T202412010400/SAT/RAC2' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412012359T202412020400/SUN/VAT2', N'SJC', 1,
    47, 70, 117,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412012359T202412020400/SUN/VAT2' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412012359T202412020400/SUN/VAT2', N'BUR', 1,
    69, 58, 127,
    36, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412012359T202412020400/SUN/VAT2' AND airport_icao = N'BUR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412060100T202412060500/MWK/A N1', N'PDX', 1,
    69, 57, 126,
    50, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412060100T202412060500/MWK/A N1' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412062359T202412070400/FNO/THE5', N'BOS', 1,
    164, 150, 314,
    40, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412062359T202412070400/FNO/THE5' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412062359T202412070400/FNO/THE5', N'JFK', 1,
    52, 64, 116,
    58, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412062359T202412070400/FNO/THE5' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412062359T202412070400/FNO/THE5', N'DCA', 1,
    40, 38, 78,
    34, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412062359T202412070400/FNO/THE5' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412072359T202412080400/SAT/SAN1', N'IAH', 1,
    96, 74, 170,
    70, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412072359T202412080400/SAT/SAN1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412082359T202412090300/SUN/TRA1', N'TPA', 1,
    70, 49, 119,
    58, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412082359T202412090300/SUN/TRA1' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412140100T202412140500/FNO/SIE3', N'RNO', 1,
    60, 74, 134,
    30, N'0000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412140100T202412140500/FNO/SIE3' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412140100T202412140500/FNO/SIE3', N'LAS', 1,
    143, 143, 286,
    60, N'0000Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412140100T202412140500/FNO/SIE3' AND airport_icao = N'LAS');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412140100T202412140500/FNO/SIE3', N'SLC', 1,
    94, 79, 173,
    56, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412140100T202412140500/FNO/SIE3' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412142359T202412150400/SAT/NEV1', N'IAD', 1,
    140, 83, 223,
    60, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412142359T202412150400/SAT/NEV1' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412192359T202412200400/MWK/HOW2', N'MIA', 1,
    117, 117, 234,
    64, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412192359T202412200400/MWK/HOW2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412192359T202412200400/MWK/HOW2', N'TPA', 1,
    69, 62, 131,
    58, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412192359T202412200400/MWK/HOW2' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412202359T202412210400/FNO/HOM3', N'ORD', 1,
    178, 143, 321,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412202359T202412210400/FNO/HOM3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412202359T202412210400/FNO/HOM3', N'MDW', 1,
    67, 40, 107,
    28, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412202359T202412210400/FNO/HOM3' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412222359T202412230400/SUN/LAS2', N'MSP', 1,
    111, 101, 212,
    54, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412222359T202412230400/SUN/LAS2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202412282359T202412290400/SAT/GTO1', N'MCO', 1,
    207, 115, 322,
    50, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202412282359T202412290400/SAT/GTO1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501032359T202501040400/FNO/HOL3', N'IAH', 1,
    87, 114, 201,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501032359T202501040400/FNO/HOL3' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501032359T202501040400/FNO/HOL3', N'DFW', 1,
    111, 122, 233,
    85, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501032359T202501040400/FNO/HOL3' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501032359T202501040400/FNO/HOL3', N'MEM', 1,
    151, 108, 259,
    66, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501032359T202501040400/FNO/HOL3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501042359T202501050300/SAT/NEW3', N'EWR', 1,
    56, 38, 94,
    38, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501042359T202501050300/SAT/NEW3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501042359T202501050300/SAT/NEW3', N'JFK', 1,
    176, 164, 340,
    58, N'2300Z',
    5, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501042359T202501050300/SAT/NEW3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501042359T202501050300/SAT/NEW3', N'LGA', 1,
    67, 48, 115,
    36, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501042359T202501050300/SAT/NEW3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501112359T202501120400/SAT/TAM4', N'TPA', 1,
    157, 96, 253,
    58, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501112359T202501120400/SAT/TAM4' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501150100T202501150400/MWK/DE-1', N'DTW', 1,
    95, 66, 161,
    76, N'0000Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501150100T202501150400/MWK/DE-1' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501172359T202501180400/FNO/HON1', N'ATL', 1,
    317, 193, 510,
    96, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501172359T202501180400/FNO/HON1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501202359T202501210300/MWK/MID1', N'MDW', 1,
    95, 45, 140,
    28, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501202359T202501210300/MWK/MID1' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501242359T202501250400/FNO/FN 2', N'YYZ', 1,
    164, 120, 284,
    60, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501242359T202501250400/FNO/FN 2' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501242359T202501250400/FNO/FN 2', N'YUL', 1,
    56, 67, 123,
    40, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501242359T202501250400/FNO/FN 2' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501252359T202501260400/SAT/OPP3', N'SFO', 1,
    166, 154, 320,
    27, N'2300Z',
    5, 4, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501252359T202501260400/SAT/OPP3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501252359T202501260400/SAT/OPP3', N'OAK', 1,
    57, 41, 98,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501252359T202501260400/SAT/OPP3' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202501252359T202501260400/SAT/OPP3', N'SJC', 1,
    19, 16, 35,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202501252359T202501260400/SAT/OPP3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502012359T202502020400/SAT/WIN2', N'MSP', 1,
    116, 102, 218,
    55, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502012359T202502020400/SAT/WIN2' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502021800T202502022059/SUN/PUN1', N'PHL', 1,
    95, 83, 178,
    40, N'1700Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502021800T202502022059/SUN/PUN1' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502022100T202502030100/SUN/STA1', N'FLL', 1,
    112, 98, 210,
    44, N'2000Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502022100T202502030100/SUN/STA1' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502072359T202502080400/FNO/NOR3', N'BOS', 1,
    134, 153, 287,
    40, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502072359T202502080400/FNO/NOR3' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502072359T202502080400/FNO/NOR3', N'LGA', 1,
    99, 82, 181,
    32, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502072359T202502080400/FNO/NOR3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502072359T202502080400/FNO/NOR3', N'DCA', 1,
    147, 102, 249,
    30, N'2300Z',
    4, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502072359T202502080400/FNO/NOR3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502082359T202502090400/SAT/SUP2', N'MSY', 1,
    214, 98, 312,
    38, N'0300Z',
    4, 3, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502082359T202502090400/SAT/SUP2' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502142359T202502150400/FNO/FEE2', N'DFW', 1,
    136, 114, 250,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502142359T202502150400/FNO/FEE2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502142359T202502150400/FNO/FEE2', N'DAL', 1,
    90, 60, 150,
    30, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502142359T202502150400/FNO/FEE2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502212359T202502220400/FNO/8 M4', N'DTW', 1,
    224, 150, 374,
    76, N'2300Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502212359T202502220400/FNO/8 M4' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502252359T202502260300/MWK/BVA2', N'BOS', 1,
    118, 93, 211,
    36, N'2300Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502252359T202502260300/MWK/BVA2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502252359T202502260300/MWK/BVA2', N'SYR', 1,
    32, 47, 79,
    30, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502252359T202502260300/MWK/BVA2' AND airport_icao = N'SYR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202502282359T202503010400/FNO/BLU1', N'DEN', 1,
    255, 146, 401,
    96, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202502282359T202503010400/FNO/BLU1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503012359T202503020400/SAT/LAN1', N'CLT', 1,
    141, 99, 240,
    80, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503012359T202503020400/SAT/LAN1' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503052359T202503060300/MWK/WIN3', N'ORD', 1,
    114, 86, 200,
    64, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503052359T202503060300/MWK/WIN3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503052359T202503060300/MWK/WIN3', N'MDW', 1,
    41, 24, 65,
    36, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503052359T202503060300/MWK/WIN3' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503062359T202503070300/MWK/SPR1', N'IAH', 1,
    76, 91, 167,
    60, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503062359T202503070300/MWK/SPR1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'ORD', 1,
    60, 74, 134,
    64, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'DTW', 1,
    56, 84, 140,
    76, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'YYZ', 1,
    48, 69, 117,
    62, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'YUL', 1,
    31, 20, 51,
    42, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'JFK', 1,
    103, 125, 228,
    58, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503090030T202503090400/SUN/HOC6', N'BOS', 1,
    125, 219, 344,
    44, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503090030T202503090400/SUN/HOC6' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503092200T202503100100/SUN/RAL1', N'RDU', 1,
    85, 84, 169,
    50, N'2100Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503092200T202503100100/SUN/RAL1' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503142300T202503150300/FNO/FLO4', N'MCO', 1,
    84, 53, 137,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503142300T202503150300/FNO/FLO4' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503142300T202503150300/FNO/FLO4', N'TPA', 1,
    92, 51, 143,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503142300T202503150300/FNO/FLO4' AND airport_icao = N'TPA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503152359T202503160400/SAT/CRO2', N'SLC', 1,
    53, 50, 103,
    56, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503152359T202503160400/SAT/CRO2' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503152359T202503160400/SAT/CRO2', N'LAX', 1,
    200, 144, 344,
    68, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503152359T202503160400/SAT/CRO2' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503162300T202503170200/SUN/AUS1', N'AUS', 1,
    110, 88, 198,
    32, N'2200Z',
    3, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503162300T202503170200/SUN/AUS1' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503182300T202503190200/MWK/TUE1', N'TEB', 1,
    44, 36, 80,
    32, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503182300T202503190200/MWK/TUE1' AND airport_icao = N'TEB');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503212359T202503220400/FNO/CAS2', N'SEA', 1,
    60, 88, 148,
    37, N'2300Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503212359T202503220400/FNO/CAS2' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503212359T202503220400/FNO/CAS2', N'PDX', 1,
    43, 40, 83,
    40, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503212359T202503220400/FNO/CAS2' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503212359T202503220400/FNO/CAS2', N'SFO', 1,
    163, 132, 295,
    32, N'2300Z',
    4, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503212359T202503220400/FNO/CAS2' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503282300T202503290300/FNO/NOR3', N'MSP', 1,
    116, 119, 235,
    60, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503282300T202503290300/FNO/NOR3' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503282300T202503290300/FNO/NOR3', N'ORD', 1,
    105, 91, 196,
    92, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503282300T202503290300/FNO/NOR3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202503282300T202503290300/FNO/NOR3', N'DTW', 1,
    46, 53, 99,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202503282300T202503290300/FNO/NOR3' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504052300T202504062300/SAT/24 3', N'DCA', 1,
    319, 205, 524,
    34, N'2200Z',
    6, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504052300T202504062300/SAT/24 3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504102300T202504110200/MWK/BVA2', N'BOS', 1,
    109, 113, 222,
    36, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504102300T202504110200/MWK/BVA2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504102300T202504110200/MWK/BVA2', N'BGR', 1,
    32, 36, 68,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504102300T202504110200/MWK/BVA2' AND airport_icao = N'BGR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504112300T202504120300/FNO/NAV2', N'ORD', 1,
    172, 149, 321,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504112300T202504120300/FNO/NAV2' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504112300T202504120300/FNO/NAV2', N'MDW', 1,
    75, 47, 122,
    28, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504112300T202504120300/FNO/NAV2' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504172359T202504180200/MWK/MIS1', N'ISP', 1,
    35, 10, 45,
    32, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504172359T202504180200/MWK/MIS1' AND airport_icao = N'ISP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504192300T202504200300/SAT/SHA1', N'ATL', 1,
    247, 189, 436,
    72, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504192300T202504200300/SAT/SHA1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'YUL', 1,
    93, 38, 131,
    42, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'YVR', 1,
    60, 33, 93,
    44, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'YVR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'YYZ', 1,
    107, 40, 147,
    42, N'1700Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'YYZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'BOS', 1,
    157, 131, 288,
    32, N'1700Z',
    5, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'DTW', 1,
    101, 43, 144,
    84, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'IAD', 1,
    127, 46, 173,
    64, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'MCO', 1,
    130, 65, 195,
    76, N'1700Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'MIA', 1,
    159, 61, 220,
    64, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'ORD', 1,
    160, 46, 206,
    90, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'PHX', 1,
    82, 83, 165,
    66, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202504261800T202504270400/CTP/CRO13', N'SEA', 1,
    63, 37, 100,
    40, N'1700Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202504261800T202504270400/CTP/CRO13' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505022300T202505030300/FNO/MEM1', N'MEM', 1,
    199, 106, 305,
    76, N'0200Z',
    2, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505022300T202505030300/FNO/MEM1' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505032300T202505040300/RLOP/CAL8', N'SFO', 1,
    123, 146, 269,
    40, N'2200Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505032300T202505040300/RLOP/CAL8' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505032300T202505040300/RLOP/CAL8', N'LAX', 1,
    141, 172, 313,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505032300T202505040300/RLOP/CAL8' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505032300T202505040300/RLOP/CAL8', N'LAS', 1,
    70, 80, 150,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505032300T202505040300/RLOP/CAL8' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505032300T202505040300/RLOP/CAL8', N'SAN', 1,
    62, 61, 123,
    22, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505032300T202505040300/RLOP/CAL8' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505042200T202505050100/SUN/RET1', N'ATL', 1,
    200, 151, 351,
    92, N'2100Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505042200T202505050100/SUN/RET1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505082300T202505090200/MWK/MIL1', N'MKE', 1,
    67, 52, 119,
    32, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505082300T202505090200/MWK/MIL1' AND airport_icao = N'MKE');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505162300T202505170300/FNO/CEN3', N'EWR', 1,
    64, 44, 108,
    34, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505162300T202505170300/FNO/CEN3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505162300T202505170300/FNO/CEN3', N'JFK', 1,
    119, 111, 230,
    40, N'2200Z',
    3, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505162300T202505170300/FNO/CEN3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505162300T202505170300/FNO/CEN3', N'LGA', 1,
    73, 56, 129,
    32, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505162300T202505170300/FNO/CEN3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505162359T202505170400/FNO/SKY1', N'PHX', 1,
    88, 108, 196,
    66, N'2300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505162359T202505170400/FNO/SKY1' AND airport_icao = N'PHX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505172300T202505180300/SAT/IND2', N'SDF', 1,
    36, 24, 60,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505172300T202505180300/SAT/IND2' AND airport_icao = N'SDF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505172300T202505180300/SAT/IND2', N'IND', 1,
    116, 76, 192,
    40, N'2200Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505172300T202505180300/SAT/IND2' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505242300T202505250300/LIVE/COW1', N'IAH', 1,
    161, 115, 276,
    80, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505242300T202505250300/LIVE/COW1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505302300T202505310300/FNO/RIV5', N'BOI', 1,
    48, 88, 136,
    30, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505302300T202505310300/FNO/RIV5' AND airport_icao = N'BOI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505302300T202505310300/FNO/RIV5', N'GTF', 1,
    13, 14, 27,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505302300T202505310300/FNO/RIV5' AND airport_icao = N'GTF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505302300T202505310300/FNO/RIV5', N'MSO', 1,
    18, 27, 45,
    20, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505302300T202505310300/FNO/RIV5' AND airport_icao = N'MSO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505302300T202505310300/FNO/RIV5', N'PDX', 1,
    40, 48, 88,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505302300T202505310300/FNO/RIV5' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505302300T202505310300/FNO/RIV5', N'SEA', 1,
    144, 101, 245,
    40, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505302300T202505310300/FNO/RIV5' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202505312300T202506010300/LIVE/ORL1', N'MCO', 1,
    247, 147, 394,
    60, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202505312300T202506010300/LIVE/ORL1' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506071400T202506072100/LIVE/LIV4', N'EWR', 1,
    53, 55, 108,
    28, N'1300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506071400T202506072100/LIVE/LIV4' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506071400T202506072100/LIVE/LIV4', N'JFK', 1,
    233, 206, 439,
    44, N'2000Z',
    7, 3, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506071400T202506072100/LIVE/LIV4' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506071400T202506072100/LIVE/LIV4', N'LGA', 1,
    94, 132, 226,
    36, N'1300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506071400T202506072100/LIVE/LIV4' AND airport_icao = N'LGA');
GO
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506071400T202506072100/LIVE/LIV4', N'PHL', 1,
    12, 20, 32,
    30, N'1300Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506071400T202506072100/LIVE/LIV4' AND airport_icao = N'PHL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506072300T202506080300/LIVE/ZDV1', N'DEN', 1,
    221, 164, 385,
    96, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506072300T202506080300/LIVE/ZDV1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506132300T202506140300/FNO/VZD3', N'DCA', 1,
    152, 88, 240,
    26, N'2200Z',
    3, 3, 2
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506132300T202506140300/FNO/VZD3' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506132300T202506140300/FNO/VZD3', N'BWI', 1,
    55, 35, 90,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506132300T202506140300/FNO/VZD3' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506132300T202506140300/FNO/VZD3', N'IAD', 1,
    86, 55, 141,
    54, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506132300T202506140300/FNO/VZD3' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506142300T202506150300/LIVE/ZLA6', N'LAX', 1,
    181, 145, 326,
    68, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506142300T202506150300/LIVE/ZLA6' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506142300T202506150300/LIVE/ZLA6', N'SAN', 1,
    70, 66, 136,
    24, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506142300T202506150300/LIVE/ZLA6' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506142300T202506150300/LIVE/ZLA6', N'SNA', 1,
    22, 31, 53,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506142300T202506150300/LIVE/ZLA6' AND airport_icao = N'SNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506142300T202506150300/LIVE/ZLA6', N'BUR', 1,
    27, 16, 43,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506142300T202506150300/LIVE/ZLA6' AND airport_icao = N'BUR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506152300T202506160200/SUN/PRI2', N'SEA', 1,
    82, 68, 150,
    40, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506152300T202506160200/SUN/PRI2' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506152300T202506160200/SUN/PRI2', N'PDX', 1,
    47, 49, 96,
    50, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506152300T202506160200/SUN/PRI2' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506162300T202506170200/MWK/MIA2', N'MIA', 1,
    127, 107, 234,
    64, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506162300T202506170200/MWK/MIA2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506162300T202506170200/MWK/MIA2', N'FLL', 1,
    31, 17, 48,
    48, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506162300T202506170200/MWK/MIA2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506212300T202506220300/SAT/ZFW2', N'DFW', 1,
    116, 128, 244,
    72, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506212300T202506220300/SAT/ZFW2' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506212300T202506220300/SAT/ZFW2', N'DAL', 1,
    30, 13, 43,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506212300T202506220300/SAT/ZFW2' AND airport_icao = N'DAL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'PVD', 1,
    104, 46, 150,
    32, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'BOS', 1,
    99, 82, 181,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'DCA', 1,
    43, 67, 110,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'DCA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'EWR', 1,
    23, 50, 73,
    51, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'IAD', 1,
    22, 34, 56,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'JFK', 1,
    77, 81, 158,
    36, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'LGA', 1,
    26, 24, 50,
    34, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'YHZ', 1,
    3, 8, 11,
    24, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'YHZ');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506272300T202506280300/LIVE/(F|9', N'YUL', 1,
    26, 38, 64,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9' AND airport_icao = N'YUL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506281300T202506282200/LIVE/FLI2', N'BOS', 1,
    122, 194, 316,
    36, N'1200Z',
    4, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506281300T202506282200/LIVE/FLI2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506281300T202506282200/LIVE/FLI2', N'PVD', 1,
    80, 80, 160,
    24, N'1200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506281300T202506282200/LIVE/FLI2' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506291300T202506291900/LIVE/FLI2', N'BOS', 1,
    82, 139, 221,
    36, N'1200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506291300T202506291900/LIVE/FLI2' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202506291300T202506291900/LIVE/FLI2', N'PVD', 1,
    33, 64, 97,
    24, N'1200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202506291300T202506291900/LIVE/FLI2' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507012300T202507020200/MWK/NTH1', N'LGA', 1,
    96, 75, 171,
    36, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507012300T202507020200/MWK/NTH1' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507032359T202507040300/MWK/FED1', N'IND', 1,
    142, 80, 222,
    48, N'2300Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507032359T202507040300/MWK/FED1' AND airport_icao = N'IND');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507052300T202507060300/SAT/MIA1', N'MIA', 1,
    143, 166, 309,
    64, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507052300T202507060300/SAT/MIA1' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507062200T202507070200/SUN/SUM1', N'SBA', 1,
    72, 68, 140,
    30, N'2100Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507062200T202507070200/SUN/SUM1' AND airport_icao = N'SBA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507092300T202507100200/MWK/SUN1', N'SAV', 1,
    104, 50, 154,
    30, N'2200Z',
    3, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507092300T202507100200/MWK/SUN1' AND airport_icao = N'SAV');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507112300T202507120300/FNO/SAN3', N'SJU', 1,
    138, 79, 217,
    40, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507112300T202507120300/FNO/SAN3' AND airport_icao = N'SJU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507122300T202507130300/LIVE/ZSE1', N'SEA', 1,
    154, 127, 281,
    38, N'2200Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507122300T202507130300/LIVE/ZSE1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507182300T202507190300/FNO/SUM3', N'MSY', 1,
    77, 63, 140,
    45, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507182300T202507190300/FNO/SUM3' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507182300T202507190300/FNO/SUM3', N'MEM', 1,
    109, 108, 217,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507182300T202507190300/FNO/SUM3' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507182300T202507190300/FNO/SUM3', N'CLT', 1,
    140, 117, 257,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507182300T202507190300/FNO/SUM3' AND airport_icao = N'CLT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507192300T202507200300/LIVE/ZMP1', N'MSP', 1,
    234, 139, 373,
    60, N'0100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507192300T202507200300/LIVE/ZMP1' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507201900T202507202200/SUN/BIG2', N'BZN', 1,
    53, 45, 98,
    15, N'1800Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507201900T202507202200/SUN/BIG2' AND airport_icao = N'BZN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507201900T202507202200/SUN/BIG2', N'BOI', 1,
    43, 56, 99,
    25, N'1800Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507201900T202507202200/SUN/BIG2' AND airport_icao = N'BOI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507262200T202507270300/LIVE/THE1', N'ATL', 1,
    294, 251, 545,
    96, N'2100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507262200T202507270300/LIVE/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202507302359T202507310300/MWK/NOR1', N'PDX', 1,
    131, 78, 209,
    50, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202507302359T202507310300/MWK/NOR1' AND airport_icao = N'PDX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508012300T202508020300/FNO/TAI1', N'IAH', 1,
    243, 149, 392,
    90, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508012300T202508020300/FNO/TAI1' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508022300T202508030300/LIVE/STO3', N'SFO', 1,
    192, 159, 351,
    36, N'2200Z',
    5, 4, 3
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508022300T202508030300/LIVE/STO3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508022300T202508030300/LIVE/STO3', N'OAK', 1,
    57, 24, 81,
    45, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508022300T202508030300/LIVE/STO3' AND airport_icao = N'OAK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508022300T202508030300/LIVE/STO3', N'SJC', 1,
    38, 30, 68,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508022300T202508030300/LIVE/STO3' AND airport_icao = N'SJC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508091500T202508092100/LIVE/26T5', N'BOS', 1,
    252, 254, 506,
    54, N'1600Z',
    5, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508091500T202508092100/LIVE/26T5' AND airport_icao = N'BOS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508091500T202508092100/LIVE/26T5', N'BDL', 1,
    55, 56, 111,
    25, N'1400Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508091500T202508092100/LIVE/26T5' AND airport_icao = N'BDL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508091500T202508092100/LIVE/26T5', N'PVD', 1,
    72, 52, 124,
    24, N'1400Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508091500T202508092100/LIVE/26T5' AND airport_icao = N'PVD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508091500T202508092100/LIVE/26T5', N'PWM', 1,
    34, 52, 86,
    30, N'1400Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508091500T202508092100/LIVE/26T5' AND airport_icao = N'PWM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508091500T202508092100/LIVE/26T5', N'ACK', 1,
    37, 43, 80,
    20, N'1400Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508091500T202508092100/LIVE/26T5' AND airport_icao = N'ACK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508092100T202508100300/LIVE/PAR1', N'BNA', 1,
    177, 150, 327,
    56, N'2000Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508092100T202508100300/LIVE/PAR1' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508101900T202508102200/SUN/SAL1', N'SLC', 1,
    104, 107, 211,
    72, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508101900T202508102200/SUN/SAL1' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508152300T202508160300/FNO/NOR3', N'SEA', 1,
    125, 131, 256,
    32, N'2200Z',
    3, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508152300T202508160300/FNO/NOR3' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508152300T202508160300/FNO/NOR3', N'RNO', 1,
    60, 72, 132,
    40, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508152300T202508160300/FNO/NOR3' AND airport_icao = N'RNO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508152300T202508160300/FNO/NOR3', N'SMF', 1,
    45, 38, 83,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508152300T202508160300/FNO/NOR3' AND airport_icao = N'SMF');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508161559T202508162100/LIVE/GRE2', N'MIA', 1,
    125, 199, 324,
    64, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508161559T202508162100/LIVE/GRE2' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508161559T202508162100/LIVE/GRE2', N'FLL', 1,
    22, 41, 63,
    48, N'1500Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508161559T202508162100/LIVE/GRE2' AND airport_icao = N'FLL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508161600T202508172200/SAT/30 1', N'EWR', 1,
    657, 610, 1267,
    30, N'1500Z',
    22, 6, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508161600T202508172200/SAT/30 1' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508222300T202508230400/FNO/JOU1', N'DTW', 1,
    242, 154, 396,
    96, N'0100Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508222300T202508230400/FNO/JOU1' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202508232300T202508240300/SAT/ZHU4', N'IAH', 1,
    140, 105, 245,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202508232300T202508240300/SAT/ZHU4' AND airport_icao = N'IAH');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509052300T202509060300/FNO/THE1', N'ATL', 1,
    274, 175, 449,
    90, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509052300T202509060300/FNO/THE1' AND airport_icao = N'ATL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509072300T202509080300/SUN/GOP5', N'MSP', 1,
    59, 92, 151,
    44, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509072300T202509080300/SUN/GOP5' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509072300T202509080300/SUN/GOP5', N'ORD', 1,
    95, 43, 138,
    80, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509072300T202509080300/SUN/GOP5' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509072300T202509080300/SUN/GOP5', N'DTW', 1,
    39, 59, 98,
    76, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509072300T202509080300/SUN/GOP5' AND airport_icao = N'DTW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509122300T202509130300/FNO/RUS4', N'LAX', 1,
    229, 152, 381,
    74, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509122300T202509130300/FNO/RUS4' AND airport_icao = N'LAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509122300T202509130300/FNO/RUS4', N'SAN', 1,
    77, 86, 163,
    24, N'2200Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509122300T202509130300/FNO/RUS4' AND airport_icao = N'SAN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509192300T202509200300/FNO/MET1', N'DFW', 1,
    190, 157, 347,
    96, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509192300T202509200300/FNO/MET1' AND airport_icao = N'DFW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509212300T202509220300/SUN/THE1', N'ORD', 1,
    194, 94, 288,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509212300T202509220300/SUN/THE1' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509262259T202509270300/FNO/THE1', N'SEA', 1,
    96, 49, 145,
    35, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509262259T202509270300/FNO/THE1' AND airport_icao = N'SEA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509262300T202509270300/FNO/THE3', N'EWR', 1,
    52, 38, 90,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509262300T202509270300/FNO/THE3' AND airport_icao = N'EWR');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509262300T202509270300/FNO/THE3', N'LGA', 1,
    61, 34, 95,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509262300T202509270300/FNO/THE3' AND airport_icao = N'LGA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509262300T202509270300/FNO/THE3', N'JFK', 1,
    139, 97, 236,
    36, N'2200Z',
    4, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509262300T202509270300/FNO/THE3' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509272300T202509280300/SAT/3054', N'MIA', 1,
    111, 151, 262,
    64, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509272300T202509280300/SAT/3054' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202509272300T202509280300/SAT/3054', N'SJU', 1,
    50, 38, 88,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202509272300T202509280300/SAT/3054' AND airport_icao = N'SJU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510012300T202510020200/MWK/A W1', N'BWI', 1,
    76, 65, 141,
    35, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510012300T202510020200/MWK/A W1' AND airport_icao = N'BWI');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510032300T202510040300/FNO/BIG5', N'MSP', 1,
    129, 146, 275,
    60, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510032300T202510040300/FNO/BIG5' AND airport_icao = N'MSP');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510032300T202510040300/FNO/BIG5', N'STL', 1,
    68, 58, 126,
    64, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510032300T202510040300/FNO/BIG5' AND airport_icao = N'STL');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510032300T202510040300/FNO/BIG5', N'MEM', 1,
    47, 48, 95,
    66, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510032300T202510040300/FNO/BIG5' AND airport_icao = N'MEM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510032300T202510040300/FNO/BIG5', N'MSY', 1,
    47, 41, 88,
    30, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510032300T202510040300/FNO/BIG5' AND airport_icao = N'MSY');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510042330T202510050130/SAT/24 2', N'LAS', 1,
    77, 159, 236,
    60, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510042330T202510050130/SAT/24 2' AND airport_icao = N'LAS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510042330T202510050130/SAT/24 2', N'DEN', 1,
    120, 66, 186,
    96, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510042330T202510050130/SAT/24 2' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510112300T202510120300/LIVE/VZD3', N'ILM', 1,
    45, 38, 83,
    26, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510112300T202510120300/LIVE/VZD3' AND airport_icao = N'ILM');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510112300T202510120300/LIVE/VZD3', N'RDU', 1,
    132, 111, 243,
    50, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510112300T202510120300/LIVE/VZD3' AND airport_icao = N'RDU');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510162300T202510170100/MWK/F1E4', N'AUS', 1,
    30, 97, 127,
    48, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510162300T202510170100/MWK/F1E4' AND airport_icao = N'AUS');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510172300T202510180300/FNO/NIG1', N'DEN', 1,
    262, 187, 449,
    96, N'2200Z',
    2, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510172300T202510180300/FNO/NIG1' AND airport_icao = N'DEN');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510182300T202510190300/LIVE/LIV3', N'ORD', 1,
    219, 133, 352,
    92, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510182300T202510190300/LIVE/LIV3' AND airport_icao = N'ORD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510182300T202510190300/LIVE/LIV3', N'MDW', 1,
    58, 48, 106,
    36, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510182300T202510190300/LIVE/LIV3' AND airport_icao = N'MDW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510242300T202510250300/FNO/FLO4', N'MIA', 1,
    173, 115, 288,
    64, N'2200Z',
    1, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510242300T202510250300/FNO/FLO4' AND airport_icao = N'MIA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510242300T202510250300/FNO/FLO4', N'MCO', 1,
    94, 71, 165,
    55, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510242300T202510250300/FNO/FLO4' AND airport_icao = N'MCO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510242300T202510250300/FNO/FLO4', N'RSW', 1,
    34, 30, 64,
    32, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510242300T202510250300/FNO/FLO4' AND airport_icao = N'RSW');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510242300T202510250300/FNO/FLO4', N'JAX', 1,
    30, 32, 62,
    40, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510242300T202510250300/FNO/FLO4' AND airport_icao = N'JAX');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510252300T202510260300/SAT/RNG1', N'JFK', 1,
    216, 154, 370,
    58, N'2200Z',
    6, 2, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510252300T202510260300/SAT/RNG1' AND airport_icao = N'JFK');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510261900T202510262300/SUN/SAL1', N'SLC', 1,
    78, 88, 166,
    62, N'1800Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510261900T202510262300/SUN/SAL1' AND airport_icao = N'SLC');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510262300T202510270300/SUN/TRI3', N'SFO', 1,
    166, 87, 253,
    40, N'2200Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510262300T202510270300/SUN/TRI3' AND airport_icao = N'SFO');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202510282300T202510290300/MWK/FLI1', N'PIT', 1,
    74, 43, 117,
    68, N'2200Z',
    NULL, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202510282300T202510290300/MWK/FLI1' AND airport_icao = N'PIT');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202511072359T202511080400/FNO/NEV1', N'IAD', 1,
    246, 141, 387,
    64, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511072359T202511080400/FNO/NEV1' AND airport_icao = N'IAD');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202511142359T202511150400/FNO/NAS2', N'BNA', 1,
    179, 122, 301,
    52, N'2300Z',
    3, 1, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511142359T202511150400/FNO/NAS2' AND airport_icao = N'BNA');
INSERT INTO dbo.vatusa_event_airport (
    event_idx, airport_icao, is_featured, total_arrivals, total_departures, total_operations,
    peak_vatsim_aar, peak_hour_utc, hours_above_50pct, hours_above_75pct, hours_above_90pct
) SELECT N'202511142359T202511150400/FNO/NAS2', N'CLT', 1,
    176, 149, 325,
    80, N'2300Z',
    3, NULL, NULL
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event_airport WHERE event_idx = N'202511142359T202511150400/FNO/NAS2' AND airport_icao = N'CLT');
GO

GO
PRINT 'Airport summaries inserted.';
GO
