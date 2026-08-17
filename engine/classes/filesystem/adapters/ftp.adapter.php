<?php

declare(strict_types=1);

namespace DleFilesystem\Adapters {

    use DateTime;
    use DleFilesystem\Config;
    use DleFilesystem\DirectoryAttributes;
    use DleFilesystem\FileAttributes;
    use DleFilesystem\FilesystemAdapter;
    use DleFilesystem\PathPrefixer;
    use DleFilesystem\StorageAttributes;
    use DleFilesystem\FilesystemException;
    use DleFilesystem\Visibility\PortableVisibilityConverter;
    use DleFilesystem\Visibility\VisibilityConverter;
    use DleFilesystem\MimeTypeDetection\FinfoMimeTypeDetector;
    use DleFilesystem\MimeTypeDetection\MimeTypeDetector;
    use Throwable;

    class FtpConnectionOptions
    {
        private array $options;

        public function __construct(array $options = [])
        {
            $this->options = $options + [
                'host' => '',
                'root' => '',
                'username' => 'anonymous',
                'password' => '',
                'port' => 21,
                'timeout' => 30,
                'ssl' => false,
                'passive' => true,
                'utf8' => false,
                'transferMode' => FTP_BINARY,
                'timestampsOnUnixListingsEnabled' => true,
                'recurseManually' => true,
                'systemType' => null,
            ];
        }

        public static function fromArray(array $options): self
        {
            return new self($options);
        }

        public function host(): string
        {
            return (string) $this->options['host'];
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

        public function port(): int
        {
            return (int) $this->options['port'];
        }

        public function timeout(): int
        {
            return max(30, (int) $this->options['timeout']);
        }

        public function ssl(): bool
        {
            return (bool) $this->options['ssl'];
        }

        public function passive(): bool
        {
            return (bool) $this->options['passive'];
        }

        public function utf8(): bool
        {
            return (bool) $this->options['utf8'];
        }

        public function transferMode(): int
        {
            return (int) $this->options['transferMode'];
        }

        public function timestampsOnUnixListingsEnabled(): bool
        {
            return (bool) $this->options['timestampsOnUnixListingsEnabled'];
        }

        public function recurseManually(): bool
        {
            return (bool) $this->options['recurseManually'];
        }

        public function systemType(): ?string
        {
            $type = $this->options['systemType'];
            return is_string($type) && $type !== '' ? strtolower($type) : null;
        }
    }

    class FtpConnectionProvider
    {
        public function createConnection(FtpConnectionOptions $options)
        {
            if ($options->ssl()) {
                if (!function_exists('ftp_ssl_connect')) {
                    throw new \RuntimeException('FTP SSL was requested but ftp_ssl_connect is not available');
                }

                $connection = @ftp_ssl_connect($options->host(), $options->port(), $options->timeout());
            } else {
                $connection = @ftp_connect($options->host(), $options->port(), $options->timeout());
            }

            if ($connection === false) {
                throw new \RuntimeException('Unable to connect to FTP host');
            }

            if (!@ftp_login($connection, $options->username(), $options->password())) {
                @ftp_close($connection);
                throw new \RuntimeException('Unable to authenticate on FTP host');
            }

            @ftp_set_option($connection, FTP_TIMEOUT_SEC, $options->timeout());
            @ftp_pasv($connection, $options->passive());

            if ($options->utf8()) {
                @ftp_raw($connection, 'OPTS UTF8 ON');
            }

            return $connection;
        }
    }

    class FtpAdapter implements FilesystemAdapter
    {
        private const MIME_TYPE_DETECTION_SAMPLE_SIZE = 65536;
        private const SYSTEM_TYPE_WINDOWS = 'windows';
        private const SYSTEM_TYPE_UNIX = 'unix';

        private FtpConnectionOptions $connectionOptions;
        private FtpConnectionProvider $connectionProvider;
        private VisibilityConverter $visibilityConverter;
        private MimeTypeDetector $mimeTypeDetector;
        private bool $detectMimeTypeUsingPath;
        private $connection = false;
        private PathPrefixer $prefixer;
        private ?string $systemType = null;
        private array $directoryExistsCache = ['' => true, '/' => true];
        private array $directoryListingCache = [];
        private array $temporaryStreamFiles = [];

