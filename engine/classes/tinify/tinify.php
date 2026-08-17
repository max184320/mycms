<?php

declare(strict_types=1);

namespace Tinify;

function runtimeException(string $message = '', string $type = '', ?int $status = null): \RuntimeException
{
    if ($message === '') {
        $message = 'No message was provided';
    }

    if ($status !== null && $type !== '') {
        $message .= ' (HTTP ' . $status . '/' . $type . ')';
    }

    return new \RuntimeException($message);
}

final class Tinify
{
    private static ?string $key = null;
    private static ?Client $client = null;
    private static ?int $compressionCount = null;

    public static function setKey(string $key): void
    {
        self::$key = trim($key);
        self::$client = null;
    }

    public static function getCompressionCount(): ?int
    {
        return self::$compressionCount;
    }

    public static function setCompressionCount(?int $compressionCount): void
    {
        self::$compressionCount = $compressionCount;
    }

    public static function getClient(): Client
    {
        if (self::$key === null || self::$key === '') {
            throw runtimeException('Provide an API key with Tinify\setKey(...)');
        }

        if (self::$client === null) {
            self::$client = new Client(self::$key);
        }

        return self::$client;
    }

    public static function setClient(Client $client): void
    {
        self::$client = $client;
    }
}

final class Client
{
    private const API_ENDPOINT = 'https://api.tinify.com';

    private string $key;
    private string $endpoint;

    public function __construct(string $key, ?string $endpoint = null)
    {
        $this->key = trim($key);
        $this->endpoint = rtrim((string) ($endpoint ?? self::API_ENDPOINT), '/');

        if (!function_exists('curl_init')) {
            throw runtimeException('cURL extension is required to use Tinify.');
        }
    }

    public function request(string $method, string $url, $body = null): object
    {
        $request = curl_init();

        if ($request === false) {
            throw runtimeException('Unable to initialize cURL.');
        }

        $headers = [];

        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($body === false) {
                throw runtimeException('Unable to encode Tinify request body.');
            }

            $headers[] = 'Content-Type: application/json';
        }

        if ($body !== null && !is_string($body)) {
            throw runtimeException('Tinify request body must be a string or array.');
        }

        $target = preg_match('#^https?://#i', $url) ? $url : $this->endpoint . '/' . ltrim($url, '/');
        
        $curl_version = curl_version();

        curl_setopt_array($request, [
            CURLOPT_URL => $target,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_USERPWD => 'api:' . $this->key,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Tinify/1.0.0 PHP/' . PHP_VERSION . " curl/" . $curl_version["version"],
        ]);

        if ($headers !== []) {
            curl_setopt($request, CURLOPT_HTTPHEADER, $headers);
        }

        if ($body !== null && $body !== '') {
            curl_setopt($request, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($request);

        if (!is_string($response)) {
            $message = curl_error($request);
            $code = curl_errno($request);
            throw runtimeException('Error while connecting: ' . $message . ' (#' . $code . ')');
        }

        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($request, CURLINFO_HEADER_SIZE);

        $responseHeaders = $this->parseHeaders(substr($response, 0, $headerSize));
        $responseBody = substr($response, $headerSize);

        if (isset($responseHeaders['compression-count'])) {
            Tinify::setCompressionCount((int) $responseHeaders['compression-count']);
        }

        if ($status >= 200 && $status <= 299) {
            return (object) [
                'headers' => $responseHeaders,
                'body' => $responseBody,
                'status' => $status,
            ];
        }

        $details = json_decode($responseBody, true);

        if (!is_array($details)) {
            throw runtimeException('Unexpected Tinify response.', 'ParseError', $status);
        }

        throw runtimeException((string) ($details['message'] ?? ''), (string) ($details['error'] ?? ''), $status);
    }

    private function parseHeaders(string $headers): array
    {
        $result = [];

        foreach (preg_split('/\r\n|\r|\n/', $headers) as $header) {
            if ($header === '' || strpos($header, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $result[strtolower(trim($name))] = trim($value);
        }

        return $result;
    }
}

final class Source
{
    private string $url;
    private array $commands;

    public static function fromFile(string $path): self
    {
        $buffer = @file_get_contents($path);

        if ($buffer === false) {
            throw runtimeException(sprintf('Unable to read file (%s).', $path));
        }

        return self::fromBuffer($buffer);
    }

    public static function fromBuffer(string $buffer): self
    {
        $response = Tinify::getClient()->request('post', '/shrink', $buffer);

        return new self(self::extractLocation($response->headers));
    }

    public static function fromUrl(string $url): self
    {
        $response = Tinify::getClient()->request('post', '/shrink', [
            'source' => ['url' => $url],
        ]);

        return new self(self::extractLocation($response->headers));
    }

    public function __construct(string $url, array $commands = [])
    {
        $this->url = $url;
        $this->commands = $commands;
    }

    public function resize(array $options): self
    {
        return new self($this->url, array_merge($this->commands, ['resize' => $options]));
    }

    public function toBuffer(): string
    {
        $response = Tinify::getClient()->request(
            $this->commands === [] ? 'get' : 'post',
            $this->url,
            $this->commands === [] ? null : $this->commands
        );

        return $response->body;
    }

    private static function extractLocation(array $headers): string
    {
        if (empty($headers['location']) || !is_string($headers['location'])) {
            throw runtimeException('Tinify response does not contain a result URL.');
        }

        return $headers['location'];
    }
}
