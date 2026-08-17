<?php

declare(strict_types=1);

namespace {

    spl_autoload_register(static function (string $class): void {
        static $map = [
            'DleFilesystem\\Adapters\\LocalAdapter' => ENGINE_DIR . '/classes/filesystem/adapters/local.adapter.php',
            'DleFilesystem\\Adapters\\FtpConnectionOptions' => ENGINE_DIR . '/classes/filesystem/adapters/ftp.adapter.php',
            'DleFilesystem\\Adapters\\FtpConnectionProvider' => ENGINE_DIR . '/classes/filesystem/adapters/ftp.adapter.php',
            'DleFilesystem\\Adapters\\FtpAdapter' => ENGINE_DIR . '/classes/filesystem/adapters/ftp.adapter.php',
            'DleFilesystem\\Adapters\\SftpConnectionProvider' => ENGINE_DIR . '/classes/filesystem/adapters/sftp.adapter.php',
            'DleFilesystem\\Adapters\\SftpAdapter' => ENGINE_DIR . '/classes/filesystem/adapters/sftp.adapter.php',
            'DleFilesystem\\S3\\S3Client' => ENGINE_DIR . '/classes/filesystem/adapters/s3.adapter.php',
            'DleFilesystem\\Adapters\\S3VisibilityConverter' => ENGINE_DIR . '/classes/filesystem/adapters/s3.adapter.php',
            'DleFilesystem\\Adapters\\S3Adapter' => ENGINE_DIR . '/classes/filesystem/adapters/s3.adapter.php',
            'DleFilesystem\\WebDAV\\Client' => ENGINE_DIR . '/classes/filesystem/adapters/webdav.adapter.php',
            'DleFilesystem\\Adapters\\WebDAVAdapter' => ENGINE_DIR . '/classes/filesystem/adapters/webdav.adapter.php',
        ];

        if (isset($map[$class])) {
            include_once(DLEPlugins::Check($map[$class]));
        }
    }, true, true);
}

namespace DleFilesystem {

    use ArrayAccess;
    use DateTimeInterface;
    use IteratorAggregate;
    use LogicException;
    use RuntimeException;
    use Throwable;
    use Traversable;

    class FilesystemException extends RuntimeException
    {
        public static function create(string $location = '', $context = '', $message = '', ?Throwable $previous = null): self
        {
            $parts = [];

            if ($location !== '') {
                $parts[] = $location;
            }

            if (is_string($context) && $context !== '') {
                $parts[] = $context;
            }

            if (is_string($message) && $message !== '') {
                $parts[] = $message;
            } elseif ($message instanceof Throwable && $previous === null) {
                $previous = $message;
            }

            if ($previous && $previous->getMessage() !== '') {
                $parts[] = $previous->getMessage();
            }

            return new self($parts ? implode(': ', $parts) : 'Filesystem error', 0, $previous);
        }

        public static function forLocation(string $location, ?Throwable $previous = null): self
        {
            return static::create($location, '', '', $previous);
        }

        public static function atLocation(string $location, $message = '', ?Throwable $previous = null): self
        {
            if (is_bool($message)) {
                $message = $message ? 'deep listing failed' : 'listing failed';
            }

            return static::create($location, '', is_string($message) ? $message : '', $previous);
        }

        public static function fromLocation(string $location, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($location, '', $message, $previous);
        }

        public static function dueToFailure(string $location, ?Throwable $previous = null): self
        {
            return static::create($location, '', '', $previous);
        }

        public static function mimeType(string $location, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($location, 'mime_type', $message, $previous);
        }

        public static function lastModified(string $location, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($location, 'last_modified', $message, $previous);
        }

        public static function fileSize(string $location, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($location, 'file_size', $message, $previous);
        }

        public static function visibility(string $location, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($location, 'visibility', $message, $previous);
        }

        public static function fromLocationTo(string $source, string $destination, ?Throwable $previous = null): self
        {
            return static::create($source . ' -> ' . $destination, '', '', $previous);
        }

        public static function because(string $message, string $source = '', string $destination = '', ?Throwable $previous = null): self
        {
            $location = trim($source . ($destination !== '' ? ' -> ' . $destination : ''), ' ');

            return static::create($location, '', $message, $previous);
        }

        public static function sourceAndDestinationAreTheSame(string $source, string $destination): self
        {
            return static::create($source . ' -> ' . $destination, '', 'source and destination are the same');
        }

        public static function noGeneratorConfigured(string $path, string $message = ''): self
        {
            return static::create($path, '', $message !== '' ? $message : 'generator is not configured');
        }

        public static function dueToError(string $path, ?Throwable $previous = null): self
        {
            return static::create($path, '', '', $previous);
        }

        public static function forPath(string $path, string $message = '', ?Throwable $previous = null): self
        {
            return static::create($path, '', $message, $previous);
        }
    }

    class Visibility
    {
        public const PUBLIC = 'public';
        public const PRIVATE = 'private';
    }

    class Config
    {
        public const OPTION_COPY_IDENTICAL_PATH = 'copy_destination_same_as_source';
        public const OPTION_MOVE_IDENTICAL_PATH = 'move_destination_same_as_source';
        public const OPTION_VISIBILITY = 'visibility';
        public const OPTION_DIRECTORY_VISIBILITY = 'directory_visibility';
        public const OPTION_RETAIN_VISIBILITY = 'retain_visibility';

        private array $options = [];

        public function __construct(array $options = [])
        {
            $this->options = $options;
        }

        public function get(string $property, $default = null)
        {
            return $this->options[$property] ?? $default;
        }

        public function extend(array $options): self
        {
            return new self(array_merge($this->options, $options));
        }

        public function withDefaults(array $defaults): self
        {
            return new self($this->options + $defaults);
        }

        public function withSetting(string $property, $setting): self
        {
            return $this->extend([$property => $setting]);
        }

        public function withoutSettings(string ...$settings): self
        {
            $options = $this->options;

            foreach ($settings as $setting) {
                unset($options[$setting]);
            }

            return new self($options);
        }

        public function toArray(): array
        {
            return $this->options;
        }
    }

    interface StorageAttributes extends \JsonSerializable, ArrayAccess
    {
        public const ATTRIBUTE_PATH = 'path';
        public const ATTRIBUTE_TYPE = 'type';
        public const ATTRIBUTE_FILE_SIZE = 'file_size';
        public const ATTRIBUTE_VISIBILITY = 'visibility';
        public const ATTRIBUTE_LAST_MODIFIED = 'last_modified';
        public const ATTRIBUTE_MIME_TYPE = 'mime_type';
        public const ATTRIBUTE_EXTRA_METADATA = 'extra_metadata';

        public const TYPE_FILE = 'file';
        public const TYPE_DIRECTORY = 'dir';

        public function path(): string;

        public function type(): string;

        public function visibility(): ?string;

        public function lastModified(): ?int;

        public static function fromArray(array $attributes): StorageAttributes;

        public function isFile(): bool;

        public function isDir(): bool;

        public function withPath(string $path): StorageAttributes;

        public function extraMetadata(): array;
    }

    trait ProxyArrayAccessToProperties
    {
        #[\ReturnTypeWillChange]
        public function offsetExists($offset): bool
        {
            return method_exists($this, (string) $offset);
        }

        #[\ReturnTypeWillChange]
        public function offsetGet($offset)
        {
            $method = (string) $offset;

            if (!method_exists($this, $method)) {
                return null;
            }

            return $this->{$method}();
        }

        #[\ReturnTypeWillChange]
        public function offsetSet($offset, $value): void
        {
            throw new LogicException('Storage attributes are read only');
        }

        #[\ReturnTypeWillChange]
        public function offsetUnset($offset): void
        {
            throw new LogicException('Storage attributes are read only');
        }
    }

    class FileAttributes implements StorageAttributes
    {
        use ProxyArrayAccessToProperties;

        private string $path;
        private ?int $fileSize;
        private ?string $visibility;
        private ?int $lastModified;
        private ?string $mimeType;
        private array $extraMetadata;

        public function __construct(string $path, ?int $fileSize = null, ?string $visibility = null, ?int $lastModified = null, ?string $mimeType = null, array $extraMetadata = [])
        {
            $this->path = ltrim(str_replace('\\', '/', $path), '/');
            $this->fileSize = $fileSize;
            $this->visibility = $visibility;
            $this->lastModified = $lastModified;
            $this->mimeType = $mimeType;
            $this->extraMetadata = $extraMetadata;
        }

        public function type(): string
        {
            return self::TYPE_FILE;
        }

        public function path(): string
        {
            return $this->path;
        }

        public function fileSize(): ?int
        {
            return $this->fileSize;
        }

        public function visibility(): ?string
        {
            return $this->visibility;
        }

        public function lastModified(): ?int
        {
            return $this->lastModified;
        }

        public function mimeType(): ?string
        {
            return $this->mimeType;
        }

        public function extraMetadata(): array
        {
            return $this->extraMetadata;
        }

        public function isFile(): bool
        {
            return true;
        }

        public function isDir(): bool
        {
            return false;
        }

        public function withPath(string $path): StorageAttributes
        {
            return new self($path, $this->fileSize, $this->visibility, $this->lastModified, $this->mimeType, $this->extraMetadata);
        }

        public static function fromArray(array $attributes): StorageAttributes
        {
            return new self(
                (string) ($attributes[self::ATTRIBUTE_PATH] ?? ''),
                isset($attributes[self::ATTRIBUTE_FILE_SIZE]) ? (int) $attributes[self::ATTRIBUTE_FILE_SIZE] : null,
                isset($attributes[self::ATTRIBUTE_VISIBILITY]) ? (string) $attributes[self::ATTRIBUTE_VISIBILITY] : null,
                isset($attributes[self::ATTRIBUTE_LAST_MODIFIED]) ? (int) $attributes[self::ATTRIBUTE_LAST_MODIFIED] : null,
                isset($attributes[self::ATTRIBUTE_MIME_TYPE]) ? (string) $attributes[self::ATTRIBUTE_MIME_TYPE] : null,
                isset($attributes[self::ATTRIBUTE_EXTRA_METADATA]) && is_array($attributes[self::ATTRIBUTE_EXTRA_METADATA]) ? $attributes[self::ATTRIBUTE_EXTRA_METADATA] : []
            );
        }

        public function jsonSerialize(): array
        {
            return [
                self::ATTRIBUTE_TYPE => self::TYPE_FILE,
                self::ATTRIBUTE_PATH => $this->path,
                self::ATTRIBUTE_FILE_SIZE => $this->fileSize,
                self::ATTRIBUTE_VISIBILITY => $this->visibility,
                self::ATTRIBUTE_LAST_MODIFIED => $this->lastModified,
                self::ATTRIBUTE_MIME_TYPE => $this->mimeType,
                self::ATTRIBUTE_EXTRA_METADATA => $this->extraMetadata,
            ];
        }
    }

    class DirectoryAttributes implements StorageAttributes
    {
        use ProxyArrayAccessToProperties;

        private string $path;
        private ?string $visibility;
        private ?int $lastModified;
        private array $extraMetadata;

        public function __construct(string $path, ?string $visibility = null, ?int $lastModified = null, array $extraMetadata = [])
        {
            $this->path = trim(str_replace('\\', '/', $path), '/');
            $this->visibility = $visibility;
            $this->lastModified = $lastModified;
            $this->extraMetadata = $extraMetadata;
        }

        public function path(): string
        {
            return $this->path;
        }

        public function type(): string
        {
            return self::TYPE_DIRECTORY;
        }

        public function visibility(): ?string
        {
            return $this->visibility;
        }

        public function lastModified(): ?int
        {
            return $this->lastModified;
        }

        public function extraMetadata(): array
        {
            return $this->extraMetadata;
        }

        public function isFile(): bool
        {
            return false;
        }

