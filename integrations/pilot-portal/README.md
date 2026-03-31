# VATSWIM Pilot Portal

Vue 3 web application providing pilots with a flight management dashboard for VATSWIM integration.

## Features

- **Flight Status** - View assigned EDCT/CTOT and CDM milestones
- **TOS Filing** - File Trajectory Option Set route preferences
- **TMI Advisories** - View active Traffic Management Initiatives (GDP, GS, AFP, reroutes)
- **AMAN Sequence** - Arrival sequence display (via WebSocket)
- **Real-time Updates** - SWIM WebSocket with exponential backoff reconnect
- **Internationalization** - English (en-US) and French Canadian (fr-CA)

## Prerequisites

- Node.js 18+
- SWIM API key (stored in localStorage as `SWIM_API_KEY`)

## Development

```bash
npm install
npm run dev
```

The dev server runs on port 5173 with API requests proxied to `https://perti.vatcscc.org`.

## Build

```bash
npm run build
```

Output goes to `dist/` for static deployment.

## Configuration

| Environment Variable | Default | Description |
|---------------------|---------|-------------|
| `VITE_SWIM_URL` | `/api/swim/v1` | SWIM API base URL |
| `VITE_WS_URL` | `wss://perti.vatcscc.org/ws/swim/v1` | SWIM WebSocket URL |

## Architecture

- **Vue 3** with Composition API
- **Pinia** for state management
- **vue-i18n** for internationalization (follows PERTI locale detection: URL param, localStorage `PERTI_LOCALE`, navigator.language)
- **Vite** for build tooling
- Connects to SWIM REST API and WebSocket for real-time CDM/TMI/AMAN events
