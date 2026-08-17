<?php

declare(strict_types=1);

namespace DleFilesystem\Adapters {

    use DateTime;
    use DleFilesystem\Config;
    use DleFilesystem\DirectoryAttributes;
    use DleFilesystem\FileAttributes;
    use DleFilesystem\FilesystemAdapter;
    use DleFilesystem\FilesystemException;
    use DleFilesystem\MimeTypeDetection\FinfoMimeTypeDetector;
    use DleFilesystem\MimeTypeDetection\MimeTypeDetector;
    use DleFilesystem\PathPrefixer;
    use DleFilesystem\StorageAttributes;
    use DleFilesystem\Visibility\PortableVisibilityConverter;
    use DleFilesystem\Visibility\VisibilityConverter;
    use Throwable;

    class SftpConnectionProvider
    {
        private array $options;

        public function __construct(array $options = [])
        {
            $this->options = $options + [
                'host' => '',
                'root' => '',
                'username' => '',
                'password' => '',
                'privateKey' => null,
                'publicKey' => null,
                'passphrase' => null,
                'port' => 22,
                'timeout' => 30,
                'maxTries' => 4,
                'useAgent' => false,
                'hostFingerprint' => null,
                'preferredAlgorithms' => [],
                'disableStatCache' => true,
            ];
        }

        public static function fromArray(array $options): self
        {
            return new self($options);
        }

        public function host(): string
        {
            return trim((string) $this->options['host']);
        }

        public function root(): string
        {
            return trim((string) $this->options['root']);
        }

        public function username(): string
        {
            return (string) $this->options['username'];
        }

        public function password(): string
        {
            return (string) $this->options['password'];
        }

        public function privateKey(): ?string
        {
            $value = $this->options['privateKey'];

            return is_string($value) && $value !== '' ? $value : null;
        }

        public function publicKey(): ?string
        {
            $value = $this->options['publicKey'];

            return is_string($value) && $value !== '' ? $value : null;
        }

        public function passphrase(): ?string
        {
            $value = $this->options['passphrase'];

            return is_string($value) && $value !== '' ? $value : null;
        }

        public function port(): int
        {
            return max(1, (int) $this->options['port']);
        }

        public function timeout(): int
        {
            return max(30, (int) $this->options['timeout']);
        }

        public function maxTries(): int
        {
            return max(0, (int) $this->options['maxTries']);
        }

        public function useAgent(): bool
        {
            return (bool) $this->options['useAgent'];
        }

        public function hostFingerprint(): ?string
        {
            $value = $this->options['hostFingerprint'];

            return is_string($value) && $value !== '' ? $value : null;
        }

        public function preferredAlgorithms(): array
        {
            return is_array($this->options['preferredAlgorithms']) ? $this->options['preferredAlgorithms'] : [];
        }

        public function disableStatCache(): bool
        {
            return (bool) $this->options['disableStatCache'];
        }
    }

    class SftpAdapter implements FilesystemAdapter
    {
        private const MIME_TYPE_DETECTION_SAMPLE_SIZE = 65536;

        private SftpConnectionProvider $connectionProvider;
        private PathPrefixer $prefixer;
        private VisibilityConverter $visibilityConverter;
        private MimeTypeDetector $mimeTypeDetector;
        private bool $detectMimeTypeUsingPath;
        private bool $disconnectOnDestruct;
        private bool $rootPrepared = false;
        private $session = null;
        private $sftp = null;
        private $curlHandle = null;
        private array $temporaryKeyFiles = [];
        private array $temporaryStreamFiles = [];
        private array $resolvedKeyFiles = [];
        private array $curlListCache = [];
        private array $curlVerifiedDirectories = ['/'=> true];
        private ?string $curlKnownHostsFile = null;
        private bool $curlHostKeyFallbackAttempted = false;

        public static function create(array $options, ?VisibilityConverter $visibilityConverter = null, ?MimeTypeDetector $mimeTypeDetector = null, bool $detectMimeTypeUsingPath = false, bool $disconnectOnDestruct = true): self
        {
            return new self(
                SftpConnectionProvider::fromArray($options),
                $visibilityConverter,
                $mimeTypeDetector,
                $detectMimeTypeUsingPath,
                $disconnectOnDestruct
            );
        }

        public function __construct(SftpConnectionProvider $connectionProvider, ?VisibilityConverter $visibilityConverter = null, ?MimeTypeDetector $mimeTypeDetector = null, bool $detectMimeTypeUsingPath = false, bool $disconnectOnDestruct = true)
        {
            $this->connectionProvider = $connectionProvider;
            $this->prefixer = new PathPrefixer($connectionProvider->root());
            $this->visibilityConverter = $visibilityConverter ?? new PortableVisibilityConverter();
            $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
            $this->detectMimeTypeUsingPath = $detectMimeTypeUsingPath;
            $this->disconnectOnDestruct = $disconnectOnDestruct;

            if (!$this->usesSsh2()) {
                $this->ensureCurlSftpSupport();
            }
        }

        public function __destruct()
        {
            if ($this->disconnectOnDestruct) {
                $this->disconnect();
            }
        }

        public function fileExists(string $path): bool
        {
            try {
                return $this->fetchMetadata($path) instanceof FileAttributes;
            } catch (Throwable $exception) {
                return false;
            }
        }

        public function directoryExists(string $path): bool
        {
            $path = trim($path, '/');

            if ($path === '') {
                try {
                    $this->assertSafePath('', true);
                    return $this->configuredRootExists();
                } catch (Throwable $exception) {
                    return false;
                }
            }

            try {
                return $this->fetchMetadata($path) instanceof DirectoryAttributes;
            } catch (Throwable $exception) {
                return false;
            }
        }

        public function write(string $path, string $contents, Config $config): void
        {
            list($stream, $tmpFile) = $this->createTemporaryStreamFromContents($contents, 'sfw');

            if ($stream === false) {
                throw FilesystemException::atLocation($path, 'failed to create temp stream');
            }

            try {
                $this->writeStream($path, $stream, $config);
            } finally {
                $this->closeTemporaryStream($stream, $tmpFile);
            }
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            if (!is_resource($contents)) {
                throw FilesystemException::atLocation($path, 'invalid stream resource');
            }

            $this->assertSafePath($path, true);
            $this->ensureParentDirectoryExists($path, $config);
            $visibility = $config->get(Config::OPTION_VISIBILITY);
            $visibility = is_string($visibility) && $visibility !== '' ? $visibility : null;

            if ($this->usesSsh2()) {
                $this->writeStreamViaSsh2($path, $contents);
            } else {
                $this->writeStreamViaCurl($path, $contents, $visibility);
            }

            if ($visibility !== null && $this->usesSsh2()) {
                $this->setVisibility($path, $visibility);
            }
        }

        public function read(string $path): string
        {
            if ($this->usesSsh2()) {
                return $this->readViaSsh2($path);
            }

            return $this->readViaCurl($path);
        }

        public function readStream(string $path)
        {
            if ($this->usesSsh2()) {
                return $this->readStreamViaSsh2($path);
            }

            return $this->readStreamViaCurl($path);
        }

        public function delete(string $path): void
        {
            if (!$this->fileExists($path)) {
                return;
            }

            $this->assertSafePath($path);
            $this->deleteKnownFile($path);
        }

        private function deleteKnownFile(string $path): void
        {
            if ($this->usesSsh2()) {
                if (!@unlink($this->streamPath($path))) {
                    throw FilesystemException::atLocation($path, 'unlink failed');
                }

                return;
            }

            $this->executeCurlCommands(['rm ' . $this->curlCommandPath($this->remotePath($path))], $path);
            $this->clearCurlCache();
        }

        public function deleteDirectory(string $path): void
        {
            $path = trim($path, '/');

            if ($path === '') {
                throw FilesystemException::atLocation($path, 'refusing to delete root directory');
            }

            if (!$this->directoryExists($path)) {
                return;
            }

            $this->deleteDirectoryContents($path);
            $this->deleteEmptyDirectory($path);
            $this->clearCurlCache();
        }

