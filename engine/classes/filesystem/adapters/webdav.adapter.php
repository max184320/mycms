<?php

declare(strict_types=1);

namespace DleFilesystem\WebDAV {

    class Client
    {
        private string $baseUri;
        private string $basePath;
        private ?string $userName;
        private ?string $password;
        private int $connectTimeout;
        private int $timeout;

        public function __construct(array $options = [])
        {
            $this->baseUri = rtrim((string) ($options['baseUri'] ?? ''), '/') . '/';
            $this->basePath = rtrim((string) parse_url($this->baseUri, PHP_URL_PATH), '/');
            $this->userName = isset($options['userName']) ? (string) $options['userName'] : null;
            $this->password = isset($options['password']) ? (string) $options['password'] : null;
            $this->connectTimeout = max(15, (int) ($options['connectTimeout'] ?? $options['connect_timeout'] ?? 15));
            $this->timeout = max(300, (int) ($options['timeout'] ?? $options['requestTimeout'] ?? $options['request_timeout'] ?? 300));
        }

        public function getAbsoluteUrl(string $path): string
        {
            return $this->baseUri . ltrim($path, '/');
        }

        public function request(string $method, string $path, $body = null, array $headers = []): array
        {
            $responseHeaders = [];
            $ch = $this->createCurlHandle($method, $path, $headers, $responseHeaders);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($responseBody === false || $error !== '') {
                throw new \RuntimeException('WebDAV request failed: ' . $error);
            }

            return [
                'statusCode' => $statusCode,
                'headers' => $responseHeaders,
                'body' => (string) $responseBody,
            ];
        }

        public function requestToStream(string $method, string $path, $stream, array $headers = []): array
        {
            if (!is_resource($stream)) {
                throw new \RuntimeException('Invalid target stream');
            }

            $responseHeaders = [];
            $ch = $this->createCurlHandle($method, $path, $headers, $responseHeaders);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $stream);

            $result = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($result === false || $error !== '') {
                throw new \RuntimeException('WebDAV request failed: ' . $error);
            }

            return [
                'statusCode' => $statusCode,
                'headers' => $responseHeaders,
                'body' => '',
            ];
        }

        public function requestWithStream(string $method, string $path, $stream, ?int $size = null, array $headers = []): array
        {
            if (!is_resource($stream)) {
                throw new \RuntimeException('Invalid source stream');
            }

            $responseHeaders = [];
            $ch = $this->createCurlHandle($method, $path, $headers, $responseHeaders);

            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $stream);

            if ($size !== null && $size >= 0) {
                curl_setopt($ch, CURLOPT_INFILESIZE, $size);
            }

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($responseBody === false || $error !== '') {
                throw new \RuntimeException('WebDAV request failed: ' . $error);
            }

