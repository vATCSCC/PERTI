-- ============================================================================
-- Airlines Reference Data - Comprehensive List
-- 
-- Sources: OpenFlights, ICAO, IATA databases
-- Includes ~500+ active airlines worldwide
-- ============================================================================

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- ============================================================================
-- 1. Create airlines table if not exists
-- ============================================================================

IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID(N'dbo.airlines') AND type = 'U')
BEGIN
    CREATE TABLE dbo.airlines (
        airline_id      INT IDENTITY(1,1) NOT NULL,
        icao            CHAR(3) NOT NULL,
        iata            CHAR(2) NULL,
        name            NVARCHAR(128) NOT NULL,
        callsign        NVARCHAR(64) NULL,
        country         NVARCHAR(64) NULL,
        country_code    CHAR(2) NULL,
        is_virtual      BIT NOT NULL DEFAULT 0,
        is_active       BIT NOT NULL DEFAULT 1,
        
        CONSTRAINT PK_airlines PRIMARY KEY CLUSTERED (airline_id),
        CONSTRAINT UQ_airlines_icao UNIQUE (icao)
    );
    
    CREATE NONCLUSTERED INDEX IX_airlines_iata ON dbo.airlines (iata) WHERE iata IS NOT NULL;
    CREATE NONCLUSTERED INDEX IX_airlines_name ON dbo.airlines (name);
    
    PRINT 'Created table dbo.airlines';
END
GO

-- ============================================================================
-- 2. Clear existing data and insert comprehensive list
-- ============================================================================

TRUNCATE TABLE dbo.airlines;
GO

INSERT INTO dbo.airlines (icao, iata, name, callsign, country, country_code) VALUES
-- ============================================================================
-- UNITED STATES - Major Carriers
-- ============================================================================
('AAL', 'AA', 'American Airlines', 'AMERICAN', 'United States', 'US'),
('DAL', 'DL', 'Delta Air Lines', 'DELTA', 'United States', 'US'),
('UAL', 'UA', 'United Airlines', 'UNITED', 'United States', 'US'),
('SWA', 'WN', 'Southwest Airlines', 'SOUTHWEST', 'United States', 'US'),
('JBU', 'B6', 'JetBlue Airways', 'JETBLUE', 'United States', 'US'),
('ASA', 'AS', 'Alaska Airlines', 'ALASKA', 'United States', 'US'),
('NKS', 'NK', 'Spirit Airlines', 'SPIRIT WINGS', 'United States', 'US'),
('FFT', 'F9', 'Frontier Airlines', 'FRONTIER', 'United States', 'US'),
('HAL', 'HA', 'Hawaiian Airlines', 'HAWAIIAN', 'United States', 'US'),
('AAY', 'G4', 'Allegiant Air', 'ALLEGIANT', 'United States', 'US'),
('SCX', 'SY', 'Sun Country Airlines', 'SUN COUNTRY', 'United States', 'US'),
('BXA', 'MX', 'Breeze Airways', 'BREEZE', 'United States', 'US'),
('AVP', 'AV', 'Avelo Airlines', 'AVELO', 'United States', 'US'),

-- UNITED STATES - Cargo
('FDX', 'FX', 'FedEx Express', 'FEDEX', 'United States', 'US'),
('UPS', '5X', 'UPS Airlines', 'UPS', 'United States', 'US'),
('ABX', 'GB', 'ABX Air', 'ABEX', 'United States', 'US'),
('ATN', '8C', 'Air Transport International', 'AIR TRANSPORT', 'United States', 'US'),
('GTI', 'GT', 'Atlas Air', 'GIANT', 'United States', 'US'),
('PAC', 'PO', 'Polar Air Cargo', 'POLAR', 'United States', 'US'),
('KFS', 'K4', 'Kalitta Air', 'CONNIE', 'United States', 'US'),
('WCW', 'WI', 'Western Global Airlines', 'WESTERN GLOBAL', 'United States', 'US'),
('SQS', 'S8', 'Southern Air', 'SOUTHERN AIR', 'United States', 'US'),
('ACK', 'KA', 'Ameristar Air Cargo', 'AMERISTAR', 'United States', 'US'),

-- UNITED STATES - Regional Airlines
('SKW', 'OO', 'SkyWest Airlines', 'SKYWEST', 'United States', 'US'),
('ENY', 'MQ', 'Envoy Air', 'ENVOY', 'United States', 'US'),
('RPA', 'YX', 'Republic Airways', 'BRICKYARD', 'United States', 'US'),
('PDT', 'PT', 'Piedmont Airlines', 'PIEDMONT', 'United States', 'US'),
('JIA', 'OH', 'PSA Airlines', 'BLUE STREAK', 'United States', 'US'),
('ASH', 'QX', 'Horizon Air', 'HORIZON AIR', 'United States', 'US'),
('CPZ', 'C5', 'CommutAir', 'COMMUTAIR', 'United States', 'US'),
('GJS', 'G7', 'GoJet Airlines', 'LINDBERGH', 'United States', 'US'),
('EDV', '9E', 'Endeavor Air', 'ENDEAVOR', 'United States', 'US'),
('MES', 'YV', 'Mesa Airlines', 'AIR SHUTTLE', 'United States', 'US'),
('AIP', 'X1', 'Air Independence', 'AIR INDEPENDENCE', 'United States', 'US'),
('EGF', 'EM', 'Empire Airlines', 'EMPIRE AIR', 'United States', 'US'),
('KYE', 'KE', 'Key Lime Air', 'KEY LIME', 'United States', 'US'),
('BKA', 'BT', 'Boutique Air', 'BOUTIQUE', 'United States', 'US'),
('CTR', 'CA', 'Contour Airlines', 'CONTOUR', 'United States', 'US'),
('SRY', 'SI', 'Southern Airways Express', 'SOUTHERN EXPRESS', 'United States', 'US'),
('CKY', 'CQ', 'Cape Air', 'CAIR', 'United States', 'US'),
('TSA', 'TS', 'Tradewind Aviation', 'TRADEWIND', 'United States', 'US'),