        public function createDirectory(string $path, Config $config): void
        {
            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            $this->assertSafePath($path, true);

            if ($this->usesSsh2()) {
                $this->ensureDirectoryExistsViaSsh2($path, $visibility);
            } else {
                $this->ensureDirectoryExistsViaCurl($path, $visibility);
                $this->clearCurlCache();
            }
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $metadata = $this->fetchMetadata($path);
            $mode = $metadata->isDir()
                ? $this->visibilityConverter->forDirectory($visibility)
                : $this->visibilityConverter->forFile($visibility);

            if ($this->usesSsh2()) {
                if (!$this->applyVisibilityViaSsh2($this->remotePath($path), $mode)) {
                    throw FilesystemException::visibility($path, 'chmod failed');
                }

                return;
            }

            $this->executeCurlCommands(['chmod ' . decoct($mode) . ' ' . $this->curlCommandPath($this->remotePath($path))], $path);
            $this->clearCurlCache();
        }

        public function visibility(string $path): FileAttributes
        {
            $metadata = $this->fetchMetadata($path);

            if (!$metadata instanceof FileAttributes) {
                throw FilesystemException::visibility($path, 'file entry was not found');
            }

            return new FileAttributes($path, null, $metadata->visibility());
        }

        public function mimeType(string $path): FileAttributes
        {
            try {
                if ($this->detectMimeTypeUsingPath) {
                    $mimeType = $this->mimeTypeDetector->detectMimeTypeFromPath($path);
                } elseif ($this->mimeTypeDetector instanceof FinfoMimeTypeDetector) {
                    $mimeType = $this->mimeTypeDetector->detectMimeType($path, $this->readMimeTypeDetectionBuffer($path));
                } else {
                    $mimeType = $this->mimeTypeDetector->detectMimeType($path, $this->read($path));
                }
            } catch (Throwable $exception) {
                throw FilesystemException::mimeType($path, $exception->getMessage(), $exception);
            }

            return new FileAttributes($path, null, null, null, $mimeType);
        }

        public function lastModified(string $path): FileAttributes
        {
            $metadata = $this->fetchMetadata($path);

            if (!$metadata instanceof FileAttributes) {
                throw FilesystemException::lastModified($path, 'file entry was not found');
            }

            return new FileAttributes($path, null, null, $metadata->lastModified());
        }

        public function fileSize(string $path): FileAttributes
        {
            $metadata = $this->fetchMetadata($path);

            if (!$metadata instanceof FileAttributes) {
                throw FilesystemException::fileSize($path, 'file entry was not found');
            }

            return new FileAttributes($path, $metadata->fileSize());
        }

        public function listContents(string $path, bool $deep): iterable
        {
            $path = trim($path, '/');
            $this->assertSafePath($path, true);

            return $this->usesSsh2()
                ? $this->listDirectoryViaSsh2($path, $deep)
                : $this->listDirectoryViaCurl($path, $deep);
        }

        public function move(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            $this->assertSafePath($source);
            $this->assertSafePath($destination, true);
            $this->ensureParentDirectoryExists($destination, $config);

            if ($this->fileExists($destination)) {
                $this->delete($destination);
            } elseif ($this->directoryExists($destination)) {
                $this->deleteDirectory($destination);
            }

            if ($this->usesSsh2()) {
                if (!@rename($this->streamPath($source), $this->streamPath($destination))) {
                    throw FilesystemException::fromLocationTo($source, $destination);
                }

                return;
            }

            try {
                $this->executeCurlCommands([
                    'rename ' . $this->curlCommandPath($this->remotePath($source)) . ' ' . $this->curlCommandPath($this->remotePath($destination)),
                ], $source);
                $this->clearCurlCache();
            } catch (Throwable $exception) {
                $this->copy($source, $destination, $config);
                $this->delete($source);
            }
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $stream = null;

            try {
                $stream = $this->readStream($source);

                if ($config->get(Config::OPTION_VISIBILITY) === null && $config->get(Config::OPTION_RETAIN_VISIBILITY, true)) {
                    $config = $config->withSetting(Config::OPTION_VISIBILITY, $this->visibility($source)->visibility());
                }

                $this->writeStream($destination, $stream, $config);
            } catch (Throwable $exception) {
                throw FilesystemException::fromLocationTo($source, $destination, $exception);
            } finally {
                if (is_resource($stream)) {
                    $this->closeTemporaryStream($stream, null);
                }
            }
        }

        public function disconnect(): void
        {
            $this->disconnectCurlHandle();
            $this->session = null;
            $this->sftp = null;
            $this->clearCurlCache();
            $this->curlVerifiedDirectories = ['/'=> true];
            $this->curlKnownHostsFile = null;
            $this->curlHostKeyFallbackAttempted = false;

            foreach ($this->temporaryKeyFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            foreach ($this->temporaryStreamFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            $this->temporaryKeyFiles = [];
            $this->temporaryStreamFiles = [];
            $this->resolvedKeyFiles = [];
        }

        private function usesSsh2(): bool
        {
            return function_exists('ssh2_connect') && function_exists('ssh2_sftp');
        }

        private function ensureCurlSftpSupport(): void
        {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('SFTP adapter requires ext-ssh2 or ext-curl with SFTP support');
            }

            $protocols = array_map('strtolower', curl_version()['protocols'] ?? []);

            if (!in_array('sftp', $protocols, true)) {
                throw new \RuntimeException('cURL SFTP protocol support is not available');
            }
        }

        private function fetchMetadata(string $path): StorageAttributes
        {
            $this->assertSafePath($path);

            return $this->usesSsh2()
                ? $this->fetchMetadataViaSsh2($path)
                : $this->fetchMetadataViaCurl($path);
        }

        private function fetchMetadataViaSsh2(string $path): StorageAttributes
        {
            $stat = $this->lstatSsh2($path);

            if ($stat === false) {
                throw FilesystemException::forLocation($path);
            }

            if ($this->isLinkStat($stat)) {
                throw FilesystemException::create($path, 'link', 'symbolic links are not allowed');
            }

            if ($this->isDirectoryStat($stat)) {
                return new DirectoryAttributes(
                    $path,
                    $this->visibilityConverter->inverseForDirectory((int) $stat['mode'] & 0777),
                    isset($stat['mtime']) ? (int) $stat['mtime'] : null
                );
            }

            return new FileAttributes(
                $path,
                isset($stat['size']) ? (int) $stat['size'] : null,
                $this->visibilityConverter->inverseForFile((int) $stat['mode'] & 0777),
                isset($stat['mtime']) ? (int) $stat['mtime'] : null
            );
        }

        private function fetchMetadataViaCurl(string $path): StorageAttributes
        {
            if ($path === '') {
                return new DirectoryAttributes('');
            }

            $remotePath = $this->remotePath($path);
            $parentPath = dirname($remotePath);
            $entries = $this->listAbsoluteDirectoryViaCurl($parentPath === '/' ? '' : $parentPath, false);
            $name = basename($remotePath);

            if (!isset($entries[$name])) {
                throw FilesystemException::forLocation($path);
            }

            $entry = $entries[$name];

            if ($entry['type'] === 'link') {
                throw FilesystemException::create($path, 'link', 'symbolic links are not allowed');
            }

            if ($entry['type'] === StorageAttributes::TYPE_DIRECTORY) {
                return new DirectoryAttributes(
                    $path,
                    $entry['visibility'],
                    $entry['lastModified']
                );
            }

            return new FileAttributes(
                $path,
                $entry['fileSize'],
                $entry['visibility'],
                $entry['lastModified']
            );
        }

        private function readViaSsh2(string $path): string
        {
            $this->assertSafePath($path);
            $stream = $this->withSocketTimeout(function () use ($path) {
                return @fopen($this->streamPath($path), 'rb');
            });

            if ($stream === false) {
                throw FilesystemException::fromLocation($path, 'unable to open stream');
            }

            $this->applyStreamTimeout($stream);

            try {
                $contents = stream_get_contents($stream);
                $this->assertStreamDidNotTimeout($stream, $path, 'read timed out');
            } finally {
                $this->closeTemporaryStream($stream, null);
            }

            if ($contents === false) {
                throw FilesystemException::fromLocation($path, 'stream_get_contents failed');
            }

            return $contents;
        }

        private function readViaCurl(string $path): string
        {
            $this->assertSafePath($path);
            list($result) = $this->executeCurl($this->curlUrlForPath($this->remotePath($path)));

            if (!is_string($result)) {
                throw FilesystemException::fromLocation($path, 'unexpected read response');
            }

            return $result;
        }

        private function readStreamViaSsh2(string $path)
        {
            $this->assertSafePath($path);
            $stream = $this->withSocketTimeout(function () use ($path) {
                return @fopen($this->streamPath($path), 'rb');
            });

            if ($stream === false) {
                throw FilesystemException::fromLocation($path, 'unable to open stream');
            }

            $this->applyStreamTimeout($stream);

            return $stream;
        }

        private function readStreamViaCurl(string $path)
        {
            $this->assertSafePath($path);
            list($stream, $tmpFile) = $this->createTemporaryStream('sfr');

            if ($stream === false) {
                throw FilesystemException::fromLocation($path, 'unable to create temp stream');
            }

            try {
                $this->executeCurl($this->curlUrlForPath($this->remotePath($path)), [
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($stream): int {
                        $written = fwrite($stream, $data);

                        return $written === false ? 0 : $written;
                    },
                ], $path);
            } catch (Throwable $exception) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw $exception;
            }

            if (!rewind($stream)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw FilesystemException::fromLocation($path, 'unable to rewind temp stream');
            }

            return $stream;
        }