        public function isDir(): bool
        {
            return true;
        }

        public function withPath(string $path): StorageAttributes
        {
            return new self($path, $this->visibility, $this->lastModified, $this->extraMetadata);
        }

        public static function fromArray(array $attributes): StorageAttributes
        {
            return new self(
                (string) ($attributes[self::ATTRIBUTE_PATH] ?? ''),
                isset($attributes[self::ATTRIBUTE_VISIBILITY]) ? (string) $attributes[self::ATTRIBUTE_VISIBILITY] : null,
                isset($attributes[self::ATTRIBUTE_LAST_MODIFIED]) ? (int) $attributes[self::ATTRIBUTE_LAST_MODIFIED] : null,
                isset($attributes[self::ATTRIBUTE_EXTRA_METADATA]) && is_array($attributes[self::ATTRIBUTE_EXTRA_METADATA]) ? $attributes[self::ATTRIBUTE_EXTRA_METADATA] : []
            );
        }

        public function jsonSerialize(): array
        {
            return [
                self::ATTRIBUTE_TYPE => self::TYPE_DIRECTORY,
                self::ATTRIBUTE_PATH => $this->path,
                self::ATTRIBUTE_VISIBILITY => $this->visibility,
                self::ATTRIBUTE_LAST_MODIFIED => $this->lastModified,
                self::ATTRIBUTE_EXTRA_METADATA => $this->extraMetadata,
            ];
        }
    }

    class DirectoryListing implements IteratorAggregate
    {
        private iterable $listing;

        public function __construct(iterable $listing)
        {
            $this->listing = $listing;
        }

        public function filter(callable $filter): self
        {
            $items = [];

            foreach ($this->listing as $item) {
                if ($filter($item)) {
                    $items[] = $item;
                }
            }

            return new self($items);
        }

        public function map(callable $mapper): self
        {
            $items = [];

            foreach ($this->listing as $item) {
                $items[] = $mapper($item);
            }

            return new self($items);
        }

        public function sortByPath(): self
        {
            $items = $this->toArray();

            usort($items, static function (StorageAttributes $left, StorageAttributes $right): int {
                return $left->path() <=> $right->path();
            });

            return new self($items);
        }

        public function getIterator(): Traversable
        {
            if ($this->listing instanceof Traversable) {
                return $this->listing;
            }

            return new \ArrayIterator($this->toArray());
        }

        public function toArray(): array
        {
            if ($this->listing instanceof Traversable) {
                return iterator_to_array($this->listing, false);
            }

            return is_array($this->listing) ? $this->listing : [];
        }
    }

    interface FilesystemReader
    {
        public const LIST_SHALLOW = false;
        public const LIST_DEEP = true;

        public function fileExists(string $location): bool;

        public function directoryExists(string $location): bool;

        public function has(string $location): bool;

        public function read(string $location): string;

        public function readStream(string $location);

        public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing;

        public function lastModified(string $path): int;

        public function fileSize(string $path): int;

        public function mimeType(string $path): string;

        public function visibility(string $path): string;
    }

    interface FilesystemWriter
    {
        public function write(string $location, string $contents, array $config = []): void;

        public function writeStream(string $location, $contents, array $config = []): void;

        public function setVisibility(string $path, string $visibility): void;

        public function delete(string $location): void;

        public function deleteDirectory(string $location): void;

        public function createDirectory(string $location, array $config = []): void;

        public function move(string $source, string $destination, array $config = []): void;

        public function copy(string $source, string $destination, array $config = []): void;
    }

    interface FilesystemOperator extends FilesystemReader, FilesystemWriter
    {
    }

    interface FilesystemAdapter
    {
        public function fileExists(string $path): bool;

        public function directoryExists(string $path): bool;

        public function write(string $path, string $contents, Config $config): void;

        public function writeStream(string $path, $contents, Config $config): void;

        public function read(string $path): string;

        public function readStream(string $path);

        public function delete(string $path): void;

        public function deleteDirectory(string $path): void;

        public function createDirectory(string $path, Config $config): void;

        public function setVisibility(string $path, string $visibility): void;

        public function visibility(string $path): FileAttributes;

        public function mimeType(string $path): FileAttributes;

        public function lastModified(string $path): FileAttributes;

        public function fileSize(string $path): FileAttributes;

        public function listContents(string $path, bool $deep): iterable;

        public function move(string $source, string $destination, Config $config): void;

        public function copy(string $source, string $destination, Config $config): void;
    }

    interface ChecksumProvider
    {
        public function checksum(string $path, Config $config): string;
    }

    class PathPrefixer
    {
        private string $prefix;

        public function __construct(string $prefix, string $separator = '/')
        {
            $prefix = str_replace('\\', '/', trim($prefix));

            if ($prefix === '' || $prefix === '.') {
                $this->prefix = '';
                return;
            }

            $prefix = rtrim($prefix, '/');

            if ($separator === '/') {
                $this->prefix = $prefix . '/';
            } else {
                $this->prefix = rtrim(str_replace('/', $separator, $prefix), $separator) . $separator;
            }
        }

        public function prefixPath(string $path): string
        {
            $path = self::normalizeRelativePath($path);

            return $this->prefix . $path;
        }

        public function prefixDirectoryPath(string $path): string
        {
            $path = self::normalizeRelativePath($path);

            if ($path === '') {
                return $this->prefix;
            }

            return $this->prefix . $path . '/';
        }

        public static function normalizeRelativePath(string $path): string
        {
            $originalPath = $path;
            $path = trim(str_replace('\\', '/', $path));

            if ($path === '') {
                return '';
            }

            if (strpos($path, "\0") !== false) {
                throw FilesystemException::forPath($originalPath, 'path contains invalid characters');
            }

            if (preg_match('#\p{C}+#u', $path)) {
                throw FilesystemException::forPath($originalPath, 'path contains invalid characters');
            }

            $segments = explode('/', $path);
            $normalized = [];

            foreach ($segments as $segment) {
                $segment = trim($segment);

                if ($segment === '' || $segment === '.') {
                    continue;
                }

                if ($segment === '..') {
                    throw FilesystemException::forPath($originalPath, 'path traversal is not allowed');
                }

                $normalized[] = $segment;
            }

            return implode('/', $normalized);
        }

        public function stripPrefix(string $path): string
        {
            $path = str_replace('\\', '/', $path);

            if ($this->prefix !== '' && strpos($path, $this->prefix) === 0) {
                return ltrim(substr($path, strlen($this->prefix)), '/');
            }

            return ltrim($path, '/');
        }

        public function stripDirectoryPrefix(string $path): string
        {
            return rtrim($this->stripPrefix($path), '/');
        }
    }

    class Filesystem implements FilesystemOperator
    {
        public const IDENTICAL_PATH_TRY = 'try';
        public const IDENTICAL_PATH_FAIL = 'fail';
        public const IDENTICAL_PATH_IGNORE = 'ignore';

        private FilesystemAdapter $adapter;
        private Config $config;
        private ?\DleFilesystem\UrlGeneration\PublicUrlGenerator $publicUrlGenerator;
        private ?\DleFilesystem\UrlGeneration\TemporaryUrlGenerator $temporaryUrlGenerator;
        private ?string $publicUrlPrefix = null;
        private ?array $publicUrlPrefixes = null;

        public function __construct(FilesystemAdapter $adapter, array $config = [], $pathNormalizer = null, ?\DleFilesystem\UrlGeneration\PublicUrlGenerator $publicUrlGenerator = null, ?\DleFilesystem\UrlGeneration\TemporaryUrlGenerator $temporaryUrlGenerator = null)
        {
            $this->adapter = $adapter;
            $this->config = new Config($config);
            $this->publicUrlGenerator = $publicUrlGenerator;
            $this->temporaryUrlGenerator = $temporaryUrlGenerator;
        }

        public function fileExists(string $location): bool
        {
            return $this->adapter->fileExists($this->normalizePath($location));
        }

        public function directoryExists(string $location): bool
        {
            return $this->adapter->directoryExists($this->normalizePath($location));
        }

        public function has(string $location): bool
        {
            $location = $this->normalizePath($location);

            return $this->adapter->fileExists($location) || $this->adapter->directoryExists($location);
        }

        public function write(string $location, string $contents, array $config = []): void
        {
            $this->adapter->write($this->normalizePath($location), $contents, $this->config->extend($config));
        }

        public function writeStream(string $location, $contents, array $config = []): void
        {
            if (!is_resource($contents) || get_resource_type($contents) !== 'stream') {
                throw FilesystemException::forPath($location, 'invalid stream provided');
            }

            if (@ftell($contents) !== 0 && (stream_get_meta_data($contents)['seekable'] ?? false)) {
                rewind($contents);
            }

            $this->adapter->writeStream($this->normalizePath($location), $contents, $this->config->extend($config));
        }

        public function read(string $location): string
        {
            return $this->adapter->read($this->normalizePath($location));
        }

        public function readStream(string $location)
        {
            return $this->adapter->readStream($this->normalizePath($location));
        }

        public function delete(string $location): void
        {
            $this->adapter->delete($this->normalizePath($location));
        }

        public function deleteDirectory(string $location): void
        {
            $this->adapter->deleteDirectory($this->normalizePath($location));
        }

        public function createDirectory(string $location, array $config = []): void
        {
            $this->adapter->createDirectory($this->normalizePath($location), $this->config->extend($config));
        }

        public function listContents(string $location, bool $deep = self::LIST_SHALLOW): DirectoryListing
        {
            $location = $this->normalizePath($location);

            try {
                return new DirectoryListing($this->adapter->listContents($location, $deep));
            } catch (Throwable $exception) {
                throw FilesystemException::atLocation($location, $deep, $exception);
            }
        }

        public function move(string $source, string $destination, array $config = []): void
        {
            $config = $this->resolveConfigForMoveAndCopy($config);
            $source = $this->normalizePath($source);
            $destination = $this->normalizePath($destination);

            if ($source === $destination) {
                $strategy = (string) $config->get(Config::OPTION_MOVE_IDENTICAL_PATH, self::IDENTICAL_PATH_TRY);

                if ($strategy === self::IDENTICAL_PATH_FAIL) {
                    throw FilesystemException::sourceAndDestinationAreTheSame($source, $destination);
                }

                if ($strategy === self::IDENTICAL_PATH_IGNORE) {
                    return;
                }
            }

            $this->adapter->move($source, $destination, $config);
        }

        public function copy(string $source, string $destination, array $config = []): void
        {
            $config = $this->resolveConfigForMoveAndCopy($config);
            $source = $this->normalizePath($source);
            $destination = $this->normalizePath($destination);

            if ($source === $destination) {
                $strategy = (string) $config->get(Config::OPTION_COPY_IDENTICAL_PATH, self::IDENTICAL_PATH_TRY);

                if ($strategy === self::IDENTICAL_PATH_FAIL) {
                    throw FilesystemException::sourceAndDestinationAreTheSame($source, $destination);
                }

                if ($strategy === self::IDENTICAL_PATH_IGNORE) {
                    return;
                }
            }

            $this->adapter->copy($source, $destination, $config);
        }

        public function lastModified(string $path): int
        {
            return (int) $this->adapter->lastModified($this->normalizePath($path))->lastModified();
        }

        public function fileSize(string $path): int
        {
            return (int) $this->adapter->fileSize($this->normalizePath($path))->fileSize();
        }

        public function mimeType(string $path): string
        {
            return (string) $this->adapter->mimeType($this->normalizePath($path))->mimeType();
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $this->adapter->setVisibility($this->normalizePath($path), $visibility);
        }

        public function visibility(string $path): string
        {
            return (string) $this->adapter->visibility($this->normalizePath($path))->visibility();
        }