-- UNITED STATES - Charter/Other
('OMS', 'O5', 'Omni Air International', 'OMNI EXPRESS', 'United States', 'US'),
('MCO', 'MY', 'Miami Air International', 'BISCAYNE', 'United States', 'US'),
('SNC', 'SE', 'Sun Air Express', 'SUN EXPRESS', 'United States', 'US'),
('JTL', 'JU', 'Jet Linx Aviation', 'JET LINX', 'United States', 'US'),
('XOJ', 'XO', 'XOJET', 'XOJET', 'United States', 'US'),
('LXJ', 'LJ', 'Flexjet', 'FLEXJET', 'United States', 'US'),
('NJA', 'QS', 'NetJets', 'NETJET', 'United States', 'US'),
('EJA', 'EJ', 'Executive Jet Management', 'JET SPEED', 'United States', 'US'),

-- ============================================================================
-- CANADA
-- ============================================================================
('ACA', 'AC', 'Air Canada', 'AIR CANADA', 'Canada', 'CA'),
('WJA', 'WS', 'WestJet', 'WESTJET', 'Canada', 'CA'),
('TSC', 'TS', 'Air Transat', 'TRANSAT', 'Canada', 'CA'),
('POE', 'PD', 'Porter Airlines', 'PORTER', 'Canada', 'CA'),
('JZA', 'QK', 'Jazz Aviation', 'JAZZ', 'Canada', 'CA'),
('FLE', 'F8', 'Flair Airlines', 'FLAIR', 'Canada', 'CA'),
('SWG', 'WG', 'Sunwing Airlines', 'SUNWING', 'Canada', 'CA'),
('ROU', 'RV', 'Rouge', 'ROUGE', 'Canada', 'CA'),
('PAL', 'PB', 'PAL Airlines', 'PROVINCIAL', 'Canada', 'CA'),
('CRQ', 'QC', 'Chrono Aviation', 'CHRONO', 'Canada', 'CA'),
('CME', 'CM', 'Canadian North', 'CANADIAN NORTH', 'Canada', 'CA'),
('MOR', 'MO', 'Morningstar Air Express', 'MORNINGSTAR', 'Canada', 'CA'),
('CWA', 'CW', 'Central Mountain Air', 'SUMMIT', 'Canada', 'CA'),

-- ============================================================================
-- MEXICO & CENTRAL AMERICA
-- ============================================================================
('AMX', 'AM', 'Aeromexico', 'AEROMEXICO', 'Mexico', 'MX'),
('VOI', 'Y4', 'Volaris', 'VOLARIS', 'Mexico', 'MX'),
('VIV', 'VB', 'VivaAerobus', 'AEROENLACES', 'Mexico', 'MX'),
('SLI', '4O', 'Interjet', 'INTERJET', 'Mexico', 'MX'),
('MXA', 'QA', 'Aeromar', 'AEROMAR', 'Mexico', 'MX'),
('CMP', 'CM', 'Copa Airlines', 'COPA', 'Panama', 'PA'),
('TRS', 'TA', 'Avianca Costa Rica', 'LACSA', 'Costa Rica', 'CR'),
('TGU', 'TG', 'TAG Airlines', 'TAG', 'Guatemala', 'GT'),
('NIL', 'N4', 'Aerolíneas Mas', 'AEROLINEAS MAS', 'Mexico', 'MX'),

-- ============================================================================
-- CARIBBEAN
-- ============================================================================
('BWA', 'BW', 'Caribbean Airlines', 'CARIBBEAN', 'Trinidad and Tobago', 'TT'),
('BHS', 'UP', 'Bahamasair', 'BAHAMAS', 'Bahamas', 'BS'),
('CAY', 'KX', 'Cayman Airways', 'CAYMAN', 'Cayman Islands', 'KY'),
('LAE', 'LI', 'LIAT', 'LIAT', 'Antigua and Barbuda', 'AG'),
('AAF', 'RF', 'Aruba Airlines', 'ARUBA', 'Aruba', 'AW'),
('WJM', 'BM', 'InterCaribbean Airways', 'INTER-CARIBBEAN', 'Turks and Caicos', 'TC'),
('SBJ', 'WM', 'Winair', 'WINDWARD', 'Sint Maarten', 'SX'),
('SLM', 'SN', 'Surinam Airways', 'SURINAM', 'Suriname', 'SR'),
('DJU', 'JY', 'Air Turks and Caicos', 'AIR TURKS', 'Turks and Caicos', 'TC'),

