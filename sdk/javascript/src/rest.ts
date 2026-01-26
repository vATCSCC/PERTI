/**
 * SWIM REST API Client
 */

import type {
  Flight,
  FlightsResponse,
  FlightResponse,
  PositionsResponse,
  TmiPrograms,
  TmiProgramsResponse,
  IngestResult,
  FlightIngest,
  TrackIngest,
  SwimRestClientOptions,
  GetFlightsOptions,
  GetPositionsOptions,
  GetTmiProgramsOptions,
  Pagination,
  AltitudeStrata,
} from './types';

const DEFAULT_BASE_URL = 'https://perti.vatcscc.org/api/swim/v1';

/**
 * Error thrown for SWIM API errors
 */
export class SwimApiError extends Error {
  constructor(
    public statusCode: number,
    message: string,
    public errorCode?: string
  ) {
    super(`[${statusCode}] ${errorCode || 'ERROR'}: ${message}`);
    this.name = 'SwimApiError';
  }
}

/**
 * SWIM REST API Client
 * 
 * @example
 * ```typescript
 * const client = new SwimRestClient('your-api-key');
 * 
 * const flights = await client.getFlights({ dest_icao: 'KJFK' });
 * for (const flight of flights) {
 *   console.log(`${flight.identity.callsign}: ${flight.flight_plan.departure} -> ${flight.flight_plan.destination}`);
 * }
 * ```
 */
export class SwimRestClient {
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly timeout: number;

  constructor(apiKey: string, options: SwimRestClientOptions = {}) {
    if (!apiKey) {
      throw new Error('API key is required');
    }

    this.apiKey = apiKey;
    this.baseUrl = (options.baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, '');
    this.timeout = options.timeout || 30000;
  }

  // ===========================================================================
  // Flight Methods
  // ===========================================================================

  /**
   * Get list of flights with optional filtering
   */
  async getFlights(options: GetFlightsOptions = {}): Promise<Flight[]> {
    const response = await this.getFlightsPaginated(options);
    return response.data;
  }

  /**
   * Get flights with pagination info
   */
  async getFlightsPaginated(options: GetFlightsOptions = {}): Promise<{
    data: Flight[];
    pagination?: Pagination;
    timestamp?: string;
  }> {
    const params = this.buildParams({
      status: options.status || 'active',
      dept_icao: this.arrayToString(options.dept_icao),
      dest_icao: this.arrayToString(options.dest_icao),
      dep_artcc: this.arrayToString(options.dep_artcc),
      dest_artcc: this.arrayToString(options.dest_artcc ?? options.artcc),  // artcc is deprecated alias
      dep_tracon: this.arrayToString(options.dep_tracon),
      dest_tracon: this.arrayToString(options.dest_tracon),
      current_artcc: this.arrayToString(options.current_artcc),
      current_tracon: this.arrayToString(options.current_tracon),
      current_sector: this.arrayToString(options.current_sector),
      strata: options.strata,
      callsign: options.callsign,
      tmi_controlled: options.tmi_controlled,
      phase: this.arrayToString(options.phase),
      format: options.format || 'fixm',
      page: options.page || 1,
      per_page: options.per_page || 100,
    });

    const response = await this.request<FlightsResponse>(`/flights?${params}`);
    return {
      data: response.data,
      pagination: response.pagination,
      timestamp: response.timestamp,
    };
  }

  /**
   * Get all flights across all pages
   */
  async getAllFlights(options: Omit<GetFlightsOptions, 'page' | 'per_page'> = {}): Promise<Flight[]> {
    const allFlights: Flight[] = [];
    let page = 1;
    const perPage = 1000;

    while (true) {
      const response = await this.getFlightsPaginated({
        ...options,
        page,
        per_page: perPage,
      });

      allFlights.push(...response.data);

      if (!response.pagination?.has_more) {
        break;
      }
      page++;
    }

    return allFlights;
  }

  /**
   * Get single flight by GUFI
   */
  async getFlightByGufi(gufi: string, format: 'legacy' | 'fixm' = 'fixm'): Promise<Flight | null> {
    return this.getFlightBy('gufi', gufi, format);
  }

  /**
   * Get single flight by flight key
   */
  async getFlightByKey(flightKey: string, format: 'legacy' | 'fixm' = 'fixm'): Promise<Flight | null> {
    return this.getFlightBy('flight_key', flightKey, format);
  }

  private async getFlightBy(
    param: string,
    value: string,
    format: 'legacy' | 'fixm'
  ): Promise<Flight | null> {
    try {
      const response = await this.request<FlightResponse>(
        `/flight?${param}=${encodeURIComponent(value)}&format=${format}`
      );
      return response.data;
    } catch (error) {
      if (error instanceof SwimApiError && error.statusCode === 404) {
        return null;
      }
      throw error;
    }
  }

