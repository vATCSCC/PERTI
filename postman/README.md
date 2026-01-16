# Postman Configuration

## OpenAPI as Source of Truth

We maintain OpenAPI specs only - Postman collections are generated on-demand by importing the specs.

### Import into Postman

| API | Import This File |
|-----|------------------|
| **PERTI API** | `api-docs/openapi.yaml` |
| **SWIM API** | `docs/swim/openapi.yaml` |

**Steps:**
1. Open Postman
2. Click **Import**
3. Select the OpenAPI YAML file
4. Postman generates the collection automatically

Re-import whenever the OpenAPI spec is updated.

## Directory Structure

```
postman/
├── environments/
│   └── SWIM_Development.postman_environment.json
├── globals/
│   └── workspace.postman_globals.json
└── README.md
```

## Environments

| Environment | Description |
|-------------|-------------|
| `SWIM_Development` | Development/testing variables for SWIM API |

## CLI Testing (Newman)

For automated testing, use Newman with the OpenAPI spec:

```bash
# Install newman and openapi-to-postman
npm install -g newman newman-reporter-html openapi-to-postmanv2

# Convert and run
openapi2postmanv2 -s docs/swim/openapi.yaml -o /tmp/swim.json
newman run /tmp/swim.json -e postman/environments/SWIM_Development.postman_environment.json
```