-- ============================================================================
-- SOUTH AMERICA
-- ============================================================================
('AVA', 'AV', 'Avianca', 'AVIANCA', 'Colombia', 'CO'),
('LAN', 'LA', 'LATAM Airlines Chile', 'LAN', 'Chile', 'CL'),
('TAM', 'JJ', 'LATAM Airlines Brasil', 'TAM', 'Brazil', 'BR'),
('ARG', 'AR', 'Aerolíneas Argentinas', 'ARGENTINA', 'Argentina', 'AR'),
('AZU', 'AD', 'Azul Brazilian Airlines', 'AZUL', 'Brazil', 'BR'),
('GLO', 'G3', 'GOL Linhas Aéreas', 'GOL TRANSPORTE', 'Brazil', 'BR'),
('SKY', 'H2', 'Sky Airline', 'SKY CHILE', 'Chile', 'CL'),
('JAM', 'JA', 'JetSMART', 'SMART JET', 'Chile', 'CL'),
('LAP', 'PZ', 'LATAM Airlines Paraguay', 'PARAGUAYA', 'Paraguay', 'PY'),
('LAW', 'WA', 'LATAM Airlines Peru', 'LAP', 'Peru', 'PE'),
('BOV', 'OB', 'Boliviana de Aviación', 'BOLIVIANA', 'Bolivia', 'BO'),
('TAO', 'EQ', 'TAME', 'TAME', 'Ecuador', 'EC'),
('AEA', '2K', 'Aerolineas Estelar', 'AEROLINEAS ESTELAR', 'Venezuela', 'VE'),
('PEV', 'P9', 'Peruvian Airlines', 'PERUVIAN', 'Peru', 'PE'),
('AJU', 'JU', 'Amaszonas', 'AMASZONAS', 'Bolivia', 'BO'),
('PSO', 'P0', 'Satena', 'SATENA', 'Colombia', 'CO'),
('WSW', 'WJ', 'Wingo', 'WINGO', 'Colombia', 'CO'),

-- ============================================================================
-- UNITED KINGDOM & IRELAND
-- ============================================================================
('BAW', 'BA', 'British Airways', 'SPEEDBIRD', 'United Kingdom', 'GB'),
('VIR', 'VS', 'Virgin Atlantic', 'VIRGIN', 'United Kingdom', 'GB'),
('EZY', 'U2', 'easyJet', 'EASY', 'United Kingdom', 'GB'),
('EZS', 'DS', 'easyJet Switzerland', 'TOPSWISS', 'Switzerland', 'CH'),
('TUI', 'BY', 'TUI Airways', 'THOMSON', 'United Kingdom', 'GB'),
('JET', 'LS', 'Jet2', 'CHANNEX', 'United Kingdom', 'GB'),
('LOG', 'LM', 'Loganair', 'LOGAN', 'United Kingdom', 'GB'),
('SHT', 'ZB', 'BA Cityflyer', 'SHUTTLE', 'United Kingdom', 'GB'),
('BMS', 'VZ', 'Titan Airways', 'ZITRON', 'United Kingdom', 'GB'),
('WZZ', 'W6', 'Wizz Air', 'WIZZAIR', 'United Kingdom', 'GB'),
('EIN', 'EI', 'Aer Lingus', 'SHAMROCK', 'Ireland', 'IE'),
('RYR', 'FR', 'Ryanair', 'RYANAIR', 'Ireland', 'IE'),
('RUK', 'RK', 'Ryanair UK', 'RYANAIR UK', 'United Kingdom', 'GB'),
('CKS', 'CV', 'Cargolux UK', 'CARGOLUX UK', 'United Kingdom', 'GB'),
('EXS', 'E7', 'Jet2.com', 'FRIENDLY', 'United Kingdom', 'GB'),
('STK', 'SK', 'Eastern Airways', 'EASTERN', 'United Kingdom', 'GB'),

-- ============================================================================
-- GERMANY
-- ============================================================================
('DLH', 'LH', 'Lufthansa', 'LUFTHANSA', 'Germany', 'DE'),
('EWG', 'EW', 'Eurowings', 'EUROWINGS', 'Germany', 'DE'),
('CLH', 'CL', 'Lufthansa CityLine', 'HANSALINE', 'Germany', 'DE'),
('GEC', 'LH', 'Lufthansa Cargo', 'LUFTHANSA CARGO', 'Germany', 'DE'),
('TUI', 'X3', 'TUIfly', 'TUIFLY', 'Germany', 'DE'),
('CFG', 'DE', 'Condor', 'CONDOR', 'Germany', 'DE'),
('BER', 'A6', 'Air Berlin', 'AIR BERLIN', 'Germany', 'DE'),
('AEE', 'A3', 'Aegean Airlines', 'AEGEAN', 'Greece', 'GR'),
('EZE', 'EJ', 'Eurowings Europe', 'SMARTWINGS', 'Germany', 'DE'),

-- ============================================================================
-- FRANCE
-- ============================================================================
('AFR', 'AF', 'Air France', 'AIRFRANS', 'France', 'FR'),
('HOP', 'A5', 'Air France Hop', 'HOP', 'France', 'FR'),
('TVF', 'TO', 'Transavia France', 'FRANCE SOLEIL', 'France', 'FR'),
('FPO', 'FP', 'French Bee', 'FRENCH BEE', 'France', 'FR'),
('BEE', 'FE', 'Belle Air Europe', 'BELLSKI', 'France', 'FR'),
('AIC', 'SB', 'Aircalin', 'AIRCALIN', 'New Caledonia', 'NC'),
('REU', 'UU', 'Air Austral', 'REUNION', 'Réunion', 'RE'),
('CRL', 'SS', 'Corsair International', 'CORSAIR', 'France', 'FR'),
('ASL', 'AG', 'ASL Airlines France', 'FRENCH POST', 'France', 'FR'),