        public function publicUrl(string $path, array $config = []): string
        {
            $path = $this->normalizePath($path);
            $config = $this->config->extend($config);

            if ($this->publicUrlGenerator instanceof \DleFilesystem\UrlGeneration\PublicUrlGenerator) {
                return $this->publicUrlGenerator->publicUrl($path, $config);
            }

            if (is_array($this->publicUrlPrefixes)) {
                if (count($this->publicUrlPrefixes) === 0) {
                    return ltrim($path, '/');
                }

                $index = (int) (sprintf('%u', crc32($path)) % count($this->publicUrlPrefixes));
                $prefix = rtrim($this->publicUrlPrefixes[$index], '/') . '/';

                return $path === '' ? $prefix : $prefix . ltrim($path, '/');
            }

            if (is_string($this->publicUrlPrefix)) {
                return $path === '' ? $this->publicUrlPrefix : $this->publicUrlPrefix . ltrim($path, '/');
            }

            if ($this->publicUrlGenerator === null) {
                $publicUrl = $config->get('public_url');

                if (is_array($publicUrl)) {
                    $this->publicUrlPrefixes = array_values(array_filter($publicUrl, 'is_string'));
                    
                    if (count($this->publicUrlPrefixes) === 0) {
                        return ltrim($path, '/');
                    }

                    $index = (int) (sprintf('%u', crc32($path)) % count($this->publicUrlPrefixes));
                    $prefix = rtrim($this->publicUrlPrefixes[$index], '/') . '/';

                    return $path === '' ? $prefix : $prefix . ltrim($path, '/');
                } elseif (is_string($publicUrl) && $publicUrl !== '') {
                    $this->publicUrlPrefix = rtrim($publicUrl, '/') . '/';

                    return $path === '' ? $this->publicUrlPrefix : $this->publicUrlPrefix . ltrim($path, '/');
                } elseif ($this->adapter instanceof \DleFilesystem\UrlGeneration\PublicUrlGenerator) {
                    $this->publicUrlGenerator = $this->adapter;

                    return $this->publicUrlGenerator->publicUrl($path, $config);
                }
            }

            if ($this->publicUrlGenerator === null) {
                throw FilesystemException::noGeneratorConfigured($path);
            }

            return $this->publicUrlGenerator->publicUrl($path, $config);
        }

        public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
        {
            $path = $this->normalizePath($path);
            $config = $this->config->extend($config);
            $generator = $this->temporaryUrlGenerator;

            if ($generator === null && $this->adapter instanceof \DleFilesystem\UrlGeneration\TemporaryUrlGenerator) {
                $generator = $this->adapter;
            }

            if ($generator === null) {
                throw FilesystemException::noGeneratorConfigured($path);
            }

            return $generator->temporaryUrl($path, $expiresAt, $config);
        }

        public function checksum(string $path, array $config = []): string
        {
            $path = $this->normalizePath($path);
            $config = $this->config->extend($config);

            if ($this->adapter instanceof ChecksumProvider) {
                return $this->adapter->checksum($path, $config);
            }

            return $this->calculateChecksumFromStream($path, $config);
        }

        private function calculateChecksumFromStream(string $path, Config $config): string
        {
            $algo = (string) $config->get('checksum_algo', 'md5');
            $stream = $this->adapter->readStream($path);

            if (!is_resource($stream)) {
                throw FilesystemException::forPath($path, 'invalid stream received');
            }

            if (!in_array($algo, hash_algos(), true)) {
                fclose($stream);
                throw FilesystemException::forPath($path, 'hash algorithm is not supported');
            }

            $context = hash_init($algo);

            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);

                if ($chunk === false) {
                    fclose($stream);
                    throw FilesystemException::forPath($path, 'failed to read stream while calculating checksum');
                }

                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }

            fclose($stream);

            return hash_final($context);
        }

        private function normalizePath(string $path): string
        {
            return PathPrefixer::normalizeRelativePath($path);
        }

        private function resolveConfigForMoveAndCopy(array $config): Config
        {
            $retainVisibility = $this->config->get(Config::OPTION_RETAIN_VISIBILITY, $config[Config::OPTION_RETAIN_VISIBILITY] ?? true);
            $fullConfig = $this->config->extend($config);

            if ($retainVisibility && !array_key_exists(Config::OPTION_VISIBILITY, $config)) {
                $fullConfig = $fullConfig->withoutSettings(Config::OPTION_VISIBILITY)->extend($config);
            }

            return $fullConfig;
        }
    }
}

namespace DleFilesystem\UrlGeneration {

    use DateTimeInterface;
    use DleFilesystem\Config;

    interface PublicUrlGenerator
    {
        public function publicUrl(string $path, Config $config): string;
    }

    interface TemporaryUrlGenerator
    {
        public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string;
    }
}

namespace DleFilesystem\Visibility {

    use DleFilesystem\Visibility;

    interface VisibilityConverter
    {
        public function forFile(string $visibility): int;

        public function forDirectory(string $visibility): int;

        public function inverseForFile(int $visibility): string;

        public function inverseForDirectory(int $visibility): string;

        public function defaultForDirectories(): int;
    }

    class PortableVisibilityConverter implements VisibilityConverter
    {
        private array $file;
        private array $dir;
        private string $defaultVisibility;

        public function __construct(array $file = ['public' => 0644, 'private' => 0600], array $dir = ['public' => 0755, 'private' => 0700], string $defaultVisibility = Visibility::PUBLIC)
        {
            $this->file = $file;
            $this->dir = $dir;
            $this->defaultVisibility = in_array($defaultVisibility, [Visibility::PUBLIC, Visibility::PRIVATE], true) ? $defaultVisibility : Visibility::PUBLIC;
        }

        public static function fromArray(array $permissions, string $defaultVisibility = Visibility::PUBLIC): self
        {
            return new self($permissions['file'] ?? ['public' => 0644, 'private' => 0600], $permissions['dir'] ?? ['public' => 0755, 'private' => 0700], $defaultVisibility);
        }

        public function forFile(string $visibility): int
        {
            if (!isset($this->file[$visibility])) {
                throw new \DleFilesystem\FilesystemException('Invalid file visibility: ' . $visibility);
            }

            return (int) $this->file[$visibility];
        }

        public function forDirectory(string $visibility): int
        {
            if (!isset($this->dir[$visibility])) {
                throw new \DleFilesystem\FilesystemException('Invalid directory visibility: ' . $visibility);
            }

            return (int) $this->dir[$visibility];
        }

        public function inverseForFile(int $visibility): string
        {
            $visibility &= 0777;

            if (($this->file['private'] ?? null) === $visibility) {
                return Visibility::PRIVATE;
            }

            if (($this->file['public'] ?? null) === $visibility) {
                return Visibility::PUBLIC;
            }

            return ($visibility & 0004) ? Visibility::PUBLIC : Visibility::PRIVATE;
        }

        public function inverseForDirectory(int $visibility): string
        {
            $visibility &= 0777;

            if (($this->dir['private'] ?? null) === $visibility) {
                return Visibility::PRIVATE;
            }

            if (($this->dir['public'] ?? null) === $visibility) {
                return Visibility::PUBLIC;
            }

            return ($visibility & 0005) ? Visibility::PUBLIC : Visibility::PRIVATE;
        }

        public function defaultForDirectories(): int
        {
            return $this->forDirectory($this->defaultVisibility);
        }
    }
}

namespace DleFilesystem\MimeTypeDetection {

    interface MimeTypeDetector
    {
        public function detectMimeType(string $path, string $contents): ?string;

        public function detectMimeTypeFromPath(string $path): ?string;
    }

