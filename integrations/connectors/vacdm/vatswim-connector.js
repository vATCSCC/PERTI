/**
 * VATSWIM Connector for vACDM
 *
 * Pushes A-CDM milestone data (TOBT/TSAT/TTOT/ASAT/EXOT) and
 * pilot readiness state to the VATSWIM CDM ingest endpoint.
 *
 * @example
 *   const { VATSWIMConnector } = require('./vatswim-connector');
 *   const connector = new VATSWIMConnector('swim_sys_your_key_here');
 *   await connector.sendCDMUpdates([{
 *     callsign: 'BAW123',
 *     airport: 'EGLL',
 *     tobt: '2026-03-06T14:30:00Z',
 *     tsat: '2026-03-06T14:35:00Z',
 *     readiness_state: 'READY'
 *   }]);
 */

const https = require('https');
const http = require('http');

const MAX_BATCH_SIZE = 500;
const DEFAULT_BASE_URL = 'https://perti.vatcscc.org';
const MAX_RETRIES = 3;
const RETRY_BACKOFF = [1000, 3000, 10000]; // ms

class VATSWIMConnector {
    /**
     * @param {string} apiKey - VATSWIM API key (swim_sys_ or swim_par_ prefix)
     * @param {string} [baseUrl] - Base URL (default: https://perti.vatcscc.org)
     */
    constructor(apiKey, baseUrl = DEFAULT_BASE_URL) {
        if (!apiKey) throw new Error('API key is required');
        this.apiKey = apiKey;
        this.baseUrl = baseUrl.replace(/\/$/, '');
    }

    /**
     * Send A-CDM milestone updates to VATSWIM.
     *
     * @param {Array<CDMUpdate>} updates - CDM milestone updates (max 500)
     * @returns {Promise<IngestResult>}
     *
     * @typedef {Object} CDMUpdate
     * @property {string} callsign - Aircraft callsign (required)
     * @property {string} [gufi] - VATSWIM GUFI for direct lookup
     * @property {string} [airport] - Departure airport ICAO
     * @property {string} [tobt] - Target Off-Block Time (ISO 8601)
     * @property {string} [tsat] - Target Startup Approval Time
     * @property {string} [ttot] - Target Takeoff Time
     * @property {string} [asat] - Actual Startup Approval Time
     * @property {number} [exot] - Expected Taxi Out Time (minutes)
     * @property {string} [readiness_state] - PLANNING|BOARDING|READY|TAXIING
     * @property {string} [source] - Source identifier (default: VACDM)
     */
    async sendCDMUpdates(updates) {
        if (updates.length > MAX_BATCH_SIZE) {
            throw new Error(`Batch exceeds max ${MAX_BATCH_SIZE} updates`);
        }

        return this._post('/api/swim/v1/ingest/cdm.php', { updates });
    }

    /**
     * Check VATSWIM connector health status.
     * @returns {Promise<Object>}
     */
    async checkHealth() {
        return this._get('/api/swim/v1/connectors/health.php');
    }

    /**
     * @private
     */
    async _post(path, payload, attempt = 0) {
        const url = new URL(path, this.baseUrl);
        const data = JSON.stringify(payload);
        const mod = url.protocol === 'https:' ? https : http;

        return new Promise((resolve, reject) => {
            const req = mod.request(url, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${this.apiKey}`,
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Content-Length': Buffer.byteLength(data),
                },
                timeout: 30000,
            }, (res) => {
                let body = '';
                res.on('data', chunk => body += chunk);
                res.on('end', () => {
                    const status = res.statusCode;
                    if (status >= 200 && status < 300) {
                        try { resolve(JSON.parse(body)); }
                        catch { resolve({ success: true, body }); }
                        return;
                    }

                    // Retry on 429 or 5xx
                    if ((status === 429 || status >= 500) && attempt < MAX_RETRIES - 1) {
                        const wait = RETRY_BACKOFF[Math.min(attempt, RETRY_BACKOFF.length - 1)];
                        setTimeout(() => {
                            this._post(path, payload, attempt + 1)
                                .then(resolve).catch(reject);
                        }, wait);
                        return;
                    }

                    resolve({ success: false, status, error: body });
                });
            });

            req.on('error', (err) => {
                if (attempt < MAX_RETRIES - 1) {
                    const wait = RETRY_BACKOFF[attempt];
                    setTimeout(() => {
                        this._post(path, payload, attempt + 1)
                            .then(resolve).catch(reject);
                    }, wait);
                } else {
                    reject(err);
                }
            });

            req.write(data);
            req.end();
        });
    }

    /**
     * @private
     */
    async _get(path) {
        const url = new URL(path, this.baseUrl);
        const mod = url.protocol === 'https:' ? https : http;

        return new Promise((resolve, reject) => {
            const req = mod.request(url, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${this.apiKey}`,
                    'Accept': 'application/json',
                },
                timeout: 15000,
            }, (res) => {
                let body = '';
                res.on('data', chunk => body += chunk);
                res.on('end', () => {
                    try { resolve(JSON.parse(body)); }
                    catch { resolve({ body }); }
                });
            });
            req.on('error', reject);
            req.end();
        });
    }
}

module.exports = { VATSWIMConnector };