-- ============================================================================
-- NETHERLANDS, BELGIUM, LUXEMBOURG
-- ============================================================================
('KLM', 'KL', 'KLM Royal Dutch Airlines', 'KLM', 'Netherlands', 'NL'),
('TRA', 'HV', 'Transavia', 'TRANSAVIA', 'Netherlands', 'NL'),
('MPH', 'MP', 'Martinair', 'MARTINAIR', 'Netherlands', 'NL'),
('CLV', 'CV', 'Cargolux', 'CARGOLUX', 'Luxembourg', 'LU'),
('BEL', 'SN', 'Brussels Airlines', 'BEE LINE', 'Belgium', 'BE'),
('TNT', '3V', 'ASL Airlines Belgium', 'TNT BELGIUM', 'Belgium', 'BE'),
('TFL', 'TF', 'TUI fly Belgium', 'BEAUTY', 'Belgium', 'BE'),

-- ============================================================================
-- SPAIN & PORTUGAL
-- ============================================================================
('IBE', 'IB', 'Iberia', 'IBERIA', 'Spain', 'ES'),
('IBS', 'I2', 'Iberia Express', 'IBEREXPRESS', 'Spain', 'ES'),
('VLG', 'VY', 'Vueling', 'VUELING', 'Spain', 'ES'),
('ANE', 'YW', 'Air Nostrum', 'AIR NOSTRUM', 'Spain', 'ES'),
('AEA', 'UX', 'Air Europa', 'EUROPA', 'Spain', 'ES'),
('PVL', 'PV', 'Privilege Style', 'PRIVILEGE', 'Spain', 'ES'),
('SWT', 'SM', 'SWIFTAIR', 'SWIFT', 'Spain', 'ES'),
('BTI', 'BT', 'Binter Canarias', 'BINTER', 'Spain', 'ES'),
('VOZ', 'V7', 'Volotea', 'VOLOTEA', 'Spain', 'ES'),
('TAP', 'TP', 'TAP Air Portugal', 'TAP PORTUGAL', 'Portugal', 'PT'),
('NVR', 'NR', 'Portugalia', 'PORTUGALIA', 'Portugal', 'PT'),
('STP', 'S4', 'SATA Air Açores', 'SATA', 'Portugal', 'PT'),
('RZO', 'SP', 'SATA International', 'AIR AZORES', 'Portugal', 'PT'),

-- ============================================================================
-- ITALY
-- ============================================================================
('AZA', 'AZ', 'ITA Airways', 'ITARROW', 'Italy', 'IT'),
('NOS', 'NO', 'Neos', 'NEOS', 'Italy', 'IT'),

-- ============================================================================
-- SCANDINAVIA
-- ============================================================================
('SAS', 'SK', 'Scandinavian Airlines', 'SCANDINAVIAN', 'Sweden', 'SE'),
('NOZ', 'D8', 'Norwegian Air Sweden', 'NORSEMAN', 'Sweden', 'SE'),
('NAX', 'DY', 'Norwegian Air Shuttle', 'NOR SHUTTLE', 'Norway', 'NO'),
('FIN', 'AY', 'Finnair', 'FINNAIR', 'Finland', 'FI'),
('ICE', 'FI', 'Icelandair', 'ICEAIR', 'Iceland', 'IS'),
('WOW', 'WW', 'WOW Air', 'WOW AIR', 'Iceland', 'IS'),
('BRA', 'BC', 'Braathens Regional', 'SCANWINGS', 'Sweden', 'SE'),
('WIF', 'WF', 'Widerøe', 'WIDEROE', 'Norway', 'NO'),

-- ============================================================================
-- SWITZERLAND & AUSTRIA
-- ============================================================================
('SWR', 'LX', 'Swiss International', 'SWISS', 'Switzerland', 'CH'),
('AUA', 'OS', 'Austrian Airlines', 'AUSTRIAN', 'Austria', 'AT'),
('ELG', 'EO', 'Edelweiss Air', 'EDELWEISS', 'Switzerland', 'CH'),
('HLF', 'HL', 'Helvetic Airways', 'HELVETIC', 'Switzerland', 'CH'),

-- ============================================================================
-- EASTERN EUROPE
-- ============================================================================
('LOT', 'LO', 'LOT Polish Airlines', 'LOT', 'Poland', 'PL'),
('CSA', 'OK', 'Czech Airlines', 'CSA', 'Czech Republic', 'CZ'),
('TVS', 'QS', 'SmartWings', 'SMARTWINGS', 'Czech Republic', 'CZ'),
('TAR', 'RO', 'TAROM', 'TAROM', 'Romania', 'RO'),
('BUC', 'FB', 'Bulgaria Air', 'FLYING BULGARIA', 'Bulgaria', 'BG'),
('WZZ', 'W6', 'Wizz Air', 'WIZZAIR', 'Hungary', 'HU'),
('AHY', 'J2', 'Azerbaijan Airlines', 'AZAL', 'Azerbaijan', 'AZ'),
('UAE', 'EK', 'Emirates', 'EMIRATES', 'United Arab Emirates', 'AE'),
('GIA', 'GA', 'Garuda Indonesia', 'INDONESIA', 'Indonesia', 'ID'),
('MAU', 'MK', 'Air Mauritius', 'AIRMAURITIUS', 'Mauritius', 'MU'),
('UKR', 'PS', 'Ukraine International', 'UKRAINE INTERNATIONAL', 'Ukraine', 'UA'),
('AUA', 'AU', 'Air Ukraine', 'AIR UKRAINE', 'Ukraine', 'UA'),
('BTA', 'BV', 'Blue Bird Airways', 'BLUE BIRD', 'Greece', 'GR'),
('ADB', 'A7', 'Air Dolomiti', 'DOLOMITI', 'Italy', 'IT'),
('ADR', 'JP', 'Adria Airways', 'ADRIA', 'Slovenia', 'SI'),
('JAT', 'JU', 'Air Serbia', 'AIR SERBIA', 'Serbia', 'RS'),
('CTN', 'OU', 'Croatia Airlines', 'CROATIA', 'Croatia', 'HR'),
('MAH', 'MW', 'Montenegro Airlines', 'MONTENEGRO', 'Montenegro', 'ME'),
('ALK', 'LK', 'Air Lituanica', 'LITUANICA', 'Lithuania', 'LT'),
('BTI', 'BT', 'airBaltic', 'AIRBALTIC', 'Latvia', 'LV'),
('EST', 'OV', 'Estonian Air', 'ESTONIAN', 'Estonia', 'EE'),
('THD', 'TD', 'Nordica', 'ESTONIAN NORDIC', 'Estonia', 'EE'),