        public static function create(array $options, ?VisibilityConverter $visibilityConverter = null, ?MimeTypeDetector $mimeTypeDetector = null, bool $detectMimeTypeUsingPath = false): self
        {
            return new self(
                FtpConnectionOptions::fromArray($options),
                null,
                null,
                $visibilityConverter,
                $mimeTypeDetector,
                $detectMimeTypeUsingPath
            );
        }

        public function __construct(FtpConnectionOptions $connectionOptions, ?FtpConnectionProvider $connectionProvider = null, $connectivityChecker = null, ?VisibilityConverter $visibilityConverter = null, ?MimeTypeDetector $mimeTypeDetector = null, bool $detectMimeTypeUsingPath = false)
        {
            $this->connectionOptions = $connectionOptions;
            $this->connectionProvider = $connectionProvider ?? new FtpConnectionProvider();
            $this->visibilityConverter = $visibilityConverter ?? new PortableVisibilityConverter();
            $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
            $this->detectMimeTypeUsingPath = $detectMimeTypeUsingPath;
            $this->prefixer = new PathPrefixer($connectionOptions->root());
            $this->systemType = $connectionOptions->systemType();
        }

        public function __destruct()
        {
            $this->disconnect();
        }

        public function fileExists(string $path): bool
        {
            try {
                return $this->fileSize($path)->fileSize() !== null;
            } catch (Throwable $exception) {
                return false;
            }
        }

        public function directoryExists(string $path): bool
        {
            $path = trim($path, '/');

            if ($path === '') {
                return true;
            }

            if (isset($this->directoryExistsCache[$path])) {
                return true;
            }

            $connection = $this->connection();
            $location = $this->prefixer->prefixPath($path);
            $current = @ftp_pwd($connection);

            if ($current === false) {
                throw FilesystemException::forLocation($path);
            }

            $result = @ftp_chdir($connection, $location);

            if ($result) {
                @ftp_chdir($connection, $current);
                $this->directoryExistsCache[$path] = true;
                return true;
            }

            return false;
        }

        public function write(string $path, string $contents, Config $config): void
        {
            list($stream, $tmpFile) = $this->createTemporaryStreamFromContents($contents, 'ftw');

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

            $meta = stream_get_meta_data($contents);

            if (!empty($meta['seekable'])) {
                @rewind($contents);
            }

            $this->ensureParentDirectoryExists($path, $config);
            $location = $this->prefixer->prefixPath($path);

            if (!@ftp_fput($this->connection(), $location, $contents, $this->connectionOptions->transferMode())) {
                throw FilesystemException::atLocation($path, 'ftp_fput failed');
            }

            $this->clearListingCache();

            $visibility = $config->get(Config::OPTION_VISIBILITY);

            if (is_string($visibility) && $visibility !== '') {
                try {
                    $this->setVisibility($path, $visibility);
                } catch (Throwable $exception) {
                }
            }
        }

        public function read(string $path): string
        {
            $stream = $this->readStream($path);
            $contents = stream_get_contents($stream);
            $this->closeTemporaryStream($stream, null);

            if ($contents === false) {
                throw FilesystemException::fromLocation($path, 'stream_get_contents failed');
            }

            return $contents;
        }

        public function readStream(string $path)
        {
            $location = $this->prefixer->prefixPath($path);
            list($stream, $tmpFile) = $this->createTemporaryStream('ftr');

            if ($stream === false) {
                throw FilesystemException::fromLocation($path, 'failed to create temp stream');
            }

            if (!@ftp_fget($this->connection(), $stream, $location, $this->connectionOptions->transferMode())) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw FilesystemException::fromLocation($path, 'ftp_fget failed');
            }

            if (!rewind($stream)) {
                $this->closeTemporaryStream($stream, $tmpFile);
                throw FilesystemException::fromLocation($path, 'failed to rewind temp stream');
            }

            return $stream;
        }

        private function readMimeTypeDetectionBuffer(string $path): string
        {
            $stream = $this->readStream($path);
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
            } finally {
                $this->closeTemporaryStream($stream, null);
            }

