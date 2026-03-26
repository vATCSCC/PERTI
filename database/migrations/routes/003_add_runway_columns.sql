-- Add departure/arrival runway columns to route_history_facts
-- Extracted from /{RWY} tokens in raw route strings (e.g., AMLUH2G/33 → dep_rwy=33, BK83A/16L → arr_rwy=16L)

ALTER TABLE `route_history_facts`
    ADD COLUMN `dep_rwy` VARCHAR(3) NULL AFTER `star_name`,
    ADD COLUMN `arr_rwy` VARCHAR(3) NULL AFTER `dep_rwy`;

ALTER TABLE `route_history_facts`
    ADD INDEX `ix_dep_rwy` (`dep_rwy`, `partition_month`),
    ADD INDEX `ix_arr_rwy` (`arr_rwy`, `partition_month`);