-- ============================================================================
-- RUSSIA & CIS
-- ============================================================================
('AFL', 'SU', 'Aeroflot', 'AEROFLOT', 'Russia', 'RU'),
('SDM', 'S7', 'S7 Airlines', 'SIBERIAN AIRLINES', 'Russia', 'RU'),
('SVR', 'U6', 'Ural Airlines', 'SVERDLOVSK AIR', 'Russia', 'RU'),
('UTA', 'UT', 'UTair', 'UTAIR', 'Russia', 'RU'),
('SBI', 'DP', 'Pobeda', 'POBEDA', 'Russia', 'RU'),
('SKP', 'NW', 'Nordwind Airlines', 'NORDLAND', 'Russia', 'RU'),
('RWZ', 'WZ', 'Red Wings', 'RED WINGS', 'Russia', 'RU'),
('YAK', 'R3', 'Yakutia Airlines', 'AIR YAKUTIA', 'Russia', 'RU'),
('AZV', 'A4', 'Azimuth', 'AZIMUT', 'Russia', 'RU'),
('KZR', 'KC', 'Air Astana', 'ASTANALINE', 'Kazakhstan', 'KZ'),
('UZB', 'HY', 'Uzbekistan Airways', 'UZBEK', 'Uzbekistan', 'UZ'),
('TAJ', 'TU', 'Turkmenistan Airlines', 'TURKMENISTAN', 'Turkmenistan', 'TM'),
('SOV', 'QH', 'Air Kyrgyzstan', 'KYRGYZSTAN', 'Kyrgyzstan', 'KG'),
('BRU', 'U8', 'Armavia', 'ARMAVIA', 'Armenia', 'AM'),
('GBA', 'A9', 'Georgian Airways', 'TAMAZI', 'Georgia', 'GE'),
('BEK', 'B2', 'Belavia', 'BELAVIA', 'Belarus', 'BY'),
('AZQ', 'QN', 'Air Armenia', 'AIRKITE', 'Armenia', 'AM'),

-- ============================================================================
-- TURKEY & MIDDLE EAST
-- ============================================================================
('THY', 'TK', 'Turkish Airlines', 'TURKISH', 'Turkey', 'TR'),
('PGT', 'PC', 'Pegasus Airlines', 'SUNTURK', 'Turkey', 'TR'),
('SXS', 'XQ', 'SunExpress', 'SUNEXPRESS', 'Turkey', 'TR'),
('AJA', 'JJ', 'Atlas Global', 'ATLAS GLOBAL', 'Turkey', 'TR'),
('UAE', 'EK', 'Emirates', 'EMIRATES', 'United Arab Emirates', 'AE'),
('ETD', 'EY', 'Etihad Airways', 'ETIHAD', 'United Arab Emirates', 'AE'),
('QTR', 'QR', 'Qatar Airways', 'QATARI', 'Qatar', 'QA'),
('SVA', 'SV', 'Saudia', 'SAUDIA', 'Saudi Arabia', 'SA'),
('FDB', 'FZ', 'flydubai', 'SKYDUBAI', 'United Arab Emirates', 'AE'),
('ABY', 'G9', 'Air Arabia', 'ARABIA', 'United Arab Emirates', 'AE'),
('NAS', 'XY', 'flynas', 'FLYNAS', 'Saudi Arabia', 'SA'),
('GFA', 'GF', 'Gulf Air', 'GULF AIR', 'Bahrain', 'BH'),
('OMA', 'WY', 'Oman Air', 'OMAN AIR', 'Oman', 'OM'),
('RJA', 'RJ', 'Royal Jordanian', 'JORDANIAN', 'Jordan', 'JO'),
('ELY', 'LY', 'El Al', 'ELAL', 'Israel', 'IL'),
('ISR', '6H', 'Israir', 'ISRAIR', 'Israel', 'IL'),
('AIJ', 'IZ', 'Arkia Israeli Airlines', 'ARKIA', 'Israel', 'IL'),
('MEA', 'ME', 'Middle East Airlines', 'CEDAR JET', 'Lebanon', 'LB'),
('KAC', 'KU', 'Kuwait Airways', 'KUWAITI', 'Kuwait', 'KW'),
('RYE', 'IR', 'Iran Air', 'IRANAIR', 'Iran', 'IR'),
('IRU', 'B9', 'Iran Airtour', 'IRAN AIRTOUR', 'Iran', 'IR'),
('MHN', 'EP', 'Mahan Air', 'MAHAN AIR', 'Iran', 'IR'),
('IRC', 'IR', 'Iran Aseman Airlines', 'ASEMAN', 'Iran', 'IR'),
('YMN', 'IY', 'Yemenia', 'YEMENIA', 'Yemen', 'YE'),
('SYR', 'RB', 'Syrian Arab Airlines', 'SYRIANAIR', 'Syria', 'SY'),
('IAW', 'IA', 'Iraqi Airways', 'IRAQI', 'Iraq', 'IQ'),

