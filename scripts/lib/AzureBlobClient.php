<?php
/**
 * Azure Blob Storage REST API Client (Shared Key Auth)
 *
 * Thin client for PUT Blob and List Blobs operations. No external dependencies.
 * Parses connection string from ADL_ARCHIVE_STORAGE_CONN environment variable.
 *
 * @see https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key
 */

declare(strict_types=1);

class AzureBlobClient
{
    private string $accountName;
    private string $accountKey;
    private string $blobEndpoint;

    /**
     * @param string $connectionString Azure Storage connection string
     *   Format: DefaultEndpointsProtocol=https;AccountName=...;AccountKey=...;EndpointSuffix=...
     */
    public function __construct(string $connectionString)
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if ($segment === '') continue;
            $eq = strpos($segment, '=');
            if ($eq === false) continue;
            $key = substr($segment, 0, $eq);
            $value = substr($segment, $eq + 1);
            $parts[$key] = $value;
        }

        if (empty($parts['AccountName']) || empty($parts['AccountKey'])) {
            throw new RuntimeException('Connection string must contain AccountName and AccountKey');
        }

        $this->accountName = $parts['AccountName'];
        $this->accountKey = $parts['AccountKey'];

        $protocol = $parts['DefaultEndpointsProtocol'] ?? 'https';
        $suffix = $parts['EndpointSuffix'] ?? 'core.windows.net';
        $this->blobEndpoint = "{$protocol}://{$this->accountName}.blob.{$suffix}";
    }

    /**
     * Upload a blob (PUT Blob — block blob).
     *
     * @param string $container Container name
     * @param string $blobPath  Blob path within the container
     * @param string $data      Raw blob content
     * @param string $contentType MIME type (default: application/gzip)
     * @return array ['status' => int, 'headers' => string]
     */
    public function putBlob(string $container, string $blobPath, string $data, string $contentType = 'application/gzip'): array
    {
        $url = "{$this->blobEndpoint}/{$container}/{$blobPath}";
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentLength = strlen($data);
        $version = '2020-10-02';

        // Canonicalized headers (alphabetical, lowercase)
        $canonHeaders = "x-ms-blob-type:BlockBlob\nx-ms-date:{$date}\nx-ms-version:{$version}";

        // Canonicalized resource
        $canonResource = "/{$this->accountName}/{$container}/{$blobPath}";

        // String to sign for PUT Blob
        $stringToSign = implode("\n", [
            'PUT',                    // HTTP verb
            '',                       // Content-Encoding
            '',                       // Content-Language
            (string)$contentLength,   // Content-Length
            '',                       // Content-MD5
            $contentType,             // Content-Type
            '',                       // Date (empty when x-ms-date used)
            '',                       // If-Modified-Since
            '',                       // If-Match
            '',                       // If-None-Match
            '',                       // If-Unmodified-Since
            '',                       // Range
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "Content-Type: {$contentType}",
                "Content-Length: {$contentLength}",
                "x-ms-blob-type: BlockBlob",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error uploading blob: {$error}");
        }

        return ['status' => $httpCode, 'response' => $response];
    }

    /**
     * List blobs in a container with optional prefix filter.
     *
     * @param string $container Container name
     * @param string $prefix    Blob name prefix (e.g., "datafeed/2026/05/")
     * @param string|null $marker Continuation marker for pagination
     * @return array ['blobs' => [['name' => string, 'size' => int, ...]], 'next_marker' => string|null]
     */
    public function listBlobs(string $container, string $prefix = '', ?string $marker = null): array
    {
        $params = ['restype' => 'container', 'comp' => 'list', 'prefix' => $prefix, 'maxresults' => '5000'];
        if ($marker !== null) {
            $params['marker'] = $marker;
        }
        $query = http_build_query($params);
        $url = "{$this->blobEndpoint}/{$container}?{$query}";

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $version = '2020-10-02';

        $canonHeaders = "x-ms-date:{$date}\nx-ms-version:{$version}";

        // Canonicalized resource with query params (alphabetical)
        $canonResource = "/{$this->accountName}/{$container}\ncomp:list\nmaxresults:5000\nprefix:{$prefix}\nrestype:container";
        if ($marker !== null) {
            $canonResource = "/{$this->accountName}/{$container}\ncomp:list\nmarker:{$marker}\nmaxresults:5000\nprefix:{$prefix}\nrestype:container";
        }

        $stringToSign = implode("\n", [
            'GET', '', '', '', '', '', '', '', '', '', '', '',
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error listing blobs: {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("List blobs failed with HTTP {$httpCode}: {$response}");
        }

        // Parse XML response
        $xml = simplexml_load_string($response);
        $blobs = [];
        if (isset($xml->Blobs->Blob)) {
            foreach ($xml->Blobs->Blob as $blob) {
                $blobs[] = [
                    'name' => (string)$blob->Name,
                    'size' => (int)($blob->Properties->{'Content-Length'} ?? 0),
                    'last_modified' => (string)($blob->Properties->{'Last-Modified'} ?? ''),
                ];
            }
        }

        $nextMarker = null;
        if (isset($xml->NextMarker) && (string)$xml->NextMarker !== '') {
            $nextMarker = (string)$xml->NextMarker;
        }

        return ['blobs' => $blobs, 'next_marker' => $nextMarker];
    }

    /**
     * Download a blob's content.
     *
     * @param string $container Container name
     * @param string $blobPath  Blob path
     * @return string Raw blob content
     */
    public function getBlob(string $container, string $blobPath): string
    {
        $url = "{$this->blobEndpoint}/{$container}/{$blobPath}";
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $version = '2020-10-02';

        $canonHeaders = "x-ms-date:{$date}\nx-ms-version:{$version}";
        $canonResource = "/{$this->accountName}/{$container}/{$blobPath}";

        $stringToSign = implode("\n", [
            'GET', '', '', '', '', '', '', '', '', '', '', '',
            $canonHeaders,
            $canonResource,
        ]);

        $signature = base64_encode(hash_hmac('sha256', $stringToSign, base64_decode($this->accountKey), true));
        $authHeader = "SharedKey {$this->accountName}:{$signature}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                "Authorization: {$authHeader}",
                "x-ms-date: {$date}",
                "x-ms-version: {$version}",
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("cURL error downloading blob: {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("GET blob failed with HTTP {$httpCode} for {$blobPath}");
        }

        return $response;
    }
}