        private function readMimeTypeDetectionBuffer(string $path): string
        {
            if (!$this->usesSsh2() && defined('CURLOPT_RANGE')) {
                try {
                    list($result) = $this->executeCurl($this->curlUrlForPath($this->remotePath($path)), [
                        CURLOPT_RANGE => '0-' . (self::MIME_TYPE_DETECTION_SAMPLE_SIZE - 1),
                    ], $path);

                    if (is_string($result)) {
                        return substr($result, 0, self::MIME_TYPE_DETECTION_SAMPLE_SIZE);
                    }
                } catch (Throwable $exception) {
                    return $this->read($path);
                }
            }

            $stream = $this->readStream($path);

            if (!is_resource($stream)) {
                throw FilesystemException::fromLocation($path, 'invalid stream received');
            }

            $buffer = '';

            try {
                while (!feof($stream) && strlen($buffer) < self::MIME_TYPE_DETECTION_SAMPLE_SIZE) {
                    $chunk = fread($stream, self::MIME_TYPE_DETECTION_SAMPLE_SIZE - strlen($buffer));

                    if ($chunk === false) {
                        throw FilesystemException::fromLocation($path, 'failed to read mime type sample');
                    }

                    if ($chunk === '') {
                        break;
                    }

                    $buffer .= $chunk;
                }

                $this->assertStreamDidNotTimeout($stream, $path, 'read timed out');
            } finally {
                $this->closeTemporaryStream($stream, null);
            }

            return $buffer;
        }

        private function writeStreamViaSsh2(string $path, $contents): void
        {
            $this->ensureConfiguredRootExists();
            $size = null;
            $meta = stream_get_meta_data($contents);

            if (($meta['seekable'] ?? false) && @rewind($contents)) {
                $stat = fstat($contents);
                $size = isset($stat['size']) ? (int) $stat['size'] : null;
            }

            $target = $this->withSocketTimeout(function () use ($path) {
                return @fopen($this->streamPath($path), 'wb');
            });

            if ($target === false) {
                throw FilesystemException::atLocation($path, 'unable to open target stream');
            }

            $this->applyStreamTimeout($target);

            try {
                $bytesCopied = stream_copy_to_stream($contents, $target);

                if ($bytesCopied === false) {
                    throw FilesystemException::atLocation($path, 'failed to write stream');
                }

                $this->assertStreamDidNotTimeout($target, $path, 'write timed out');

                if (is_int($size) && $size >= 0 && $bytesCopied < $size) {
                    throw FilesystemException::atLocation($path, 'failed to write complete stream');
                }
            } finally {
                fclose($target);
            }
        }

        private function writeStreamViaCurl(string $path, $contents, ?string $visibility = null): void
        {
            $this->ensureConfiguredRootExists();
            list($stream, $closeStream, $tmpFile) = $this->makeSeekableStream($contents);
            $size = fstat($stream)['size'] ?? null;

            try {
                $options = [
                    CURLOPT_UPLOAD => true,
                    CURLOPT_INFILE => $stream,
                    CURLOPT_RETURNTRANSFER => true,
                ];

                if (is_int($size) && $size >= 0) {
                    $options[CURLOPT_INFILESIZE] = $size;
                }

                if ($visibility !== null && defined('CURLOPT_POSTQUOTE')) {
                    $options[CURLOPT_POSTQUOTE] = [
                        'chmod ' . decoct($this->visibilityConverter->forFile($visibility)) . ' ' . $this->curlCommandPath($this->remotePath($path)),
                    ];
                }

                $this->executeCurl($this->curlUrlForPath($this->remotePath($path)), $options, $path);
                $this->clearCurlCache();
            } finally {
                if ($closeStream) {
                    $this->closeTemporaryStream($stream, $tmpFile);
                }
            }
        }

        private function listDirectoryViaSsh2(string $path, bool $deep): iterable
        {
            $directory = $this->streamDirectoryPath($path);

            if (!@is_dir($directory)) {
                return;
            }

            $handle = @opendir($directory);

            if ($handle === false) {
                throw FilesystemException::atLocation($path, 'opendir failed');
            }

            try {
                while (($name = readdir($handle)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }

                    $itemPath = $path === '' ? $name : $path . '/' . $name;
                    $stat = $this->lstatSsh2($itemPath);

                    if ($stat === false) {
                        continue;
                    }

                    if ($this->isLinkStat($stat)) {
                        throw FilesystemException::create($itemPath, 'link', 'symbolic links are not allowed');
                    }

                    if ($this->isDirectoryStat($stat)) {
                        yield new DirectoryAttributes(
                            $itemPath,
                            $this->visibilityConverter->inverseForDirectory((int) $stat['mode'] & 0777),
                            isset($stat['mtime']) ? (int) $stat['mtime'] : null
                        );

                        if ($deep) {
                            yield from $this->listDirectoryViaSsh2($itemPath, true);
                        }

                        continue;
                    }

                    yield new FileAttributes(
                        $itemPath,
                        isset($stat['size']) ? (int) $stat['size'] : null,
                        $this->visibilityConverter->inverseForFile((int) $stat['mode'] & 0777),
                        isset($stat['mtime']) ? (int) $stat['mtime'] : null
                    );
                }
            } finally {
                closedir($handle);
            }
        }

        private function listDirectoryViaCurl(string $path, bool $deep): iterable
        {
            $remotePath = $this->remotePath($path);
            $entries = $this->listAbsoluteDirectoryViaCurl($remotePath === '/' ? '' : $remotePath, true);
            $cacheKey = $remotePath === '' ? '/' : $remotePath;

            try {
                foreach ($entries as $entry) {
                    $relativePath = $this->stripRemotePrefix($entry['remotePath']);

                    if ($entry['type'] === StorageAttributes::TYPE_DIRECTORY) {
                        yield new DirectoryAttributes($relativePath, $entry['visibility'], $entry['lastModified']);

                        if ($deep) {
                            yield from $this->listDirectoryViaCurl($relativePath, true);
                        }

                        continue;
                    }

                    yield new FileAttributes($relativePath, $entry['fileSize'], $entry['visibility'], $entry['lastModified']);
                }
            } finally {
                if ($deep) {
                    unset($this->curlListCache[$cacheKey]);
                }
            }
        }

