<?php

namespace GoogleAgentPlatform\Resources;

use GoogleAgentPlatform\Http\HttpClient;
use GoogleAgentPlatform\Support\MimeTypes;
use GoogleAgentPlatform\Exceptions\FileNotFoundException;

/**
 * File handling — local file embedding and File API uploads.
 *
 * Two strategies for sending files to models:
 *
 * 1. inlineData (< ~20 MB, single request)
 *    Base64-encodes the file and embeds it directly in the request body.
 *    Use withFile() to build a ready-to-use contents array for generateContent().
 *
 * 2. File API upload (any size, reusable across requests)
 *    Uploads the file to Google's File API via a resumable upload.
 *    Returns a file URI that can be referenced in subsequent requests.
 *    Use uploadFile() to get the URI, then pass it via fileData in your content.
 */
class FileResource
{
    public function __construct(private readonly HttpClient $http) {}

    // -------------------------------------------------------------------------
    // Strategy 1 — inlineData (base64 embed, < ~20 MB)
    // -------------------------------------------------------------------------

    /**
     * Build a Gemini-compatible `contents` array with a local file embedded
     * as base64 inlineData, ready to pass directly to generateContent().
     *
     * Best for: images, short audio clips, small PDFs under ~20 MB.
     *
     * @param string      $filePath  Absolute or relative path to the local file.
     * @param string      $text      The text prompt to accompany the file.
     * @param string|null $mimeType  MIME type override. Auto-detected if null.
     * @param string      $role      Message role (default: 'user').
     *
     * @return array  A single-element contents array, ready for generateContent().
     *
     * @example
     *   $contents = $client->files->withFile('/tmp/photo.jpg', 'What is in this image?');
     *   $response = $client->generateContent($contents);
     */
    public function withFile(
        string  $filePath,
        string  $text,
        ?string $mimeType = null,
        string  $role     = 'user'
    ): array {
        if (!\file_exists($filePath)) {
            throw new FileNotFoundException("File not found: {$filePath}");
        }

        $mimeType = $mimeType ?? MimeTypes::detect($filePath);
        $bytes    = \file_get_contents($filePath);
        $b64      = \base64_encode($bytes);

        return [
            [
                'role'  => $role,
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $mimeType,
                            'data'     => $b64,
                        ],
                    ],
                    ['text' => $text],
                ],
            ],
        ];
    }

    /**
     * Build a contents array with multiple local files and a text prompt.
     *
     * @param array<string|array{path:string,mimeType?:string}> $files
     *        Either plain file path strings, or arrays with 'path' and optional 'mimeType'.
     * @param string $text  The text prompt.
     * @param string $role  Message role (default: 'user').
     *
     * @return array  A single-element contents array for generateContent().
     *
     * @example
     *   $contents = $client->files->withFiles([
     *       '/tmp/chart.png',
     *       ['path' => '/tmp/report.pdf', 'mimeType' => 'application/pdf'],
     *   ], 'Summarize the report and explain the chart.');
     */
    public function withFiles(array $files, string $text, string $role = 'user'): array
    {
        $parts = [];

        foreach ($files as $file) {
            if (\is_string($file)) {
                $filePath = $file;
                $mimeType = null;
            } else {
                $filePath = $file['path'];
                $mimeType = $file['mimeType'] ?? null;
            }

            if (!\file_exists($filePath)) {
                throw new FileNotFoundException("File not found: {$filePath}");
            }

            $mimeType = $mimeType ?? MimeTypes::detect($filePath);
            $b64      = \base64_encode(\file_get_contents($filePath));

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data'     => $b64,
                ],
            ];
        }

        $parts[] = ['text' => $text];

        return [
            [
                'role'  => $role,
                'parts' => $parts,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Strategy 2 — File API upload (resumable, any size, reusable)
    // -------------------------------------------------------------------------

    /**
     * Upload a local file to the Gemini File API.
     *
     * Uses a two-step resumable upload:
     *  1. Initiate the upload session → get an upload URL
     *  2. Upload the file bytes to that URL
     *
     * The returned file URI can be used in subsequent generateContent() calls
     * via the 'fileData' part, and remains valid for 48 hours.
     *
     * Best for: large files (> 20 MB), files reused across multiple requests,
     * video files, long audio recordings.
     *
     * @param string      $filePath    Absolute or relative path to the local file.
     * @param string|null $mimeType    MIME type override. Auto-detected if null.
     * @param string|null $displayName Optional human-readable name for the file.
     *
     * @return array {
     *   'name'       => 'files/abc123',       // File API resource name
     *   'uri'        => 'https://...',         // URI to use in fileData parts
     *   'mimeType'   => 'image/jpeg',
     *   'sizeBytes'  => '204800',
     *   'state'      => 'ACTIVE',
     *   'displayName'=> 'my-photo.jpg',
     * }
     *
     * @example
     *   $file = $client->files->uploadFile('/tmp/large-video.mp4');
     *
     *   $response = $client->generateContent([[
     *       'role'  => 'user',
     *       'parts' => [
     *           ['fileData' => ['mimeType' => $file['mimeType'], 'fileUri' => $file['uri']]],
     *           ['text'     => 'Summarize this video.'],
     *       ],
     *   ]]);
     */
    public function uploadFile(
        string  $filePath,
        ?string $mimeType    = null,
        ?string $displayName = null
    ): array {
        if (!\file_exists($filePath)) {
            throw new FileNotFoundException("File not found: {$filePath}");
        }

        $mimeType    = $mimeType ?? MimeTypes::detect($filePath);
        $displayName = $displayName ?? \basename($filePath);
        $fileSize    = \filesize($filePath);

        // Step 1: Initiate the resumable upload session
        $uploadUrl = $this->initiateUpload($mimeType, $displayName, $fileSize);

        // Step 2: Upload the file bytes
        $fileInfo = $this->uploadBytes($uploadUrl, $filePath, $mimeType, $fileSize);

        return $fileInfo;
    }

    /**
     * Build a fileData part array from an already-uploaded File API URI.
     * Convenience helper to avoid constructing the array manually.
     *
     * @example
     *   $file    = $client->files->uploadFile('/tmp/photo.jpg');
     *   $content = $client->files->fromUri($file['uri'], $file['mimeType'], 'Describe this.');
     *   $response = $client->generateContent($content);
     */
    public function fromUri(string $fileUri, string $mimeType, string $text, string $role = 'user'): array
    {
        return [
            [
                'role'  => $role,
                'parts' => [
                    [
                        'fileData' => [
                            'mimeType' => $mimeType,
                            'fileUri'  => $fileUri,
                        ],
                    ],
                    ['text' => $text],
                ],
            ],
        ];
    }

    /**
     * List files previously uploaded to the File API.
     *
     * @param int $pageSize  Maximum number of files to return (default 10, max 100).
     */
    public function listFiles(int $pageSize = 10): array
    {
        $url = $this->http->buildFileApiUrl("?pageSize={$pageSize}");
        return $this->http->get($url);
    }

    /**
     * Get metadata for a specific uploaded file.
     *
     * @param string $fileName  The file resource name, e.g. 'files/abc123' or just 'abc123'.
     */
    public function getFile(string $fileName): array
    {
        // Normalise: strip any leading slash, then strip a 'files/' prefix so we
        // always end up with just the bare ID, then re-add it via buildFileApiUrl.
        $name = \ltrim($fileName, '/');

        if (\str_starts_with($name, 'files/')) {
            $name = \substr($name, \strlen('files/'));
        }

        $url = $this->http->buildFileApiUrl('/' . $name);
        return $this->http->get($url);
    }

    /**
     * Delete an uploaded file from the File API.
     *
     * @param string $fileName  The file resource name, e.g. 'files/abc123' or just 'abc123'.
     */
    public function deleteFile(string $fileName): void
    {
        $name = \ltrim($fileName, '/');

        if (\str_starts_with($name, 'files/')) {
            $name = \substr($name, \strlen('files/'));
        }

        $url = $this->http->buildFileApiUrl('/' . $name);
        $this->http->delete($url);
    }

    // -------------------------------------------------------------------------
    // Internal upload helpers
    // -------------------------------------------------------------------------

    /**
     * Step 1: Initiate a resumable upload session.
     * Returns the upload URL to send the file bytes to.
     */
    private function initiateUpload(string $mimeType, string $displayName, int $fileSize): string
    {
        $initiateUrl = $this->http->buildUploadUrl();

        $metadata = \json_encode([
            'file' => ['display_name' => $displayName],
        ]);

        $headers = \array_merge([
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            "X-Goog-Upload-Header-Content-Length: {$fileSize}",
            "X-Goog-Upload-Header-Content-Type: {$mimeType}",
            'Content-Type: application/json',
        ], $this->http->getAuthHeaders());

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $initiateUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_HEADER, true); // We need the response headers

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = \curl_error($ch);
        $headerSize = \curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if ($error) {
            throw new \RuntimeException("cURL Error during upload initiation: {$error}");
        }

        if ($httpCode >= 400) {
            $body    = \substr($response, $headerSize);
            $decoded = \json_decode($body, true);
            $msg     = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("File API Error ({$httpCode}): {$msg}");
        }

        // Extract the upload URL from the response headers
        $rawHeaders = \substr($response, 0, $headerSize);
        \preg_match('/X-Goog-Upload-URL:\s*(\S+)/i', $rawHeaders, $matches);

        if (empty($matches[1])) {
            throw new \RuntimeException('File API did not return an upload URL.');
        }

        return \trim($matches[1]);
    }

    /**
     * Step 2: Upload the actual file bytes to the resumable upload URL.
     *
     * Uses CURLOPT_READFUNCTION to stream the file from disk in chunks,
     * avoiding loading the entire file into memory at once.
     *
     * Returns the file metadata from the API response.
     */
    private function uploadBytes(string $uploadUrl, string $filePath, string $mimeType, int $fileSize): array
    {
        $fh = \fopen($filePath, 'rb');

        if ($fh === false) {
            throw new \RuntimeException("Could not open file for reading: {$filePath}");
        }

        $headers = \array_merge([
            "Content-Type: {$mimeType}",
            "Content-Length: {$fileSize}",
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ], $this->http->getAuthHeaders());

        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_PUT, true);                    // PUT streams via READFUNCTION
        \curl_setopt($ch, CURLOPT_INFILE, $fh);
        \curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = \curl_error($ch);

        \fclose($fh);

        if ($error) {
            throw new \RuntimeException("cURL Error during file upload: {$error}");
        }

        if ($httpCode >= 400) {
            $decoded = \json_decode($response, true);
            $msg     = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("File upload Error ({$httpCode}): {$msg}");
        }

        $decoded = \json_decode($response, true);

        if (!\is_array($decoded) || !isset($decoded['file'])) {
            throw new \RuntimeException('Unexpected response from File API after upload.');
        }

        $file = $decoded['file'];

        return [
            'name'        => $file['name']        ?? '',
            'uri'         => $file['uri']          ?? '',
            'mimeType'    => $file['mimeType']     ?? $mimeType,
            'sizeBytes'   => $file['sizeBytes']    ?? (string) $fileSize,
            'state'       => $file['state']        ?? 'ACTIVE',
            'displayName' => $file['displayName']  ?? \basename($filePath),
        ];
    }
}
