/**
 * VATSWIM API Types
 */

// ============================================================================
// Enums
// ============================================================================

export type FlightPhase = 
  | 'PREFLIGHT' 
  | 'DEPARTING' 
  | 'CLIMBING' 
  | 'ENROUTE' 
  | 'DESCENDING' 
  | 'APPROACH' 
  | 'LANDED' 
  | 'ARRIVED';

export type FlightStatus = 'active' | 'completed' | 'all';

export type TmiType = 'GS' | 'GDP' | 'MIT' | 'MINIT' | 'AFP';

/** Sector strata classification (based on sector type, not flight altitude) */
export type SectorStrata = 'low' | 'high' | 'superhigh';

/** @deprecated Use SectorStrata instead - strata is based on sector classification, not altitude */
export type AltitudeStrata = SectorStrata;

// ============================================================================
// Flight Models
// ============================================================================

export interface FlightIdentity {
  callsign: string;
  cid?: number;
  aircraft_type?: string;
  aircraft_icao?: string;
  weight_class?: string;
  wake_category?: string;
  airline_icao?: string;
  airline_name?: string;
}

export interface FlightPlan {
  departure: string;
  destination: string;
  alternate?: string;
  cruise_altitude?: number;
  cruise_speed?: number;
  route?: string;
  flight_rules?: string;
  departure_artcc?: string;
  destination_artcc?: string;
  arrival_fix?: string;
  arrival_procedure?: string;
}

export interface FlightPosition {
  latitude: number;
  longitude: number;
  altitude_ft: number;
  heading: number;
  ground_speed_kts: number;
  vertical_rate_fpm: number;
  current_artcc?: string;
}

export interface FlightProgress {
  phase: FlightPhase | string;
  is_active: boolean;
  distance_remaining_nm?: number;
  pct_complete?: number;
  time_to_dest_min?: number;
}

export interface FlightTimes {
  eta?: string;
  eta_runway?: string;
  out?: string;
  off?: string;
  on?: string;
  in?: string;
}

export interface FlightTmi {
  is_controlled: boolean;
  ground_stop_held: boolean;
  control_type?: string;
  edct?: string;
  delay_minutes?: number;
}

export interface Flight {
  gufi: string;
  flight_uid: number;
  flight_key: string;
  identity: FlightIdentity;
  flight_plan: FlightPlan;
  position?: FlightPosition;
  progress?: FlightProgress;
  times?: FlightTimes;
  tmi?: FlightTmi;
}

// ============================================================================
// TMI Models
// ============================================================================

export interface GroundStop {
  type: 'ground_stop';
  airport: string;
  airport_name?: string;
  artcc?: string;
  reason?: string;
  probability_of_extension?: number;
  start_time?: string;
  end_time?: string;
  is_active: boolean;
}

export interface GdpProgram {
  type: 'gdp';
  program_id: string;
  airport: string;
  airport_name?: string;
  artcc?: string;
  reason?: string;
  program_rate?: number;
  delay_limit_minutes?: number;
  average_delay_minutes?: number;
  maximum_delay_minutes?: number;
  total_flights?: number;
  affected_flights?: number;
  is_active: boolean;
}

export interface TmiPrograms {
  ground_stops: GroundStop[];
  gdp_programs: GdpProgram[];
  active_ground_stops: number;
  active_gdp_programs: number;
  total_controlled_airports: number;
}

// ============================================================================
// GeoJSON Models
// ============================================================================

export interface GeoJsonFeature {
  type: 'Feature';
  id: number;
  geometry: {
    type: 'Point';
    coordinates: [number, number, number]; // [lon, lat, alt]
  };
  properties: {
    flight_uid: number;
    callsign: string;
    aircraft?: string;
    departure?: string;
    destination?: string;
    phase?: string;
    altitude?: number;
    heading?: number;
    groundspeed?: number;
    distance_remaining_nm?: number;
    tmi_status?: string;
  };
}

export interface PositionsResponse {
  type: 'FeatureCollection';
  features: GeoJsonFeature[];
  metadata: {
    count: number;
    timestamp: string;
    source?: string;
  };
}

// ============================================================================
// Response Models
// ============================================================================

export interface Pagination {
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
  has_more: boolean;
}

export interface FlightsResponse {
  success: boolean;
  data: Flight[];
  pagination?: Pagination;
  timestamp?: string;
}

export interface FlightResponse {
  success: boolean;
  data: Flight;
  timestamp?: string;
}

export interface TmiProgramsResponse {
  success: boolean;
  data: TmiPrograms;
  timestamp?: string;
}

export interface IngestResult {
  processed: number;
  created: number;
  updated: number;
  errors: number;
  error_details: string[];
}

export interface ApiError {
  code?: string;
  message: string;
}

export interface ApiErrorResponse {
  success: false;
  error: ApiError;
}

// ============================================================================
// Ingest Models
// ============================================================================

export interface FlightIngest {
  callsign: string;
  dept_icao: string;
  dest_icao: string;
  cid?: number;
  aircraft_type?: string;
  route?: string;
  phase?: string;
  is_active?: boolean;
  latitude?: number;
  longitude?: number;
  altitude_ft?: number;
  heading_deg?: number;
  groundspeed_kts?: number;
  vertical_rate_fpm?: number;
  out_utc?: string;
  off_utc?: string;
  on_utc?: string;
  in_utc?: string;
  eta_utc?: string;
  etd_utc?: string;
}