        private function deleteDirectoryContents(string $path): void
        {
            try {
                foreach ($this->listContents($path, false) as $item) {
                    if ($item->isDir()) {
                        $this->deleteDirectoryContents($item->path());
                        $this->deleteEmptyDirectory($item->path());
                        continue;
                    }

                    $this->deleteKnownFile($item->path());
                }
            } finally {
                if (!$this->usesSsh2()) {
                    unset($this->curlListCache[$this->remotePath($path)]);
                }
            }
        }

        private function deleteEmptyDirectory(string $path): void
        {
            if ($this->usesSsh2()) {
                if (!@rmdir($this->streamDirectoryPath($path))) {
                    throw FilesystemException::atLocation($path, 'rmdir failed');
                }

                return;
            }

            $this->executeCurlCommands(['rmdir ' . $this->curlCommandPath(rtrim($this->remoteDirectoryPath($path), '/'))], $path);
        }

        private function ensureParentDirectoryExists(string $path, Config $config): void
        {
            $this->ensureConfiguredRootExists();
            $parent = trim(dirname($path), '.');

            if ($parent === '' || $parent === '/') {
                return;
            }

            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            if ($this->usesSsh2()) {
                $this->ensureDirectoryExistsViaSsh2($parent, $visibility);
            } else {
                $this->ensureDirectoryExistsViaCurl($parent, $visibility);
            }
        }

        private function ensureDirectoryExistsViaSsh2(string $path, string $visibility): void
        {
            $this->ensureConfiguredRootExists();
            $path = trim($path, '/');

            if ($path === '') {
                $this->assertSafePath('', true);
                return;
            }

            $segments = explode('/', $path);
            $current = '';
            $mode = $this->visibilityConverter->forDirectory($visibility);

            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '' ? $segment : $current . '/' . $segment;
                $remoteDirectory = $this->remotePath($current);
                $stat = $this->lstatAbsoluteSsh2($remoteDirectory);

                if (is_array($stat)) {
                    if ($this->isLinkStat($stat)) {
                        throw FilesystemException::create($current, 'link', 'symbolic links are not allowed');
                    }

                    if (!$this->isDirectoryStat($stat)) {
                        throw FilesystemException::forPath($current, 'path segment is not a directory');
                    }

                    continue;
                }

                if (!$this->createDirectoryViaSsh2($remoteDirectory, $mode)) {
                    throw FilesystemException::atLocation($current, 'mkdir failed');
                }
            }
        }

