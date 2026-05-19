<?php

namespace GoogleAgentPlatform\Http;

use GoogleAgentPlatform\Exceptions\ApiException;
use GoogleAgentPlatform\Exceptions\AuthException;

/**
 * Low-level HTTP client.
 *
 * Handles all cURL communication with the Agent Platform API.
 * All higher-level resource classes receive an instance of this class
 * and call request() / requestRaw() / get() / stream() / delete() as needed.
 */
class HttpClient
{
    private const BASE_URL = 'https://aiplatform.googleapis.com/v1';

    private ?string $apiKey;
    private ?string $accessToken;
    private ?string $projectId;
    private string  $location;

    public function __construct(
        ?string $apiKey,
        ?string $accessToken,
        ?string $projectId,
        string  $location
    ) {
        $this->apiKey      = $apiKey;
        $this->accessToken = $accessToken;
        $this->projectId   = $projectId;
        $this->location    = $location;
    }

    // -------------------------------------------------------------------------
    // Public interface used by resource classes
    // -------------------------------------------------------------------------

    /**
     * POST to a model endpoint and return a decoded JSON array.
     */
    public function request(string $modelId, string $action, array $payload): array
    {
        $raw     = $this->requestRaw($modelId, $action, $payload);
        $decoded = \json_decode($raw, true);

        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException(
                'Failed to decode API response as JSON. '
                . 'If this endpoint returns binary data, use requestRaw() instead.'
            );
        }

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? 'Unknown API Error';
            $code = $decoded['error']['code']    ?? 0;
            $this->throwTypedApiException($msg, (int) $code);
        }

        return $decoded;
    }

    /**
     * POST to a model endpoint and return the raw response string.
     * Used for binary responses (audio, images) and as the base for request().
     */
    public function requestRaw(string $modelId, string $action, array $payload): string
    {
        [$publisher, $model] = $this->resolvePublisher($modelId);
        $url     = $this->buildModelUrl($publisher, $model, $action);
        $headers = $this->buildHeaders();

        return $this->execute($url, $headers, \json_encode($payload), 'POST');
    }

    /**
     * POST to a model endpoint and stream the response via a user-supplied callback.
     *
     * The Agent Platform streaming endpoint returns newline-delimited JSON chunks
     * (server-sent events). Each non-empty line is passed raw to $onChunk so the
     * caller can parse or forward it incrementally.
     *
     * @param string   $modelId
     * @param string   $action    Typically 'streamGenerateContent' or 'rawPredict'
     * @param array    $payload
     * @param callable $onChunk   fn(string $line): void — called for every non-empty line
     */
    public function stream(string $modelId, string $action, array $payload, callable $onChunk): void
    {
        [$publisher, $model] = $this->resolvePublisher($modelId);
        $url     = $this->buildModelUrl($publisher, $model, $action);
        $headers = $this->buildHeaders();

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($payload));
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // Do NOT buffer — stream instead

        // Buffer partial lines across chunks
        $lineBuffer = '';

        \curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use ($onChunk, &$lineBuffer): int {
            $lineBuffer .= $data;

            // Process all complete lines in the buffer
            while (($pos = \strpos($lineBuffer, "\n")) !== false) {
                $line       = \substr($lineBuffer, 0, $pos);
                $lineBuffer = \substr($lineBuffer, $pos + 1);

                // Strip SSE "data: " prefix if present
                if (\str_starts_with($line, 'data: ')) {
                    $line = \substr($line, 6);
                }

                $line = \trim($line);

                if ($line !== '' && $line !== '[DONE]') {
                    $onChunk($line);
                }
            }

            return \strlen($data); // Must return the number of bytes handled
        });

        \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = \curl_error($ch);

        if ($error) {
            throw new ApiException("cURL stream error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new ApiException("API stream error: HTTP {$httpCode}", $httpCode);
        }

        // Flush any remaining partial line
        $lineBuffer = \trim($lineBuffer);
        if ($lineBuffer !== '' && $lineBuffer !== '[DONE]') {
            $onChunk($lineBuffer);
        }
    }

    /**
     * GET a URL and return a decoded JSON array.
     * Used for polling long-running operations.
     * Appends the API key as a query parameter when in Express Mode.
     */
    public function get(string $url): array
    {
        // Append API key for Express Mode (Cloud Mode uses the Authorization header)
        if ($this->apiKey) {
            $sep = \str_contains($url, '?') ? '&' : '?';
            $url .= "{$sep}key={$this->apiKey}";
        }

        $headers  = $this->buildHeaders();
        $raw      = $this->execute($url, $headers, null, 'GET');
        $decoded  = \json_decode($raw, true);

        if ($decoded === null) {
            throw new ApiException('Failed to decode GET response as JSON.');
        }

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? 'Unknown API Error';
            $code = $decoded['error']['code']    ?? 0;
            $this->throwTypedApiException($msg, (int) $code);
        }

        return $decoded;
    }

    /**
     * DELETE a URL. Used by FileResource::deleteFile().
     * A successful delete returns HTTP 200 with an empty JSON body {}.
     */
    public function delete(string $url): void
    {
        if ($this->apiKey) {
            $sep = \str_contains($url, '?') ? '&' : '?';
            $url .= "{$sep}key={$this->apiKey}";
        }

        $headers = $this->buildHeaders();
        $this->execute($url, $headers, null, 'DELETE');
    }

    /**
     * POST raw bytes (multipart or binary) to a URL and return decoded JSON.
     * Used by the File API upload.
     */
    public function postRaw(string $url, string $body, array $extraHeaders = []): array
    {
        $headers = \array_merge($this->buildAuthHeaders(), $extraHeaders);
        $raw     = $this->execute($url, $headers, $body, 'POST');
        $decoded = \json_decode($raw, true);

        if ($decoded === null) {
            throw new ApiException('Failed to decode upload response as JSON.');
        }

        if (isset($decoded['error'])) {
            $msg  = $decoded['error']['message'] ?? 'Unknown API Error';
            $code = $decoded['error']['code']    ?? 0;
            $this->throwTypedApiException($msg, (int) $code);
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // URL / header builders
    // -------------------------------------------------------------------------

    /**
     * Build the full model endpoint URL.
     */
    public function buildModelUrl(string $publisher, string $model, string $action): string
    {
        if ($this->apiKey) {
            // Express mode uses generativelanguage endpoint
            return \sprintf(
                "https://generativelanguage.googleapis.com/v1beta/models/%s:%s?key=%s",
                $model,
                $action,
                $this->apiKey
            );
        }

        // Cloud mode (Agent Platform / Vertex AI) uses region-specific aiplatform endpoints
        $baseUrl = \sprintf('https://%s-aiplatform.googleapis.com/v1', $this->location);

        return \sprintf(
            "%s/projects/%s/locations/%s/publishers/%s/models/%s:%s",
            $baseUrl,
            $this->projectId,
            $this->location,
            $publisher,
            $model,
            $action
        );
    }

    /**
     * Build the base URL for the File API (upload / metadata).
     */
    public function buildFileApiUrl(string $path = ''): string
    {
        $base = 'https://generativelanguage.googleapis.com/v1beta/files';

        if ($this->apiKey) {
            $sep = \str_contains($path, '?') ? '&' : '?';
            return $base . $path . $sep . 'key=' . $this->apiKey;
        }

        return $base . $path;
    }

    /**
     * Build the resumable upload initiation URL for the File API.
     */
    public function buildUploadUrl(): string
    {
        $base = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

        if ($this->apiKey) {
            return $base . '?uploadType=resumable&key=' . $this->apiKey;
        }

        return $base . '?uploadType=resumable';
    }

    public function getProjectId(): ?string
    {
        return $this->projectId;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a modelId string into [publisher, model].
     * 'anthropic/claude-sonnet-4-6' → ['anthropic', 'claude-sonnet-4-6']
     * 'gemini-3.1-flash-lite-preview' → ['google', 'gemini-3.1-flash-lite-preview']
     */
    private function resolvePublisher(string $modelId): array
    {
        if (\strpos($modelId, '/') !== false) {
            return \explode('/', $modelId, 2);
        }

        return ['google', $modelId];
    }

    /**
     * Build Content-Type + auth headers for JSON API calls.
     */
    private function buildHeaders(): array
    {
        return \array_merge(
            ['Content-Type: application/json'],
            $this->getAuthHeaders()
        );
    }

    /**
     * Build only the auth header (used when Content-Type is set separately).
     */
    public function getAuthHeaders(): array
    {
        if ($this->accessToken) {
            return ["Authorization: Bearer {$this->accessToken}"];
        }

        return []; // API key is appended to the URL, not a header
    }

    /**
     * Execute a cURL request and return the raw response body.
     *
     * @param string      $url
     * @param string[]    $headers
     * @param string|null $body     POST body; null for GET/DELETE
     * @param string      $method   'GET', 'POST', or 'DELETE'
     */
    private function execute(string $url, array $headers, ?string $body, string $method): string
    {
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        } elseif ($method === 'DELETE') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = \curl_error($ch);

        if ($error) {
            throw new ApiException("cURL Error: {$error}");
        }

        if ($httpCode >= 400) {
            $decoded = \json_decode($response, true);
            $msg     = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            $this->throwTypedApiException($msg, $httpCode);
        }

        return $response;
    }

    /**
     * Throw the most specific exception type based on HTTP status code.
     */
    private function throwTypedApiException(string $message, int $httpCode): never
    {
        if ($httpCode === 401 || $httpCode === 403) {
            throw new AuthException("API Error ({$httpCode}): {$message}", $httpCode);
        }

        throw new ApiException("API Error ({$httpCode}): {$message}", $httpCode);
    }
}
