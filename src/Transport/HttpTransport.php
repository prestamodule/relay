<?php

declare(strict_types=1);

namespace Prism\Relay\Transport;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Prism\Relay\Exceptions\TransportException;

class HttpTransport implements Transport
{
    private const MCP_SESSION_ID_HEADER = 'Mcp-Session-Id';

    protected int $requestId = 0;
    protected string $sessionId;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config
    ) {}

    #[\Override]
    public function start(): void {}

    /**
     * Initializes a session with the MCP server for upcoming request
     *
     * @return void
     */
    public function initializeSession(): void
    {
        $this->sendRequest('initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => new \stdClass,
            'clientInfo' => [
                'name' => 'relay',
                'version' => '1.0.0',
            ],
        ]);

        // This one is just a ping, doesn't need actual response handling (but we could check for 200 response)
        $this->sendHttpRequest([
            'method' => 'notifications/initialized',
            'jsonrpc' => '2.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    #[\Override]
    public function sendRequest(string $method, array $params = []): array
    {
        if ($this->requiresSession() && $method != 'initialize' && !$this->hasSession()) {
            $this->initializeSession();
        }

        $this->requestId++;
        $requestPayload = $this->createRequestPayload($method, $params);

        try {
            $response = $this->sendHttpRequest($requestPayload);

            return $this->processResponse($response);
        } catch (\Throwable $e) {
            if ($e instanceof TransportException) {
                throw $e;
            }

            throw new TransportException(
                "Failed to send request to MCP server: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    #[\Override]
    public function close(): void {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function createRequestPayload(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => (string) $this->requestId,
            'method' => $method,
            'params' => $params,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function sendHttpRequest(array $payload): Response
    {
        $request = Http::timeout($this->getTimeout())
            ->acceptJson()
            ->when(
                $this->hasApiKey(),
                fn ($http) => $http->withToken($this->getApiKey())
            )
            ->when(
                $this->hasHeaders(),
                fn ($http) => $http->withHeaders($this->getHeaders())
            );

        if ($this->hasSession()) {
            $request->withHeader(self::MCP_SESSION_ID_HEADER, $this->sessionId);
        }

        return $request->post($this->getServerUrl(), $payload);
    }

    protected function requiresSession(): bool
    {
        return $this->config['requires_session'] ?? false;
    }

    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    protected function hasApiKey(): bool
    {
        return isset($this->config['api_key']) && $this->config['api_key'] !== null;
    }

    protected function hasHeaders(): bool
    {
        return isset($this->config['headers'])
            && is_array($this->config['headers'])
            && (isset($this->config['headers']) && $this->config['headers'] !== []);
    }

    protected function getApiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return $this->config['headers'] ?? [];
    }

    protected function getServerUrl(): string
    {
        return $this->config['url'];
    }

    protected function hasSession(): bool
    {
        return !empty($this->sessionId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TransportException
     */
    protected function processResponse(Response $response): array
    {
        $this->validateHttpResponse($response);

        $this->extractSessionIdFromResponse($response);

        $jsonResponse = $response->json();
        $this->validateJsonRpcResponse($jsonResponse);

        if (isset($jsonResponse['error'])) {
            $this->handleJsonRpcError($jsonResponse['error']);
        }

        return $jsonResponse['result'] ?? [];
    }

    /**
     * @throws TransportException
     */
    protected function validateHttpResponse(Response $response): void
    {
        if ($response->failed()) {
            throw new TransportException(
                "HTTP request failed with status code: {$response->status()}"
            );
        }
    }

    /**
     * @param  array<string, mixed>  $jsonResponse
     *
     * @throws TransportException
     */
    protected function validateJsonRpcResponse(array $jsonResponse): void
    {
        if (! isset($jsonResponse['jsonrpc']) ||
            $jsonResponse['jsonrpc'] !== '2.0' ||
            ! isset($jsonResponse['id']) ||
            (string) $jsonResponse['id'] !== (string) $this->requestId
        ) {
            throw new TransportException(
                'Invalid JSON-RPC 2.0 response received'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $error
     *
     * @throws TransportException
     */
    protected function handleJsonRpcError(array $error): void
    {
        $errorMessage = $error['message'] ?? 'Unknown error';
        $errorCode = $error['code'] ?? -1;
        $errorData = isset($error['data']) ? json_encode($error['data']) : '';

        $detailsSuffix = '';
        if (! ($errorData === '' || $errorData === '0' || $errorData === false) && $errorData !== '0' && $errorData !== 'false') {
            $detailsSuffix = " Details: {$errorData}";
        }

        throw new TransportException(
            "JSON-RPC error: {$errorMessage} (code: {$errorCode}){$detailsSuffix}"
        );
    }

    /**
     * Extracts the session ID returned as a header from the given response
     *
     * @param \Illuminate\Http\Client\Response $response
     * @return void
     */
    protected function extractSessionIdFromResponse(Response $response): void
    {
        $mcpHeader = $response->header(self::MCP_SESSION_ID_HEADER);

        if (!empty($mcpHeader)) {
            $this->sessionId = (string)$mcpHeader;
        }
    }
}
