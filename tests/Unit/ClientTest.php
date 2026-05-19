<?php

namespace GoogleAgentPlatform\Tests\Unit;

use GoogleAgentPlatform\Client;
use GoogleAgentPlatform\Http\HttpClient;
use GoogleAgentPlatform\Resources\AudioResource;
use GoogleAgentPlatform\Resources\ClaudeResource;
use GoogleAgentPlatform\Resources\FileResource;
use GoogleAgentPlatform\Resources\ImageResource;
use GoogleAgentPlatform\Resources\TextResource;
use GoogleAgentPlatform\Resources\VideoResource;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function test_constructs_with_api_key(): void
    {
        $client = new Client(['api_key' => 'test-key']);

        $this->assertInstanceOf(TextResource::class,  $client->text);
        $this->assertInstanceOf(ImageResource::class, $client->images);
        $this->assertInstanceOf(AudioResource::class, $client->audio);
        $this->assertInstanceOf(VideoResource::class, $client->video);
        $this->assertInstanceOf(ClaudeResource::class, $client->claude);
        $this->assertInstanceOf(FileResource::class,  $client->files);
    }

    public function test_constructs_with_cloud_mode(): void
    {
        $client = new Client([
            'project_id'   => 'my-project',
            'access_token' => 'my-token',
            'location'     => 'us-central1',
        ]);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function test_throws_without_credentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client([]);
    }

    public function test_throws_with_project_id_but_no_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(['project_id' => 'my-project']);
    }

    public function test_throws_with_token_but_no_project_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(['access_token' => 'my-token']);
    }

    // Since Client passes all requests through the internal HttpClient,
    // we can test the legacy methods by mocking HttpClient instead of the readonly properties.

    private function getMockedClient(): array
    {
        $mockHttp = $this->createMock(HttpClient::class);

        // Use Reflection to instantiate the Client WITHOUT calling the constructor,
        // so readonly properties are uninitialized!
        $reflection = new \ReflectionClass(Client::class);
        $client = $reflection->newInstanceWithoutConstructor();

        // Now we can set the readonly properties
        $reflection->getProperty('http')->setValue($client, $mockHttp);

        $reflection->getProperty('text')->setValue($client, new TextResource($mockHttp));
        $reflection->getProperty('images')->setValue($client, new ImageResource($mockHttp));
        $reflection->getProperty('audio')->setValue($client, new AudioResource($mockHttp));
        $reflection->getProperty('video')->setValue($client, new VideoResource($mockHttp));
        $reflection->getProperty('claude')->setValue($client, new ClaudeResource($mockHttp));
        $reflection->getProperty('files')->setValue($client, new FileResource($mockHttp));

        return [$client, $mockHttp];
    }

    public function test_legacy_generate_content_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('request')
                 ->with(
                     'gemini-3.1-pro-preview',
                     'generateContent',
                     $this->callback(function ($payload) {
                         return isset($payload['contents']) && $payload['contents'][0] === 'content' &&
                                isset($payload['systemInstruction']) && $payload['systemInstruction']['parts'][0]['text'] === 'system prompt' &&
                                isset($payload['generationConfig']) && $payload['generationConfig']['temp'] === 0.5;
                     })
                 )
                 ->willReturn(['result' => 'success']);

        $result = $client->generateContent(['content'], 'gemini-3.1-pro-preview', 'system prompt', ['temp' => 0.5]);

        $this->assertSame(['result' => 'success'], $result);
    }

    public function test_legacy_stream_generate_content_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('stream')
                 ->with(
                     'gemini-3.1-pro-preview',
                     'streamGenerateContent',
                     $this->isType('array'),
                     $this->isType('callable')
                 );

        $client->streamGenerateContent(['content'], 'gemini-3.1-pro-preview', function() {}, 'system prompt', ['temp' => 0.5]);
    }

    public function test_legacy_generate_image_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('request')
                 ->with(
                     'imagen-3.0-generate-001',
                     'predict',
                     $this->callback(function ($payload) {
                         return $payload['instances'][0]['prompt'] === 'A red fox' &&
                                $payload['parameters']['sampleCount'] === 2 &&
                                $payload['parameters']['aspectRatio'] === '16:9' &&
                                $payload['parameters']['negativePrompt'] === 'blurry';
                     })
                 )
                 ->willReturn(['predictions' => [['mimeType' => 'image/png', 'bytesBase64Encoded' => 'YQ==']]]);

        $result = $client->generateImage('A red fox', 'imagen-3.0-generate-001', 2, '16:9', null, ['negativePrompt' => 'blurry']);

        $this->assertIsArray($result);
        $this->assertSame('image/png', $result[0]['mimeType']);
        $this->assertSame('YQ==', $result[0]['base64']);
    }

    public function test_legacy_synthesize_speech_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('requestRaw')
                 ->with(
                     'gemini-3.1-flash-tts-preview',
                     'predict',
                     $this->callback(function ($payload) {
                         return $payload['text'] === 'Hello' &&
                                $payload['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'en-US-Standard-A' &&
                                $payload['stylePrompt'] === 'calm';
                     })
                 )
                 ->willReturn('rawbytes');

        $result = $client->synthesizeSpeech('Hello', 'gemini-3.1-flash-tts-preview', ['prebuiltVoiceConfig' => ['voiceName' => 'en-US-Standard-A']], 'calm');

        $this->assertSame('audio/mp3', $result['mimeType']);
        $this->assertSame('rawbytes', $result['bytes']);
    }

    public function test_legacy_generate_video_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('request')
                 ->with(
                     'google/veo-3.1-generate-001',
                     'predictLongRunning',
                     $this->callback(function ($payload) {
                         return $payload['instances'][0]['prompt'] === 'A timelapse' &&
                                $payload['parameters']['sampleCount'] === 1 &&
                                $payload['parameters']['storageUri'] === 'gs://bucket' &&
                                $payload['parameters']['generateAudio'] === true;
                     })
                 )
                 ->willReturn(['name' => 'operations/123']);

        $result = $client->generateVideo('A timelapse', 'google/veo-3.1-generate-001', 1, 'gs://bucket', ['generateAudio' => true]);

        $this->assertSame(['name' => 'operations/123'], $result);
    }

    public function test_legacy_get_operation_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->method('getBaseUrl')->willReturn('https://api');

        $mockHttp->expects($this->once())
                 ->method('get')
                 ->with('https://api/operations/123')
                 ->willReturn(['done' => true]);

        $result = $client->getOperation('operations/123');

        $this->assertSame(['done' => true], $result);
    }

    public function test_legacy_claude_messages_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('request')
                 ->with(
                     'anthropic/claude-sonnet-4-6',
                     'rawPredict',
                     $this->callback(function ($payload) {
                         return $payload['messages'][0]['content'] === 'Hello' &&
                                $payload['max_tokens'] === 500 &&
                                $payload['stream'] === false &&
                                $payload['anthropic_version'] === 'vertex-2023-10-16';
                     })
                 )
                 ->willReturn(['content' => [['text' => 'Hi']]]);

        $result = $client->claudeMessages([['role' => 'user', 'content' => 'Hello']], 'anthropic/claude-sonnet-4-6', 500);

        $this->assertSame(['content' => [['text' => 'Hi']]], $result);
    }

    public function test_legacy_predict_delegates(): void
    {
        /** @var Client $client */
        /** @var \PHPUnit\Framework\MockObject\MockObject $mockHttp */
        [$client, $mockHttp] = $this->getMockedClient();

        $mockHttp->expects($this->once())
                 ->method('request')
                 ->with('model-id', 'predict', ['key' => 'value'])
                 ->willReturn(['result' => 'ok']);

        $result = $client->predict(['key' => 'value'], 'model-id', 'predict');

        $this->assertSame(['result' => 'ok'], $result);
    }
}
