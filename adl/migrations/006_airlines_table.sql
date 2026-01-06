-- ============================================================================
-- Airlines Reference Data - Comprehensive List (Fixed duplicates)
-- 
-- Sources: OpenFlights, ICAO, IATA databases
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
('MXY', 'MX', 'Breeze Airways', 'BREEZE', 'United States', 'US'),
('VXP', 'XP', 'Avelo Airlines', 'AVELO', 'United States', 'US'),

-- UNITED STATES - Cargo
('FDX', 'FX', 'FedEx Express', 'FEDEX', 'United States', 'US'),
('UPS', '5X', 'UPS Airlines', 'UPS', 'United States', 'US'),
('ABX', 'GB', 'ABX Air', 'ABEX', 'United States', 'US'),
('ATN', '8C', 'Air Transport International', 'AIR TRANSPORT', 'United States', 'US'),
('GTI', 'GT', 'Atlas Air', 'GIANT', 'United States', 'US'),
('PAC', 'PO', 'Polar Air Cargo', 'POLAR', 'United States', 'US'),
('CKS', 'K4', 'Kalitta Air', 'CONNIE', 'United States', 'US'),
('WGN', 'WI', 'Western Global Airlines', 'WESTERN GLOBAL', 'United States', 'US'),
('SOO', 'S8', 'Southern Air', 'SOUTHERN AIR', 'United States', 'US'),

-- UNITED STATES - Regional Airlines
('SKW', 'OO', 'SkyWest Airlines', 'SKYWEST', 'United States', 'US'),
('ENY', 'MQ', 'Envoy Air', 'ENVOY', 'United States', 'US'),
('RPA', 'YX', 'Republic Airways', 'BRICKYARD', 'United States', 'US'),
('PDT', 'PT', 'Piedmont Airlines', 'PIEDMONT', 'United States', 'US'),
('JIA', 'OH', 'PSA Airlines', 'BLUE STREAK', 'United States', 'US'),
('QXE', 'QX', 'Horizon Air', 'HORIZON AIR', 'United States', 'US'),
('CPZ', 'C5', 'CommutAir', 'COMMUTAIR', 'United States', 'US'),
('GJS', 'G7', 'GoJet Airlines', 'LINDBERGH', 'United States', 'US'),
('EDV', '9E', 'Endeavor Air', 'ENDEAVOR', 'United States', 'US'),
('ASH', 'YV', 'Mesa Airlines', 'AIR SHUTTLE', 'United States', 'US'),
('CKY', 'CQ', 'Cape Air', 'CAIR', 'United States', 'US'),

-- UNITED STATES - Charter/Other
('OAE', 'O5', 'Omni Air International', 'OMNI EXPRESS', 'United States', 'US'),
('NJA', 'QS', 'NetJets', 'NETJET', 'United States', 'US'),
('EJA', 'EJ', 'Executive Jet Management', 'JET SPEED', 'United States', 'US'),
('LXJ', 'LJ', 'Flexjet', 'FLEXJET', 'United States', 'US'),

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

-- ============================================================================
-- MEXICO & CENTRAL AMERICA
-- ============================================================================
('AMX', 'AM', 'Aeromexico', 'AEROMEXICO', 'Mexico', 'MX'),
('VOI', 'Y4', 'Volaris', 'VOLARIS', 'Mexico', 'MX'),
('VIV', 'VB', 'VivaAerobus', 'AEROENLACES', 'Mexico', 'MX'),
('CMP', 'CM', 'Copa Airlines', 'COPA', 'Panama', 'PA'),

-- ============================================================================
-- CARIBBEAN
-- ============================================================================
('BWA', 'BW', 'Caribbean Airlines', 'CARIBBEAN', 'Trinidad and Tobago', 'TT'),
('BHS', 'UP', 'Bahamasair', 'BAHAMAS', 'Bahamas', 'BS'),
('CAY', 'KX', 'Cayman Airways', 'CAYMAN', 'Cayman Islands', 'KY'),