  /**
   * Get flights currently in a specific ARTCC
   */
  async getFlightsInArtcc(
    artcc: string | string[],
    options: Omit<GetFlightsOptions, 'current_artcc'> = {}
  ): Promise<Flight[]> {
    return this.getFlights({ ...options, current_artcc: artcc });
  }

  /**
   * Get flights in a specific sector with optional strata filter
   */
  async getFlightsInSector(
    sector: string | string[],
    strata?: AltitudeStrata,
    options: Omit<GetFlightsOptions, 'current_sector' | 'strata'> = {}
  ): Promise<Flight[]> {
    return this.getFlights({ ...options, current_sector: sector, strata });
  }

  // ===========================================================================
  // Position Methods
  // ===========================================================================

  /**
   * Get bulk flight positions as GeoJSON
   */
  async getPositions(options: GetPositionsOptions = {}): Promise<PositionsResponse> {
    const params = this.buildParams({
      dept_icao: this.arrayToString(options.dept_icao),
      dest_icao: this.arrayToString(options.dest_icao),
      dep_artcc: this.arrayToString(options.dep_artcc),
      dest_artcc: this.arrayToString(options.dest_artcc ?? options.artcc),  // artcc is deprecated alias
      dep_tracon: this.arrayToString(options.dep_tracon),
      dest_tracon: this.arrayToString(options.dest_tracon),
      current_artcc: this.arrayToString(options.current_artcc),
      current_tracon: this.arrayToString(options.current_tracon),
      current_sector: this.arrayToString(options.current_sector),
      strata: options.strata,
      bounds: options.bounds,
      tmi_controlled: options.tmi_controlled,
      phase: this.arrayToString(options.phase),
      include_route: options.include_route,
    });

    return this.request<PositionsResponse>(`/positions?${params}`);
  }

  /**
   * Get positions within bounding box
   */
  async getPositionsBbox(
    north: number,
    south: number,
    east: number,
    west: number,
    options: Omit<GetPositionsOptions, 'bounds'> = {}
  ): Promise<PositionsResponse> {
    const bounds = `${west},${south},${east},${north}`;
    return this.getPositions({ ...options, bounds });
  }

  // ===========================================================================
  // TMI Methods
  // ===========================================================================

  /**
   * Get active TMI programs
   */
  async getTmiPrograms(options: GetTmiProgramsOptions = {}): Promise<TmiPrograms> {
    const params = this.buildParams({
      type: options.type || 'all',
      airport: options.airport,
      artcc: options.artcc,
      include_history: options.include_history,
    });

    const response = await this.request<TmiProgramsResponse>(`/tmi/programs?${params}`);
    return response.data;
  }

  /**
   * Get flights under TMI control
   */
  async getTmiControlledFlights(
    airport?: string,
    controlType?: string
  ): Promise<Flight[]> {
    const params = this.buildParams({
      airport,
      control_type: controlType,
    });

    const response = await this.request<{ data: Flight[] }>(`/tmi/controlled?${params}`);
    return response.data;
  }

  // ===========================================================================
  // Ingest Methods
  // ===========================================================================

  /**
   * Ingest flight data (requires write access)
   */
  async ingestFlights(flights: FlightIngest[]): Promise<IngestResult> {
    const response = await this.request<{ data: IngestResult }>('/ingest/adl', {
      method: 'POST',
      body: JSON.stringify({ flights }),
    });
    return response.data;
  }

  /**
   * Ingest track/position data (requires write access)
   */
  async ingestTracks(tracks: TrackIngest[]): Promise<IngestResult> {
    const response = await this.request<{ data: IngestResult }>('/ingest/track', {
      method: 'POST',
      body: JSON.stringify({ tracks }),
    });
    return response.data;
  }

  // ===========================================================================
  // Internal Methods
  // ===========================================================================

  private async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const url = `${this.baseUrl}${endpoint}`;
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(url, {
        ...options,
        headers: {
          'Authorization': `Bearer ${this.apiKey}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          ...options.headers,
        },
        signal: controller.signal,
      });

      const data = await response.json();

      if (!response.ok) {
        throw new SwimApiError(
          response.status,
          data.error?.message || 'Unknown error',
          data.error?.code
        );
      }

      return data as T;
    } catch (error) {
      if (error instanceof SwimApiError) {
        throw error;
      }
      if (error instanceof Error && error.name === 'AbortError') {
        throw new SwimApiError(0, 'Request timeout');
      }
      throw new SwimApiError(0, String(error));
    } finally {
      clearTimeout(timeoutId);
    }
  }

  private buildParams(params: Record<string, unknown>): string {
    const searchParams = new URLSearchParams();
    
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      
      if (typeof value === 'boolean') {
        searchParams.append(key, value ? 'true' : 'false');
      } else {
        searchParams.append(key, String(value));
      }
    }

    return searchParams.toString();
  }

  private arrayToString(value: string | string[] | undefined): string | undefined {
    if (Array.isArray(value)) {
      return value.join(',');
    }
    return value;
  }
}
