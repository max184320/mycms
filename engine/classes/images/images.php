<?php

declare(strict_types=1);

namespace DleImages;

function normalize_image_format(?string $format, ?string $fallbackMime = null): string
{
    $format = strtolower(trim((string) ($format !== null && $format !== '' ? $format : ($fallbackMime ?? 'jpg'))));

    return match ($format) {
        'gif', 'image/gif' => 'gif',
        'png', 'image/png', 'image/x-png' => 'png',
        'jpg', 'jpeg', 'jfif', 'image/jpg', 'image/jpeg', 'image/pjpeg', 'image/jfif', 'image/jp2' => 'jpg',
        'webp', 'image/webp', 'image/x-webp', 'image/heic-sequence' => 'webp',
        'avif', 'image/avif', 'image/heif' => 'avif',
        'heic', 'image/heic' => 'heic',
        'data-url' => 'data-url',
        default => $format,
    };
}

function image_mime(string $format): string
{
    return match (normalize_image_format($format)) {
        'gif' => 'image/gif',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'heic' => 'image/heic',
        default => 'image/jpeg',
    };
}

function image_extension(?string $mime): string
{
    $format = normalize_image_format($mime);

    return $format === 'data-url' ? 'png' : $format;
}

function clamp_image_byte($value): int
{
    $value = (int) $value;

    if ($value < 0) {
        return 0;
    }

    if ($value > 255) {
        return 255;
    }

    return $value;
}

function clamp_image_alpha($value): float
{
    $value = (float) $value;

    if ($value < 0.0) {
        return 0.0;
    }

    if ($value > 1.0) {
        return 1.0;
    }

    return $value;
}

function normalize_image_color($value): array
{
    if ($value === null) {
        return [255, 255, 255, 0.0];
    }

    if (is_array($value)) {
        $value = array_values($value);

        if (count($value) === 3) {
            return [clamp_image_byte($value[0]), clamp_image_byte($value[1]), clamp_image_byte($value[2]), 1.0];
        }

        if (count($value) === 4) {
            return [clamp_image_byte($value[0]), clamp_image_byte($value[1]), clamp_image_byte($value[2]), clamp_image_alpha($value[3])];
        }

        throw new \RuntimeException('Color array must contain 3 or 4 elements.');
    }

    if (is_int($value)) {
        $alpha = ($value >> 24) & 0xFF;

        return [
            ($value >> 16) & 0xFF,
            ($value >> 8) & 0xFF,
            $value & 0xFF,
            round($alpha > 127 ? $alpha / 255 : (1 - ($alpha / 127)), 2),
        ];
    }

    if (is_string($value)) {
        $color = trim($value);
        $named = [
            'black' => [0, 0, 0, 1.0],
            'white' => [255, 255, 255, 1.0],
            'red' => [255, 0, 0, 1.0],
            'green' => [0, 128, 0, 1.0],
            'blue' => [0, 0, 255, 1.0],
            'transparent' => [255, 255, 255, 0.0],
        ];

        $key = strtolower($color);

        if (isset($named[$key])) {
            return $named[$key];
        }

        if (preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', $color, $matches)) {
            $hex = strtolower($matches[1]);

            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2)),
                1.0,
            ];
        }

        if (preg_match('/^rgb ?\(([0-9]{1,3}), ?([0-9]{1,3}), ?([0-9]{1,3})\)$/i', $color, $matches)) {
            return [clamp_image_byte($matches[1]), clamp_image_byte($matches[2]), clamp_image_byte($matches[3]), 1.0];
        }

        if (preg_match('/^rgba ?\(([0-9]{1,3}), ?([0-9]{1,3}), ?([0-9]{1,3}), ?([0-9.]{1,4})\)$/i', $color, $matches)) {
            return [clamp_image_byte($matches[1]), clamp_image_byte($matches[2]), clamp_image_byte($matches[3]), clamp_image_alpha($matches[4])];
        }
    }

    if (is_object($value) && class_exists('ImagickPixel', false) && $value instanceof \ImagickPixel) {
        return [
            (int) round($value->getColorValue(\Imagick::COLOR_RED) * 255),
            (int) round($value->getColorValue(\Imagick::COLOR_GREEN) * 255),
            (int) round($value->getColorValue(\Imagick::COLOR_BLUE) * 255),
            round($value->getColorValue(\Imagick::COLOR_ALPHA), 2),
        ];
    }

    throw new \RuntimeException('Unable to read color value.');
}