-- ============================================================================
-- SOUTH AMERICA
-- ============================================================================
('AVA', 'AV', 'Avianca', 'AVIANCA', 'Colombia', 'CO'),
('LAN', 'LA', 'LATAM Airlines Chile', 'LAN', 'Chile', 'CL'),
('TAM', 'JJ', 'LATAM Airlines Brasil', 'TAM', 'Brazil', 'BR'),
('ARG', 'AR', 'Aerolíneas Argentinas', 'ARGENTINA', 'Argentina', 'AR'),
('AZU', 'AD', 'Azul Brazilian Airlines', 'AZUL', 'Brazil', 'BR'),
('GLO', 'G3', 'GOL Linhas Aéreas', 'GOL TRANSPORTE', 'Brazil', 'BR'),
('SKU', 'H2', 'Sky Airline', 'SKY CHILE', 'Chile', 'CL'),
('JAT', 'JA', 'JetSMART', 'SMART JET', 'Chile', 'CL'),

-- ============================================================================
-- UNITED KINGDOM & IRELAND
-- ============================================================================
('BAW', 'BA', 'British Airways', 'SPEEDBIRD', 'United Kingdom', 'GB'),
('VIR', 'VS', 'Virgin Atlantic', 'VIRGIN', 'United Kingdom', 'GB'),
('EZY', 'U2', 'easyJet', 'EASY', 'United Kingdom', 'GB'),
('TOM', 'BY', 'TUI Airways', 'THOMSON', 'United Kingdom', 'GB'),
('EXS', 'LS', 'Jet2', 'CHANNEX', 'United Kingdom', 'GB'),
('LOG', 'LM', 'Loganair', 'LOGAN', 'United Kingdom', 'GB'),
('CFE', 'ZB', 'BA Cityflyer', 'SHUTTLE', 'United Kingdom', 'GB'),
('EIN', 'EI', 'Aer Lingus', 'SHAMROCK', 'Ireland', 'IE'),
('RYR', 'FR', 'Ryanair', 'RYANAIR', 'Ireland', 'IE'),
('RUK', 'RK', 'Ryanair UK', 'RYANAIR UK', 'United Kingdom', 'GB'),

-- ============================================================================
-- GERMANY
-- ============================================================================
('DLH', 'LH', 'Lufthansa', 'LUFTHANSA', 'Germany', 'DE'),
('EWG', 'EW', 'Eurowings', 'EUROWINGS', 'Germany', 'DE'),
('CLH', 'CL', 'Lufthansa CityLine', 'HANSALINE', 'Germany', 'DE'),
('GEC', 'GF', 'Lufthansa Cargo', 'LUFTHANSA CARGO', 'Germany', 'DE'),
('TUI', 'X3', 'TUIfly', 'TUIFLY', 'Germany', 'DE'),
('CFG', 'DE', 'Condor', 'CONDOR', 'Germany', 'DE'),

-- ============================================================================
-- FRANCE
-- ============================================================================
('AFR', 'AF', 'Air France', 'AIRFRANS', 'France', 'FR'),
('HOP', 'A5', 'Air France Hop', 'HOP', 'France', 'FR'),
('TVF', 'TO', 'Transavia France', 'FRANCE SOLEIL', 'France', 'FR'),
('FBU', 'BF', 'French Bee', 'FRENCH BEE', 'France', 'FR'),
('CRL', 'SS', 'Corsair International', 'CORSAIR', 'France', 'FR'),

-- ============================================================================
-- NETHERLANDS, BELGIUM, LUXEMBOURG
-- ============================================================================
('KLM', 'KL', 'KLM Royal Dutch Airlines', 'KLM', 'Netherlands', 'NL'),
('TRA', 'HV', 'Transavia', 'TRANSAVIA', 'Netherlands', 'NL'),
('CLX', 'CV', 'Cargolux', 'CARGOLUX', 'Luxembourg', 'LU'),
('BEL', 'SN', 'Brussels Airlines', 'BEE LINE', 'Belgium', 'BE'),