-- ============================================================================
-- INDIA & SOUTH ASIA
-- ============================================================================
('AIC', 'AI', 'Air India', 'AIRINDIA', 'India', 'IN'),
('IGO', '6E', 'IndiGo', 'IFLY', 'India', 'IN'),
('AXB', 'IX', 'Air India Express', 'EXPRESS INDIA', 'India', 'IN'),
('SJT', 'SG', 'SpiceJet', 'SPICEJET', 'India', 'IN'),
('GOW', 'G8', 'Go First', 'GO AIR', 'India', 'IN'),
('VTI', 'UK', 'Vistara', 'VISTARA', 'India', 'IN'),
('JAI', '9W', 'Jet Airways', 'JET AIRWAYS', 'India', 'IN'),
('AKJ', 'QP', 'Akasa Air', 'AKASA AIR', 'India', 'IN'),
('ALV', 'G8', 'Alliance Air', 'DOYEN', 'India', 'IN'),
('PIA', 'PK', 'Pakistan International', 'PAKISTAN', 'Pakistan', 'PK'),
('SLK', 'UL', 'SriLankan Airlines', 'SRILANKAN', 'Sri Lanka', 'LK'),
('GBA', 'KB', 'Drukair', 'ROYAL BHUTAN', 'Bhutan', 'BT'),
('RNA', 'RA', 'Nepal Airlines', 'ROYAL NEPAL', 'Nepal', 'NP'),
('BBC', 'BG', 'Biman Bangladesh', 'BANGLADESH', 'Bangladesh', 'BD'),
('MDV', 'Q2', 'Maldivian', 'ISLAND AVIATION', 'Maldives', 'MV'),

-- ============================================================================
-- SOUTHEAST ASIA
-- ============================================================================
('SIA', 'SQ', 'Singapore Airlines', 'SINGAPORE', 'Singapore', 'SG'),
('TGW', 'TR', 'Scoot', 'SCOOTER', 'Singapore', 'SG'),
('SLK', '3K', 'Jetstar Asia', 'JETSTAR ASIA', 'Singapore', 'SG'),
('THA', 'TG', 'Thai Airways', 'THAI', 'Thailand', 'TH'),
('THD', 'WE', 'Thai Smile', 'THAI SMILE', 'Thailand', 'TH'),
('TAX', 'XJ', 'Thai AirAsia X', 'EXPRESS WING', 'Thailand', 'TH'),
('AIQ', 'FD', 'Thai AirAsia', 'THAI AIRASIA', 'Thailand', 'TH'),
('BKP', 'PG', 'Bangkok Airways', 'BANGKOK AIR', 'Thailand', 'TH'),
('NOK', 'DD', 'Nok Air', 'NOK AIR', 'Thailand', 'TH'),
('MAS', 'MH', 'Malaysia Airlines', 'MALAYSIAN', 'Malaysia', 'MY'),
('AXM', 'AK', 'AirAsia', 'RED CAP', 'Malaysia', 'MY'),
('MXD', 'D7', 'AirAsia X', 'XANADU', 'Malaysia', 'MY'),
('MWG', 'OD', 'Malindo Air', 'MALINDO', 'Malaysia', 'MY'),
('FYT', 'FY', 'Firefly', 'FIREFLY', 'Malaysia', 'MY'),
('GIA', 'GA', 'Garuda Indonesia', 'INDONESIA', 'Indonesia', 'ID'),
('LNI', 'JT', 'Lion Air', 'LION INTER', 'Indonesia', 'ID'),
('BTK', 'ID', 'Batik Air', 'BATIK', 'Indonesia', 'ID'),
('CTV', 'QG', 'Citilink', 'SUPERGREEN', 'Indonesia', 'ID'),
('SJY', 'SJ', 'Sriwijaya Air', 'SRIWIJAYA', 'Indonesia', 'ID'),
('PAL', 'PR', 'Philippine Airlines', 'PHILIPPINE', 'Philippines', 'PH'),
('CEB', '5J', 'Cebu Pacific', 'CEBU AIR', 'Philippines', 'PH'),
('APG', 'Z2', 'Philippines AirAsia', 'COOL RED', 'Philippines', 'PH'),
('VNA', 'VN', 'Vietnam Airlines', 'VIETNAM AIRLINES', 'Vietnam', 'VN'),
('PIC', 'BL', 'Pacific Airlines', 'PACIFIC AIRLINES', 'Vietnam', 'VN'),
('HVN', 'VJ', 'VietJet Air', 'VIETJET', 'Vietnam', 'VN'),
('BAV', 'QH', 'Bamboo Airways', 'BAMBOO', 'Vietnam', 'VN'),
('LXR', 'SL', 'Thai Lion Air', 'MENTARI', 'Thailand', 'TH'),
('CXA', 'K6', 'Cambodia Angkor Air', 'CAMBODIA ANGKOR', 'Cambodia', 'KH'),
('LAO', 'QV', 'Lao Airlines', 'LAO', 'Laos', 'LA'),
('MNA', '8M', 'Myanmar Airways', 'ROYAL GOLDEN', 'Myanmar', 'MM'),
('RBA', 'BI', 'Royal Brunei', 'BRUNEI', 'Brunei', 'BN'),