function gd_image_alpha(float $alpha): int
{
    return (int) ceil(((clamp_image_alpha($alpha) * (0 - 127)) / 1.0) + 127);
}

function gd_image_int(array $rgba): int
{
    return (gd_image_alpha((float) $rgba[3]) << 24)
        + ((int) $rgba[0] << 16)
        + ((int) $rgba[1] << 8)
        + (int) $rgba[2];
}

function gd_image_rgba(int $color): array
{
    $alpha = ($color >> 24) & 0x7F;

    return [
        ($color >> 16) & 0xFF,
        ($color >> 8) & 0xFF,
        $color & 0xFF,
        round(1 - ($alpha / 127), 2),
    ];
}

function imagick_image_pixel(array $rgba): \ImagickPixel
{
    return new \ImagickPixel(sprintf('rgba(%d, %d, %d, %.2F)', $rgba[0], $rgba[1], $rgba[2], clamp_image_alpha($rgba[3])));
}

function format_image_color(array $rgba, string $format = 'array')
{
    return match (strtolower($format)) {
        'rgba' => sprintf('rgba(%d, %d, %d, %.2F)', $rgba[0], $rgba[1], $rgba[2], clamp_image_alpha($rgba[3])),
        'hex' => sprintf('#%02x%02x%02x', $rgba[0], $rgba[1], $rgba[2]),
        'int', 'integer' => gd_image_int($rgba),
        'array' => [(int) $rgba[0], (int) $rgba[1], (int) $rgba[2], round((float) $rgba[3], 2)],
        default => throw new \RuntimeException(sprintf('Color format (%s) is not supported.', $format)),
    };
}

function image_anchor_offset(int $containerWidth, int $containerHeight, int $boxWidth, int $boxHeight, string $position = 'center', int $offsetX = 0, int $offsetY = 0): array
{
    $freeX = $containerWidth - $boxWidth;
    $freeY = $containerHeight - $boxHeight;

    return match (strtolower($position)) {
        'top', 'top-center', 'top-middle', 'center-top', 'middle-top' => [(int) ($freeX / 2) + $offsetX, $offsetY],
        'top-right', 'right-top' => [$freeX - $offsetX, $offsetY],
        'left', 'left-center', 'left-middle', 'center-left', 'middle-left' => [$offsetX, (int) ($freeY / 2) + $offsetY],
        'right', 'right-center', 'right-middle', 'center-right', 'middle-right' => [$freeX - $offsetX, (int) ($freeY / 2) + $offsetY],
        'bottom-left', 'left-bottom' => [$offsetX, $freeY - $offsetY],
        'bottom', 'bottom-center', 'bottom-middle', 'center-bottom', 'middle-bottom' => [(int) ($freeX / 2) + $offsetX, $freeY - $offsetY],
        'bottom-right', 'right-bottom' => [$freeX - $offsetX, $freeY - $offsetY],
        'center', 'middle', 'center-center', 'middle-middle' => [(int) ($freeX / 2) + $offsetX, (int) ($freeY / 2) + $offsetY],
        default => [$offsetX, $offsetY],
    };
}