        private function ensureDirectoryExistsViaCurl(string $path, string $visibility): void
        {
            $this->ensureConfiguredRootExists();
            $path = trim($path, '/');

            if ($path === '') {
                $this->assertSafePath('', true);
                return;
            }

            $segments = explode('/', $path);
            $current = '';
            $mode = decoct($this->visibilityConverter->forDirectory($visibility));

            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '' ? $segment : $current . '/' . $segment;
                $absoluteCurrent = $this->remotePath($current);
                $this->assertSafePath($current, true);
                $directory = $this->curlCommandPath(rtrim($this->remoteDirectoryPath($current), '/'));

                if ($this->absoluteDirectoryExistsViaCurl($absoluteCurrent)) {
                    $this->curlVerifiedDirectories[$absoluteCurrent] = true;
                    continue;
                }

                try {
                    $this->executeCurlCommands([
                        'mkdir ' . $directory,
                        'chmod ' . $mode . ' ' . $directory,
                    ], $current);
                } catch (Throwable $exception) {
                    $this->clearCurlCache();

                    if ($this->absoluteDirectoryExistsViaCurl($absoluteCurrent)) {
                        $this->curlVerifiedDirectories[$absoluteCurrent] = true;
                        continue;
                    }

                    try {
                        $this->executeCurlCommands([
                            'mkdir ' . $directory,
                        ], $current);
                    } catch (Throwable $retryException) {
                        $this->clearCurlCache();
                    }

                    if (!$this->absoluteDirectoryExistsViaCurl($absoluteCurrent)) {
                        throw FilesystemException::atLocation($current, 'mkdir failed', $exception);
                    }
                }

                $this->curlVerifiedDirectories[$absoluteCurrent] = true;
            }
        }

        private function assertSafePath(string $path, bool $allowMissingLeaf = false): void
        {
            $remotePath = $this->remotePath($path);

            if ($this->usesSsh2()) {
                $this->guardAbsoluteRemotePathViaSsh2($remotePath, $allowMissingLeaf);
                return;
            }

            $this->guardAbsoluteRemotePathViaCurl($remotePath, $allowMissingLeaf);
        }

        private function configuredRootExists(): bool
        {
            $root = trim($this->connectionProvider->root(), '/');

            if ($root === '') {
                return true;
            }

            $remoteRoot = $this->remotePath('');

            return $this->usesSsh2()
                ? $this->absoluteDirectoryExistsViaSsh2($remoteRoot)
                : $this->absoluteDirectoryExistsViaCurl($remoteRoot);
        }

        private function ensureConfiguredRootExists(): void
        {
            if ($this->rootPrepared) {
                return;
            }

            $root = trim($this->connectionProvider->root(), '/');
            $remoteRoot = $this->remotePath('');

            if ($root === '') {
                $this->curlVerifiedDirectories['/'] = true;
                $this->rootPrepared = true;
                return;
            }

            $mode = $this->visibilityConverter->defaultForDirectories();

            if ($this->usesSsh2()) {
                $this->ensureAbsoluteDirectoryExistsViaSsh2($remoteRoot, $mode);
            } else {
                $this->ensureAbsoluteDirectoryExistsViaCurl($remoteRoot, $mode);
            }

            $this->curlVerifiedDirectories[$remoteRoot] = true;
            $this->rootPrepared = true;
        }

        private function guardAbsoluteRemotePathViaSsh2(string $remotePath, bool $allowMissingLeaf): void
        {
            $segments = array_values(array_filter(explode('/', trim($remotePath, '/')), 'strlen'));
            $current = '';
            $lastIndex = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                $current .= '/' . $segment;
                $stat = $this->lstatAbsoluteSsh2($current);

                if ($stat === false) {
                    if ($allowMissingLeaf && $index === $lastIndex) {
                        return;
                    }

                    return;
                }

                if ($this->isLinkStat($stat)) {
                    throw FilesystemException::create($this->relativePathFromRemote($remotePath), 'link', 'symbolic links are not allowed');
                }

                if ($index < $lastIndex && !$this->isDirectoryStat($stat)) {
                    throw FilesystemException::forPath($this->relativePathFromRemote($remotePath), 'path segment is not a directory');
                }
            }
        }

        private function guardAbsoluteRemotePathViaCurl(string $remotePath, bool $allowMissingLeaf): void
        {
            $segments = array_values(array_filter(explode('/', trim($remotePath, '/')), 'strlen'));
            $parent = '';
            $lastIndex = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                $current = ($parent === '' ? '' : rtrim($parent, '/')) . '/' . $segment;

                if (isset($this->curlVerifiedDirectories[$current])) {
                    $parent = $current;
                    continue;
                }

                $entries = $this->listAbsoluteDirectoryViaCurl($parent, false);

                if (!isset($entries[$segment])) {
                    if ($allowMissingLeaf && $index === $lastIndex) {
                        return;
                    }

                    return;
                }

                $entry = $entries[$segment];

                if ($entry['type'] === 'link') {
                    throw FilesystemException::create($this->relativePathFromRemote($remotePath), 'link', 'symbolic links are not allowed');
                }

                if ($index < $lastIndex && $entry['type'] !== StorageAttributes::TYPE_DIRECTORY) {
                    throw FilesystemException::forPath($this->relativePathFromRemote($remotePath), 'path segment is not a directory');
                }

                $parent = $entry['remotePath'];

                 if ($entry['type'] === StorageAttributes::TYPE_DIRECTORY) {
                    $this->curlVerifiedDirectories[$parent] = true;
                }
            }
        }

        private function listAbsoluteDirectoryViaCurl(string $absoluteRemotePath, bool $strictLinks): array
        {
            $absoluteRemotePath = trim(str_replace('\\', '/', $absoluteRemotePath));
            $absoluteRemotePath = $absoluteRemotePath === '' ? '/' : '/' . ltrim($absoluteRemotePath, '/');
            $cacheKey = $absoluteRemotePath;

            if (!isset($this->curlListCache[$cacheKey])) {
                list($result) = $this->executeCurl($this->curlUrlForPath($absoluteRemotePath, true), [
                    CURLOPT_RETURNTRANSFER => true,
                ], $this->relativePathFromRemote($absoluteRemotePath), true);

                $lines = preg_split('/\r\n|\r|\n/', (string) $result) ?: [];
                $listing = [];

                foreach ($lines as $line) {
                    $entry = $this->normalizeUnixListingLine($line, $absoluteRemotePath);

                    if ($entry === null) {
                        continue;
                    }

                    $listing[$entry['name']] = $entry;

                    if ($entry['type'] === StorageAttributes::TYPE_DIRECTORY) {
                        $this->curlVerifiedDirectories[$entry['remotePath']] = true;
                    }
                }

                $this->curlListCache[$cacheKey] = $listing;
            }

            $listing = $this->curlListCache[$cacheKey];

            if ($strictLinks) {
                foreach ($listing as $entry) {
                    if ($entry['type'] === 'link') {
                        throw FilesystemException::create($this->stripRemotePrefix($entry['remotePath']), 'link', 'symbolic links are not allowed');
                    }
                }
            }

            return $listing;
        }

        private function absoluteDirectoryExistsViaSsh2(string $absoluteRemotePath): bool
        {
            $stat = $this->lstatAbsoluteSsh2($absoluteRemotePath);

            return is_array($stat) && !$this->isLinkStat($stat) && $this->isDirectoryStat($stat);
        }

        private function absoluteDirectoryExistsViaCurl(string $absoluteRemotePath): bool
        {
            $absoluteRemotePath = trim(str_replace('\\', '/', $absoluteRemotePath));
            $absoluteRemotePath = $absoluteRemotePath === '' ? '/' : '/' . ltrim($absoluteRemotePath, '/');

            if ($absoluteRemotePath === '/') {
                return true;
            }

            if (isset($this->curlVerifiedDirectories[$absoluteRemotePath])) {
                return true;
            }

            $parent = dirname($absoluteRemotePath);
            $entries = $this->listAbsoluteDirectoryViaCurl($parent === '/' ? '' : $parent, false);
            $name = basename($absoluteRemotePath);

            $exists = isset($entries[$name]) && $entries[$name]['type'] === StorageAttributes::TYPE_DIRECTORY;

            if ($exists) {
                $this->curlVerifiedDirectories[$absoluteRemotePath] = true;
            }

            return $exists;
        }

        private function ensureAbsoluteDirectoryExistsViaSsh2(string $absoluteRemotePath, int $mode): void
        {
            $segments = array_values(array_filter(explode('/', trim($absoluteRemotePath, '/')), 'strlen'));
            $current = '';

            foreach ($segments as $segment) {
                $current .= '/' . $segment;
                $stat = $this->lstatAbsoluteSsh2($current);

                if (is_array($stat)) {
                    if ($this->isLinkStat($stat)) {
                        throw FilesystemException::create($this->relativePathFromRemote($current), 'link', 'symbolic links are not allowed');
                    }

                    if (!$this->isDirectoryStat($stat)) {
                        throw FilesystemException::forPath($this->relativePathFromRemote($current), 'path segment is not a directory');
                    }

                    continue;
                }

                if (!$this->createDirectoryViaSsh2($current, $mode)) {
                    throw FilesystemException::atLocation($this->relativePathFromRemote($current), 'mkdir failed');
                }
            }
        }

        private function createDirectoryViaSsh2(string $absoluteRemotePath, int $mode): bool
        {
            $absoluteRemotePath = '/' . ltrim(rtrim($absoluteRemotePath, '/'), '/');
            $created = false;

            if (function_exists('ssh2_sftp_mkdir')) {
                $created = (bool) $this->withSocketTimeout(function () use ($absoluteRemotePath, $mode) {
                    return @ssh2_sftp_mkdir($this->sftpResource(), $absoluteRemotePath, $mode, false);
                });
            }

            if (!$created) {
                $directory = $this->streamAbsolutePath($absoluteRemotePath . '/');
                $created = (bool) $this->withSocketTimeout(function () use ($directory) {
                    return @mkdir($directory);
                });
            }

            if (!$created && function_exists('ssh2_exec')) {
                $this->executeSsh2Command('mkdir -p -- ' . escapeshellarg($absoluteRemotePath));
                $created = $this->absoluteDirectoryExistsViaSsh2($absoluteRemotePath);
            }

            if (!$created && !$this->absoluteDirectoryExistsViaSsh2($absoluteRemotePath)) {
                return false;
            }

            $this->applyVisibilityViaSsh2($absoluteRemotePath, $mode);

            return true;
        }

        private function applyVisibilityViaSsh2(string $absoluteRemotePath, int $mode): bool
        {
            $absoluteRemotePath = '/' . ltrim(rtrim($absoluteRemotePath, '/'), '/');

            if (function_exists('ssh2_sftp_chmod')) {
                $result = $this->withSocketTimeout(function () use ($absoluteRemotePath, $mode) {
                    return @ssh2_sftp_chmod($this->sftpResource(), $absoluteRemotePath, $mode);
                });

                if ($result) {
                    return true;
                }
            }

            return (bool) @chmod($this->streamAbsolutePath($absoluteRemotePath), $mode);
        }

        private function executeSsh2Command(string $command): void
        {
            $stream = $this->withSocketTimeout(function () use ($command) {
                return @ssh2_exec($this->session(), $command);
            });

            if (!is_resource($stream)) {
                return;
            }

            @stream_set_blocking($stream, true);
            @stream_set_timeout($stream, $this->connectionProvider->timeout());
            @stream_get_contents($stream);
            @fclose($stream);
        }

        private function ensureAbsoluteDirectoryExistsViaCurl(string $absoluteRemotePath, int $mode): void
        {
            $segments = array_values(array_filter(explode('/', trim($absoluteRemotePath, '/')), 'strlen'));
            $current = '';
            $mode = decoct($mode);

            foreach ($segments as $segment) {
                $current .= '/' . $segment;
                $this->guardAbsoluteRemotePathViaCurl($current, true);
                $directory = $this->curlCommandPath($current);

                if ($this->absoluteDirectoryExistsViaCurl($current)) {
                    continue;
                }

                try {
                    $this->executeCurlCommands([
                        'mkdir ' . $directory,
                        'chmod ' . $mode . ' ' . $directory,
                    ], $this->relativePathFromRemote($current));
                } catch (Throwable $exception) {
                    $this->clearCurlCache();

                    if ($this->absoluteDirectoryExistsViaCurl($current)) {
                        $this->curlVerifiedDirectories[$current] = true;
                        continue;
                    }

                    try {
                        $this->executeCurlCommands([
                            'mkdir ' . $directory,
                        ], $this->relativePathFromRemote($current));
                    } catch (Throwable $retryException) {
                        $this->clearCurlCache();
                    }

                    if (!$this->absoluteDirectoryExistsViaCurl($current)) {
                        throw FilesystemException::atLocation($this->relativePathFromRemote($current), 'mkdir failed', $exception);
                    }
                }

                $this->curlVerifiedDirectories[$current] = true;
            }
        }

        private function normalizeUnixListingLine(string $line, string $absoluteRemotePath): ?array
        {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'total')) {
                return null;
            }

            $line = preg_replace('#\s+#', ' ', $line, 8);
            $parts = explode(' ', $line, 9);

            if (count($parts) !== 9) {
                return null;
            }

            list($permissions, $number, $owner, $group, $size, $month, $day, $timeOrYear, $name) = $parts;
            $name = trim($name);
            $baseName = preg_replace('#\s+->.*$#', '', $name);

            if ($baseName === '.' || $baseName === '..') {
                return null;
            }

            $type = StorageAttributes::TYPE_FILE;

            if (str_starts_with($permissions, 'd')) {
                $type = StorageAttributes::TYPE_DIRECTORY;
            } elseif (str_starts_with($permissions, 'l')) {
                $type = 'link';
            }

            $mode = $this->normalizePermissions($permissions);
            $remotePath = rtrim($absoluteRemotePath, '/');
            $remotePath = ($remotePath === '' ? '' : $remotePath) . '/' . $baseName;

            return [
                'name' => $baseName,
                'remotePath' => $remotePath === '' ? '/' . $baseName : $remotePath,
                'type' => $type,
                'fileSize' => $type === StorageAttributes::TYPE_DIRECTORY ? null : (int) $size,
                'lastModified' => $this->normalizeUnixTimestamp($month, $day, $timeOrYear),
                'visibility' => $type === StorageAttributes::TYPE_DIRECTORY
                    ? $this->visibilityConverter->inverseForDirectory($mode)
                    : $this->visibilityConverter->inverseForFile($mode),
            ];
        }

        private function normalizePermissions(string $permissions): int
        {
            $permissions = substr($permissions, 1, 9);
            $groups = str_split($permissions, 3);
            $result = '';

            foreach ($groups as $group) {
                $value = 0;

                if (isset($group[0]) && $group[0] === 'r') {
                    $value += 4;
                }

                if (isset($group[1]) && $group[1] === 'w') {
                    $value += 2;
                }

                if (isset($group[2]) && in_array($group[2], ['x', 's', 't'], true)) {
                    $value += 1;
                }

                $result .= (string) $value;
            }

            return octdec($result);
        }

        private function normalizeUnixTimestamp(string $month, string $day, string $timeOrYear): int
        {
            if (strpos($timeOrYear, ':') !== false) {
                $date = DateTime::createFromFormat('Y M d H:i', date('Y') . ' ' . $month . ' ' . $day . ' ' . $timeOrYear);

                if ($date instanceof DateTime && $date->getTimestamp() > time() + 86400) {
                    $date->modify('-1 year');
                }
            } else {
                $date = DateTime::createFromFormat('Y M d H:i', $timeOrYear . ' ' . $month . ' ' . $day . ' 00:00');
            }

            return $date instanceof DateTime ? $date->getTimestamp() : time();
        }

        private function makeSeekableStream($contents): array
        {
            $meta = stream_get_meta_data($contents);

            if (($meta['seekable'] ?? false) && @rewind($contents)) {
                return [$contents, false, null];
            }

            list($stream, $tmpFile) = $this->createTemporaryStream('sfu');

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

            return [$stream, true, $tmpFile];
        }

        private function createTemporaryStream(string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                return [$stream, null];
            }

            return $this->createTemporaryFileStream($prefix);
        }

        private function createTemporaryStreamFromContents(string $contents, string $prefix): array
        {
            $stream = @fopen('php://temp', 'w+b');

            if ($stream !== false) {
                if ($this->writeTemporaryStreamContents($stream, $contents)) {
                    return [$stream, null];
                }

                @fclose($stream);
            }

            list($stream, $tmpFile) = $this->createTemporaryFileStream($prefix);

            if ($stream === false) {
                return [false, null];
            }

            if (!$this->writeTemporaryStreamContents($stream, $contents)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                return [false, null];
            }

            return [$stream, $tmpFile];
        }

        private function writeTemporaryStreamContents($stream, string $contents): bool
        {
            $length = strlen($contents);

            for ($offset = 0; $offset < $length;) {
                $written = @fwrite($stream, substr($contents, $offset, 1048576));

                if ($written === false || $written === 0) {
                    return false;
                }

                $offset += $written;
            }

            return @rewind($stream);
        }

        private function createTemporaryFileStream(string $prefix): array
        {
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

        private function applyStreamTimeout($stream): void
        {
            if (is_resource($stream)) {
                @stream_set_timeout($stream, $this->connectionProvider->timeout());
            }
        }

        private function assertStreamDidNotTimeout($stream, string $path, string $message): void
        {
            if (!is_resource($stream)) {
                return;
            }

            $meta = stream_get_meta_data($stream);

            if (!empty($meta['timed_out'])) {
                throw FilesystemException::atLocation($path, $message);
            }
        }

        private function withSocketTimeout(callable $callback)
        {
            $timeout = $this->connectionProvider->timeout();
            $previous = ini_get('default_socket_timeout');

            if ($timeout > 0) {
                @ini_set('default_socket_timeout', (string) $timeout);
            }

            try {
                return $callback();
            } finally {
                if ($previous !== false && $previous !== '') {
                    @ini_set('default_socket_timeout', (string) $previous);
                }
            }
        }

        private function clearCurlCache(): void
        {
            $this->curlListCache = [];
        }

        private function lstatSsh2(string $path)
        {
            return $this->lstatAbsoluteSsh2($this->remotePath($path));
        }

        private function lstatAbsoluteSsh2(string $remotePath)
        {
            $location = $this->streamAbsolutePath($remotePath);
            $this->clearStatCache($location);

            return @lstat($location);
        }

        private function clearStatCache(string $path): void
        {
            if ($this->connectionProvider->disableStatCache()) {
                clearstatcache(true, $path);
            }
        }

        private function isLinkStat(array $stat): bool
        {
            return isset($stat['mode']) && (((int) $stat['mode'] & 0170000) === 0120000);
        }

        private function isDirectoryStat(array $stat): bool
        {
            return isset($stat['mode']) && (((int) $stat['mode'] & 0170000) === 0040000);
        }

        private function executeCurlCommands(array $commands, string $path = ''): void
        {
            $options = [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_QUOTE => $commands,
            ];

            $this->executeCurl($this->curlUrlForPath('/'), $options, $path, true);
            $this->clearCurlCache();
        }

        private function curlCommandPath(string $path): string
        {
            $path = str_replace('\\', '/', $path);

            if ($path === '') {
                return '/';
            }

            if (strpbrk($path, "\r\n") !== false) {
                throw FilesystemException::forPath($path, 'path contains invalid characters');
            }

            if (strpbrk($path, " \t\"'\\") === false) {
                return $path;
            }

            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $path) . '"';
        }

        private function executeCurl(string $url, array $options = [], string $path = '', bool $allowNotFound = false): array
        {
            $attempts = max(2, $this->connectionProvider->maxTries() + 1);
            $lastError = '';
            $lastErrno = 0;
            $lastInfo = [];

            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                $ch = $this->createCurlHandle($url);
                curl_setopt_array($ch, $options);

                $result = curl_exec($ch);
                $errno = curl_errno($ch);
                $error = curl_error($ch);
                $info = curl_getinfo($ch);

                if ($result !== false) {
                    return [$result, $info];
                }

                if ($allowNotFound && $errno === 78) {
                    return ['', $info];
                }

                $lastError = $error;
                $lastErrno = $errno;
                $lastInfo = $info;

                if ($this->isHostKeyVerificationCurlError($errno, $error) && $this->enableAutomaticCurlHostKeyFallback()) {
                    continue;
                }

                if (!$this->isRetryableCurlError($errno) || $attempt + 1 >= $attempts) {
                    break;
                }

                usleep(500000);
            }

            throw $this->curlException($path, $lastError, $lastErrno ?: 0, $lastInfo);
        }

        private function isRetryableCurlError(int $errno): bool
        {
            return in_array($errno, [7, 28, 35, 52, 56], true);
        }

        private function isHostKeyVerificationCurlError(int $errno, string $error): bool
        {
            return $errno === 51
                || $errno === 60
                || stripos($error, 'SSH remote key was not OK') !== false
                || stripos($error, 'remote key') !== false;
        }

        private function createCurlHandle(string $url)
        {
            if (is_resource($this->curlHandle) || is_object($this->curlHandle)) {
                curl_reset($this->curlHandle);
                $ch = $this->curlHandle;
            } else {
                $ch = curl_init();
                $this->curlHandle = $ch;
            }

            if ($ch === false) {
                throw new \RuntimeException('Unable to initialize cURL');
            }

            curl_setopt($ch, CURLOPT_URL, $url);

            $authTypes = 0;

            if (defined('CURLSSH_AUTH_PASSWORD') && $this->connectionProvider->password() !== '') {
                $authTypes |= CURLSSH_AUTH_PASSWORD;
            }

            if (defined('CURLSSH_AUTH_PUBLICKEY') && ($this->connectionProvider->privateKey() || $this->connectionProvider->publicKey())) {
                $authTypes |= CURLSSH_AUTH_PUBLICKEY;
            }

            if (defined('CURLSSH_AUTH_AGENT') && $this->connectionProvider->useAgent()) {
                $authTypes |= CURLSSH_AUTH_AGENT;
            }

            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => max(15, $this->connectionProvider->timeout()),
                CURLOPT_TIMEOUT => max(60, $this->connectionProvider->timeout()),
                CURLOPT_USERPWD => $this->connectionProvider->username() . ':' . $this->connectionProvider->password(),
                CURLOPT_RETURNTRANSFER => true,
            ]);

            if ($authTypes > 0 && defined('CURLOPT_SSH_AUTH_TYPES')) {
                curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, $authTypes);
            }

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_SFTP')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_SFTP);
            }

            if ($this->curlKnownHostsFile === null && $this->connectionProvider->hostFingerprint() === null) {
                $cachedKnownHosts = $this->cachedKnownHostsFilePath();

                if ($cachedKnownHosts !== null) {
                    $this->curlKnownHostsFile = $cachedKnownHosts;
                }
            }

            if ($this->curlKnownHostsFile !== null && $this->curlKnownHostsFile !== '' && is_file($this->curlKnownHostsFile) && defined('CURLOPT_SSH_KNOWNHOSTS')) {
                curl_setopt($ch, CURLOPT_SSH_KNOWNHOSTS, $this->curlKnownHostsFile);
            }

            $this->applyCurlKeyOptions($ch);
            $this->applyCurlFingerprintOptions($ch);

            return $ch;
        }

        private function applyCurlKeyOptions($ch): void
        {
            $privateKey = $this->connectionProvider->privateKey();
            $publicKey = $this->connectionProvider->publicKey();

            if ($privateKey === null) {
                return;
            }

            $privateKeyFile = $this->resolveKeyFile($privateKey, '.pem');
            $publicKeyFile = $publicKey !== null ? $this->resolveKeyFile($publicKey, '.pub') : null;

            if ($publicKeyFile === null && is_file($privateKeyFile . '.pub')) {
                $publicKeyFile = $privateKeyFile . '.pub';
            }

            if ($publicKeyFile !== null && defined('CURLOPT_SSH_PRIVATE_KEYFILE') && defined('CURLOPT_SSH_PUBLIC_KEYFILE')) {
                curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $privateKeyFile);
                curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, $publicKeyFile);

                if (defined('CURLOPT_KEYPASSWD') && $this->connectionProvider->passphrase() !== null) {
                    curl_setopt($ch, CURLOPT_KEYPASSWD, $this->connectionProvider->passphrase());
                }
            }
        }

        private function applyCurlFingerprintOptions($ch): void
        {
            $fingerprint = $this->connectionProvider->hostFingerprint();

            if ($fingerprint === null) {
                return;
            }

            $normalized = $this->normalizeFingerprint($fingerprint);

            if (strlen($normalized) === 32 && defined('CURLOPT_SSH_HOST_PUBLIC_KEY_MD5')) {
                curl_setopt($ch, CURLOPT_SSH_HOST_PUBLIC_KEY_MD5, strtolower($fingerprint));
                return;
            }

            if (defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
                curl_setopt($ch, CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256, $fingerprint);
            }
        }

        private function enableAutomaticCurlHostKeyFallback(): bool
        {
            if ($this->curlHostKeyFallbackAttempted) {
                return false;
            }

            $this->curlHostKeyFallbackAttempted = true;

            if ($this->connectionProvider->hostFingerprint() !== null) {
                return false;
            }

            $knownHostsFile = $this->createTemporaryKnownHostsFile();

            if ($knownHostsFile === null) {
                return false;
            }

            $this->curlKnownHostsFile = $knownHostsFile;
            $this->clearCurlCache();
            $this->disconnectCurlHandle();

            return true;
        }

        private function createTemporaryKnownHostsFile(): ?string
        {
            if (!defined('CURLOPT_SSH_KNOWNHOSTS')) {
                return null;
            }

            $host = trim($this->connectionProvider->host());

            if ($host === '') {
                return null;
            }

            $timeout = max(5, min(15, $this->connectionProvider->timeout()));
            $port = $this->connectionProvider->port();
            $command = 'ssh-keyscan -H -T ' . (int) $timeout . ' -p ' . (int) $port . ' ' . escapeshellarg($host) . ' 2>/dev/null';
            $output = $this->executeShellCommand($command);

            if (!is_string($output) || trim($output) === '') {
                return null;
            }

            $file = $this->persistentKnownHostsCachePath();

            if (@file_put_contents($file, $output) === false) {
                @unlink($file);
                return null;
            }

            @chmod($file, 0600);

            return $file;
        }

        private function cachedKnownHostsFilePath(): ?string
        {
            $file = $this->persistentKnownHostsCachePath();

            if (is_file($file) && filesize($file) > 0) {
                return $file;
            }

            return null;
        }

        private function persistentKnownHostsCachePath(): string
        {
            return $this->temporaryCacheDirectory() . '/dle-sftp-kh-' . sha1($this->connectionProvider->host() . ':' . $this->connectionProvider->port() . \SECURE_AUTH_KEY) . '.known_hosts';
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

        private function disconnectCurlHandle(): void
        {
            $this->curlHandle = null;
        }

        private function executeShellCommand(string $command): ?string
        {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

            if (function_exists('proc_open') && !in_array('proc_open', $disabled, true)) {
                $descriptors = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $process = @proc_open($command, $descriptors, $pipes);

                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    if (isset($pipes[2]) && is_resource($pipes[2])) {
                        fclose($pipes[2]);
                    }
                    @proc_close($process);

                    if (is_string($stdout) && trim($stdout) !== '') {
                        return $stdout;
                    }
                }
            }

            if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
                $stdout = @shell_exec($command);

                if (is_string($stdout) && trim($stdout) !== '') {
                    return $stdout;
                }
            }

            return null;
        }

        private function curlException(string $path, string $error, int $errno, array $info = []): FilesystemException
        {
            $message = trim($error);

            if ($message === '') {
                $message = 'cURL error #' . $errno;
            }

            if (
                $errno === 51
                || $errno === 60
                || stripos($message, 'SSH remote key was not OK') !== false
                || stripos($message, 'remote key') !== false
            ) {
                if ($this->curlKnownHostsFile !== null && is_file($this->curlKnownHostsFile)) {
                    return FilesystemException::atLocation(
                        $path,
                        'SFTP host key verification failed after automatic fallback. Verify the server SSH key or enable ext-ssh2 on the host'
                    );
                }

                return FilesystemException::atLocation(
                    $path,
                    'SFTP host key verification failed before password authentication. Verify the server SSH key, ensure ssh-keyscan is available for PHP, or enable ext-ssh2 on the host'
                );
            }

            if ($errno === 28 && isset($info['connect_time']) && (float) $info['connect_time'] > 0) {
                $message .= ' (connect_time=' . number_format((float) $info['connect_time'], 3, '.', '') . 's)';
            }

            if ($errno === 78 && stripos($message, 'No such file or directory') !== false) {
                return FilesystemException::forLocation($path);
            }

            return FilesystemException::atLocation($path, $message);
        }

        private function curlUrlForPath(string $remotePath, bool $directory = false): string
        {
            $remotePath = trim(str_replace('\\', '/', $remotePath));
            $remotePath = $remotePath === '' ? '/' : '/' . ltrim($remotePath, '/');

            if ($directory) {
                $remotePath = rtrim($remotePath, '/') . '/';
            }

            $segments = array_values(array_filter(explode('/', ltrim($remotePath, '/')), 'strlen'));
            $url = $this->curlBaseUrl() . '/';

            if ($segments !== []) {
                $url .= implode('/', array_map('rawurlencode', $segments));
            }

            if ($directory && substr($url, -1) !== '/') {
                $url .= '/';
            }

            return $url;
        }

        private function curlBaseUrl(): string
        {
            $host = $this->connectionProvider->host();
            $port = $this->connectionProvider->port();

            return 'sftp://' . $host . ($port > 0 ? ':' . $port : '');
        }

        private function streamPath(string $path): string
        {
            return $this->streamAbsolutePath($this->remotePath($path));
        }

        private function streamDirectoryPath(string $path): string
        {
            return $this->streamAbsolutePath($this->remoteDirectoryPath($path));
        }

        private function streamAbsolutePath(string $remotePath): string
        {
            return 'ssh2.sftp://' . (int) $this->sftpResource() . $remotePath;
        }

        private function remotePath(string $path): string
        {
            $path = $this->prefixer->prefixPath($path);

            if ($path === '') {
                return '/';
            }

            return '/' . ltrim(rtrim($path, '/'), '/');
        }

        private function remoteDirectoryPath(string $path): string
        {
            $path = $this->remotePath($path);

            return $path === '/' ? '/' : rtrim($path, '/') . '/';
        }

        private function relativePathFromRemote(string $remotePath): string
        {
            return trim($this->stripRemotePrefix($remotePath), '/');
        }

        private function stripRemotePrefix(string $remotePath): string
        {
            $remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
            $prefix = trim(str_replace('\\', '/', $this->connectionProvider->root()), '/');

            if ($prefix !== '') {
                $prefix .= '/';

                if (strpos($remotePath, $prefix) === 0) {
                    return ltrim(substr($remotePath, strlen($prefix)), '/');
                }

                if (rtrim($remotePath, '/') === rtrim($prefix, '/')) {
                    return '';
                }
            }

            return $remotePath;
        }

        private function sftpResource()
        {
            if ($this->sftp !== null) {
                return $this->sftp;
            }

            $this->sftp = $this->withSocketTimeout(function () {
                return @ssh2_sftp($this->session());
            });

            if ($this->sftp === false || $this->sftp === null) {
                throw new \RuntimeException('Unable to initialize SFTP subsystem');
            }

            return $this->sftp;
        }

        private function session()
        {
            if ($this->session !== null) {
                return $this->session;
            }

            $tries = max(1, $this->connectionProvider->maxTries() + 1);
            $lastException = null;

            for ($attempt = 0; $attempt < $tries; $attempt++) {
                try {
                    $this->session = $this->openSession();
                    $this->authenticate($this->session);

                    return $this->session;
                } catch (Throwable $exception) {
                    $lastException = $exception;
                    $this->session = null;
                    $this->sftp = null;
                }
            }

            throw new \RuntimeException($lastException ? $lastException->getMessage() : 'Unable to connect to SFTP host');
        }

        private function openSession()
        {
            $algorithms = $this->connectionProvider->preferredAlgorithms();
            $session = $this->withSocketTimeout(function () use ($algorithms) {
                return $algorithms !== []
                    ? @ssh2_connect($this->connectionProvider->host(), $this->connectionProvider->port(), $algorithms)
                    : @ssh2_connect($this->connectionProvider->host(), $this->connectionProvider->port());
            });

            if ($session === false) {
                throw new \RuntimeException('Unable to connect to SFTP host');
            }

            $this->assertFingerprint($session);

            return $session;
        }

        private function assertFingerprint($session): void
        {
            $expected = $this->connectionProvider->hostFingerprint();

            if ($expected === null || $expected === '') {
                return;
            }

            if (!function_exists('ssh2_fingerprint') || !defined('SSH2_FINGERPRINT_MD5') || !defined('SSH2_FINGERPRINT_HEX')) {
                throw new \RuntimeException('Host fingerprint validation is not supported by ext-ssh2');
            }

            $actual = ssh2_fingerprint($session, \SSH2_FINGERPRINT_MD5 | \SSH2_FINGERPRINT_HEX);

            if ($this->normalizeFingerprint((string) $actual) !== $this->normalizeFingerprint($expected)) {
                throw new \RuntimeException('SFTP host fingerprint mismatch');
            }
        }

        private function authenticate($session): void
        {
            $privateKey = $this->connectionProvider->privateKey();

            if ($privateKey !== null) {
                $this->authenticateWithPrivateKey($session, $privateKey);
                return;
            }

            if ($this->connectionProvider->useAgent() && function_exists('ssh2_auth_agent')) {
                if (@ssh2_auth_agent($session, $this->connectionProvider->username())) {
                    return;
                }
            }

            if ($this->connectionProvider->password() !== '') {
                if (@ssh2_auth_password($session, $this->connectionProvider->username(), $this->connectionProvider->password())) {
                    return;
                }

                throw new \RuntimeException('Unable to authenticate on SFTP host');
            }

            throw new \RuntimeException('No supported SFTP authentication method configured');
        }

        private function authenticateWithPrivateKey($session, string $privateKey): void
        {
            if (!function_exists('ssh2_auth_pubkey_file')) {
                throw new \RuntimeException('Public key authentication is not supported by ext-ssh2');
            }

            $privateKeyFile = $this->resolveKeyFile($privateKey, '.pem');
            $publicKey = $this->connectionProvider->publicKey();
            $publicKeyFile = $publicKey !== null ? $this->resolveKeyFile($publicKey, '.pub') : null;

            if ($publicKeyFile === null && is_file($privateKeyFile . '.pub')) {
                $publicKeyFile = $privateKeyFile . '.pub';
            }

            if ($publicKeyFile === null) {
                throw new \RuntimeException('Public key file is required for SFTP authentication');
            }

            if (@ssh2_auth_pubkey_file(
                $session,
                $this->connectionProvider->username(),
                $publicKeyFile,
                $privateKeyFile,
                $this->connectionProvider->passphrase() ?? ''
            )) {
                return;
            }

            if ($this->connectionProvider->password() !== '' && @ssh2_auth_password($session, $this->connectionProvider->username(), $this->connectionProvider->password())) {
                return;
            }

            throw new \RuntimeException('Unable to authenticate on SFTP host');
        }

        private function resolveKeyFile(string $value, string $suffix): ?string
        {
            if ($value === '') {
                return null;
            }

            if (is_file($value)) {
                return $value;
            }

            $cacheKey = $suffix . ':' . sha1($value);

            if (isset($this->resolvedKeyFiles[$cacheKey]) && is_file($this->resolvedKeyFiles[$cacheKey])) {
                return $this->resolvedKeyFiles[$cacheKey];
            }

            $tmp = $this->createTemporaryCacheFile('sfk');

            if ($tmp === false) {
                throw new \RuntimeException('Unable to create temporary key file');
            }

            $file = $tmp . $suffix;

            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Unable to create temporary key file');
            }

            if (@file_put_contents($file, $value) === false) {
                @unlink($file);
                throw new \RuntimeException('Unable to write temporary key file');
            }

            @chmod($file, 0600);
            $this->temporaryKeyFiles[] = $file;
            $this->resolvedKeyFiles[$cacheKey] = $file;

            return $file;
        }

        private function normalizeFingerprint(string $fingerprint): string
        {
            return strtolower(preg_replace('/[^a-f0-9]/i', '', $fingerprint) ?? '');
        }
    }
}
