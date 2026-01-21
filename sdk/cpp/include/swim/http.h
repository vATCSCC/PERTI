/**
 * VATSWIM C/C++ SDK - HTTP Client
 *
 * HTTP client abstraction using libcurl.
 * Requires linking with -lcurl.
 *
 * @file http.h
 * @version 1.0.0
 * @license MIT
 */

#ifndef SWIM_HTTP_H
#define SWIM_HTTP_H

#include "types.h"
#include "json.h"

#ifdef __cplusplus
extern "C" {
#endif

/* Check if curl is available */
#ifdef SWIM_USE_CURL

#include <curl/curl.h>

/* ============================================================================
 * HTTP Response Buffer
 * ============================================================================ */

typedef struct {
    char* data;
    size_t size;
    size_t capacity;
} SwimHttpBuffer;

static inline size_t swim_http_write_callback(void* contents, size_t size, size_t nmemb, void* userp) {
    size_t total = size * nmemb;
    SwimHttpBuffer* buf = (SwimHttpBuffer*)userp;

    size_t needed = buf->size + total + 1;
    if (needed > buf->capacity) {
        size_t new_cap = buf->capacity * 2;
        while (new_cap < needed) new_cap *= 2;

        char* new_data = (char*)realloc(buf->data, new_cap);
        if (!new_data) return 0;

        buf->data = new_data;
        buf->capacity = new_cap;
    }

    memcpy(buf->data + buf->size, contents, total);
    buf->size += total;
    buf->data[buf->size] = '\0';

    return total;
}

/* ============================================================================
 * HTTP Client
 * ============================================================================ */

typedef struct {
    CURL* curl;
    SwimClientConfig config;
    SwimHttpBuffer response;
    char error_buffer[CURL_ERROR_SIZE];
} SwimHttpClient;

/**
 * Initialize HTTP client
 */
static inline bool swim_http_init(SwimHttpClient* client, const SwimClientConfig* config) {
    if (!client || !config) return false;

    memset(client, 0, sizeof(SwimHttpClient));
    memcpy(&client->config, config, sizeof(SwimClientConfig));

    /* Initialize response buffer */
    client->response.capacity = 4096;
    client->response.data = (char*)malloc(client->response.capacity);
    if (!client->response.data) return false;
    client->response.data[0] = '\0';
    client->response.size = 0;

    /* Initialize curl */
    client->curl = curl_easy_init();
    if (!client->curl) {
        free(client->response.data);
        return false;
    }

    return true;
}

/**
 * Cleanup HTTP client
 */
static inline void swim_http_cleanup(SwimHttpClient* client) {
    if (!client) return;

    if (client->curl) {
        curl_easy_cleanup(client->curl);
        client->curl = NULL;
    }
    if (client->response.data) {
        free(client->response.data);
        client->response.data = NULL;
    }
}

/**
 * Perform HTTP POST request
 */
static inline SwimStatus swim_http_post(
    SwimHttpClient* client,
    const char* endpoint,
    const char* json_body,
    SwimIngestResult* result
) {
    if (!client || !client->curl || !endpoint || !json_body || !result) {
        return SWIM_ERROR_INVALID_DATA;
    }

    memset(result, 0, sizeof(SwimIngestResult));

    /* Reset response buffer */
    client->response.size = 0;
    client->response.data[0] = '\0';

    /* Build full URL */
    char url[512];
    snprintf(url, sizeof(url), "%s%s",
             client->config.base_url[0] ? client->config.base_url : SWIM_DEFAULT_BASE_URL,
             endpoint);

    /* Set up curl */
    curl_easy_reset(client->curl);
    curl_easy_setopt(client->curl, CURLOPT_URL, url);
    curl_easy_setopt(client->curl, CURLOPT_POST, 1L);
    curl_easy_setopt(client->curl, CURLOPT_POSTFIELDS, json_body);
    curl_easy_setopt(client->curl, CURLOPT_POSTFIELDSIZE, strlen(json_body));

    /* Headers */
    struct curl_slist* headers = NULL;
    headers = curl_slist_append(headers, "Content-Type: application/json");

    char auth_header[256];
    snprintf(auth_header, sizeof(auth_header), "Authorization: Bearer %s", client->config.api_key);
    headers = curl_slist_append(headers, auth_header);

    if (client->config.source_id[0]) {
        char source_header[64];
        snprintf(source_header, sizeof(source_header), "X-SWIM-Source: %s", client->config.source_id);
        headers = curl_slist_append(headers, source_header);
    }

    curl_easy_setopt(client->curl, CURLOPT_HTTPHEADER, headers);

    /* Response handling */
    curl_easy_setopt(client->curl, CURLOPT_WRITEFUNCTION, swim_http_write_callback);
    curl_easy_setopt(client->curl, CURLOPT_WRITEDATA, &client->response);
    curl_easy_setopt(client->curl, CURLOPT_ERRORBUFFER, client->error_buffer);

    /* Timeout */
    int timeout = client->config.timeout_ms > 0 ? client->config.timeout_ms : 30000;
    curl_easy_setopt(client->curl, CURLOPT_TIMEOUT_MS, timeout);

    /* SSL verification */
    curl_easy_setopt(client->curl, CURLOPT_SSL_VERIFYPEER, client->config.verify_ssl ? 1L : 0L);

    /* Perform request */
    CURLcode res = curl_easy_perform(client->curl);

    /* Cleanup headers */
    curl_slist_free_all(headers);

    /* Check result */
    if (res != CURLE_OK) {
        result->status = (res == CURLE_OPERATION_TIMEDOUT) ? SWIM_ERROR_TIMEOUT : SWIM_ERROR_NETWORK;
        snprintf(result->error_message, sizeof(result->error_message),
                 "CURL error: %s", client->error_buffer[0] ? client->error_buffer : curl_easy_strerror(res));
        return result->status;
    }

    /* Get HTTP status code */
    long http_code = 0;
    curl_easy_getinfo(client->curl, CURLINFO_RESPONSE_CODE, &http_code);
    result->http_code = (int)http_code;

    /* Map HTTP status to result */
    if (http_code == 200 || http_code == 201) {
        result->status = SWIM_OK;
        /* Parse response for counts (simplified) */
        const char* processed = strstr(client->response.data, "\"processed\":");
        if (processed) {
            result->processed = atoi(processed + 12);
        }
        const char* created = strstr(client->response.data, "\"created\":");
        if (created) {
            result->created = atoi(created + 10);
        }
        const char* updated = strstr(client->response.data, "\"updated\":");
        if (updated) {
            result->updated = atoi(updated + 10);
        }
    } else if (http_code == 401 || http_code == 403) {
        result->status = SWIM_ERROR_AUTH;
        snprintf(result->error_message, sizeof(result->error_message), "Authentication failed (HTTP %d)", (int)http_code);
    } else if (http_code == 429) {
        result->status = SWIM_ERROR_RATE_LIMIT;
        snprintf(result->error_message, sizeof(result->error_message), "Rate limit exceeded");
    } else if (http_code >= 500) {
        result->status = SWIM_ERROR_SERVER;
        snprintf(result->error_message, sizeof(result->error_message), "Server error (HTTP %d)", (int)http_code);
    } else {
        result->status = SWIM_ERROR_INVALID_DATA;
        snprintf(result->error_message, sizeof(result->error_message), "Request failed (HTTP %d)", (int)http_code);
    }

    return result->status;
}

#else /* !SWIM_USE_CURL */

/* Stub implementations when curl is not available */
typedef struct {
    SwimClientConfig config;
} SwimHttpClient;

static inline bool swim_http_init(SwimHttpClient* client, const SwimClientConfig* config) {
    if (!client || !config) return false;
    memcpy(&client->config, config, sizeof(SwimClientConfig));
    return true;
}

static inline void swim_http_cleanup(SwimHttpClient* client) {
    (void)client;
}

static inline SwimStatus swim_http_post(
    SwimHttpClient* client,
    const char* endpoint,
    const char* json_body,
    SwimIngestResult* result
) {
    (void)client;
    (void)endpoint;
    (void)json_body;
    if (result) {
        result->status = SWIM_ERROR_NETWORK;
        snprintf(result->error_message, sizeof(result->error_message),
                 "HTTP support not compiled (define SWIM_USE_CURL and link with -lcurl)");
    }
    return SWIM_ERROR_NETWORK;
}

#endif /* SWIM_USE_CURL */

#ifdef __cplusplus
}
#endif

#endif /* SWIM_HTTP_H */