function resize_image_dimensions(int $originalWidth, int $originalHeight, ?int $targetWidth, ?int $targetHeight, bool $keepAspect = false, bool $preventUpsize = false): array
{
    if ($targetWidth === null && $targetHeight === null) {
        throw new \InvalidArgumentException('Width or height needs to be defined.');
    }

    if (!$keepAspect) {
        $width = $targetWidth ?? $originalWidth;
        $height = $targetHeight ?? $originalHeight;
    } elseif ($targetWidth !== null && $targetHeight !== null) {
        $ratio = min($targetWidth / $originalWidth, $targetHeight / $originalHeight);

        if ($preventUpsize) {
            $ratio = min($ratio, 1);
        }

        $width = max(1, (int) round($originalWidth * $ratio));
        $height = max(1, (int) round($originalHeight * $ratio));
    } elseif ($targetWidth !== null) {
        $ratio = $targetWidth / $originalWidth;

        if ($preventUpsize) {
            $ratio = min($ratio, 1);
        }

        $width = max(1, (int) round($originalWidth * $ratio));
        $height = max(1, (int) round($originalHeight * $ratio));
    } else {
        $ratio = $targetHeight / $originalHeight;

        if ($preventUpsize) {
            $ratio = min($ratio, 1);
        }

        $width = max(1, (int) round($originalWidth * $ratio));
        $height = max(1, (int) round($originalHeight * $ratio));
    }

    if (!$keepAspect) {
        if ($preventUpsize) {
            $width = min($width, $originalWidth);
            $height = min($height, $originalHeight);
        }

        $width = max(1, (int) $width);
        $height = max(1, (int) $height);
    }

    return ['width' => (int) $width, 'height' => (int) $height];
}

function fit_image_crop(int $originalWidth, int $originalHeight, int $targetWidth, int $targetHeight, string $position = 'center'): array
{
    $sourceRatio = $originalWidth / $originalHeight;
    $targetRatio = $targetWidth / $targetHeight;

    if ($targetRatio > $sourceRatio) {
        $cropWidth = $originalWidth;
        $cropHeight = max(1, (int) round($originalWidth / $targetRatio));
    } else {
        $cropWidth = max(1, (int) round($originalHeight * $targetRatio));
        $cropHeight = $originalHeight;
    }

    [$x, $y] = image_anchor_offset($originalWidth, $originalHeight, $cropWidth, $cropHeight, $position);

    return [
        'x' => $x,
        'y' => $y,
        'width' => $cropWidth,
        'height' => $cropHeight,
    ];
}

function normalize_text_options(array $options = []): array
{
    return [
        'file' => isset($options['file']) ? $options['file'] : null,
        'size' => isset($options['size']) ? max(1, (int) $options['size']) : 12,
        'color' => $options['color'] ?? '000000',
        'angle' => isset($options['angle']) ? (int) $options['angle'] : 0,
        'align' => isset($options['align']) && $options['align'] !== '' ? (string) $options['align'] : null,
        'valign' => isset($options['valign']) && $options['valign'] !== '' ? (string) $options['valign'] : null,
        'kerning' => isset($options['kerning']) ? (float) $options['kerning'] : 0.0,
    ];
}

abstract class Driver
{
    public function __construct()
    {
        if (!function_exists('finfo_buffer')) {
            throw new \RuntimeException('PHP Fileinfo extension must be installed/enabled to use DLE images.');
        }

        if (!$this->isAvailable()) {
            throw new \RuntimeException(sprintf('%s driver is not available.', $this->name()));
        }
    }

    abstract protected function isAvailable(): bool;

    abstract public function name(): string;

    abstract public function newImage(int $width, int $height, $background = null): Image;

    abstract public function initFromPath(string $path): Image;

    abstract public function initFromBinary(string $binary): Image;

    abstract public function width(Image $image): int;

    abstract public function height(Image $image): int;

    abstract public function resize(Image $image, ?int $width, ?int $height, bool $keepAspect = false, bool $preventUpsize = false): void;

    abstract public function fit(Image $image, int $width, ?int $height = null, bool $preventUpsize = false, string $position = 'center'): void;