export interface TrackIngest {
  callsign: string;
  latitude: number;
  longitude: number;
  altitude_ft?: number;
  ground_speed_kts?: number;
  heading_deg?: number;
  vertical_rate_fpm?: number;
  squawk?: string;
  track_source?: string;
  timestamp?: string;
}

// ============================================================================
// WebSocket Event Types
// ============================================================================

export type EventType =
  | 'connected'
  | 'subscribed'
  | 'unsubscribed'
  | 'disconnected'
  | 'error'
  | 'pong'
  | 'status'
  | 'system.heartbeat'
  | 'flight.created'
  | 'flight.updated'
  | 'flight.departed'
  | 'flight.arrived'
  | 'flight.deleted'
  | 'flight.position'
  | 'flight.positions'
  | 'tmi.issued'
  | 'tmi.modified'
  | 'tmi.released'
  | 'flight.*'
  | 'tmi.*'
  | 'system.*';

export interface ConnectionInfo {
  client_id: string;
  server_time: string;
  version: string;
}

export interface FlightEventData {
  callsign: string;
  flight_uid: number;
  dep?: string;
  arr?: string;
  equipment?: string;
  route?: string;
  off_utc?: string;
  in_utc?: string;
  latitude?: number;
  longitude?: number;
  altitude_ft?: number;
  groundspeed_kts?: number;
  heading_deg?: number;
}

export interface PositionData {
  callsign: string;
  flight_uid: number;
  latitude: number;
  longitude: number;
  altitude_ft: number;
  groundspeed_kts: number;
  heading_deg: number;
  vertical_rate_fpm?: number;
  current_artcc?: string;
  dep?: string;
  arr?: string;
}

export interface PositionsBatch {
  count: number;
  positions: PositionData[];
}

export interface TmiEventData {
  program_id: string;
  program_type: string;
  airport: string;
  start_time?: string;
  end_time?: string;
  reason?: string;
  status?: string;
}

export interface HeartbeatData {
  connected_clients: number;
  uptime_seconds: number;
}

export interface SubscriptionFilters {
  airports?: string[];
  artccs?: string[];
  callsign_prefix?: string[];
  bbox?: {
    north: number;
    south: number;
    east: number;
    west: number;
  };
}

export interface SwimMessage<T = unknown> {
  type: string;
  timestamp?: string;
  data?: T;
  code?: string;
  message?: string;
}

// ============================================================================
// Client Options
// ============================================================================

export interface SwimRestClientOptions {
  baseUrl?: string;
  timeout?: number;
}

export interface SwimWebSocketClientOptions {
  wsUrl?: string;
  reconnect?: boolean;
  reconnectInterval?: number;
  maxReconnectInterval?: number;
  pingInterval?: number;
}

export interface GetFlightsOptions {
  status?: FlightStatus;
  dept_icao?: string | string[];
  dest_icao?: string | string[];
  /** Filter by departure ARTCC (flight plan) */
  dep_artcc?: string | string[];
  /** Filter by destination ARTCC (flight plan) */
  dest_artcc?: string | string[];
  /** Filter by departure TRACON (flight plan) */
  dep_tracon?: string | string[];
  /** Filter by destination TRACON (flight plan) */
  dest_tracon?: string | string[];
  /** @deprecated Use dest_artcc instead */
  artcc?: string | string[];
  /** Filter by current ARTCC (where flight is now, not destination) */
  current_artcc?: string | string[];
  /** Filter by current TRACON */
  current_tracon?: string | string[];
  /** Filter by current sector */
  current_sector?: string | string[];
  /** Filter by sector strata classification (low, high, superhigh sectors) */
  strata?: SectorStrata;
  callsign?: string;
  tmi_controlled?: boolean;
  phase?: FlightPhase | string | string[];
  format?: 'legacy' | 'fixm';
  page?: number;
  per_page?: number;
}

export interface GetPositionsOptions {
  dept_icao?: string | string[];
  dest_icao?: string | string[];
  /** Filter by departure ARTCC (flight plan) */
  dep_artcc?: string | string[];
  /** Filter by destination ARTCC (flight plan) */
  dest_artcc?: string | string[];
  /** Filter by departure TRACON (flight plan) */
  dep_tracon?: string | string[];
  /** Filter by destination TRACON (flight plan) */
  dest_tracon?: string | string[];
  /** @deprecated Use dest_artcc instead */
  artcc?: string | string[];
  /** Filter by current ARTCC (where flight is now, not destination) */
  current_artcc?: string | string[];
  /** Filter by current TRACON */
  current_tracon?: string | string[];
  /** Filter by current sector */
  current_sector?: string | string[];
  /** Filter by sector strata classification (low, high, superhigh sectors) */
  strata?: SectorStrata;
  bounds?: string;
  tmi_controlled?: boolean;
  phase?: FlightPhase | string | string[];
  include_route?: boolean;
}

export interface GetTmiProgramsOptions {
  type?: 'all' | 'gs' | 'gdp';
  airport?: string;
  artcc?: string;
  include_history?: boolean;
}
