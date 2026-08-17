<?php

declare(strict_types=1);

namespace DleFilesystem\Adapters {

    use FilesystemIterator;
    use DleFilesystem\ChecksumProvider;
    use DleFilesystem\Config;
    use DleFilesystem\DirectoryAttributes;
    use DleFilesystem\FileAttributes;
    use DleFilesystem\FilesystemAdapter;
    use DleFilesystem\PathPrefixer;
    use DleFilesystem\FilesystemException;
    use DleFilesystem\Visibility\PortableVisibilityConverter;
    use DleFilesystem\Visibility\VisibilityConverter;
    use DleFilesystem\MimeTypeDetection\FinfoMimeTypeDetector;
    use DleFilesystem\MimeTypeDetection\MimeTypeDetector;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use SplFileInfo;
    use Throwable;

    class LocalAdapter implements FilesystemAdapter, ChecksumProvider
    {
        public const SKIP_LINKS = 1;
        public const DISALLOW_LINKS = 2;

        private string $rootLocation;
        private PathPrefixer $prefixer;
        private VisibilityConverter $visibility;
        private int $writeFlags;
        private int $linkHandling;
        private MimeTypeDetector $mimeTypeDetector;
        private bool $rootLocationIsSetup = false;

        public function __construct(string $location, ?VisibilityConverter $visibility = null, int $writeFlags = LOCK_EX, int $linkHandling = self::DISALLOW_LINKS, ?MimeTypeDetector $mimeTypeDetector = null, bool $lazyRootCreation = false, bool $useInconclusiveMimeTypeFallback = false)
        {
            $location = str_replace('\\', '/', trim($location));
            $this->rootLocation = $location === '/' ? '/' : rtrim($location, '/');
            $this->prefixer = new PathPrefixer($this->rootLocation);
            $this->visibility = $visibility ?? new PortableVisibilityConverter();
            $this->writeFlags = $writeFlags;
            $this->linkHandling = $linkHandling;
            $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();

            if (!$lazyRootCreation) {
                $this->ensureRootDirectoryExists();
            }
        }

        public function fileExists(string $path): bool
        {
            try {
                $location = $this->fullPath($path);
            } catch (Throwable $exception) {
                return false;
            }

            return !is_link($location) && is_file($location);
        }

        public function directoryExists(string $path): bool
        {
            try {
                $location = $this->fullPath($path);
            } catch (Throwable $exception) {
                return false;
            }

            return !is_link($location) && is_dir($location);
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->writeToFile($path, $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            if (!is_resource($contents)) {
                throw FilesystemException::atLocation($path, 'invalid stream resource');
            }

            $this->writeToFile($path, $contents, $config);
        }

        public function read(string $path): string
        {
            $location = $this->fullPath($path);

            if (!is_file($location) || !is_readable($location)) {
                throw FilesystemException::fromLocation($path, 'file is not readable');
            }

            $contents = @file_get_contents($location);

            if ($contents === false) {
                throw FilesystemException::fromLocation('', 'Unable to read file from location: ' . $path);
            }

            return $contents;
        }

        public function readStream(string $path)
        {
            $location = $this->fullPath($path);

            if (!is_file($location) || !is_readable($location)) {
                throw FilesystemException::fromLocation($path, 'file is not readable');
            }

            $stream = @fopen($location, 'rb');

            if ($stream === false) {
                throw FilesystemException::fromLocation('', 'Unable to read file from stream: ' . $path);
            }

            return $stream;
        }

        public function delete(string $path): void
        {
            $location = $this->fullPath($path);

            if (!file_exists($location)) {
                return;
            }

            if (!@unlink($location)) {
                throw FilesystemException::atLocation('', "Unable to delete file located at: " . $path);
            }
        }

        public function deleteDirectory(string $path): void
        {
            $location = $this->fullPath($path);

            if ($location === $this->rootLocation) {
                throw FilesystemException::atLocation($path, 'refusing to delete root directory');
            }

            if (!is_dir($location)) {
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    if (!@unlink($item->getPathname())) {
                        throw FilesystemException::atLocation($path, 'failed to delete symbolic link');
                    }

                    continue;
                }

                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        throw FilesystemException::atLocation('', 'Unable to delete directory located at: ' . $path);
                    }
                } else {
                    if (!@unlink($item->getPathname())) {
                        throw FilesystemException::atLocation('', 'Unable to delete file located at: ' . $path);
                    }
                }
            }