            return [
                'statusCode' => $statusCode,
                'headers' => $responseHeaders,
                'body' => (string) $responseBody,
            ];
        }

        public function propFind(string $path, array $properties, int $depth = 0): array
        {
            $body = $this->buildPropfindBody($properties);
            $response = $this->request('PROPFIND', $path, $body, [
                'Depth' => (string) $depth,
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);

            if (!in_array($response['statusCode'], [200, 207], true)) {
                throw new \RuntimeException('Unexpected PROPFIND status code: ' . $response['statusCode']);
            }

            $items = $this->parsePropfindResponse((string) $response['body']);

            if ($depth === 0) {
                return $items[0]['props'] ?? [];
            }

            $result = [];

            foreach ($items as $item) {
                $result[$item['href']] = $item['props'];
            }

            return $result;
        }

        private function buildPropfindBody(array $properties): string
        {
            $xml = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop>';

            foreach ($properties as $property) {
                $property = (string) $property;

                if (strpos($property, '{DAV:}') === 0) {
                    $xml .= '<d:' . substr($property, 6) . '/>';
                }
            }

            return $xml . '</d:prop></d:propfind>';
        }

        private function parsePropfindResponse(string $xml): array
        {
            $result = [];
            $document = @simplexml_load_string($xml);

            if ($document === false) {
                return $result;
            }

            $namespaces = $document->getNamespaces(true);
            $dav = $namespaces['d'] ?? $namespaces['D'] ?? 'DAV:';

            foreach ($document->children($dav)->response as $response) {
                $href = rawurldecode((string) $response->href);
                $props = [];

                foreach ($response->propstat as $propstat) {
                    $prop = $propstat->prop;

                    foreach ($prop->children($dav) as $name => $value) {
                        $key = '{DAV:}' . $name;

                        if ($name === 'resourcetype') {
                            $children = $value->children($dav);
                            $props[$key] = [
                                'collection' => isset($children->collection),
                            ];
                        } else {
                            $props[$key] = (string) $value;
                        }
                    }
                }

                $result[] = [
                    'href' => $this->normalizeHref($href),
                    'props' => $props,
                ];
            }

            return $result;
        }

        private function normalizeHref(string $href): string
        {
            $path = (string) parse_url($href, PHP_URL_PATH);

            if ($this->basePath !== '' && ($path === $this->basePath || strpos($path, $this->basePath . '/') === 0)) {
                $path = substr($path, strlen($this->basePath));
            }

            return ltrim($path, '/');
        }

        private function createCurlHandle(string $method, string $path, array $headers, array &$responseHeaders)
        {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('cURL extension is required for WebDAV adapter');
            }

            $curlHeaders = [];

            foreach ($headers as $name => $value) {
                $curlHeaders[] = $name . ': ' . $value;
            }

            $ch = curl_init($this->getAbsoluteUrl($path));

            if ($ch === false) {
                throw new \RuntimeException('Unable to initialize cURL');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$responseHeaders): int {
                    $length = strlen($headerLine);
                    $headerLine = trim($headerLine);

                    if ($headerLine === '' || strpos($headerLine, ':') === false) {
                        return $length;
                    }

                    list($name, $value) = explode(':', $headerLine, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);

                    return $length;
                },
            ]);

            if ($this->userName !== null) {
                curl_setopt($ch, CURLOPT_USERPWD, $this->userName . ':' . ($this->password ?? ''));
            }

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }

            return $ch;
        }
    }
}

namespace DleFilesystem\Adapters {

    use DleFilesystem\Config;
    use DleFilesystem\DirectoryAttributes;
    use DleFilesystem\FileAttributes;
    use DleFilesystem\FilesystemAdapter;
    use DleFilesystem\PathPrefixer;
    use DleFilesystem\FilesystemException;
    use DleFilesystem\UrlGeneration\PublicUrlGenerator;
    use DleFilesystem\WebDAV\Client;
    use Throwable;

    class WebDAVAdapter implements FilesystemAdapter, PublicUrlGenerator
    {
        public const ON_VISIBILITY_THROW_ERROR = 'throw';
        public const ON_VISIBILITY_IGNORE = 'ignore';

        private const PROPERTY_DISPLAY_NAME = '{DAV:}displayname';
        private const PROPERTY_CONTENT_LENGTH = '{DAV:}getcontentlength';
        private const PROPERTY_CONTENT_TYPE = '{DAV:}getcontenttype';
        private const PROPERTY_LAST_MODIFIED = '{DAV:}getlastmodified';
        private const PROPERTY_RESOURCE_TYPE = '{DAV:}resourcetype';

        private Client $client;
        private PathPrefixer $prefixer;
        private string $visibilityHandling;
        private bool $manualCopy;
        private bool $manualMove;
        private array $temporaryStreamFiles = [];

        public static function create(array $clientOptions, string $prefix = '', string $visibilityHandling = self::ON_VISIBILITY_THROW_ERROR, bool $manualCopy = true, bool $manualMove = false): self
        {
            return new self(new Client($clientOptions), $prefix, $visibilityHandling, $manualCopy, $manualMove);
        }

        public function __construct(Client $client, string $prefix = '', string $visibilityHandling = self::ON_VISIBILITY_THROW_ERROR, bool $manualCopy = true, bool $manualMove = false)
        {
            $this->client = $client;
            $this->prefixer = new PathPrefixer($prefix);
            $this->visibilityHandling = $visibilityHandling;
            $this->manualCopy = $manualCopy;
            $this->manualMove = $manualMove;
        }

        public function __destruct()
        {
            $this->clearTemporaryStreamFiles();
        }

        public function fileExists(string $path): bool
        {
            try {
                $properties = $this->client->propFind($this->encodePath($this->prefixer->prefixPath($path)), [self::PROPERTY_RESOURCE_TYPE], 0);

                return !$this->propsIsDirectory($properties);
            } catch (Throwable $exception) {
                return false;
            }
        }