    abstract public function resizeCanvas(Image $image, int $width, int $height, string $anchor = 'center', bool $relative = false, $background = null): void;

    abstract public function pickColor(Image $image, int $x, int $y, string $format = 'array');

    abstract public function insert(Image $image, $source, string $position = 'top-left', int $x = 0, int $y = 0): void;

    abstract public function rotate(Image $image, float $angle, $background = null): void;

    abstract public function opacity(Image $image, int $transparency): void;

    abstract public function text(Image $image, string $text, int $x = 0, int $y = 0, array $options = []): void;

    abstract public function measureText(string $text, array $options = []): array;

    abstract public function encode(Image $image, ?string $format = null, int $quality = 90): string;

    abstract public function flip(Image $image, string $mode = 'h'): void;

    abstract public function exif(Image $image, ?string $key = null);

    public function cloneCore($core)
    {
        return clone $core;
    }

    public function init($data): Image
    {
        if ($data instanceof Image) {
            if ($data->getDriver() instanceof static) {
                return $data;
            }

            return $this->initFromBinary((string) $data->encode('png'));
        }

        if (is_string($data)) {
            if (is_file($data)) {
                return $this->initFromPath($data);
            }

            if ($this->isRemoteUrl($data)) {
                return $this->initFromBinary($this->loadRemoteBinary($data));
            }

            if ($this->isDataUrl($data)) {
                return $this->initFromBinary((string) $this->decodeDataUrl($data));
            }

            if ($this->isBinary($data)) {
                return $this->initFromBinary($data);
            }

            if ($this->isBase64($data)) {
                $decoded = base64_decode(str_replace(["\n", "\r"], '', $data), true);

                if ($decoded !== false) {
                    return $this->initFromBinary($decoded);
                }
            }
        }

        throw new \RuntimeException('Image source not readable.');
    }

    public function orientate(Image $image): void
    {
        $orientation = (int) ($this->exif($image, 'Orientation') ?? 0);

        switch ($orientation) {
            case 2:
                $this->flip($image);
                break;

            case 3:
                $this->rotate($image, 180);
                break;

            case 4:
                $this->rotate($image, 180);
                $this->flip($image);
                break;

            case 5:
                $this->rotate($image, 270);
                $this->flip($image);
                break;

            case 6:
                $this->rotate($image, 270);
                break;

            case 7:
                $this->rotate($image, 90);
                $this->flip($image);
                break;

            case 8:
                $this->rotate($image, 90);
                break;
        }
    }

    protected function isBinary(string $data): bool
    {
        $mime = @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $data);

        return is_string($mime) && strpos($mime, 'text') !== 0 && $mime !== 'application/x-empty';
    }

    protected function isBase64(string $data): bool
    {
        $plain = str_replace(["\n", "\r"], '', $data);

        return $plain !== '' && base64_encode((string) base64_decode($plain, true)) === $plain;
    }

    protected function isDataUrl(string $data): bool
    {
        return $this->decodeDataUrl($data) !== null;
    }

    protected function decodeDataUrl(string $data): ?string
    {
        if (!preg_match('/^data:(?:image\/[a-zA-Z\\-\\.]+)(?:charset=\".+\")?;base64,(?P<data>.+)$/', str_replace(["\n", "\r"], '', $data), $matches)) {
            return null;
        }

        $decoded = base64_decode($matches['data'], true);

        return $decoded === false ? null : $decoded;
    }

    protected function isRemoteUrl(string $data): bool
    {
        if (!filter_var($data, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($data, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    protected function loadRemoteBinary(string $url): string
    {
        $this->assertSafeRemoteUrl($url);
        
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        
        $parsedUrl = parse_url($url);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        $referer = $host ? "{$scheme}://{$host}/" : $url;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_REFERER => $referer,
                CURLOPT_HTTPHEADER => ['Accept: image/*'],
            ]);

            $binary = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($binary !== false && $error === '' && $status >= 200 && $status < 300) {
                return (string) $binary;
            }
        }

        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException('Unable to read image from remote source.');
        }

        $headers = "Accept: image/*\r\n"
            . "User-Agent: {$userAgent}\r\n"
            . "Referer: {$referer}\r\n";

        $contextOptions = [
            'method' => 'GET',
            'timeout' => 30,
            'follow_location' => 0,
            'header' => $headers,
        ];

        $context = stream_context_create([
            'http' => $contextOptions,
            'https' => $contextOptions,
        ]);

        $binary = @file_get_contents($url, false, $context);

        if ($binary === false) {
            throw new \RuntimeException('Unable to read image from remote source.');
        }

        return $binary;
    }

    protected function assertSafeRemoteUrl(string $url): void {
        $parsed = parse_url($url);

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException('Invalid URL scheme. Only HTTP and HTTPS are allowed.');
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            throw new \RuntimeException('Invalid URL host.');
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]') {
            throw new \RuntimeException('Remote image host is not allowed.');
        }


        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Remote image host is not allowed.');
            }
        }
    }
}