            if (!@rmdir($location)) {
                throw FilesystemException::atLocation('', 'Unable to delete directory located at: ' . $path);
            }
        }

        public function createDirectory(string $path, Config $config): void
        {
            $location = $this->fullPath($path);
            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            $this->ensureDirectoryExists($location, $this->visibility->forDirectory($visibility));
        }

        public function setVisibility(string $path, string $visibility): void
        {
            $location = $this->fullPath($path);
            $mode = is_dir($location) ? $this->visibility->forDirectory($visibility) : $this->visibility->forFile($visibility);

            @chmod($location, $mode);
        }

        public function visibility(string $path): FileAttributes
        {
            return $this->buildFileAttributes($path, true, false, false, false);
        }

        public function mimeType(string $path): FileAttributes
        {
            return $this->buildFileAttributes($path, false, true, false, false);
        }

        public function lastModified(string $path): FileAttributes
        {
            return $this->buildFileAttributes($path, false, false, true, false);
        }

        public function fileSize(string $path): FileAttributes
        {
            return $this->buildFileAttributes($path, false, false, false, true);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            $location = $this->fullPath($path);

            if (!is_dir($location)) {
                return;
            }

            $iterator = $deep
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)
                : new \IteratorIterator(new \DirectoryIterator($location));

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || $item->getFilename() === '.' || $item->getFilename() === '..') {
                    continue;
                }

                if ($item->isLink()) {
                    if ($this->linkHandling === self::SKIP_LINKS) {
                        continue;
                    }

                    throw FilesystemException::create($path, 'link', 'symbolic links are not allowed');
                }

                $relativePath = $this->prefixer->stripPrefix(str_replace('\\', '/', $item->getPathname()));
                $permissions = $item->getPerms() & 0777;
                $lastModified = $item->getMTime();

                if ($item->isDir()) {
                    yield new DirectoryAttributes($relativePath, $this->visibility->inverseForDirectory($permissions), $lastModified);
                    continue;
                }

                yield new FileAttributes($relativePath, $item->getSize(), $this->visibility->inverseForFile($permissions), $lastModified);
            }
        }

        public function move(string $source, string $destination, Config $config): void
        {
            $sourcePath = $this->fullPath($source);
            $destinationPath = $this->fullPath($destination, true);
            $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            $this->ensureDirectoryExists(dirname($destinationPath), $this->visibility->forDirectory((string) $visibility));

            if (!@rename($sourcePath, $destinationPath)) {
                throw FilesystemException::because('rename failed', $source, $destination);
            }
        }

        public function copy(string $source, string $destination, Config $config): void
        {
            $sourcePath = $this->fullPath($source);
            $destinationPath = $this->fullPath($destination, true);
            $visibility = $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            $this->ensureDirectoryExists(dirname($destinationPath), $this->visibility->forDirectory((string) $visibility));

            if (!@copy($sourcePath, $destinationPath)) {
                throw FilesystemException::fromLocationTo($source, $destination);
            }

            $targetVisibility = $config->get(Config::OPTION_VISIBILITY);

            if (is_string($targetVisibility) && $targetVisibility !== '') {
                $this->setVisibility($destination, $targetVisibility);
            }
        }

        public function checksum(string $path, Config $config): string
        {
            $location = $this->fullPath($path);
            $algo = (string) $config->get('checksum_algo', 'md5');

            if (!in_array($algo, hash_algos(), true)) {
                throw FilesystemException::forPath($path, 'hash algorithm is not supported');
            }

            $checksum = @hash_file($algo, $location);

            if ($checksum === false) {
                throw FilesystemException::forPath($path, 'hash_file failed');
            }

            return $checksum;
        }

        private function writeToFile(string $path, $contents, Config $config): void
        {
            $location = $this->fullPath($path, true);
            $visibility = (string) $config->get(Config::OPTION_DIRECTORY_VISIBILITY, $config->get(Config::OPTION_VISIBILITY, 'public'));

            $this->ensureRootDirectoryExists();
            $this->ensureDirectoryExists(dirname($location), $this->visibility->forDirectory($visibility));

            $result = is_resource($contents)
                ? @file_put_contents($location, $contents, $this->writeFlags)
                : @file_put_contents($location, (string) $contents, $this->writeFlags);

            if ($result === false) {
                throw FilesystemException::atLocation('', 'Unable to write file at location: '.$path.'. Check directory permissions.');
            }

            $fileVisibility = $config->get(Config::OPTION_VISIBILITY);

            if (is_string($fileVisibility) && $fileVisibility !== '') {
                $this->setVisibility($path, $fileVisibility);
            }
        }

        private function ensureRootDirectoryExists(): void
        {
            if ($this->rootLocationIsSetup) {
                return;
            }

            $this->ensureDirectoryExists($this->rootLocation, $this->visibility->defaultForDirectories());
            $this->rootLocationIsSetup = true;
        }

        private function ensureDirectoryExists(string $location, int $visibility): void
        {
            $location = str_replace('\\', '/', $location);
            $location = $location === '/' ? '/' : rtrim($location, '/');

            if ($location === '' || $location === '.') {
                return;
            }

            if (is_link($location)) {
                throw FilesystemException::atLocation($this->prefixer->stripPrefix($location), 'symbolic links are not allowed');
            }

            if (is_dir($location)) {
                return;
            }

            $rootLocation = str_replace('\\', '/', $this->rootLocation);
            $rootLocation = $rootLocation === '/' ? '/' : rtrim($rootLocation, '/');
            $rootPrefix = $rootLocation === '' || $rootLocation === '/' ? $rootLocation : $rootLocation . '/';

            if ($rootLocation !== '' && $rootLocation !== '/' && ($location === $rootLocation || strpos($location, $rootPrefix) === 0)) {
                $relative = $location === $rootLocation ? '' : substr($location, strlen($rootPrefix));
                $current = $rootLocation;

                if (is_link($current)) {
                    throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'symbolic links are not allowed');
                }

                if (!is_dir($current)) {
                    if (!@mkdir($current, $visibility) && !is_dir($current)) {
                        throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'Unable to create a directory, check the permissions.');
                    }

                    @chmod($current, $visibility);
                }

                if ($relative === '') {
                    return;
                }

                foreach (explode('/', $relative) as $segment) {
                    if ($segment === '') {
                        continue;
                    }

                    $current .= '/' . $segment;

                    if (is_link($current)) {
                        throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'symbolic links are not allowed');
                    }

                    if (!is_dir($current)) {
                        if (!@mkdir($current, $visibility) && !is_dir($current)) {
                            throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'Unable to create a directory, check the permissions.');
                        }

                        @chmod($current, $visibility);
                    }
                }

                return;
            }

            $current = strpos($location, '/') === 0 ? '/' : '';

            foreach (explode('/', trim($location, '/')) as $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '/' ? '/' . $segment : ($current === '' ? $segment : $current . '/' . $segment);

                if (is_link($current)) {
                    throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'symbolic links are not allowed');
                }

                if (!is_dir($current)) {
                    if (!@mkdir($current, $visibility) && !is_dir($current)) {
                        throw FilesystemException::atLocation($this->prefixer->stripPrefix($current), 'Unable to create a directory, check the permissions.');
                    }

                    @chmod($current, $visibility);
                }
            }
        }

        private function buildFileAttributes(string $path, bool $withVisibility, bool $withMimeType, bool $withLastModified, bool $withFileSize): FileAttributes
        {
            $location = $this->fullPath($path);

            if (!is_file($location)) {
                throw FilesystemException::create($path, 'file', 'file not found');
            }

            clearstatcache(true, $location);

            try {
                return new FileAttributes(
                    $path,
                    $withFileSize ? (int) filesize($location) : null,
                    $withVisibility ? $this->visibility->inverseForFile(fileperms($location) & 0777) : null,
                    $withLastModified ? (int) filemtime($location) : null,
                    $withMimeType ? $this->mimeTypeDetector->detectMimeTypeFromPath($location) : null
                );
            } catch (Throwable $exception) {
                throw FilesystemException::create($path, 'file', $exception->getMessage(), $exception);
            }
        }

        private function fullPath(string $path, bool $allowMissingLeaf = false): string
        {
            $normalizedPath = PathPrefixer::normalizeRelativePath($path);
            $location = $this->rootLocation;

            if ($normalizedPath !== '') {
                $location = $location === '' ? $normalizedPath : rtrim($location, '/') . '/' . $normalizedPath;
            }

            $location = str_replace('\\', '/', $location);
            $this->guardAgainstSymlinks($location, $normalizedPath, $allowMissingLeaf);

            return $location;
        }

        private function guardAgainstSymlinks(string $location, string $path, bool $allowMissingLeaf): void
        {
            if ($location === $this->rootLocation || $this->linkHandling !== self::DISALLOW_LINKS) {
                return;
            }

            $relative = ltrim(substr($location, strlen($this->rootLocation)), '/');

            if ($relative === '') {
                return;
            }

            $segments = explode('/', $relative);
            $current = $this->rootLocation;
            $lastIndex = count($segments) - 1;

            foreach ($segments as $index => $segment) {
                if ($segment === '') {
                    continue;
                }

                $current = $current === '/'
                    ? '/' . $segment
                    : ($current === '' ? $segment : $current . '/' . $segment);

                if (is_link($current)) {
                    throw FilesystemException::create($path, 'link', 'symbolic links are not allowed');
                }

                if (!file_exists($current)) {
                    if ($allowMissingLeaf && $index === $lastIndex) {
                        return;
                    }

                    return;
                }
            }
        }
    }
}