        public function directoryExists(string $path): bool
        {
            try {
                $properties = $this->client->propFind($this->encodePath($this->prefixer->prefixPath($path)), [self::PROPERTY_RESOURCE_TYPE], 0);

                return $this->propsIsDirectory($properties);
            } catch (Throwable $exception) {
                if ($this->isNotFound($exception)) {
                    return false;
                }

                throw FilesystemException::forLocation($path, $exception);
            }
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->upload($path, $contents);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            if (!is_resource($contents)) {
                throw FilesystemException::atLocation($path, 'invalid stream resource');
            }

            $this->uploadStream($path, $contents);
        }

        public function read(string $path): string
        {
            try {
                $response = $this->client->request('GET', $this->encodePath($this->prefixer->prefixPath($path)));

                if ($response['statusCode'] !== 200) {
                    throw new \RuntimeException('Unexpected GET status code: ' . $response['statusCode']);
                }

                return (string) $response['body'];
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function readStream(string $path)
        {
            list($stream, $tmpFile) = $this->createTemporaryStream('wdr');

            if ($stream === false) {
                throw FilesystemException::fromLocation($path, 'failed to create temp stream');
            }

            try {
                $response = $this->client->requestToStream('GET', $this->encodePath($this->prefixer->prefixPath($path)), $stream);

                if ($response['statusCode'] !== 200) {
                    throw new \RuntimeException('Unexpected GET status code: ' . $response['statusCode']);
                }

                if (!rewind($stream)) {
                    throw new \RuntimeException('Unable to rewind temporary stream');
                }

                return $stream;
            } catch (Throwable $exception) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw $exception;
            }
        }

        public function delete(string $path): void
        {
            try {
                $response = $this->client->request('DELETE', $this->encodePath($this->prefixer->prefixPath($path)));

                if ($response['statusCode'] !== 404 && ($response['statusCode'] < 200 || $response['statusCode'] >= 300)) {
                    throw new \RuntimeException('Unexpected DELETE status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function deleteDirectory(string $path): void
        {
            $path = trim($path, '/');

            if ($path === '') {
                throw FilesystemException::atLocation($path, 'refusing to delete root directory');
            }

            try {
                $response = $this->client->request('DELETE', $this->encodePath($this->prefixer->prefixDirectoryPath($path)));

                if ($response['statusCode'] !== 404 && ($response['statusCode'] < 200 || $response['statusCode'] >= 300)) {
                    throw new \RuntimeException('Unexpected DELETE status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        public function createDirectory(string $path, Config $config): void
        {
            $parts = explode('/', trim($this->prefixer->prefixDirectoryPath($path), '/'));
            $current = '';

            foreach ($parts as $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '' ? $segment : $current . '/' . $segment;
                $encoded = $this->encodePath($current) . '/';

                if ($this->directoryExists($this->prefixer->stripPrefix($current))) {
                    continue;
                }

                try {
                    $response = $this->client->request('MKCOL', $encoded);

                    if (!in_array($response['statusCode'], [201, 405], true)) {
                        throw new \RuntimeException('Unexpected MKCOL status code: ' . $response['statusCode']);
                    }
                } catch (Throwable $exception) {
                    throw FilesystemException::dueToFailure($path, $exception);
                }
            }
        }

        public function setVisibility(string $path, string $visibility): void
        {
            if ($this->visibilityHandling === self::ON_VISIBILITY_THROW_ERROR) {
                throw FilesystemException::atLocation($path, 'WebDAV does not support visibility changes');
            }
        }

        public function visibility(string $path): FileAttributes
        {
            throw FilesystemException::visibility($path, 'WebDAV does not support visibility metadata');
        }

        public function mimeType(string $path): FileAttributes
        {
            return new FileAttributes($path, null, null, null, (string) $this->propFind($path, self::PROPERTY_CONTENT_TYPE, 'mime_type'));
        }

        public function lastModified(string $path): FileAttributes
        {
            $lastModified = strtotime((string) $this->propFind($path, self::PROPERTY_LAST_MODIFIED, 'last_modified'));

            return new FileAttributes($path, null, null, $lastModified !== false ? $lastModified : null);
        }

        public function fileSize(string $path): FileAttributes
        {
            return new FileAttributes($path, (int) $this->propFind($path, self::PROPERTY_CONTENT_LENGTH, 'file_size'));
        }

        public function listContents(string $path, bool $deep): iterable
        {
            $path = trim($path, '/');
            $response = $this->client->propFind($this->encodePath($this->prefixer->prefixDirectoryPath($path)), [
                self::PROPERTY_DISPLAY_NAME,
                self::PROPERTY_CONTENT_LENGTH,
                self::PROPERTY_CONTENT_TYPE,
                self::PROPERTY_LAST_MODIFIED,
                self::PROPERTY_RESOURCE_TYPE,
            ], 1);

            foreach ($response as $itemPath => $properties) {
                $decodedPath = $this->normalizeResponsePath($itemPath);

                if ($decodedPath === $path) {
                    continue;
                }

                if ($this->propsIsDirectory($properties)) {
                    yield new DirectoryAttributes($decodedPath, null, $this->lastModifiedFromProperties($properties));

                    if ($deep) {
                        yield from $this->listContents($decodedPath, true);
                    }

                    continue;
                } else {
                    yield new FileAttributes(
                        $decodedPath,
                        isset($properties[self::PROPERTY_CONTENT_LENGTH]) ? (int) $properties[self::PROPERTY_CONTENT_LENGTH] : null,
                        null,
                        $this->lastModifiedFromProperties($properties),
                        isset($properties[self::PROPERTY_CONTENT_TYPE]) ? (string) $properties[self::PROPERTY_CONTENT_TYPE] : null
                    );
                }
            }
        }

        public function move(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            if ($this->manualMove) {
                $this->manualMove($source, $destination);
                return;
            }

            $this->createParentDirFor($destination);

            try {
                $response = $this->client->request('MOVE', $this->encodePath($this->prefixer->prefixPath($source)), null, [
                    'Destination' => $this->client->getAbsoluteUrl($this->encodePath($this->prefixer->prefixPath($destination))),
                    'Overwrite' => 'T',
                ]);

                if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
                    throw new \RuntimeException('Unexpected MOVE status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            }
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            if ($this->manualCopy) {
                $this->manualCopy($source, $destination);
                return;
            }

            $this->createParentDirFor($destination);

            try {
                $response = $this->client->request('COPY', $this->encodePath($this->prefixer->prefixPath($source)), null, [
                    'Destination' => $this->client->getAbsoluteUrl($this->encodePath($this->prefixer->prefixPath($destination))),
                    'Overwrite' => 'T',
                ]);

                if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
                    throw new \RuntimeException('Unexpected COPY status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            }
        }

        public function publicUrl(string $path, Config $config): string
        {
            return $this->client->getAbsoluteUrl($this->encodePath($this->prefixer->prefixPath($path)));
        }

        private function upload(string $path, $contents): void
        {
            $this->createParentDirFor($path);

            try {
                $response = $this->client->request('PUT', $this->encodePath($this->prefixer->prefixPath($path)), $contents);

                if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
                    throw new \RuntimeException('Unexpected PUT status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            }
        }

        private function uploadStream(string $path, $contents): void
        {
            $this->createParentDirFor($path);
            list($stream, $size, $closeStream) = $this->makeSeekableStream($contents);

            try {
                $response = $this->client->requestWithStream('PUT', $this->encodePath($this->prefixer->prefixPath($path)), $stream, $size);

                if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
                    throw new \RuntimeException('Unexpected PUT status code: ' . $response['statusCode']);
                }
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($path, $exception->getMessage(), $exception);
            } finally {
                if ($closeStream) {
                    $this->closeTemporaryStream($stream, null);
                }
            }
        }

        private function makeSeekableStream($contents): array
        {
            $meta = stream_get_meta_data($contents);

            if (($meta['seekable'] ?? false) && @rewind($contents)) {
                $stat = fstat($contents);

                return [$contents, isset($stat['size']) ? (int) $stat['size'] : null, false];
            }

            list($stream, $tmpFile) = $this->createTemporaryStream('wdu');

            if ($stream === false) {
                throw new \RuntimeException('Unable to create temporary stream');
            }

            if (stream_copy_to_stream($contents, $stream) === false) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw new \RuntimeException('Unable to buffer stream');
            }

            if (!rewind($stream)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw new \RuntimeException('Unable to rewind temporary stream');
            }

            $stat = fstat($stream);

            return [$stream, isset($stat['size']) ? (int) $stat['size'] : null, true];
        }

        private function createTemporaryStream(string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                return [$stream, null];
            }

            $tmpFile = $this->createTemporaryCacheFile($prefix);

            if ($tmpFile === false) {
                return [false, null];
            }

            $stream = @fopen($tmpFile, 'w+b');

            if ($stream === false) {
                @unlink($tmpFile);
                return [false, null];
            }

            $this->temporaryStreamFiles[(int) $stream] = $tmpFile;

            return [$stream, $tmpFile];
        }

        private function closeTemporaryStream($stream, ?string $tmpFile): void
        {
            $streamKey = is_resource($stream) ? (int) $stream : null;

            if ($tmpFile === null && $streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                $tmpFile = $this->temporaryStreamFiles[$streamKey];
            }

            if (is_resource($stream)) {
                @fclose($stream);
            }

            if ($tmpFile !== null && $tmpFile !== '') {
                @unlink($tmpFile);
                $this->forgetTemporaryStreamFile($tmpFile, $streamKey);
            }
        }

        private function forgetTemporaryStreamFile(string $tmpFile, ?int $streamKey = null): void
        {
            if ($streamKey !== null && isset($this->temporaryStreamFiles[$streamKey])) {
                unset($this->temporaryStreamFiles[$streamKey]);
                return;
            }

            $key = array_search($tmpFile, $this->temporaryStreamFiles, true);

            if ($key !== false) {
                unset($this->temporaryStreamFiles[$key]);
            }
        }

        private function clearTemporaryStreamFiles(): void
        {
            foreach ($this->temporaryStreamFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            $this->temporaryStreamFiles = [];
        }

        private function temporaryCacheDirectory(): string
        {
            return rtrim(str_replace('\\', '/', \ENGINE_DIR), '/') . '/cache/system';
        }

        private function createTemporaryCacheFile(string $prefix)
        {
            $directory = $this->temporaryCacheDirectory();

            if (!is_dir($directory) || !is_writable($directory)) {
                return false;
            }

            $tmpFile = @tempnam($directory . '/', $prefix);

            if ($tmpFile === false) {
                return false;
            }

            $tmpFile = str_replace('\\', '/', $tmpFile);

            if (strpos($tmpFile, $directory . '/') !== 0) {
                @unlink($tmpFile);
                return false;
            }

            return $tmpFile;
        }

        private function encodePath(string $path): string
        {
            $parts = explode('/', $path);

            foreach ($parts as &$part) {
                $part = rawurlencode($part);
            }

            return implode('/', $parts);
        }

        private function propsIsDirectory(array $properties): bool
        {
            return !empty($properties[self::PROPERTY_RESOURCE_TYPE]['collection']);
        }

        private function isNotFound(Throwable $exception): bool
        {
            return strpos($exception->getMessage(), '404') !== false;
        }

        private function createParentDirFor(string $path): void
        {
            $parent = dirname($path);

            if ($parent === '' || $parent === '.' || $this->directoryExists($parent)) {
                return;
            }

            $this->createDirectory($parent, new Config());
        }

        private function normalizeResponsePath(string $path): string
        {
            $path = ltrim((string) parse_url($path, PHP_URL_PATH), '/');

            return trim($this->prefixer->stripPrefix($path), '/');
        }

        private function lastModifiedFromProperties(array $properties): ?int
        {
            if (!isset($properties[self::PROPERTY_LAST_MODIFIED])) {
                return null;
            }

            $timestamp = strtotime((string) $properties[self::PROPERTY_LAST_MODIFIED]);

            return $timestamp !== false ? $timestamp : null;
        }

        private function propFind(string $path, string $property, string $section)
        {
            try {
                $result = $this->client->propFind($this->encodePath($this->prefixer->prefixPath($path)), [$property], 0);

                if (!array_key_exists($property, $result)) {
                    throw new \RuntimeException('Missing property ' . $property);
                }

                return $result[$property];
            } catch (Throwable $exception) {
                throw FilesystemException::create($path, $section, $exception->getMessage(), $exception);
            }
        }

        private function manualMove(string $source, string $destination): void
        {
            $stream = null;

            try {
                $stream = $this->readStream($source);
                $this->writeStream($destination, $stream, new Config());
                $this->delete($source);
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            } finally {
                if (is_resource($stream)) {
                    $this->closeTemporaryStream($stream, null);
                }
            }
        }

        private function manualCopy(string $source, string $destination): void
        {
            $stream = null;

            try {
                $stream = $this->readStream($source);
                $this->writeStream($destination, $stream, new Config());
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            } finally {
                if (is_resource($stream)) {
                    $this->closeTemporaryStream($stream, null);
                }
            }
        }
    }
}