final class ImageManagerStatic
{
    private static array $config = ['driver' => 'gd'];
    private static array $drivers = [];

    public static function configure(array $config = []): void
    {
        self::$config = array_replace(self::$config, $config);
    }

    public static function make($data): Image
    {
        return self::driver()->init($data);
    }

    public static function canvas($width, $height, $background = null): Image
    {
        return self::driver()->newImage((int) $width, (int) $height, $background);
    }

    public static function measureText(string $text, array $options = []): array
    {
        return self::driver()->measureText($text, $options);
    }

    private static function driver(): Driver
    {
        $driver = strtolower((string) (self::$config['driver'] ?? 'gd'));
        $driver = $driver === 'imagick' ? 'imagick' : 'gd';

        if (!isset(self::$drivers[$driver])) {
            
            include_once(\DLEPlugins::Check(ENGINE_DIR . '/classes/images/adapters/' . $driver . '.adapter.php'));

            $class = $driver === 'imagick' ? ImagickDriver::class : GdDriver::class;
            self::$drivers[$driver] = new $class();
        }

        return self::$drivers[$driver];
    }
}

class Image
{
    private Driver $driver;
    private $core;
    private array $backups = [];

    public ?string $mime = null;
    public ?string $dirname = null;
    public ?string $basename = null;
    public ?string $extension = null;
    public ?string $filename = null;
    public string $encoded = '';

    public function __construct(Driver $driver, $core)
    {
        $this->driver = $driver;
        $this->core = $core;
    }

    public function getDriver(): Driver
    {
        return $this->driver;
    }

    public function getCore()
    {
        return $this->core;
    }

    public function setCore($core): self
    {
        $this->core = $core;

        return $this;
    }

    public function setFileInfoFromPath(string $path): self
    {
        $info = pathinfo($path);
        $this->dirname = isset($info['dirname']) ? (string) $info['dirname'] : null;
        $this->basename = isset($info['basename']) ? (string) $info['basename'] : null;
        $this->extension = isset($info['extension']) ? (string) $info['extension'] : null;
        $this->filename = isset($info['filename']) ? (string) $info['filename'] : null;

        if (is_file($path)) {
            $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);

            if (is_string($mime) && $mime !== '') {
                $this->mime = strtolower($mime);
            }
        }