-- ============================================================================
-- SPAIN & PORTUGAL
-- ============================================================================
('IBE', 'IB', 'Iberia', 'IBERIA', 'Spain', 'ES'),
('IBS', 'I2', 'Iberia Express', 'IBEREXPRESS', 'Spain', 'ES'),
('VLG', 'VY', 'Vueling', 'VUELING', 'Spain', 'ES'),
('AEA', 'UX', 'Air Europa', 'EUROPA', 'Spain', 'ES'),
('VEP', 'V7', 'Volotea', 'VOLOTEA', 'Spain', 'ES'),
('TAP', 'TP', 'TAP Air Portugal', 'TAP PORTUGAL', 'Portugal', 'PT'),

-- ============================================================================
-- ITALY
-- ============================================================================
('ITY', 'AZ', 'ITA Airways', 'ITARROW', 'Italy', 'IT'),
('NOS', 'NO', 'Neos', 'NEOS', 'Italy', 'IT'),

-- ============================================================================
-- SCANDINAVIA
-- ============================================================================
('SAS', 'SK', 'Scandinavian Airlines', 'SCANDINAVIAN', 'Sweden', 'SE'),
('NAX', 'DY', 'Norwegian Air Shuttle', 'NOR SHUTTLE', 'Norway', 'NO'),
('NOZ', 'D8', 'Norwegian Air Sweden', 'NORSEMAN', 'Sweden', 'SE'),
('FIN', 'AY', 'Finnair', 'FINNAIR', 'Finland', 'FI'),
('ICE', 'FI', 'Icelandair', 'ICEAIR', 'Iceland', 'IS'),
('WIF', 'WF', 'Widerøe', 'WIDEROE', 'Norway', 'NO'),

-- ============================================================================
-- SWITZERLAND & AUSTRIA
-- ============================================================================
('SWR', 'LX', 'Swiss International', 'SWISS', 'Switzerland', 'CH'),
('AUA', 'OS', 'Austrian Airlines', 'AUSTRIAN', 'Austria', 'AT'),
('EDW', 'WK', 'Edelweiss Air', 'EDELWEISS', 'Switzerland', 'CH'),

-- ============================================================================
-- EASTERN EUROPE
-- ============================================================================
('LOT', 'LO', 'LOT Polish Airlines', 'LOT', 'Poland', 'PL'),
('CSA', 'OK', 'Czech Airlines', 'CSA', 'Czech Republic', 'CZ'),
('TVS', 'QS', 'SmartWings', 'SMARTWINGS', 'Czech Republic', 'CZ'),
('ROT', 'RO', 'TAROM', 'TAROM', 'Romania', 'RO'),
('LZB', 'FB', 'Bulgaria Air', 'FLYING BULGARIA', 'Bulgaria', 'BG'),
('WZZ', 'W6', 'Wizz Air', 'WIZZAIR', 'Hungary', 'HU'),
('AHY', 'J2', 'Azerbaijan Airlines', 'AZAL', 'Azerbaijan', 'AZ'),
('UKR', 'PS', 'Ukraine International', 'UKRAINE INTERNATIONAL', 'Ukraine', 'UA'),
('AEE', 'A3', 'Aegean Airlines', 'AEGEAN', 'Greece', 'GR'),
('SRB', 'JU', 'Air Serbia', 'AIR SERBIA', 'Serbia', 'RS'),
('CTN', 'OU', 'Croatia Airlines', 'CROATIA', 'Croatia', 'HR'),
('BTI', 'BT', 'airBaltic', 'AIRBALTIC', 'Latvia', 'LV'),