            return $buffer;
        }

        public function delete(string $path): void
        {
            if (!$this->fileExists($path)) {
                return;
            }

            $this->deleteKnownFile($path);
            $this->clearListingCache();
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

            $this->deleteDirectoryContents($path, $path);
            $this->deleteKnownDirectory($path, $path);
            $this->clearListingCache();
        }

        public function createDirectory(string $path, Config $config): void
        {
            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));
            $this->ensureDirectoryExists($path, $visibility);
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $location = $this->prefixer->prefixPath($path);
            $mode = $this->directoryExists($path)
                ? $this->visibilityConverter->forDirectory($visibility)
                : $this->visibilityConverter->forFile($visibility);

            if (!function_exists('ftp_chmod') || !@ftp_chmod($this->connection(), $mode, $location)) {
                throw FilesystemException::atLocation($path, 'ftp_chmod failed');
            }

            $this->clearListingCache();
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->fetchMetadata($path, StorageAttributes::ATTRIBUTE_VISIBILITY);
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
            $location = $this->prefixer->prefixPath($path);
            $lastModified = @ftp_mdtm($this->connection(), $location);

            if ($lastModified < 0) {
                throw FilesystemException::lastModified($path, 'ftp_mdtm failed');
            }

            return new FileAttributes($path, null, null, $lastModified);
        }

        public function fileSize(string $path): FileAttributes
        {
            $location = $this->prefixer->prefixPath($path);
            $size = @ftp_size($this->connection(), $location);

            if ($size < 0) {
                throw FilesystemException::fileSize($path, 'ftp_size failed');
            }

            return new FileAttributes($path, $size);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            $path = trim($path, '/');

            return $this->listDirectoryRecursive($path, $deep);
        }

        public function move(string $source, string $destination, Config $config): void
        {
            if ($source === $destination) {
                return;
            }

            $this->ensureParentDirectoryExists($destination, $config);

            if ($this->fileExists($destination)) {
                $this->delete($destination);
            }

            if ($this->directoryExists($destination)) {
                $this->deleteDirectory($destination);
            }

            $sourceLocation = $this->prefixer->prefixPath($source);
            $destinationLocation = $this->prefixer->prefixPath($destination);

            if (!@ftp_rename($this->connection(), $sourceLocation, $destinationLocation)) {
                throw FilesystemException::fromLocationTo($source, $destination);
            }

            $this->clearListingCache();
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
            if ($this->connection) {
                @ftp_close($this->connection);
            }

            $this->connection = false;
            $this->directoryExistsCache = ['' => true, '/' => true];
            $this->directoryListingCache = [];

            foreach ($this->temporaryStreamFiles as $file) {
                if (is_string($file) && $file !== '' && is_file($file)) {
                    @unlink($file);
                }
            }

            $this->temporaryStreamFiles = [];
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

        private function connection()
        {
            if ($this->connection !== false) {
                return $this->connection;
            }

            $this->connection = $this->connectionProvider->createConnection($this->connectionOptions);

            return $this->connection;
        }

        private function ensureParentDirectoryExists(string $path, Config $config): void
        {
            $parent = dirname($path);
            $parent = $parent === '.' ? '' : trim($parent, '/');

            if ($parent === '' || $parent === '/') {
                return;
            }

            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));
            $this->ensureDirectoryExists($parent, $visibility);
        }

        private function ensureDirectoryExists(string $path, string $visibility): void
        {
            $path = trim($path, '/');

            if ($path === '') {
                return;
            }

            $segments = explode('/', $path);
            $current = '';

            foreach ($segments as $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '' ? $segment : $current . '/' . $segment;

                if (isset($this->directoryExistsCache[$current])) {
                    continue;
                }

                $location = $this->prefixer->prefixPath($current);

                if (!@ftp_mkdir($this->connection(), $location)) {
                    if (!$this->directoryExists($current)) {
                        throw FilesystemException::atLocation($path, 'ftp_mkdir failed');
                    }
                } elseif (function_exists('ftp_chmod')) {
                    @ftp_chmod($this->connection(), $this->visibilityConverter->forDirectory($visibility), $location);
                }

                $this->directoryExistsCache[$current] = true;
            }

            $this->clearListingCache();
        }

        private function fetchMetadata(string $path, string $type): FileAttributes
        {
            $entry = $this->findEntry($path);

            if (!$entry instanceof FileAttributes) {
                throw FilesystemException::create($path, $type, 'file entry was not found');
            }

            return $entry;
        }

        private function findEntry(string $path): ?StorageAttributes
        {
            $parent = dirname($path);
            $parent = $parent === '.' ? '' : trim($parent, '/');
            $name = basename($path);

            foreach ($this->listContents($parent, false) as $item) {
                if (basename($item->path()) === $name) {
                    return $item;
                }
            }

            return null;
        }

        private function deleteKnownFile(string $path): void
        {
            $location = $this->prefixer->prefixPath($path);

            if (!@ftp_delete($this->connection(), $location)) {
                throw FilesystemException::atLocation($path, 'ftp_delete failed');
            }

            $parent = dirname($path);
            unset($this->directoryListingCache[$parent === '.' ? '' : trim($parent, '/')]);
        }

        private function deleteDirectoryContents(string $path, string $rootPath): void
        {
            try {
                foreach ($this->listContents($path, false) as $item) {
                    if ($item->isDir()) {
                        $this->deleteDirectoryContents($item->path(), $rootPath);
                        $this->deleteKnownDirectory($item->path(), $rootPath);
                        continue;
                    }

                    try {
                        $this->deleteKnownFile($item->path());
                    } catch (Throwable $exception) {
                        throw FilesystemException::atLocation($rootPath, 'failed to delete child file', $exception);
                    }
                }
            } finally {
                unset($this->directoryListingCache[trim($path, '/')]);
            }
        }

        private function deleteKnownDirectory(string $path, string $rootPath): void
        {
            $directory = trim($path, '/');
            $location = $this->prefixer->prefixPath($directory);

            if ($location === '' || $location === '/') {
                throw FilesystemException::atLocation($rootPath, 'refusing to delete root directory');
            }

            if (!@ftp_rmdir($this->connection(), $location) && $this->directoryExistsUncached($directory)) {
                throw FilesystemException::atLocation($rootPath, 'ftp_rmdir failed for ' . $directory);
            }

            unset($this->directoryExistsCache[$directory], $this->directoryListingCache[$directory]);
        }

        private function directoryExistsUncached(string $path): bool
        {
            $path = trim($path, '/');

            if ($path === '') {
                return true;
            }

            $connection = $this->connection();
            $current = @ftp_pwd($connection);

            if ($current === false) {
                throw FilesystemException::forLocation($path);
            }

            $result = @ftp_chdir($connection, $this->prefixer->prefixPath($path));

            if ($result) {
                @ftp_chdir($connection, $current);
                return true;
            }

            return false;
        }

        private function listDirectoryRecursive(string $path, bool $deep): iterable
        {
            $path = trim($path, '/');
            $rawListing = $this->rawListing($path);

            if ($rawListing === []) {
                return;
            }

            try {
                foreach ($rawListing as $line) {
                    $item = $this->normalizeObject($line, $path);

                    if (!$item instanceof StorageAttributes) {
                        continue;
                    }

                    yield $item;

                    if ($deep && $item->isDir()) {
                        yield from $this->listDirectoryRecursive($item->path(), true);
                    }
                }
            } finally {
                if ($deep) {
                    unset($this->directoryListingCache[$path]);
                }
            }
        }

        private function rawListing(string $path): array
        {
            $path = trim($path, '/');

            if (isset($this->directoryListingCache[$path])) {
                return $this->directoryListingCache[$path];
            }

            $location = $this->prefixer->prefixPath($path);
            $rawListing = @ftp_rawlist($this->connection(), $location === '' ? '.' : $location, false);

            if (!is_array($rawListing)) {
                return [];
            }

            $this->directoryExistsCache[$path] = true;

            return $this->directoryListingCache[$path] = $rawListing;
        }

        private function clearListingCache(): void
        {
            $this->directoryListingCache = [];
        }

        private function normalizeObject(string $line, string $basePath): ?StorageAttributes
        {
            $line = trim($line);

            if ($line === '' || preg_match('#\s\.(\.)?$#', $line) || str_starts_with($line, 'total')) {
                return null;
            }

            if ($this->systemType === null) {
                $this->systemType = preg_match('/^[0-9]{2,4}-[0-9]{2}-[0-9]{2}/', $line) ? self::SYSTEM_TYPE_WINDOWS : self::SYSTEM_TYPE_UNIX;
            }

            return $this->systemType === self::SYSTEM_TYPE_WINDOWS
                ? $this->normalizeWindowsObject($line, $basePath)
                : $this->normalizeUnixObject($line, $basePath);
        }

        private function normalizeWindowsObject(string $line, string $basePath): ?StorageAttributes
        {
            $line = preg_replace('#\s+#', ' ', $line);
            $parts = explode(' ', $line, 4);

            if (count($parts) !== 4) {
                return null;
            }

            list($date, $time, $size, $name) = $parts;
            $name = trim($name);

            if ($name === '.' || $name === '..') {
                return null;
            }

            $path = $basePath === '' ? $name : trim($basePath, '/') . '/' . $name;
            $dateTime = DateTime::createFromFormat(strlen($date) === 8 ? 'm-d-y h:ia' : 'Y-m-d H:i', strtolower($date . ' ' . $time)) ?: new DateTime();
            $timestamp = $dateTime->getTimestamp();

            if (strtoupper($size) === '<DIR>') {
                return new DirectoryAttributes($path, null, $timestamp);
            }

            return new FileAttributes($path, (int) $size, null, $timestamp);
        }

        private function normalizeUnixObject(string $line, string $basePath): ?StorageAttributes
        {
            $line = preg_replace('#\s+#', ' ', $line, 8);
            $parts = explode(' ', $line, 9);

            if (count($parts) !== 9) {
                return null;
            }

            list($permissions, $number, $owner, $group, $size, $month, $day, $timeOrYear, $name) = $parts;
            $name = preg_replace('#\s+->.*$#', '', trim($name));

            if ($name === '.' || $name === '..') {
                return null;
            }

            $path = $basePath === '' ? $name : trim($basePath, '/') . '/' . $name;
            $mode = $this->normalizePermissions($permissions);
            $timestamp = $this->connectionOptions->timestampsOnUnixListingsEnabled() ? $this->normalizeUnixTimestamp($month, $day, $timeOrYear) : null;

            if (str_starts_with($permissions, 'l')) {
                throw FilesystemException::create($path, 'link', 'symbolic links are not allowed');
            }

            if (str_starts_with($permissions, 'd')) {
                return new DirectoryAttributes($path, $this->visibilityConverter->inverseForDirectory($mode), $timestamp);
            }

            return new FileAttributes($path, (int) $size, $this->visibilityConverter->inverseForFile($mode), $timestamp);
        }

        private function normalizePermissions(string $permissions): int
        {
            $permissions = substr($permissions, 1, 9);
            $map = ['-' => '0', 'r' => '4', 'w' => '2', 'x' => '1', 's' => '1', 't' => '1'];
            $permissions = strtr($permissions, $map);
            $groups = str_split($permissions, 3);
            $numeric = '';

            foreach ($groups as $group) {
                $numeric .= (string) array_sum(array_map('intval', str_split($group)));
            }

            return octdec($numeric);
        }

        private function normalizeUnixTimestamp(string $month, string $day, string $timeOrYear): int
        {
            if (strpos($timeOrYear, ':') !== false) {
                $format = 'Y M d H:i';
                $value = date('Y') . ' ' . $month . ' ' . $day . ' ' . $timeOrYear;
            } else {
                $format = 'Y M d H:i';
                $value = $timeOrYear . ' ' . $month . ' ' . $day . ' 00:00';
            }

            $date = DateTime::createFromFormat($format, $value);

            if ($date && strpos($timeOrYear, ':') !== false && $date->getTimestamp() > time() + 86400) {
                $date->modify('-1 year');
            }

            return $date ? $date->getTimestamp() : time();
        }
    }
}
