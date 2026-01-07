-- ============================================================================
-- VATUSA Event Statistics - Events Import (Part 1 of 3+)
-- Generated: 2026-01-07 05:44:36
-- ============================================================================

SET NOCOUNT ON;
GO

PRINT 'Importing 1187 events...';
GO

INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003062359T202003070400/FNO/FNO1', N'FNO Over the Mountains ZSE FNO', N'FNO', N'FNO1',
    '2020-03-06 23:59:00', '2020-03-07 04:00:00', N'Fri',
    159, 76, 235, 1,
    350, 350, 700, 33.57,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003062359T202003070400/FNO/FNO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003072359T202003080400/SAT/FOR1', N'Fort Myers Fly-In', N'SAT', N'FOR1',
    '2020-03-07 23:59:00', '2020-03-08 04:00:00', N'Sat',
    95, 45, 140, 1,
    224, 210, 434, 32.26,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003072359T202003080400/SAT/FOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003102359T202003110200/MWK/TRA2', N'Train it up Tuesdays feat. KCYS and KEGE', N'MWK', N'TRA2',
    '2020-03-10 23:59:00', '2020-03-11 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003102359T202003110200/MWK/TRA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003130100T202003130400/MWK/BVA2', N'BVA Regional Circuit: ACK and BOS', N'MWK', N'BVA2',
    '2020-03-13 01:00:00', '2020-03-13 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003130100T202003130400/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003132300T202003140300/FNO/FRI1', N'Friday the 13th FNO', N'FNO', N'FRI1',
    '2020-03-13 23:00:00', '2020-03-14 03:00:00', N'Fri',
    186, 98, 284, 1,
    364, 364, 728, 39.01,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003132300T202003140300/FNO/FRI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003142359T202003150300/SAT/MEM1', N'Memphis Madness', N'SAT', N'MEM1',
    '2020-03-14 23:59:00', '2020-03-15 03:00:00', N'Sat',
    82, 40, 122, 1,
    644, 644, 1288, 9.47,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003142359T202003150300/SAT/MEM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003151800T202003152200/SUN/HCF5', N'HCF Presents: VFR Hawaii', N'SUN', N'HCF5',
    '2020-03-15 18:00:00', '2020-03-15 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003151800T202003152200/SUN/HCF5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003152201T202003160100/SUN/BE 2', N'Be Seen in Green KGSP & KGSO', N'SUN', N'BE 2',
    '2020-03-15 22:01:00', '2020-03-16 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003152201T202003160100/SUN/BE 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003202300T202003210300/FNO/A B1', N'A Beautiful Day in the Neighborhood FNO', N'FNO', N'A B1',
    '2020-03-20 23:00:00', '2020-03-21 03:00:00', N'Fri',
    175, 91, 266, 1,
    560, 560, 1120, 23.75,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003202300T202003210300/FNO/A B1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003212300T202003220300/SAT/SOU4', N'Southern Spring Showdown', N'SAT', N'SOU4',
    '2020-03-21 23:00:00', '2020-03-22 03:00:00', N'Sat',
    308, 270, 578, 4,
    2002, 1638, 3640, 15.88,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003212300T202003220300/SAT/SOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003222100T202003230100/SUN/ZLA3', N'ZLA Presents: Empire Sunday', N'SUN', N'ZLA3',
    '2020-03-22 21:00:00', '2020-03-23 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003222100T202003230100/SUN/ZLA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003250100T202003250400/MWK/BVA1', N'BVA Pack the Pattern: PSM', N'MWK', N'BVA1',
    '2020-03-25 01:00:00', '2020-03-25 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003250100T202003250400/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003272300T202003280300/FNO/LET3', N'Lets go to Fresno FNO', N'FNO', N'LET3',
    '2020-03-27 23:00:00', '2020-03-28 03:00:00', N'Fri',
    237, 64, 301, 3,
    630, 630, 1260, 23.89,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003272300T202003280300/FNO/LET3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003282330T202003290330/SAT/ZDC1', N'ZDC Presents: Cherry Blossom', N'SAT', N'ZDC1',
    '2020-03-28 23:30:00', '2020-03-29 03:30:00', N'Sat',
    182, 73, 255, 1,
    288, 288, 576, 44.27,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003282330T202003290330/SAT/ZDC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003292200T202003300200/SUN/GRE6', N'Great Lakes Dash', N'SUN', N'GRE6',
    '2020-03-29 22:00:00', '2020-03-30 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003292200T202003300200/SUN/GRE6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202003302300T202003310300/MWK/MON1', N'Monroe Monday', N'MWK', N'MON1',
    '2020-03-30 23:00:00', '2020-03-31 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202003302300T202003310300/MWK/MON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004032359T202004040400/FNO/RIS1', N'Rise of the SIDs FNO', N'FNO', N'RIS1',
    '2020-04-03 23:59:00', '2020-04-04 04:00:00', N'Fri',
    258, 122, 380, 1,
    798, 532, 1330, 28.57,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004032359T202004040400/FNO/RIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004041100T202004050200/CTP/CRO6', N'Cross the Pond Westbound 2020', N'CTP', N'CRO6',
    '2020-04-04 11:00:00', '2020-04-05 02:00:00', N'Sat',
    1199, 431, 1630, 5,
    3740, 3322, 7062, 23.08,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004041100T202004050200/CTP/CRO6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004072300T202004080200/MWK/GA 1', N'GA Tuesdays: Fargo Fly-in', N'MWK', N'GA 1',
    '2020-04-07 23:00:00', '2020-04-08 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004072300T202004080200/MWK/GA 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004102300T202004110300/FNO/SPR1', N'Springtime in Philadelphia FNO', N'FNO', N'SPR1',
    '2020-04-10 23:00:00', '2020-04-11 03:00:00', N'Fri',
    282, 94, 376, 1,
    408, 240, 648, 58.02,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004102300T202004110300/FNO/SPR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004112200T202004120300/SAT/CAT2', N'Catch and Release Crossfire', N'SAT', N'CAT2',
    '2020-04-11 22:00:00', '2020-04-12 03:00:00', N'Sat',
    201, 143, 344, 2,
    973, 658, 1631, 21.09,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004112200T202004120300/SAT/CAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004122300T202004130300/SUN/COR2', N'Cornhuskers vs. Mavericks Fly-in', N'SUN', N'COR2',
    '2020-04-12 23:00:00', '2020-04-13 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004122300T202004130300/SUN/COR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004140100T202004140400/MWK/BLO1', N'Blow your Refund in Vegas', N'MWK', N'BLO1',
    '2020-04-14 01:00:00', '2020-04-14 04:00:00', N'Tue',
    124, 70, 194, 1,
    340, 340, 680, 28.53,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004140100T202004140400/MWK/BLO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004150100T202004150400/MWK/NOR3', N'NorCAL Tuesday', N'MWK', N'NOR3',
    '2020-04-15 01:00:00', '2020-04-15 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004150100T202004150400/MWK/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004152359T202004160300/MWK/VFR3', N'VFR through the Gorge', N'MWK', N'VFR3',
    '2020-04-15 23:59:00', '2020-04-16 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004152359T202004160300/MWK/VFR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004162300T202004170300/MWK/MUS1', N'Music City Madness', N'MWK', N'MUS1',
    '2020-04-16 23:00:00', '2020-04-17 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004162300T202004170300/MWK/MUS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004172300T202004180400/FNO/HON3', N'Honk! FNO', N'FNO', N'HON3',
    '2020-04-17 23:00:00', '2020-04-18 04:00:00', N'Fri',
    449, 312, 761, 3,
    1022, 938, 1960, 38.83,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004172300T202004180400/FNO/HON3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004181800T202004182130/SAT/PEA1', N'Peaches in Peachtree', N'SAT', N'PEA1',
    '2020-04-18 18:00:00', '2020-04-18 21:30:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004181800T202004182130/SAT/PEA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004182300T202004190300/SAT/SHA1', N'Share a Coke with Atlanta', N'SAT', N'SHA1',
    '2020-04-18 23:00:00', '2020-04-19 03:00:00', N'Sat',
    366, 206, 572, 1,
    1452, 770, 2222, 25.74,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004182300T202004190300/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004191800T202004192200/SUN/ESC2', N'Escape To Paradise: Featuring KTPA & MYNN', N'SUN', N'ESC2',
    '2020-04-19 18:00:00', '2020-04-19 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004191800T202004192200/SUN/ESC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004192200T202004200200/SUN/COM2', N'Come Fly To Wisconsin For Cripes Sakes!', N'SUN', N'COM2',
    '2020-04-19 22:00:00', '2020-04-20 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004192200T202004200200/SUN/COM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004212300T202004220300/MWK/ZOB2', N'ZOB Tuesday Night Traffic Feat. KCAK, KROC', N'MWK', N'ZOB2',
    '2020-04-21 23:00:00', '2020-04-22 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004212300T202004220300/MWK/ZOB2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004222300T202004230200/MWK/ZHU2', N'ZHU Features "Exective Fly out"', N'MWK', N'ZHU2',
    '2020-04-22 23:00:00', '2020-04-23 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004222300T202004230200/MWK/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004242300T202004250300/FNO/FLY3', N'Fly The Alligator Alley: KFLL KRSW KMIA FNO', N'FNO', N'FLY3',
    '2020-04-24 23:00:00', '2020-04-25 03:00:00', N'Fri',
    312, 166, 478, 2,
    760, 854, 1614, 29.62,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004242300T202004250300/FNO/FLY3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004251600T202004251900/SAT/GOO1', N'Good Morning Portland!', N'SAT', N'GOO1',
    '2020-04-25 16:00:00', '2020-04-25 19:00:00', N'Sat',
    70, 64, 134, 1,
    300, 300, 600, 22.33,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004251600T202004251900/SAT/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004261800T202004262200/SUN/I356', N'I35 Blue Bonnet Bonanza', N'SUN', N'I356',
    '2020-04-26 18:00:00', '2020-04-26 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004261800T202004262200/SUN/I356');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004282300T202004290200/MWK/GA 1', N'GA Tuesdays: Duluth International', N'MWK', N'GA 1',
    '2020-04-28 23:00:00', '2020-04-29 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004282300T202004290200/MWK/GA 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004300000T202004300300/MWK/BVA2', N'BVA GA Fly-In: PVD and OQU', N'MWK', N'BVA2',
    '2020-04-30 00:00:00', '2020-04-30 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004300000T202004300300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202004302300T202005010300/MWK/RAZ2', N'Razorback Roundup', N'MWK', N'RAZ2',
    '2020-04-30 23:00:00', '2020-05-01 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202004302300T202005010300/MWK/RAZ2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005012300T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-05-01 23:00:00', NULL, N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005012300T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005022300T202005030400/SAT/THE2', N'The Force of Flight Crossfire', N'SAT', N'THE2',
    '2020-05-02 23:00:00', '2020-05-03 04:00:00', N'Sat',
    228, 197, 425, 2,
    1632, 1088, 2720, 15.62,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005022300T202005030400/SAT/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005032100T202005040100/SUN/STA1', N'Stacked up in the Seattle TRACON', N'SUN', N'STA1',
    '2020-05-03 21:00:00', '2020-05-04 01:00:00', N'Sun',
    90, 69, 159, 1,
    300, 300, 600, 26.50,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005032100T202005040100/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005061900T202005062300/MWK/MUS1', N'Musem of Flight Fly-in at Boeing Field', N'MWK', N'MUS1',
    '2020-05-06 19:00:00', '2020-05-06 23:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005061900T202005062300/MWK/MUS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005080100T202005080400/MWK/BVA3', N'BVA Regional Circuit: ALB, BTV, and BDL', N'MWK', N'BVA3',
    '2020-05-08 01:00:00', '2020-05-08 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005080100T202005080400/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005082300T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-05-08 23:00:00', NULL, N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005082300T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005092300T202005100300/SAT/I-72', N'I-70 Crossfire III', N'SAT', N'I-72',
    '2020-05-09 23:00:00', '2020-05-10 03:00:00', N'Sat',
    125, 108, 233, 2,
    868, 784, 1652, 14.10,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005092300T202005100300/SAT/I-72');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005102100T202005110000/SUN/STA1', N'Staff it Up: JAX Class D', N'SUN', N'STA1',
    '2020-05-10 21:00:00', '2020-05-11 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005102100T202005110000/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005122300T202005130200/MWK/GA 2', N'GA Tuesdays: GRB/ATW', N'MWK', N'GA 2',
    '2020-05-12 23:00:00', '2020-05-13 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005122300T202005130200/MWK/GA 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005140100T202005140400/MWK/BVA3', N'BVA Regional Circuit: BGR, BTV, and PWM', N'MWK', N'BVA3',
    '2020-05-14 01:00:00', '2020-05-14 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005140100T202005140400/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005152300T202005160400/FNO/SOU14', N'Southern Region FNO', N'FNO', N'SOU14',
    '2020-05-15 23:00:00', '2020-05-16 04:00:00', N'Fri',
    471, 552, 1023, 14,
    8360, 7368, 15728, 6.50,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005152300T202005160400/FNO/SOU14');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005161600T202005161900/SAT/GOO1', N'Good Morning Seattle', N'SAT', N'GOO1',
    '2020-05-16 16:00:00', '2020-05-16 19:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005161600T202005161900/SAT/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005162300T202005170300/SAT/LIG4', N'Light Up Liberty', N'SAT', N'LIG4',
    '2020-05-16 23:00:00', '2020-05-17 03:00:00', N'Sat',
    139, 82, 221, 4,
    1019, 962, 1981, 11.16,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005162300T202005170300/SAT/LIG4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005172200T202005180100/SUN/POR2', N'Portland-Vancouver Crossfire', N'SUN', N'POR2',
    '2020-05-17 22:00:00', '2020-05-18 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005172200T202005180100/SUN/POR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005212300T202005220200/MWK/TEX3', N'Textron Takeoff', N'MWK', N'TEX3',
    '2020-05-21 23:00:00', '2020-05-22 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005212300T202005220200/MWK/TEX3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005222300T202005230300/FNO/NOR3', N'Northern Crossings III', N'FNO', N'NOR3',
    '2020-05-22 23:00:00', '2020-05-23 03:00:00', N'Fri',
    274, 273, 547, 3,
    1488, 1188, 2676, 20.44,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005222300T202005230300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005232300T202005240300/RLOP/CAL6', N'Calscream (Real Ops) XX', N'RLOP', N'CAL6',
    '2020-05-23 23:00:00', '2020-05-24 03:00:00', N'Sat',
    439, 464, 903, 6,
    2496, 2544, 5040, 17.92,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005232300T202005240300/RLOP/CAL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005242359T202005250400/SUN/COL6', N'Colorado Mountain Fly In', N'SUN', N'COL6',
    '2020-05-24 23:59:00', '2020-05-25 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005242359T202005250400/SUN/COL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005262300T202005270200/MWK/TRA1', N'Traverse City Tuesday', N'MWK', N'TRA1',
    '2020-05-26 23:00:00', '2020-05-27 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005262300T202005270200/MWK/TRA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005282300T202005290400/MWK/DEF10', N'Definitely Not An FNO', N'MWK', N'DEF10',
    '2020-05-28 23:00:00', '2020-05-29 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005282300T202005290400/MWK/DEF10');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005292300T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-05-29 23:00:00', NULL, N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005292300T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005301600T202005301900/SAT/GOO1', N'Good Morning Portland', N'SAT', N'GOO1',
    '2020-05-30 16:00:00', '2020-05-30 19:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005301600T202005301900/SAT/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005301800T202005302200/SAT/BVA5', N'BVA GA Fly-In: The Great Cape Escape!', N'SAT', N'BVA5',
    '2020-05-30 18:00:00', '2020-05-30 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005301800T202005302200/SAT/BVA5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005302300T202005310400/SAT/DAL2', N'Dallas Overload', N'SAT', N'DAL2',
    '2020-05-30 23:00:00', '2020-05-31 04:00:00', N'Sat',
    128, 91, 219, 2,
    1168, 1056, 2224, 9.85,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005302300T202005310400/SAT/DAL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202005312300T202006010300/SUN/GOO1', N'Goodbye, Mad Dog', N'SUN', N'GOO1',
    '2020-05-31 23:00:00', '2020-06-01 03:00:00', N'Sun',
    172, 127, 299, 1,
    1056, 800, 1856, 16.11,
    N'Spring', 5, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202005312300T202006010300/SUN/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006052300T202006060300/FNO/HCF3', N'HCF Overload FNO (HNL,OGG,LIH,KOA)', N'FNO', N'HCF3',
    '2020-06-05 23:00:00', '2020-06-06 03:00:00', N'Fri',
    185, 144, 329, 2,
    528, 492, 1020, 32.25,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006052300T202006060300/FNO/HCF3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006062000T202006062359/SAT/KMO1', N'KMOD Graffiti Summer Fly-In', N'SAT', N'KMO1',
    '2020-06-06 20:00:00', '2020-06-06 23:59:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006062000T202006062359/SAT/KMO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006072300T202006080300/SUN/THR1', N'Throwback Orlando', N'SUN', N'THR1',
    '2020-06-07 23:00:00', '2020-06-08 03:00:00', N'Sun',
    120, 50, 170, 1,
    602, 448, 1050, 16.19,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006072300T202006080300/SUN/THR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006092359T202006100200/MWK/TRA2', N'Train It Up Tuesdays: KBJC & KGJT', N'MWK', N'TRA2',
    '2020-06-09 23:59:00', '2020-06-10 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006092359T202006100200/MWK/TRA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006120000T202006120300/MWK/BGA3', N'BGA GA Fly-In: Springtime in the Adirondacks', N'MWK', N'BGA3',
    '2020-06-12 00:00:00', '2020-06-12 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006120000T202006120300/MWK/BGA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006122359T202006130400/FNO/DEN3', N'Denver, Seattle, Salt Lake FNO', N'FNO', N'DEN3',
    '2020-06-12 23:59:00', '2020-06-13 04:00:00', N'Fri',
    301, 136, 437, 3,
    1608, 1008, 2616, 16.70,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006122359T202006130400/FNO/DEN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006132300T202006140300/SAT/PLA1', N'Play Ball', N'SAT', N'PLA1',
    '2020-06-13 23:00:00', '2020-06-14 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006132300T202006140300/SAT/PLA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006142300T202006150230/SUN/THE1', N'The All American Birmingham Light up', N'SUN', N'THE1',
    '2020-06-14 23:00:00', '2020-06-15 02:30:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006142300T202006150230/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006192330T202006200400/FNO/FRE2', N'Freight Night Operations', N'FNO', N'FRE2',
    '2020-06-19 23:30:00', '2020-06-20 04:00:00', N'Fri',
    221, 167, 388, 2,
    1152, 1024, 2176, 17.83,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006192330T202006200400/FNO/FRE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006202000T202006210300/SAT/TRA7', N'Transcon Westbound', N'SAT', N'TRA7',
    '2020-06-20 20:00:00', '2020-06-21 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006202000T202006210300/SAT/TRA7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006212100T202006212359/SUN/STA1', N'Staff it up: KSAV', N'SUN', N'STA1',
    '2020-06-21 21:00:00', '2020-06-21 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006212100T202006212359/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006230000T202006230300/MWK/BVA2', N'BVA Regional Circuit: Providence(KPVD), Manchester (KMHT)', N'MWK', N'BVA2',
    '2020-06-23 00:00:00', '2020-06-23 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006230000T202006230300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006270000T202006270400/FNO/THE1', N'The Great Phoenix Flyout! FNO', N'FNO', N'THE1',
    '2020-06-27 00:00:00', '2020-06-27 04:00:00', N'Sat',
    133, 132, 265, 1,
    380, 300, 680, 38.97,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006270000T202006270400/FNO/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006271700T202006272100/SAT/GOO1', N'Good Morning Portland', N'SAT', N'GOO1',
    '2020-06-27 17:00:00', '2020-06-27 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006271700T202006272100/SAT/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006272300T202006280400/SAT/GET1', N'Get Out of Newark', N'SAT', N'GET1',
    '2020-06-27 23:00:00', '2020-06-28 04:00:00', N'Sat',
    124, 101, 225, 1,
    280, 266, 546, 41.21,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006272300T202006280400/SAT/GET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006281700T202006282100/SUN/CAJ7', N'Cajun Craziness', N'SUN', N'CAJ7',
    '2020-06-28 17:00:00', '2020-06-28 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006281700T202006282100/SUN/CAJ7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202006302300T202007010200/MWK/GA 1', N'GA Tuesdays', N'MWK', N'GA 1',
    '2020-06-30 23:00:00', '2020-07-01 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202006302300T202007010200/MWK/GA 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007032300T202007040400/FNO/ZDC3', N'ZDC Presents: 4th of July Preparty', N'FNO', N'ZDC3',
    '2020-07-03 23:00:00', '2020-07-04 04:00:00', N'Fri',
    127, 61, 188, 1,
    252, 210, 462, 40.69,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007032300T202007040400/FNO/ZDC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007052300T202007060300/SUN/SOU2', N'Southern Sweet Tea Party Crossfire', N'SUN', N'SOU2',
    '2020-07-05 23:00:00', '2020-07-06 03:00:00', N'Sun',
    229, 239, 468, 2,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007052300T202007060300/SUN/SOU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007090000T202007090300/MWK/BVA3', N'BVA GA Fly-In: New Hampshire', N'MWK', N'BVA3',
    '2020-07-09 00:00:00', '2020-07-09 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007090000T202007090300/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007102300T202007110300/FNO/FOR2', N'Fort Worth Freedom Fly In FNO!', N'FNO', N'FOR2',
    '2020-07-10 23:00:00', '2020-07-11 03:00:00', N'Fri',
    254, 125, 379, 2,
    1200, 1008, 2208, 17.16,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007102300T202007110300/FNO/FOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007111830T202007112230/SAT/MIA2', N'Miami Vice (feat. EGLL-KMIA)', N'SAT', N'MIA2',
    '2020-07-11 18:30:00', '2020-07-11 22:30:00', N'Sat',
    136, 61, 197, 1,
    504, 504, 1008, 19.54,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007111830T202007112230/SAT/MIA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007112200T202007120400/SAT/VAT3', N'VATCAN Presents: East Coast Vacation', N'SAT', N'VAT3',
    '2020-07-11 22:00:00', '2020-07-12 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007112200T202007120400/SAT/VAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007121900T202007122300/SUN/AFT1', N'Afternoon In Spokane', N'SUN', N'AFT1',
    '2020-07-12 19:00:00', '2020-07-12 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007121900T202007122300/SUN/AFT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007122359T202007130400/SUN/WIS1', N'Wishing Upon A Shooting Star', N'SUN', N'WIS1',
    '2020-07-12 23:59:00', '2020-07-13 04:00:00', N'Sun',
    121, 96, 217, 1,
    798, 672, 1470, 14.76,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007122359T202007130400/SUN/WIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007142359T202007150300/MWK/ZOB5', N'ZOB Tuesday Night Traffic', N'MWK', N'ZOB5',
    '2020-07-14 23:59:00', '2020-07-15 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007142359T202007150300/MWK/ZOB5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007172300T202007180300/FNO/THE1', N'The City in the Forest FNO', N'FNO', N'THE1',
    '2020-07-17 23:00:00', '2020-07-18 03:00:00', N'Fri',
    280, 134, 414, 1,
    924, 700, 1624, 25.49,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007172300T202007180300/FNO/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007182300T202007190300/SAT/IN 1', N'In The Sky to PBI', N'SAT', N'IN 1',
    '2020-07-18 23:00:00', '2020-07-19 03:00:00', N'Sat',
    256, 165, 421, 2,
    752, 752, 1504, 27.99,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007182300T202007190300/SAT/IN 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007192000T202007192300/SUN/FUE1', N'Fuel Stop Waco', N'SUN', N'FUE1',
    '2020-07-19 20:00:00', '2020-07-19 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007192000T202007192300/SUN/FUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007210000T202007210300/MWK/BVA1', N'BVA Pack the Pattern: Bradley Intl.', N'MWK', N'BVA1',
    '2020-07-21 00:00:00', '2020-07-21 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007210000T202007210300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007222000T202007222300/MWK/WHA1', N'WHATALANDING', N'MWK', N'WHA1',
    '2020-07-22 20:00:00', '2020-07-22 23:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007222000T202007222300/MWK/WHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007242330T202007250400/FNO/NIG1', N'Night in N90 FNO incl. Tel Aviv Flyout', N'FNO', N'NIG1',
    '2020-07-24 23:30:00', '2020-07-25 04:00:00', N'Fri',
    165, 124, 289, 1,
    480, 288, 768, 37.63,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007242330T202007250400/FNO/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007251400T202007252000/SAT/ZNY4', N'ZNY RealOps', N'SAT', N'ZNY4',
    '2020-07-25 14:00:00', '2020-07-25 20:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007251400T202007252000/SAT/ZNY4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007252200T202007260200/SAT/ZLA22', N'ZLA Presents: TEC Route Madness', N'SAT', N'ZLA22',
    '2020-07-25 22:00:00', '2020-07-26 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007252200T202007260200/SAT/ZLA22');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007262000T202007270000/SUN/CAU5', N'Caution Wake Turbulence', N'SUN', N'CAU5',
    '2020-07-26 20:00:00', '2020-07-27 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007262000T202007270000/SUN/CAU5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007272100T202007280000/MWK/VAT1', N'VATVENTURE', N'MWK', N'VAT1',
    '2020-07-27 21:00:00', '2020-07-28 00:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007272100T202007280000/MWK/VAT1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007282200T202007290100/MWK/VAT1', N'VATVENTURE  Part 2', N'MWK', N'VAT1',
    '2020-07-28 22:00:00', '2020-07-29 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007282200T202007290100/MWK/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007292300T202007300300/MWK/WED2', N'Wednesday Night Training', N'MWK', N'WED2',
    '2020-07-29 23:00:00', '2020-07-30 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007292300T202007300300/MWK/WED2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007302300T202007310300/MWK/CRO2', N'Cross the Mini Pond', N'MWK', N'CRO2',
    '2020-07-30 23:00:00', '2020-07-31 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007302300T202007310300/MWK/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007310000T202007310300/MWK/BVA2', N'BVA Regional Circuit', N'MWK', N'BVA2',
    '2020-07-31 00:00:00', '2020-07-31 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007310000T202007310300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202007312300T202008010300/FNO/FLO6', N'Florida Citrus Haul FNO', N'FNO', N'FLO6',
    '2020-07-31 23:00:00', '2020-08-01 03:00:00', N'Fri',
    287, 257, 544, 6,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202007312300T202008010300/FNO/FLO6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008012300T202008020300/SAT/SEA1', N'Seattle in the Summer', N'SAT', N'SEA1',
    '2020-08-01 23:00:00', '2020-08-02 03:00:00', N'Sat',
    109, 94, 203, 1,
    350, 350, 700, 29.00,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008012300T202008020300/SAT/SEA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008022300T202008030300/SUN/JOI1', N'Join AvA 19th Birthday', N'SUN', N'JOI1',
    '2020-08-02 23:00:00', '2020-08-03 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008022300T202008030300/SUN/JOI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008042300T202008050200/MWK/GA 1', N'GA Tuesday: Bismarck Municipal', N'MWK', N'GA 1',
    '2020-08-04 23:00:00', '2020-08-05 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008042300T202008050200/MWK/GA 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008052100T202008052230/MWK/JUS0', N'Just Ground', N'MWK', N'JUS0',
    '2020-08-05 21:00:00', '2020-08-05 22:30:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008052100T202008052230/MWK/JUS0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008072300T202008080300/FNO/OPE0', N'Open Mic Night', N'FNO', N'OPE0',
    '2020-08-07 23:00:00', '2020-08-08 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008072300T202008080300/FNO/OPE0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008082000T202008090300/SAT/21S1', N'21st Annual Boston Tea Party', N'SAT', N'21S1',
    '2020-08-08 20:00:00', '2020-08-09 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008082000T202008090300/SAT/21S1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008090700T202008100659/SUN/24 1', N'24 Hours of ATC', N'SUN', N'24 1',
    '2020-08-09 07:00:00', '2020-08-10 06:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008090700T202008100659/SUN/24 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008142300T202008150300/FNO/SUM1', N'Summertime in the Queen City', N'FNO', N'SUM1',
    '2020-08-14 23:00:00', '2020-08-15 03:00:00', N'Fri',
    157, 84, 241, 1,
    528, 528, 1056, 22.82,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008142300T202008150300/FNO/SUM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008152300T202008160300/SAT/SWE2', N'Sweltering Summer Crossfire', N'SAT', N'SWE2',
    '2020-08-15 23:00:00', '2020-08-16 03:00:00', N'Sat',
    215, 220, 435, 2,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008152300T202008160300/SAT/SWE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008162100T202008162359/SUN/STA1', N'Staff it up: KPNS', N'SUN', N'STA1',
    '2020-08-16 21:00:00', '2020-08-16 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008162100T202008162359/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008162359T202008170400/SUN/COL4', N'Colorado Birthday Bash', N'SUN', N'COL4',
    '2020-08-16 23:59:00', '2020-08-17 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008162359T202008170400/SUN/COL4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008182359T202008190400/MWK/MSF2', N'MSFS Shakedown', N'MWK', N'MSF2',
    '2020-08-18 23:59:00', '2020-08-19 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008182359T202008190400/MWK/MSF2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008202330T202008210330/MWK/THU0', N'Thursday Night on the Town (in NYC)', N'MWK', N'THU0',
    '2020-08-20 23:30:00', '2020-08-21 03:30:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008202330T202008210330/MWK/THU0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008212300T202008220300/FNO/NEW4', N'New Generation FNO', N'FNO', N'NEW4',
    '2020-08-21 23:00:00', '2020-08-22 03:00:00', N'Fri',
    166, 151, 317, 4,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008212300T202008220300/FNO/NEW4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008221230T202008221500/SAT/JFK2', N'JFK-AMS Crossfire', N'SAT', N'JFK2',
    '2020-08-22 12:30:00', '2020-08-22 15:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008221230T202008221500/SAT/JFK2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008222300T202008230300/SAT/IND1', N'Indy 500 Fly-In', N'SAT', N'IND1',
    '2020-08-22 23:00:00', '2020-08-23 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008222300T202008230300/SAT/IND1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008232200T202008240100/SUN/VFR4', N'VFR Through the Valley', N'SUN', N'VFR4',
    '2020-08-23 22:00:00', '2020-08-24 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008232200T202008240100/SUN/VFR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008250000T202008260300/MWK/BVA2', N'BVA Regional Circuit: BOS,ALB', N'MWK', N'BVA2',
    '2020-08-25 00:00:00', '2020-08-26 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008250000T202008260300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008262359T202008270400/MWK/MID1', N'Midweek Madness Aspen Edition', N'MWK', N'MID1',
    '2020-08-26 23:59:00', '2020-08-27 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008262359T202008270400/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008282359T202008290500/FNO/NOR1', N'Northern "Isolation" XV', N'FNO', N'NOR1',
    '2020-08-28 23:59:00', '2020-08-29 05:00:00', N'Fri',
    159, 78, 237, 1,
    525, 420, 945, 25.08,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008282359T202008290500/FNO/NOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008292300T202008300300/SAT/FLO1', N'Florida Man Fly In', N'SAT', N'FLO1',
    '2020-08-29 23:00:00', '2020-08-30 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008292300T202008300300/SAT/FLO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202008301900T202008302300/SUN/SUR2', N'Surf N Turf Crossfire', N'SUN', N'SUR2',
    '2020-08-30 19:00:00', '2020-08-30 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202008301900T202008302300/SUN/SUR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009042300T202009050300/FNO/THE3', N'The I-95 Overload FNO', N'FNO', N'THE3',
    '2020-09-04 23:00:00', '2020-09-05 03:00:00', N'Fri',
    259, 259, 518, 3,
    1260, 1183, 2443, 21.20,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009042300T202009050300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009051700T202009052100/SAT/KEN1', N'Kentucky Derby Fly-In', N'SAT', N'KEN1',
    '2020-09-05 17:00:00', '2020-09-05 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009051700T202009052100/SAT/KEN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009061900T202009062300/SUN/POR1', N'Portland Getaway', N'SUN', N'POR1',
    '2020-09-06 19:00:00', '2020-09-06 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009061900T202009062300/SUN/POR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009082300T202009090200/MWK/KEY1', N'Key City Fly-in: KDBQ', N'MWK', N'KEY1',
    '2020-09-08 23:00:00', '2020-09-09 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009082300T202009090200/MWK/KEY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009122300T202009130300/SAT/ZMA3', N'ZMA Roulette', N'SAT', N'ZMA3',
    '2020-09-12 23:00:00', '2020-09-13 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009122300T202009130300/SAT/ZMA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009132359T202009140400/SUN/FOU4', N'Four Corners Fly-In', N'SUN', N'FOU4',
    '2020-09-13 23:59:00', '2020-09-14 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009132359T202009140400/SUN/FOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009160100T202009160400/MWK/NOR2', N'NorCAL Tuesday: TRK & RNO', N'MWK', N'NOR2',
    '2020-09-16 01:00:00', '2020-09-16 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009160100T202009160400/MWK/NOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009172330T202009180330/MWK/THE1', N'the dunder mifflin fly-in', N'MWK', N'THE1',
    '2020-09-17 23:30:00', '2020-09-18 03:30:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009172330T202009180330/MWK/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009182300T202009190300/FNO/1001', N'100 Years of Flyness FNO', N'FNO', N'1001',
    '2020-09-18 23:00:00', '2020-09-19 03:00:00', N'Fri',
    120, 82, 202, 1,
    432, 360, 792, 25.51,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009182300T202009190300/FNO/1001');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009192359T202009200400/SAT/DEF5', N'Definitely Not A ZTL Event', N'SAT', N'DEF5',
    '2020-09-19 23:59:00', '2020-09-20 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009192359T202009200400/SAT/DEF5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009202100T202009210100/SUN/WIT2', N'Withstand the Tide Crossfire', N'SUN', N'WIT2',
    '2020-09-20 21:00:00', '2020-09-21 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009202100T202009210100/SUN/WIT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009230000T202009230300/MWK/BVA3', N'BVA GA Fly-In: The Maine Event', N'MWK', N'BVA3',
    '2020-09-23 00:00:00', '2020-09-23 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009230000T202009230300/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009252300T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-09-25 23:00:00', NULL, N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009252300T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009262300T202009270300/SAT/RET1', N'Retro NY Fly-in', N'SAT', N'RET1',
    '2020-09-26 23:00:00', '2020-09-27 03:00:00', N'Sat',
    76, 83, 159, 1,
    228, 216, 444, 35.81,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009262300T202009270300/SAT/RET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202009272300T202009280200/SUN/SOU4', N'South Sat Sunday: MDW, ARR, DPA, GYY', N'SUN', N'SOU4',
    '2020-09-27 23:00:00', '2020-09-28 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202009272300T202009280200/SUN/SOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010022300T202010030300/FNO/ZAB5', N'ZAB/ZFW/ZME Trifire', N'FNO', N'ZAB5',
    '2020-10-02 23:00:00', '2020-10-03 03:00:00', N'Fri',
    197, 239, 436, 3,
    1764, 1316, 3080, 14.16,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010022300T202010030300/FNO/ZAB5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010032300T202010040300/SAT/LIB2', N'Liberty City Fly-In', N'SAT', N'LIB2',
    '2020-10-03 23:00:00', '2020-10-04 03:00:00', N'Sat',
    136, 75, 211, 2,
    686, 448, 1134, 18.61,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010032300T202010040300/SAT/LIB2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010042100T202010042359/SUN/STA1', N'Staff it up: KMYR', N'SUN', N'STA1',
    '2020-10-04 21:00:00', '2020-10-04 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010042100T202010042359/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010060000T202010060300/MWK/BVA2', N'BVA Fly-In: Military Airports', N'MWK', N'BVA2',
    '2020-10-06 00:00:00', '2020-10-06 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010060000T202010060300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010092300T202010100300/FNO/PAL2', N'Palmetto Expressway FNO', N'FNO', N'PAL2',
    '2020-10-09 23:00:00', '2020-10-10 03:00:00', N'Fri',
    217, 133, 350, 2,
    854, 700, 1554, 22.52,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010092300T202010100300/FNO/PAL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010102300T202010110300/SAT/ZDC4', N'ZDC Presents: Tim''s Big Event', N'SAT', N'ZDC4',
    '2020-10-10 23:00:00', '2020-10-11 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010102300T202010110300/SAT/ZDC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010112000T202010120000/SUN/SPO1', N'Spooky Season in Seattle', N'SUN', N'SPO1',
    '2020-10-11 20:00:00', '2020-10-12 00:00:00', N'Sun',
    52, 71, 123, 1,
    522, 300, 822, 14.96,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010112000T202010120000/SUN/SPO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010132330T202010140230/MWK/ZTL1', N'ZTL''s TRSA Tuesdays @ KMCN', N'MWK', N'ZTL1',
    '2020-10-13 23:30:00', '2020-10-14 02:30:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010132330T202010140230/MWK/ZTL1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010152359T202010160400/MWK/ZLA2', N'ZLA and VATMEX Present: MexiCali Link Southbound', N'MWK', N'ZLA2',
    '2020-10-15 23:59:00', '2020-10-16 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010152359T202010160400/MWK/ZLA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010162359T202010170400/FNO/EMM3', N'Emma Crawford Coffin Races FNO', N'FNO', N'EMM3',
    '2020-10-16 23:59:00', '2020-10-17 04:00:00', N'Fri',
    197, 162, 359, 3,
    1308, 1018, 2326, 15.43,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010162359T202010170400/FNO/EMM3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010172300T202010180300/SAT/BRA1', N'Bradley Bash 2020!', N'SAT', N'BRA1',
    '2020-10-17 23:00:00', '2020-10-18 03:00:00', N'Sat',
    90, 62, 152, 1,
    224, 224, 448, 33.93,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010172300T202010180300/SAT/BRA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010210100T202010210400/MWK/REG5', N'Regional Night (ZOA)', N'MWK', N'REG5',
    '2020-10-21 01:00:00', '2020-10-21 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010210100T202010210400/MWK/REG5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010222359T202010230400/MWK/ZLA2', N'ZLA and VATMEX present: MexiCali Link Northbound', N'MWK', N'ZLA2',
    '2020-10-22 23:59:00', '2020-10-23 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010222359T202010230400/MWK/ZLA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010232300T202010240300/FNO/IT''2', N'It''s The Great PMPKN FNO', N'FNO', N'IT''2',
    '2020-10-23 23:00:00', '2020-10-24 03:00:00', N'Fri',
    229, 200, 429, 2,
    1200, 896, 2096, 20.47,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010232300T202010240300/FNO/IT''2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010242300T202010250300/SAT/AUT2', N'Autumn in the North', N'SAT', N'AUT2',
    '2020-10-24 23:00:00', '2020-10-25 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010242300T202010250300/SAT/AUT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010251900T202010252200/SUN/YVR2', N'YVR-PDX Crossfire', N'SUN', N'YVR2',
    '2020-10-25 19:00:00', '2020-10-25 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010251900T202010252200/SUN/YVR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010280000T202010280300/MWK/BVA1', N'BVA Minor Facility Showcase: Portland Jetport', N'MWK', N'BVA1',
    '2020-10-28 00:00:00', '2020-10-28 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010280000T202010280300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010292300T202010300200/MWK/ZHU0', N'ZHU Presents The Bob Ross Fly Thru', N'MWK', N'ZHU0',
    '2020-10-29 23:00:00', '2020-10-30 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010292300T202010300200/MWK/ZHU0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010302300T202010310300/FNO/A N2', N'A Not So Scary FNO, Part II: Electric Boogaloo', N'FNO', N'A N2',
    '2020-10-30 23:00:00', '2020-10-31 03:00:00', N'Fri',
    187, 114, 301, 2,
    816, 624, 1440, 20.90,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010302300T202010310300/FNO/A N2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202010312300T202011010300/SAT/WES6', N'West Coast Freight Ops 2020', N'SAT', N'WES6',
    '2020-10-31 23:00:00', '2020-11-01 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202010312300T202011010300/SAT/WES6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011032359T202011040230/MWK/ZHU0', N'ZHU Presents "The Airports Among Us"', N'MWK', N'ZHU0',
    '2020-11-03 23:59:00', '2020-11-04 02:30:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011032359T202011040230/MWK/ZHU0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011062359T202011070400/FNO/COR3', N'Cornfield Crossfire', N'FNO', N'COR3',
    '2020-11-06 23:59:00', '2020-11-07 04:00:00', N'Fri',
    293, 250, 543, 3,
    1960, 1694, 3654, 14.86,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011062359T202011070400/FNO/COR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011072359T202011080400/SAT/ATH1', N'Athens of the South (KBNA)', N'SAT', N'ATH1',
    '2020-11-07 23:59:00', '2020-11-08 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011072359T202011080400/SAT/ATH1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011102359T202011110300/MWK/ZTL1', N'ZTL''s TRSA Tuesdays Masters Edition @ KAGS', N'MWK', N'ZTL1',
    '2020-11-10 23:59:00', '2020-11-11 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011102359T202011110300/MWK/ZTL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011140100T202011140500/FNO/ZLA4', N'ZLA/ZOA FNO', N'FNO', N'ZLA4',
    '2020-11-14 01:00:00', '2020-11-14 05:00:00', N'Sat',
    352, 343, 695, 4,
    1230, 1230, 2460, 28.25,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011140100T202011140500/FNO/ZLA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011141100T202011142300/CTP/CRO8', N'Cross the Pond Eastbound 2020', N'CTP', N'CRO8',
    '2020-11-14 11:00:00', '2020-11-14 23:00:00', N'Sat',
    68, 742, 810, 6,
    3010, 2954, 5964, 13.58,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011141100T202011142300/CTP/CRO8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011142301T202011150300/SAT/THE6', N'The Texas Extravaganza!', N'SAT', N'THE6',
    '2020-11-14 23:01:00', '2020-11-15 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011142301T202011150300/SAT/THE6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011152100T202011152300/SUN/FLI2', N'Flight of the Americas', N'SUN', N'FLI2',
    '2020-11-15 21:00:00', '2020-11-15 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011152100T202011152300/SUN/FLI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011182359T202011190400/MWK/WIN2', N'Winter in Anchorage!', N'MWK', N'WIN2',
    '2020-11-18 23:59:00', '2020-11-19 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011182359T202011190400/MWK/WIN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011192359T202011200300/MWK/FOR2', N'Fort Worth Parade of Lights', N'MWK', N'FOR2',
    '2020-11-19 23:59:00', '2020-11-20 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011192359T202011200300/MWK/FOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011202359T202011210400/FNO/NEV1', N'Never Dull at Dulles', N'FNO', N'NEV1',
    '2020-11-20 23:59:00', '2020-11-21 04:00:00', N'Fri',
    206, 125, 331, 1,
    644, 210, 854, 38.76,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011202359T202011210400/FNO/NEV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011212000T202011220400/SAT/GUA2', N'Guam Crossfire', N'SAT', N'GUA2',
    '2020-11-21 20:00:00', '2020-11-22 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011212000T202011220400/SAT/GUA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011212359T202011220400/SAT/DEN3', N'Denver''s 162nd Birthday', N'SAT', N'DEN3',
    '2020-11-21 23:59:00', '2020-11-22 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011212359T202011220400/SAT/DEN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011222300T202011230300/SUN/STU2', N'Stuff the Albu-turkey', N'SUN', N'STU2',
    '2020-11-22 23:00:00', '2020-11-23 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011222300T202011230300/SUN/STU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011272359T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-11-27 23:59:00', '2020-11-28 00:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011272359T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011282359T202011290400/SAT/ZHU1', N'ZHU Features "Autumn In Austin"', N'SAT', N'ZHU1',
    '2020-11-28 23:59:00', '2020-11-29 04:00:00', N'Sat',
    73, 56, 129, 1,
    336, 336, 672, 19.20,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011282359T202011290400/SAT/ZHU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011291800T202011292200/SUN/SUN1', N'Sunday Brunch in Seattle', N'SUN', N'SUN1',
    '2020-11-29 18:00:00', '2020-11-29 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011291800T202011292200/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202011292100T202011300100/SUN/SUN3', N'Sunday Funday (Feat. KSRQ, KLAL, KTPA)', N'SUN', N'SUN3',
    '2020-11-29 21:00:00', '2020-11-30 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202011292100T202011300100/SUN/SUN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012030100T202012030400/MWK/BVA1', N'BVA Minor Facility Showcase: Albany International', N'MWK', N'BVA1',
    '2020-12-03 01:00:00', '2020-12-03 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012030100T202012030400/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012042359T202012050400/FNO/ZSE2', N'ZSE Winter Wonderland FNO', N'FNO', N'ZSE2',
    '2020-12-04 23:59:00', '2020-12-05 04:00:00', N'Fri',
    172, 85, 257, 1,
    300, 216, 516, 49.81,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012042359T202012050400/FNO/ZSE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012051700T202012052100/SAT/OPE1', N'Operation Good Cheer', N'SAT', N'OPE1',
    '2020-12-05 17:00:00', '2020-12-05 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012051700T202012052100/SAT/OPE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012052359T202012060400/SAT/MIN1', N'Minimum Time on the Runway @ KATL', N'SAT', N'MIN1',
    '2020-12-05 23:59:00', '2020-12-06 04:00:00', N'Sat',
    162, 92, 254, 1,
    600, 600, 1200, 21.17,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012052359T202012060400/SAT/MIN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012062100T202012070100/SUN/LOS2', N'Los Angeles - Vancouver Crossfire', N'SUN', N'LOS2',
    '2020-12-06 21:00:00', '2020-12-07 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012062100T202012070100/SUN/LOS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012080200T202012080500/MWK/NOR2', N'Norcal Tuesday VII', N'MWK', N'NOR2',
    '2020-12-08 02:00:00', '2020-12-08 05:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012080200T202012080500/MWK/NOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012111600T202012140400/MWK/60 0', N'60 Hours of BVARTCC', N'MWK', N'60 0',
    '2020-12-11 16:00:00', '2020-12-14 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012111600T202012140400/MWK/60 0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012112359T202012120400/FNO/WIN4', N'Winter Kickoff FNO (KBOS, KEWR, KPHL, KBDL)', N'FNO', N'WIN4',
    '2020-12-11 23:59:00', '2020-12-12 04:00:00', N'Fri',
    348, 226, 574, 4,
    1440, 1232, 2672, 21.48,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012112359T202012120400/FNO/WIN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012121800T202012122100/SAT/VAT4', N'VATSIM First Wings', N'SAT', N'VAT4',
    '2020-12-12 18:00:00', '2020-12-12 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012121800T202012122100/SAT/VAT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012122359T202012130400/SAT/ZHU2', N'ZHU Presents "Cooldown Crossfire"', N'SAT', N'ZHU2',
    '2020-12-12 23:59:00', '2020-12-13 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012122359T202012130400/SAT/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012132000T202012140000/SUN/CAR2', N'Caribbean Crossings (Feat. KFLL-TJBQ)', N'SUN', N'CAR2',
    '2020-12-13 20:00:00', '2020-12-14 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012132000T202012140000/SUN/CAR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012182300T202012190300/FNO/END3', N'Ending the Year in Paradise', N'FNO', N'END3',
    '2020-12-18 23:00:00', '2020-12-19 03:00:00', N'Fri',
    188, 183, 371, 3,
    784, 784, 1568, 23.66,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012182300T202012190300/FNO/END3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012192130T202012192330/SAT/AFT4', N'Afternoon on the Bay (ZOA)', N'SAT', N'AFT4',
    '2020-12-19 21:30:00', '2020-12-19 23:30:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012192130T202012192330/SAT/AFT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012192359T202012200400/SAT/ZDC1', N'ZDC Presents: Capital Christmas', N'SAT', N'ZDC1',
    '2020-12-19 23:59:00', '2020-12-20 04:00:00', N'Sat',
    126, 57, 183, 1,
    180, 180, 360, 50.83,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012192359T202012200400/SAT/ZDC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012202359T202012210400/SUN/ZMP2', N'ZMP Last Minute Shopping', N'SUN', N'ZMP2',
    '2020-12-20 23:59:00', '2020-12-21 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012202359T202012210400/SUN/ZMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012212359T202012220400/MWK/MEN0', N'Mentoring Monday at ZSE', N'MWK', N'MEN0',
    '2020-12-21 23:59:00', '2020-12-22 04:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012212359T202012220400/MWK/MEN0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012252359T/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2020-12-25 23:59:00', NULL, N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012252359T/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012261700T202012272300/SAT/ZNY1', N'ZNY Staffs: 30 Hours of LGA', N'SAT', N'ZNY1',
    '2020-12-26 17:00:00', '2020-12-27 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012261700T202012272300/SAT/ZNY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202012272359T202012280300/SUN/HOM2', N'Home For The Holidays', N'SUN', N'HOM2',
    '2020-12-27 23:59:00', '2020-12-28 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2020,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202012272359T202012280300/SUN/HOM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101020200T202101020600/FNO/NEW4', N'New Year, New FNO!', N'FNO', N'NEW4',
    '2021-01-02 02:00:00', '2021-01-02 06:00:00', N'Sat',
    445, 356, 801, 4,
    1426, 1324, 2750, 29.13,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101020200T202101020600/FNO/NEW4');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101022359T202101030400/SAT/FIR1', N'Fireworks Over Lake Houston', N'SAT', N'FIR1',
    '2021-01-02 23:59:00', '2021-01-03 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101022359T202101030400/SAT/FIR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101050100T202101050400/MWK/BVA2', N'BVA Regional Circuit: BOS and BTV', N'MWK', N'BVA2',
    '2021-01-05 01:00:00', '2021-01-05 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101050100T202101050400/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101062359T202101070300/MWK/LOW2', N'Lowcountry Crossfire', N'MWK', N'LOW2',
    '2021-01-06 23:59:00', '2021-01-07 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101062359T202101070300/MWK/LOW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101082359T202101090400/FNO/TEX4', N'Texas Center Crossfire 2021', N'FNO', N'TEX4',
    '2021-01-08 23:59:00', '2021-01-09 04:00:00', N'Fri',
    377, 305, 682, 4,
    1666, 1470, 3136, 21.75,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101082359T202101090400/FNO/TEX4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101092359T202101100300/SAT/NIG1', N'Night at "The Rock"', N'SAT', N'NIG1',
    '2021-01-09 23:59:00', '2021-01-10 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101092359T202101100300/SAT/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101102359T202101110400/SUN/KSE2', N'KSEA-KDEN Crossfire!', N'SUN', N'KSE2',
    '2021-01-10 23:59:00', '2021-01-11 04:00:00', N'Sun',
    97, 144, 241, 1,
    912, 912, 1824, 13.21,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101102359T202101110400/SUN/KSE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101122359T202101130300/MWK/MON2', N'Montreal Monthly - Montreal/Buffalo Crossfire', N'MWK', N'MON2',
    '2021-01-12 23:59:00', '2021-01-13 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101122359T202101130300/MWK/MON2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101142330T202101150400/MWK/ZNY1', N'ZNY''s Tower Thursday: TTN', N'MWK', N'ZNY1',
    '2021-01-14 23:30:00', '2021-01-15 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101142330T202101150400/MWK/ZNY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101152359T/FNO/HON1', N'Honoring the Dream @ KATL', N'FNO', N'HON1',
    '2021-01-15 23:59:00', NULL, N'Fri',
    340, 151, 491, 1,
    924, 700, 1624, 30.23,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101152359T/FNO/HON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101162200T202101170400/RLOP/ZLA1', N'ZLA Presents: Los Angeles Real Ops', N'RLOP', N'ZLA1',
    '2021-01-16 22:00:00', '2021-01-17 04:00:00', N'Sat',
    235, 200, 435, 1,
    476, 476, 952, 45.69,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101162200T202101170400/RLOP/ZLA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101172100T202101180100/SUN/ZDC1', N'ZDC Presents Malarkey: An Inauguration Fly-in', N'SUN', N'ZDC1',
    '2021-01-17 21:00:00', '2021-01-18 01:00:00', N'Sun',
    134, 62, 196, 1,
    192, 180, 372, 52.69,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101172100T202101180100/SUN/ZDC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101220100T202101220400/MWK/BVA3', N'BVA GA Fly-In: The Seacoast Fly-In', N'MWK', N'BVA3',
    '2021-01-22 01:00:00', '2021-01-22 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101220100T202101220400/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101222359T202101230500/FNO/ZMP1', N'ZMP Presents: Operation Deep Freeze 2021', N'FNO', N'ZMP1',
    '2021-01-22 23:59:00', '2021-01-23 05:00:00', N'Fri',
    325, 163, 488, 1,
    675, 540, 1215, 40.16,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101222359T202101230500/FNO/ZMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101240100T202101240500/SUN/CRO2', N'Cross the Sierras', N'SUN', N'CRO2',
    '2021-01-24 01:00:00', '2021-01-24 05:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101240100T202101240500/SUN/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101242100T202101250100/SUN/ZNY4', N'ZNY Regions: Hershey Valley Fly-In', N'SUN', N'ZNY4',
    '2021-01-24 21:00:00', '2021-01-25 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101242100T202101250100/SUN/ZNY4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101262359T202101270400/MWK/TRA3', N'TRACON Tuesday', N'MWK', N'TRA3',
    '2021-01-26 23:59:00', '2021-01-27 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101262359T202101270400/MWK/TRA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101282359T202101290300/MWK/MAC1', N'Macon My Way Downtown', N'MWK', N'MAC1',
    '2021-01-28 23:59:00', '2021-01-29 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101282359T202101290300/MWK/MAC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101292300T202101300300/FNO/ESC2', N'Escape the Cold FNO', N'FNO', N'ESC2',
    '2021-01-29 23:00:00', '2021-01-30 03:00:00', N'Fri',
    331, 219, 550, 2,
    595, 490, 1085, 50.69,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101292300T202101300300/FNO/ESC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101301900T202101302300/SAT/CRO2', N'Cross the Panhandle', N'SAT', N'CRO2',
    '2021-01-30 19:00:00', '2021-01-30 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101301900T202101302300/SAT/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101302300T202101310300/SAT/"RB4', N'"RBUKL" Your Seatbelts to Oklahoma', N'SAT', N'"RB4',
    '2021-01-30 23:00:00', '2021-01-31 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101302300T202101310300/SAT/"RB4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101312100T202101312359/SUN/SUN3', N'Sunday Funday', N'SUN', N'SUN3',
    '2021-01-31 21:00:00', '2021-01-31 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101312100T202101312359/SUN/SUN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202101312359T202102010400/SUN/SOC9', N'SoCal Sunday', N'SUN', N'SOC9',
    '2021-01-31 23:59:00', '2021-02-01 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202101312359T202102010400/SUN/SOC9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102040200T202102040500/MWK/ZOA4', N'ZOA Regional Night: Vinos and Vectors', N'MWK', N'ZOA4',
    '2021-02-04 02:00:00', '2021-02-04 05:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102040200T202102040500/MWK/ZOA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102052359T202102060500/FNO/NOR8', N'Northeast Corridor FNO', N'FNO', N'NOR8',
    '2021-02-05 23:59:00', '2021-02-06 05:00:00', N'Fri',
    622, 499, 1121, 8,
    2664, 2576, 5240, 21.39,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102052359T202102060500/FNO/NOR8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102062359T202102070400/SAT/ZHU2', N'ZHU and VATMEX Presents City Link: Houston-Cabos Southbound', N'SAT', N'ZHU2',
    '2021-02-06 23:59:00', '2021-02-07 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102062359T202102070400/SAT/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102072330T202102080330/SUN/KSE2', N'KSEA-CYYC Crossfire', N'SUN', N'KSE2',
    '2021-02-07 23:30:00', '2021-02-08 03:30:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102072330T202102080330/SUN/KSE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102110100T202102110400/MWK/BVA1', N'BVA Minor Facility Showcase: PVD', N'MWK', N'BVA1',
    '2021-02-11 01:00:00', '2021-02-11 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102110100T202102110400/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102112359T202102120400/MWK/LOV3', N'Lover''s Getaway', N'MWK', N'LOV3',
    '2021-02-11 23:59:00', '2021-02-12 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102112359T202102120400/MWK/LOV3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102122359T202102130400/FNO/ZFW2', N'ZFW Presents the "Feel the Love FNO"', N'FNO', N'ZFW2',
    '2021-02-12 23:59:00', '2021-02-13 04:00:00', N'Fri',
    293, 120, 413, 2,
    1104, 864, 1968, 20.99,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102122359T202102130400/FNO/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102131900T202102132300/SAT/WAS4', N'Washington''s Class D Shenanigans', N'SAT', N'WAS4',
    '2021-02-13 19:00:00', '2021-02-13 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102131900T202102132300/SAT/WAS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102132359T202102140400/SAT/ZHU2', N'ZHU and VATMEX Presents City Link: Houston-Cabos Northbound', N'SAT', N'ZHU2',
    '2021-02-13 23:59:00', '2021-02-14 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102132359T202102140400/SAT/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102142200T202102150200/SUN/ZNY2', N'ZNY Regions: Southern Tier Regional', N'SUN', N'ZNY2',
    '2021-02-14 22:00:00', '2021-02-15 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102142200T202102150200/SUN/ZNY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102162359T202102170230/MWK/ZHU2', N'ZHU Presents "Mardi Gras Showdown"', N'MWK', N'ZHU2',
    '2021-02-16 23:59:00', '2021-02-17 02:30:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102162359T202102170230/MWK/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102190100T202102190400/MWK/BVA2', N'BVA Regional Circuit: BOS and SYR', N'MWK', N'BVA2',
    '2021-02-19 01:00:00', '2021-02-19 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102190100T202102190400/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102192359T202102200400/FNO/DEN2', N'Denver''s Winter FNO', N'FNO', N'DEN2',
    '2021-02-19 23:59:00', '2021-02-20 04:00:00', N'Fri',
    350, 240, 590, 2,
    1230, 1006, 2236, 26.39,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102192359T202102200400/FNO/DEN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102201900T202102202200/SAT/NQA2', N'NQA/MQY Crossfire', N'SAT', N'NQA2',
    '2021-02-20 19:00:00', '2021-02-20 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102201900T202102202200/SAT/NQA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102202359T202102210400/SAT/ZLA1', N'ZLA Presents: Las Vegas Restyling', N'SAT', N'ZLA1',
    '2021-02-20 23:59:00', '2021-02-21 04:00:00', N'Sat',
    247, 119, 366, 1,
    544, 384, 928, 39.44,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102202359T202102210400/SAT/ZLA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102212300T/SUN/CRO2', N'Cross The Land And Some Water', N'SUN', N'CRO2',
    '2021-02-21 23:00:00', NULL, N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102212300T/SUN/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102222359T202102230300/MWK/NOT1', N'Not John Wayne…Fort Wayne!', N'MWK', N'NOT1',
    '2021-02-22 23:59:00', '2021-02-23 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102222359T202102230300/MWK/NOT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102232359T/MWK/WHE1', N'When Pigs Fly', N'MWK', N'WHE1',
    '2021-02-23 23:59:00', NULL, N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102232359T/MWK/WHE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102252300T202102260200/MWK/POR1', N'Port Authority Pandemonium: SWF', N'MWK', N'POR1',
    '2021-02-25 23:00:00', '2021-02-26 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102252300T202102260200/MWK/POR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102270100T202102270500/FNO/STO3', N'Storm the Bay FNO', N'FNO', N'STO3',
    '2021-02-27 01:00:00', '2021-02-27 05:00:00', N'Sat',
    500, 187, 687, 3,
    1192, 1192, 2384, 28.82,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102270100T202102270500/FNO/STO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102272359T202102280400/SAT/CRO4', N'Cross the Gulf', N'SAT', N'CRO4',
    '2021-02-27 23:59:00', '2021-02-28 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102272359T202102280400/SAT/CRO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202102281900T202102282300/SUN/BVA2', N'BVA Fly-In: The Living History Fly-In', N'SUN', N'BVA2',
    '2021-02-28 19:00:00', '2021-02-28 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202102281900T202102282300/SUN/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103032359T202103040300/MWK/POT2', N'Potato Flying Frenzy', N'MWK', N'POT2',
    '2021-03-03 23:59:00', '2021-03-04 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103032359T202103040300/MWK/POT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103042359T202103050300/MWK/TRS2', N'TRSA Thursday Featuring KGGG and KMLU', N'MWK', N'TRS2',
    '2021-03-04 23:59:00', '2021-03-05 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103042359T202103050300/MWK/TRS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103052345T202103060400/FNO/THE2', N'The East vs West Dynamic FNO', N'FNO', N'THE2',
    '2021-03-05 23:45:00', '2021-03-06 04:00:00', N'Fri',
    272, 152, 424, 2,
    992, 896, 1888, 22.46,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103052345T202103060400/FNO/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103062359T202103070400/SAT/DEN5', N'Denver Poker Run', N'SAT', N'DEN5',
    '2021-03-06 23:59:00', '2021-03-07 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103062359T202103070400/SAT/DEN5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103071900T202103072300/SUN/DIS2', N'Disney Cruiseline Crossfire', N'SUN', N'DIS2',
    '2021-03-07 19:00:00', '2021-03-07 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103071900T202103072300/SUN/DIS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103072359T202103080300/SUN/NOR2', N'Norcal Sunday (KOAK, KHWD)', N'SUN', N'NOR2',
    '2021-03-07 23:59:00', '2021-03-08 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103072359T202103080300/SUN/NOR2');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103122359T202103130400/FNO/PCF1', N'PCF Presents: Never Enough Snow In Alaska FNO', N'FNO', N'PCF1',
    '2021-03-12 23:59:00', '2021-03-13 04:00:00', N'Fri',
    142, 93, 235, 1,
    350, 210, 560, 41.96,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103122359T202103130400/FNO/PCF1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103131900T202103132300/SAT/LON8', N'Long Island Sound Fly-In', N'SAT', N'LON8',
    '2021-03-13 19:00:00', '2021-03-13 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103131900T202103132300/SAT/LON8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103132359T202103140400/SAT/ORL3', N'Orlando''s St. Patrick''s Day Pre-Party', N'SAT', N'ORL3',
    '2021-03-13 23:59:00', '2021-03-14 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103132359T202103140400/SAT/ORL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103142300T202103150300/SUN/BEA2', N'Beautiful and Busy!', N'SUN', N'BEA2',
    '2021-03-14 23:00:00', '2021-03-15 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103142300T202103150300/SUN/BEA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103162359T202103170300/MWK/BVA2', N'BVA Regional Circuit: BOS and BGR', N'MWK', N'BVA2',
    '2021-03-16 23:59:00', '2021-03-17 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103162359T202103170300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103172200T202103180100/MWK/BE 2', N'Be Seen In Green', N'MWK', N'BE 2',
    '2021-03-17 22:00:00', '2021-03-18 01:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103172200T202103180100/MWK/BE 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103192300T202103200700/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2021-03-19 23:00:00', '2021-03-20 07:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103192300T202103200700/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103201000T202103202300/SAT/CRO6', N'Cross the Pacific Eastbound 2021', N'SAT', N'CRO6',
    '2021-03-20 10:00:00', '2021-03-20 23:00:00', N'Sat',
    433, 245, 678, 3,
    1568, 1184, 2752, 24.64,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103201000T202103202300/SAT/CRO6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103202300T202103210300/SAT/ZHU2', N'ZHU Presents "First Day Of Spring Fly In"', N'SAT', N'ZHU2',
    '2021-03-20 23:00:00', '2021-03-21 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103202300T202103210300/SAT/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103212300T202103220300/SUN/TUC1', N'Tucson Jazz Festival', N'SUN', N'TUC1',
    '2021-03-21 23:00:00', '2021-03-22 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103212300T202103220300/SUN/TUC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103252200T202103260100/MWK/THE1', N'The New Haven Minutemen', N'MWK', N'THE1',
    '2021-03-25 22:00:00', '2021-03-26 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103252200T202103260100/MWK/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103262300T202103270700/OMN/VAT0', N'VATUSA Presents: OMN', N'OMN', N'VAT0',
    '2021-03-26 23:00:00', '2021-03-27 07:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103262300T202103270700/OMN/VAT0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103271300T202103271500/SAT/BAC2', N'Back to Basics (feat. KMIA-EGLL)', N'SAT', N'BAC2',
    '2021-03-27 13:00:00', '2021-03-27 15:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103271300T202103271500/SAT/BAC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103272300T202103280300/SAT/BBQ2', N'BBQ and a Coke feat KATL/KMEM', N'SAT', N'BBQ2',
    '2021-03-27 23:00:00', '2021-03-28 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103272300T202103280300/SAT/BBQ2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103282100T202103290100/SUN/ZDC3', N'ZDC Presents: Cherry Blossom Fly In', N'SUN', N'ZDC3',
    '2021-03-28 21:00:00', '2021-03-29 01:00:00', N'Sun',
    195, 130, 325, 3,
    1088, 736, 1824, 17.82,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103282100T202103290100/SUN/ZDC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103292359T202103300300/MWK/BVA1', N'BVA Minor Facility Showcase: MHT', N'MWK', N'BVA1',
    '2021-03-29 23:59:00', '2021-03-30 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103292359T202103300300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202103312359T202104010300/MWK/SAC2', N'Sacramento River White Water Wednesday (KRDD, KCIC)', N'MWK', N'SAC2',
    '2021-03-31 23:59:00', '2021-04-01 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202103312359T202104010300/MWK/SAC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104031800T202104032200/SAT/BVA3', N'BVA Bunny Hop!', N'SAT', N'BVA3',
    '2021-04-03 18:00:00', '2021-04-03 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104031800T202104032200/SAT/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104032300T202104040300/SAT/ZKC1', N'ZKC Saturday Night Ops', N'SAT', N'ZKC1',
    '2021-04-03 23:00:00', '2021-04-04 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104032300T202104040300/SAT/ZKC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104042259T202104050200/SUN/NCA1', N'NCAA Championship Fly-in', N'SUN', N'NCA1',
    '2021-04-04 22:59:00', '2021-04-05 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104042259T202104050200/SUN/NCA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104062300T/MWK/SWT4', N'SWTEE at Peachtree', N'MWK', N'SWT4',
    '2021-04-06 23:00:00', NULL, N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104062300T/MWK/SWT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104092300T202104100400/FNO/SPR7', N'Spring Fling FNO!', N'FNO', N'SPR7',
    '2021-04-09 23:00:00', '2021-04-10 04:00:00', N'Fri',
    596, 575, 1171, 7,
    3288, 2984, 6272, 18.67,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104092300T202104100400/FNO/SPR7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104101800T202104102230/SAT/CIR6', N'Circle In on the Triangle', N'SAT', N'CIR6',
    '2021-04-10 18:00:00', '2021-04-10 22:30:00', N'Sat',
    267, 313, 580, 6,
    1785, 1309, 3094, 18.75,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104101800T202104102230/SAT/CIR6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104102300T/SAT/QUE2', N'Queen City Crossfire', N'SAT', N'QUE2',
    '2021-04-10 23:00:00', NULL, N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104102300T/SAT/QUE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104112000T202104112300/SUN/DEN2', N'Denver Vancouver Crossfire!', N'SUN', N'DEN2',
    '2021-04-11 20:00:00', '2021-04-11 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104112000T202104112300/SUN/DEN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104122359T202104130400/MWK/BLO1', N'Blow your Tax Refund in Vegas', N'MWK', N'BLO1',
    '2021-04-12 23:59:00', '2021-04-13 04:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104122359T202104130400/MWK/BLO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104132300T202104140300/MWK/SUN1', N'SUN''nFUN (Feat. KLAL)', N'MWK', N'SUN1',
    '2021-04-13 23:00:00', '2021-04-14 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104132300T202104140300/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104152200T202104160100/MWK/ZNY1', N'ZNY Tower Thursday: CDW', N'MWK', N'ZNY1',
    '2021-04-15 22:00:00', '2021-04-16 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104152200T202104160100/MWK/ZNY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104172000T202104180300/SAT/A T2', N'A Trip to the Andes', N'SAT', N'A T2',
    '2021-04-17 20:00:00', '2021-04-18 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104172000T202104180300/SAT/A T2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104182000T202104190000/SUN/THE3', N'The Virginia Showdown', N'SUN', N'THE3',
    '2021-04-18 20:00:00', '2021-04-19 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104182000T202104190000/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104202320T202104210320/MWK/PAI1', N'Paine in my Pasco Crossfire', N'MWK', N'PAI1',
    '2021-04-20 23:20:00', '2021-04-21 03:20:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104202320T202104210320/MWK/PAI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104212359T202104220300/MWK/BVA4', N'BVA GA Fly-In: The Great Cape Escape', N'MWK', N'BVA4',
    '2021-04-21 23:59:00', '2021-04-22 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104212359T202104220300/MWK/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104222300T202104230200/MWK/ZFW11', N'ZFW Presents the "D10 Delta Staff up"', N'MWK', N'ZFW11',
    '2021-04-22 23:00:00', '2021-04-23 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104222300T202104230200/MWK/ZFW11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104232300T202104240300/FNO/NOR3', N'Northern Crossings IV', N'FNO', N'NOR3',
    '2021-04-23 23:00:00', '2021-04-24 03:00:00', N'Fri',
    276, 287, 563, 3,
    1925, 1372, 3297, 17.08,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104232300T202104240300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104241100T202104242300/CTP/CRO9', N'Cross the Pond Westbound 2021', N'CTP', N'CRO9',
    '2021-04-24 11:00:00', '2021-04-24 23:00:00', N'Sat',
    914, 414, 1328, 7,
    5632, 4510, 10142, 13.09,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104241100T202104242300/CTP/CRO9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104242000T202104242300/SAT/CRO2', N'Cross the Desert', N'SAT', N'CRO2',
    '2021-04-24 20:00:00', '2021-04-24 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104242000T202104242300/SAT/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202104242300T202104250300/SAT/THE4', N'The Extreme Stream Team Event Theme', N'SAT', N'THE4',
    '2021-04-24 23:00:00', '2021-04-25 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202104242300T202104250300/SAT/THE4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105012300T202105020300/SAT/ZMP1', N'ZMP Presents: BYOB', N'SAT', N'ZMP1',
    '2021-05-01 23:00:00', '2021-05-02 03:00:00', N'Sat',
    82, 27, 109, 1,
    210, 210, 420, 25.95,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105012300T202105020300/SAT/ZMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105022100T202105030100/SUN/SEC2', N'Second Best Airport Crossfire!', N'SUN', N'SEC2',
    '2021-05-02 21:00:00', '2021-05-03 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105022100T202105030100/SUN/SEC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105042359T202105050400/MWK/NAT1', N'National Wildfire Prevention Month @TYS', N'MWK', N'NAT1',
    '2021-05-04 23:59:00', '2021-05-05 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105042359T202105050400/MWK/NAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105082300T202105090300/SAT/SHA1', N'Share a Coke with Atlanta @ KATL', N'SAT', N'SHA1',
    '2021-05-08 23:00:00', '2021-05-09 03:00:00', N'Sat',
    172, 121, 293, 1,
    800, 800, 1600, 18.31,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105082300T202105090300/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105152300T202105160300/RLOP/CAL6', N'Calscream (Real Ops) XXI', N'RLOP', N'CAL6',
    '2021-05-15 23:00:00', '2021-05-16 03:00:00', N'Sat',
    361, 415, 776, 6,
    2108, 2126, 4234, 18.33,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105152300T202105160300/RLOP/CAL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105212300T202105220300/FNO/ZMA8', N'ZMA Presents: 3 Points FNO', N'FNO', N'ZMA8',
    '2021-05-21 23:00:00', '2021-05-22 03:00:00', N'Fri',
    312, 268, 580, 5,
    2261, 1911, 4172, 13.90,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105212300T202105220300/FNO/ZMA8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105222359T202105230400/SAT/DUA2', N'Dual City Linkup (ZDV - MMTY)', N'SAT', N'DUA2',
    '2021-05-22 23:59:00', '2021-05-23 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105222359T202105230400/SAT/DUA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202105292300T202105300300/SAT/HON7', N'Honk! v2: Electric Honk-alooo', N'SAT', N'HON7',
    '2021-05-29 23:00:00', '2021-05-30 03:00:00', N'Sat',
    270, 280, 550, 4,
    1428, 1393, 2821, 19.50,
    N'Spring', 5, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202105292300T202105300300/SAT/HON7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106052200T202106060200/SAT/MAN2', N'Manhattan Madness: LGA & HPN', N'SAT', N'MAN2',
    '2021-06-05 22:00:00', '2021-06-06 02:00:00', N'Sat',
    134, 109, 243, 2,
    504, 490, 994, 24.45,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106052200T202106060200/SAT/MAN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106112300T202106120300/FNO/TRI3', N'Trips: The FNO', N'FNO', N'TRI3',
    '2021-06-11 23:00:00', '2021-06-12 03:00:00', N'Fri',
    283, 335, 618, 3,
    2436, 1736, 4172, 14.81,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106112300T202106120300/FNO/TRI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106122359T202106130400/SAT/TOG1', N'Toga Party II (KSJC)', N'SAT', N'TOG1',
    '2021-06-12 23:59:00', '2021-06-13 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106122359T202106130400/SAT/TOG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106131900T202106132300/SUN/ZMA4', N'ZMA hosts: VATSTAR''s Caribbean Adventure!', N'SUN', N'ZMA4',
    '2021-06-13 19:00:00', '2021-06-13 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106131900T202106132300/SUN/ZMA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106192300T202106200300/SAT/CAR10', N'Carolinas Crossfire', N'SAT', N'CAR10',
    '2021-06-19 23:00:00', '2021-06-20 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106192300T202106200300/SAT/CAR10');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106192359T202106202359/SAT/24 1', N'24 Hours of Mile High Mayhem at KDEN', N'SAT', N'24 1',
    '2021-06-19 23:59:00', '2021-06-20 23:59:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106192359T202106202359/SAT/24 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106212359T202106220300/MWK/BVA1', N'BVA Minor Facility Showcase: BTV', N'MWK', N'BVA1',
    '2021-06-21 23:59:00', '2021-06-22 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106212359T202106220300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106252359T202106260400/FNO/FAJ5', N'Fajita Friday FNO', N'FNO', N'FAJ5',
    '2021-06-25 23:59:00', '2021-06-26 04:00:00', N'Fri',
    396, 416, 812, 5,
    2156, 1904, 4060, 20.00,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106252359T202106260400/FNO/FAJ5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106262300T202106270300/SAT/PLA1', N'Play Ball!', N'SAT', N'PLA1',
    '2021-06-26 23:00:00', '2021-06-27 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106262300T202106270300/SAT/PLA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106272300T202106280300/SUN/SUN1', N'Sunday in Tulsa', N'SUN', N'SUN1',
    '2021-06-27 23:00:00', '2021-06-28 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106272300T202106280300/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106282100T202106282358/MWK/HAP3', N'Happy Birthday Elon Musk', N'MWK', N'HAP3',
    '2021-06-28 21:00:00', '2021-06-28 23:58:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106282100T202106282358/MWK/HAP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106282359T202106290300/MWK/LIG4', N'Light Up Alabama', N'MWK', N'LIG4',
    '2021-06-28 23:59:00', '2021-06-29 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106282359T202106290300/MWK/LIG4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202106302359T202107010300/MWK/BVA2', N'BVA Regional Circuit: BOS and ALB', N'MWK', N'BVA2',
    '2021-06-30 23:59:00', '2021-07-01 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202106302359T202107010300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107032359T202107040400/SAT/4TH3', N'4th of July Preparty', N'SAT', N'4TH3',
    '2021-07-03 23:59:00', '2021-07-04 04:00:00', N'Sat',
    179, 110, 289, 3,
    1176, 938, 2114, 13.67,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107032359T202107040400/SAT/4TH3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107092300T202107100300/FNO/GUL3', N'Gulf Coast FNO', N'FNO', N'GUL3',
    '2021-07-09 23:00:00', '2021-07-10 03:00:00', N'Fri',
    249, 221, 470, 3,
    1512, 1288, 2800, 16.79,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107092300T202107100300/FNO/GUL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107102359T202107110400/SAT/SAY2', N'Say Yes to SFO', N'SAT', N'SAY2',
    '2021-07-10 23:59:00', '2021-07-11 04:00:00', N'Sat',
    161, 93, 254, 1,
    378, 378, 756, 33.60,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107102359T202107110400/SAT/SAY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107112200T202107120200/SUN/MON2', N'Montego Bay, Houston Fever', N'SUN', N'MON2',
    '2021-07-11 22:00:00', '2021-07-12 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107112200T202107120200/SUN/MON2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107132359T202107140300/MWK/BVA2', N'BVA GA Fly-In: The Maine Event', N'MWK', N'BVA2',
    '2021-07-13 23:59:00', '2021-07-14 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107132359T202107140300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107162300T202107170300/FNO/FED3', N'FedEx Rush FNO', N'FNO', N'FED3',
    '2021-07-16 23:00:00', '2021-07-17 03:00:00', N'Fri',
    246, 228, 474, 3,
    1624, 1400, 3024, 15.67,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107162300T202107170300/FNO/FED3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107172300T202107180300/SAT/CEL1', N'Celebrating 80 Years of Atlanta''s Hometown Airline', N'SAT', N'CEL1',
    '2021-07-17 23:00:00', '2021-07-18 03:00:00', N'Sat',
    184, 151, 335, 1,
    868, 700, 1568, 21.36,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107172300T202107180300/SAT/CEL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107181600T202107181900/SUN/VAT1', N'VATVENTURE 2021', N'SUN', N'VAT1',
    '2021-07-18 16:00:00', '2021-07-18 19:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107181600T202107181900/SUN/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107182300T202107190200/SUN/SAU1', N'Sausalito Summernight', N'SUN', N'SAU1',
    '2021-07-18 23:00:00', '2021-07-19 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107182300T202107190200/SUN/SAU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107222200T202107230100/MWK/VAT1', N'VATVENTURE 2021 Part 2', N'MWK', N'VAT1',
    '2021-07-22 22:00:00', '2021-07-23 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107222200T202107230100/MWK/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107232300T202107240300/FNO/SUM3', N'Summer Sizzle FNO', N'FNO', N'SUM3',
    '2021-07-23 23:00:00', '2021-07-24 03:00:00', N'Fri',
    252, 307, 559, 3,
    2304, 1836, 4140, 13.50,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107232300T202107240300/FNO/SUM3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107241800T202107242100/SAT/BVA1', N'BVA Minor Facility Showcase: SYR', N'SAT', N'BVA1',
    '2021-07-24 18:00:00', '2021-07-24 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107241800T202107242100/SAT/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107242100T202107260300/SAT/30 1', N'30 Hours of EWR', N'SAT', N'30 1',
    '2021-07-24 21:00:00', '2021-07-26 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107242100T202107260300/SAT/30 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107262300T/MWK/SMO2', N'Smoky Mountain Crossfire', N'MWK', N'SMO2',
    '2021-07-26 23:00:00', NULL, N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107262300T/MWK/SMO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107272300T202107280200/MWK/ZMP2', N'ZMP Presents: TRACON Tuesdays', N'MWK', N'ZMP2',
    '2021-07-27 23:00:00', '2021-07-28 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107272300T202107280200/MWK/ZMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107282359T202107290300/MWK/BVA2', N'BVA Regional Circuit: BOS and PWM', N'MWK', N'BVA2',
    '2021-07-28 23:59:00', '2021-07-29 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107282359T202107290300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202107312359T202108010400/SAT/DEN1', N'Denver ARTCC Saturday Night Ops', N'SAT', N'DEN1',
    '2021-07-31 23:59:00', '2021-08-01 04:00:00', N'Sat',
    159, 111, 270, 1,
    798, 672, 1470, 18.37,
    N'Summer', 7, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202107312359T202108010400/SAT/DEN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108062300T202108070300/FNO/HOT5', N'Hot Plane Summer FNO', N'FNO', N'HOT5',
    '2021-08-06 23:00:00', '2021-08-07 03:00:00', N'Fri',
    358, 378, 736, 5,
    1952, 1972, 3924, 18.76,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108062300T202108070300/FNO/HOT5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108071600T202108072300/SAT/22N5', N'22nd Annual Boston Tea Party', N'SAT', N'22N5',
    '2021-08-07 16:00:00', '2021-08-07 23:00:00', N'Sat',
    274, 269, 543, 2,
    825, 825, 1650, 32.91,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108071600T202108072300/SAT/22N5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108081900T202108082200/SUN/THE18', N'The V3 Milkrun', N'SUN', N'THE18',
    '2021-08-08 19:00:00', '2021-08-08 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108081900T202108082200/SUN/THE18');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108082100T202108090100/SUN/WIN2', N'Winnipeg Minneapolis Crossfire', N'SUN', N'WIN2',
    '2021-08-08 21:00:00', '2021-08-09 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108082100T202108090100/SUN/WIN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108132300T202108140300/FNO/ATL6', N'Atlantic Route Madness FNO', N'FNO', N'ATL6',
    '2021-08-13 23:00:00', '2021-08-14 03:00:00', N'Fri',
    309, 328, 637, 6,
    2520, 2408, 4928, 12.93,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108132300T202108140300/FNO/ATL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108141400T202108141700/SAT/BVA4', N'BVA GA Fly-In: Flapjacks in the Adirondacks', N'SAT', N'BVA4',
    '2021-08-14 14:00:00', '2021-08-14 17:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108141400T202108141700/SAT/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108142300T202108150200/SAT/PHI1', N'Philly 1 Runway', N'SAT', N'PHI1',
    '2021-08-14 23:00:00', '2021-08-15 02:00:00', N'Sat',
    111, 119, 230, 1,
    144, 144, 288, 79.86,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108142300T202108150200/SAT/PHI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108152359T202108160300/SUN/MON2', N'Monsoon Madness', N'SUN', N'MON2',
    '2021-08-15 23:59:00', '2021-08-16 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108152359T202108160300/SUN/MON2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108212359T202108220130/SAT/PEA2', N'Peaches to Pikes', N'SAT', N'PEA2',
    '2021-08-21 23:59:00', '2021-08-22 01:30:00', N'Sat',
    214, 202, 416, 2,
    1968, 1712, 3680, 11.30,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108212359T202108220130/SAT/PEA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108221700T202108222000/SUN/GA 3', N'GA Dream Cruise', N'SUN', N'GA 3',
    '2021-08-22 17:00:00', '2021-08-22 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108221700T202108222000/SUN/GA 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108272300T202108280300/FNO/ZMP4', N'ZMP/ZKC/ZFW Present FNO: North and South on Interstate 35', N'FNO', N'ZMP4',
    '2021-08-27 23:00:00', '2021-08-28 03:00:00', N'Fri',
    301, 301, 602, 4,
    1808, 1792, 3600, 16.72,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108272300T202108280300/FNO/ZMP4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108282300T202108290300/SAT/NOR1', N'Northern Migration XVI - The Great Minnesota Get-Together', N'SAT', N'NOR1',
    '2021-08-28 23:00:00', '2021-08-29 03:00:00', N'Sat',
    142, 108, 250, 1,
    480, 720, 1200, 20.83,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108282300T202108290300/SAT/NOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108292300T202108300200/SUN/CAL4', N'Caltrain Commute', N'SUN', N'CAL4',
    '2021-08-29 23:00:00', '2021-08-30 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108292300T202108300200/SUN/CAL4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109041100T202109042300/SAT/CRO6', N'Cross the Arctic', N'SAT', N'CRO6',
    '2021-09-04 11:00:00', '2021-09-04 23:00:00', N'Sat',
    74, 52, 126, 1,
    350, 224, 574, 21.95,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109041100T202109042300/SAT/CRO6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109042300T202109050300/SAT/THE1', N'The O''Hare Special', N'SAT', N'THE1',
    '2021-09-04 23:00:00', '2021-09-05 03:00:00', N'Sat',
    197, 98, 295, 1,
    800, 608, 1408, 20.95,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109042300T202109050300/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109052200T202109060200/SUN/LAK3', N'Lake Day Adventure', N'SUN', N'LAK3',
    '2021-09-05 22:00:00', '2021-09-06 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109052200T202109060200/SUN/LAK3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109102300T202109110300/FNO/THE3', N'The JFK FNO', N'FNO', N'THE3',
    '2021-09-10 23:00:00', '2021-09-11 03:00:00', N'Fri',
    280, 172, 452, 3,
    848, 664, 1512, 29.89,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109102300T202109110300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109121700T202109122100/SUN/BRA5', N'Bradley Bash 2021', N'SUN', N'BRA5',
    '2021-09-12 17:00:00', '2021-09-12 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109121700T202109122100/SUN/BRA5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109162359T202109170300/MWK/ZHU3', N'ZHU, ZLA, & VATSIM Mexico Present: Día de la Independencia', N'MWK', N'ZHU3',
    '2021-09-16 23:59:00', '2021-09-17 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109162359T202109170300/MWK/ZHU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109182359T202109190400/SAT/TOG2', N'Toga Party II', N'SAT', N'TOG2',
    '2021-09-18 23:59:00', '2021-09-19 04:00:00', N'Sat',
    112, 64, 176, 1,
    244, 204, 448, 39.29,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109182359T202109190400/SAT/TOG2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109191900T202109202200/SUN/SUN1', N'Sunday Funday @ Key West', N'SUN', N'SUN1',
    '2021-09-19 19:00:00', '2021-09-20 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109191900T202109202200/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109192300T202109200300/SUN/VFR5', N'VFRRRR', N'SUN', N'VFR5',
    '2021-09-19 23:00:00', '2021-09-20 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109192300T202109200300/SUN/VFR5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109212300T202109220200/MWK/NEW2', N'New York''s Class Delta Clash', N'MWK', N'NEW2',
    '2021-09-21 23:00:00', '2021-09-22 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109212300T202109220200/MWK/NEW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109222300T202109230300/MWK/ZFW1', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW1',
    '2021-09-22 23:00:00', '2021-09-23 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109222300T202109230300/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109242300T202109250300/FNO/FNO2', N'FNOklahoma', N'FNO', N'FNO2',
    '2021-09-24 23:00:00', '2021-09-25 03:00:00', N'Fri',
    149, 70, 219, 2,
    840, 840, 1680, 13.04,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109242300T202109250300/FNO/FNO2');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109262359T202109270400/SUN/ZLA9', N'ZLA Presents: SoCal Sunday', N'SUN', N'ZLA9',
    '2021-09-26 23:59:00', '2021-09-27 04:00:00', N'Sun',
    57, 58, 115, 1,
    144, 144, 288, 39.93,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109262359T202109270400/SUN/ZLA9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109290000T202109290300/MWK/ZHU3', N'ZHU, XP72, & Shaquille Oatmeal Presents: Battle for I10', N'MWK', N'ZHU3',
    '2021-09-29 00:00:00', '2021-09-29 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109290000T202109290300/MWK/ZHU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202109292300T202109300200/MWK/BVA2', N'BVA Regional Circuit: BOS, BTV', N'MWK', N'BVA2',
    '2021-09-29 23:00:00', '2021-09-30 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202109292300T202109300200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110032359T202110040400/SUN/ALB6', N'Albuquerque Balloon Fiesta', N'SUN', N'ALB6',
    '2021-10-03 23:59:00', '2021-10-04 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110032359T202110040400/SUN/ALB6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110062300T202110070200/MWK/ZFW2', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW2',
    '2021-10-06 23:00:00', '2021-10-07 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110062300T202110070200/MWK/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110072359T202110080300/MWK/DOW3', N'Down the Coast', N'MWK', N'DOW3',
    '2021-10-07 23:59:00', '2021-10-08 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110072359T202110080300/MWK/DOW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110091800T202110092200/SAT/BVA4', N'BVA GA Fly-In: Upstate New York', N'SAT', N'BVA4',
    '2021-10-09 18:00:00', '2021-10-09 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110091800T202110092200/SAT/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110092300T202110100300/SAT/AUT1', N'Autumn in New York', N'SAT', N'AUT1',
    '2021-10-09 23:00:00', '2021-10-10 03:00:00', N'Sat',
    126, 89, 215, 1,
    304, 288, 592, 36.32,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110092300T202110100300/SAT/AUT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110101600T202110101900/SUN/ROC4', N'Rocky Mountain Mayhem', N'SUN', N'ROC4',
    '2021-10-10 16:00:00', '2021-10-10 19:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110101600T202110101900/SUN/ROC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110132300T202110140200/MWK/WOO4', N'Wooo Pig Sooie', N'MWK', N'WOO4',
    '2021-10-13 23:00:00', '2021-10-14 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110132300T202110140200/MWK/WOO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110152300T202110160300/FNO/VAT3', N'VATPAC Presents: FNO Down Under', N'FNO', N'VAT3',
    '2021-10-15 23:00:00', '2021-10-16 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110152300T202110160300/FNO/VAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110162300T/LIVE/SOU2', N'South Beach Live', N'LIVE', N'SOU2',
    '2021-10-16 23:00:00', NULL, N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110162300T/LIVE/SOU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110171900T202110172200/SUN/CEL1', N'Celebrating 600 Phenom 300 Deliveries at Melbourne (KMLB)', N'SUN', N'CEL1',
    '2021-10-17 19:00:00', '2021-10-17 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110171900T202110172200/SUN/CEL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110172300T202110180200/SUN/PAC3', N'Pacific Northwest Shenanigans', N'SUN', N'PAC3',
    '2021-10-17 23:00:00', '2021-10-18 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110172300T202110180200/SUN/PAC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110192300T202110200200/MWK/ZAU25', N'ZAU''s Minor TRACON Tuesday', N'MWK', N'ZAU25',
    '2021-10-19 23:00:00', '2021-10-20 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110192300T202110200200/MWK/ZAU25');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110202300T202110210200/MWK/ZFW2', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW2',
    '2021-10-20 23:00:00', '2021-10-21 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110202300T202110210200/MWK/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110222300T202110230300/FNO/B&O3', N'B&O Railroad FNO', N'FNO', N'B&O3',
    '2021-10-22 23:00:00', '2021-10-23 03:00:00', N'Fri',
    210, 183, 393, 3,
    1554, 1344, 2898, 13.56,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110222300T202110230300/FNO/B&O3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110241900T202110242200/SUN/SHO1', N'Showcase Sunday: Savannah/Hilton Head Airport (KSAV)', N'SUN', N'SHO1',
    '2021-10-24 19:00:00', '2021-10-24 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110241900T202110242200/SUN/SHO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110292300T202110300300/FNO/NOT6', N'Not so Scary FNO Part III: Electric Troogaloo', N'FNO', N'NOT6',
    '2021-10-29 23:00:00', '2021-10-30 03:00:00', N'Fri',
    250, 274, 524, 6,
    2485, 2030, 4515, 11.61,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110292300T202110300300/FNO/NOT6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110300900T202110310300/CTP/CRO7', N'Cross the Pond Eastbound 2021', N'CTP', N'CRO7',
    '2021-10-30 09:00:00', '2021-10-31 03:00:00', N'Sat',
    177, 1076, 1253, 6,
    3680, 3870, 7550, 16.60,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110300900T202110310300/CTP/CRO7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110302300T202110310300/SAT/FLI1', N'Flight of the Living Dead', N'SAT', N'FLI1',
    '2021-10-30 23:00:00', '2021-10-31 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110302300T202110310300/SAT/FLI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110312300T202111010300/SUN/WHY3', N'Why do Ghosts wear Clothes?', N'SUN', N'WHY3',
    '2021-10-31 23:00:00', '2021-11-01 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110312300T202111010300/SUN/WHY3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111032300T202111040200/MWK/ZFW2', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW2',
    '2021-11-03 23:00:00', '2021-11-04 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111032300T202111040200/MWK/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111061900T202111062200/SAT/PEN1', N'Pensacola Air Show! (KPNS)', N'SAT', N'PEN1',
    '2021-11-06 19:00:00', '2021-11-06 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111061900T202111062200/SAT/PEN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111062359T202111070400/SAT/SAC3', N'Sacramento Saturday', N'SAT', N'SAC3',
    '2021-11-06 23:59:00', '2021-11-07 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111062359T202111070400/SAT/SAC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111072200T202111080200/SUN/BEA3', N'Beach Day Sunday by Pacific Control Facility', N'SUN', N'BEA3',
    '2021-11-07 22:00:00', '2021-11-08 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111072200T202111080200/SUN/BEA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111132300T202111140300/FNO/THE3', N'The Event Formerly Known as the Midwest FNO', N'FNO', N'THE3',
    '2021-11-13 23:00:00', '2021-11-14 03:00:00', N'Sat',
    221, 265, 486, 3,
    2121, 1428, 3549, 13.69,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111132300T202111140300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111141900T202111142200/SUN/NEW1', N'Newark Short Field Ops', N'SUN', N'NEW1',
    '2021-11-14 19:00:00', '2021-11-14 22:00:00', N'Sun',
    133, 93, 226, 1,
    266, 308, 574, 39.37,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111141900T202111142200/SUN/NEW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111162300T202111170300/MWK/GRE6', N'Great Lakes Dash 2', N'MWK', N'GRE6',
    '2021-11-16 23:00:00', '2021-11-17 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111162300T202111170300/MWK/GRE6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111172300T202111180200/MWK/ZFW1', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW1',
    '2021-11-17 23:00:00', '2021-11-18 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111172300T202111180200/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111191600T202111210400/MWK/60 1', N'60 Hours of BVARTCC', N'MWK', N'60 1',
    '2021-11-19 16:00:00', '2021-11-21 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111191600T202111210400/MWK/60 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111192359T202111200400/FNO/60 1', N'60 Hours Kickoff FNO', N'FNO', N'60 1',
    '2021-11-19 23:59:00', '2021-11-20 04:00:00', N'Fri',
    193, 156, 349, 1,
    308, 210, 518, 67.37,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111192359T202111200400/FNO/60 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111202300T202111210300/SAT/STU2', N'Stuff the Albu-Turkey', N'SAT', N'STU2',
    '2021-11-20 23:00:00', '2021-11-21 03:00:00', N'Sat',
    88, 85, 173, 2,
    720, 504, 1224, 14.13,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111202300T202111210300/SAT/STU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202111212200T202111220200/SUN/ROC2', N'Rocky Mountain Run', N'SUN', N'ROC2',
    '2021-11-21 22:00:00', '2021-11-22 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202111212200T202111220200/SUN/ROC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112012300T202112020200/MWK/ZFW3', N'ZFW Presents the "Fort Worth Smackdown"', N'MWK', N'ZFW3',
    '2021-12-01 23:00:00', '2021-12-02 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112012300T202112020200/MWK/ZFW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112040000T202112040400/FNO/WIN4', N'Winter on the West Coast FNO', N'FNO', N'WIN4',
    '2021-12-04 00:00:00', '2021-12-04 04:00:00', N'Sat',
    315, 398, 713, 4,
    1392, 1392, 2784, 25.61,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112040000T202112040400/FNO/WIN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112042359T202112050400/LIVE/ZDC3', N'ZDC Live ft. Capital Christmas', N'LIVE', N'ZDC3',
    '2021-12-04 23:59:00', '2021-12-05 04:00:00', N'Sat',
    144, 84, 228, 3,
    1008, 792, 1800, 12.67,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112042359T202112050400/LIVE/ZDC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112051700T202112052100/SUN/OPE5', N'Operation Good Cheer', N'SUN', N'OPE5',
    '2021-12-05 17:00:00', '2021-12-05 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112051700T202112052100/SUN/OPE5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112102359T202112110400/FNO/RIV3', N'Riverboat FNO', N'FNO', N'RIV3',
    '2021-12-10 23:59:00', '2021-12-11 04:00:00', N'Fri',
    213, 220, 433, 3,
    1008, 900, 1908, 22.69,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112102359T202112110400/FNO/RIV3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112112359T202112120400/SAT/CHR2', N'Christmas Crossfire: KMCO-KBOS', N'SAT', N'CHR2',
    '2021-12-11 23:59:00', '2021-12-12 04:00:00', N'Sat',
    244, 252, 496, 2,
    960, 752, 1712, 28.97,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112112359T202112120400/SAT/CHR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112170100T202112170400/MWK/BVA1', N'BVA Minor Facility Showcase: Bradley Intl. Airport', N'MWK', N'BVA1',
    '2021-12-17 01:00:00', '2021-12-17 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112170100T202112170400/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112172359T202112180400/FNO/PCF5', N'PCF Presents 4th Annual Ending the Year in Paradise', N'FNO', N'PCF5',
    '2021-12-17 23:59:00', '2021-12-18 04:00:00', N'Fri',
    103, 90, 193, 1,
    448, 448, 896, 21.54,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112172359T202112180400/FNO/PCF5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202112182300T202112190300/SAT/ZMP2', N'ZMP Last Minute Shopping - let it SNO!', N'SAT', N'ZMP2',
    '2021-12-18 23:00:00', '2021-12-19 03:00:00', N'Sat',
    153, 73, 226, 1,
    544, 528, 1072, 21.08,
    N'Winter', 12, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202112182300T202112190300/SAT/ZMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201012359T202201020400/SAT/NEW3', N'New Year New York!', N'SAT', N'NEW3',
    '2022-01-01 23:59:00', '2022-01-02 04:00:00', N'Sat',
    310, 206, 516, 3,
    912, 768, 1680, 30.71,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201012359T202201020400/SAT/NEW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201082300T202201090300/SAT/HOB1', N'Hobby Day at Hobby', N'SAT', N'HOB1',
    '2022-01-08 23:00:00', '2022-01-09 03:00:00', N'Sat',
    110, 69, 179, 1,
    192, 240, 432, 41.44,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201082300T202201090300/SAT/HOB1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201282359T202201290400/FNO/ESC3', N'Escape the Cold FNO', N'FNO', N'ESC3',
    '2022-01-28 23:59:00', '2022-01-29 04:00:00', N'Fri',
    397, 334, 731, 3,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201282359T202201290400/FNO/ESC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201292359T202201300400/SAT/ZMP1', N'ZMP Presents: Operation Deep Freeze 2022', N'SAT', N'ZMP1',
    '2022-01-29 23:59:00', '2022-01-30 04:00:00', N'Sat',
    145, 89, 234, 1,
    544, 528, 1072, 21.83,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201292359T202201300400/SAT/ZMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201302300T202201310200/SUN/CRO5', N'Cross the Lake (Tahoe)', N'SUN', N'CRO5',
    '2022-01-30 23:00:00', '2022-01-31 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201302300T202201310200/SUN/CRO5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202042200T202202050200/FNO/HOC1', N'Hockey Night in Halifax FNO', N'FNO', N'HOC1',
    '2022-02-04 22:00:00', '2022-02-05 02:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202042200T202202050200/FNO/HOC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202052359T202202060400/SAT/NEV1', N'Never Dull at Dulles', N'SAT', N'NEV1',
    '2022-02-05 23:59:00', '2022-02-06 04:00:00', N'Sat',
    204, 122, 326, 1,
    768, 576, 1344, 24.26,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202052359T202202060400/SAT/NEV1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202061800T202202062359/SUN/EST3', N'Estimating Bermuda At?', N'SUN', N'EST3',
    '2022-02-06 18:00:00', '2022-02-06 23:59:00', N'Sun',
    162, 169, 331, 3,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202061800T202202062359/SUN/EST3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202082359T202202090300/MWK/BVA1', N'BVA Minor Facility Showcase: Portland Intl. Jetport', N'MWK', N'BVA1',
    '2022-02-08 23:59:00', '2022-02-09 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202082359T202202090300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202112300T202202120300/FNO/ZFW3', N'ZFW Presents: Feel the Love FNO', N'FNO', N'ZFW3',
    '2022-02-11 23:00:00', '2022-02-12 03:00:00', N'Fri',
    229, 208, 437, 3,
    1632, 1088, 2720, 16.07,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202112300T202202120300/FNO/ZFW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202121700T202202122200/SAT/CIN1', N'Cincinnati Super Bowl Pre-Party', N'SAT', N'CIN1',
    '2022-02-12 17:00:00', '2022-02-12 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202121700T202202122200/SAT/CIN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202130100T202202130400/SUN/MIL1', N'Mile High Love', N'SUN', N'MIL1',
    '2022-02-13 01:00:00', '2022-02-13 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202130100T202202130400/SUN/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202132000T202202132300/SUN/SUN6', N'Sunday Funday Ft. Florida''s I-4 Corridor!', N'SUN', N'SUN6',
    '2022-02-13 20:00:00', '2022-02-13 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202132000T202202132300/SUN/SUN6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202170200T202202170300/MWK/BUR1', N'Burbank Thursday Fly-In', N'MWK', N'BUR1',
    '2022-02-17 02:00:00', '2022-02-17 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202170200T202202170300/MWK/BUR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202182359T202202190400/FNO/8TH3', N'8th Annual Northeast Corridor FNO feat. DCA, JFK, & BOS', N'FNO', N'8TH3',
    '2022-02-18 23:59:00', '2022-02-19 04:00:00', N'Fri',
    397, 312, 709, 3,
    966, 588, 1554, 45.62,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202182359T202202190400/FNO/8TH3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202201700T202202202100/SUN/OPE1', N'Operation: Buffalo Wings', N'SUN', N'OPE1',
    '2022-02-20 17:00:00', '2022-02-20 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202201700T202202202100/SUN/OPE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202261900T202202262300/SAT/BVA3', N'BVA Ski Trip: BTV, MHT, and LEB', N'SAT', N'BVA3',
    '2022-02-26 19:00:00', '2022-02-26 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202261900T202202262300/SAT/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203030130T202203030300/MWK/FLY2', N'Fly-In Thursday with TPC', N'MWK', N'FLY2',
    '2022-03-03 01:30:00', '2022-03-03 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203030130T202203030300/MWK/FLY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203050100T202203050500/FNO/COA9', N'Coast Starlight FNO', N'FNO', N'COA9',
    '2022-03-05 01:00:00', '2022-03-05 05:00:00', N'Sat',
    358, 429, 787, 6,
    2288, 2368, 4656, 16.90,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203050100T202203050500/FNO/COA9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203052359T202203060400/SAT/PHI1', N'Philadelphia Converging Runway Display Aids', N'SAT', N'PHI1',
    '2022-03-05 23:59:00', '2022-03-06 04:00:00', N'Sat',
    147, 73, 220, 1,
    420, 420, 840, 26.19,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203052359T202203060400/SAT/PHI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203082359T202203090300/MWK/BVA2', N'BVA Regional Circuit: BOS and BDL', N'MWK', N'BVA2',
    '2022-03-08 23:59:00', '2022-03-09 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203082359T202203090300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203110100T202203110400/MWK/TRO1', N'Trouble at Teterboro', N'MWK', N'TRO1',
    '2022-03-11 01:00:00', '2022-03-11 04:00:00', N'Fri',
    50, 29, 79, 1,
    192, 180, 372, 21.24,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203110100T202203110400/MWK/TRO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203182300T202203190300/FNO/POT4', N'Pot O'' Gold FNO', N'FNO', N'POT4',
    '2022-03-18 23:00:00', '2022-03-19 03:00:00', N'Fri',
    261, 261, 522, 4,
    1312, 1200, 2512, 20.78,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203182300T202203190300/FNO/POT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203201700T202203202000/SUN/ZBW9', N'ZBW and ZNY: Long Island Sound Fly-In', N'SUN', N'ZBW9',
    '2022-03-20 17:00:00', '2022-03-20 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203201700T202203202000/SUN/ZBW9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203252300T202203260300/FNO/NOR3', N'Northern Crossings V', N'FNO', N'NOR3',
    '2022-03-25 23:00:00', '2022-03-26 03:00:00', N'Fri',
    328, 329, 657, 3,
    688, 320, 1008, 65.18,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203252300T202203260300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203262300T202203270300/SAT/CHE3', N'Cherry Blossom Fly-In', N'SAT', N'CHE3',
    '2022-03-26 23:00:00', '2022-03-27 03:00:00', N'Sat',
    244, 133, 377, 3,
    1376, 1136, 2512, 15.01,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203262300T202203270300/SAT/CHE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203272000T202203280000/SUN/ZLA4', N'ZLA Presents: Welcome to the Oscars', N'SUN', N'ZLA4',
    '2022-03-27 20:00:00', '2022-03-28 00:00:00', N'Sun',
    126, 124, 250, 1,
    476, 476, 952, 26.26,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203272000T202203280000/SUN/ZLA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202203312359T202204010300/MWK/CHA1', N'Charleston Fly-In (KCHS)', N'MWK', N'CHA1',
    '2022-03-31 23:59:00', '2022-04-01 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202203312359T202204010300/MWK/CHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204021700T202204030000/CTP/CRO12', N'Cross the Pond Westbound 2022', N'CTP', N'CRO12',
    '2022-04-02 17:00:00', '2022-04-03 00:00:00', N'Sat',
    872, 425, 1297, 6,
    4450, 3650, 8100, 16.01,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204021700T202204030000/CTP/CRO12');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204072359T202204080300/MWK/BVA2', N'BVA Regional Circuit: BOS and ALB', N'MWK', N'BVA2',
    '2022-04-07 23:59:00', '2022-04-08 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204072359T202204080300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204082359T202204090400/FNO/SKI3', N'Skiing into Spring FNO', N'FNO', N'SKI3',
    '2022-04-08 23:59:00', '2022-04-09 04:00:00', N'Fri',
    324, 342, 666, 3,
    1984, 1840, 3824, 17.42,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204082359T202204090400/FNO/SKI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204100030T202204100300/SUN/TPC4', N'TPC Birthday Flight', N'SUN', N'TPC4',
    '2022-04-10 00:30:00', '2022-04-10 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204100030T202204100300/SUN/TPC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204132300T202204140200/MWK/ZFW1', N'ZFW Focus: Abilene Regional Airport', N'MWK', N'ZFW1',
    '2022-04-13 23:00:00', '2022-04-14 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204132300T202204140200/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204152300T202204160300/FNO/SPR4', N'Springtime Celebration FNO', N'FNO', N'SPR4',
    '2022-04-15 23:00:00', '2022-04-16 03:00:00', N'Fri',
    318, 312, 630, 4,
    2232, 1936, 4168, 15.12,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204152300T202204160300/FNO/SPR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204162359T202204170400/SAT/SHA1', N'Share a Coke in Atlanta 2022', N'SAT', N'SHA1',
    '2022-04-16 23:59:00', '2022-04-17 04:00:00', N'Sat',
    218, 176, 394, 1,
    924, 700, 1624, 24.26,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204162359T202204170400/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204232359T202204240400/SAT/HOU4', N'Houston Montego Bay Fever II', N'SAT', N'HOU4',
    '2022-04-23 23:59:00', '2022-04-24 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204232359T202204240400/SAT/HOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204241900T202204242200/SUN/IT''1', N'It''s Pronounced Westchesta', N'SUN', N'IT''1',
    '2022-04-24 19:00:00', '2022-04-24 22:00:00', N'Sun',
    58, 26, 84, 1,
    160, 150, 310, 27.10,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204241900T202204242200/SUN/IT''1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204262300T202204270100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-04-26 23:00:00', '2022-04-27 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204262300T202204270100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204280300T202204280500/MWK/ZHU3', N'ZHU Presents: Creole Coast', N'MWK', N'ZHU3',
    '2022-04-28 03:00:00', '2022-04-28 05:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204280300T202204280500/MWK/ZHU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204292300T202204300300/FNO/GUL3', N'Gulf Coast FNO 2', N'FNO', N'GUL3',
    '2022-04-29 23:00:00', '2022-04-30 03:00:00', N'Fri',
    262, 275, 537, 3,
    1211, 868, 2079, 25.83,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204292300T202204300300/FNO/GUL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204302300T202205010300/SAT/BYO1', N'BYOB - Bring Your Own Bizjet (KOMA)', N'SAT', N'BYO1',
    '2022-04-30 23:00:00', '2022-05-01 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204302300T202205010300/SAT/BYO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205011900T202205012200/SUN/BVA1', N'BVA Minor Facility Showcase: Bangor Intl. Airport', N'SUN', N'BVA1',
    '2022-05-01 19:00:00', '2022-05-01 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205011900T202205012200/SUN/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205042300T202205050200/MWK/ZFW1', N'ZFW Focus: East Texas Regional Airport (KGGG)', N'MWK', N'ZFW1',
    '2022-05-04 23:00:00', '2022-05-05 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205042300T202205050200/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205052359T202205060200/MWK/FLY1', N'Fly-In Thursday: Monterey (MRY)', N'MWK', N'FLY1',
    '2022-05-05 23:59:00', '2022-05-06 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205052359T202205060200/MWK/FLY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205062300T202205070300/FNO/DER3', N'Derby Day FNO', N'FNO', N'DER3',
    '2022-05-06 23:00:00', '2022-05-07 03:00:00', N'Fri',
    229, 267, 496, 3,
    1428, 1428, 2856, 17.37,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205062300T202205070300/FNO/DER3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205071600T202205072000/SAT/THE2', N'The Great Flight for Charity (Spring is going to the Dogs!)', N'SAT', N'THE2',
    '2022-05-07 16:00:00', '2022-05-07 20:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205071600T202205072000/SAT/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205072300T202205080300/SAT/HON8', N'Honk! III: Revenge of the Honk', N'SAT', N'HON8',
    '2022-05-07 23:00:00', '2022-05-08 03:00:00', N'Sat',
    278, 277, 555, 4,
    1204, 1127, 2331, 23.81,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205072300T202205080300/SAT/HON8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205102300T202205110100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-05-10 23:00:00', '2022-05-11 01:00:00', N'Tue',
    67, 77, 144, 1,
    224, 224, 448, 32.14,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205102300T202205110100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205122200T202205130100/MWK/ZJX1', N'ZJX TRACON Thursdays Presents: Pensacola!', N'MWK', N'ZJX1',
    '2022-05-12 22:00:00', '2022-05-13 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205122200T202205130100/MWK/ZJX1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205142300T202205150300/SAT/PRE1', N'Preakness Stakes Fly-In', N'SAT', N'PRE1',
    '2022-05-14 23:00:00', '2022-05-15 03:00:00', N'Sat',
    126, 86, 212, 1,
    252, 252, 504, 42.06,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205142300T202205150300/SAT/PRE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205151900T202205152200/SUN/BVA4', N'BVA GA Fly-In: The Great Cape Escape', N'SUN', N'BVA4',
    '2022-05-15 19:00:00', '2022-05-15 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205151900T202205152200/SUN/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205172300T202205180100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-05-17 23:00:00', '2022-05-18 01:00:00', N'Tue',
    67, 72, 139, 1,
    406, 168, 574, 24.22,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205172300T202205180100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205192300T202205200200/MWK/THE3', N'The Pilot Club''s Fly-In Thursday: West Texas Edition', N'MWK', N'THE3',
    '2022-05-19 23:00:00', '2022-05-20 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205192300T202205200200/MWK/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205211900T202205212300/LIVE/MIA3', N'Miami Live 2022', N'LIVE', N'MIA3',
    '2022-05-21 19:00:00', '2022-05-21 23:00:00', N'Sat',
    146, 142, 288, 3,
    1544, 1496, 3040, 9.47,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205211900T202205212300/LIVE/MIA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205212300T202205220300/RLOP/CAL6', N'Calscream (Real Ops) XXII', N'RLOP', N'CAL6',
    '2022-05-21 23:00:00', '2022-05-22 03:00:00', N'Sat',
    417, 473, 890, 6,
    2304, 2192, 4496, 19.80,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205212300T202205220300/RLOP/CAL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305221800T202305222300/MWK/NEW3', N'New York Escape!', N'MWK', N'NEW3',
    '2023-05-22 18:00:00', '2023-05-22 23:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305221800T202305222300/MWK/NEW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205242300T202205250100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-05-24 23:00:00', '2022-05-25 01:00:00', N'Tue',
    56, 66, 122, 1,
    280, 266, 546, 22.34,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205242300T202205250100/MWK/TUE1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205272300T202205280300/FNO/ATL7', N'Atlantic Route Madness FNO', N'FNO', N'ATL7',
    '2022-05-27 23:00:00', '2022-05-28 03:00:00', N'Fri',
    297, 333, 630, 6,
    1841, 1820, 3661, 17.21,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205272300T202205280300/FNO/ATL7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205282100T202205290100/SAT/MEM1', N'Memphis in May', N'SAT', N'MEM1',
    '2022-05-28 21:00:00', '2022-05-29 01:00:00', N'Sat',
    83, 99, 182, 1,
    528, 528, 1056, 17.23,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205282100T202205290100/SAT/MEM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205282359T202205290300/SAT/KEN2', N'Kennywood''s Open', N'SAT', N'KEN2',
    '2022-05-28 23:59:00', '2022-05-29 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205282359T202205290300/SAT/KEN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205292000T202205292359/SUN/CAN5', N'Canucks, Oilers, & Kraken! Oh My!!', N'SUN', N'CAN5',
    '2022-05-29 20:00:00', '2022-05-29 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205292000T202205292359/SUN/CAN5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202205312300T202206010100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-05-31 23:00:00', '2022-06-01 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202205312300T202206010100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206012300T202206020200/MWK/ZFW1', N'ZFW Focus: Will Rogers World Airport (KOKC)', N'MWK', N'ZFW1',
    '2022-06-01 23:00:00', '2022-06-02 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206012300T202206020200/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206032300T202206040300/FNO/SAN5', N'San Joaquins Summer FNO', N'FNO', N'SAN5',
    '2022-06-03 23:00:00', '2022-06-04 03:00:00', N'Fri',
    192, 194, 386, 5,
    1526, 1526, 3052, 12.65,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206032300T202206040300/FNO/SAN5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206041400T202206042200/LIVE/LIV4', N'Live from NY, It''s Saturday Night!!', N'LIVE', N'LIV4',
    '2022-06-04 14:00:00', '2022-06-04 22:00:00', N'Sat',
    284, 296, 580, 4,
    1955, 1862, 3817, 15.20,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206041400T202206042200/LIVE/LIV4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206052000T202206052300/SUN/CIR4', N'Circle the Drain', N'SUN', N'CIR4',
    '2022-06-05 20:00:00', '2022-06-05 23:00:00', N'Sun',
    67, 33, 100, 1,
    98, 210, 308, 32.47,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206052000T202206052300/SUN/CIR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206072300T202206080100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-06-07 23:00:00', '2022-06-08 01:00:00', N'Tue',
    85, 62, 147, 1,
    280, 280, 560, 26.25,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206072300T202206080100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206092300T202206100100/MWK/ZJX1', N'ZJX Presents: TRACON Thursday - Jacksonville!', N'MWK', N'ZJX1',
    '2022-06-09 23:00:00', '2022-06-10 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206092300T202206100100/MWK/ZJX1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206102359T202206110400/FNO/MUS4', N'Music City FNO', N'FNO', N'MUS4',
    '2022-06-10 23:59:00', '2022-06-11 04:00:00', N'Fri',
    270, 227, 497, 3,
    1071, 1071, 2142, 23.20,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206102359T202206110400/FNO/MUS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206112300T202206120300/SAT/ZMP6', N'ZMP-ZWG Fishing Trip', N'SAT', N'ZMP6',
    '2022-06-11 23:00:00', '2022-06-12 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206112300T202206120300/SAT/ZMP6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206121900T202206122200/SUN/NY''1', N'NY''s Tribeca Film Festival', N'SUN', N'NY''1',
    '2022-06-12 19:00:00', '2022-06-12 22:00:00', N'Sun',
    94, 125, 219, 1,
    320, 320, 640, 34.22,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206121900T202206122200/SUN/NY''1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206142300T202206150100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-06-14 23:00:00', '2022-06-15 01:00:00', N'Tue',
    66, 55, 121, 1,
    252, 224, 476, 25.42,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206142300T202206150100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206152300T202206160200/MWK/BVA3', N'BVA GA Fly-In: The Seacoast Fly-In', N'MWK', N'BVA3',
    '2022-06-15 23:00:00', '2022-06-16 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206152300T202206160200/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206162300T202206170200/MWK/NIG1', N'Nightmares in Newark', N'MWK', N'NIG1',
    '2022-06-16 23:00:00', '2022-06-17 02:00:00', N'Thu',
    71, 46, 117, 1,
    280, 266, 546, 21.43,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206162300T202206170200/MWK/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206182000T202206182300/SAT/ZHU2', N'ZHU Presents: Brain vs Braun!', N'SAT', N'ZHU2',
    '2022-06-18 20:00:00', '2022-06-18 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206182000T202206182300/SAT/ZHU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206182300T202206190300/SAT/JOU1', N'Journey out of Detroit', N'SAT', N'JOU1',
    '2022-06-18 23:00:00', '2022-06-19 03:00:00', N'Sat',
    67, 117, 184, 1,
    602, 280, 882, 20.86,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206182300T202206190300/SAT/JOU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206191900T202206192300/SUN/PRI4', N'Pride Flies Crossfire', N'SUN', N'PRI4',
    '2022-06-19 19:00:00', '2022-06-19 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206191900T202206192300/SUN/PRI4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206242300T202206250300/FNO/LIG10', N'Light Up the East Coast FNO', N'FNO', N'LIG10',
    '2022-06-24 23:00:00', '2022-06-25 03:00:00', N'Fri',
    229, 279, 508, 10,
    2030, 2009, 4039, 12.58,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206242300T202206250300/FNO/LIG10');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206252200T202206260100/SAT/CHE6', N'Check Density Altitude', N'SAT', N'CHE6',
    '2022-06-25 22:00:00', '2022-06-26 01:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206252200T202206260100/SAT/CHE6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206262000T202206262300/SUN/SUN3', N'Sunday in the Bay', N'SUN', N'SUN3',
    '2022-06-26 20:00:00', '2022-06-26 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206262000T202206262300/SUN/SUN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202206282300T202206290100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-06-28 23:00:00', '2022-06-29 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202206282300T202206290100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207022300T202207030300/SAT/4TH3', N'4th of July Preparty', N'SAT', N'4TH3',
    '2022-07-02 23:00:00', '2022-07-03 03:00:00', N'Sat',
    206, 130, 336, 3,
    1204, 994, 2198, 15.29,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207022300T202207030300/SAT/4TH3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207032300T202207040300/SUN/AMO4', N'Among the Volcanoes', N'SUN', N'AMO4',
    '2022-07-03 23:00:00', '2022-07-04 03:00:00', N'Sun',
    131, 150, 281, 2,
    770, 770, 1540, 18.25,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207032300T202207040300/SUN/AMO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207052300T202207060100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-07-05 23:00:00', '2022-07-06 01:00:00', N'Tue',
    55, 56, 111, 1,
    280, 280, 560, 19.82,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207052300T202207060100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207072200T202207080100/MWK/ZJX4', N'ZJX Presents TRACON Thursday - Daytona Beach!!', N'MWK', N'ZJX4',
    '2022-07-07 22:00:00', '2022-07-08 01:00:00', N'Thu',
    57, 44, 101, 1,
    210, 210, 420, 24.05,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207072200T202207080100/MWK/ZJX4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207082300T202207090300/FNO/THE2', N'The Summer Sizzle FNO 2022', N'FNO', N'THE2',
    '2022-07-08 23:00:00', '2022-07-09 03:00:00', N'Fri',
    231, 239, 470, 2,
    1526, 1092, 2618, 17.95,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207082300T202207090300/FNO/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207102300T202207110300/SUN/FRE2', N'Freight Ops Frenzy (KOAK, KMHR)', N'SUN', N'FRE2',
    '2022-07-10 23:00:00', '2022-07-11 03:00:00', N'Sun',
    88, 101, 189, 1,
    406, 406, 812, 23.28,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207102300T202207110300/SUN/FRE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207122300T202207130100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-07-12 23:00:00', '2022-07-13 01:00:00', N'Tue',
    103, 58, 161, 1,
    252, 224, 476, 33.82,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207122300T202207130100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207132300T202207140200/MWK/BVA2', N'BVA Regional Circuit: Boston (BOS) & Syracuse (SYR)', N'MWK', N'BVA2',
    '2022-07-13 23:00:00', '2022-07-14 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207132300T202207140200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207171900T202207172200/SUN/THE1', N'The NJ Transit 42nd Anniversary!', N'SUN', N'THE1',
    '2022-07-17 19:00:00', '2022-07-17 22:00:00', N'Sun',
    95, 104, 199, 1,
    280, 266, 546, 36.45,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207171900T202207172200/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207192300T202207200100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-07-19 23:00:00', '2022-07-20 01:00:00', N'Tue',
    56, 57, 113, 1,
    280, 266, 546, 20.70,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207192300T202207200100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207202200T202207210100/MWK/VAT1', N'VATVENTURE 2022 (1)', N'MWK', N'VAT1',
    '2022-07-20 22:00:00', '2022-07-21 01:00:00', N'Wed',
    78, 10, 88, 1,
    224, 224, 448, 19.64,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207202200T202207210100/MWK/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207212200T202207220200/MWK/VAT1', N'VATVENTURE 2022 (2)', N'MWK', N'VAT1',
    '2022-07-21 22:00:00', '2022-07-22 02:00:00', N'Thu',
    94, 21, 115, 1,
    224, 224, 448, 25.67,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207212200T202207220200/MWK/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207231530T202207232330/SAT/VAT14', N'VATUSA Presents: TRANSCON 2022 Eastbound', N'SAT', N'VAT14',
    '2022-07-23 15:30:00', '2022-07-23 23:30:00', N'Sat',
    619, 796, 1415, 14,
    6743, 5905, 12648, 11.19,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207231530T202207232330/SAT/VAT14');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207232300T202207240300/SAT/MIN1', N'Minneapolis Center''s Northern Migration XVII', N'SAT', N'MIN1',
    '2022-07-23 23:00:00', '2022-07-24 03:00:00', N'Sat',
    93, 83, 176, 1,
    544, 544, 1088, 16.18,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207232300T202207240300/SAT/MIN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207262300T202207270100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-07-26 23:00:00', '2022-07-27 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207262300T202207270100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207292300T202207300300/FNO/GRE11', N'Great Lakes FNO', N'FNO', N'GRE11',
    '2022-07-29 23:00:00', '2022-07-30 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207292300T202207300300/FNO/GRE11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202207302300T202207310300/SAT/WIL1', N'Wildness in Waikiki', N'SAT', N'WIL1',
    '2022-07-30 23:00:00', '2022-07-31 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202207302300T202207310300/SAT/WIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208022300T202208030100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-08-02 23:00:00', '2022-08-03 01:00:00', N'Tue',
    74, 46, 120, 1,
    210, 224, 434, 27.65,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208022300T202208030100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208032300T202208040200/MWK/ZFW1', N'ZFW Focus: Monroe Regional Airport', N'MWK', N'ZFW1',
    '2022-08-03 23:00:00', '2022-08-04 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208032300T202208040200/MWK/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208052359T202208060400/FNO/HIG3', N'High Sierra FNO', N'FNO', N'HIG3',
    '2022-08-05 23:59:00', '2022-08-06 04:00:00', N'Fri',
    369, 387, 756, 3,
    1176, 924, 2100, 36.00,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208052359T202208060400/FNO/HIG3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208061600T202208062300/LIVE/23R1', N'23rd Annual Boston Tea Party Live!', N'LIVE', N'23R1',
    '2022-08-06 16:00:00', '2022-08-06 23:00:00', N'Sat',
    200, 204, 404, 1,
    480, 480, 960, 42.08,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208061600T202208062300/LIVE/23R1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208072000T202208080430/SUN/ISL2', N'Island to Coast', N'SUN', N'ISL2',
    '2022-08-07 20:00:00', '2022-08-08 04:30:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208072000T202208080430/SUN/ISL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208092300T202208100100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-08-09 23:00:00', '2022-08-10 01:00:00', N'Tue',
    109, 112, 221, 1,
    245, 294, 539, 41.00,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208092300T202208100100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208122359T202208130400/FNO/THE3', N'The Great Basin FNO', N'FNO', N'THE3',
    '2022-08-12 23:59:00', '2022-08-13 04:00:00', N'Fri',
    266, 289, 555, 3,
    1365, 1365, 2730, 20.33,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208122359T202208130400/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208141800T202208142200/SUN/A D3', N'A Day on the Bay', N'SUN', N'A D3',
    '2022-08-14 18:00:00', '2022-08-14 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208141800T202208142200/SUN/A D3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208182300T202208190200/MWK/BVA3', N'BVA Regional Circuit: Albany, Bradley, & Burlington', N'MWK', N'BVA3',
    '2022-08-18 23:00:00', '2022-08-19 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208182300T202208190200/MWK/BVA3');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208192300T202208200200/FNO/FRI3', N'Friday Night Frenzy', N'FNO', N'FRI3',
    '2022-08-19 23:00:00', '2022-08-20 02:00:00', N'Fri',
    289, 307, 596, 3,
    1428, 1064, 2492, 23.92,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208192300T202208200200/FNO/FRI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208202300T202208210300/SAT/HOT2', N'Hot August Nights', N'SAT', N'HOT2',
    '2022-08-20 23:00:00', '2022-08-21 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208202300T202208210300/SAT/HOT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208211700T202208211900/SUN/GA 3', N'GA Dream Cruise', N'SUN', N'GA 3',
    '2022-08-21 17:00:00', '2022-08-21 19:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208211700T202208211900/SUN/GA 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208232300T202208240100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-08-23 23:00:00', '2022-08-24 01:00:00', N'Tue',
    70, 98, 168, 1,
    280, 266, 546, 30.77,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208232300T202208240100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208252359T202208260400/MWK/FOU3', N'Four Stacks Frenzy', N'MWK', N'FOU3',
    '2022-08-25 23:59:00', '2022-08-26 04:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208252359T202208260400/MWK/FOU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208272300T202208280300/SAT/PHI1', N'Philly 1 Runway v2', N'SAT', N'PHI1',
    '2022-08-27 23:00:00', '2022-08-28 03:00:00', N'Sat',
    152, 93, 245, 1,
    224, 224, 448, 54.69,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208272300T202208280300/SAT/PHI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208271800T202208272200/SAT/COK4', N'Coke Zero Sugar 400 Fly-In', N'SAT', N'COK4',
    '2022-08-27 18:00:00', '2022-08-27 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208271800T202208272200/SAT/COK4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208281900T202208282200/SUN/THE2', N'The Next Best Thing', N'SUN', N'THE2',
    '2022-08-28 19:00:00', '2022-08-28 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208281900T202208282200/SUN/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208292300T202208300300/MWK/FAI2', N'Fair Weather Flying', N'MWK', N'FAI2',
    '2022-08-29 23:00:00', '2022-08-30 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208292300T202208300300/MWK/FAI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208302300T202208310100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-08-30 23:00:00', '2022-08-31 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208302300T202208310100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202208312300T202209010200/MWK/ZAU1', N'ZAU''s Farewell to Peoria', N'MWK', N'ZAU1',
    '2022-08-31 23:00:00', '2022-09-01 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202208312300T202209010200/MWK/ZAU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209041900T202209042200/SUN/THE2', N'The Shuttle Run', N'SUN', N'THE2',
    '2022-09-04 19:00:00', '2022-09-04 22:00:00', N'Sun',
    176, 232, 408, 2,
    707, 707, 1414, 28.85,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209041900T202209042200/SUN/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209062300T202209070100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-09-06 23:00:00', '2022-09-07 01:00:00', N'Tue',
    61, 58, 119, 1,
    266, 252, 518, 22.97,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209062300T202209070100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209102000T202209102300/SAT/VAT2', N'VATSIM First Wings: Winnipeg & Madison', N'SAT', N'VAT2',
    '2022-09-10 20:00:00', '2022-09-10 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209102000T202209102300/SAT/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209102300T202209110300/SAT/HOO1', N'Hooked on Phoeniks', N'SAT', N'HOO1',
    '2022-09-10 23:00:00', '2022-09-11 03:00:00', N'Sat',
    111, 97, 208, 1,
    608, 320, 928, 22.41,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209102300T202209110300/SAT/HOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209112100T202209120000/SUN/THE1', N'The Cowboys Opening Day Kickoff', N'SUN', N'THE1',
    '2022-09-11 21:00:00', '2022-09-12 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209112100T202209120000/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209132300T202209140100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-09-13 23:00:00', '2022-09-14 01:00:00', N'Tue',
    66, 78, 144, 1,
    252, 224, 476, 30.25,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209132300T202209140100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209150100T202209150300/MWK/WIN3', N'Wine Country Wednesday', N'MWK', N'WIN3',
    '2022-09-15 01:00:00', '2022-09-15 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209150100T202209150300/MWK/WIN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209152359T202209160300/MWK/BVA1', N'BVA Minor Facility Showcase: Syracuse Intl.', N'MWK', N'BVA1',
    '2022-09-15 23:59:00', '2022-09-16 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209152359T202209160300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209162300T202209170300/FNO/CAR4', N'Caribbean FNO', N'FNO', N'CAR4',
    '2022-09-16 23:00:00', '2022-09-17 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209162300T202209170300/FNO/CAR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209172200T202209180100/SAT/ZFW1', N'ZFW Presents: The Nolan Danziger Memorial SNO', N'SAT', N'ZFW1',
    '2022-09-17 22:00:00', '2022-09-18 01:00:00', N'Sat',
    94, 107, 201, 1,
    714, 504, 1218, 16.50,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209172200T202209180100/SAT/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209182359T202209190400/SUN/WHO4', N'Whose House??', N'SUN', N'WHO4',
    '2022-09-18 23:59:00', '2022-09-19 04:00:00', N'Sun',
    93, 90, 183, 1,
    476, 476, 952, 19.22,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209182359T202209190400/SUN/WHO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209202300T202209210100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-09-20 23:00:00', '2022-09-21 01:00:00', N'Tue',
    58, 54, 112, 1,
    280, 266, 546, 20.51,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209202300T202209210100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209222200T202209230100/MWK/ZJX2', N'ZJX Presents: TRACON Thursday - South Carolina', N'MWK', N'ZJX2',
    '2022-09-22 22:00:00', '2022-09-23 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209222200T202209230100/MWK/ZJX2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209232300T202209240300/FNO/THE3', N'The New York FNO', N'FNO', N'THE3',
    '2022-09-23 23:00:00', '2022-09-24 03:00:00', N'Fri',
    276, 162, 438, 3,
    945, 756, 1701, 25.75,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209232300T202209240300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209242300T202209250300/SAT/THI4', N'Things to do in Ohio', N'SAT', N'THI4',
    '2022-09-24 23:00:00', '2022-09-25 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209242300T202209250300/SAT/THI4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209252200T202209260200/SUN/SOC3', N'SoCal Sundays Presents: San Diego!!', N'SUN', N'SOC3',
    '2022-09-25 22:00:00', '2022-09-26 02:00:00', N'Sun',
    121, 88, 209, 1,
    192, 240, 432, 48.38,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209252200T202209260200/SUN/SOC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209272300T202209280100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-09-27 23:00:00', '2022-09-28 01:00:00', N'Tue',
    50, 57, 107, 1,
    280, 280, 560, 19.11,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209272300T202209280100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209291900T202209292200/MWK/DES2', N'Destination Pease Portsmouth, NH', N'MWK', N'DES2',
    '2022-09-29 19:00:00', '2022-09-29 22:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209291900T202209292200/MWK/DES2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202209302300T202210010300/FNO/FOR1', N'Fort Worth Presents: The Meet Me in the Middle FNO', N'FNO', N'FOR1',
    '2022-09-30 23:00:00', '2022-10-01 03:00:00', N'Fri',
    192, 133, 325, 1,
    798, 588, 1386, 23.45,
    N'Fall', 9, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202209302300T202210010300/FNO/FOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210011700T202210012100/SAT/BVA4', N'BVA GA Fly-In: Military Airports', N'SAT', N'BVA4',
    '2022-10-01 17:00:00', '2022-10-01 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210011700T202210012100/SAT/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210012359T202210020300/SAT/MEM2', N'MEM-IND FedEx Cargo Ops Event', N'SAT', N'MEM2',
    '2022-10-01 23:59:00', '2022-10-02 03:00:00', N'Sat',
    143, 122, 265, 2,
    708, 708, 1416, 18.71,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210012359T202210020300/SAT/MEM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210022200T202210030100/SUN/HAL1', N'Halloween Horror Ops', N'SUN', N'HAL1',
    '2022-10-02 22:00:00', '2022-10-03 01:00:00', N'Sun',
    117, 107, 224, 1,
    602, 448, 1050, 21.33,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210022200T202210030100/SUN/HAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210082300T202210090300/SAT/THE1', N'The Shane O''Neill Memorial Celebrity Awareness Pro-am Fun Run 5K on the Fifth Runway for the Cure', N'SAT', N'THE1',
    '2022-10-08 23:00:00', '2022-10-09 03:00:00', N'Sat',
    132, 146, 278, 1,
    700, 700, 1400, 19.86,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210082300T202210090300/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210092359T202210100300/SUN/ALB4', N'Albuquerque Balloon Fiesta Returns!', N'SUN', N'ALB4',
    '2022-10-09 23:59:00', '2022-10-10 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210092359T202210100300/SUN/ALB4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210112359T202210120300/MWK/BVA3', N'BVA Regional Circuit: Bangor, Burlington, & Manchester', N'MWK', N'BVA3',
    '2022-10-11 23:59:00', '2022-10-12 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210112359T202210120300/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210132359T202210140200/MWK/THU1', N'Thursday Night in New York', N'MWK', N'THU1',
    '2022-10-13 23:59:00', '2022-10-14 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210132359T202210140200/MWK/THU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210142300T202210150300/FNO/FNO3', N'FNO Downunder', N'FNO', N'FNO3',
    '2022-10-14 23:00:00', '2022-10-15 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210142300T202210150300/FNO/FNO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210162200T202210170200/SUN/SOC3', N'SoCal Sundays Presents: Las Vegas?!?', N'SUN', N'SOC3',
    '2022-10-16 22:00:00', '2022-10-17 02:00:00', N'Sun',
    143, 121, 264, 1,
    364, 364, 728, 36.26,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210162200T202210170200/SUN/SOC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210182330T202210190230/MWK/ZHU4', N'ZHU, ZJX, ZTL, and ZME Present The Southern Shuffle', N'MWK', N'ZHU4',
    '2022-10-18 23:30:00', '2022-10-19 02:30:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210182330T202210190230/MWK/ZHU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210221000T202210222355/CTP/CRO9', N'Cross the Pond Eastbound 2022', N'CTP', N'CRO9',
    '2022-10-22 10:00:00', '2022-10-22 23:55:00', N'Sat',
    107, 718, 825, 4,
    2940, 2700, 5640, 14.63,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210221000T202210222355/CTP/CRO9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210222300T202210230300/SAT/DAW1', N'Dawn of the Flight of the Dead', N'SAT', N'DAW1',
    '2022-10-22 23:00:00', '2022-10-23 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210222300T202210230300/SAT/DAW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210232300T202210240300/SUN/NIG1', N'Nightmare on Colfax Avenue', N'SUN', N'NIG1',
    '2022-10-23 23:00:00', '2022-10-24 03:00:00', N'Sun',
    133, 108, 241, 1,
    798, 672, 1470, 16.39,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210232300T202210240300/SUN/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210252300T202210260100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-10-25 23:00:00', '2022-10-26 01:00:00', N'Tue',
    58, 68, 126, 1,
    266, 252, 518, 24.32,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210252300T202210260100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210282300T202210290300/FNO/EVE1', N'Evening Under The Arch FNO', N'FNO', N'EVE1',
    '2022-10-28 23:00:00', '2022-10-29 03:00:00', N'Fri',
    168, 120, 288, 1,
    448, 420, 868, 33.18,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210282300T202210290300/FNO/EVE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210292300T202210300300/SAT/TET1', N'Teter-Hold at Teterboro', N'SAT', N'TET1',
    '2022-10-29 23:00:00', '2022-10-30 03:00:00', N'Sat',
    88, 75, 163, 1,
    224, 210, 434, 37.56,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210292300T202210300300/SAT/TET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210302359T202210310400/SUN/TRI1', N'Trick or SMF', N'SUN', N'TRI1',
    '2022-10-30 23:59:00', '2022-10-31 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210302359T202210310400/SUN/TRI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202210301600T202210302000/SUN/FLI3', N'FlightFactor Spooktober', N'SUN', N'FLI3',
    '2022-10-30 16:00:00', '2022-10-30 20:00:00', N'Sun',
    227, 310, 537, 3,
    1552, 1104, 2656, 20.22,
    N'Fall', 10, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202210301600T202210302000/SUN/FLI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211012300T202211020100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-11-01 23:00:00', '2022-11-02 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211012300T202211020100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211032300T202211040200/MWK/FIG1', N'Fight VXVs Fight!', N'MWK', N'FIG1',
    '2022-11-03 23:00:00', '2022-11-04 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211032300T202211040200/MWK/FIG1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211042300T202211050300/FNO/MON1', N'Moncton Friday!', N'FNO', N'MON1',
    '2022-11-04 23:00:00', '2022-11-05 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211042300T202211050300/FNO/MON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211052300T202211060300/LIVE/CLA3', N'Class Charlie Chaos (feat. ZDC Live)', N'LIVE', N'CLA3',
    '2022-11-05 23:00:00', '2022-11-06 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211052300T202211060300/LIVE/CLA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211061700T202211062100/SUN/BRA5', N'Bradley Bash 2022!', N'SUN', N'BRA5',
    '2022-11-06 17:00:00', '2022-11-06 21:00:00', N'Sun',
    43, 66, 109, 1,
    224, 224, 448, 24.33,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211061700T202211062100/SUN/BRA5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211062100T202211062359/SUN/SAN5', N'Santa Barbara and Bakersfield Staff Up!!', N'SUN', N'SAN5',
    '2022-11-06 21:00:00', '2022-11-06 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211062100T202211062359/SUN/SAN5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211082359T202211090200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-11-08 23:59:00', '2022-11-09 02:00:00', N'Tue',
    70, 54, 124, 1,
    252, 266, 518, 23.94,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211082359T202211090200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211092300T202211100200/MWK/PAR3', N'Paradise in the Valley (VLY)', N'MWK', N'PAR3',
    '2022-11-09 23:00:00', '2022-11-10 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211092300T202211100200/MWK/PAR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211112359T202211120400/FNO/SAL6', N'Salute to Veterans', N'FNO', N'SAL6',
    '2022-11-11 23:59:00', '2022-11-12 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211112359T202211120400/FNO/SAL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211122359T202211130400/SAT/THE1', N'The Great JFK SNO', N'SAT', N'THE1',
    '2022-11-12 23:59:00', '2022-11-13 04:00:00', N'Sat',
    189, 106, 295, 1,
    406, 168, 574, 51.39,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211122359T202211130400/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211140100T202211140500/MWK/TOG2', N'Toga Party III: Return of the TO/GA', N'MWK', N'TOG2',
    '2022-11-14 01:00:00', '2022-11-14 05:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211140100T202211140500/MWK/TOG2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211142359T202211150300/MWK/CRO4', N'Cross the Gulf Southbound', N'MWK', N'CRO4',
    '2022-11-14 23:59:00', '2022-11-15 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211142359T202211150300/MWK/CRO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211152359T202211160400/MWK/FLI4', N'Flight School Frenzy', N'MWK', N'FLI4',
    '2022-11-15 23:59:00', '2022-11-16 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211152359T202211160400/MWK/FLI4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211192359T202211200400/SAT/TUR4', N'Turkey Trot 2', N'SAT', N'TUR4',
    '2022-11-19 23:59:00', '2022-11-20 04:00:00', N'Sat',
    249, 235, 484, 4,
    1365, 910, 2275, 21.27,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211192359T202211200400/SAT/TUR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211202300T202211210300/SUN/SOC3', N'SoCal Sundays Present: Orange County!!', N'SUN', N'SOC3',
    '2022-11-20 23:00:00', '2022-11-21 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211202300T202211210300/SUN/SOC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211212359T202211220300/MWK/MID1', N'Midway Mondays - Session 1', N'MWK', N'MID1',
    '2022-11-21 23:59:00', '2022-11-22 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211212359T202211220300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211222359T202211230400/MWK/CAS4', N'Cascades Returns!', N'MWK', N'CAS4',
    '2022-11-22 23:59:00', '2022-11-23 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211222359T202211230400/MWK/CAS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211252300T202211260300/FNO/STU2', N'Stuff the Albu-Turkey', N'FNO', N'STU2',
    '2022-11-25 23:00:00', '2022-11-26 03:00:00', N'Fri',
    182, 165, 347, 2,
    980, 490, 1470, 23.61,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211252300T202211260300/FNO/STU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211262300T202211270300/SAT/COU3', N'Country Music Crossfire', N'SAT', N'COU3',
    '2022-11-26 23:00:00', '2022-11-27 03:00:00', N'Sat',
    206, 263, 469, 3,
    1260, 1260, 2520, 18.61,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211262300T202211270300/SAT/COU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211292300T202211300200/MWK/DON3', N'Don''t Bust the Bravo', N'MWK', N'DON3',
    '2022-11-29 23:00:00', '2022-11-30 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211292300T202211300200/MWK/DON3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212012359T202212020300/MWK/CAN1', N'Can I Get a Waffle?', N'MWK', N'CAN1',
    '2022-12-01 23:59:00', '2022-12-02 03:00:00', N'Thu',
    119, 117, 236, 1,
    700, 700, 1400, 16.86,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212012359T202212020300/MWK/CAN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212021600T202212050400/MWK/60 1', N'60 Hours of BVARTCC', N'MWK', N'60 1',
    '2022-12-02 16:00:00', '2022-12-05 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212021600T202212050400/MWK/60 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212022359T202212030400/FNO/WIN4', N'Winter Kickoff FNO', N'FNO', N'WIN4',
    '2022-12-02 23:59:00', '2022-12-03 04:00:00', N'Fri',
    365, 321, 686, 4,
    1190, 1064, 2254, 30.43,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212022359T202212030400/FNO/WIN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212032359T202212040400/SAT/OPE4', N'Operation Good Cheer', N'SAT', N'OPE4',
    '2022-12-03 23:59:00', '2022-12-04 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212032359T202212040400/SAT/OPE4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212042359T202212050400/SUN/NOR2', N'North Pole Express', N'SUN', N'NOR2',
    '2022-12-04 23:59:00', '2022-12-05 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212042359T202212050400/SUN/NOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212052359T202212060300/MWK/MID1', N'Midway Mondays - Session 2', N'MWK', N'MID1',
    '2022-12-05 23:59:00', '2022-12-06 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212052359T202212060300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212062359T202212070200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2022-12-06 23:59:00', '2022-12-07 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212062359T202212070200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212092359T202212100400/FNO/HOL4', N'Hollydays in the Rockies', N'FNO', N'HOL4',
    '2022-12-09 23:59:00', '2022-12-10 04:00:00', N'Fri',
    312, 374, 686, 4,
    1736, 1652, 3388, 20.25,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212092359T202212100400/FNO/HOL4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212102000T202212110500/SAT/NIN5', N'Nine Hours of NCT', N'SAT', N'NIN5',
    '2022-12-10 20:00:00', '2022-12-11 05:00:00', N'Sat',
    270, 233, 503, 3,
    1056, 1188, 2244, 22.42,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212102000T202212110500/SAT/NIN5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212111800T202212112200/SUN/THE3', N'The Bermuda Triangle', N'SUN', N'THE3',
    '2022-12-11 18:00:00', '2022-12-11 22:00:00', N'Sun',
    93, 88, 181, 2,
    560, 560, 1120, 16.16,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212111800T202212112200/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212162359T202212170400/FNO/THE5', N'The Fightin'' Seventh FNO', N'FNO', N'THE5',
    '2022-12-16 23:59:00', '2022-12-17 04:00:00', N'Fri',
    331, 309, 640, 5,
    2919, 2583, 5502, 11.63,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212162359T202212170400/FNO/THE5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212172359T202212180400/SAT/CAP3', N'Capital Christmas', N'SAT', N'CAP3',
    '2022-12-17 23:59:00', '2022-12-18 04:00:00', N'Sat',
    191, 148, 339, 3,
    1176, 966, 2142, 15.83,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212172359T202212180400/SAT/CAP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212172000T202212172200/SAT/HIS1', N'Historical Boston Tea Party Fly-in', N'SAT', N'HIS1',
    '2022-12-17 20:00:00', '2022-12-17 22:00:00', N'Sat',
    201, 113, 314, 1,
    288, 288, 576, 54.51,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212172000T202212172200/SAT/HIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212182359T202212190400/SUN/ZMP2', N'ZMP Last Minute Shopping', N'SUN', N'ZMP2',
    '2022-12-18 23:59:00', '2022-12-19 04:00:00', N'Sun',
    102, 62, 164, 1,
    476, 462, 938, 17.48,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212182359T202212190400/SUN/ZMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212192359T202212200300/MWK/MIL1', N'Milwaukee Madness', N'MWK', N'MIL1',
    '2022-12-19 23:59:00', '2022-12-20 03:00:00', N'Mon',
    104, 68, 172, 1,
    224, 210, 434, 39.63,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212192359T202212200300/MWK/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202212222359T202212230300/MWK/MOL2', N'Molokai Shuffle', N'MWK', N'MOL2',
    '2022-12-22 23:59:00', '2022-12-23 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202212222359T202212230300/MWK/MOL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301072359T202301080400/SAT/NEW3', N'New Year New York!', N'SAT', N'NEW3',
    '2023-01-07 23:59:00', '2023-01-08 04:00:00', N'Sat',
    302, 198, 500, 3,
    938, 742, 1680, 29.76,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301072359T202301080400/SAT/NEW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301082200T202301090200/SUN/HOM1', N'Home from the Holidays', N'SUN', N'HOM1',
    '2023-01-08 22:00:00', '2023-01-09 02:00:00', N'Sun',
    134, 134, 268, 1,
    742, 532, 1274, 21.04,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301082200T202301090200/SUN/HOM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301102359T202301110200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-01-10 23:59:00', '2023-01-11 02:00:00', N'Tue',
    49, 36, 85, 1,
    259, 280, 539, 15.77,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301102359T202301110200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301132358T202301161200/FNO/ATL1', N'Atlanta Real-Ops', N'FNO', N'ATL1',
    '2023-01-13 23:58:00', '2023-01-16 12:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301132358T202301161200/FNO/ATL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301132359T202301140400/FNO/HON1', N'Honoring the Dream FNO', N'FNO', N'HON1',
    '2023-01-13 23:59:00', '2023-01-14 04:00:00', N'Fri',
    377, 241, 618, 1,
    1116, 900, 2016, 30.65,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301132359T202301140400/FNO/HON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301162359T202301170400/MWK/MID1', N'Midway Mondays - Session 3', N'MWK', N'MID1',
    '2023-01-16 23:59:00', '2023-01-17 04:00:00', N'Mon',
    79, 62, 141, 1,
    172, 212, 384, 36.72,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301162359T202301170400/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301172359T202301180200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-01-17 23:59:00', '2023-01-18 02:00:00', N'Tue',
    70, 80, 150, 1,
    252, 224, 476, 31.51,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301172359T202301180200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301202359T202301210400/FNO/9TH3', N'9th Annual Northeast Corridor FNO feat. DCA, LGA, & BOS', N'FNO', N'9TH3',
    '2023-01-20 23:59:00', '2023-01-21 04:00:00', N'Fri',
    392, 334, 726, 3,
    770, 714, 1484, 48.92,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301202359T202301210400/FNO/9TH3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301212359T202301220400/SAT/MC-2', N'MC-SNOW', N'SAT', N'MC-2',
    '2023-01-21 23:59:00', '2023-01-22 04:00:00', N'Sat',
    129, 64, 193, 1,
    602, 448, 1050, 18.38,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301212359T202301220400/SAT/MC-2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202201222100T202201230000/SAT/THE3', N'The Extreme Event', N'SAT', N'THE3',
    '2022-01-22 21:00:00', '2022-01-23 00:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202201222100T202201230000/SAT/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301242359T202301250200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-01-24 23:59:00', '2023-01-25 02:00:00', N'Tue',
    78, 70, 148, 1,
    280, 266, 546, 27.11,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301242359T202301250200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301272345T202301280400/FNO/FIR1', N'First Round is on ZKC FNO', N'FNO', N'FIR1',
    '2023-01-27 23:45:00', '2023-01-28 04:00:00', N'Fri',
    177, 133, 310, 1,
    576, 480, 1056, 29.36,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301272345T202301280400/FNO/FIR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301290000T202301290400/SUN/OPP3', N'Opposite Day in the Bay Pt 2', N'SUN', N'OPP3',
    '2023-01-29 00:00:00', '2023-01-29 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301290000T202301290400/SUN/OPP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301292300T202301300300/SUN/SOC4', N'SoCal Sunday Presents: San Bernardino!!', N'SUN', N'SOC4',
    '2023-01-29 23:00:00', '2023-01-30 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301292300T202301300300/SUN/SOC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301312359T202302010300/MWK/BVA2', N'BVA Regional Circuit: Boston and Albany', N'MWK', N'BVA2',
    '2023-01-31 23:59:00', '2023-02-01 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301312359T202302010300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302012359T202302020300/MWK/BRA3', N'Bravo & Charlie & Deltas OH MY!', N'MWK', N'BRA3',
    '2023-02-01 23:59:00', '2023-02-02 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302012359T202302020300/MWK/BRA3');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302032300T202302040300/FNO/WIN1', N'Winnipeg Whiteout FNO', N'FNO', N'WIN1',
    '2023-02-03 23:00:00', '2023-02-04 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302032300T202302040300/FNO/WIN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302042300T202302050400/LIVE/OPE1', N'Operation Deep Freeze Live', N'LIVE', N'OPE1',
    '2023-02-04 23:00:00', '2023-02-05 04:00:00', N'Sat',
    225, 131, 356, 1,
    630, 810, 1440, 24.72,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302042300T202302050400/LIVE/OPE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302052100T202302052359/SUN/THE3', N'The Everglades Circuit', N'SUN', N'THE3',
    '2023-02-05 21:00:00', '2023-02-05 23:59:00', N'Sun',
    184, 127, 311, 3,
    564, 552, 1116, 27.87,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302052100T202302052359/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302072359T202302080200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-02-07 23:59:00', '2023-02-08 02:00:00', N'Tue',
    63, 56, 119, 1,
    280, 266, 546, 21.79,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302072359T202302080200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302102359T202302110400/FNO/THE2', N'The New York FNO', N'FNO', N'THE2',
    '2023-02-10 23:59:00', '2023-02-11 04:00:00', N'Fri',
    232, 162, 394, 2,
    731, 496, 1227, 32.11,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302102359T202302110400/FNO/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302112300T202302120200/SAT/SNO2', N'Snowy Night Operations', N'SAT', N'SNO2',
    '2023-02-11 23:00:00', '2023-02-12 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302112300T202302120200/SAT/SNO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302132359T202302140400/MWK/MID1', N'Midway Mondays - Session 4', N'MWK', N'MID1',
    '2023-02-13 23:59:00', '2023-02-14 04:00:00', N'Mon',
    95, 77, 172, 1,
    252, 252, 504, 34.13,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302132359T202302140400/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302142359T202302150200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-02-14 23:59:00', '2023-02-15 02:00:00', N'Tue',
    73, 72, 145, 1,
    252, 224, 476, 30.46,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302142359T202302150200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302172359T202302180400/FNO/ZFW1', N'ZFW Presents: Feel The Love FNO 2023', N'FNO', N'ZFW1',
    '2023-02-17 23:59:00', '2023-02-18 04:00:00', N'Fri',
    248, 183, 431, 2,
    1050, 924, 1974, 21.83,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302172359T202302180400/FNO/ZFW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302182000T202302182300/SAT/DAY1', N'Daytona 500 Fly-In', N'SAT', N'DAY1',
    '2023-02-18 20:00:00', '2023-02-18 23:00:00', N'Sat',
    97, 41, 138, 1,
    180, 180, 360, 38.33,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302182000T202302182300/SAT/DAY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302190100T202302190500/SUN/SFO1', N'SFO Overload #SayYesToSFO', N'SUN', N'SFO1',
    '2023-02-19 01:00:00', '2023-02-19 05:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302190100T202302190500/SUN/SFO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302191900T202302192200/SUN/THE1', N'THE Statue of Liberty', N'SUN', N'THE1',
    '2023-02-19 19:00:00', '2023-02-19 22:00:00', N'Sun',
    137, 69, 206, 1,
    320, 304, 624, 33.01,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302191900T202302192200/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302212359T202302220300/MWK/MON2', N'Montréal Boston Crossfire', N'MWK', N'MON2',
    '2023-02-21 23:59:00', '2023-02-22 03:00:00', N'Tue',
    114, 129, 243, 1,
    336, 336, 672, 36.16,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302212359T202302220300/MWK/MON2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302222359T202302230300/MWK/ZFW2', N'ZFW Focus: Waco Regional Airport', N'MWK', N'ZFW2',
    '2023-02-22 23:59:00', '2023-02-23 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302222359T202302230300/MWK/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302232359T202302240300/MWK/BIR1', N'Birmingham of the South', N'MWK', N'BIR1',
    '2023-02-23 23:59:00', '2023-02-24 03:00:00', N'Thu',
    76, 59, 135, 1,
    217, 217, 434, 31.11,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302232359T202302240300/MWK/BIR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302242359T202302250400/FNO/THE3', N'The Triple Crown FNO', N'FNO', N'THE3',
    '2023-02-24 23:59:00', '2023-02-25 04:00:00', N'Fri',
    313, 259, 572, 3,
    1216, 960, 2176, 26.29,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302242359T202302250400/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302252200T202302260200/SAT/MAR1', N'Mardi Gras in MSY', N'SAT', N'MAR1',
    '2023-02-25 22:00:00', '2023-02-26 02:00:00', N'Sat',
    110, 60, 170, 1,
    360, 240, 600, 28.33,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302252200T202302260200/SAT/MAR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302272359T202302280400/MWK/MIL1', N'Milwaukee Madness', N'MWK', N'MIL1',
    '2023-02-27 23:59:00', '2023-02-28 04:00:00', N'Mon',
    53, 39, 92, 1,
    147, 210, 357, 25.77,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302272359T202302280400/MWK/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303012359T202303020300/MWK/BVA2', N'BVA Regional Circuit: Boston and Bangor', N'MWK', N'BVA2',
    '2023-03-01 23:59:00', '2023-03-02 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303012359T202303020300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303041800T202303050000/SAT/24 4', N'24 Hours of VATSIM', N'SAT', N'24 4',
    '2023-03-04 18:00:00', '2023-03-05 00:00:00', N'Sat',
    257, 477, 734, 4,
    2467, 2275, 4742, 15.48,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303041800T202303050000/SAT/24 4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303072359T202303080200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-03-07 23:59:00', '2023-03-08 02:00:00', N'Tue',
    55, 72, 127, 1,
    259, 280, 539, 23.56,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303072359T202303080200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303102359T202303110400/FNO/NOR3', N'Northern Crossings VI', N'FNO', N'NOR3',
    '2023-03-10 23:59:00', '2023-03-11 04:00:00', N'Fri',
    335, 312, 647, 3,
    2032, 1584, 3616, 17.89,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303102359T202303110400/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303122300T202303130200/SUN/BE 1', N'Be Seen in Green', N'SUN', N'BE 1',
    '2023-03-12 23:00:00', '2023-03-13 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303122300T202303130200/SUN/BE 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303132359T202303140300/MWK/SPR3', N'Spring in SoCal!', N'MWK', N'SPR3',
    '2023-03-13 23:59:00', '2023-03-14 03:00:00', N'Mon',
    125, 141, 266, 2,
    644, 644, 1288, 20.65,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303132359T202303140300/MWK/SPR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303152300T202303160300/MWK/MIN4', N'Minneflowta', N'MWK', N'MIN4',
    '2023-03-15 23:00:00', '2023-03-16 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303152300T202303160300/MWK/MIN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303172359T202303180400/FNO/GRE3', N'Green Day FNO', N'FNO', N'GRE3',
    '2023-03-17 23:59:00', '2023-03-18 04:00:00', N'Fri',
    243, 159, 402, 3,
    784, 800, 1584, 25.38,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303172359T202303180400/FNO/GRE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303182300T202303190300/SAT/CHE1', N'Cherry Blossom Fly-In', N'SAT', N'CHE1',
    '2023-03-18 23:00:00', '2023-03-19 03:00:00', N'Sat',
    170, 98, 268, 1,
    256, 240, 496, 54.03,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303182300T202303190300/SAT/CHE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303192200T202303200100/SUN/SUN1', N'SUN''n FUN 2023', N'SUN', N'SUN1',
    '2023-03-19 22:00:00', '2023-03-20 01:00:00', N'Sun',
    63, 15, 78, 1,
    120, 120, 240, 32.50,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303192200T202303200100/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303202300T202303210300/MWK/SHA1', N'Shamrocks in Chicago', N'MWK', N'SHA1',
    '2023-03-20 23:00:00', '2023-03-21 03:00:00', N'Mon',
    119, 122, 241, 1,
    798, 532, 1330, 18.12,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303202300T202303210300/MWK/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303212359T202303220300/MWK/BVA1', N'BVA Minor Facility Showcase: T.F. Green Intl. Airport', N'MWK', N'BVA1',
    '2023-03-21 23:59:00', '2023-03-22 03:00:00', N'Tue',
    58, 49, 107, 1,
    192, 180, 372, 28.76,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303212359T202303220300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303252300T202303260300/SAT/DEN1', N'Denver Roulette', N'SAT', N'DEN1',
    '2023-03-25 23:00:00', '2023-03-26 03:00:00', N'Sat',
    105, 124, 229, 1,
    798, 798, 1596, 14.35,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303252300T202303260300/SAT/DEN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303262200T202303270100/SUN/MAU2', N'Mauna Loa Crossfire', N'SUN', N'MAU2',
    '2023-03-26 22:00:00', '2023-03-27 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303262200T202303270100/SUN/MAU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202303302359T202303310200/MWK/MIS1', N'Mission Islip(ossible) v2', N'MWK', N'MIS1',
    '2023-03-30 23:59:00', '2023-03-31 02:00:00', N'Thu',
    58, 43, 101, 1,
    180, 180, 360, 28.06,
    N'Spring', 3, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202303302359T202303310200/MWK/MIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304011800T202304020100/CTP/CRO4', N'Cross the Pond Westbound 2023', N'CTP', N'CRO4',
    '2023-04-01 18:00:00', '2023-04-02 01:00:00', N'Sat',
    714, 336, 1050, 4,
    2772, 2196, 4968, 21.14,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304011800T202304020100/CTP/CRO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304042300T202304050100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-04-04 23:00:00', '2023-04-05 01:00:00', N'Tue',
    54, 71, 125, 1,
    280, 266, 546, 22.89,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304042300T202304050100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304062300T202304070200/MWK/SWT4', N'SWTEE at Peachtree', N'MWK', N'SWT4',
    '2023-04-06 23:00:00', '2023-04-07 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304062300T202304070200/MWK/SWT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304072300T202304080300/FNO/FEN3', N'FENWY Fly-Out FNO', N'FNO', N'FEN3',
    '2023-04-07 23:00:00', '2023-04-08 03:00:00', N'Fri',
    361, 318, 679, 3,
    1288, 1200, 2488, 27.29,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304072300T202304080300/FNO/FEN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304082300T202304090300/SAT/REV1', N'Revenge of the SITTH', N'SAT', N'REV1',
    '2023-04-08 23:00:00', '2023-04-09 03:00:00', N'Sat',
    253, 161, 414, 1,
    880, 784, 1664, 24.88,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304082300T202304090300/SAT/REV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304122359T202304130300/MWK/PAR4', N'Paradise in the Valley 2', N'MWK', N'PAR4',
    '2023-04-12 23:59:00', '2023-04-13 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304122359T202304130300/MWK/PAR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304150000T202304150400/FNO/DEL9', N'Delta Delight FNO', N'FNO', N'DEL9',
    '2023-04-15 00:00:00', '2023-04-15 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304150000T202304150400/FNO/DEL9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304152300T202304160300/SAT/SHA1', N'Share a Coke with Atlanta', N'SAT', N'SHA1',
    '2023-04-15 23:00:00', '2023-04-16 03:00:00', N'Sat',
    230, 154, 384, 1,
    1056, 800, 1856, 20.69,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304152300T202304160300/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304162200T202304170100/SUN/THE3', N'The Longest Final: A Cherry Blossom Epilogue', N'SUN', N'THE3',
    '2023-04-16 22:00:00', '2023-04-17 01:00:00', N'Sun',
    105, 53, 158, 1,
    256, 240, 496, 31.85,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304162200T202304170100/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304182300T202304190100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-04-18 23:00:00', '2023-04-19 01:00:00', N'Tue',
    112, 94, 206, 1,
    378, 154, 532, 38.72,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304182300T202304190100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304192300T202304200100/MWK/A M3', N'A Mile High', N'MWK', N'A M3',
    '2023-04-19 23:00:00', '2023-04-20 01:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304192300T202304200100/MWK/A M3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304212300T202304220300/FNO/GUL3', N'Gulf Coast FNO3', N'FNO', N'GUL3',
    '2023-04-21 23:00:00', '2023-04-22 03:00:00', N'Fri',
    270, 274, 544, 3,
    1372, 1113, 2485, 21.89,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304212300T202304220300/FNO/GUL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304222200T202304230100/SAT/VAT2', N'VATSIM First Wings: Albuquerque & Dallas', N'SAT', N'VAT2',
    '2023-04-22 22:00:00', '2023-04-23 01:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304222200T202304230100/SAT/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304222300T202304230300/SAT/BIN2', N'Bingo in the Bay', N'SAT', N'BIN2',
    '2023-04-22 23:00:00', '2023-04-23 03:00:00', N'Sat',
    73, 71, 144, 1,
    315, 315, 630, 22.86,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304222300T202304230300/SAT/BIN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304231500T202304231800/SUN/BVA4', N'BVA Fly-in: Flapjacks in the Adirondacks!', N'SUN', N'BVA4',
    '2023-04-23 15:00:00', '2023-04-23 18:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304231500T202304231800/SUN/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304282300T202304290300/FNO/ADO3', N'Adopted Red-Tail Hubs FNO', N'FNO', N'ADO3',
    '2023-04-28 23:00:00', '2023-04-29 03:00:00', N'Fri',
    264, 258, 522, 3,
    1744, 1440, 3184, 16.39,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304282300T202304290300/FNO/ADO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202304292200T202304300300/SAT/VAN2', N'vANCouver', N'SAT', N'VAN2',
    '2023-04-29 22:00:00', '2023-04-30 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202304292200T202304300300/SAT/VAN2');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305022300T202305030100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-05-02 23:00:00', '2023-05-03 01:00:00', N'Tue',
    60, 42, 102, 1,
    280, 280, 560, 18.21,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305022300T202305030100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305042300T202305050200/MWK/BYO1', N'BYOB - Bring Your Own Bizjet (KOMA)', N'MWK', N'BYO1',
    '2023-05-04 23:00:00', '2023-05-05 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305042300T202305050200/MWK/BYO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305052300T202305060300/FNO/MEM1', N'Memphis in May', N'FNO', N'MEM1',
    '2023-05-05 23:00:00', '2023-05-06 03:00:00', N'Fri',
    189, 90, 279, 1,
    392, 413, 805, 34.66,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305052300T202305060300/FNO/MEM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305062300T202305070300/SAT/HON5', N'HONK!-apalooza 4: The Ultimate Toga Party', N'SAT', N'HON5',
    '2023-05-06 23:00:00', '2023-05-07 03:00:00', N'Sat',
    280, 305, 585, 4,
    1464, 1408, 2872, 20.37,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305062300T202305070300/SAT/HON5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305072000T202305080000/SUN/HAL1', N'Half Moon Heart-Attack Fly in', N'SUN', N'HAL1',
    '2023-05-07 20:00:00', '2023-05-08 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305072000T202305080000/SUN/HAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305122300T202305130300/FNO/CAR6', N'Caribbean FNO', N'FNO', N'CAR6',
    '2023-05-12 23:00:00', '2023-05-13 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305122300T202305130300/FNO/CAR6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305132300T202305140300/SAT/THE2', N'The Curley Bryant Memorial Event', N'SAT', N'THE2',
    '2023-05-13 23:00:00', '2023-05-14 03:00:00', N'Sat',
    140, 130, 270, 2,
    903, 490, 1393, 19.38,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305132300T202305140300/SAT/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305142359T202305150300/SUN/IT''1', N'It''s just a HOU', N'SUN', N'IT''1',
    '2023-05-14 23:59:00', '2023-05-15 03:00:00', N'Sun',
    110, 79, 189, 1,
    224, 210, 434, 43.55,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305142359T202305150300/SUN/IT''1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305162300T202305170100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-05-16 23:00:00', '2023-05-17 01:00:00', N'Tue',
    63, 64, 127, 1,
    280, 266, 546, 23.26,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305162300T202305170100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305192300T202305200300/FNO/ALO1', N'Aloha FNO', N'FNO', N'ALO1',
    '2023-05-19 23:00:00', '2023-05-20 03:00:00', N'Fri',
    81, 99, 180, 1,
    448, 448, 896, 20.09,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305192300T202305200300/FNO/ALO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305201400T202305212100/LIVE/NEW4', N'New York Live!', N'LIVE', N'NEW4',
    '2023-05-20 14:00:00', '2023-05-21 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305201400T202305212100/LIVE/NEW4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305212300T202305220300/RLOP/CAL8', N'Calscream (Real Ops) XXIII', N'RLOP', N'CAL8',
    '2023-05-21 23:00:00', '2023-05-22 03:00:00', N'Sun',
    449, 466, 915, 8,
    2632, 2936, 5568, 16.43,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305212300T202305220300/RLOP/CAL8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305262300T202305270300/FNO/RAC3', N'Race Into Summer FNO', N'FNO', N'RAC3',
    '2023-05-26 23:00:00', '2023-05-27 03:00:00', N'Fri',
    304, 277, 581, 3,
    924, 910, 1834, 31.68,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305262300T202305270300/FNO/RAC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305272300T202305280200/SAT/ZFW11', N'ZFW Presents: D10 Delta Staffup', N'SAT', N'ZFW11',
    '2023-05-27 23:00:00', '2023-05-28 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305272300T202305280200/SAT/ZFW11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305281700T202305282000/SUN/THE4', N'The Great Cape Escape!', N'SUN', N'THE4',
    '2023-05-28 17:00:00', '2023-05-28 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305281700T202305282000/SUN/THE4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305302300T202305310100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-05-30 23:00:00', '2023-05-31 01:00:00', N'Tue',
    67, 50, 117, 1,
    420, 308, 728, 16.07,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305302300T202305310100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202305312300T202306010200/MWK/TAX1', N'Taxi2Gate MCO Release Event!', N'MWK', N'TAX1',
    '2023-05-31 23:00:00', '2023-06-01 02:00:00', N'Wed',
    120, 99, 219, 1,
    385, 420, 805, 27.20,
    N'Spring', 5, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202305312300T202306010200/MWK/TAX1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306012300T202306020200/MWK/ROC2', N'Rocky Top Roundup', N'MWK', N'ROC2',
    '2023-06-01 23:00:00', '2023-06-02 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306012300T202306020200/MWK/ROC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306022359T202306030400/FNO/CAC4', N'Cactus Shuttle', N'FNO', N'CAC4',
    '2023-06-02 23:59:00', '2023-06-03 04:00:00', N'Fri',
    360, 361, 721, 4,
    1442, 1190, 2632, 27.39,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306022359T202306030400/FNO/CAC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306032300T202306040300/SAT/A N1', N'A Night in the Rocket City', N'SAT', N'A N1',
    '2023-06-03 23:00:00', '2023-06-04 03:00:00', N'Sat',
    91, 39, 130, 1,
    420, 420, 840, 15.48,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306032300T202306040300/SAT/A N1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306092300T202306100300/FNO/NAV1', N'Navy Pier Night Out FNO', N'FNO', N'NAV1',
    '2023-06-09 23:00:00', '2023-06-10 03:00:00', N'Fri',
    223, 142, 365, 1,
    798, 532, 1330, 27.44,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306092300T202306100300/FNO/NAV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306102359T202306110400/LIVE/ZLA6', N'ZLA Live 2023', N'LIVE', N'ZLA6',
    '2023-06-10 23:59:00', '2023-06-11 04:00:00', N'Sat',
    303, 290, 593, 6,
    1680, 1952, 3632, 16.33,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306102359T202306110400/LIVE/ZLA6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306122300T202306130200/MWK/BVA2', N'BVA Regional Circuit: Boston and Syracuse', N'MWK', N'BVA2',
    '2023-06-12 23:00:00', '2023-06-13 02:00:00', N'Mon',
    181, 197, 378, 2,
    560, 560, 1120, 33.75,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306122300T202306130200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306132300T202306140100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-06-13 23:00:00', '2023-06-14 01:00:00', N'Tue',
    86, 45, 131, 1,
    280, 280, 560, 23.39,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306132300T202306140100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306142300T202306150200/MWK/ZME1', N'ZME Primetime', N'MWK', N'ZME1',
    '2023-06-14 23:00:00', '2023-06-15 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306142300T202306150200/MWK/ZME1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306152300T202306160200/MWK/ANC1', N'Anchorage Sundown Solstice', N'MWK', N'ANC1',
    '2023-06-15 23:00:00', '2023-06-16 02:00:00', N'Thu',
    62, 57, 119, 1,
    350, 210, 560, 21.25,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306152300T202306160200/MWK/ANC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306162300T202306170300/FNO/THE2', N'The Magic of Miami FNO', N'FNO', N'THE2',
    '2023-06-16 23:00:00', '2023-06-17 03:00:00', N'Fri',
    280, 203, 483, 2,
    1024, 976, 2000, 24.15,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306162300T202306170300/FNO/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306172300T202306180300/LIVE/STO3', N'Storm the Bay Live!', N'LIVE', N'STO3',
    '2023-06-17 23:00:00', '2023-06-18 03:00:00', N'Sat',
    242, 152, 394, 3,
    1216, 1216, 2432, 16.20,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306172300T202306180300/LIVE/STO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306222300T202306230200/MWK/RAD1', N'Radar Service Terminated', N'MWK', N'RAD1',
    '2023-06-22 23:00:00', '2023-06-23 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306222300T202306230200/MWK/RAD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306232300T202306240300/LIVE/ZHU1', N'ZHU Live', N'LIVE', N'ZHU1',
    '2023-06-23 23:00:00', '2023-06-24 03:00:00', N'Fri',
    185, 107, 292, 1,
    600, 520, 1120, 26.07,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306232300T202306240300/LIVE/ZHU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306241900T202306242200/SAT/THE1', N'The Rings of Power SNO', N'SAT', N'THE1',
    '2023-06-24 19:00:00', '2023-06-24 22:00:00', N'Sat',
    136, 122, 258, 1,
    900, 900, 1800, 14.33,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306241900T202306242200/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306251700T202306252100/SUN/DON4', N'Donuts in the Desert', N'SUN', N'DON4',
    '2023-06-25 17:00:00', '2023-06-25 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306251700T202306252100/SUN/DON4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306272300T202306280100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-06-27 23:00:00', '2023-06-28 01:00:00', N'Tue',
    90, 79, 169, 1,
    280, 266, 546, 30.95,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306272300T202306280100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306282300T202306290300/MWK/WED1', N'Wednesday in Wichita', N'MWK', N'WED1',
    '2023-06-28 23:00:00', '2023-06-29 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306282300T202306290300/MWK/WED1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306292359T202306300300/MWK/BVA1', N'BVA Minor Facility Showcase: Portland Intl. Jetport', N'MWK', N'BVA1',
    '2023-06-29 23:59:00', '2023-06-30 03:00:00', N'Thu',
    67, 32, 99, 1,
    204, 204, 408, 24.26,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306292359T202306300300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202306302300T202307010300/FNO/FOU3', N'Fourth of July Preparty', N'FNO', N'FOU3',
    '2023-06-30 23:00:00', '2023-07-01 03:00:00', N'Fri',
    280, 116, 396, 3,
    1280, 768, 2048, 19.34,
    N'Summer', 6, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202306302300T202307010300/FNO/FOU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307052300T202307060200/MWK/ZME1', N'ZME Primetime', N'MWK', N'ZME1',
    '2023-07-05 23:00:00', '2023-07-06 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307052300T202307060200/MWK/ZME1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307062300T202307070200/MWK/BLU2', N'Blue Ridge Bonanza', N'MWK', N'BLU2',
    '2023-07-06 23:00:00', '2023-07-07 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307062300T202307070200/MWK/BLU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307072359T202307080400/LIVE/DEN3', N'Denver Live', N'LIVE', N'DEN3',
    '2023-07-07 23:59:00', '2023-07-08 04:00:00', N'Fri',
    301, 200, 501, 2,
    952, 616, 1568, 31.95,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307072359T202307080400/LIVE/DEN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307082000T202307082300/SAT/VAT2', N'VATSIM First Wings: Little Rock → Charlotte', N'SAT', N'VAT2',
    '2023-07-08 20:00:00', '2023-07-08 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307082000T202307082300/SAT/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307082300T202307090300/SAT/THE1', N'The Philly CHEAZ STAYK', N'SAT', N'THE1',
    '2023-07-08 23:00:00', '2023-07-09 03:00:00', N'Sat',
    166, 104, 270, 1,
    350, 210, 560, 48.21,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307082300T202307090300/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307091800T202307092200/SUN/VAT1', N'VATVENTURE 2023', N'SUN', N'VAT1',
    '2023-07-09 18:00:00', '2023-07-09 22:00:00', N'Sun',
    117, 22, 139, 1,
    192, 192, 384, 36.20,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307091800T202307092200/SUN/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307112300T202307120100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-07-11 23:00:00', '2023-07-12 01:00:00', N'Tue',
    102, 97, 199, 1,
    308, 224, 532, 37.41,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307112300T202307120100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307122300T202307130200/MWK/BVA2', N'BVA Regional Circuit: Boston and Burlington', N'MWK', N'BVA2',
    '2023-07-12 23:00:00', '2023-07-13 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307122300T202307130200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307152300T202307160200/SAT/HOU2', N'Houston, Montego Bay Fever III', N'SAT', N'HOU2',
    '2023-07-15 23:00:00', '2023-07-16 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307152300T202307160200/SAT/HOU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307161700T202307162000/SUN/NEW2', N'New Jersey Turnpike Spectacular', N'SUN', N'NEW2',
    '2023-07-16 17:00:00', '2023-07-16 20:00:00', N'Sun',
    119, 136, 255, 2,
    532, 476, 1008, 25.30,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307161700T202307162000/SUN/NEW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307172300T202307180300/MWK/SUN2', N'Sunshine Spotlight Series Myrtle Beach', N'MWK', N'SUN2',
    '2023-07-17 23:00:00', '2023-07-18 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307172300T202307180300/MWK/SUN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307212300T202307220300/FNO/SUM2', N'Summer Sizzle 2023', N'FNO', N'SUM2',
    '2023-07-21 23:00:00', '2023-07-22 03:00:00', N'Fri',
    249, 186, 435, 2,
    966, 840, 1806, 24.09,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307212300T202307220300/FNO/SUM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307222200T202307230300/LIVE/THE1', N'The City in the Forest Live', N'LIVE', N'THE1',
    '2023-07-22 22:00:00', '2023-07-23 03:00:00', N'Sat',
    271, 209, 480, 1,
    1056, 800, 1856, 25.86,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307222200T202307230300/LIVE/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307242300T202307250300/MWK/MID1', N'Midway Mondays - Session 5', N'MWK', N'MID1',
    '2023-07-24 23:00:00', '2023-07-25 03:00:00', N'Mon',
    106, 79, 185, 1,
    252, 252, 504, 36.71,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307242300T202307250300/MWK/MID1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307252300T202307260100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-07-25 23:00:00', '2023-07-26 01:00:00', N'Tue',
    90, 63, 153, 1,
    280, 280, 560, 27.32,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307252300T202307260100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307282300T202307290300/FNO/FNO3', N'FNO Downunder 2023', N'FNO', N'FNO3',
    '2023-07-28 23:00:00', '2023-07-29 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307282300T202307290300/FNO/FNO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307292300T202307300300/LIVE/NOR1', N'Northern Migration XVIII Live', N'LIVE', N'NOR1',
    '2023-07-29 23:00:00', '2023-07-30 03:00:00', N'Sat',
    145, 113, 258, 1,
    490, 630, 1120, 23.04,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307292300T202307300300/LIVE/NOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307301700T202307302000/SUN/OPE1', N'Operation Buffalo Wings 2: Hotter, Faster, Spicier', N'SUN', N'OPE1',
    '2023-07-30 17:00:00', '2023-07-30 20:00:00', N'Sun',
    76, 76, 152, 1,
    259, 231, 490, 31.02,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307301700T202307302000/SUN/OPE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202307312300T202308010300/MWK/SUN3', N'Sunshine Spotlight Series Jacksonville', N'MWK', N'SUN3',
    '2023-07-31 23:00:00', '2023-08-01 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202307312300T202308010300/MWK/SUN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308042300T202308050300/FNO/SUN7', N'Sunset in the South FNO', N'FNO', N'SUN7',
    '2023-08-04 23:00:00', '2023-08-05 03:00:00', N'Fri',
    414, 435, 849, 7,
    2604, 2576, 5180, 16.39,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308042300T202308050300/FNO/SUN7');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308051600T202308052300/LIVE/24T1', N'24th Annual Boston Tea Party Live!', N'LIVE', N'24T1',
    '2023-08-05 16:00:00', '2023-08-05 23:00:00', N'Sat',
    233, 263, 496, 1,
    671, 671, 1342, 36.96,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308051600T202308052300/LIVE/24T1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308061500T202308061800/SUN/HCF2', N'HCF Aloha Air Birthday Bash', N'SUN', N'HCF2',
    '2023-08-06 15:00:00', '2023-08-06 18:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308061500T202308061800/SUN/HCF2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308082300T202308090100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-08-08 23:00:00', '2023-08-09 01:00:00', N'Tue',
    77, 55, 132, 1,
    280, 266, 546, 24.18,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308082300T202308090100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308112359T202308120400/FNO/SEA1', N'Seattle Roulette: FNO', N'FNO', N'SEA1',
    '2023-08-11 23:59:00', '2023-08-12 04:00:00', N'Fri',
    169, 149, 318, 1,
    368, 368, 736, 43.21,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308112359T202308120400/FNO/SEA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308122300T202308130300/SAT/RET1', N'Return of the Teter-Hold', N'SAT', N'RET1',
    '2023-08-12 23:00:00', '2023-08-13 03:00:00', N'Sat',
    117, 66, 183, 1,
    224, 210, 434, 42.17,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308122300T202308130300/SAT/RET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308172300T202308180200/MWK/SUN4', N'Sunshine Spotlight Series: Pensacola', N'MWK', N'SUN4',
    '2023-08-17 23:00:00', '2023-08-18 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308172300T202308180200/MWK/SUN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308182300T202308190300/FNO/SOU4', N'South American FNO', N'FNO', N'SOU4',
    '2023-08-18 23:00:00', '2023-08-19 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308182300T202308190300/FNO/SOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308192300T202308200200/SAT/SAT4', N'SATurday Night Satellites', N'SAT', N'SAT4',
    '2023-08-19 23:00:00', '2023-08-20 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308192300T202308200200/SAT/SAT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308201700T202308202000/SUN/US 1', N'US Open Pregame', N'SUN', N'US 1',
    '2023-08-20 17:00:00', '2023-08-20 20:00:00', N'Sun',
    104, 85, 189, 1,
    280, 280, 560, 33.75,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308201700T202308202000/SUN/US 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308222300T202308230100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-08-22 23:00:00', '2023-08-23 01:00:00', N'Tue',
    103, 101, 204, 1,
    252, 266, 518, 39.38,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308222300T202308230100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308252300T202308260300/FNO/MY 2', N'My Fair is BIGGER Than Yours FNO', N'FNO', N'MY 2',
    '2023-08-25 23:00:00', '2023-08-26 03:00:00', N'Fri',
    251, 218, 469, 2,
    1113, 1008, 2121, 22.11,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308252300T202308260300/FNO/MY 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308261800T202308262200/LIVE/MIA2', N'Miami Live 2023', N'LIVE', N'MIA2',
    '2023-08-26 18:00:00', '2023-08-26 22:00:00', N'Sat',
    191, 178, 369, 2,
    896, 854, 1750, 21.09,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308261800T202308262200/LIVE/MIA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308271900T202308272200/SUN/BVA3', N'BVA Fly-in: New Hampshire', N'SUN', N'BVA3',
    '2023-08-27 19:00:00', '2023-08-27 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308271900T202308272200/SUN/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308282300T202308290300/MWK/MID1', N'Midway Mondays - Session 6', N'MWK', N'MID1',
    '2023-08-28 23:00:00', '2023-08-29 03:00:00', N'Mon',
    90, 78, 168, 1,
    252, 252, 504, 33.33,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308282300T202308290300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202308292359T202308300300/MWK/TUL1', N'Tulsa Tuesday', N'MWK', N'TUL1',
    '2023-08-29 23:59:00', '2023-08-30 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202308292359T202308300300/MWK/TUL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309012359T202309020400/FNO/BRA2', N'Bravos by the Beach', N'FNO', N'BRA2',
    '2023-09-01 23:59:00', '2023-09-02 04:00:00', N'Fri',
    222, 202, 424, 2,
    644, 686, 1330, 31.88,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309012359T202309020400/FNO/BRA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309022300T202309030300/SAT/THE1', N'The Plane Train SNO @ KATL', N'SAT', N'THE1',
    '2023-09-02 23:00:00', '2023-09-03 03:00:00', N'Sat',
    213, 152, 365, 1,
    700, 700, 1400, 26.07,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309022300T202309030300/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309032300T202309040200/SUN/WOO2', N'Wooo Pig Sooie!', N'SUN', N'WOO2',
    '2023-09-03 23:00:00', '2023-09-04 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309032300T202309040200/SUN/WOO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309052300T202309060100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-09-05 23:00:00', '2023-09-06 01:00:00', N'Tue',
    80, 37, 117, 1,
    259, 280, 539, 21.71,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309052300T202309060100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309082300T202309090300/FNO/BES3', N'Best of BBQ FNO', N'FNO', N'BES3',
    '2023-09-08 23:00:00', '2023-09-09 03:00:00', N'Fri',
    301, 273, 574, 3,
    1400, 1400, 2800, 20.50,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309082300T202309090300/FNO/BES3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309092300T202309100300/SAT/HOO1', N'Hooked on Phoeniks Book #2', N'SAT', N'HOO1',
    '2023-09-09 23:00:00', '2023-09-10 03:00:00', N'Sat',
    116, 116, 232, 1,
    420, 280, 700, 33.14,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309092300T202309100300/SAT/HOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309101700T202309102000/SUN/SUN1', N'Sunday with The Sopranos', N'SUN', N'SUN1',
    '2023-09-10 17:00:00', '2023-09-10 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309101700T202309102000/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309142300T202309150200/MWK/SOU1', N'Southern Cargo Shuttle', N'MWK', N'SOU1',
    '2023-09-14 23:00:00', '2023-09-15 02:00:00', N'Thu',
    68, 48, 116, 1,
    186, 186, 372, 31.18,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309142300T202309150200/MWK/SOU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309152300T202309160300/FNO/SEP4', N'September Madness FNO', N'FNO', N'SEP4',
    '2023-09-15 23:00:00', '2023-09-16 03:00:00', N'Fri',
    297, 200, 497, 4,
    1624, 1624, 3248, 15.30,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309152300T202309160300/FNO/SEP4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309162300T202309170300/SAT/THI4', N'Things to do in Ohio 2', N'SAT', N'THI4',
    '2023-09-16 23:00:00', '2023-09-17 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309162300T202309170300/SAT/THI4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309182300T202309190300/MWK/MIL1', N'Milwaukee Madness', N'MWK', N'MIL1',
    '2023-09-18 23:00:00', '2023-09-19 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309182300T202309190300/MWK/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309192300T202309200100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-09-19 23:00:00', '2023-09-20 01:00:00', N'Tue',
    83, 78, 161, 1,
    280, 308, 588, 27.38,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309192300T202309200100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309222300T202309230300/FNO/KMI1', N'KMIA VICE FNO', N'FNO', N'KMI1',
    '2023-09-22 23:00:00', '2023-09-23 03:00:00', N'Fri',
    200, 128, 328, 1,
    504, 504, 1008, 32.54,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309222300T202309230300/FNO/KMI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309292300T202309300300/FNO/THE3', N'The New York FNO: Cleared 2 Land', N'FNO', N'THE3',
    '2023-09-29 23:00:00', '2023-09-30 03:00:00', N'Fri',
    297, 163, 460, 3,
    714, 686, 1400, 32.86,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309292300T202309300300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202309302300T202310010300/SAT/MLB2', N'MLB Madness', N'SAT', N'MLB2',
    '2023-09-30 23:00:00', '2023-10-01 03:00:00', N'Sat',
    205, 210, 415, 2,
    1379, 1155, 2534, 16.38,
    N'Fall', 9, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202309302300T202310010300/SAT/MLB2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310012200T202310020200/SUN/LAK8', N'Lake Erie League Gym Challenge!', N'SUN', N'LAK8',
    '2023-10-01 22:00:00', '2023-10-02 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310012200T202310020200/SUN/LAK8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310032300T202310040100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-10-03 23:00:00', '2023-10-04 01:00:00', N'Tue',
    79, 80, 159, 1,
    252, 224, 476, 33.40,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310032300T202310040100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310052230T202310060130/MWK/CIT1', N'CitationMax Birthday Bash!', N'MWK', N'CIT1',
    '2023-10-05 22:30:00', '2023-10-06 01:30:00', N'Thu',
    45, 21, 66, 1,
    224, 210, 434, 15.21,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310052230T202310060130/MWK/CIT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310072300T202310080300/SAT/SPE2', N'Speed into the Roval 4000', N'SAT', N'SPE2',
    '2023-10-07 23:00:00', '2023-10-08 03:00:00', N'Sat',
    145, 84, 229, 1,
    609, 609, 1218, 18.80,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310072300T202310080300/SAT/SPE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310071800T202310072000/SAT/24 2', N'24 Hours of VATSIM', N'SAT', N'24 2',
    '2023-10-07 18:00:00', '2023-10-07 20:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310071800T202310072000/SAT/24 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310081700T202310082000/SUN/BOS2', N'Boston & New York - A Tale of Two Cities', N'SUN', N'BOS2',
    '2023-10-08 17:00:00', '2023-10-08 20:00:00', N'Sun',
    278, 336, 614, 2,
    1034, 594, 1628, 37.71,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310081700T202310082000/SUN/BOS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310092300T202310100300/MWK/MID1', N'Midway Mondays - Session 7', N'MWK', N'MID1',
    '2023-10-09 23:00:00', '2023-10-10 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310092300T202310100300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310132300T202310140300/FNO/HAL1', N'Halloween Horror Ops 2023! ZJX FNO', N'FNO', N'HAL1',
    '2023-10-13 23:00:00', '2023-10-14 03:00:00', N'Fri',
    189, 137, 326, 1,
    672, 448, 1120, 29.11,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310132300T202310140300/FNO/HAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310152300T202310160300/SUN/THE1', N'The O''Hare Special', N'SUN', N'THE1',
    '2023-10-15 23:00:00', '2023-10-16 03:00:00', N'Sun',
    162, 89, 251, 1,
    798, 532, 1330, 18.87,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310152300T202310160300/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310172300T202310180100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-10-17 23:00:00', '2023-10-18 01:00:00', N'Tue',
    59, 54, 113, 1,
    259, 28, 287, 39.37,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310172300T202310180100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310202300T202310210300/FNO/LOU6', N'Louis-sippi-bama FNO', N'FNO', N'LOU6',
    '2023-10-20 23:00:00', '2023-10-21 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310202300T202310210300/FNO/LOU6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310212300T202310220300/SAT/RET1', N'Return of the Flight of the Living Dead', N'SAT', N'RET1',
    '2023-10-21 23:00:00', '2023-10-22 03:00:00', N'Sat',
    110, 79, 189, 1,
    560, 280, 840, 22.50,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310212300T202310220300/SAT/RET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310221700T202310222000/SUN/BVA4', N'BVA Fly-in: Scenic Route 20 Fly-in!', N'SUN', N'BVA4',
    '2023-10-22 17:00:00', '2023-10-22 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310221700T202310222000/SUN/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310282300T202310290300/SAT/TRI1', N'Trick or OAK', N'SAT', N'TRI1',
    '2023-10-28 23:00:00', '2023-10-29 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310282300T202310290300/SAT/TRI1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310281000T202310281600/CTP/CRO12', N'Cross the Pond Eastbound 2023', N'CTP', N'CRO12',
    '2023-10-28 10:00:00', '2023-10-28 16:00:00', N'Sat',
    106, 772, 878, 4,
    2800, 2460, 5260, 16.69,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310281000T202310281600/CTP/CRO12');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202310292300T202310300300/SUN/NAT2', N'National Treasures', N'SUN', N'NAT2',
    '2023-10-29 23:00:00', '2023-10-30 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202310292300T202310300300/SUN/NAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311032300T202311040300/FNO/AN 3', N'An Evening on the Potomac FNO', N'FNO', N'AN 3',
    '2023-11-03 23:00:00', '2023-11-04 03:00:00', N'Fri',
    261, 115, 376, 3,
    1148, 770, 1918, 19.60,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311032300T202311040300/FNO/AN 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311042300T202311050300/SAT/CAR1', N'Cargo Chaos in Miami', N'SAT', N'CAR1',
    '2023-11-04 23:00:00', '2023-11-05 03:00:00', N'Sat',
    163, 118, 281, 1,
    504, 504, 1008, 27.88,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311042300T202311050300/SAT/CAR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311052200T202311060100/SUN/THE1', N'The New York City Marathon', N'SUN', N'THE1',
    '2023-11-05 22:00:00', '2023-11-06 01:00:00', N'Sun',
    110, 75, 185, 1,
    280, 280, 560, 33.04,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311052200T202311060100/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311061700T202311062300/MWK/WF11', N'WF1 WorldFlight 2023 - Houston', N'MWK', N'WF11',
    '2023-11-06 17:00:00', '2023-11-06 23:00:00', N'Mon',
    101, 156, 257, 1,
    936, 648, 1584, 16.22,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311061700T202311062300/MWK/WF11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311062000T202311070200/MWK/WF21', N'WF2 WorldFlight 2023 - Atlanta', N'MWK', N'WF21',
    '2023-11-06 20:00:00', '2023-11-07 02:00:00', N'Mon',
    164, 199, 363, 1,
    1188, 900, 2088, 17.39,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311062000T202311070200/MWK/WF21');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311062300T202311070400/MWK/WF31', N'WF3 Worldflight 2023 - Boston', N'MWK', N'WF31',
    '2023-11-06 23:00:00', '2023-11-07 04:00:00', N'Mon',
    158, 141, 299, 1,
    336, 336, 672, 44.49,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311062300T202311070400/MWK/WF31');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311062359T202311070300/MWK/MID1', N'Midway Mondays - Session 8', N'MWK', N'MID1',
    '2023-11-06 23:59:00', '2023-11-07 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311062359T202311070300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311100100T202311100500/MWK/ZLA3', N'ZLA Spotlight: Santa Barbara', N'MWK', N'ZLA3',
    '2023-11-10 01:00:00', '2023-11-10 05:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311100100T202311100500/MWK/ZLA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311102359T202311110400/FNO/FNO2', N'FNOklahoma', N'FNO', N'FNO2',
    '2023-11-10 23:59:00', '2023-11-11 04:00:00', N'Fri',
    151, 101, 252, 2,
    630, 630, 1260, 20.00,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311102359T202311110400/FNO/FNO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311112359T202311120400/SAT/RET1', N'Return of the JJEDI @ KATL', N'SAT', N'RET1',
    '2023-11-11 23:59:00', '2023-11-12 04:00:00', N'Sat',
    228, 113, 341, 1,
    770, 686, 1456, 23.42,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311112359T202311120400/SAT/RET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311121700T202311130100/SUN/HCF2', N'HCF Coast to Coast ish? Event', N'SUN', N'HCF2',
    '2023-11-12 17:00:00', '2023-11-13 01:00:00', N'Sun',
    26, 82, 108, 1,
    392, 392, 784, 13.78,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311121700T202311130100/SUN/HCF2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311142359T202311150200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-11-14 23:59:00', '2023-11-15 02:00:00', N'Tue',
    74, 76, 150, 1,
    210, 252, 462, 32.47,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311142359T202311150200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311172359T202311180400/FNO/OVE3', N'Over the River and Through the Woods FNO', N'FNO', N'OVE3',
    '2023-11-17 23:59:00', '2023-11-18 04:00:00', N'Fri',
    287, 281, 568, 3,
    1512, 1323, 2835, 20.04,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311172359T202311180400/FNO/OVE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311190000T202311190400/SUN/TOG3', N'Toga Party IV: TOGA Strikes Back', N'SUN', N'TOG3',
    '2023-11-19 00:00:00', '2023-11-19 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311190000T202311190400/SUN/TOG3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311192200T202311200100/SUN/TUR1', N'Turkey Day at Teterboro', N'SUN', N'TUR1',
    '2023-11-19 22:00:00', '2023-11-20 01:00:00', N'Sun',
    39, 42, 81, 1,
    224, 210, 434, 18.66,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311192200T202311200100/SUN/TUR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311202359T202311210300/MWK/SUN4', N'Sunshine Spotlight Series: Daytona Beach', N'MWK', N'SUN4',
    '2023-11-20 23:59:00', '2023-11-21 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311202359T202311210300/MWK/SUN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311220100T202311220400/MWK/BVA2', N'BVA Regional Circuit: Boston and Bradley', N'MWK', N'BVA2',
    '2023-11-22 01:00:00', '2023-11-22 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311220100T202311220400/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311242300T202311250300/FNO/STU2', N'Stuff the Albu-Turkey!', N'FNO', N'STU2',
    '2023-11-24 23:00:00', '2023-11-25 03:00:00', N'Fri',
    77, 67, 144, 1,
    448, 210, 658, 21.88,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311242300T202311250300/FNO/STU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311272359T202311280300/MWK/ZAU12', N'ZAU Minor Field Monday', N'MWK', N'ZAU12',
    '2023-11-27 23:59:00', '2023-11-28 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311272359T202311280300/MWK/ZAU12');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311282359T202311290200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-11-28 23:59:00', '2023-11-29 02:00:00', N'Tue',
    63, 53, 116, 1,
    252, 280, 532, 21.80,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311282359T202311290200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312011600T202312040400/MWK/60 3', N'60 Hours of BVARTCC', N'MWK', N'60 3',
    '2023-12-01 16:00:00', '2023-12-04 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312011600T202312040400/MWK/60 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312012359T202312020400/FNO/2023', N'2023 Winter Kickoff FNO', N'FNO', N'2023',
    '2023-12-01 23:59:00', '2023-12-02 04:00:00', N'Fri',
    363, 258, 621, 3,
    945, 945, 1890, 32.86,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312012359T202312020400/FNO/2023');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312021400T202312022100/SAT/CRO4', N'Cross the Land 2023', N'SAT', N'CRO4',
    '2023-12-02 14:00:00', '2023-12-02 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312021400T202312022100/SAT/CRO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312082359T202312090400/FNO/COR3', N'Cornfield Crossfire 2!', N'FNO', N'COR3',
    '2023-12-08 23:59:00', '2023-12-09 04:00:00', N'Fri',
    291, 287, 578, 3,
    1904, 1400, 3304, 17.49,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312082359T202312090400/FNO/COR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312092359T202312100400/LIVE/NEV1', N'Never Dull at Dulles / ZDC Live 2023', N'LIVE', N'NEV1',
    '2023-12-09 23:59:00', '2023-12-10 04:00:00', N'Sat',
    174, 97, 271, 1,
    672, 504, 1176, 23.04,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312092359T202312100400/LIVE/NEV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312102200T202312110100/SUN/CRO2', N'Cross the Delaware', N'SUN', N'CRO2',
    '2023-12-10 22:00:00', '2023-12-11 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312102200T202312110100/SUN/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312120000T202312120300/MWK/BVA3', N'BVA Fly-In: Across State Lines Fly-In!', N'MWK', N'BVA3',
    '2023-12-12 00:00:00', '2023-12-12 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312120000T202312120300/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312122359T202312130200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2023-12-12 23:59:00', '2023-12-13 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312122359T202312130200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312152359T202312160400/FNO/THE4', N'The Holiday SeaSin', N'FNO', N'THE4',
    '2023-12-15 23:59:00', '2023-12-16 04:00:00', N'Fri',
    314, 284, 598, 2,
    1008, 1008, 2016, 29.66,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312152359T202312160400/FNO/THE4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312162000T202312162300/SAT/VAT2', N'VATSIM First Wings: Indianapolis and Greenville', N'SAT', N'VAT2',
    '2023-12-16 20:00:00', '2023-12-16 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312162000T202312162300/SAT/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312162300T202312170200/SAT/I-25', N'I-20 Corridor', N'SAT', N'I-25',
    '2023-12-16 23:00:00', '2023-12-17 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312162300T202312170200/SAT/I-25');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312172300T202312180200/SUN/HOL1', N'Holiday Hauls @ MEM', N'SUN', N'HOL1',
    '2023-12-17 23:00:00', '2023-12-18 02:00:00', N'Sun',
    92, 83, 175, 1,
    462, 462, 924, 18.94,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312172300T202312180200/SUN/HOL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312172000T202312172200/SUN/KEN2', N'Kennedy & Swiss Dreams', N'SUN', N'KEN2',
    '2023-12-17 20:00:00', '2023-12-17 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312172000T202312172200/SUN/KEN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312192300T202312200200/MWK/MEL2', N'Mele Kalikimaka from Maui', N'MWK', N'MEL2',
    '2023-12-19 23:00:00', '2023-12-20 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312192300T202312200200/MWK/MEL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312222359T202312230400/FNO/HOM2', N'Home for the Holidays FNO', N'FNO', N'HOM2',
    '2023-12-22 23:59:00', '2023-12-23 04:00:00', N'Fri',
    292, 168, 460, 2,
    742, 700, 1442, 31.90,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312222359T202312230400/FNO/HOM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312232358T202312240359/SAT/SAT1', N'Saturday Night in the Queen City @ KCLT', N'SAT', N'SAT1',
    '2023-12-23 23:58:00', '2023-12-24 03:59:00', N'Sat',
    114, 99, 213, 1,
    609, 609, 1218, 17.49,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312232358T202312240359/SAT/SAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312232359T202312240400/SAT/HAV1', N'Have Yourself a Merry Little…Go Around', N'SAT', N'HAV1',
    '2023-12-23 23:59:00', '2023-12-24 04:00:00', N'Sat',
    135, 98, 233, 1,
    231, 231, 462, 50.43,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312232359T202312240400/SAT/HAV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312242359T202312250400/SUN/LAS2', N'Last Minute Shopping', N'SUN', N'LAS2',
    '2023-12-24 23:59:00', '2023-12-25 04:00:00', N'Sun',
    121, 75, 196, 1,
    378, 630, 1008, 19.44,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312242359T202312250400/SUN/LAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202312302300T202312310300/SAT/GTO1', N'GTOUT of 2023 @ KMCO', N'SAT', N'GTO1',
    '2023-12-30 23:00:00', '2023-12-31 03:00:00', N'Sat',
    231, 177, 408, 1,
    688, 512, 1200, 34.00,
    N'Winter', 12, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202312302300T202312310300/SAT/GTO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401020300T202401020600/MWK/LAT3', N'Late Nights at ZOA', N'MWK', N'LAT3',
    '2024-01-02 03:00:00', '2024-01-02 06:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401020300T202401020600/MWK/LAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401060100T202401060500/FNO/GRE2', N'Green Christmas FNO', N'FNO', N'GRE2',
    '2024-01-06 01:00:00', '2024-01-06 05:00:00', N'Sat',
    170, 129, 299, 1,
    420, 420, 840, 35.60,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401060100T202401060500/FNO/GRE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401062359T202401070400/SAT/NEW3', N'New Year New York', N'SAT', N'NEW3',
    '2024-01-06 23:59:00', '2024-01-07 04:00:00', N'Sat',
    368, 223, 591, 3,
    665, 644, 1309, 45.15,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401062359T202401070400/SAT/NEW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401072200T202401080100/SUN/THE3', N'The Everglades Circuit Lap #2', N'SUN', N'THE3',
    '2024-01-07 22:00:00', '2024-01-08 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401072200T202401080100/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401092359T202401100200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2024-01-09 23:59:00', '2024-01-10 02:00:00', N'Tue',
    95, 59, 154, 1,
    196, 196, 392, 39.29,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401092359T202401100200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401100300T202401100600/MWK/LAT3', N'Late Nights at ZOA', N'MWK', N'LAT3',
    '2024-01-10 03:00:00', '2024-01-10 06:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401100300T202401100600/MWK/LAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401112359T202401120300/MWK/BVA1', N'BVA Minor Facility Showcase: Bradley Intl. Airport', N'MWK', N'BVA1',
    '2024-01-11 23:59:00', '2024-01-12 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401112359T202401120300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401122359T202401130400/FNO/HON1', N'Honoring the Dream FNO @ KATL', N'FNO', N'HON1',
    '2024-01-12 23:59:00', '2024-01-13 04:00:00', N'Fri',
    342, 220, 562, 1,
    770, 686, 1456, 38.60,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401122359T202401130400/FNO/HON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401132359T202401140400/SAT/MEE1', N'Meet Me at the RYYMN', N'SAT', N'MEE1',
    '2024-01-13 23:59:00', '2024-01-14 04:00:00', N'Sat',
    169, 116, 285, 1,
    364, 364, 728, 39.15,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401132359T202401140400/SAT/MEE1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401142200T202401150100/SUN/PHR1', N'Phrozen at Philly', N'SUN', N'PHR1',
    '2024-01-14 22:00:00', '2024-01-15 01:00:00', N'Sun',
    171, 82, 253, 1,
    224, 210, 434, 58.29,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401142200T202401150100/SUN/PHR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401152359T202401160300/MWK/MID1', N'Midway Mondays - Session 9', N'MWK', N'MID1',
    '2024-01-15 23:59:00', '2024-01-16 03:00:00', N'Mon',
    116, 77, 193, 1,
    252, 252, 504, 38.29,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401152359T202401160300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401180300T202401180600/MWK/LAT3', N'Late Nights at ZOA', N'MWK', N'LAT3',
    '2024-01-18 03:00:00', '2024-01-18 06:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401180300T202401180600/MWK/LAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401200000T202401200400/FNO/NCT6', N'NCT SUPER Staff Up FNO', N'FNO', N'NCT6',
    '2024-01-20 00:00:00', '2024-01-20 04:00:00', N'Sat',
    269, 231, 500, 3,
    960, 960, 1920, 26.04,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401200000T202401200400/FNO/NCT6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401200100T202401200500/SAT/SCO2', N'Scooby Dooby Duke', N'SAT', N'SCO2',
    '2024-01-20 01:00:00', '2024-01-20 05:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401200100T202401200500/SAT/SCO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401212200T202401220100/SUN/ROC1', N'Rochester Freeze Fest', N'SUN', N'ROC1',
    '2024-01-21 22:00:00', '2024-01-22 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401212200T202401220100/SUN/ROC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401232359T202401240200/MWK/EMP2', N'Empire Fields of Mind', N'MWK', N'EMP2',
    '2024-01-23 23:59:00', '2024-01-24 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401232359T202401240200/MWK/EMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401260300T202401260600/MWK/LAT3', N'Late Nights at ZOA', N'MWK', N'LAT3',
    '2024-01-26 03:00:00', '2024-01-26 06:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401260300T202401260600/MWK/LAT3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401262359T202401270400/FNO/10T4', N'10th Anniversary Northeast Corridor FNO', N'FNO', N'10T4',
    '2024-01-26 23:59:00', '2024-01-27 04:00:00', N'Fri',
    434, 370, 804, 4,
    1168, 1008, 2176, 36.95,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401262359T202401270400/FNO/10T4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401272359T202401280300/SAT/WES4', N'West Texas Triangle', N'SAT', N'WES4',
    '2024-01-27 23:59:00', '2024-01-28 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401272359T202401280300/SAT/WES4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401282300T202401290300/SUN/BOL2', N'Bolt to Jamaica', N'SUN', N'BOL2',
    '2024-01-28 23:00:00', '2024-01-29 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401282300T202401290300/SUN/BOL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401300000T202401300230/MWK/HAW3', N'Hawaiian Airlines Birthday Bash', N'MWK', N'HAW3',
    '2024-01-30 00:00:00', '2024-01-30 02:30:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401300000T202401300230/MWK/HAW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202401312359T202402010300/MWK/VZD1', N'vZDC Regional Night ft. KRDU', N'MWK', N'VZD1',
    '2024-01-31 23:59:00', '2024-02-01 03:00:00', N'Wed',
    87, 64, 151, 1,
    420, 420, 840, 17.98,
    N'Winter', 1, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202401312359T202402010300/MWK/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402012359T202402020400/MWK/WIN2', N'Winter Carnival', N'MWK', N'WIN2',
    '2024-02-01 23:59:00', '2024-02-02 04:00:00', N'Thu',
    97, 92, 189, 1,
    490, 462, 952, 19.85,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402012359T202402020400/MWK/WIN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402022359T202402030400/FNO/GAT2', N'Gateway to the Skies', N'FNO', N'GAT2',
    '2024-02-02 23:59:00', '2024-02-03 04:00:00', N'Fri',
    223, 178, 401, 2,
    1064, 980, 2044, 19.62,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402022359T202402030400/FNO/GAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402040100T202402040500/SUN/LIO2', N'Lion''s Gate to Golden Gate Crossfire', N'SUN', N'LIO2',
    '2024-02-04 01:00:00', '2024-02-04 05:00:00', N'Sun',
    119, 109, 228, 1,
    360, 360, 720, 31.67,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402040100T202402040500/SUN/LIO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402082359T202402090300/MWK/TAK1', N'Take a Hop to Greensboro @ KGSO', N'MWK', N'TAK1',
    '2024-02-08 23:59:00', '2024-02-09 03:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402082359T202402090300/MWK/TAK1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402092359T202402100400/FNO/SUN4', N'Sunshine Spotlight Series: FNO', N'FNO', N'SUN4',
    '2024-02-09 23:59:00', '2024-02-10 04:00:00', N'Fri',
    271, 226, 497, 4,
    952, 952, 1904, 26.10,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402092359T202402100400/FNO/SUN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402102359T202402110400/SAT/SUP3', N'Super Bowl LVIII', N'SAT', N'SUP3',
    '2024-02-10 23:59:00', '2024-02-11 04:00:00', N'Sat',
    281, 137, 418, 1,
    476, 476, 952, 43.91,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402102359T202402110400/SAT/SUP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402111800T202402112100/SUN/BVA3', N'BVA Ski Trip: BTV, MHT, and LEB', N'SUN', N'BVA3',
    '2024-02-11 18:00:00', '2024-02-11 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402111800T202402112100/SUN/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402132359T202402140200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2024-02-13 23:59:00', '2024-02-14 02:00:00', N'Tue',
    84, 69, 153, 1,
    280, 266, 546, 28.02,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402132359T202402140200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402162359T202402170400/FNO/FEE2', N'Feel the Love FNO', N'FNO', N'FEE2',
    '2024-02-16 23:59:00', '2024-02-17 04:00:00', N'Fri',
    255, 172, 427, 2,
    840, 840, 1680, 25.42,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402162359T202402170400/FNO/FEE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402172359T202402180400/SAT/DE-1', N'De-Ice at Detroit', N'SAT', N'DE-1',
    '2024-02-17 23:59:00', '2024-02-18 04:00:00', N'Sat',
    168, 117, 285, 1,
    602, 280, 882, 32.31,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402172359T202402180400/SAT/DE-1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402182300T202402190300/SUN/VAT12', N'VATUSA Presents: Hidden Gateways, Sponsored by SimWorks Studios', N'SUN', N'VAT12',
    '2024-02-18 23:00:00', '2024-02-19 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402182300T202402190300/SUN/VAT12');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402202359T202402210400/MWK/VZD2', N'vZDC Regional Night ft. HEF and JYO', N'MWK', N'VZD2',
    '2024-02-20 23:59:00', '2024-02-21 04:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402202359T202402210400/MWK/VZD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402232359T202402240400/FNO/PAR3', N'Parkway Power FNO', N'FNO', N'PAR3',
    '2024-02-23 23:59:00', '2024-02-24 04:00:00', N'Fri',
    362, 280, 642, 3,
    840, 840, 1680, 38.21,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402232359T202402240400/FNO/PAR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402242000T202402250100/SAT/POL2', N'Polar Southbound Flyout', N'SAT', N'POL2',
    '2024-02-24 20:00:00', '2024-02-25 01:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402242000T202402250100/SAT/POL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402262359T202402270300/MWK/MIL1', N'Milwaukee Madness', N'MWK', N'MIL1',
    '2024-02-26 23:59:00', '2024-02-27 03:00:00', N'Mon',
    49, 50, 99, 1,
    224, 210, 434, 22.81,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402262359T202402270300/MWK/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202402272359T202402280300/MWK/BVA2', N'BVA Regional Circuit: Boston and Albany', N'MWK', N'BVA2',
    '2024-02-27 23:59:00', '2024-02-28 03:00:00', N'Tue',
    140, 140, 280, 2,
    546, 546, 1092, 25.64,
    N'Winter', 2, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202402272359T202402280300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403022300T202403030200/SAT/24 6', N'24 Hours of VATSIM', N'SAT', N'24 6',
    '2024-03-02 23:00:00', '2024-03-03 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403022300T202403030200/SAT/24 6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403052359T202403060200/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2024-03-05 23:59:00', '2024-03-06 02:00:00', N'Tue',
    89, 63, 152, 1,
    224, 224, 448, 33.93,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403052359T202403060200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403080200T202403080300/MWK/MIS1', N'Mission: ISLIPossible v3', N'MWK', N'MIS1',
    '2024-03-08 02:00:00', '2024-03-08 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403080200T202403080300/MWK/MIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403090100T202403090500/FNO/THE3', N'The FNOScars', N'FNO', N'THE3',
    '2024-03-09 01:00:00', '2024-03-09 05:00:00', N'Sat',
    368, 273, 641, 3,
    959, 938, 1897, 33.79,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403090100T202403090500/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403092359T202403100400/LIVE/HOU1', N'Houston Live 2024', N'LIVE', N'HOU1',
    '2024-03-09 23:59:00', '2024-03-10 04:00:00', N'Sat',
    183, 127, 310, 1,
    728, 504, 1232, 25.16,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403092359T202403100400/LIVE/HOU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403101800T202403102200/SUN/IN 1', N'In Memory of Ira Robinson: Light Up New York', N'SUN', N'IN 1',
    '2024-03-10 18:00:00', '2024-03-10 22:00:00', N'Sun',
    116, 74, 190, 1,
    280, 266, 546, 34.80,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403101800T202403102200/SUN/IN 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403112300T202403120200/MWK/MID1', N'Midway Mondays - Session 10', N'MWK', N'MID1',
    '2024-03-11 23:00:00', '2024-03-12 02:00:00', N'Mon',
    84, 71, 155, 1,
    252, 252, 504, 30.75,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403112300T202403120200/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403142300T202403150200/MWK/FLY2', N'Fly Throwback Thursdays', N'MWK', N'FLY2',
    '2024-03-14 23:00:00', '2024-03-15 02:00:00', N'Thu',
    125, 129, 254, 2,
    462, 420, 882, 28.80,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403142300T202403150200/MWK/FLY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403152300T202403160300/FNO/SPR6', N'Spring Break Madness FNO', N'FNO', N'SPR6',
    '2024-03-15 23:00:00', '2024-03-16 03:00:00', N'Fri',
    553, 552, 1105, 6,
    2842, 2660, 5502, 20.08,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403152300T202403160300/FNO/SPR6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403172300T202403180200/SUN/BE 3', N'Be Seen in Green', N'SUN', N'BE 3',
    '2024-03-17 23:00:00', '2024-03-18 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403172300T202403180200/SUN/BE 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403192300T202403200100/MWK/EMP2', N'Empire Fields of Mind', N'MWK', N'EMP2',
    '2024-03-19 23:00:00', '2024-03-20 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403192300T202403200100/MWK/EMP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403202300T202403210200/MWK/VZD1', N'vZDC Regional Night feat. KILM', N'MWK', N'VZD1',
    '2024-03-20 23:00:00', '2024-03-21 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403202300T202403210200/MWK/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403222300T202403230300/FNO/NOR3', N'Northern Crossings VII', N'FNO', N'NOR3',
    '2024-03-22 23:00:00', '2024-03-23 03:00:00', N'Fri',
    355, 324, 679, 3,
    1708, 1414, 3122, 21.75,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403222300T202403230300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403252200T202403260100/MWK/BVA1', N'BVA Minor Facility Showcase: Syracuse Intl.', N'MWK', N'BVA1',
    '2024-03-25 22:00:00', '2024-03-26 01:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403252200T202403260100/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403272300T202403280200/MWK/FIE5', N'Field of Dreams', N'MWK', N'FIE5',
    '2024-03-27 23:00:00', '2024-03-28 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403272300T202403280200/MWK/FIE5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403282300T202403290200/MWK/KJA1', N'KJAN''s Thursday Symphony', N'MWK', N'KJA1',
    '2024-03-28 23:00:00', '2024-03-29 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403282300T202403290200/MWK/KJA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403292259T202403300300/FNO/BLA3', N'Blazing Blizzard FNO', N'FNO', N'BLA3',
    '2024-03-29 22:59:00', '2024-03-30 03:00:00', N'Fri',
    285, 328, 613, 3,
    1706, 1162, 2868, 21.37,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403292259T202403300300/FNO/BLA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403302329T202403310330/SAT/VZD1', N'vZDC Annual Cherry Blossom Fly-In 2024', N'SAT', N'VZD1',
    '2024-03-30 23:29:00', '2024-03-31 03:30:00', N'Sat',
    140, 71, 211, 1,
    224, 210, 434, 48.62,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403302329T202403310330/SAT/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403302330T202403310330/SAT/SPR1', N'Spring in Seattle', N'SAT', N'SPR1',
    '2024-03-30 23:30:00', '2024-03-31 03:30:00', N'Sat',
    82, 89, 171, 1,
    322, 322, 644, 26.55,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403302330T202403310330/SAT/SPR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202403311900T202403312200/SUN/THE8', N'The Mike Foxtrot Catalina Wine Mixer', N'SUN', N'THE8',
    '2024-03-31 19:00:00', '2024-03-31 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202403311900T202403312200/SUN/THE8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404052300T202404060400/FNO/SEA10', N'Sea to Shining Sea FNO', N'FNO', N'SEA10',
    '2024-04-05 23:00:00', '2024-04-06 04:00:00', N'Fri',
    484, 599, 1083, 10,
    5880, 5448, 11328, 9.56,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404052300T202404060400/FNO/SEA10');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404062300T202404070300/SAT/HON11', N'Honk 5: Dead Geese Honk No More!', N'SAT', N'HON11',
    '2024-04-06 23:00:00', '2024-04-07 03:00:00', N'Sat',
    393, 359, 752, 11,
    3619, 3164, 6783, 11.09,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404062300T202404070300/SAT/HON11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404072200T202404080100/SUN/SUN1', N'SUN''n FUN 2024', N'SUN', N'SUN1',
    '2024-04-07 22:00:00', '2024-04-08 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404072200T202404080100/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404092300T202404100100/MWK/EMP1', N'Empire Fields of Mind', N'MWK', N'EMP1',
    '2024-04-09 23:00:00', '2024-04-10 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404092300T202404100100/MWK/EMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404102300T202404110200/MWK/TRA3', N'Train ZMA', N'MWK', N'TRA3',
    '2024-04-10 23:00:00', '2024-04-11 02:00:00', N'Wed',
    109, 98, 207, 3,
    828, 678, 1506, 13.75,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404102300T202404110200/MWK/TRA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404112330T202404120230/MWK/MAS2', N'Masters with ZTL featuring Augusta', N'MWK', N'MAS2',
    '2024-04-11 23:30:00', '2024-04-12 02:30:00', N'Thu',
    120, 121, 241, 2,
    696, 696, 1392, 17.31,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404112330T202404120230/MWK/MAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404122300T202404130300/FNO/ORD2', N'ORD: Established 1944', N'FNO', N'ORD2',
    '2024-04-12 23:00:00', '2024-04-13 03:00:00', N'Fri',
    243, 176, 419, 2,
    868, 784, 1652, 25.36,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404122300T202404130300/FNO/ORD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404131159T202404131500/SAT/GOO1', N'Good Morning Captain Happy', N'SAT', N'GOO1',
    '2024-04-13 11:59:00', '2024-04-13 15:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404131159T202404131500/SAT/GOO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404131200T202404140500/RLOP/ATL1', N'Atlanta Real Ops aka Good Morning Captain Happy aka The Gall of the Gatekeeper aka Afternoon in Flight aka Hartsfield Rush Hour aka A City Too Busy to Hate aka Midnight Plane to Georgia @ KATL', N'RLOP', N'ATL1',
    '2024-04-13 12:00:00', '2024-04-14 05:00:00', N'Sat',
    348, 395, 743, 1,
    1928, 1928, 3856, 19.27,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404131200T202404140500/RLOP/ATL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404131500T202404131800/SAT/THE1', N'The Gall of the Gatekeeper', N'SAT', N'THE1',
    '2024-04-13 15:00:00', '2024-04-13 18:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404131500T202404131800/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404131800T202404132000/SAT/AFT1', N'Afternoon in Flight', N'SAT', N'AFT1',
    '2024-04-13 18:00:00', '2024-04-13 20:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404131800T202404132000/SAT/AFT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404132000T202404132300/SAT/HAR1', N'Hartsfield Rush Hour', N'SAT', N'HAR1',
    '2024-04-13 20:00:00', '2024-04-13 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404132000T202404132300/SAT/HAR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404132300T202404140300/SAT/A C1', N'A City Too Busy to Hate', N'SAT', N'A C1',
    '2024-04-13 23:00:00', '2024-04-14 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404132300T202404140300/SAT/A C1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404140300T202404140500/SUN/MID1', N'Midnight Plane to Georgia @ KATL', N'SUN', N'MID1',
    '2024-04-14 03:00:00', '2024-04-14 05:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404140300T202404140500/SUN/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404142300T202404150200/SUN/FOR1', N'Fort Worth Flyin - ABI', N'SUN', N'FOR1',
    '2024-04-14 23:00:00', '2024-04-15 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404142300T202404150200/SUN/FOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404152300T202404160200/MWK/VZD1', N'vZDC Regional Night feat. KACY', N'MWK', N'VZD1',
    '2024-04-15 23:00:00', '2024-04-16 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404152300T202404160200/MWK/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404182300T202404190200/MWK/SUN1', N'Sunshine Spotlight Series: Sanford', N'MWK', N'SUN1',
    '2024-04-18 23:00:00', '2024-04-19 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404182300T202404190200/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404201800T202404210200/CTP/CRO11', N'Cross the Pond Westbound 2024', N'CTP', N'CRO11',
    '2024-04-20 18:00:00', '2024-04-21 02:00:00', N'Sat',
    1075, 455, 1530, 7,
    4888, 4182, 9070, 16.87,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404201800T202404210200/CTP/CRO11');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404262300T202404270300/FNO/APP9', N'Appalachian (chem)Trails FNO', N'FNO', N'APP9',
    '2024-04-26 23:00:00', '2024-04-27 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404262300T202404270300/FNO/APP9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404272300T202404280300/LIVE/ORL1', N'Orlando Overload 2024 | Live From Orlando', N'LIVE', N'ORL1',
    '2024-04-27 23:00:00', '2024-04-28 03:00:00', N'Sat',
    259, 139, 398, 1,
    672, 448, 1120, 35.54,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404272300T202404280300/LIVE/ORL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202404282300T202404290300/RLOP/CAL6', N'Calscream (Real Ops) XXIV', N'RLOP', N'CAL6',
    '2024-04-28 23:00:00', '2024-04-29 03:00:00', N'Sun',
    394, 384, 778, 6,
    1946, 2114, 4060, 19.16,
    N'Spring', 4, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202404282300T202404290300/RLOP/CAL6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405012300T202405020200/MWK/BVA2', N'BVA Regional Circuit: Boston and Burlington', N'MWK', N'BVA2',
    '2024-05-01 23:00:00', '2024-05-02 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405012300T202405020200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405032300T202405040300/FNO/LON8', N'Lone Star FNO', N'FNO', N'LON8',
    '2024-05-03 23:00:00', '2024-05-04 03:00:00', N'Fri',
    317, 340, 657, 8,
    3437, 2849, 6286, 10.45,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405032300T202405040300/FNO/LON8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405042000T202405050000/SAT/MAY2', N'May the 4th', N'SAT', N'MAY2',
    '2024-05-04 20:00:00', '2024-05-05 00:00:00', N'Sat',
    175, 219, 394, 2,
    1470, 1246, 2716, 14.51,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405042000T202405050000/SAT/MAY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405052300T202405060300/SUN/B.Y2', N'B.Y.O.B. - Bring Your Own Bizjet', N'SUN', N'B.Y2',
    '2024-05-05 23:00:00', '2024-05-06 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405052300T202405060300/SUN/B.Y2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405051900T202405052200/SUN/VZL1', N'vZLC Salt Lake Sundays', N'SUN', N'VZL1',
    '2024-05-05 19:00:00', '2024-05-05 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405051900T202405052200/SUN/VZL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405072300T202405080100/MWK/TUE1', N'Tuesday Nights in New York', N'MWK', N'TUE1',
    '2024-05-07 23:00:00', '2024-05-08 01:00:00', N'Tue',
    89, 57, 146, 1,
    280, 280, 560, 26.07,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405072300T202405080100/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405112000T202405112300/SAT/A D1', N'A Day Out at Kennywood', N'SAT', N'A D1',
    '2024-05-11 20:00:00', '2024-05-11 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405112000T202405112300/SAT/A D1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405132300T202405140200/MWK/MIL4', N'Milwaukee Madness', N'MWK', N'MIL4',
    '2024-05-13 23:00:00', '2024-05-14 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405132300T202405140200/MWK/MIL4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405172359T202405180400/FNO/THE0', N'The Class D Spree', N'FNO', N'THE0',
    '2024-05-17 23:59:00', '2024-05-18 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405172359T202405180400/FNO/THE0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405181900T202405182300/SAT/BRA5', N'Bradley Bash 2024', N'SAT', N'BRA5',
    '2024-05-18 19:00:00', '2024-05-18 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405181900T202405182300/SAT/BRA5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405182300T202405190300/SAT/SHA1', N'Share A Coke with Atlanta', N'SAT', N'SHA1',
    '2024-05-18 23:00:00', '2024-05-19 03:00:00', N'Sat',
    197, 175, 372, 1,
    798, 798, 1596, 23.31,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405182300T202405190300/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405192300T202405200200/SUN/FOR1', N'Fort Worth Flyin - MLU', N'SUN', N'FOR1',
    '2024-05-19 23:00:00', '2024-05-20 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405192300T202405200200/SUN/FOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405202300T202405210200/MWK/VZD3', N'vZDC Regional Night ft. DC SFRA', N'MWK', N'VZD3',
    '2024-05-20 23:00:00', '2024-05-21 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405202300T202405210200/MWK/VZD3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405212300T202405220100/MWK/EMP1', N'Empire Fields of Mind', N'MWK', N'EMP1',
    '2024-05-21 23:00:00', '2024-05-22 01:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405212300T202405220100/MWK/EMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405222330T202405230230/MWK/THE2', N'The Great Locomotive Chase @ KCHA KTYS', N'MWK', N'THE2',
    '2024-05-22 23:30:00', '2024-05-23 02:30:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405222330T202405230230/MWK/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405242300T202405250300/FNO/MEM1', N'Memphis In May', N'FNO', N'MEM1',
    '2024-05-24 23:00:00', '2024-05-25 03:00:00', N'Fri',
    183, 140, 323, 1,
    532, 532, 1064, 30.36,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405242300T202405250300/FNO/MEM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405262100T202405270100/SUN/BVA4', N'BVA Fly-In: Escape to the Cape', N'SUN', N'BVA4',
    '2024-05-26 21:00:00', '2024-05-27 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405262100T202405270100/SUN/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405292300T202405300200/MWK/WIN4', N'Windy City Wednesday', N'MWK', N'WIN4',
    '2024-05-29 23:00:00', '2024-05-30 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405292300T202405300200/MWK/WIN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202405312300T202406010300/FNO/FLO4', N'Florida Night Ops', N'FNO', N'FLO4',
    '2024-05-31 23:00:00', '2024-06-01 03:00:00', N'Fri',
    318, 286, 604, 4,
    1610, 1442, 3052, 19.79,
    N'Spring', 5, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202405312300T202406010300/FNO/FLO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406011900T202406012300/SAT/MAT4', N'Matthew Chicoine "MC" Memorial Event', N'SAT', N'MAT4',
    '2024-06-01 19:00:00', '2024-06-01 23:00:00', N'Sat',
    238, 304, 542, 4,
    1190, 1232, 2422, 22.38,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406011900T202406012300/SAT/MAT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406012300T202406020300/LIVE/ZFW2', N'ZFW Live 2024', N'LIVE', N'ZFW2',
    '2024-06-01 23:00:00', '2024-06-02 03:00:00', N'Sat',
    180, 141, 321, 2,
    938, 756, 1694, 18.95,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406012300T202406020300/LIVE/ZFW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406021700T202406022000/SUN/BVA3', N'BVA Fly-In: Summer on the Seacoast', N'SUN', N'BVA3',
    '2024-06-02 17:00:00', '2024-06-02 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406021700T202406022000/SUN/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406042300T202406050200/MWK/TEN1', N'Ten Cent Beer Night', N'MWK', N'TEN1',
    '2024-06-04 23:00:00', '2024-06-05 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406042300T202406050200/MWK/TEN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406072300T202406080300/FNO/EMP6', N'Empire Builder FNO', N'FNO', N'EMP6',
    '2024-06-07 23:00:00', '2024-06-08 03:00:00', N'Fri',
    151, 181, 332, 2,
    696, 560, 1256, 26.43,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406072300T202406080300/FNO/EMP6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406081400T202406082100/LIVE/MAN4', N'Manhattan Madness Live', N'LIVE', N'MAN4',
    '2024-06-08 14:00:00', '2024-06-08 21:00:00', N'Sat',
    306, 359, 665, 4,
    1788, 1626, 3414, 19.48,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406081400T202406082100/LIVE/MAN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406092000T202406092300/SUN/VAT2', N'VATSIM First Wings: Indianapolis and Birmingham', N'SUN', N'VAT2',
    '2024-06-09 20:00:00', '2024-06-09 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406092000T202406092300/SUN/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406102300T202406110200/MWK/CHI9', N'Chicago ARTCC Presents: Minor Field Monday', N'MWK', N'CHI9',
    '2024-06-10 23:00:00', '2024-06-11 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406102300T202406110200/MWK/CHI9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406122300T202406130200/MWK/VZD1', N'vZDC Regional Night ft. KROA', N'MWK', N'VZD1',
    '2024-06-12 23:00:00', '2024-06-13 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406122300T202406130200/MWK/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406142300T202406150300/FNO/WE 2', N'We Run This HTOWN', N'FNO', N'WE 2',
    '2024-06-14 23:00:00', '2024-06-15 03:00:00', N'Fri',
    233, 155, 388, 2,
    896, 714, 1610, 24.10,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406142300T202406150300/FNO/WE 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406152300T202406160300/LIVE/ZLA6', N'ZLA Live 2024', N'LIVE', N'ZLA6',
    '2024-06-15 23:00:00', '2024-06-16 03:00:00', N'Sat',
    194, 197, 391, 2,
    644, 686, 1330, 29.40,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406152300T202406160300/LIVE/ZLA6');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406161900T202406162200/SUN/ZLC1', N'ZLC Salt Lake Sundays', N'SUN', N'ZLC1',
    '2024-06-16 19:00:00', '2024-06-16 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406161900T202406162200/SUN/ZLC1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406172300T202406180200/MWK/MTR1', N'MTRCT Monday', N'MWK', N'MTR1',
    '2024-06-17 23:00:00', '2024-06-18 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406172300T202406180200/MWK/MTR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406202300T202406210200/MWK/A N1', N'A Night in the Rocket City', N'MWK', N'A N1',
    '2024-06-20 23:00:00', '2024-06-21 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406202300T202406210200/MWK/A N1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406212300T202406220400/LIVE/LIG0', N'Light Up America: Live from FSExpo', N'LIVE', N'LIG0',
    '2024-06-21 23:00:00', '2024-06-22 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406212300T202406220400/LIVE/LIG0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406231900T202406232200/SUN/NOR2', N'Northern Lights to Desert Skies Crossfire', N'SUN', N'NOR2',
    '2024-06-23 19:00:00', '2024-06-23 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406231900T202406232200/SUN/NOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406272300T202406280200/MWK/SUN1', N'Sunshine Spotlight Series: Charleston', N'MWK', N'SUN1',
    '2024-06-27 23:00:00', '2024-06-28 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406272300T202406280200/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406282300T202406290300/FNO/MIL1', N'Mile High Madness FNO', N'FNO', N'MIL1',
    '2024-06-28 23:00:00', '2024-06-29 03:00:00', N'Fri',
    192, 132, 324, 1,
    798, 560, 1358, 23.86,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406282300T202406290300/FNO/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406292330T202406300330/SAT/VZD3', N'vZDC 4th of July Preparty', N'SAT', N'VZD3',
    '2024-06-29 23:30:00', '2024-06-30 03:30:00', N'Sat',
    211, 125, 336, 3,
    980, 966, 1946, 17.27,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406292330T202406300330/SAT/VZD3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406301100T202407010400/SUN/PMD2', N'PMDG 777 Release Event', N'SUN', N'PMD2',
    '2024-06-30 11:00:00', '2024-07-01 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406301100T202407010400/SUN/PMD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202406301900T202406302200/SUN/HOU2', N'Houston Highlight: Lafayette, LA', N'SUN', N'HOU2',
    '2024-06-30 19:00:00', '2024-06-30 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202406301900T202406302200/SUN/HOU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407022300T202407030200/MWK/NTH1', N'NTHNS Hot Dog Eating Contest', N'MWK', N'NTH1',
    '2024-07-02 23:00:00', '2024-07-03 02:00:00', N'Tue',
    82, 60, 142, 1,
    280, 280, 560, 25.36,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407022300T202407030200/MWK/NTH1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407061200T202407070300/SAT/CRO5', N'Cross the Land: America''s Northbound 2024', N'SAT', N'CRO5',
    '2024-07-06 12:00:00', '2024-07-07 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407061200T202407070300/SAT/CRO5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407092300T202407100200/MWK/BVA2', N'BVA Regional Circuit: Boston and Albany', N'MWK', N'BVA2',
    '2024-07-09 23:00:00', '2024-07-10 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407092300T202407100200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407102300T202407110200/MWK/SUN2', N'Sunshine Spotlight Series: Myrtle Beach', N'MWK', N'SUN2',
    '2024-07-10 23:00:00', '2024-07-11 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407102300T202407110200/MWK/SUN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407122359T202407130400/FNO/MOU4', N'Mountains to Malibu Regional FNO', N'FNO', N'MOU4',
    '2024-07-12 23:59:00', '2024-07-13 04:00:00', N'Fri',
    390, 466, 856, 6,
    2800, 2730, 5530, 15.48,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407122359T202407130400/FNO/MOU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407132200T202407140300/SAT/THE1', N'The City in the Forest @ KATL', N'SAT', N'THE1',
    '2024-07-13 22:00:00', '2024-07-14 03:00:00', N'Sat',
    231, 165, 396, 1,
    854, 658, 1512, 26.19,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407132200T202407140300/SAT/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407141800T202407142200/SUN/VAT1', N'VATVenture 2024', N'SUN', N'VAT1',
    '2024-07-14 18:00:00', '2024-07-14 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407141800T202407142200/SUN/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407162300T202407170300/MWK/TRA6', N'TRACON Tuesday: NCT Area A', N'MWK', N'TRA6',
    '2024-07-16 23:00:00', '2024-07-17 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407162300T202407170300/MWK/TRA6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407182300T202407190200/MWK/VZD2', N'vZDC Regional Night ft. KORF and KPHF', N'MWK', N'VZD2',
    '2024-07-18 23:00:00', '2024-07-19 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407182300T202407190200/MWK/VZD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407202300T202407210300/SAT/ZMP1', N'ZMP''s Northern Migration XIX', N'SAT', N'ZMP1',
    '2024-07-20 23:00:00', '2024-07-21 03:00:00', N'Sat',
    188, 109, 297, 1,
    354, 390, 744, 39.92,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407202300T202407210300/SAT/ZMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407212000T202407212300/SUN/SWT4', N'SWTEE at Peachtree', N'SUN', N'SWT4',
    '2024-07-21 20:00:00', '2024-07-21 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407212000T202407212300/SUN/SWT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407222300T202407230200/MWK/LEX1', N'Lexington Light Up', N'MWK', N'LEX1',
    '2024-07-22 23:00:00', '2024-07-23 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407222300T202407230200/MWK/LEX1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407242300T202407250200/MWK/MIL2', N'Milwaukee Madness', N'MWK', N'MIL2',
    '2024-07-24 23:00:00', '2024-07-25 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407242300T202407250200/MWK/MIL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407262300T202407270200/FNO/SUM2', N'Summer Sizzle FNO', N'FNO', N'SUM2',
    '2024-07-26 23:00:00', '2024-07-27 02:00:00', N'Fri',
    251, 169, 420, 2,
    950, 783, 1733, 24.24,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407262300T202407270200/FNO/SUM2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407271700T202407272100/SAT/BVA14', N'BVA Minor Facility Showcase: Portland TRACON', N'SAT', N'BVA14',
    '2024-07-27 17:00:00', '2024-07-27 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407271700T202407272100/SAT/BVA14');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407272100T202407280100/SAT/CAR2', N'Cargo Shuttle: Bound South America', N'SAT', N'CAR2',
    '2024-07-27 21:00:00', '2024-07-28 01:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407272100T202407280100/SAT/CAR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407282359T202407290400/SUN/PUG3', N'Puget Sound Parade', N'SUN', N'PUG3',
    '2024-07-28 23:59:00', '2024-07-29 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407282359T202407290400/SUN/PUG3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202407312300T202408010200/MWK/THE1', N'The City Beautiful | KMCO', N'MWK', N'THE1',
    '2024-07-31 23:00:00', '2024-08-01 02:00:00', N'Wed',
    138, 105, 243, 1,
    672, 448, 1120, 21.70,
    N'Summer', 7, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202407312300T202408010200/MWK/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408022300T202408030300/FNO/THI5', N'Things to Do in Ohio III FNO', N'FNO', N'THI5',
    '2024-08-02 23:00:00', '2024-08-03 03:00:00', N'Fri',
    151, 134, 285, 2,
    721, 630, 1351, 21.10,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408022300T202408030300/FNO/THI5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408032300T202408040300/LIVE/ZOA6', N'ZOA Live 2024: Hot Plane Summer', N'LIVE', N'ZOA6',
    '2024-08-03 23:00:00', '2024-08-04 03:00:00', N'Sat',
    253, 167, 420, 3,
    910, 910, 1820, 23.08,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408032300T202408040300/LIVE/ZOA6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408062300T202408070200/MWK/VZD2', N'vZDC Regional Night ft. KRDU and KFAY', N'MWK', N'VZD2',
    '2024-08-06 23:00:00', '2024-08-07 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408062300T202408070200/MWK/VZD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408092300T202408100300/OMN/LIG0', N'Light Up America OMN', N'OMN', N'LIG0',
    '2024-08-09 23:00:00', '2024-08-10 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408092300T202408100300/OMN/LIG0');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408101600T202408102200/LIVE/BOS4', N'Boston Tea Party Poker Live', N'LIVE', N'BOS4',
    '2024-08-10 16:00:00', '2024-08-10 22:00:00', N'Sat',
    209, 185, 394, 1,
    432, 432, 864, 45.60,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408101600T202408102200/LIVE/BOS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408111600T202408112000/SUN/NEW2', N'New York Airshow', N'SUN', N'NEW2',
    '2024-08-11 16:00:00', '2024-08-11 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408111600T202408112000/SUN/NEW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408142300T202408150200/MWK/WIN3', N'Windy City Wednesday', N'MWK', N'WIN3',
    '2024-08-14 23:00:00', '2024-08-15 02:00:00', N'Wed',
    106, 122, 228, 1,
    798, 532, 1330, 17.14,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408142300T202408150200/MWK/WIN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408162300T202408170300/FNO/ROC2', N'Rocky Mountain FNO', N'FNO', N'ROC2',
    '2024-08-16 23:00:00', '2024-08-17 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408162300T202408170300/FNO/ROC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408172300T202408180300/SAT/END1', N'End of Summer Swarm: LAX', N'SAT', N'END1',
    '2024-08-17 23:00:00', '2024-08-18 03:00:00', N'Sat',
    174, 141, 315, 1,
    476, 476, 952, 33.09,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408172300T202408180300/SAT/END1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408232300T202408240300/FNO/SUN4', N'Sunshine Spotlight Series: FNO Part 2', N'FNO', N'SUN4',
    '2024-08-23 23:00:00', '2024-08-24 03:00:00', N'Fri',
    165, 100, 265, 4,
    882, 952, 1834, 14.45,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408232300T202408240300/FNO/SUN4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408242300T202408250200/SAT/SPA2', N'Spanning the Ohio', N'SAT', N'SPA2',
    '2024-08-24 23:00:00', '2024-08-25 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408242300T202408250200/SAT/SPA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408251600T202408252000/SUN/IN 1', N'In Memoriam: Ed David', N'SUN', N'IN 1',
    '2024-08-25 16:00:00', '2024-08-25 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408251600T202408252000/SUN/IN 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408251700T202408252000/SUN/BVA3', N'BVA Fly-In: New Hampshire', N'SUN', N'BVA3',
    '2024-08-25 17:00:00', '2024-08-25 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408251700T202408252000/SUN/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408262300T202408270200/MWK/GT 1', N'GT Yellow Jackets Flight Back to Atlanta', N'MWK', N'GT 1',
    '2024-08-26 23:00:00', '2024-08-27 02:00:00', N'Mon',
    82, 81, 163, 1,
    700, 700, 1400, 11.64,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408262300T202408270200/MWK/GT 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408272300T202408280200/MWK/SUS4', N'Susquehanna Valley Staff-Up', N'MWK', N'SUS4',
    '2024-08-27 23:00:00', '2024-08-28 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408272300T202408280200/MWK/SUS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408282300T202408290300/MWK/FAI2', N'Fair Weather Flying', N'MWK', N'FAI2',
    '2024-08-28 23:00:00', '2024-08-29 03:00:00', N'Wed',
    90, 74, 164, 1,
    252, 252, 504, 32.54,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408282300T202408290300/MWK/FAI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408292200T202408300100/MWK/FT.1', N'Ft. Worth Flyin - MAF', N'MWK', N'FT.1',
    '2024-08-29 22:00:00', '2024-08-30 01:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408292200T202408300100/MWK/FT.1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408302300T202408310300/FNO/FNO3', N'FNO Downunder', N'FNO', N'FNO3',
    '2024-08-30 23:00:00', '2024-08-31 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408302300T202408310300/FNO/FNO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202408312359T202409010300/SAT/HAY2', N'HAYLL2 the VCTRZ', N'SAT', N'HAY2',
    '2024-08-31 23:59:00', '2024-09-01 03:00:00', N'Sat',
    107, 64, 171, 1,
    602, 420, 1022, 16.73,
    N'Summer', 8, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202408312359T202409010300/SAT/HAY2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409062300T202409070300/FNO/THE1', N'The Plane Train FNO', N'FNO', N'THE1',
    '2024-09-06 23:00:00', '2024-09-07 03:00:00', N'Fri',
    253, 191, 444, 1,
    924, 700, 1624, 27.34,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409062300T202409070300/FNO/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409071900T202409072300/LIVE/AUS4', N'Austin TRACON Live', N'LIVE', N'AUS4',
    '2024-09-07 19:00:00', '2024-09-07 23:00:00', N'Sat',
    115, 132, 247, 1,
    392, 392, 784, 31.51,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409071900T202409072300/LIVE/AUS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409082100T202409090000/SUN/IT''1', N'It''s Pronounced "Lawn Guyland"', N'SUN', N'IT''1',
    '2024-09-08 21:00:00', '2024-09-09 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409082100T202409090000/SUN/IT''1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409092300T202409100200/MWK/ZAU9', N'ZAU Minor Field Monday', N'MWK', N'ZAU9',
    '2024-09-09 23:00:00', '2024-09-10 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409092300T202409100200/MWK/ZAU9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409122300T202409130200/MWK/I-13', N'I-10 Express, Fly The Panhandle', N'MWK', N'I-13',
    '2024-09-12 23:00:00', '2024-09-13 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409122300T202409130200/MWK/I-13');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409132359T202409140400/FNO/LIG3', N'Light Up The Coast FNO', N'FNO', N'LIG3',
    '2024-09-13 23:59:00', '2024-09-14 04:00:00', N'Fri',
    422, 475, 897, 6,
    2030, 2072, 4102, 21.87,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409132359T202409140400/FNO/LIG3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409151700T202409152000/SUN/THE3', N'The Big Apple Orchard', N'SUN', N'THE3',
    '2024-09-15 17:00:00', '2024-09-15 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409151700T202409152000/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409202300T202409210300/FNO/NAS2', N'Nashville Nights, Charlotte Lights', N'FNO', N'NAS2',
    '2024-09-20 23:00:00', '2024-09-21 03:00:00', N'Fri',
    269, 272, 541, 2,
    973, 973, 1946, 27.80,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409202300T202409210300/FNO/NAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409212300T202409220200/SAT/FLY4', N'Flyin'' on Island Time', N'SAT', N'FLY4',
    '2024-09-21 23:00:00', '2024-09-22 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409212300T202409220200/SAT/FLY4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409272259T202409280300/FNO/THE3', N'The New York FNO: A 3-Ring Circus', N'FNO', N'THE3',
    '2024-09-27 22:59:00', '2024-09-28 03:00:00', N'Fri',
    282, 207, 489, 3,
    882, 896, 1778, 27.50,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409272259T202409280300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409272300T202409280300/FNO/SLA1', N'Slap Shot Down the Mountains FNO', N'FNO', N'SLA1',
    '2024-09-27 23:00:00', '2024-09-28 03:00:00', N'Fri',
    45, 40, 85, 1,
    462, 462, 924, 9.20,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409272300T202409280300/FNO/SLA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202409292200T202409300100/SUN/FT.1', N'Ft. Worth Flyin - ACT', N'SUN', N'FT.1',
    '2024-09-29 22:00:00', '2024-09-30 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202409292200T202409300100/SUN/FT.1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410022300T202410030200/MWK/BVA3', N'BVA Regional Circuit: Albany, Boston, and Bangor', N'MWK', N'BVA3',
    '2024-10-02 23:00:00', '2024-10-03 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410022300T202410030200/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410032300T202410040300/MWK/HAL1', N'Halloween Horror Ops 2024', N'MWK', N'HAL1',
    '2024-10-03 23:00:00', '2024-10-04 03:00:00', N'Thu',
    101, 102, 203, 1,
    602, 448, 1050, 19.33,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410032300T202410040300/MWK/HAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410042300T202410050300/FNO/VAT6', N'VATSUR FNO', N'FNO', N'VAT6',
    '2024-10-04 23:00:00', '2024-10-05 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410042300T202410050300/FNO/VAT6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410052200T202410060000/SAT/24 2', N'24 Hours of VATSIM: Leg 7', N'SAT', N'24 2',
    '2024-10-05 22:00:00', '2024-10-06 00:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410052200T202410060000/SAT/24 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410060000T202410060200/SUN/24 2', N'24 Hours of VATSIM: Leg 8', N'SUN', N'24 2',
    '2024-10-06 00:00:00', '2024-10-06 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410060000T202410060200/SUN/24 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410062300T202410070300/SUN/THE1', N'The O''Hare Special III', N'SUN', N'THE1',
    '2024-10-06 23:00:00', '2024-10-07 03:00:00', N'Sun',
    126, 112, 238, 1,
    798, 532, 1330, 17.89,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410062300T202410070300/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410082300T202410090200/MWK/VZD1', N'vZDC Regional Night ft. KORF', N'MWK', N'VZD1',
    '2024-10-08 23:00:00', '2024-10-09 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410082300T202410090200/MWK/VZD1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410102330T202410110230/MWK/THE1', N'The Hot ''Nooga Challenge', N'MWK', N'THE1',
    '2024-10-10 23:30:00', '2024-10-11 02:30:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410102330T202410110230/MWK/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410112300T202410120300/FNO/HIG3', N'High Five on I-5', N'FNO', N'HIG3',
    '2024-10-11 23:00:00', '2024-10-12 03:00:00', N'Fri',
    351, 378, 729, 4,
    1232, 1274, 2506, 29.09,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410112300T202410120300/FNO/HIG3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410121900T202410122300/SAT/THA2', N'Thanks Milton, Miami not Li/ve anymore', N'SAT', N'THA2',
    '2024-10-12 19:00:00', '2024-10-12 23:00:00', N'Sat',
    152, 153, 305, 2,
    896, 854, 1750, 17.43,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410121900T202410122300/SAT/THA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410162300T202410170200/MWK/FED1', N'FedEx "Fright" Night', N'MWK', N'FED1',
    '2024-10-16 23:00:00', '2024-10-17 02:00:00', N'Wed',
    86, 77, 163, 1,
    364, 364, 728, 22.39,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410162300T202410170200/MWK/FED1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410191000T202410191800/CTP/CRO10', N'Cross the Pond Eastbound 2024', N'CTP', N'CRO10',
    '2024-10-19 10:00:00', '2024-10-19 18:00:00', N'Sat',
    125, 864, 989, 6,
    2646, 3328, 5974, 16.56,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410191000T202410191800/CTP/CRO10');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410201700T202410202000/SUN/THA2', N'Thanksgiving in Paradise', N'SUN', N'THA2',
    '2024-10-20 17:00:00', '2024-10-20 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410201700T202410202000/SUN/THA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410252300T202410260300/FNO/HAU6', N'Haunted Headwinds FNO', N'FNO', N'HAU6',
    '2024-10-25 23:00:00', '2024-10-26 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410252300T202410260300/FNO/HAU6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410262300T202410270300/LIVE/VZD3', N'vZDC Live', N'LIVE', N'VZD3',
    '2024-10-26 23:00:00', '2024-10-27 03:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410262300T202410270300/LIVE/VZD3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410272300T202410280300/SUN/TRI1', N'Trick or SJC', N'SUN', N'TRI1',
    '2024-10-27 23:00:00', '2024-10-28 03:00:00', N'Sun',
    90, 40, 130, 1,
    210, 252, 462, 28.14,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410272300T202410280300/SUN/TRI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410292359T202410300200/MWK/FLI1', N'Flight of the Living Dead IV', N'MWK', N'FLI1',
    '2024-10-29 23:59:00', '2024-10-30 02:00:00', N'Tue',
    65, 40, 105, 1,
    560, 560, 1120, 9.38,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410292359T202410300200/MWK/FLI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202410302300T202410310200/MWK/BVA3', N'BVA Fly-In: Foliage', N'MWK', N'BVA3',
    '2024-10-30 23:00:00', '2024-10-31 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202410302300T202410310200/MWK/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411021700T202411022100/SAT/COA2', N'Coastal Crossroads', N'SAT', N'COA2',
    '2024-11-02 17:00:00', '2024-11-02 21:00:00', N'Sat',
    104, 174, 278, 1,
    576, 576, 1152, 24.13,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411021700T202411022100/SAT/COA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411022300T202411030300/SAT/CAL3', N'CalFlow Cargo', N'SAT', N'CAL3',
    '2024-11-02 23:00:00', '2024-11-03 03:00:00', N'Sat',
    221, 187, 408, 3,
    826, 938, 1764, 23.13,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411022300T202411030300/SAT/CAL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411032300T202411040200/SUN/ZTL1', N'ZTL Knox Out Daylight', N'SUN', N'ZTL1',
    '2024-11-03 23:00:00', '2024-11-04 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411032300T202411040200/SUN/ZTL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411082359T202411090400/FNO/NIG1', N'Nightmare on Colfax II', N'FNO', N'NIG1',
    '2024-11-08 23:59:00', '2024-11-09 04:00:00', N'Fri',
    272, 181, 453, 1,
    448, 336, 784, 57.78,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411082359T202411090400/FNO/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411092359T202411100400/SAT/SNO2', N'SNOklahoma', N'SAT', N'SNO2',
    '2024-11-09 23:59:00', '2024-11-10 04:00:00', N'Sat',
    116, 75, 191, 1,
    420, 420, 840, 22.74,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411092359T202411100400/SAT/SNO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411101800T202411102100/SUN/BAK1', N'Baker Bowl', N'SUN', N'BAK1',
    '2024-11-10 18:00:00', '2024-11-10 21:00:00', N'Sun',
    78, 84, 162, 1,
    308, 308, 616, 26.30,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411101800T202411102100/SUN/BAK1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411130000T202411130300/MWK/BVA4', N'BVA Fly-in: Military Airports', N'MWK', N'BVA4',
    '2024-11-13 00:00:00', '2024-11-13 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411130000T202411130300/MWK/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411152359T202411160400/FNO/JOU1', N'Journey out of Detroit 2: Wheels in the Sky', N'FNO', N'JOU1',
    '2024-11-15 23:59:00', '2024-11-16 04:00:00', N'Fri',
    152, 122, 274, 1,
    560, 420, 980, 27.96,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411152359T202411160400/FNO/JOU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411162000T202411162300/SAT/SUR4', N'Surfing the Gulf', N'SAT', N'SUR4',
    '2024-11-16 20:00:00', '2024-11-16 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411162000T202411162300/SAT/SUR4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411172100T202411180200/SUN/VAT68', N'VATUSA General Aviation Appreciation Day', N'SUN', N'VAT68',
    '2024-11-17 21:00:00', '2024-11-18 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411172100T202411180200/SUN/VAT68');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411222359T202411230400/FNO/UND8', N'Under The Sea FNO', N'FNO', N'UND8',
    '2024-11-22 23:59:00', '2024-11-23 04:00:00', N'Fri',
    131, 181, 312, 2,
    896, 896, 1792, 17.41,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411222359T202411230400/FNO/UND8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411232359T202411240400/LIVE/LIV3', N'Live from Chicago (ZAU Live)', N'LIVE', N'LIV3',
    '2024-11-23 23:59:00', '2024-11-24 04:00:00', N'Sat',
    235, 150, 385, 2,
    868, 756, 1624, 23.71,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411232359T202411240400/LIVE/LIV3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411242000T202411242300/SUN/TUR1', N'Turkey Day at Teterboro', N'SUN', N'TUR1',
    '2024-11-24 20:00:00', '2024-11-24 23:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411242000T202411242300/SUN/TUR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411292300T202411300300/FNO/STU2', N'Stuff the Albu-Turkey!', N'FNO', N'STU2',
    '2024-11-29 23:00:00', '2024-11-30 03:00:00', N'Fri',
    215, 150, 365, 2,
    980, 490, 1470, 24.83,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411292300T202411300300/FNO/STU2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202411302359T202412010400/SAT/RAC2', N'Race to the Runway', N'SAT', N'RAC2',
    '2024-11-30 23:59:00', '2024-12-01 04:00:00', N'Sat',
    252, 257, 509, 2,
    1099, 1113, 2212, 23.01,
    N'Fall', 11, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202411302359T202412010400/SAT/RAC2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412012359T202412020400/SUN/VAT2', N'VATSIM Elite Wings', N'SUN', N'VAT2',
    '2024-12-01 23:59:00', '2024-12-02 04:00:00', N'Sun',
    116, 128, 244, 2,
    532, 532, 1064, 22.93,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412012359T202412020400/SUN/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412060100T202412060500/MWK/A N1', N'A New PDX', N'MWK', N'A N1',
    '2024-12-06 01:00:00', '2024-12-06 05:00:00', N'Fri',
    69, 57, 126, 1,
    420, 420, 840, 15.00,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412060100T202412060500/MWK/A N1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412061600T202412080400/MWK/60 1', N'60 Hours of BVARTCC', N'MWK', N'60 1',
    '2024-12-06 16:00:00', '2024-12-08 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412061600T202412080400/MWK/60 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412062359T202412070400/FNO/THE5', N'The Winter Howl FNO', N'FNO', N'THE5',
    '2024-12-06 23:59:00', '2024-12-07 04:00:00', N'Fri',
    256, 252, 508, 3,
    938, 658, 1596, 31.83,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412062359T202412070400/FNO/THE5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412072359T202412080400/SAT/SAN1', N'Santa''s Coming to HTOWN', N'SAT', N'SAN1',
    '2024-12-07 23:59:00', '2024-12-08 04:00:00', N'Sat',
    96, 74, 170, 1,
    504, 504, 1008, 16.87,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412072359T202412080400/SAT/SAN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412082359T202412090300/SUN/TRA1', N'Train ZMA', N'SUN', N'TRA1',
    '2024-12-08 23:59:00', '2024-12-09 03:00:00', N'Sun',
    70, 49, 119, 1,
    455, 455, 910, 13.08,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412082359T202412090300/SUN/TRA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412140100T202412140500/FNO/SIE3', N'Sierras FNO', N'FNO', N'SIE3',
    '2024-12-14 01:00:00', '2024-12-14 05:00:00', N'Sat',
    297, 296, 593, 3,
    1148, 1260, 2408, 24.63,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412140100T202412140500/FNO/SIE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412142359T202412150400/SAT/NEV1', N'Never Dull at Dulles', N'SAT', N'NEV1',
    '2024-12-14 23:59:00', '2024-12-15 04:00:00', N'Sat',
    140, 83, 223, 1,
    504, 504, 1008, 22.12,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412142359T202412150400/SAT/NEV1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412152359T202412160300/SUN/THE4', N'The Big Easy "Lazy" 8', N'SUN', N'THE4',
    '2024-12-15 23:59:00', '2024-12-16 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412152359T202412160300/SUN/THE4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412192359T202412200400/MWK/HOW2', N'How The Grinch Stole Miami', N'MWK', N'HOW2',
    '2024-12-19 23:59:00', '2024-12-20 04:00:00', N'Thu',
    186, 179, 365, 2,
    959, 959, 1918, 19.03,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412192359T202412200400/MWK/HOW2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412202359T202412210400/FNO/HOM3', N'Home for the Holidays FNO', N'FNO', N'HOM3',
    '2024-12-20 23:59:00', '2024-12-21 04:00:00', N'Fri',
    245, 183, 428, 2,
    840, 728, 1568, 27.30,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412202359T202412210400/FNO/HOM3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412222359T202412230400/SUN/LAS2', N'Last Minute Shopping', N'SUN', N'LAS2',
    '2024-12-22 23:59:00', '2024-12-23 04:00:00', N'Sun',
    111, 101, 212, 1,
    490, 630, 1120, 18.93,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412222359T202412230400/SUN/LAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412232359T202412240300/MWK/VZD2', N'vZDC Regional Night ft. KFDK and KHGR', N'MWK', N'VZD2',
    '2024-12-23 23:59:00', '2024-12-24 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412232359T202412240300/MWK/VZD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412282359T202412290400/SAT/GTO1', N'GTOUT of 2024', N'SAT', N'GTO1',
    '2024-12-28 23:59:00', '2024-12-29 04:00:00', N'Sat',
    207, 115, 322, 1,
    602, 448, 1050, 30.67,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412282359T202412290400/SAT/GTO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202412292359T202412300300/SUN/STU1', N'Stuffin'' the ''Ham', N'SUN', N'STU1',
    '2024-12-29 23:59:00', '2024-12-30 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 12, 2024,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202412292359T202412300300/SUN/STU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501032359T202501040400/FNO/HOL3', N'Holiday Gift Returns FNO', N'FNO', N'HOL3',
    '2025-01-03 23:59:00', '2025-01-04 04:00:00', N'Fri',
    349, 344, 693, 3,
    1736, 1498, 3234, 21.43,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501032359T202501040400/FNO/HOL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501042359T202501050300/SAT/NEW3', N'New Year New York', N'SAT', N'NEW3',
    '2025-01-04 23:59:00', '2025-01-05 03:00:00', N'Sat',
    299, 250, 549, 3,
    952, 756, 1708, 32.14,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501042359T202501050300/SAT/NEW3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501112359T202501120400/SAT/TAM4', N'Tampa Bay Takeover', N'SAT', N'TAM4',
    '2025-01-11 23:59:00', '2025-01-12 04:00:00', N'Sat',
    157, 96, 253, 1,
    455, 455, 910, 27.80,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501112359T202501120400/SAT/TAM4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501150100T202501150400/MWK/DE-1', N'De-Ice at Detroit 2: The Type II Boogaloo', N'MWK', N'DE-1',
    '2025-01-15 01:00:00', '2025-01-15 04:00:00', N'Wed',
    95, 66, 161, 1,
    602, 420, 1022, 15.75,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501150100T202501150400/MWK/DE-1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501172359T202501180400/FNO/HON1', N'Honoring the Dream', N'FNO', N'HON1',
    '2025-01-17 23:59:00', '2025-01-18 04:00:00', N'Fri',
    317, 193, 510, 1,
    924, 700, 1624, 31.40,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501172359T202501180400/FNO/HON1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501202359T202501210300/MWK/MID1', N'Midway Mondays', N'MWK', N'MID1',
    '2025-01-20 23:59:00', '2025-01-21 03:00:00', N'Mon',
    95, 45, 140, 1,
    196, 224, 420, 33.33,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501202359T202501210300/MWK/MID1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501222359T202501230300/MWK/BVA1', N'BVA Facility Showcase: Providence', N'MWK', N'BVA1',
    '2025-01-22 23:59:00', '2025-01-23 03:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501222359T202501230300/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501242359T202501250400/FNO/FN 2', N'FN O''Canada', N'FNO', N'FN 2',
    '2025-01-24 23:59:00', '2025-01-25 04:00:00', N'Fri',
    220, 187, 407, 2,
    686, 679, 1365, 29.82,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501242359T202501250400/FNO/FN 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501252359T202501260400/SAT/OPP3', N'Opposite Day in the Bay 2025', N'SAT', N'OPP3',
    '2025-01-25 23:59:00', '2025-01-26 04:00:00', N'Sat',
    242, 211, 453, 3,
    616, 700, 1316, 34.42,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501252359T202501260400/SAT/OPP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202501262359T202501270300/SUN/SUN1', N'Sunday at Springfield', N'SUN', N'SUN1',
    '2025-01-26 23:59:00', '2025-01-27 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 1, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202501262359T202501270300/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502012359T202502020400/SAT/WIN2', N'Winter Carnival', N'SAT', N'WIN2',
    '2025-02-01 23:59:00', '2025-02-02 04:00:00', N'Sat',
    116, 102, 218, 1,
    490, 630, 1120, 19.46,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502012359T202502020400/SAT/WIN2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502021800T202502022059/SUN/PUN1', N'Punxsutawney Phil(adelphia)', N'SUN', N'PUN1',
    '2025-02-02 18:00:00', '2025-02-02 20:59:00', N'Sun',
    95, 83, 178, 1,
    336, 336, 672, 26.49,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502021800T202502022059/SUN/PUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502022100T202502030100/SUN/STA1', N'Stairway To Fort Lauderdale', N'SUN', N'STA1',
    '2025-02-02 21:00:00', '2025-02-03 01:00:00', N'Sun',
    112, 98, 210, 1,
    364, 350, 714, 29.41,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502022100T202502030100/SUN/STA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502072359T202502080400/FNO/NOR3', N'Northeast Corridor FNO', N'FNO', N'NOR3',
    '2025-02-07 23:59:00', '2025-02-08 04:00:00', N'Fri',
    380, 337, 717, 3,
    819, 700, 1519, 47.20,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502072359T202502080400/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502082359T202502090400/SAT/SUP2', N'Super Bowl in the Swamp', N'SAT', N'SUP2',
    '2025-02-08 23:59:00', '2025-02-09 04:00:00', N'Sat',
    214, 98, 312, 1,
    255, 216, 471, 66.24,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502082359T202502090400/SAT/SUP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502120100T202502120400/MWK/TRA2', N'TRACON Tuesdays: Monterey Madness', N'MWK', N'TRA2',
    '2025-02-12 01:00:00', '2025-02-12 04:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502120100T202502120400/MWK/TRA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502142359T202502150400/FNO/FEE2', N'Feel the Love FNO', N'FNO', N'FEE2',
    '2025-02-14 23:59:00', '2025-02-15 04:00:00', N'Fri',
    226, 174, 400, 2,
    882, 728, 1610, 24.84,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502142359T202502150400/FNO/FEE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502151800T202502160600/SAT/WES3', N'West Coast Madness', N'SAT', N'WES3',
    '2025-02-15 18:00:00', '2025-02-16 06:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502151800T202502160600/SAT/WES3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502152359T202502160400/SAT/SAT2', N'Saturday Night Skyline Chili', N'SAT', N'SAT2',
    '2025-02-15 23:59:00', '2025-02-16 04:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502152359T202502160400/SAT/SAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502162100T202502162359/SUN/LOV1', N'Lovell Is In the Air', N'SUN', N'LOV1',
    '2025-02-16 21:00:00', '2025-02-16 23:59:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502162100T202502162359/SUN/LOV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502162359T202502170400/SUN/CRO3', N'Cross the Lake: Tahoe', N'SUN', N'CRO3',
    '2025-02-16 23:59:00', '2025-02-17 04:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502162359T202502170400/SUN/CRO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502172359T202502180300/MWK/CEL2', N'Celebrating The Life Of Nicholas Ader', N'MWK', N'CEL2',
    '2025-02-17 23:59:00', '2025-02-18 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502172359T202502180300/MWK/CEL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502182359T202502190300/MWK/TUE1', N'Tuesday Nights in New York: Islip', N'MWK', N'TUE1',
    '2025-02-18 23:59:00', '2025-02-19 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502182359T202502190300/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502212359T202502220400/FNO/8 M4', N'8 Mile Final', N'FNO', N'8 M4',
    '2025-02-21 23:59:00', '2025-02-22 04:00:00', N'Fri',
    224, 150, 374, 1,
    602, 420, 1022, 36.59,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502212359T202502220400/FNO/8 M4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502232200T202502240100/SUN/THE3', N'The Everglades Circuit Lap 3', N'SUN', N'THE3',
    '2025-02-23 22:00:00', '2025-02-24 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502232200T202502240100/SUN/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502252358T202502260300/MWK/FEB1', N'February Regional Night - Featuring KACY ''Monopoly City'' TRACON', N'MWK', N'FEB1',
    '2025-02-25 23:58:00', '2025-02-26 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502252358T202502260300/MWK/FEB1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502252359T202502260300/MWK/BVA2', N'BVA Regional Circuit: Boston and Syracuse', N'MWK', N'BVA2',
    '2025-02-25 23:59:00', '2025-02-26 03:00:00', N'Tue',
    150, 140, 290, 2,
    476, 476, 952, 30.46,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502252359T202502260300/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202502282359T202503010400/FNO/BLU1', N'Blucifer''s Denver Stampede - FNO', N'FNO', N'BLU1',
    '2025-02-28 23:59:00', '2025-03-01 04:00:00', N'Fri',
    255, 146, 401, 1,
    798, 798, 1596, 25.13,
    N'Winter', 2, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202502282359T202503010400/FNO/BLU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503012359T202503020400/SAT/LAN1', N'Landing Queen', N'SAT', N'LAN1',
    '2025-03-01 23:59:00', '2025-03-02 04:00:00', N'Sat',
    141, 99, 240, 1,
    609, 609, 1218, 19.70,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503012359T202503020400/SAT/LAN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503052359T202503060300/MWK/WIN3', N'Windy City Wednesday', N'MWK', N'WIN3',
    '2025-03-05 23:59:00', '2025-03-06 03:00:00', N'Wed',
    155, 110, 265, 2,
    784, 784, 1568, 16.90,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503052359T202503060300/MWK/WIN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503062359T202503070300/MWK/SPR1', N'Springtime in SOTEX', N'MWK', N'SPR1',
    '2025-03-06 23:59:00', '2025-03-07 03:00:00', N'Thu',
    76, 91, 167, 1,
    560, 532, 1092, 15.29,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503062359T202503070300/MWK/SPR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503090030T202503090400/SUN/HOC6', N'HOCKE night in VATUSA', N'SUN', N'HOC6',
    '2025-03-09 00:30:00', '2025-03-09 04:00:00', N'Sun',
    423, 591, 1014, 6,
    2576, 2058, 4634, 21.88,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503090030T202503090400/SUN/HOC6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503092200T202503100100/SUN/RAL1', N'Raleigh''s Spring Festival', N'SUN', N'RAL1',
    '2025-03-09 22:00:00', '2025-03-10 01:00:00', N'Sun',
    85, 84, 169, 1,
    420, 420, 840, 20.12,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503092200T202503100100/SUN/RAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503142300T202503150300/FNO/FLO4', N'Flow Constrained FNO', N'FNO', N'FLO4',
    '2025-03-14 23:00:00', '2025-03-15 03:00:00', N'Fri',
    176, 104, 280, 2,
    1057, 903, 1960, 14.29,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503142300T202503150300/FNO/FLO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503152359T202503160400/SAT/CRO2', N'Cross The Land: Northbound', N'SAT', N'CRO2',
    '2025-03-15 23:59:00', '2025-03-16 04:00:00', N'Sat',
    253, 194, 447, 2,
    938, 938, 1876, 23.83,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503152359T202503160400/SAT/CRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503162300T202503170200/SUN/AUS1', N'Austin 3.16', N'SUN', N'AUS1',
    '2025-03-16 23:00:00', '2025-03-17 02:00:00', N'Sun',
    110, 88, 198, 1,
    392, 392, 784, 25.26,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503162300T202503170200/SUN/AUS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503172300T202503180200/MWK/BE 2', N'Be Seen In Green', N'MWK', N'BE 2',
    '2025-03-17 23:00:00', '2025-03-18 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503172300T202503180200/MWK/BE 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503182300T202503190200/MWK/TUE1', N'Tuesday Nights in New York: Teterboro', N'MWK', N'TUE1',
    '2025-03-18 23:00:00', '2025-03-19 02:00:00', N'Tue',
    44, 36, 80, 1,
    224, 210, 434, 18.43,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503182300T202503190200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503202300T202503210200/MWK/FIE5', N'Field of Dreams II', N'MWK', N'FIE5',
    '2025-03-20 23:00:00', '2025-03-21 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503202300T202503210200/MWK/FIE5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503212359T202503220400/FNO/CAS2', N'CASCD to SILCN FNO', N'FNO', N'CAS2',
    '2025-03-21 23:59:00', '2025-03-22 04:00:00', N'Fri',
    266, 260, 526, 3,
    798, 945, 1743, 30.18,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503212359T202503220400/FNO/CAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503231900T202503232200/SUN/MAP2', N'Maple in the Mountains', N'SUN', N'MAP2',
    '2025-03-23 19:00:00', '2025-03-23 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503231900T202503232200/SUN/MAP2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503242300T202503250200/MWK/VZD2', N'vZDC Regional Crossfire', N'MWK', N'VZD2',
    '2025-03-24 23:00:00', '2025-03-25 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503242300T202503250200/MWK/VZD2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503252300T202503260200/MWK/TUL1', N'Tulsa Tuesday', N'MWK', N'TUL1',
    '2025-03-25 23:00:00', '2025-03-26 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503252300T202503260200/MWK/TUL1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503262200T202503270100/MWK/BVA1', N'BVA Facility Showcase: Albany', N'MWK', N'BVA1',
    '2025-03-26 22:00:00', '2025-03-27 01:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503262200T202503270100/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503272300T202503280200/MWK/KJA1', N'KJAN''s Thursday Symphony', N'MWK', N'KJA1',
    '2025-03-27 23:00:00', '2025-03-28 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503272300T202503280200/MWK/KJA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503282300T202503290300/FNO/NOR3', N'Northern Crossings VIII', N'FNO', N'NOR3',
    '2025-03-28 23:00:00', '2025-03-29 03:00:00', N'Fri',
    267, 263, 530, 3,
    1925, 1372, 3297, 16.08,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503282300T202503290300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202503291900T202503292100/SAT/VUS1', N'vUSAF Flyin', N'SAT', N'VUS1',
    '2025-03-29 19:00:00', '2025-03-29 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 3, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202503291900T202503292100/SAT/VUS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504012300T202504020200/MWK/TUE2', N'Tuesday Nights in New York: Westchester & Danbury', N'MWK', N'TUE2',
    '2025-04-01 23:00:00', '2025-04-02 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504012300T202504020200/MWK/TUE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504042300T202504050300/FNO/FRI3', N'Friday Night Ops Downunder', N'FNO', N'FRI3',
    '2025-04-04 23:00:00', '2025-04-05 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504042300T202504050300/FNO/FRI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504052300T202504062300/SAT/24 3', N'24 Hours of ZDC', N'SAT', N'24 3',
    '2025-04-05 23:00:00', '2025-04-06 23:00:00', N'Sat',
    319, 205, 524, 1,
    1024, 930, 1954, 26.82,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504052300T202504062300/SAT/24 3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504062200T202504070100/SUN/SUN1', N'Sun ''N Fun 2025', N'SUN', N'SUN1',
    '2025-04-06 22:00:00', '2025-04-07 01:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504062200T202504070100/SUN/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504072300T202504080200/MWK/THE2', N'The River Walk', N'MWK', N'THE2',
    '2025-04-07 23:00:00', '2025-04-08 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504072300T202504080200/MWK/THE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504092300T202504100200/MWK/SUN1', N'Sunshine Spotlight Series: Tallahassee', N'MWK', N'SUN1',
    '2025-04-09 23:00:00', '2025-04-10 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504092300T202504100200/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504102300T202504110200/MWK/BVA2', N'BVA Regional Circuit: Boston and Bangor', N'MWK', N'BVA2',
    '2025-04-10 23:00:00', '2025-04-11 02:00:00', N'Thu',
    141, 149, 290, 2,
    476, 476, 952, 30.46,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504102300T202504110200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504112300T202504120300/FNO/NAV2', N'Navy Pier Night Out FNO', N'FNO', N'NAV2',
    '2025-04-11 23:00:00', '2025-04-12 03:00:00', N'Fri',
    247, 196, 443, 2,
    1050, 784, 1834, 24.15,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504112300T202504120300/FNO/NAV2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504132300T202504140300/SUN/HAL3', N'Half-Dome Fly In', N'SUN', N'HAL3',
    '2025-04-13 23:00:00', '2025-04-14 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504132300T202504140300/SUN/HAL3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504142330T202504150200/MWK/DUN1', N'DUNKS with the CAVVS', N'MWK', N'DUN1',
    '2025-04-14 23:30:00', '2025-04-15 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504142330T202504150200/MWK/DUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504162359T202504170200/MWK/WAL1', N'Walla Walla Wednesday', N'MWK', N'WAL1',
    '2025-04-16 23:59:00', '2025-04-17 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504162359T202504170200/MWK/WAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504172359T202504180200/MWK/MIS1', N'Mission: ISLIPossible v4', N'MWK', N'MIS1',
    '2025-04-17 23:59:00', '2025-04-18 02:00:00', N'Thu',
    35, 10, 45, 1,
    224, 224, 448, 10.04,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504172359T202504180200/MWK/MIS1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504192300T202504200300/SAT/SHA1', N'Share A Coke with Atlanta', N'SAT', N'SHA1',
    '2025-04-19 23:00:00', '2025-04-20 03:00:00', N'Sat',
    247, 189, 436, 1,
    924, 700, 1624, 26.85,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504192300T202504200300/SAT/SHA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504232300T202504240200/MWK/SUN1', N'Sunshine Spotlight Series 2025: KDAB', N'MWK', N'SUN1',
    '2025-04-23 23:00:00', '2025-04-24 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504232300T202504240200/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202504261800T202504270400/CTP/CRO13', N'Cross the Pond Westbound 2025', N'CTP', N'CRO13',
    '2025-04-26 18:00:00', '2025-04-27 04:00:00', N'Sat',
    1239, 623, 1862, 11,
    8646, 5027, 13673, 13.62,
    N'Spring', 4, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202504261800T202504270400/CTP/CRO13');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505022300T202505030300/FNO/MEM1', N'Memphis In May', N'FNO', N'MEM1',
    '2025-05-02 23:00:00', '2025-05-03 03:00:00', N'Fri',
    199, 106, 305, 1,
    508, 367, 875, 34.86,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505022300T202505030300/FNO/MEM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505032300T202505040300/RLOP/CAL8', N'CalScream (Real Ops) XXV', N'RLOP', N'CAL8',
    '2025-05-03 23:00:00', '2025-05-04 03:00:00', N'Sat',
    396, 459, 855, 4,
    1169, 1365, 2534, 33.74,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505032300T202505040300/RLOP/CAL8');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505042200T202505050100/SUN/RET1', N'Return of the JJEDI', N'SUN', N'RET1',
    '2025-05-04 22:00:00', '2025-05-05 01:00:00', N'Sun',
    200, 151, 351, 1,
    924, 700, 1624, 21.61,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505042200T202505050100/SUN/RET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505072300T202505080200/MWK/BVA1', N'BVA Facility Showcase: Burlington', N'MWK', N'BVA1',
    '2025-05-07 23:00:00', '2025-05-08 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505072300T202505080200/MWK/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505082300T202505090200/MWK/MIL1', N'Milwaukee Madness', N'MWK', N'MIL1',
    '2025-05-08 23:00:00', '2025-05-09 02:00:00', N'Thu',
    67, 52, 119, 1,
    224, 224, 448, 26.56,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505082300T202505090200/MWK/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505092200T202505100200/FNO/FNO3', N'FNO Come to Brasil', N'FNO', N'FNO3',
    '2025-05-09 22:00:00', '2025-05-10 02:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505092200T202505100200/FNO/FNO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505102200T202505110200/SAT/CAR2', N'Cargo Shuttle: Back to Americas - Bring a Flower Edition', N'SAT', N'CAR2',
    '2025-05-10 22:00:00', '2025-05-11 02:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505102200T202505110200/SAT/CAR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505111700T202505112000/SUN/AN 1', N'An Allegheny Afternoon', N'SUN', N'AN 1',
    '2025-05-11 17:00:00', '2025-05-11 20:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505111700T202505112000/SUN/AN 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505162300T202505170300/FNO/CEN3', N'Center Stage FNO', N'FNO', N'CEN3',
    '2025-05-16 23:00:00', '2025-05-17 03:00:00', N'Fri',
    256, 211, 467, 3,
    854, 812, 1666, 28.03,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505162300T202505170300/FNO/CEN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505162359T202505170400/FNO/SKY1', N'Sky Harbor Spotlight FNO', N'FNO', N'SKY1',
    '2025-05-16 23:59:00', '2025-05-17 04:00:00', N'Fri',
    88, 108, 196, 1,
    532, 280, 812, 24.14,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505162359T202505170400/FNO/SKY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505172300T202505180300/SAT/IND2', N'Indy "Race" Day', N'SAT', N'IND2',
    '2025-05-17 23:00:00', '2025-05-18 03:00:00', N'Sat',
    152, 100, 252, 2,
    770, 770, 1540, 16.36,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505172300T202505180300/SAT/IND2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505182300T202505190300/SUN/VEC3', N'Vectors and Vineyards', N'SUN', N'VEC3',
    '2025-05-18 23:00:00', '2025-05-19 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505182300T202505190300/SUN/VEC3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505202300T202505210200/MWK/TUE2', N'Tuesday Nights in New York: Harrisburg & Capital City', N'MWK', N'TUE2',
    '2025-05-20 23:00:00', '2025-05-21 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505202300T202505210200/MWK/TUE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505232300T202505240300/FNO/FNO4', N'FNO South America Edition', N'FNO', N'FNO4',
    '2025-05-23 23:00:00', '2025-05-24 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505232300T202505240300/FNO/FNO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505242300T202505250300/LIVE/COW1', N'Cowboys Spaceships and Star Spangled Banners Live', N'LIVE', N'COW1',
    '2025-05-24 23:00:00', '2025-05-25 03:00:00', N'Sat',
    161, 115, 276, 1,
    560, 532, 1092, 25.27,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505242300T202505250300/LIVE/COW1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505262359T202505270300/MWK/MIN6', N'Minor Field Monday', N'MWK', N'MIN6',
    '2025-05-26 23:59:00', '2025-05-27 03:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505262359T202505270300/MWK/MIN6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505272300T202505280200/MWK/VZD4', N'vZDC Regional Night ft. KORF, KPHF, KNTU, KLFI', N'MWK', N'VZD4',
    '2025-05-27 23:00:00', '2025-05-28 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505272300T202505280200/MWK/VZD4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505292200T202505300200/MWK/BVA4', N'BVA Fly-In: Escape to the Cape', N'MWK', N'BVA4',
    '2025-05-29 22:00:00', '2025-05-30 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505292200T202505300200/MWK/BVA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505302300T202505310300/FNO/RIV5', N'Rivers & Ranges FNO', N'FNO', N'RIV5',
    '2025-05-30 23:00:00', '2025-05-31 03:00:00', N'Fri',
    263, 278, 541, 5,
    1288, 1288, 2576, 21.00,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505302300T202505310300/FNO/RIV5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202505312300T202506010300/LIVE/ORL1', N'Orlando Overload 2025 | Live From Orlando', N'LIVE', N'ORL1',
    '2025-05-31 23:00:00', '2025-06-01 03:00:00', N'Sat',
    247, 147, 394, 1,
    672, 448, 1120, 35.18,
    N'Spring', 5, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202505312300T202506010300/LIVE/ORL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506011700T202506012100/SUN/VIS3', N'Visit Cedar Point!', N'SUN', N'VIS3',
    '2025-06-01 17:00:00', '2025-06-01 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506011700T202506012100/SUN/VIS3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506071400T202506072100/LIVE/LIV4', N'Live from New York, it''s Saturday Ops!', N'LIVE', N'LIV4',
    '2025-06-07 14:00:00', '2025-06-07 21:00:00', N'Sat',
    392, 413, 805, 4,
    1644, 1556, 3200, 25.16,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506071400T202506072100/LIVE/LIV4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506072300T202506080300/LIVE/ZDV1', N'ZDV Live 2025 - SNO', N'LIVE', N'ZDV1',
    '2025-06-07 23:00:00', '2025-06-08 03:00:00', N'Sat',
    221, 164, 385, 1,
    798, 612, 1410, 27.30,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506072300T202506080300/LIVE/ZDV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506132300T202506140300/FNO/VZD3', N'vZDC 4th of July Preparty', N'FNO', N'VZD3',
    '2025-06-13 23:00:00', '2025-06-14 03:00:00', N'Fri',
    293, 178, 471, 3,
    952, 882, 1834, 25.68,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506132300T202506140300/FNO/VZD3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506142300T202506150300/LIVE/ZLA6', N'ZLA Live 2025', N'LIVE', N'ZLA6',
    '2025-06-14 23:00:00', '2025-06-15 03:00:00', N'Sat',
    300, 258, 558, 4,
    1064, 1148, 2212, 25.23,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506142300T202506150300/LIVE/ZLA6');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506152300T202506160200/SUN/PRI2', N'Pride Flies', N'SUN', N'PRI2',
    '2025-06-15 23:00:00', '2025-06-16 02:00:00', N'Sun',
    129, 117, 246, 2,
    742, 742, 1484, 16.58,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506152300T202506160200/SUN/PRI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506162300T202506170200/MWK/MIA2', N'Miami Monday', N'MWK', N'MIA2',
    '2025-06-16 23:00:00', '2025-06-17 02:00:00', N'Mon',
    158, 124, 282, 2,
    896, 854, 1750, 16.11,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506162300T202506170200/MWK/MIA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506172300T202506180200/MWK/TUE2', N'Tuesday Nights in New York: Elmira & Ithaca', N'MWK', N'TUE2',
    '2025-06-17 23:00:00', '2025-06-18 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506172300T202506180200/MWK/TUE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506192300T202506200200/MWK/A N1', N'A Night in the Rocket City', N'MWK', N'A N1',
    '2025-06-19 23:00:00', '2025-06-20 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506192300T202506200200/MWK/A N1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506202100T202506210100/FNO/THE3', N'The Rainbow Nation FNO (VATSSA)', N'FNO', N'THE3',
    '2025-06-20 21:00:00', '2025-06-21 01:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506202100T202506210100/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506212300T202506220300/SAT/ZFW2', N'ZFW "Summer Simmer"', N'SAT', N'ZFW2',
    '2025-06-21 23:00:00', '2025-06-22 03:00:00', N'Sat',
    146, 141, 287, 2,
    812, 840, 1652, 17.37,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506212300T202506220300/SAT/ZFW2');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506221800T202506222100/SUN/SWT4', N'SWTEE at Peachtree', N'SUN', N'SWT4',
    '2025-06-22 18:00:00', '2025-06-22 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506221800T202506222100/SUN/SWT4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506242300T202506250300/MWK/TRA2', N'TRACON Tuesdays: Mizzou Approach', N'MWK', N'TRA2',
    '2025-06-24 23:00:00', '2025-06-25 03:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506242300T202506250300/MWK/TRA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506252300T202506260200/MWK/WIN3', N'Windy City Wednesday', N'MWK', N'WIN3',
    '2025-06-25 23:00:00', '2025-06-26 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506252300T202506260200/MWK/WIN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506272300T202506280300/LIVE/(F|9', N'(F|N|O) Into FSExpo - Live from Providence, RI', N'LIVE', N'(F|9',
    '2025-06-27 23:00:00', '2025-06-28 03:00:00', N'Fri',
    423, 430, 853, 9,
    2569, 2352, 4921, 17.33,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506272300T202506280300/LIVE/(F|9');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506281300T202506282200/LIVE/FLI2', N'FlightSimExpo 2025: Live from Providence, RI', N'LIVE', N'FLI2',
    '2025-06-28 13:00:00', '2025-06-28 22:00:00', N'Sat',
    202, 274, 476, 2,
    792, 792, 1584, 30.05,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506281300T202506282200/LIVE/FLI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202506291300T202506291900/LIVE/FLI2', N'FlightSimExpo 2025: Live from Providence, RI', N'LIVE', N'FLI2',
    '2025-06-29 13:00:00', '2025-06-29 19:00:00', N'Sun',
    115, 203, 318, 2,
    792, 792, 1584, 20.08,
    N'Summer', 6, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202506291300T202506291900/LIVE/FLI2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507012300T202507020200/MWK/NTH1', N'NTHNS Hot Dog Eating Contest: Back for Seconds', N'MWK', N'NTH1',
    '2025-07-01 23:00:00', '2025-07-02 02:00:00', N'Tue',
    96, 75, 171, 1,
    280, 280, 560, 30.54,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507012300T202507020200/MWK/NTH1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507032359T202507040300/MWK/FED1', N'FedEx 4th of July Party - KIND', N'MWK', N'FED1',
    '2025-07-03 23:59:00', '2025-07-04 03:00:00', N'Thu',
    142, 80, 222, 1,
    364, 364, 728, 30.49,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507032359T202507040300/MWK/FED1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507052300T202507060300/SAT/MIA1', N'Miami Midsummer Takeoff', N'SAT', N'MIA1',
    '2025-07-05 23:00:00', '2025-07-06 03:00:00', N'Sat',
    143, 166, 309, 1,
    504, 504, 1008, 30.65,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507052300T202507060300/SAT/MIA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507062200T202507070200/SUN/SUM1', N'Summers in Santa Barbara', N'SUN', N'SUM1',
    '2025-07-06 22:00:00', '2025-07-07 02:00:00', N'Sun',
    72, 68, 140, 1,
    210, 280, 490, 28.57,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507062200T202507070200/SUN/SUM1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507092300T202507100200/MWK/SUN1', N'Sunshine Spotlight Series: Banana Bus in Savannah', N'MWK', N'SUN1',
    '2025-07-09 23:00:00', '2025-07-10 02:00:00', N'Wed',
    104, 50, 154, 1,
    245, 210, 455, 33.85,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507092300T202507100200/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507112300T202507120300/FNO/SAN3', N'San Juan FNO', N'FNO', N'SAN3',
    '2025-07-11 23:00:00', '2025-07-12 03:00:00', N'Fri',
    138, 79, 217, 1,
    280, 280, 560, 38.75,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507112300T202507120300/FNO/SAN3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507122300T202507130300/LIVE/ZSE1', N'ZSE Live 2025', N'LIVE', N'ZSE1',
    '2025-07-12 23:00:00', '2025-07-13 03:00:00', N'Sat',
    154, 127, 281, 1,
    322, 322, 644, 43.63,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507122300T202507130300/LIVE/ZSE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507131759T202507132100/SUN/OPE1', N'Operation Buffalo Wings III: Flamed Out!', N'SUN', N'OPE1',
    '2025-07-13 17:59:00', '2025-07-13 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507131759T202507132100/SUN/OPE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507131800T202507132200/SUN/VAT1', N'VATVENTURE 2025', N'SUN', N'VAT1',
    '2025-07-13 18:00:00', '2025-07-13 22:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507131800T202507132200/SUN/VAT1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507142300T202507150200/MWK/HOU3', N'Houston Highlight: South Texas Valley', N'MWK', N'HOU3',
    '2025-07-14 23:00:00', '2025-07-15 02:00:00', N'Mon',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507142300T202507150200/MWK/HOU3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507152300T202507160200/MWK/TUE1', N'Tuesday Nights in New York: Wilkes-Barre', N'MWK', N'TUE1',
    '2025-07-15 23:00:00', '2025-07-16 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507152300T202507160200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507172300T202507180200/MWK/A N5', N'A Night On the Chesapeake', N'MWK', N'A N5',
    '2025-07-17 23:00:00', '2025-07-18 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507172300T202507180200/MWK/A N5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507182300T202507190300/FNO/SUM3', N'Summer In The South', N'FNO', N'SUM3',
    '2025-07-18 23:00:00', '2025-07-19 03:00:00', N'Fri',
    326, 288, 614, 3,
    1386, 1281, 2667, 23.02,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507182300T202507190300/FNO/SUM3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507192300T202507200300/LIVE/ZMP1', N'ZMP''s Northern Migration Live XX', N'LIVE', N'ZMP1',
    '2025-07-19 23:00:00', '2025-07-20 03:00:00', N'Sat',
    234, 139, 373, 1,
    504, 438, 942, 39.60,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507192300T202507200300/LIVE/ZMP1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507201900T202507202200/SUN/BIG2', N'Big Sky Bash', N'SUN', N'BIG2',
    '2025-07-20 19:00:00', '2025-07-20 22:00:00', N'Sun',
    96, 101, 197, 2,
    315, 448, 763, 25.82,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507201900T202507202200/SUN/BIG2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507232300T202507240200/MWK/BVA2', N'BVA Regional Circuit: Bradley and Portland', N'MWK', N'BVA2',
    '2025-07-23 23:00:00', '2025-07-24 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507232300T202507240200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507242300T202507250200/MWK/TRO2', N'Trouble in The TRACON', N'MWK', N'TRO2',
    '2025-07-24 23:00:00', '2025-07-25 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507242300T202507250200/MWK/TRO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507262200T202507270300/LIVE/THE1', N'The City in the Forest (Atlanta Live Event)', N'LIVE', N'THE1',
    '2025-07-26 22:00:00', '2025-07-27 03:00:00', N'Sat',
    294, 251, 545, 1,
    1056, 800, 1856, 29.36,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507262200T202507270300/LIVE/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202507302359T202507310300/MWK/NOR1', N'Northwest Nights: KPDX', N'MWK', N'NOR1',
    '2025-07-30 23:59:00', '2025-07-31 03:00:00', N'Wed',
    131, 78, 209, 1,
    420, 420, 840, 24.88,
    N'Summer', 7, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202507302359T202507310300/MWK/NOR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508012300T202508020300/FNO/TAI1', N'Tailgating for the Texans', N'FNO', N'TAI1',
    '2025-08-01 23:00:00', '2025-08-02 03:00:00', N'Fri',
    243, 149, 392, 1,
    728, 504, 1232, 31.82,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508012300T202508020300/FNO/TAI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508022300T202508030300/LIVE/STO3', N'Storm The Bay (ZOA Live 2025)', N'LIVE', N'STO3',
    '2025-08-02 23:00:00', '2025-08-03 03:00:00', N'Sat',
    287, 213, 500, 3,
    847, 819, 1666, 30.01,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508022300T202508030300/LIVE/STO3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508052300T202508060200/MWK/ZNY5', N'ZNY''s Back to School Bash', N'MWK', N'ZNY5',
    '2025-08-05 23:00:00', '2025-08-06 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508052300T202508060200/MWK/ZNY5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508091500T202508092100/LIVE/26T5', N'26th Annual Boston Tea Party - Live', N'LIVE', N'26T5',
    '2025-08-09 15:00:00', '2025-08-09 21:00:00', N'Sat',
    450, 457, 907, 5,
    1760, 1760, 3520, 25.77,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508091500T202508092100/LIVE/26T5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508092100T202508100300/LIVE/PAR1', N'Party on Broadway Live', N'LIVE', N'PAR1',
    '2025-08-09 21:00:00', '2025-08-10 03:00:00', N'Sat',
    177, 150, 327, 1,
    448, 448, 896, 36.50,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508092100T202508100300/LIVE/PAR1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508101900T202508102200/SUN/SAL1', N'Salt Lake City Sunday', N'SUN', N'SAL1',
    '2025-08-10 19:00:00', '2025-08-10 22:00:00', N'Sun',
    104, 107, 211, 1,
    574, 574, 1148, 18.38,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508101900T202508102200/SUN/SAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508122300T202508130200/MWK/TRA4', N'TRACON Tuesdays: Keeper Of The Planes', N'MWK', N'TRA4',
    '2025-08-12 23:00:00', '2025-08-13 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508122300T202508130200/MWK/TRA4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508152300T202508160300/FNO/NOR3', N'Northwest to NorCal FNO', N'FNO', N'NOR3',
    '2025-08-15 23:00:00', '2025-08-16 03:00:00', N'Fri',
    230, 241, 471, 3,
    1050, 1071, 2121, 22.21,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508152300T202508160300/FNO/NOR3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508161559T202508162100/LIVE/GRE2', N'Greetings From Miami Live', N'LIVE', N'GRE2',
    '2025-08-16 15:59:00', '2025-08-16 21:00:00', N'Sat',
    147, 240, 387, 2,
    1152, 1098, 2250, 17.20,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508161559T202508162100/LIVE/GRE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508161600T202508172200/SAT/30 1', N'30 Hours of EWR', N'SAT', N'30 1',
    '2025-08-16 16:00:00', '2025-08-17 22:00:00', N'Sat',
    657, 610, 1267, 1,
    1320, 1254, 2574, 49.22,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508161600T202508172200/SAT/30 1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508212300T202508220200/MWK/BIM3', N'BIMMRs and Byways', N'MWK', N'BIM3',
    '2025-08-21 23:00:00', '2025-08-22 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508212300T202508220200/MWK/BIM3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508222300T202508230400/FNO/JOU1', N'Journey out of Detroit 3: Who''s Flying Now?', N'FNO', N'JOU1',
    '2025-08-22 23:00:00', '2025-08-23 04:00:00', N'Fri',
    242, 154, 396, 1,
    918, 480, 1398, 28.33,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508222300T202508230400/FNO/JOU1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508232300T202508240300/SAT/ZHU4', N'ZHU Wheel of Misfortune!!', N'SAT', N'ZHU4',
    '2025-08-23 23:00:00', '2025-08-24 03:00:00', N'Sat',
    140, 105, 245, 1,
    560, 532, 1092, 22.44,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508232300T202508240300/SAT/ZHU4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508282200T202508290200/MWK/VAT2', N'VATSIM First Wings: BIL-BOI', N'MWK', N'VAT2',
    '2025-08-28 22:00:00', '2025-08-29 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508282200T202508290200/MWK/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202508312000T202509010000/SUN/VAT12', N'VATUSA GA Appreciation Day!!', N'SUN', N'VAT12',
    '2025-08-31 20:00:00', '2025-09-01 00:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Summer', 8, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202508312000T202509010000/SUN/VAT12');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509052300T202509060300/FNO/THE1', N'The Plane Train FNO', N'FNO', N'THE1',
    '2025-09-05 23:00:00', '2025-09-06 03:00:00', N'Fri',
    274, 175, 449, 1,
    924, 700, 1624, 27.65,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509052300T202509060300/FNO/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509072300T202509080300/SUN/GOP5', N'GOPAC GO!', N'SUN', N'GOP5',
    '2025-09-07 23:00:00', '2025-09-08 03:00:00', N'Sun',
    193, 194, 387, 3,
    1526, 1204, 2730, 14.18,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509072300T202509080300/SUN/GOP5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509122300T202509130300/FNO/RUS4', N'Rush to the Red Carpet FNO', N'FNO', N'RUS4',
    '2025-09-12 23:00:00', '2025-09-13 03:00:00', N'Fri',
    306, 238, 544, 2,
    644, 728, 1372, 39.65,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509122300T202509130300/FNO/RUS4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509141800T202509142100/SUN/BVA1', N'BVA Fly-In: Bedford Bravo Busters', N'SUN', N'BVA1',
    '2025-09-14 18:00:00', '2025-09-14 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509141800T202509142100/SUN/BVA1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509162300T202509170200/MWK/TUE2', N'Tuesday Nights in New York: Islip & New Haven', N'MWK', N'TUE2',
    '2025-09-16 23:00:00', '2025-09-17 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509162300T202509170200/MWK/TUE2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509182300T202509190200/MWK/A N5', N'A Night Over the Shenandoah Valley', N'MWK', N'A N5',
    '2025-09-18 23:00:00', '2025-09-19 02:00:00', N'Thu',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509182300T202509190200/MWK/A N5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509192300T202509200300/FNO/MET1', N'Metroplex Madness', N'FNO', N'MET1',
    '2025-09-19 23:00:00', '2025-09-20 03:00:00', N'Fri',
    190, 157, 347, 1,
    714, 504, 1218, 28.49,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509192300T202509200300/FNO/MET1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509202100T202509202300/SAT/VAT2', N'VATSSA & vZDC: African Skies to American Shores', N'SAT', N'VAT2',
    '2025-09-20 21:00:00', '2025-09-20 23:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509202100T202509202300/SAT/VAT2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509212300T202509220300/SUN/THE1', N'The O''Hare Special IV', N'SUN', N'THE1',
    '2025-09-21 23:00:00', '2025-09-22 03:00:00', N'Sun',
    194, 94, 288, 1,
    798, 532, 1330, 21.65,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509212300T202509220300/SUN/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509242300T202509250200/MWK/SUN1', N'Sunshine Spotlight Series: Jacksonville International', N'MWK', N'SUN1',
    '2025-09-24 23:00:00', '2025-09-25 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509242300T202509250200/MWK/SUN1');