-- ============================================================================
-- RUSSIA & CIS
-- ============================================================================
('AFL', 'SU', 'Aeroflot', 'AEROFLOT', 'Russia', 'RU'),
('SBI', 'S7', 'S7 Airlines', 'SIBERIAN AIRLINES', 'Russia', 'RU'),
('SVR', 'U6', 'Ural Airlines', 'SVERDLOVSK AIR', 'Russia', 'RU'),
('UTA', 'UT', 'UTair', 'UTAIR', 'Russia', 'RU'),
('PBD', 'DP', 'Pobeda', 'POBEDA', 'Russia', 'RU'),
('KZR', 'KC', 'Air Astana', 'ASTANALINE', 'Kazakhstan', 'KZ'),
('UZB', 'HY', 'Uzbekistan Airways', 'UZBEK', 'Uzbekistan', 'UZ'),
('BRU', 'U8', 'Belavia', 'BELAVIA', 'Belarus', 'BY'),

-- ============================================================================
-- TURKEY & MIDDLE EAST
-- ============================================================================
('THY', 'TK', 'Turkish Airlines', 'TURKISH', 'Turkey', 'TR'),
('PGT', 'PC', 'Pegasus Airlines', 'SUNTURK', 'Turkey', 'TR'),
('SXS', 'XQ', 'SunExpress', 'SUNEXPRESS', 'Turkey', 'TR'),
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
('MEA', 'ME', 'Middle East Airlines', 'CEDAR JET', 'Lebanon', 'LB'),
('KAC', 'KU', 'Kuwait Airways', 'KUWAITI', 'Kuwait', 'KW'),
('IRA', 'IR', 'Iran Air', 'IRANAIR', 'Iran', 'IR'),
('IAW', 'IA', 'Iraqi Airways', 'IRAQI', 'Iraq', 'IQ'),

-- ============================================================================
-- INDIA & SOUTH ASIA
-- ============================================================================
('AIC', 'AI', 'Air India', 'AIRINDIA', 'India', 'IN'),
('IGO', '6E', 'IndiGo', 'IFLY', 'India', 'IN'),
('AXB', 'IX', 'Air India Express', 'EXPRESS INDIA', 'India', 'IN'),
('SEJ', 'SG', 'SpiceJet', 'SPICEJET', 'India', 'IN'),
('GOW', 'G8', 'Go First', 'GO AIR', 'India', 'IN'),
('VTI', 'UK', 'Vistara', 'VISTARA', 'India', 'IN'),
('AKJ', 'QP', 'Akasa Air', 'AKASA AIR', 'India', 'IN'),
('PIA', 'PK', 'Pakistan International', 'PAKISTAN', 'Pakistan', 'PK'),
('ALK', 'UL', 'SriLankan Airlines', 'SRILANKAN', 'Sri Lanka', 'LK'),
('DRK', 'KB', 'Drukair', 'ROYAL BHUTAN', 'Bhutan', 'BT'),
('RNA', 'RA', 'Nepal Airlines', 'ROYAL NEPAL', 'Nepal', 'NP'),
('BBC', 'BG', 'Biman Bangladesh', 'BANGLADESH', 'Bangladesh', 'BD'),

