<?php

namespace GoogleAgentPlatform\Tests\Unit;

use GoogleAgentPlatform\Http\HttpClient;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // URL building — Express Mode (API key)
    // -------------------------------------------------------------------------

    public function test_build_model_url_express_mode(): void
    {
        $http = new HttpClient('my-api-key', null, null, 'global');

        $url = $http->buildModelUrl('google', 'gemini-3.1-flash-lite-preview', 'generateContent');

        $this->assertStringContainsString('models/gemini-3.1-flash-lite-preview:generateContent', $url);
        $this->assertStringContainsString('key=my-api-key', $url);
        $this->assertStringNotContainsString('projects/', $url);
    }

    public function test_build_model_url_cloud_mode(): void
    {
        $http = new HttpClient(null, 'my-token', 'my-project', 'us-central1');

        $url = $http->buildModelUrl('anthropic', 'claude-sonnet-4-6', 'rawPredict');

        $this->assertStringContainsString('https://us-central1-aiplatform.googleapis.com/v1/projects/my-project', $url);
        $this->assertStringContainsString('locations/us-central1', $url);
        $this->assertStringContainsString('publishers/anthropic/models/claude-sonnet-4-6:rawPredict', $url);
        $this->assertStringNotContainsString('key=', $url);
    }

    // -------------------------------------------------------------------------
    // URL building — File API
    // -------------------------------------------------------------------------

    public function test_build_file_api_url_express_mode(): void
    {
        $http = new HttpClient('my-api-key', null, null, 'global');

        $url = $http->buildFileApiUrl();

        $this->assertStringContainsString('generativelanguage.googleapis.com', $url);
        $this->assertStringContainsString('key=my-api-key', $url);
    }

    public function test_build_file_api_url_cloud_mode(): void
    {
        $http = new HttpClient(null, 'my-token', 'my-project', 'global');

        $url = $http->buildFileApiUrl();

        $this->assertStringContainsString('generativelanguage.googleapis.com', $url);
        $this->assertStringNotContainsString('key=', $url);
    }

    public function test_build_file_api_url_with_path(): void
    {
        $http = new HttpClient('my-api-key', null, null, 'global');

        $url = $http->buildFileApiUrl('/abc123');

        $this->assertStringContainsString('/files/abc123', $url);
        $this->assertStringContainsString('key=my-api-key', $url);
    }

    public function test_build_upload_url_express_mode(): void
    {
        $http = new HttpClient('my-api-key', null, null, 'global');

        $url = $http->buildUploadUrl();

        $this->assertStringContainsString('upload/v1beta/files', $url);
        $this->assertStringContainsString('uploadType=resumable', $url);
        $this->assertStringContainsString('key=my-api-key', $url);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function test_get_base_url(): void
    {
        $http = new HttpClient('key', null, null, 'global');
        $this->assertStringStartsWith('https://', $http->getBaseUrl());
    }

    public function test_get_location(): void
    {
        $http = new HttpClient(null, 'token', 'project', 'us-east5');
        $this->assertSame('us-east5', $http->getLocation());
    }

    public function test_get_project_id(): void
    {
        $http = new HttpClient(null, 'token', 'my-project', 'global');
        $this->assertSame('my-project', $http->getProjectId());
    }
}