    class GeneratedExtensionToMimeTypeMap
    {
        public const MIME_TYPES_FOR_EXTENSIONS = [
                '1km' => 'application/vnd.1000minds.decision-model+xml',
                '3dml' => 'text/vnd.in3d.3dml',
                '3ds' => 'image/x-3ds',
                '3g2' => 'video/3gpp2',
                '3gp' => 'video/3gp',
                '3gpp' => 'video/3gpp',
                '3mf' => 'model/3mf',
                '7z' => 'application/x-7z-compressed',
                '7zip' => 'application/x-7z-compressed',
                '123' => 'application/vnd.lotus-1-2-3',
                'aab' => 'application/x-authorware-bin',
                'aac' => 'audio/acc',
                'aam' => 'application/x-authorware-map',
                'aas' => 'application/x-authorware-seg',
                'abw' => 'application/x-abiword',
                'ac' => 'application/vnd.nokia.n-gage.ac+xml',
                'ac3' => 'audio/ac3',
                'acc' => 'application/vnd.americandynamics.acc',
                'ace' => 'application/x-ace-compressed',
                'acu' => 'application/vnd.acucobol',
                'acutc' => 'application/vnd.acucorp',
                'adp' => 'audio/adpcm',
                'adts' => 'audio/aac',
                'aep' => 'application/vnd.audiograph',
                'afm' => 'application/x-font-type1',
                'afp' => 'application/vnd.ibm.modcap',
                'age' => 'application/vnd.age',
                'ahead' => 'application/vnd.ahead.space',
                'ai' => 'application/pdf',
                'aif' => 'audio/x-aiff',
                'aifc' => 'audio/x-aiff',
                'aiff' => 'audio/x-aiff',
                'air' => 'application/vnd.adobe.air-application-installer-package+zip',
                'ait' => 'application/vnd.dvb.ait',
                'ami' => 'application/vnd.amiga.ami',
                'aml' => 'application/automationml-aml+xml',
                'amlx' => 'application/automationml-amlx+zip',
                'amr' => 'audio/amr',
                'apk' => 'application/vnd.android.package-archive',
                'apng' => 'image/apng',
                'appcache' => 'text/cache-manifest',
                'appinstaller' => 'application/appinstaller',
                'application' => 'application/x-ms-application',
                'appx' => 'application/appx',
                'appxbundle' => 'application/appxbundle',
                'apr' => 'application/vnd.lotus-approach',
                'arc' => 'application/x-freearc',
                'arj' => 'application/x-arj',
                'asc' => 'application/pgp-signature',
                'asf' => 'video/x-ms-asf',
                'asm' => 'text/x-asm',
                'aso' => 'application/vnd.accpac.simply.aso',
                'asx' => 'video/x-ms-asf',
                'atc' => 'application/vnd.acucorp',
                'atom' => 'application/atom+xml',
                'atomcat' => 'application/atomcat+xml',
                'atomdeleted' => 'application/atomdeleted+xml',
                'atomsvc' => 'application/atomsvc+xml',
                'atx' => 'application/vnd.antix.game-component',
                'au' => 'audio/x-au',
                'avci' => 'image/avci',
                'avcs' => 'image/avcs',
                'avi' => 'video/x-msvideo',
                'avif' => 'image/avif',
                'aw' => 'application/applixware',
                'azf' => 'application/vnd.airzip.filesecure.azf',
                'azs' => 'application/vnd.airzip.filesecure.azs',
                'azv' => 'image/vnd.airzip.accelerator.azv',
                'azw' => 'application/vnd.amazon.ebook',
                'b16' => 'image/vnd.pco.b16',
                'bary' => 'model/vnd.bary',
                'bat' => 'application/x-msdownload',
                'bcpio' => 'application/x-bcpio',
                'bdf' => 'application/x-font-bdf',
                'bdm' => 'application/vnd.syncml.dm+wbxml',
                'bdo' => 'application/vnd.nato.bindingdataobject+xml',
                'bdoc' => 'application/x-bdoc',
                'bed' => 'application/vnd.realvnc.bed',
                'bh2' => 'application/vnd.fujitsu.oasysprs',
                'bin' => 'application/octet-stream',
                'blb' => 'application/x-blorb',
                'blorb' => 'application/x-blorb',
                'bmi' => 'application/vnd.bmi',
                'bmml' => 'application/vnd.balsamiq.bmml+xml',
                'bmp' => 'image/bmp',
                'book' => 'application/vnd.framemaker',
                'box' => 'application/vnd.previewsystems.box',
                'boz' => 'application/x-bzip2',
                'bpk' => 'application/octet-stream',
                'bpmn' => 'application/octet-stream',
                'brf' => 'application/braille',
                'bsp' => 'model/vnd.valve.source.compiled-map',
                'btf' => 'image/prs.btif',
                'btif' => 'image/prs.btif',
                'buffer' => 'application/octet-stream',
                'bz' => 'application/x-bzip',
                'bz2' => 'application/x-bzip2',
                'c' => 'text/x-c',
                'c4d' => 'application/vnd.clonk.c4group',
                'c4f' => 'application/vnd.clonk.c4group',
                'c4g' => 'application/vnd.clonk.c4group',
                'c4p' => 'application/vnd.clonk.c4group',
                'c4u' => 'application/vnd.clonk.c4group',
                'c11amc' => 'application/vnd.cluetrust.cartomobile-config',
                'c11amz' => 'application/vnd.cluetrust.cartomobile-config-pkg',
                'cab' => 'application/vnd.ms-cab-compressed',
                'caf' => 'audio/x-caf',
                'cap' => 'application/vnd.tcpdump.pcap',
                'car' => 'application/vnd.curl.car',
                'cat' => 'application/vnd.ms-pki.seccat',
                'cb7' => 'application/x-cbr',
                'cba' => 'application/x-cbr',
                'cbr' => 'application/x-cbr',
                'cbt' => 'application/x-cbr',
                'cbz' => 'application/x-cbr',
                'cc' => 'text/x-c',
                'cco' => 'application/x-cocoa',
                'cct' => 'application/x-director',
                'ccxml' => 'application/ccxml+xml',
                'cdbcmsg' => 'application/vnd.contact.cmsg',
                'cdf' => 'application/x-netcdf',
                'cdfx' => 'application/cdfx+xml',
                'cdkey' => 'application/vnd.mediastation.cdkey',
                'cdmia' => 'application/cdmi-capability',
                'cdmic' => 'application/cdmi-container',
                'cdmid' => 'application/cdmi-domain',
                'cdmio' => 'application/cdmi-object',
                'cdmiq' => 'application/cdmi-queue',
                'cdr' => 'application/cdr',
                'cdx' => 'chemical/x-cdx',
                'cdxml' => 'application/vnd.chemdraw+xml',
                'cdy' => 'application/vnd.cinderella',
                'cer' => 'application/pkix-cert',
                'cfs' => 'application/x-cfs-compressed',
                'cgm' => 'image/cgm',
                'chat' => 'application/x-chat',
                'chm' => 'application/vnd.ms-htmlhelp',
                'chrt' => 'application/vnd.kde.kchart',
                'cif' => 'chemical/x-cif',
                'cii' => 'application/vnd.anser-web-certificate-issue-initiation',
                'cil' => 'application/vnd.ms-artgalry',
                'cjs' => 'application/node',
                'cla' => 'application/vnd.claymore',
                'class' => 'application/octet-stream',
                'cld' => 'model/vnd.cld',
                'clkk' => 'application/vnd.crick.clicker.keyboard',
                'clkp' => 'application/vnd.crick.clicker.palette',
                'clkt' => 'application/vnd.crick.clicker.template',
                'clkw' => 'application/vnd.crick.clicker.wordbank',
                'clkx' => 'application/vnd.crick.clicker',
                'clp' => 'application/x-msclip',
                'cmc' => 'application/vnd.cosmocaller',
                'cmdf' => 'chemical/x-cmdf',
                'cml' => 'chemical/x-cml',
                'cmp' => 'application/vnd.yellowriver-custom-menu',
                'cmx' => 'image/x-cmx',
                'cod' => 'application/vnd.rim.cod',
                'coffee' => 'text/coffeescript',
                'com' => 'application/x-msdownload',
                'conf' => 'text/plain',
                'cpio' => 'application/x-cpio',
                'cpl' => 'application/cpl+xml',
                'cpp' => 'text/x-c',
                'cpt' => 'application/mac-compactpro',
                'crd' => 'application/x-mscardfile',
                'crl' => 'application/pkix-crl',
                'crt' => 'application/x-x509-ca-cert',
                'crx' => 'application/x-chrome-extension',
                'cryptonote' => 'application/vnd.rig.cryptonote',
                'csh' => 'application/x-csh',
                'csl' => 'application/vnd.citationstyles.style+xml',
                'csml' => 'chemical/x-csml',
                'csp' => 'application/vnd.commonspace',
                'csr' => 'application/octet-stream',
                'css' => 'text/css',
                'cst' => 'application/x-director',
                'csv' => 'text/csv',
                'cu' => 'application/cu-seeme',
                'curl' => 'text/vnd.curl',
                'cwl' => 'application/cwl',
                'cww' => 'application/prs.cww',
                'cxt' => 'application/x-director',
                'cxx' => 'text/x-c',
                'dae' => 'model/vnd.collada+xml',
                'daf' => 'application/vnd.mobius.daf',
                'dart' => 'application/vnd.dart',
                'dataless' => 'application/vnd.fdsn.seed',
                'davmount' => 'application/davmount+xml',
                'dbf' => 'application/vnd.dbf',
                'dbk' => 'application/docbook+xml',
                'dcr' => 'application/x-director',
                'dcurl' => 'text/vnd.curl.dcurl',
                'dd2' => 'application/vnd.oma.dd2+xml',
                'ddd' => 'application/vnd.fujixerox.ddd',
                'ddf' => 'application/vnd.syncml.dmddf+xml',
                'dds' => 'image/vnd.ms-dds',
                'deb' => 'application/x-debian-package',
                'def' => 'text/plain',
                'deploy' => 'application/octet-stream',
                'der' => 'application/x-x509-ca-cert',
                'dfac' => 'application/vnd.dreamfactory',
                'dgc' => 'application/x-dgc-compressed',
                'dib' => 'image/bmp',
                'dic' => 'text/x-c',
                'dir' => 'application/x-director',
                'dis' => 'application/vnd.mobius.dis',
                'disposition-notification' => 'message/disposition-notification',
                'dist' => 'application/octet-stream',
                'distz' => 'application/octet-stream',
                'djv' => 'image/vnd.djvu',
                'djvu' => 'image/vnd.djvu',
                'dll' => 'application/octet-stream',
                'dmg' => 'application/x-apple-diskimage',
                'dmn' => 'application/octet-stream',
                'dmp' => 'application/vnd.tcpdump.pcap',
                'dms' => 'application/octet-stream',
                'dna' => 'application/vnd.dna',
                'doc' => 'application/msword',
                'docm' => 'application/vnd.ms-word.template.macroEnabled.12',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'dot' => 'application/msword',
                'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
                'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
                'dp' => 'application/vnd.osgi.dp',
                'dpg' => 'application/vnd.dpgraph',
                'dpx' => 'image/dpx',
                'dra' => 'audio/vnd.dra',
                'drle' => 'image/dicom-rle',
                'dsc' => 'text/prs.lines.tag',
                'dssc' => 'application/dssc+der',
                'dst' => 'application/octet-stream',
                'dtb' => 'application/x-dtbook+xml',
                'dtd' => 'application/xml-dtd',
                'dts' => 'audio/vnd.dts',
                'dtshd' => 'audio/vnd.dts.hd',
                'dump' => 'application/octet-stream',
                'dvb' => 'video/vnd.dvb.file',
                'dvi' => 'application/x-dvi',
                'dwd' => 'application/atsc-dwd+xml',
                'dwf' => 'model/vnd.dwf',
                'dwg' => 'image/vnd.dwg',
                'dxf' => 'image/vnd.dxf',
                'dxp' => 'application/vnd.spotfire.dxp',
                'dxr' => 'application/x-director',
                'ear' => 'application/java-archive',
                'ecelp4800' => 'audio/vnd.nuera.ecelp4800',
                'ecelp7470' => 'audio/vnd.nuera.ecelp7470',
                'ecelp9600' => 'audio/vnd.nuera.ecelp9600',
                'ecma' => 'application/ecmascript',
                'edm' => 'application/vnd.novadigm.edm',
                'edx' => 'application/vnd.novadigm.edx',
                'efif' => 'application/vnd.picsel',
                'ei6' => 'application/vnd.pg.osasli',
                'elc' => 'application/octet-stream',
                'emf' => 'image/emf',
                'eml' => 'message/rfc822',
                'emma' => 'application/emma+xml',
                'emotionml' => 'application/emotionml+xml',
                'emz' => 'application/x-msmetafile',
                'eol' => 'audio/vnd.digital-winds',
                'eot' => 'application/vnd.ms-fontobject',
                'eps' => 'application/postscript',
                'epub' => 'application/epub+zip',
                'es3' => 'application/vnd.eszigno3+xml',
                'esa' => 'application/vnd.osgi.subsystem',
                'esf' => 'application/vnd.epson.esf',
                'et3' => 'application/vnd.eszigno3+xml',
                'etx' => 'text/x-setext',
                'eva' => 'application/x-eva',
                'evy' => 'application/x-envoy',
                'exe' => 'application/octet-stream',
                'exi' => 'application/exi',
                'exp' => 'application/express',
                'exr' => 'image/aces',
                'ext' => 'application/vnd.novadigm.ext',
                'ez' => 'application/andrew-inset',
                'ez2' => 'application/vnd.ezpix-album',
                'ez3' => 'application/vnd.ezpix-package',
                'f' => 'text/x-fortran',
                'f4v' => 'video/mp4',
                'f77' => 'text/x-fortran',
                'f90' => 'text/x-fortran',
                'fbs' => 'image/vnd.fastbidsheet',
                'fcdt' => 'application/vnd.adobe.formscentral.fcdt',
                'fcs' => 'application/vnd.isac.fcs',
                'fdf' => 'application/vnd.fdf',
                'fdt' => 'application/fdt+xml',
                'fe_launch' => 'application/vnd.denovo.fcselayout-link',
                'fg5' => 'application/vnd.fujitsu.oasysgp',
                'fgd' => 'application/x-director',
                'fh' => 'image/x-freehand',
                'fh4' => 'image/x-freehand',
                'fh5' => 'image/x-freehand',
                'fh7' => 'image/x-freehand',
                'fhc' => 'image/x-freehand',
                'fig' => 'application/x-xfig',
                'fits' => 'image/fits',
                'flac' => 'audio/x-flac',
                'fli' => 'video/x-fli',
                'flo' => 'application/vnd.micrografx.flo',
                'flv' => 'video/x-flv',
                'flw' => 'application/vnd.kde.kivio',
                'flx' => 'text/vnd.fmi.flexstor',
                'fly' => 'text/vnd.fly',
                'fm' => 'application/vnd.framemaker',
                'fnc' => 'application/vnd.frogans.fnc',
                'fo' => 'application/vnd.software602.filler.form+xml',
                'for' => 'text/x-fortran',
                'fpx' => 'image/vnd.fpx',
                'frame' => 'application/vnd.framemaker',
                'fsc' => 'application/vnd.fsc.weblaunch',
                'fst' => 'image/vnd.fst',
                'ftc' => 'application/vnd.fluxtime.clip',
                'fti' => 'application/vnd.anser-web-funds-transfer-initiation',
                'fvt' => 'video/vnd.fvt',
                'fxp' => 'application/vnd.adobe.fxp',
                'fxpl' => 'application/vnd.adobe.fxp',
                'fzs' => 'application/vnd.fuzzysheet',
                'g2w' => 'application/vnd.geoplan',
                'g3' => 'image/g3fax',
                'g3w' => 'application/vnd.geospace',
                'gac' => 'application/vnd.groove-account',
                'gam' => 'application/x-tads',
                'gbr' => 'application/rpki-ghostbusters',
                'gca' => 'application/x-gca-compressed',
                'gdl' => 'model/vnd.gdl',
                'gdoc' => 'application/vnd.google-apps.document',
                'ged' => 'text/vnd.familysearch.gedcom',
                'geo' => 'application/vnd.dynageo',
                'geojson' => 'application/geo+json',
                'gex' => 'application/vnd.geometry-explorer',
                'ggb' => 'application/vnd.geogebra.file',
                'ggs' => 'application/vnd.geogebra.slides',
                'ggt' => 'application/vnd.geogebra.tool',
                'ghf' => 'application/vnd.groove-help',
                'gif' => 'image/gif',
                'gim' => 'application/vnd.groove-identity-message',
                'glb' => 'model/gltf-binary',
                'gltf' => 'model/gltf+json',
                'gml' => 'application/gml+xml',
                'gmx' => 'application/vnd.gmx',
                'gnumeric' => 'application/x-gnumeric',
                'gpg' => 'application/gpg-keys',
                'gph' => 'application/vnd.flographit',
                'gpx' => 'application/gpx+xml',
                'gqf' => 'application/vnd.grafeq',
                'gqs' => 'application/vnd.grafeq',
                'gram' => 'application/srgs',
                'gramps' => 'application/x-gramps-xml',
                'gre' => 'application/vnd.geometry-explorer',
                'grv' => 'application/vnd.groove-injector',
                'grxml' => 'application/srgs+xml',
                'gsf' => 'application/x-font-ghostscript',
                'gsheet' => 'application/vnd.google-apps.spreadsheet',
                'gslides' => 'application/vnd.google-apps.presentation',
                'gtar' => 'application/x-gtar',
                'gtm' => 'application/vnd.groove-tool-message',
                'gtw' => 'model/vnd.gtw',
                'gv' => 'text/vnd.graphviz',
                'gxf' => 'application/gxf',
                'gxt' => 'application/vnd.geonext',
                'gz' => 'application/gzip',
                'gzip' => 'application/gzip',
                'h' => 'text/x-c',
                'h261' => 'video/h261',
                'h263' => 'video/h263',
                'h264' => 'video/h264',
                'hal' => 'application/vnd.hal+xml',
                'hbci' => 'application/vnd.hbci',
                'hbs' => 'text/x-handlebars-template',
                'hdd' => 'application/x-virtualbox-hdd',
                'hdf' => 'application/x-hdf',
                'heic' => 'image/heic',
                'heics' => 'image/heic-sequence',
                'heif' => 'image/heif',
                'heifs' => 'image/heif-sequence',
                'hej2' => 'image/hej2k',
                'held' => 'application/atsc-held+xml',
                'hh' => 'text/x-c',
                'hjson' => 'application/hjson',
                'hlp' => 'application/winhlp',
                'hpgl' => 'application/vnd.hp-hpgl',
                'hpid' => 'application/vnd.hp-hpid',
                'hps' => 'application/vnd.hp-hps',
                'hqx' => 'application/mac-binhex40',
                'hsj2' => 'image/hsj2',
                'htc' => 'text/x-component',
                'htke' => 'application/vnd.kenameaapp',
                'htm' => 'text/html',
                'html' => 'text/html',
                'hvd' => 'application/vnd.yamaha.hv-dic',
                'hvp' => 'application/vnd.yamaha.hv-voice',
                'hvs' => 'application/vnd.yamaha.hv-script',
                'i2g' => 'application/vnd.intergeo',
                'icc' => 'application/vnd.iccprofile',
                'ice' => 'x-conference/x-cooltalk',
                'icm' => 'application/vnd.iccprofile',
                'ico' => 'image/x-icon',
                'ics' => 'text/calendar',
                'ief' => 'image/ief',
                'ifb' => 'text/calendar',
                'ifm' => 'application/vnd.shana.informed.formdata',
                'iges' => 'model/iges',
                'igl' => 'application/vnd.igloader',
                'igm' => 'application/vnd.insors.igm',
                'igs' => 'model/iges',
                'igx' => 'application/vnd.micrografx.igx',
                'iif' => 'application/vnd.shana.informed.interchange',
                'img' => 'application/octet-stream',
                'imp' => 'application/vnd.accpac.simply.imp',
                'ims' => 'application/vnd.ms-ims',
                'in' => 'text/plain',
                'ini' => 'text/plain',
                'ink' => 'application/inkml+xml',
                'inkml' => 'application/inkml+xml',
                'install' => 'application/x-install-instructions',
                'iota' => 'application/vnd.astraea-software.iota',
                'ipfix' => 'application/ipfix',
                'ipk' => 'application/vnd.shana.informed.package',
                'irm' => 'application/vnd.ibm.rights-management',
                'irp' => 'application/vnd.irepository.package+xml',
                'iso' => 'application/x-iso9660-image',
                'itp' => 'application/vnd.shana.informed.formtemplate',
                'its' => 'application/its+xml',
                'ivp' => 'application/vnd.immervision-ivp',
                'ivu' => 'application/vnd.immervision-ivu',
                'jad' => 'text/vnd.sun.j2me.app-descriptor',
                'jade' => 'text/jade',
                'jam' => 'application/vnd.jam',
                'jar' => 'application/java-archive',
                'jardiff' => 'application/x-java-archive-diff',
                'java' => 'text/x-java-source',
                'jhc' => 'image/jphc',
                'jisp' => 'application/vnd.jisp',
                'jls' => 'image/jls',
                'jlt' => 'application/vnd.hp-jlyt',
                'jng' => 'image/x-jng',
                'jnlp' => 'application/x-java-jnlp-file',
                'joda' => 'application/vnd.joost.joda-archive',
                'jp2' => 'image/jp2',
                'jpe' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'jpf' => 'image/jpx',
                'jpg' => 'image/jpeg',
                'jpg2' => 'image/jp2',
                'jpgm' => 'video/jpm',
                'jpgv' => 'video/jpeg',
                'jph' => 'image/jph',
                'jpm' => 'video/jpm',
                'jpx' => 'image/jpx',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'json5' => 'application/json5',
                'jsonld' => 'application/ld+json',
                'jsonml' => 'application/jsonml+json',
                'jsx' => 'text/jsx',
                'jt' => 'model/jt',
                'jxl' => 'image/jxl',
                'jxr' => 'image/jxr',
                'jxra' => 'image/jxra',
                'jxrs' => 'image/jxrs',
                'jxs' => 'image/jxs',
                'jxsc' => 'image/jxsc',
                'jxsi' => 'image/jxsi',
                'jxss' => 'image/jxss',
                'kar' => 'audio/midi',
                'karbon' => 'application/vnd.kde.karbon',
                'kdb' => 'application/octet-stream',
                'kdbx' => 'application/x-keepass2',
                'key' => 'application/x-iwork-keynote-sffkey',
                'kfo' => 'application/vnd.kde.kformula',
                'kia' => 'application/vnd.kidspiration',
                'kml' => 'application/vnd.google-earth.kml+xml',
                'kmz' => 'application/vnd.google-earth.kmz',
                'kne' => 'application/vnd.kinar',
                'knp' => 'application/vnd.kinar',
                'kon' => 'application/vnd.kde.kontour',
                'kpr' => 'application/vnd.kde.kpresenter',
                'kpt' => 'application/vnd.kde.kpresenter',
                'kpxx' => 'application/vnd.ds-keypoint',
                'ksp' => 'application/vnd.kde.kspread',
                'ktr' => 'application/vnd.kahootz',
                'ktx' => 'image/ktx',
                'ktx2' => 'image/ktx2',
                'ktz' => 'application/vnd.kahootz',
                'kwd' => 'application/vnd.kde.kword',
                'kwt' => 'application/vnd.kde.kword',
                'lasxml' => 'application/vnd.las.las+xml',
                'latex' => 'application/x-latex',
                'lbd' => 'application/vnd.llamagraphics.life-balance.desktop',
                'lbe' => 'application/vnd.llamagraphics.life-balance.exchange+xml',
                'les' => 'application/vnd.hhe.lesson-player',
                'less' => 'text/less',
                'lgr' => 'application/lgr+xml',
                'lha' => 'application/octet-stream',
                'link66' => 'application/vnd.route66.link66+xml',
                'list' => 'text/plain',
                'list3820' => 'application/vnd.ibm.modcap',
                'listafp' => 'application/vnd.ibm.modcap',
                'litcoffee' => 'text/coffeescript',
                'lnk' => 'application/x-ms-shortcut',
                'log' => 'text/plain',
                'lostxml' => 'application/lost+xml',
                'lrf' => 'application/octet-stream',
                'lrm' => 'application/vnd.ms-lrm',
                'ltf' => 'application/vnd.frogans.ltf',
                'lua' => 'text/x-lua',
                'luac' => 'application/x-lua-bytecode',
                'lvp' => 'audio/vnd.lucent.voice',
                'lwp' => 'application/vnd.lotus-wordpro',
                'lzh' => 'application/octet-stream',
                'm1v' => 'video/mpeg',
                'm2a' => 'audio/mpeg',
                'm2t' => 'video/mp2t',
                'm2ts' => 'video/mp2t',
                'm2v' => 'video/mpeg',
                'm3a' => 'audio/mpeg',
                'm3u' => 'text/plain',
                'm3u8' => 'application/vnd.apple.mpegurl',
                'm4a' => 'audio/x-m4a',
                'm4p' => 'application/mp4',
                'm4s' => 'video/iso.segment',
                'm4u' => 'application/vnd.mpegurl',
                'm4v' => 'video/x-m4v',
                'm13' => 'application/x-msmediaview',
                'm14' => 'application/x-msmediaview',
                'm21' => 'application/mp21',
                'ma' => 'application/mathematica',
                'mads' => 'application/mads+xml',
                'maei' => 'application/mmt-aei+xml',
                'mag' => 'application/vnd.ecowin.chart',
                'maker' => 'application/vnd.framemaker',
                'man' => 'text/troff',
                'manifest' => 'text/cache-manifest',
                'map' => 'application/json',
                'mar' => 'application/octet-stream',
                'markdown' => 'text/markdown',
                'mathml' => 'application/mathml+xml',
                'mb' => 'application/mathematica',
                'mbk' => 'application/vnd.mobius.mbk',
                'mbox' => 'application/mbox',
                'mc1' => 'application/vnd.medcalcdata',
                'mcd' => 'application/vnd.mcd',
                'mcurl' => 'text/vnd.curl.mcurl',
                'md' => 'text/markdown',
                'mdb' => 'application/x-msaccess',
                'mdi' => 'image/vnd.ms-modi',
                'mdx' => 'text/mdx',
                'me' => 'text/troff',
                'mesh' => 'model/mesh',
                'meta4' => 'application/metalink4+xml',
                'metalink' => 'application/metalink+xml',
                'mets' => 'application/mets+xml',
                'mfm' => 'application/vnd.mfmp',
                'mft' => 'application/rpki-manifest',
                'mgp' => 'application/vnd.osgeo.mapguide.package',
                'mgz' => 'application/vnd.proteus.magazine',
                'mid' => 'audio/midi',
                'midi' => 'audio/midi',
                'mie' => 'application/x-mie',
                'mif' => 'application/vnd.mif',
                'mime' => 'message/rfc822',
                'mj2' => 'video/mj2',
                'mjp2' => 'video/mj2',
                'mjs' => 'text/javascript',
                'mk3d' => 'video/x-matroska',
                'mka' => 'audio/x-matroska',
                'mkd' => 'text/x-markdown',
                'mks' => 'video/x-matroska',
                'mkv' => 'video/x-matroska',
                'mlp' => 'application/vnd.dolby.mlp',
                'mmd' => 'application/vnd.chipnuts.karaoke-mmd',
                'mmf' => 'application/vnd.smaf',
                'mml' => 'text/mathml',
                'mmr' => 'image/vnd.fujixerox.edmics-mmr',
                'mng' => 'video/x-mng',
                'mny' => 'application/x-msmoney',
                'mobi' => 'application/x-mobipocket-ebook',
                'mods' => 'application/mods+xml',
                'mov' => 'video/quicktime',
                'movie' => 'video/x-sgi-movie',
                'mp2' => 'audio/mpeg',
                'mp2a' => 'audio/mpeg',
                'mp3' => 'audio/mpeg',
                'mp4' => 'video/mp4',
                'mp4a' => 'audio/mp4',
                'mp4s' => 'application/mp4',
                'mp4v' => 'video/mp4',
                'mp21' => 'application/mp21',
                'mpc' => 'application/vnd.mophun.certificate',
                'mpd' => 'application/dash+xml',
                'mpe' => 'video/mpeg',
                'mpeg' => 'video/mpeg',
                'mpf' => 'application/media-policy-dataset+xml',
                'mpg' => 'video/mpeg',
                'mpg4' => 'video/mp4',
                'mpga' => 'audio/mpeg',
                'mpkg' => 'application/vnd.apple.installer+xml',
                'mpm' => 'application/vnd.blueice.multipass',
                'mpn' => 'application/vnd.mophun.application',
                'mpp' => 'application/vnd.ms-project',
                'mpt' => 'application/vnd.ms-project',
                'mpy' => 'application/vnd.ibm.minipay',
                'mqy' => 'application/vnd.mobius.mqy',
                'mrc' => 'application/marc',
                'mrcx' => 'application/marcxml+xml',
                'ms' => 'text/troff',
                'mscml' => 'application/mediaservercontrol+xml',
                'mseed' => 'application/vnd.fdsn.mseed',
                'mseq' => 'application/vnd.mseq',
                'msf' => 'application/vnd.epson.msf',
                'msg' => 'application/vnd.ms-outlook',
                'msh' => 'model/mesh',
                'msi' => 'application/x-msdownload',
                'msix' => 'application/msix',
                'msixbundle' => 'application/msixbundle',
                'msl' => 'application/vnd.mobius.msl',
                'msm' => 'application/octet-stream',
                'msp' => 'application/octet-stream',
                'msty' => 'application/vnd.muvee.style',
                'mtl' => 'model/mtl',
                'mts' => 'video/mp2t',
                'mus' => 'application/vnd.musician',
                'musd' => 'application/mmt-usd+xml',
                'musicxml' => 'application/vnd.recordare.musicxml+xml',
                'mvb' => 'application/x-msmediaview',
                'mvt' => 'application/vnd.mapbox-vector-tile',
                'mwf' => 'application/vnd.mfer',
                'mxf' => 'application/mxf',
                'mxl' => 'application/vnd.recordare.musicxml',
                'mxmf' => 'audio/mobile-xmf',
                'mxml' => 'application/xv+xml',
                'mxs' => 'application/vnd.triscape.mxs',
                'mxu' => 'video/vnd.mpegurl',
                'n-gage' => 'application/vnd.nokia.n-gage.symbian.install',
                'n3' => 'text/n3',
                'nb' => 'application/mathematica',
                'nbp' => 'application/vnd.wolfram.player',
                'nc' => 'application/x-netcdf',
                'ncx' => 'application/x-dtbncx+xml',
                'ndjson' => 'application/x-ndjson',
                'nfo' => 'text/x-nfo',
                'ngdat' => 'application/vnd.nokia.n-gage.data',
                'nitf' => 'application/vnd.nitf',
                'nlu' => 'application/vnd.neurolanguage.nlu',
                'nml' => 'application/vnd.enliven',
                'nnd' => 'application/vnd.noblenet-directory',
                'nns' => 'application/vnd.noblenet-sealer',
                'nnw' => 'application/vnd.noblenet-web',
                'npx' => 'image/vnd.net-fpx',
                'nq' => 'application/n-quads',
                'nsc' => 'application/x-conference',
                'nsf' => 'application/vnd.lotus-notes',
                'nt' => 'application/n-triples',
                'ntf' => 'application/vnd.nitf',
                'numbers' => 'application/x-iwork-numbers-sffnumbers',
                'nzb' => 'application/x-nzb',
                'oa2' => 'application/vnd.fujitsu.oasys2',
                'oa3' => 'application/vnd.fujitsu.oasys3',
                'oas' => 'application/vnd.fujitsu.oasys',
                'obd' => 'application/x-msbinder',
                'obgx' => 'application/vnd.openblox.game+xml',
                'obj' => 'model/obj',
                'oda' => 'application/oda',
                'odb' => 'application/vnd.oasis.opendocument.database',
                'odc' => 'application/vnd.oasis.opendocument.chart',
                'odf' => 'application/vnd.oasis.opendocument.formula',
                'odft' => 'application/vnd.oasis.opendocument.formula-template',
                'odg' => 'application/vnd.oasis.opendocument.graphics',
                'odi' => 'application/vnd.oasis.opendocument.image',
                'odm' => 'application/vnd.oasis.opendocument.text-master',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odt' => 'application/vnd.oasis.opendocument.text',
                'oga' => 'audio/ogg',
                'ogex' => 'model/vnd.opengex',
                'ogg' => 'audio/ogg',
                'ogv' => 'video/ogg',
                'ogx' => 'application/ogg',
                'omdoc' => 'application/omdoc+xml',
                'onepkg' => 'application/onenote',
                'onetmp' => 'application/onenote',
                'onetoc' => 'application/onenote',
                'onetoc2' => 'application/onenote',
                'opf' => 'application/oebps-package+xml',
                'opml' => 'text/x-opml',
                'oprc' => 'application/vnd.palm',
                'opus' => 'audio/ogg',
                'org' => 'text/x-org',
                'osf' => 'application/vnd.yamaha.openscoreformat',
                'osfpvg' => 'application/vnd.yamaha.openscoreformat.osfpvg+xml',
                'osm' => 'application/vnd.openstreetmap.data+xml',
                'otc' => 'application/vnd.oasis.opendocument.chart-template',
                'otf' => 'font/otf',
                'otg' => 'application/vnd.oasis.opendocument.graphics-template',
                'oth' => 'application/vnd.oasis.opendocument.text-web',
                'oti' => 'application/vnd.oasis.opendocument.image-template',
                'otp' => 'application/vnd.oasis.opendocument.presentation-template',
                'ots' => 'application/vnd.oasis.opendocument.spreadsheet-template',
                'ott' => 'application/vnd.oasis.opendocument.text-template',
                'ova' => 'application/x-virtualbox-ova',
                'ovf' => 'application/x-virtualbox-ovf',
                'owl' => 'application/rdf+xml',
                'oxps' => 'application/oxps',
                'oxt' => 'application/vnd.openofficeorg.extension',
                'p' => 'text/x-pascal',
                'p7a' => 'application/x-pkcs7-signature',
                'p7b' => 'application/x-pkcs7-certificates',
                'p7c' => 'application/pkcs7-mime',
                'p7m' => 'application/pkcs7-mime',
                'p7r' => 'application/x-pkcs7-certreqresp',
                'p7s' => 'application/pkcs7-signature',
                'p8' => 'application/pkcs8',
                'p10' => 'application/x-pkcs10',
                'p12' => 'application/x-pkcs12',
                'pac' => 'application/x-ns-proxy-autoconfig',
                'pages' => 'application/x-iwork-pages-sffpages',
                'pas' => 'text/x-pascal',
                'paw' => 'application/vnd.pawaafile',
                'pbd' => 'application/vnd.powerbuilder6',
                'pbm' => 'image/x-portable-bitmap',
                'pcap' => 'application/vnd.tcpdump.pcap',
                'pcf' => 'application/x-font-pcf',
                'pcl' => 'application/vnd.hp-pcl',
                'pclxl' => 'application/vnd.hp-pclxl',
                'pct' => 'image/x-pict',
                'pcurl' => 'application/vnd.curl.pcurl',
                'pcx' => 'image/x-pcx',
                'pdb' => 'application/x-pilot',
                'pde' => 'text/x-processing',
                'pdf' => 'application/pdf',
                'pem' => 'application/x-x509-user-cert',
                'pfa' => 'application/x-font-type1',
                'pfb' => 'application/x-font-type1',
                'pfm' => 'application/x-font-type1',
                'pfr' => 'application/font-tdpfr',
                'pfx' => 'application/x-pkcs12',
                'pgm' => 'image/x-portable-graymap',
                'pgn' => 'application/x-chess-pgn',
                'pgp' => 'application/pgp',
                'phar' => 'application/octet-stream',
                'php' => 'application/x-httpd-php',
                'php3' => 'application/x-httpd-php',
                'php4' => 'application/x-httpd-php',
                'phps' => 'application/x-httpd-php-source',
                'phtml' => 'application/x-httpd-php',
                'pic' => 'image/x-pict',
                'pkg' => 'application/octet-stream',
                'pki' => 'application/pkixcmp',
                'pkipath' => 'application/pkix-pkipath',
                'pkpass' => 'application/vnd.apple.pkpass',
                'pl' => 'application/x-perl',
                'plb' => 'application/vnd.3gpp.pic-bw-large',
                'plc' => 'application/vnd.mobius.plc',
                'plf' => 'application/vnd.pocketlearn',
                'pls' => 'application/pls+xml',
                'pm' => 'application/x-perl',
                'pml' => 'application/vnd.ctc-posml',
                'png' => 'image/png',
                'pnm' => 'image/x-portable-anymap',
                'portpkg' => 'application/vnd.macports.portpkg',
                'pot' => 'application/vnd.ms-powerpoint',
                'potm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
                'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
                'ppa' => 'application/vnd.ms-powerpoint',
                'ppam' => 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
                'ppd' => 'application/vnd.cups-ppd',
                'ppm' => 'image/x-portable-pixmap',
                'pps' => 'application/vnd.ms-powerpoint',
                'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
                'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                'ppt' => 'application/powerpoint',
                'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'pqa' => 'application/vnd.palm',
                'prc' => 'model/prc',
                'pre' => 'application/vnd.lotus-freelance',
                'prf' => 'application/pics-rules',
                'provx' => 'application/provenance+xml',
                'ps' => 'application/postscript',
                'psb' => 'application/vnd.3gpp.pic-bw-small',
                'psd' => 'application/x-photoshop',
                'psf' => 'application/x-font-linux-psf',
                'pskcxml' => 'application/pskc+xml',
                'pti' => 'image/prs.pti',
                'ptid' => 'application/vnd.pvi.ptid1',
                'pub' => 'application/x-mspublisher',
                'pv' => 'application/octet-stream',
                'pvb' => 'application/vnd.3gpp.pic-bw-var',
                'pwn' => 'application/vnd.3m.post-it-notes',
                'pxf' => 'application/octet-stream',
                'pya' => 'audio/vnd.ms-playready.media.pya',
                'pyo' => 'model/vnd.pytha.pyox',
                'pyox' => 'model/vnd.pytha.pyox',
                'pyv' => 'video/vnd.ms-playready.media.pyv',
                'qam' => 'application/vnd.epson.quickanime',
                'qbo' => 'application/vnd.intu.qbo',
                'qfx' => 'application/vnd.intu.qfx',
                'qps' => 'application/vnd.publishare-delta-tree',
                'qt' => 'video/quicktime',
                'qwd' => 'application/vnd.quark.quarkxpress',
                'qwt' => 'application/vnd.quark.quarkxpress',
                'qxb' => 'application/vnd.quark.quarkxpress',
                'qxd' => 'application/vnd.quark.quarkxpress',
                'qxl' => 'application/vnd.quark.quarkxpress',
                'qxt' => 'application/vnd.quark.quarkxpress',
                'ra' => 'audio/x-realaudio',
                'ram' => 'audio/x-pn-realaudio',
                'raml' => 'application/raml+yaml',
                'rapd' => 'application/route-apd+xml',
                'rar' => 'application/x-rar',
                'ras' => 'image/x-cmu-raster',
                'rcprofile' => 'application/vnd.ipunplugged.rcprofile',
                'rdf' => 'application/rdf+xml',
                'rdz' => 'application/vnd.data-vision.rdz',
                'relo' => 'application/p2p-overlay+xml',
                'rep' => 'application/vnd.businessobjects',
                'res' => 'application/x-dtbresource+xml',
                'rgb' => 'image/x-rgb',
                'rif' => 'application/reginfo+xml',
                'rip' => 'audio/vnd.rip',
                'ris' => 'application/x-research-info-systems',
                'rl' => 'application/resource-lists+xml',
                'rlc' => 'image/vnd.fujixerox.edmics-rlc',
                'rld' => 'application/resource-lists-diff+xml',
                'rm' => 'audio/x-pn-realaudio',
                'rmi' => 'audio/midi',
                'rmp' => 'audio/x-pn-realaudio-plugin',
                'rms' => 'application/vnd.jcp.javame.midlet-rms',
                'rmvb' => 'application/vnd.rn-realmedia-vbr',
                'rnc' => 'application/relax-ng-compact-syntax',
                'rng' => 'application/xml',
                'roa' => 'application/rpki-roa',
                'roff' => 'text/troff',
                'rp9' => 'application/vnd.cloanto.rp9',
                'rpm' => 'audio/x-pn-realaudio-plugin',
                'rpss' => 'application/vnd.nokia.radio-presets',
                'rpst' => 'application/vnd.nokia.radio-preset',
                'rq' => 'application/sparql-query',
                'rs' => 'application/rls-services+xml',
                'rsa' => 'application/x-pkcs7',
                'rsat' => 'application/atsc-rsat+xml',
                'rsd' => 'application/rsd+xml',
                'rsheet' => 'application/urc-ressheet+xml',
                'rss' => 'application/rss+xml',
                'rtf' => 'text/rtf',
                'rtx' => 'text/richtext',
                'run' => 'application/x-makeself',
                'rusd' => 'application/route-usd+xml',
                'rv' => 'video/vnd.rn-realvideo',
                's' => 'text/x-asm',
                's3m' => 'audio/s3m',
                'saf' => 'application/vnd.yamaha.smaf-audio',
                'sass' => 'text/x-sass',
                'sbml' => 'application/sbml+xml',
                'sc' => 'application/vnd.ibm.secure-container',
                'scd' => 'application/x-msschedule',
                'scm' => 'application/vnd.lotus-screencam',
                'scq' => 'application/scvp-cv-request',
                'scs' => 'application/scvp-cv-response',
                'scss' => 'text/x-scss',
                'scurl' => 'text/vnd.curl.scurl',
                'sda' => 'application/vnd.stardivision.draw',
                'sdc' => 'application/vnd.stardivision.calc',
                'sdd' => 'application/vnd.stardivision.impress',
                'sdkd' => 'application/vnd.solent.sdkm+xml',
                'sdkm' => 'application/vnd.solent.sdkm+xml',
                'sdp' => 'application/sdp',
                'sdw' => 'application/vnd.stardivision.writer',
                'sea' => 'application/octet-stream',
                'see' => 'application/vnd.seemail',
                'seed' => 'application/vnd.fdsn.seed',
                'sema' => 'application/vnd.sema',
                'semd' => 'application/vnd.semd',
                'semf' => 'application/vnd.semf',
                'senmlx' => 'application/senml+xml',
                'sensmlx' => 'application/sensml+xml',
                'ser' => 'application/java-serialized-object',
                'setpay' => 'application/set-payment-initiation',
                'setreg' => 'application/set-registration-initiation',
                'sfd-hdstx' => 'application/vnd.hydrostatix.sof-data',
                'sfs' => 'application/vnd.spotfire.sfs',
                'sfv' => 'text/x-sfv',
                'sgi' => 'image/sgi',
                'sgl' => 'application/vnd.stardivision.writer-global',
                'sgm' => 'text/sgml',
                'sgml' => 'text/sgml',
                'sh' => 'application/x-sh',
                'shar' => 'application/x-shar',
                'shex' => 'text/shex',
                'shf' => 'application/shf+xml',
                'shtml' => 'text/html',
                'sid' => 'image/x-mrsid-image',
                'sieve' => 'application/sieve',
                'sig' => 'application/pgp-signature',
                'sil' => 'audio/silk',
                'silo' => 'model/mesh',
                'sis' => 'application/vnd.symbian.install',
                'sisx' => 'application/vnd.symbian.install',
                'sit' => 'application/x-stuffit',
                'sitx' => 'application/x-stuffitx',
                'siv' => 'application/sieve',
                'skd' => 'application/vnd.koan',
                'skm' => 'application/vnd.koan',
                'skp' => 'application/vnd.koan',
                'skt' => 'application/vnd.koan',
                'sldm' => 'application/vnd.ms-powerpoint.slide.macroenabled.12',
                'sldx' => 'application/vnd.openxmlformats-officedocument.presentationml.slide',
                'slim' => 'text/slim',
                'slm' => 'text/slim',
                'sls' => 'application/route-s-tsid+xml',
                'slt' => 'application/vnd.epson.salt',
                'sm' => 'application/vnd.stepmania.stepchart',
                'smf' => 'application/vnd.stardivision.math',
                'smi' => 'application/smil',
                'smil' => 'application/smil',
                'smv' => 'video/x-smv',
                'smzip' => 'application/vnd.stepmania.package',
                'snd' => 'audio/basic',
                'snf' => 'application/x-font-snf',
                'so' => 'application/octet-stream',
                'spc' => 'application/x-pkcs7-certificates',
                'spdx' => 'text/spdx',
                'spf' => 'application/vnd.yamaha.smaf-phrase',
                'spl' => 'application/x-futuresplash',
                'spot' => 'text/vnd.in3d.spot',
                'spp' => 'application/scvp-vp-response',
                'spq' => 'application/scvp-vp-request',
                'spx' => 'audio/ogg',
                'sql' => 'application/x-sql',
                'src' => 'application/x-wais-source',
                'srt' => 'application/x-subrip',
                'sru' => 'application/sru+xml',
                'srx' => 'application/sparql-results+xml',
                'ssdl' => 'application/ssdl+xml',
                'sse' => 'application/vnd.kodak-descriptor',
                'ssf' => 'application/vnd.epson.ssf',
                'ssml' => 'application/ssml+xml',
                'sst' => 'application/octet-stream',
                'st' => 'application/vnd.sailingtracker.track',
                'stc' => 'application/vnd.sun.xml.calc.template',
                'std' => 'application/vnd.sun.xml.draw.template',
                'step' => 'application/STEP',
                'stf' => 'application/vnd.wt.stf',
                'sti' => 'application/vnd.sun.xml.impress.template',
                'stk' => 'application/hyperstudio',
                'stl' => 'model/stl',
                'stp' => 'application/STEP',
                'stpx' => 'model/step+xml',
                'stpxz' => 'model/step-xml+zip',
                'stpz' => 'model/step+zip',
                'str' => 'application/vnd.pg.format',
                'stw' => 'application/vnd.sun.xml.writer.template',
                'styl' => 'text/stylus',
                'stylus' => 'text/stylus',
                'sub' => 'text/vnd.dvb.subtitle',
                'sus' => 'application/vnd.sus-calendar',
                'susp' => 'application/vnd.sus-calendar',
                'sv4cpio' => 'application/x-sv4cpio',
                'sv4crc' => 'application/x-sv4crc',
                'svc' => 'application/vnd.dvb.service',
                'svd' => 'application/vnd.svd',
                'svg' => 'image/svg+xml',
                'svgz' => 'image/svg+xml',
                'swa' => 'application/x-director',
                'swf' => 'application/x-shockwave-flash',
                'swi' => 'application/vnd.aristanetworks.swi',
                'swidtag' => 'application/swid+xml',
                'sxc' => 'application/vnd.sun.xml.calc',
                'sxd' => 'application/vnd.sun.xml.draw',
                'sxg' => 'application/vnd.sun.xml.writer.global',
                'sxi' => 'application/vnd.sun.xml.impress',
                'sxm' => 'application/vnd.sun.xml.math',
                'sxw' => 'application/vnd.sun.xml.writer',
                't' => 'text/troff',
                't3' => 'application/x-t3vm-image',
                't38' => 'image/t38',
                'taglet' => 'application/vnd.mynfc',
                'tao' => 'application/vnd.tao.intent-module-archive',
                'tap' => 'image/vnd.tencent.tap',
                'tar' => 'application/x-tar',
                'tcap' => 'application/vnd.3gpp2.tcap',
                'tcl' => 'application/x-tcl',
                'td' => 'application/urc-targetdesc+xml',
                'teacher' => 'application/vnd.smart.teacher',
                'tei' => 'application/tei+xml',
                'teicorpus' => 'application/tei+xml',
                'tex' => 'application/x-tex',
                'texi' => 'application/x-texinfo',
                'texinfo' => 'application/x-texinfo',
                'text' => 'text/plain',
                'tfi' => 'application/thraud+xml',
                'tfm' => 'application/x-tex-tfm',
                'tfx' => 'image/tiff-fx',
                'tga' => 'image/x-tga',
                'tgz' => 'application/x-tar',
                'thmx' => 'application/vnd.ms-officetheme',
                'tif' => 'image/tiff',
                'tiff' => 'image/tiff',
                'tk' => 'application/x-tcl',
                'tmo' => 'application/vnd.tmobile-livetv',
                'toml' => 'application/toml',
                'torrent' => 'application/x-bittorrent',
                'tpl' => 'application/vnd.groove-tool-template',
                'tpt' => 'application/vnd.trid.tpt',
                'tr' => 'text/troff',
                'tra' => 'application/vnd.trueapp',
                'trig' => 'application/trig',
                'trm' => 'application/x-msterminal',
                'ts' => 'video/mp2t',
                'tsd' => 'application/timestamped-data',
                'tsv' => 'text/tab-separated-values',
                'ttc' => 'font/collection',
                'ttf' => 'font/ttf',
                'ttl' => 'text/turtle',
                'ttml' => 'application/ttml+xml',
                'twd' => 'application/vnd.simtech-mindmapper',
                'twds' => 'application/vnd.simtech-mindmapper',
                'txd' => 'application/vnd.genomatix.tuxedo',
                'txf' => 'application/vnd.mobius.txf',
                'txt' => 'text/plain',
                'u3d' => 'model/u3d',
                'u8dsn' => 'message/global-delivery-status',
                'u8hdr' => 'message/global-headers',
                'u8mdn' => 'message/global-disposition-notification',
                'u8msg' => 'message/global',
                'u32' => 'application/x-authorware-bin',
                'ubj' => 'application/ubjson',
                'udeb' => 'application/x-debian-package',
                'ufd' => 'application/vnd.ufdl',
                'ufdl' => 'application/vnd.ufdl',
                'ulx' => 'application/x-glulx',
                'umj' => 'application/vnd.umajin',
                'unityweb' => 'application/vnd.unity',
                'uo' => 'application/vnd.uoml+xml',
                'uoml' => 'application/vnd.uoml+xml',
                'uri' => 'text/uri-list',
                'uris' => 'text/uri-list',
                'urls' => 'text/uri-list',
                'usda' => 'model/vnd.usda',
                'usdz' => 'model/vnd.usdz+zip',
                'ustar' => 'application/x-ustar',
                'utz' => 'application/vnd.uiq.theme',
                'uu' => 'text/x-uuencode',
                'uva' => 'audio/vnd.dece.audio',
                'uvd' => 'application/vnd.dece.data',
                'uvf' => 'application/vnd.dece.data',
                'uvg' => 'image/vnd.dece.graphic',
                'uvh' => 'video/vnd.dece.hd',
                'uvi' => 'image/vnd.dece.graphic',
                'uvm' => 'video/vnd.dece.mobile',
                'uvp' => 'video/vnd.dece.pd',
                'uvs' => 'video/vnd.dece.sd',
                'uvt' => 'application/vnd.dece.ttml+xml',
                'uvu' => 'video/vnd.uvvu.mp4',
                'uvv' => 'video/vnd.dece.video',
                'uvva' => 'audio/vnd.dece.audio',
                'uvvd' => 'application/vnd.dece.data',
                'uvvf' => 'application/vnd.dece.data',
                'uvvg' => 'image/vnd.dece.graphic',
                'uvvh' => 'video/vnd.dece.hd',
                'uvvi' => 'image/vnd.dece.graphic',
                'uvvm' => 'video/vnd.dece.mobile',
                'uvvp' => 'video/vnd.dece.pd',
                'uvvs' => 'video/vnd.dece.sd',
                'uvvt' => 'application/vnd.dece.ttml+xml',
                'uvvu' => 'video/vnd.uvvu.mp4',
                'uvvv' => 'video/vnd.dece.video',
                'uvvx' => 'application/vnd.dece.unspecified',
                'uvvz' => 'application/vnd.dece.zip',
                'uvx' => 'application/vnd.dece.unspecified',
                'uvz' => 'application/vnd.dece.zip',
                'vbox' => 'application/x-virtualbox-vbox',
                'vbox-extpack' => 'application/x-virtualbox-vbox-extpack',
                'vcard' => 'text/vcard',
                'vcd' => 'application/x-cdlink',
                'vcf' => 'text/x-vcard',
                'vcg' => 'application/vnd.groove-vcard',
                'vcs' => 'text/x-vcalendar',
                'vcx' => 'application/vnd.vcx',
                'vdi' => 'application/x-virtualbox-vdi',
                'vds' => 'model/vnd.sap.vds',
                'vhd' => 'application/x-virtualbox-vhd',
                'vis' => 'application/vnd.visionary',
                'viv' => 'video/vnd.vivo',
                'vlc' => 'application/videolan',
                'vmdk' => 'application/x-virtualbox-vmdk',
                'vob' => 'video/x-ms-vob',
                'vor' => 'application/vnd.stardivision.writer',
                'vox' => 'application/x-authorware-bin',
                'vrml' => 'model/vrml',
                'vsd' => 'application/vnd.visio',
                'vsf' => 'application/vnd.vsf',
                'vss' => 'application/vnd.visio',
                'vst' => 'application/vnd.visio',
                'vsw' => 'application/vnd.visio',
                'vtf' => 'image/vnd.valve.source.texture',
                'vtt' => 'text/vtt',
                'vtu' => 'model/vnd.vtu',
                'vxml' => 'application/voicexml+xml',
                'w3d' => 'application/x-director',
                'wad' => 'application/x-doom',
                'wadl' => 'application/vnd.sun.wadl+xml',
                'war' => 'application/java-archive',
                'wasm' => 'application/wasm',
                'wav' => 'audio/x-wav',
                'wax' => 'audio/x-ms-wax',
                'wbmp' => 'image/vnd.wap.wbmp',
                'wbs' => 'application/vnd.criticaltools.wbs+xml',
                'wbxml' => 'application/wbxml',
                'wcm' => 'application/vnd.ms-works',
                'wdb' => 'application/vnd.ms-works',
                'wdp' => 'image/vnd.ms-photo',
                'weba' => 'audio/webm',
                'webapp' => 'application/x-web-app-manifest+json',
                'webm' => 'video/webm',
                'webmanifest' => 'application/manifest+json',
                'webp' => 'image/webp',
                'wg' => 'application/vnd.pmi.widget',
                'wgsl' => 'text/wgsl',
                'wgt' => 'application/widget',
                'wif' => 'application/watcherinfo+xml',
                'wks' => 'application/vnd.ms-works',
                'wm' => 'video/x-ms-wm',
                'wma' => 'audio/x-ms-wma',
                'wmd' => 'application/x-ms-wmd',
                'wmf' => 'image/wmf',
                'wml' => 'text/vnd.wap.wml',
                'wmlc' => 'application/wmlc',
                'wmls' => 'text/vnd.wap.wmlscript',
                'wmlsc' => 'application/vnd.wap.wmlscriptc',
                'wmv' => 'video/x-ms-wmv',
                'wmx' => 'video/x-ms-wmx',
                'wmz' => 'application/x-msmetafile',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'word' => 'application/msword',
                'wpd' => 'application/vnd.wordperfect',
                'wpl' => 'application/vnd.ms-wpl',
                'wps' => 'application/vnd.ms-works',
                'wqd' => 'application/vnd.wqd',
                'wri' => 'application/x-mswrite',
                'wrl' => 'model/vrml',
                'wsc' => 'message/vnd.wfa.wsc',
                'wsdl' => 'application/wsdl+xml',
                'wspolicy' => 'application/wspolicy+xml',
                'wtb' => 'application/vnd.webturbo',
                'wvx' => 'video/x-ms-wvx',
                'x3d' => 'model/x3d+xml',
                'x3db' => 'model/x3d+fastinfoset',
                'x3dbz' => 'model/x3d+binary',
                'x3dv' => 'model/x3d-vrml',
                'x3dvz' => 'model/x3d+vrml',
                'x3dz' => 'model/x3d+xml',
                'x32' => 'application/x-authorware-bin',
                'x_b' => 'model/vnd.parasolid.transmit.binary',
                'x_t' => 'model/vnd.parasolid.transmit.text',
                'xaml' => 'application/xaml+xml',
                'xap' => 'application/x-silverlight-app',
                'xar' => 'application/vnd.xara',
                'xav' => 'application/xcap-att+xml',
                'xbap' => 'application/x-ms-xbap',
                'xbd' => 'application/vnd.fujixerox.docuworks.binder',
                'xbm' => 'image/x-xbitmap',
                'xca' => 'application/xcap-caps+xml',
                'xcs' => 'application/calendar+xml',
                'xdcf' => 'application/vnd.gov.sk.xmldatacontainer+xml',
                'xdf' => 'application/xcap-diff+xml',
                'xdm' => 'application/vnd.syncml.dm+xml',
                'xdp' => 'application/vnd.adobe.xdp+xml',
                'xdssc' => 'application/dssc+xml',
                'xdw' => 'application/vnd.fujixerox.docuworks',
                'xel' => 'application/xcap-el+xml',
                'xenc' => 'application/xenc+xml',
                'xer' => 'application/patch-ops-error+xml',
                'xfdf' => 'application/xfdf',
                'xfdl' => 'application/vnd.xfdl',
                'xht' => 'application/xhtml+xml',
                'xhtm' => 'application/vnd.pwg-xhtml-print+xml',
                'xhtml' => 'application/xhtml+xml',
                'xhvml' => 'application/xv+xml',
                'xif' => 'image/vnd.xiff',
                'xl' => 'application/excel',
                'xla' => 'application/vnd.ms-excel',
                'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
                'xlc' => 'application/vnd.ms-excel',
                'xlf' => 'application/xliff+xml',
                'xlm' => 'application/vnd.ms-excel',
                'xls' => 'application/vnd.ms-excel',
                'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
                'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xlt' => 'application/vnd.ms-excel',
                'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
                'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
                'xlw' => 'application/vnd.ms-excel',
                'xm' => 'audio/xm',
                'xml' => 'application/xml',
                'xns' => 'application/xcap-ns+xml',
                'xo' => 'application/vnd.olpc-sugar',
                'xop' => 'application/xop+xml',
                'xpi' => 'application/x-xpinstall',
                'xpl' => 'application/xproc+xml',
                'xpm' => 'image/x-xpixmap',
                'xpr' => 'application/vnd.is-xpr',
                'xps' => 'application/vnd.ms-xpsdocument',
                'xpw' => 'application/vnd.intercon.formnet',
                'xpx' => 'application/vnd.intercon.formnet',
                'xsd' => 'application/xml',
                'xsf' => 'application/prs.xsf+xml',
                'xsl' => 'application/xml',
                'xslt' => 'application/xslt+xml',
                'xsm' => 'application/vnd.syncml+xml',
                'xspf' => 'application/xspf+xml',
                'xul' => 'application/vnd.mozilla.xul+xml',
                'xvm' => 'application/xv+xml',
                'xvml' => 'application/xv+xml',
                'xwd' => 'image/x-xwindowdump',
                'xyz' => 'chemical/x-xyz',
                'xz' => 'application/x-xz',
                'yaml' => 'text/yaml',
                'yang' => 'application/yang',
                'yin' => 'application/yin+xml',
                'yml' => 'text/yaml',
                'ymp' => 'text/x-suse-ymp',
                'z' => 'application/x-compress',
                'z1' => 'application/x-zmachine',
                'z2' => 'application/x-zmachine',
                'z3' => 'application/x-zmachine',
                'z4' => 'application/x-zmachine',
                'z5' => 'application/x-zmachine',
                'z6' => 'application/x-zmachine',
                'z7' => 'application/x-zmachine',
                'z8' => 'application/x-zmachine',
                'zaz' => 'application/vnd.zzazz.deck+xml',
                'zip' => 'application/zip',
                'zir' => 'application/vnd.zul',
                'zirz' => 'application/vnd.zul',
                'zmm' => 'application/vnd.handheld-entertainment+xml',
                'zsh' => 'text/x-scriptzsh',
        ];
    }