-- ============================================================================
-- SOUTHEAST ASIA
-- ============================================================================
('SIA', 'SQ', 'Singapore Airlines', 'SINGAPORE', 'Singapore', 'SG'),
('TGW', 'TR', 'Scoot', 'SCOOTER', 'Singapore', 'SG'),
('JSA', '3K', 'Jetstar Asia', 'JETSTAR ASIA', 'Singapore', 'SG'),
('THA', 'TG', 'Thai Airways', 'THAI', 'Thailand', 'TH'),
('WTH', 'WE', 'Thai Smile', 'THAI SMILE', 'Thailand', 'TH'),
('AIQ', 'FD', 'Thai AirAsia', 'THAI AIRASIA', 'Thailand', 'TH'),
('BKP', 'PG', 'Bangkok Airways', 'BANGKOK AIR', 'Thailand', 'TH'),
('NOK', 'DD', 'Nok Air', 'NOK AIR', 'Thailand', 'TH'),
('MAS', 'MH', 'Malaysia Airlines', 'MALAYSIAN', 'Malaysia', 'MY'),
('AXM', 'AK', 'AirAsia', 'RED CAP', 'Malaysia', 'MY'),
('XAX', 'D7', 'AirAsia X', 'XANADU', 'Malaysia', 'MY'),
('ODA', 'OD', 'Batik Air Malaysia', 'MALINDO', 'Malaysia', 'MY'),
('GIA', 'GA', 'Garuda Indonesia', 'INDONESIA', 'Indonesia', 'ID'),
('LNI', 'JT', 'Lion Air', 'LION INTER', 'Indonesia', 'ID'),
('BTK', 'ID', 'Batik Air', 'BATIK', 'Indonesia', 'ID'),
('CTV', 'QG', 'Citilink', 'SUPERGREEN', 'Indonesia', 'ID'),
('PAL', 'PR', 'Philippine Airlines', 'PHILIPPINE', 'Philippines', 'PH'),
('CEB', '5J', 'Cebu Pacific', 'CEBU AIR', 'Philippines', 'PH'),
('VNA', 'VN', 'Vietnam Airlines', 'VIETNAM AIRLINES', 'Vietnam', 'VN'),
('HVN', 'VJ', 'VietJet Air', 'VIETJET', 'Vietnam', 'VN'),
('BAV', 'QH', 'Bamboo Airways', 'BAMBOO', 'Vietnam', 'VN'),
('LAO', 'QV', 'Lao Airlines', 'LAO', 'Laos', 'LA'),
('RBA', 'BI', 'Royal Brunei', 'BRUNEI', 'Brunei', 'BN'),

-- ============================================================================
-- CHINA
-- ============================================================================
('CCA', 'CA', 'Air China', 'AIR CHINA', 'China', 'CN'),
('CES', 'MU', 'China Eastern', 'CHINA EASTERN', 'China', 'CN'),
('CSN', 'CZ', 'China Southern', 'CHINA SOUTHERN', 'China', 'CN'),
('CHH', 'HU', 'Hainan Airlines', 'HAINAN', 'China', 'CN'),
('CSC', '3U', 'Sichuan Airlines', 'SICHUAN', 'China', 'CN'),
('CSH', 'FM', 'Shanghai Airlines', 'SHANGHAI AIR', 'China', 'CN'),
('CXA', 'MF', 'Xiamen Airlines', 'XIAMEN AIR', 'China', 'CN'),
('CDG', 'SC', 'Shandong Airlines', 'SHANDONG', 'China', 'CN'),
('CSZ', 'ZH', 'Shenzhen Airlines', 'SHENZHEN AIR', 'China', 'CN'),
('DKH', 'HO', 'Juneyao Airlines', 'AIR JUNEYAO', 'China', 'CN'),
('CQH', 'PN', 'West Air', 'CHINA WEST AIR', 'China', 'CN'),
('CXN', '9C', 'Spring Airlines', 'AIR SPRING', 'China', 'CN'),
('LKE', '8L', 'Lucky Air', 'LUCKY AIR', 'China', 'CN'),

-- ============================================================================
-- HONG KONG, MACAU, TAIWAN
-- ============================================================================
('CPA', 'CX', 'Cathay Pacific', 'CATHAY', 'Hong Kong', 'HK'),
('HKE', 'UO', 'Hong Kong Express', 'HONGKONG SHUTTLE', 'Hong Kong', 'HK'),
('HKC', 'HX', 'Hong Kong Airlines', 'BAUHINIA', 'Hong Kong', 'HK'),
('AMU', 'NX', 'Air Macau', 'AIR MACAU', 'Macau', 'MO'),
('CAL', 'CI', 'China Airlines', 'DYNASTY', 'Taiwan', 'TW'),
('EVA', 'BR', 'EVA Air', 'EVA', 'Taiwan', 'TW'),
('TTW', 'IT', 'Tigerair Taiwan', 'SMART CAT', 'Taiwan', 'TW'),
('MDA', 'AE', 'Mandarin Airlines', 'MANDARIN', 'Taiwan', 'TW'),