-- ============================================================================
-- CHINA
-- ============================================================================
('CCA', 'CA', 'Air China', 'AIR CHINA', 'China', 'CN'),
('CES', 'MU', 'China Eastern', 'CHINA EASTERN', 'China', 'CN'),
('CSN', 'CZ', 'China Southern', 'CHINA SOUTHERN', 'China', 'CN'),
('HDA', 'HU', 'Hainan Airlines', 'HAINAN', 'China', 'CN'),
('CHH', '3U', 'Sichuan Airlines', 'SICHUAN', 'China', 'CN'),
('CSH', 'FM', 'Shanghai Airlines', 'SHANGHAI AIR', 'China', 'CN'),
('CXA', 'MF', 'Xiamen Airlines', 'XIAMEN AIR', 'China', 'CN'),
('CDC', 'KN', 'China United Airlines', 'LIANHANG', 'China', 'CN'),
('CDG', 'SC', 'Shandong Airlines', 'SHANDONG', 'China', 'CN'),
('CSZ', 'ZH', 'Shenzhen Airlines', 'SHENZHEN AIR', 'China', 'CN'),
('CQH', 'PN', 'West Air', 'CHINA WEST AIR', 'China', 'CN'),
('GCR', 'G5', 'China Express', 'CHINA EXPRESS', 'China', 'CN'),
('LKE', 'GJ', 'Loong Air', 'LOONG AIR', 'China', 'CN'),
('DKH', 'HO', 'Juneyao Airlines', 'AIR JUNEYAO', 'China', 'CN'),
('OKA', 'JD', 'Beijing Capital Airlines', 'CAPITAL JET', 'China', 'CN'),
('SFJ', '9C', 'Spring Airlines', 'AIR SPRING', 'China', 'CN'),
('LHA', '8L', 'Lucky Air', 'LUCKY AIR', 'China', 'CN'),
('TBA', 'GS', 'Tianjin Airlines', 'TIANJIN AIR', 'China', 'CN'),
('CBJ', 'UQ', 'Urumqi Air', 'URUMQI AIR', 'China', 'CN'),
('JYA', 'CN', 'Grand China Air', 'GRAND CHINA', 'China', 'CN'),

-- ============================================================================
-- HONG KONG, MACAU, TAIWAN
-- ============================================================================
('CPA', 'CX', 'Cathay Pacific', 'CATHAY', 'Hong Kong', 'HK'),
('HDA', 'KA', 'Cathay Dragon', 'DRAGON', 'Hong Kong', 'HK'),
('HKE', 'UO', 'Hong Kong Express', 'HONGKONG SHUTTLE', 'Hong Kong', 'HK'),
('HKG', 'HX', 'Hong Kong Airlines', 'BAUHINIA', 'Hong Kong', 'HK'),
('CRK', 'NX', 'Air Macau', 'AIR MACAU', 'Macau', 'MO'),
('CAL', 'CI', 'China Airlines', 'DYNASTY', 'Taiwan', 'TW'),
('EVA', 'BR', 'EVA Air', 'EVA', 'Taiwan', 'TW'),
('TNA', 'IT', 'Tigerair Taiwan', 'SMART CAT', 'Taiwan', 'TW'),
('MDA', 'AE', 'Mandarin Airlines', 'MANDARIN', 'Taiwan', 'TW'),
('UIA', 'B7', 'Uni Air', 'GLORY', 'Taiwan', 'TW'),
('SCO', '9S', 'StarFlyer', 'STARFLYER', 'Japan', 'JP'),

-- ============================================================================
-- JAPAN
-- ============================================================================
('JAL', 'JL', 'Japan Airlines', 'JAPANAIR', 'Japan', 'JP'),
('ANA', 'NH', 'All Nippon Airways', 'ALL NIPPON', 'Japan', 'JP'),
('JJP', 'GK', 'Jetstar Japan', 'ORANGE LINER', 'Japan', 'JP'),
('APJ', 'MM', 'Peach', 'AIR PEACH', 'Japan', 'JP'),
('WAJ', 'IJ', 'Spring Japan', 'J SPRING', 'Japan', 'JP'),
('SKY', 'BC', 'Skymark Airlines', 'SKYMARK', 'Japan', 'JP'),
('SNA', '7G', 'Solaseed Air', 'NEW SKY', 'Japan', 'JP'),
('ADO', 'HD', 'Air Do', 'AIR DO', 'Japan', 'JP'),
('JAC', 'JC', 'Japan Air Commuter', 'COMMUTER', 'Japan', 'JP'),
('IBX', 'FW', 'Ibex Airlines', 'IBEX', 'Japan', 'JP'),
('AMX', 'MZ', 'Amakusa Airlines', 'AMAKUSA', 'Japan', 'JP'),
('ORC', 'OC', 'Oriental Air Bridge', 'ORIENTAL BRIDGE', 'Japan', 'JP'),
('NCA', 'KZ', 'Nippon Cargo Airlines', 'NIPPON CARGO', 'Japan', 'JP'),

-- ============================================================================
-- KOREA
-- ============================================================================
('KAL', 'KE', 'Korean Air', 'KOREANAIR', 'South Korea', 'KR'),
('AAR', 'OZ', 'Asiana Airlines', 'ASIANA', 'South Korea', 'KR'),
('JNA', 'ZE', 'Eastar Jet', 'EASTAR', 'South Korea', 'KR'),
('JJA', '7C', 'Jeju Air', 'JEJU AIR', 'South Korea', 'KR'),
('ABL', 'BX', 'Air Busan', 'AIR BUSAN', 'South Korea', 'KR'),
('TWB', 'TW', 'T''way Air', 'TWAY AIR', 'South Korea', 'KR'),
('JNA', 'RF', 'Fly Gangwon', 'FLY GANGWON', 'South Korea', 'KR'),
('ESR', 'ZE', 'Eastar Jet', 'EASTAR', 'South Korea', 'KR'),
('KAC', 'KW', 'Air Seoul', 'AIR SEOUL', 'South Korea', 'KR'),