GO
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509262259T202509270300/FNO/THE1', N'The Seattle Salmon Run FNO', N'FNO', N'THE1',
    '2025-09-26 22:59:00', '2025-09-27 03:00:00', N'Fri',
    96, 49, 145, 1,
    350, 350, 700, 20.71,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509262259T202509270300/FNO/THE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509262300T202509270300/FNO/THE3', N'The New York FNO: BaconEggCheese', N'FNO', N'THE3',
    '2025-09-26 23:00:00', '2025-09-27 03:00:00', N'Fri',
    252, 169, 421, 3,
    812, 770, 1582, 26.61,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509262300T202509270300/FNO/THE3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509271900T202509272200/SAT/HOP3', N'Hop The Ridge - TYS | TRI | GSO', N'SAT', N'HOP3',
    '2025-09-27 19:00:00', '2025-09-27 22:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509271900T202509272200/SAT/HOP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202509272300T202509280300/SAT/3054', N'305 To Paradise', N'SAT', N'3054',
    '2025-09-27 23:00:00', '2025-09-28 03:00:00', N'Sat',
    161, 189, 350, 2,
    784, 784, 1568, 22.32,
    N'Fall', 9, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202509272300T202509280300/SAT/3054');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510012300T202510020200/MWK/A W1', N'A Wednesday In Charm City', N'MWK', N'A W1',
    '2025-10-01 23:00:00', '2025-10-02 02:00:00', N'Wed',
    76, 65, 141, 1,
    280, 280, 560, 25.18,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510012300T202510020200/MWK/A W1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510032300T202510040300/FNO/BIG5', N'Big River FNO', N'FNO', N'BIG5',
    '2025-10-03 23:00:00', '2025-10-04 03:00:00', N'Fri',
    291, 293, 584, 4,
    1701, 1512, 3213, 18.18,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510032300T202510040300/FNO/BIG5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510042330T202510050130/SAT/24 2', N'24 Hours of VATSIM - Leg 11', N'SAT', N'24 2',
    '2025-10-04 23:30:00', '2025-10-05 01:30:00', N'Sat',
    197, 225, 422, 2,
    1456, 1312, 2768, 15.25,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510042330T202510050130/SAT/24 2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510082300T202510090200/MWK/BVA2', N'BVA Regional Circuit: Bangor and Syracuse', N'MWK', N'BVA2',
    '2025-10-08 23:00:00', '2025-10-09 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510082300T202510090200/MWK/BVA2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510102300T202510110300/FNO/FNO4', N'FNO South America Edition', N'FNO', N'FNO4',
    '2025-10-10 23:00:00', '2025-10-11 03:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510102300T202510110300/FNO/FNO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510112300T202510120300/LIVE/VZD3', N'vZDC Presents: “Live from Wilmington” – Featuring ILM, FAY, & RDU!', N'LIVE', N'VZD3',
    '2025-10-11 23:00:00', '2025-10-12 03:00:00', N'Sat',
    177, 149, 326, 2,
    644, 630, 1274, 25.59,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510112300T202510120300/LIVE/VZD3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510122300T202510130200/SUN/ROS3', N'Rose City Ramble', N'SUN', N'ROS3',
    '2025-10-12 23:00:00', '2025-10-13 02:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510122300T202510130200/SUN/ROS3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510142300T202510150200/MWK/TUE1', N'Tuesday Nights in New York: The Legend of Sleepy Hollow', N'MWK', N'TUE1',
    '2025-10-14 23:00:00', '2025-10-15 02:00:00', N'Tue',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510142300T202510150200/MWK/TUE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510162300T202510170100/MWK/F1E4', N'F1ESTA', N'MWK', N'F1E4',
    '2025-10-16 23:00:00', '2025-10-17 01:00:00', N'Thu',
    30, 97, 127, 1,
    392, 392, 784, 16.20,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510162300T202510170100/MWK/F1E4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510172300T202510180300/FNO/NIG1', N'Nightmare on Colfax III', N'FNO', N'NIG1',
    '2025-10-17 23:00:00', '2025-10-18 03:00:00', N'Fri',
    262, 187, 449, 1,
    798, 672, 1470, 30.54,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510172300T202510180300/FNO/NIG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510182300T202510190300/LIVE/LIV3', N'Live from Chicago 2025', N'LIVE', N'LIV3',
    '2025-10-18 23:00:00', '2025-10-19 03:00:00', N'Sat',
    277, 181, 458, 2,
    1050, 784, 1834, 24.97,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510182300T202510190300/LIVE/LIV3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510191700T202510192100/SUN/BVA3', N'BVASO Fly-In: Operation Leaf Peeper', N'SUN', N'BVA3',
    '2025-10-19 17:00:00', '2025-10-19 21:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510191700T202510192100/SUN/BVA3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510222300T202510230200/MWK/RAZ5', N'Razorback Rendezvous', N'MWK', N'RAZ5',
    '2025-10-22 23:00:00', '2025-10-23 02:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510222300T202510230200/MWK/RAZ5');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510242300T202510250300/FNO/FLO4', N'Florida Night Ops', N'FNO', N'FLO4',
    '2025-10-24 23:00:00', '2025-10-25 03:00:00', N'Fri',
    331, 248, 579, 4,
    1680, 1442, 3122, 18.55,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510242300T202510250300/FNO/FLO4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510251700T202510252100/SAT/KEE1', N'Keeneland Fall Meet', N'SAT', N'KEE1',
    '2025-10-25 17:00:00', '2025-10-25 21:00:00', N'Sat',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510251700T202510252100/SAT/KEE1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510252300T202510260300/SAT/RNG1', N'RNGRRs on the Runway', N'SAT', N'RNG1',
    '2025-10-25 23:00:00', '2025-10-26 03:00:00', N'Sat',
    216, 154, 370, 1,
    406, 168, 574, 64.46,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510252300T202510260300/SAT/RNG1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510261900T202510262300/SUN/SAL1', N'Salt Lake City Sunday', N'SUN', N'SAL1',
    '2025-10-26 19:00:00', '2025-10-26 23:00:00', N'Sun',
    78, 88, 166, 1,
    462, 462, 924, 17.97,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510261900T202510262300/SUN/SAL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510262300T202510270300/SUN/TRI3', N'Trick or Bay [TRACON]', N'SUN', N'TRI3',
    '2025-10-26 23:00:00', '2025-10-27 03:00:00', N'Sun',
    166, 87, 253, 1,
    315, 315, 630, 40.16,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510262300T202510270300/SUN/TRI3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510282300T202510290300/MWK/FLI1', N'Flight of the Living Dead V', N'MWK', N'FLI1',
    '2025-10-28 23:00:00', '2025-10-29 03:00:00', N'Tue',
    74, 43, 117, 1,
    560, 560, 1120, 10.45,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510282300T202510290300/MWK/FLI1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510292200T202510300100/MWK/SUN1', N'Sunshine Spotlight Series: KDAB', N'MWK', N'SUN1',
    '2025-10-29 22:00:00', '2025-10-30 01:00:00', N'Wed',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510292200T202510300100/MWK/SUN1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202510312359T202511010400/FNO/MAY3', N'Mayan Afterlife FNO', N'FNO', N'MAY3',
    '2025-10-31 23:59:00', '2025-11-01 04:00:00', N'Fri',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 10, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202510312359T202511010400/FNO/MAY3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202511072359T202511080400/FNO/NEV1', N'Never Dull at Dulles', N'FNO', N'NEV1',
    '2025-11-07 23:59:00', '2025-11-08 04:00:00', N'Fri',
    246, 141, 387, 1,
    672, 504, 1176, 32.91,
    N'Fall', 11, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511072359T202511080400/FNO/NEV1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202511092359T202511100300/SUN/NOR2', N'Norcal Sunday: OAK HWD', N'SUN', N'NOR2',
    '2025-11-09 23:59:00', '2025-11-10 03:00:00', N'Sun',
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, NULL,
    N'Fall', 11, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511092359T202511100300/SUN/NOR2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202511142359T202511150400/FNO/NAS2', N'Nashville Nights, Charlotte Lights', N'FNO', N'NAS2',
    '2025-11-14 23:59:00', '2025-11-15 04:00:00', N'Fri',
    355, 271, 626, 2,
    973, 973, 1946, 32.17,
    N'Fall', 11, 2025,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202511142359T202511150400/FNO/NAS2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202108291500T202108300600/MWK/MSY1', NULL, N'MWK', N'MSY1',
    '2021-08-29 15:00:00', '2021-08-30 06:00:00', NULL,
    147, 118, 265, 1,
    375, 375, 750, 35.33,
    N'Summer', 8, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202108291500T202108300600/MWK/MSY1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110022200T202110030400/SAT/ZTL2', NULL, N'SAT', N'ZTL2',
    '2021-10-02 22:00:00', '2021-10-03 04:00:00', NULL,
    204, 204, 408, 2,
    1120, 1120, 2240, 18.21,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110022200T202110030400/SAT/ZTL2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202110241800T202110250200/SUN/SFO2', NULL, N'SUN', N'SFO2',
    '2021-10-24 18:00:00', '2021-10-25 02:00:00', NULL,
    180, 208, 388, 2,
    567, 549, 1116, 34.77,
    N'Fall', 10, 2021,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202110241800T202110250200/SUN/SFO2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202202130100T202202130400/SAT/MIL1', NULL, N'SAT', N'MIL1',
    '2022-02-13 01:00:00', '2022-02-13 04:00:00', NULL,
    144, 95, 239, 1,
    798, 798, 1596, 14.97,
    N'Winter', 2, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202202130100T202202130400/SAT/MIL1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202204100030T202204100300/SAT/TPC4', NULL, N'SAT', N'TPC4',
    '2022-04-10 00:30:00', '2022-04-10 03:00:00', NULL,
    85, 42, 127, 1,
    192, 180, 372, 34.14,
    N'Spring', 4, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202204100030T202204100300/SAT/TPC4');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202211140100T202211140500/SUN/TOG2', NULL, N'SUN', N'TOG2',
    '2022-11-14 01:00:00', '2022-11-14 05:00:00', NULL,
    77, 61, 138, 1,
    280, 280, 560, 24.64,
    N'Fall', 11, 2022,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202211140100T202211140500/SUN/TOG2');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202301290000T202301290400/SAT/OPP3', NULL, N'SAT', N'OPP3',
    '2023-01-29 00:00:00', '2023-01-29 04:00:00', NULL,
    187, 144, 331, 3,
    840, 840, 1680, 19.70,
    N'Winter', 1, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202301290000T202301290400/SAT/OPP3');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202302190100T202302190500/SAT/SFO1', NULL, N'SAT', N'SFO1',
    '2023-02-19 01:00:00', '2023-02-19 05:00:00', NULL,
    187, 142, 329, 1,
    432, 432, 864, 38.08,
    N'Winter', 2, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202302190100T202302190500/SAT/SFO1');
INSERT INTO dbo.vatusa_event (
    event_idx, event_name, event_type, event_code,
    start_utc, end_utc, day_of_week,
    total_arrivals, total_departures, total_operations, airport_count,
    rw_total_arrivals, rw_total_departures, rw_total_operations, pct_of_rw_total,
    season, month_num, year_num,
    tmr_link, timelapse_link, simaware_link, perti_plan_link,
    staffing_score, tactical_score, overall_score, source
) SELECT
    N'202311190000T202311190400/SAT/TOG3', NULL, N'SAT', N'TOG3',
    '2023-11-19 00:00:00', '2023-11-19 04:00:00', NULL,
    101, 81, 182, 1,
    280, 280, 560, 32.50,
    N'Fall', 11, 2023,
    NULL, NULL, NULL, NULL,
    NULL, NULL, NULL, 'EXCEL'
WHERE NOT EXISTS (SELECT 1 FROM dbo.vatusa_event WHERE event_idx = N'202311190000T202311190400/SAT/TOG3');

GO
PRINT 'Events inserted.';
GO