        return $this;
    }

    public function basePath(): ?string
    {
        if ($this->dirname === null || $this->basename === null) {
            return null;
        }

        return $this->dirname . '/' . $this->basename;
    }

    public function filesize()
    {
        $path = $this->basePath();

        return ($path !== null && is_file($path)) ? @filesize($path) : false;
    }

    public function width(): int
    {
        return $this->driver->width($this);
    }

    public function height(): int
    {
        return $this->driver->height($this);
    }

    public function mime(): string
    {
        return $this->mime ?? image_mime($this->extension ?? 'png');
    }

    public function orientate(): self
    {
        $this->driver->orientate($this);

        return $this;
    }

    public function backup(string $name = 'default'): self
    {
        $this->backups[$name] = $this->driver->cloneCore($this->core);

        return $this;
    }

    public function reset(string $name = 'default'): self
    {
        if (!array_key_exists($name, $this->backups)) {
            throw new \RuntimeException(sprintf('Backup with name (%s) not available. Call backup() before reset().', $name));
        }

        $this->core = $this->driver->cloneCore($this->backups[$name]);

        return $this;
    }

    public function fit(int $width, ?int $height = null, bool $preventUpsize = false, string $position = 'center'): self
    {
        $this->driver->fit($this, $width, $height, $preventUpsize, $position);

        return $this;
    }

    public function widen(int $width, bool $preventUpsize = false): self
    {
        $this->driver->resize($this, $width, null, true, $preventUpsize);

        return $this;
    }

    public function heighten(int $height, bool $preventUpsize = false): self
    {
        $this->driver->resize($this, null, $height, true, $preventUpsize);

        return $this;
    }

    public function resize(?int $width = null, ?int $height = null, bool $keepAspect = false, bool $preventUpsize = false): self
    {
        $this->driver->resize($this, $width, $height, $keepAspect, $preventUpsize);

        return $this;
    }

    public function resizeCanvas(int $width, int $height, string $anchor = 'center', bool $relative = false, $background = null): self
    {
        $this->driver->resizeCanvas($this, $width, $height, $anchor, $relative, $background);

        return $this;
    }

    public function pickColor(int $x, int $y, string $format = 'array')
    {
        return $this->driver->pickColor($this, $x, $y, $format);
    }

    public function insert($source, string $position = 'top-left', int $x = 0, int $y = 0): self
    {
        $this->driver->insert($this, $source, $position, $x, $y);

        return $this;
    }

    public function rotate(float $angle, $background = null): self
    {
        $this->driver->rotate($this, $angle, $background);

        return $this;
    }

    public function opacity(int $transparency): self
    {
        $this->driver->opacity($this, $transparency);

        return $this;
    }

    public function text(string $text, int $x = 0, int $y = 0, array $options = []): self
    {
        $this->driver->text($this, $text, $x, $y, $options);

        return $this;
    }

    public function flip(string $mode = 'h'): self
    {
        $this->driver->flip($this, $mode);

        return $this;
    }

    public function exif(?string $key = null)
    {
        return $this->driver->exif($this, $key);
    }

    public function encode(?string $format = null, int $quality = 90): self
    {
        $quality = $quality === 0 ? 1 : max(1, min(100, $quality));
        $this->encoded = $this->driver->encode($this, $format, $quality);

        return $this;
    }

    public function save(?string $path = null, ?int $quality = null, ?string $format = null): self
    {
        $path = $path ?? $this->basePath();

        if ($path === null || $path === '') {
            throw new \RuntimeException("Can't write to undefined path.");
        }

        if ($format === null) {
            $format = pathinfo($path, PATHINFO_EXTENSION) ?: image_extension($this->mime);
        }

        $binary = (string) $this->encode($format, $quality ?? 90);
        $written = @file_put_contents($path, $binary, LOCK_EX);

        if ($written === false) {
            throw new \RuntimeException(sprintf("Can't write image data to path (%s)", $path));
        }

        $this->setFileInfoFromPath($path);

        return $this;
    }

    public function destroy(): void
    {
        if (class_exists('GdImage', false) && $this->core instanceof \GdImage) {
            return;
        }

        if ($this->core instanceof \Imagick) {
            $this->core->clear();
        }
    }

    public function __toString(): string
    {
        return $this->encoded;
    }

    public function __clone()
    {
        $this->core = $this->driver->cloneCore($this->core);

        foreach ($this->backups as $name => $backup) {
            $this->backups[$name] = $this->driver->cloneCore($backup);
        }
    }
}
