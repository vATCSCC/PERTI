/**
 * VATSWIM C++ SDK - Basic Ingest Example
 *
 * Demonstrates how to send position updates to the SWIM API.
 */

#define SWIM_USE_CURL
#include <swim/swim.h>
#include <stdio.h>
#include <string.h>

int main(int argc, char* argv[]) {
    printf("VATSWIM C++ SDK - Basic Ingest Example\n");
    printf("======================================\n\n");

    /* Initialize client */
    SwimClientConfig config = {0};

    /* Set your API key here or via environment */
    const char* api_key = getenv("SWIM_API_KEY");
    if (api_key) {
        strncpy(config.api_key, api_key, sizeof(config.api_key) - 1);
    } else {
        strcpy(config.api_key, "swim_dev_test_001");  /* Default test key */
    }

    strcpy(config.source_id, "simulator");
    strcpy(config.base_url, "https://perti.vatcscc.org/api/swim/v1");
    config.timeout_ms = 10000;
    config.verify_ssl = true;

    SwimClient client;
    if (!swim_client_init(&client, &config)) {
        fprintf(stderr, "Error: Failed to initialize SWIM client\n");
        return 1;
    }

    printf("Client initialized successfully\n");
    printf("API Key: %s...%s\n",
           config.api_key[0] ? "****" : "(none)",
           config.api_key[0] ? config.api_key + strlen(config.api_key) - 4 : "");
    printf("Base URL: %s\n\n", config.base_url);

    /* Create a sample track update */
    SwimTrackUpdate track = {0};
    strcpy(track.callsign, "TEST123");
    track.position.latitude = 40.6413;
    track.position.longitude = -73.7781;
    track.position.altitude_ft = 35000;
    track.position.heading_deg = 270;
    track.position.groundspeed_kts = 450;
    track.position.vertical_rate = -500;
    track.position.on_ground = false;
    track.timestamp = time(NULL);
    strcpy(track.squawk, "1200");

    printf("Sending track update:\n");
    printf("  Callsign: %s\n", track.callsign);
    printf("  Position: %.4f, %.4f\n", track.position.latitude, track.position.longitude);
    printf("  Altitude: %d ft\n", track.position.altitude_ft);
    printf("  Heading: %d deg\n", track.position.heading_deg);
    printf("  Groundspeed: %d kts\n", track.position.groundspeed_kts);
    printf("  Vertical Rate: %d fpm\n\n", track.position.vertical_rate);

    /* Send track update */
    SwimIngestResult result;
    SwimStatus status = swim_client_ingest_track(&client, &track, 1, &result);

    printf("Result:\n");
    printf("  Status: %s (code %d)\n",
           status == SWIM_OK ? "OK" :
           status == SWIM_ERROR_AUTH ? "AUTH_ERROR" :
           status == SWIM_ERROR_RATE_LIMIT ? "RATE_LIMITED" :
           status == SWIM_ERROR_NETWORK ? "NETWORK_ERROR" :
           status == SWIM_ERROR_SERVER ? "SERVER_ERROR" : "ERROR",
           status);
    printf("  HTTP Code: %d\n", result.http_code);
    printf("  Processed: %d\n", result.processed);
    printf("  Created: %d\n", result.created);
    printf("  Updated: %d\n", result.updated);

    if (result.error_message[0]) {
        printf("  Error: %s\n", result.error_message);
    }

    /* Cleanup */
    swim_client_cleanup(&client);
    printf("\nClient cleaned up\n");

    return status == SWIM_OK ? 0 : 1;
}