    class ExtensionMimeTypeDetector implements MimeTypeDetector
    {
        private static array $defaultMap = [];
        private array $map;

        public function __construct(array $map = [])
        {
            if (!self::$defaultMap) {
                self::$defaultMap = GeneratedExtensionToMimeTypeMap::MIME_TYPES_FOR_EXTENSIONS;
            }

            $this->map = $map ? $map + self::$defaultMap : self::$defaultMap;
        }

        public function detectMimeType(string $path, string $contents): ?string
        {
            return $this->detectMimeTypeFromPath($path);
        }

        public function detectMimeTypeFromPath(string $path): ?string
        {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            return $extension !== '' && isset($this->map[$extension]) ? $this->map[$extension] : null;
        }
    }

    class FinfoMimeTypeDetector implements MimeTypeDetector
    {
        private static ?ExtensionMimeTypeDetector $defaultExtensionDetector = null;
        private ExtensionMimeTypeDetector $extensionDetector;

        public function __construct(?ExtensionMimeTypeDetector $extensionDetector = null)
        {
            if ($extensionDetector instanceof ExtensionMimeTypeDetector) {
                $this->extensionDetector = $extensionDetector;
                return;
            }

            if (!(self::$defaultExtensionDetector instanceof ExtensionMimeTypeDetector)) {
                self::$defaultExtensionDetector = new ExtensionMimeTypeDetector();
            }

            $this->extensionDetector = self::$defaultExtensionDetector;
        }

        public function detectMimeType(string $path, string $contents): ?string
        {
            $mime = null;

            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);

                if ($finfo !== false) {
                    $mime = @finfo_buffer($finfo, $contents) ?: null;
                }
            }

            if (!$this->isConclusive($mime)) {
                $mime = $this->extensionDetector->detectMimeTypeFromPath($path);
            }

            return $mime ?: 'application/octet-stream';
        }

        public function detectMimeTypeFromPath(string $path): ?string
        {
            $mime = $this->extensionDetector->detectMimeTypeFromPath($path);

            return $mime ?: 'application/octet-stream';
        }

        private function isConclusive(?string $mime): bool
        {
            if ($mime === null || $mime === '' || $mime === 'application/octet-stream' || $mime === 'text/plain') {
                return false;
            }

            return true;
        }
    }
}