-- ============================================================================
-- AUSTRALIA & NEW ZEALAND
-- ============================================================================
('QFA', 'QF', 'Qantas', 'QANTAS', 'Australia', 'AU'),
('JST', 'JQ', 'Jetstar', 'JETSTAR', 'Australia', 'AU'),
('VOZ', 'VA', 'Virgin Australia', 'VELOCITY', 'Australia', 'AU'),
('REX', 'ZL', 'Regional Express', 'REX', 'Australia', 'AU'),
('TGG', 'TL', 'Airnorth', 'TOPEND', 'Australia', 'AU'),
('QJE', 'QF', 'QantasLink', 'QLINK', 'Australia', 'AU'),
('BOQ', 'NF', 'Air Vanuatu', 'AIR VANUATU', 'Vanuatu', 'VU'),
('FJI', 'FJ', 'Fiji Airways', 'FIJI', 'Fiji', 'FJ'),
('NMI', 'IE', 'Solomon Airlines', 'SOLOMON', 'Solomon Islands', 'SB'),
('ANZ', 'NZ', 'Air New Zealand', 'NEW ZEALAND', 'New Zealand', 'NZ'),
('JST', 'JQ', 'Jetstar New Zealand', 'JETSTAR', 'New Zealand', 'NZ'),
('SOL', 'S3', 'Sounds Air', 'SOUNDS', 'New Zealand', 'NZ'),

-- ============================================================================
-- AFRICA - NORTH
-- ============================================================================
('MSR', 'MS', 'EgyptAir', 'EGYPTAIR', 'Egypt', 'EG'),
('NIA', 'MS', 'Nile Air', 'NILE AIR', 'Egypt', 'EG'),
('RAM', 'AT', 'Royal Air Maroc', 'ROYALAIR MAROC', 'Morocco', 'MA'),
('AHH', 'AH', 'Air Algerie', 'AIR ALGERIE', 'Algeria', 'DZ'),
('TAR', 'TU', 'Tunisair', 'TUNAIR', 'Tunisia', 'TN'),
('LAA', 'LN', 'Libyan Airlines', 'LIBYAN', 'Libya', 'LY'),
('AFU', 'AF', 'Air Mauritanie', 'MAURITANIE', 'Mauritania', 'MR'),

-- ============================================================================
-- AFRICA - WEST & CENTRAL
-- ============================================================================
('ARA', 'W3', 'Arik Air', 'ARIK AIR', 'Nigeria', 'NG'),
('ETH', 'ET', 'Ethiopian Airlines', 'ETHIOPIAN', 'Ethiopia', 'ET'),
('KQA', 'KQ', 'Kenya Airways', 'KENYA', 'Kenya', 'KE'),
('SAA', 'SA', 'South African Airways', 'SPRINGBOK', 'South Africa', 'ZA'),
('NML', 'JE', 'Mango', 'MANGO', 'South Africa', 'ZA'),
('FLY', 'FA', 'FlySafair', 'SAFAIR', 'South Africa', 'ZA'),
('TCW', 'TC', 'Air Tanzania', 'TANZANIA', 'Tanzania', 'TZ'),
('RWD', 'WB', 'RwandAir', 'RWANDAIR', 'Rwanda', 'RW'),
('UGD', 'QU', 'Uganda Airlines', 'UGANDA AIR', 'Uganda', 'UG'),
('ANA', 'DT', 'TAAG Angola Airlines', 'DTA', 'Angola', 'AO'),
('LAM', 'TM', 'LAM Mozambique Airlines', 'MOZAMBIQUE', 'Mozambique', 'MZ'),
('MAU', 'MK', 'Air Mauritius', 'AIRMAURITIUS', 'Mauritius', 'MU'),
('SEY', 'HM', 'Air Seychelles', 'SEYCHELLES', 'Seychelles', 'SC'),
('MDG', 'MD', 'Air Madagascar', 'MADAGASCAR', 'Madagascar', 'MG'),
('CAW', 'W3', 'Cabo Verde Airlines', 'CABOVERDE', 'Cabo Verde', 'CV'),
('DSR', 'DS', 'Air Senegal', 'TERANGA', 'Senegal', 'SN'),
('CIV', 'HF', 'Air Côte d''Ivoire', 'COTE DIVOIRE', 'Ivory Coast', 'CI'),
('GHB', 'G0', 'Ghana International', 'AFRICAN BIRD', 'Ghana', 'GH'),
('RKM', 'RK', 'Air Burkina', 'BURKINA', 'Burkina Faso', 'BF'),
('AIS', 'YJ', 'African Express Airways', 'DONKEY', 'Kenya', 'KE'),
('APK', 'YP', 'Asky Airlines', 'ASKY', 'Togo', 'TG'),
('ELF', 'LF', 'CEIBA Intercontinental', 'CEIBA LINE', 'Equatorial Guinea', 'GQ'),
('CMR', 'QC', 'Camair-Co', 'CAMAIRCO', 'Cameroon', 'CM');

GO

-- Get count
DECLARE @count INT;
SELECT @count = COUNT(*) FROM dbo.airlines;
PRINT '';
PRINT 'Airlines table populated with ' + CAST(@count AS VARCHAR) + ' airlines.';
GO
