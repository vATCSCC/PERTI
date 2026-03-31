-- Migration 014: Add GUFI (UUID) to adl_flight_core
-- GUFI = Globally Unique Flight Identifier, immutable per flight
-- Generated on INSERT via DEFAULT NEWID(), propagated to SWIM sync

ALTER TABLE dbo.adl_flight_core ADD gufi UNIQUEIDENTIFIER NOT NULL
    CONSTRAINT DF_adl_flight_core_gufi DEFAULT NEWID();

CREATE UNIQUE INDEX IX_adl_flight_core_gufi ON dbo.adl_flight_core (gufi);