-- ============================================================================
-- JAPAN
-- ============================================================================
('JAL', 'JL', 'Japan Airlines', 'JAPANAIR', 'Japan', 'JP'),
('ANA', 'NH', 'All Nippon Airways', 'ALL NIPPON', 'Japan', 'JP'),
('JJP', 'GK', 'Jetstar Japan', 'ORANGE LINER', 'Japan', 'JP'),
('APJ', 'MM', 'Peach', 'AIR PEACH', 'Japan', 'JP'),
('SJO', 'BC', 'Skymark Airlines', 'SKYMARK', 'Japan', 'JP'),
('ADO', 'HD', 'Air Do', 'AIR DO', 'Japan', 'JP'),
('SFJ', '7G', 'StarFlyer', 'STARFLYER', 'Japan', 'JP'),
('NCA', 'KZ', 'Nippon Cargo Airlines', 'NIPPON CARGO', 'Japan', 'JP'),

-- ============================================================================
-- KOREA
-- ============================================================================
('KAL', 'KE', 'Korean Air', 'KOREANAIR', 'South Korea', 'KR'),
('AAR', 'OZ', 'Asiana Airlines', 'ASIANA', 'South Korea', 'KR'),
('JJA', '7C', 'Jeju Air', 'JEJU AIR', 'South Korea', 'KR'),
('ABL', 'BX', 'Air Busan', 'AIR BUSAN', 'South Korea', 'KR'),
('TWB', 'TW', 'T''way Air', 'TWAY AIR', 'South Korea', 'KR'),
('JNA', 'ZE', 'Eastar Jet', 'EASTAR', 'South Korea', 'KR'),
('ASV', 'RS', 'Air Seoul', 'AIR SEOUL', 'South Korea', 'KR'),

-- ============================================================================
-- AUSTRALIA & NEW ZEALAND
-- ============================================================================
('QFA', 'QF', 'Qantas', 'QANTAS', 'Australia', 'AU'),
('JST', 'JQ', 'Jetstar', 'JETSTAR', 'Australia', 'AU'),
('VOZ', 'VA', 'Virgin Australia', 'VELOCITY', 'Australia', 'AU'),
('RXA', 'ZL', 'Regional Express', 'REX', 'Australia', 'AU'),
('ANZ', 'NZ', 'Air New Zealand', 'NEW ZEALAND', 'New Zealand', 'NZ'),
('FJI', 'FJ', 'Fiji Airways', 'FIJI', 'Fiji', 'FJ'),

-- ============================================================================
-- AFRICA
-- ============================================================================
('MSR', 'MS', 'EgyptAir', 'EGYPTAIR', 'Egypt', 'EG'),
('RAM', 'AT', 'Royal Air Maroc', 'ROYALAIR MAROC', 'Morocco', 'MA'),
('DAH', 'AH', 'Air Algerie', 'AIR ALGERIE', 'Algeria', 'DZ'),
('TAR', 'TU', 'Tunisair', 'TUNAIR', 'Tunisia', 'TN'),
('ETH', 'ET', 'Ethiopian Airlines', 'ETHIOPIAN', 'Ethiopia', 'ET'),
('KQA', 'KQ', 'Kenya Airways', 'KENYA', 'Kenya', 'KE'),
('SAA', 'SA', 'South African Airways', 'SPRINGBOK', 'South Africa', 'ZA'),
('SFR', 'FA', 'FlySafair', 'SAFAIR', 'South Africa', 'ZA'),
('RWD', 'WB', 'RwandAir', 'RWANDAIR', 'Rwanda', 'RW'),
('MAU', 'MK', 'Air Mauritius', 'AIRMAURITIUS', 'Mauritius', 'MU'),
('SEY', 'HM', 'Air Seychelles', 'SEYCHELLES', 'Seychelles', 'SC');

GO

-- Get count
DECLARE @count INT;
SELECT @count = COUNT(*) FROM dbo.airlines;
PRINT '';
PRINT 'Airlines table populated with ' + CAST(@count AS VARCHAR) + ' airlines.';
GO
