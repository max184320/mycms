<?php

declare(strict_types=1);

namespace DleImages;

use function DleImages\fit_image_crop;
use function DleImages\format_image_color;
use function DleImages\gd_image_alpha;
use function DleImages\gd_image_int;
use function DleImages\gd_image_rgba;
use function DleImages\image_anchor_offset;
use function DleImages\image_mime;
use function DleImages\normalize_image_color;
use function DleImages\normalize_image_format;
use function DleImages\normalize_text_options;
use function DleImages\resize_image_dimensions;

final class GdDriver extends Driver
{
    protected function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('gd_info');
    }

    public function name(): string
    {
        return 'gd';
    }

    public function newImage(int $width, int $height, $background = null): Image
    {
        $image = new Image($this, $this->createCanvas($width, $height, $background));
        $image->mime = image_mime('png');

        return $image;
    }

    public function initFromPath(string $path): Image
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Unable to find file (%s).', $path));
        }

        $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
        $mime = is_string($mime) && $mime !== '' ? strtolower($mime) : '';

        $core = match ($mime) {
            'image/png', 'image/x-png' => @imagecreatefrompng($path),
            'image/jpg', 'image/jpeg', 'image/pjpeg' => $this->readJpeg($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp', 'image/x-webp' => $this->readIfSupported($path, 'imagecreatefromwebp', 'WebP'),
            'image/avif', 'image/heif' => $this->readIfSupported($path, 'imagecreatefromavif', 'AVIF'),
            'image/bmp', 'image/ms-bmp', 'image/x-bitmap', 'image/x-bmp', 'image/x-ms-bmp', 'image/x-win-bitmap', 'image/x-windows-bmp', 'image/x-xbitmap' => $this->readIfSupported($path, 'imagecreatefrombmp', 'BMP'),
            default => throw new \RuntimeException(sprintf('Unsupported image type %s. GD driver can decode JPG, PNG, GIF, BMP, WebP and AVIF files.', $mime !== '' ? $mime : 'unknown')),
        };

        if (!$this->isGdCore($core)) {
            throw new \RuntimeException(sprintf('Unable to decode image from file (%s).', $path));
        }

        $this->ensureTrueColor($core);

        $image = new Image($this, $core);
        $image->mime = $mime !== '' ? $mime : image_mime('png');
        $image->setFileInfoFromPath($path);

        return $image;
    }

    public function initFromBinary(string $binary): Image
    {
        $core = @imagecreatefromstring($binary);

        if (!$this->isGdCore($core)) {
            throw new \RuntimeException('Unable to init GD image from given binary data.');
        }

        $this->ensureTrueColor($core);

        $image = new Image($this, $core);
        $mime = @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $binary);
        $image->mime = is_string($mime) && $mime !== '' ? strtolower($mime) : image_mime('png');

        return $image;
    }

    public function width(Image $image): int
    {
        return imagesx($image->getCore());
    }

    public function height(Image $image): int
    {
        return imagesy($image->getCore());
    }

    public function resize(Image $image, ?int $width, ?int $height, bool $keepAspect = false, bool $preventUpsize = false): void
    {
        $target = resize_image_dimensions($this->width($image), $this->height($image), $width, $height, $keepAspect, $preventUpsize);

        $this->copyResampled($image, 0, 0, 0, 0, $target['width'], $target['height'], $this->width($image), $this->height($image));
    }

    public function fit(Image $image, int $width, ?int $height = null, bool $preventUpsize = false, string $position = 'center'): void
    {
        $height = $height ?? $width;
        $crop = fit_image_crop($this->width($image), $this->height($image), $width, $height, $position);
        $target = resize_image_dimensions($crop['width'], $crop['height'], $width, $height, true, $preventUpsize);

        $this->copyResampled(
            $image,
            0,
            0,
            $crop['x'],
            $crop['y'],
            $target['width'],
            $target['height'],
            $crop['width'],
            $crop['height']
        );
    }

    public function resizeCanvas(Image $image, int $width, int $height, string $anchor = 'center', bool $relative = false, $background = null): void
    {
        $originalWidth = $this->width($image);
        $originalHeight = $this->height($image);

        if ($relative) {
            $width += $originalWidth;
            $height += $originalHeight;
        }

        $width = $width <= 0 ? $width + $originalWidth : $width;
        $height = $height <= 0 ? $height + $originalHeight : $height;
        $width = max(1, $width);
        $height = max(1, $height);

        $copyWidth = min($originalWidth, $width);
        $copyHeight = min($originalHeight, $height);
        [$srcX, $srcY] = $width < $originalWidth || $height < $originalHeight
            ? image_anchor_offset($originalWidth, $originalHeight, $copyWidth, $copyHeight, $anchor)
            : [0, 0];
        [$dstX, $dstY] = $width > $originalWidth || $height > $originalHeight
            ? image_anchor_offset($width, $height, $copyWidth, $copyHeight, $anchor)
            : [0, 0];

        $canvas = $this->createCanvas($width, $height, $background);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, $dstX, $dstY, $dstX + $copyWidth - 1, $dstY + $copyHeight - 1, $transparent);
        imagecopy($canvas, $image->getCore(), $dstX, $dstY, $srcX, $srcY, $copyWidth, $copyHeight);
        imagesavealpha($canvas, true);

        $image->setCore($canvas);
    }

    public function pickColor(Image $image, int $x, int $y, string $format = 'array')
    {
        $core = $image->getCore();

        if ($x < 0 || $y < 0 || $x >= imagesx($core) || $y >= imagesy($core)) {
            throw new \InvalidArgumentException('Color pick coordinates are outside the image canvas.');
        }

        $color = imagecolorat($core, $x, $y);

        if (!imageistruecolor($core)) {
            $rgba = imagecolorsforindex($core, $color);
            $rgba = [
                (int) ($rgba['red'] ?? 0),
                (int) ($rgba['green'] ?? 0),
                (int) ($rgba['blue'] ?? 0),
                round(1 - ((int) ($rgba['alpha'] ?? 0) / 127), 2),
            ];
        } else {
            $rgba = gd_image_rgba($color);
        }

        return format_image_color($rgba, $format);
    }

    public function insert(Image $image, $source, string $position = 'top-left', int $x = 0, int $y = 0): void
    {
        $watermark = $this->init($source);
        [$dstX, $dstY] = image_anchor_offset(
            $this->width($image),
            $this->height($image),
            $this->width($watermark),
            $this->height($watermark),
            $position,
            $x,
            $y
        );

        imagealphablending($image->getCore(), true);
        imagecopy($image->getCore(), $watermark->getCore(), $dstX, $dstY, 0, 0, $this->width($watermark), $this->height($watermark));
        imagesavealpha($image->getCore(), true);
    }

    public function rotate(Image $image, float $angle, $background = null): void
    {
        $angle = fmod($angle, 360);
        $rgba = normalize_image_color($background);
        $rotated = imagerotate($image->getCore(), $angle, gd_image_int($rgba));

        if (!$this->isGdCore($rotated)) {
            throw new \RuntimeException('Unable to rotate GD image.');
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        $image->setCore($rotated);
    }

    public function opacity(Image $image, int $transparency): void
    {
        $transparency = max(0, min(100, $transparency));
        $factor = $transparency / 100;

        if ($factor >= 1) {
            return;
        }

        $core = $image->getCore();
        $width = imagesx($core);
        $height = imagesy($core);

        imagealphablending($core, false);
        imagesavealpha($core, true);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgba = gd_image_rgba(imagecolorat($core, $x, $y));
                $rgba[3] = round($rgba[3] * $factor, 2);
                imagesetpixel($core, $x, $y, gd_image_int($rgba));
            }
        }
    }

    public function text(Image $image, string $text, int $x = 0, int $y = 0, array $options = []): void
    {
        $options = normalize_text_options($options);
        $rgba = normalize_image_color($options['color']);
        $core = $image->getCore();

        if (is_string($options['file']) && $options['file'] !== '' && is_file($options['file'])) {
            [$drawX, $drawY] = $this->resolveTtfPosition($text, $x, $y, $options);

            imagealphablending($core, true);
            imagettftext(
                $core,
                $this->ttfPointSize($options['size']),
                $options['angle'],
                $drawX,
                $drawY,
                imagecolorallocatealpha($core, $rgba[0], $rgba[1], $rgba[2], gd_image_alpha($rgba[3])),
                $options['file'],
                $this->prepareTtfText($text)
            );

            return;
        }

        $font = $this->internalFont($options['file']);
        [$width, $height] = $this->internalBox($text, $font);
        [$drawX, $drawY] = $this->resolveInternalPosition($x, $y, $width, $height, $font, (string) ($options['align'] ?? ''), (string) ($options['valign'] ?? ''));

        imagestring(
            $core,
            $font,
            $drawX,
            $drawY,
            $text,
            imagecolorallocatealpha($core, $rgba[0], $rgba[1], $rgba[2], gd_image_alpha($rgba[3]))
        );
    }

    public function measureText(string $text, array $options = []): array
    {
        $options = normalize_text_options($options);

        if ($text === '') {
            return ['width' => 0, 'height' => 0];
        }

        if (is_string($options['file']) && $options['file'] !== '' && is_file($options['file'])) {
            $box = imagettfbbox($this->ttfPointSize($options['size']), 0, $options['file'], $this->prepareTtfText($text));

            if (!is_array($box)) {
                throw new \RuntimeException('Unable to calculate TTF text bounding box.');
            }

            return [
                'width' => (int) abs($box[4] - $box[0]),
                'height' => (int) abs($box[5] - $box[1]),
            ];
        }

        [$width, $height] = $this->internalBox($text, $this->internalFont($options['file']));

        return ['width' => $width, 'height' => $height];
    }

    public function encode(Image $image, ?string $format = null, int $quality = 90): string
    {
        return match (normalize_image_format($format, $image->mime)) {
            'data-url' => $this->encodeDataUrl($image, $quality),
            'gif' => $this->encodeGif($image),
            'png' => $this->encodePng($image),
            'jpg' => $this->encodeJpeg($image, $quality),
            'webp' => $this->encodeWebp($image, $quality),
            'avif' => $this->encodeAvif($image, $quality),
            default => throw new \RuntimeException(sprintf('Encoding format (%s) is not supported by GD driver.', normalize_image_format($format, $image->mime))),
        };
    }

    private function encodeDataUrl(Image $image, int $quality): string
    {
        $mime = $image->mime();

        return 'data:' . $mime . ';base64,' . base64_encode($this->encode($image, $mime, $quality));
    }

    public function flip(Image $image, string $mode = 'h'): void
    {
        $width = $this->width($image);
        $height = $this->height($image);

        if (in_array(strtolower($mode), ['2', 'v', 'vert', 'vertical'], true)) {
            $this->copyResampled($image, 0, 0, 0, $height - 1, $width, $height, $width, -$height);
            return;
        }

        $this->copyResampled($image, 0, 0, $width - 1, 0, $width, $height, -$width, $height);
    }

    public function exif(Image $image, ?string $key = null)
    {
        if (!function_exists('exif_read_data')) {
            return false;
        }

        $path = $image->basePath();

        if ($path === null || !is_file($path)) {
            return false;
        }

        try {
            $data = @exif_read_data($path);
        } catch (\Throwable $exception) {
            return false;
        }

        if (!is_array($data)) {
            return false;
        }

        return $key !== null ? ($data[$key] ?? false) : $data;
    }

    public function cloneCore($core)
    {
        if (!$this->isGdCore($core)) {
            return parent::cloneCore($core);
        }

        $width = imagesx($core);
        $height = imagesy($core);
        $clone = $this->createCanvas($width, $height, [255, 255, 255, 0]);
        imagecopy($clone, $core, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    private function readJpeg(string $path)
    {
        $core = @imagecreatefromjpeg($path);

        if ($this->isGdCore($core)) {
            return $core;
        }

        $binary = @file_get_contents($path);

        return is_string($binary) ? @imagecreatefromstring($binary) : false;
    }

    private function readIfSupported(string $path, string $function, string $format)
    {
        if (!function_exists($function)) {
            throw new \RuntimeException(sprintf('%s format is not supported by this GD installation.', $format));
        }

        return @$function($path);
    }

    private function ensureTrueColor(&$core): void
    {
        if (!$this->isGdCore($core)) {
            return;
        }

        if (function_exists('imageistruecolor') && imageistruecolor($core)) {
            imagealphablending($core, false);
            imagesavealpha($core, true);
            return;
        }

        $width = imagesx($core);
        $height = imagesy($core);
        $canvas = $this->createCanvas($width, $height, [255, 255, 255, 0]);
        imagecopy($canvas, $core, 0, 0, 0, 0, $width, $height);
        $core = $canvas;
    }

    private function copyResampled(Image $image, int $dstX, int $dstY, int $srcX, int $srcY, int $dstWidth, int $dstHeight, int $srcWidth, int $srcHeight): void
    {
        $modified = $this->createCanvas($dstWidth, $dstHeight, [255, 255, 255, 0]);

        imagecopyresampled(
            $modified,
            $image->getCore(),
            $dstX,
            $dstY,
            $srcX,
            $srcY,
            $dstWidth,
            $dstHeight,
            $srcWidth,
            $srcHeight
        );

        $image->setCore($modified);
    }

    private function createCanvas(int $width, int $height, $background)
    {
        $core = imagecreatetruecolor(max(1, $width), max(1, $height));

        if (!$this->isGdCore($core)) {
            throw new \RuntimeException('Unable to create GD canvas.');
        }

        imagealphablending($core, false);
        imagesavealpha($core, true);

        $rgba = normalize_image_color($background);
        $fill = imagecolorallocatealpha($core, $rgba[0], $rgba[1], $rgba[2], gd_image_alpha($rgba[3]));
        imagefilledrectangle($core, 0, 0, max(1, $width), max(1, $height), $fill);

        return $core;
    }

    private function encodeJpeg(Image $image, int $quality): string
    {
        ob_start();
        imagejpeg($image->getCore(), null, $quality);
        $buffer = (string) ob_get_clean();
        $image->mime = image_mime('jpg');

        return $buffer;
    }

    private function encodePng(Image $image): string
    {
        $core = $image->getCore();
        imagealphablending($core, false);
        imagesavealpha($core, true);

        ob_start();
        imagepng($core);
        $buffer = (string) ob_get_clean();
        $image->mime = image_mime('png');

        return $buffer;
    }

    private function encodeGif(Image $image): string
    {
        ob_start();
        imagegif($image->getCore());
        $buffer = (string) ob_get_clean();
        $image->mime = image_mime('gif');

        return $buffer;
    }

    private function encodeWebp(Image $image, int $quality): string
    {
        if (!function_exists('imagewebp')) {
            throw new \RuntimeException('WebP format is not supported by this GD installation.');
        }

        $core = $image->getCore();

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($core);
        }

        imagealphablending($core, true);
        imagesavealpha($core, true);

        ob_start();
        imagewebp($core, null, $quality);
        $buffer = (string) ob_get_clean();
        $image->mime = image_mime('webp');

        return $buffer;
    }

    private function encodeAvif(Image $image, int $quality): string
    {
        if (!function_exists('imageavif')) {
            throw new \RuntimeException('AVIF format is not supported by this GD installation.');
        }

        $core = $image->getCore();

        if (function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($core);
        }

        imagealphablending($core, true);
        imagesavealpha($core, true);

        ob_start();
        imageavif($core, null, $quality);
        $buffer = (string) ob_get_clean();
        $image->mime = image_mime('avif');

        return $buffer;
    }

    private function ttfPointSize(int $size): int
    {
        return (int) ceil($size * 0.75);
    }

    private function prepareTtfText(string $text): string
    {
        $text = preg_replace('/&(#(?:x[a-fA-F0-9]+|[0-9]+);)/', '&#38;\1', $text) ?? $text;

        if (function_exists('mb_encode_numericentity')) {
            $encoded = mb_encode_numericentity($text, [0x0080, 0xffff, 0, 0xffff], 'UTF-8');

            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return $text;
    }

    private function resolveTtfPosition(string $text, int $x, int $y, array $options): array
    {
        $box = imagettfbbox($this->ttfPointSize($options['size']), 0, $options['file'], $this->prepareTtfText($text));

        if (!is_array($box)) {
            throw new \RuntimeException('Unable to calculate TTF text bounding box.');
        }

        if ((int) $options['angle'] !== 0) {
            $angle = pi() * 2 - ((int) $options['angle'] * pi() * 2 / 360);

            for ($i = 0; $i < 4; $i++) {
                $pointX = $box[$i * 2];
                $pointY = $box[$i * 2 + 1];
                $box[$i * 2] = cos($angle) * $pointX - sin($angle) * $pointY;
                $box[$i * 2 + 1] = sin($angle) * $pointX + cos($angle) * $pointY;
            }
        }

        $align = strtolower((string) ($options['align'] ?? 'left'));
        $valign = strtolower((string) ($options['valign'] ?? 'bottom'));

        switch ($align . '-' . $valign) {
            case 'center-top':
                $x -= (int) round(($box[6] + $box[4]) / 2);
                $y -= (int) round(($box[7] + $box[5]) / 2);
                break;

            case 'right-top':
                $x -= (int) $box[4];
                $y -= (int) $box[5];
                break;

            case 'left-top':
                $x -= (int) $box[6];
                $y -= (int) $box[7];
                break;

            case 'center-center':
            case 'center-middle':
                $x -= (int) round(($box[0] + $box[4]) / 2);
                $y -= (int) round(($box[1] + $box[5]) / 2);
                break;

            case 'right-center':
            case 'right-middle':
                $x -= (int) round(($box[2] + $box[4]) / 2);
                $y -= (int) round(($box[3] + $box[5]) / 2);
                break;

            case 'left-center':
            case 'left-middle':
                $x -= (int) round(($box[0] + $box[6]) / 2);
                $y -= (int) round(($box[1] + $box[7]) / 2);
                break;

            case 'center-bottom':
                $x -= (int) round(($box[0] + $box[2]) / 2);
                $y -= (int) round(($box[1] + $box[3]) / 2);
                break;

            case 'right-bottom':
                $x -= (int) $box[2];
                $y -= (int) $box[3];
                break;

            case 'left-bottom':
                $x -= (int) $box[0];
                $y -= (int) $box[1];
                break;
        }

        return [$x, $y];
    }

    private function internalFont($file): int
    {
        $font = $file === null ? 1 : $file;
        $font = is_numeric($font) ? (int) $font : 1;

        if (!in_array($font, [1, 2, 3, 4, 5], true)) {
            throw new \RuntimeException(sprintf('Internal GD font (%s) is not available. Use only 1-5.', (string) $font));
        }

        return $font;
    }

    private function internalBox(string $text, int $font): array
    {
        $width = $font + 4;
        $height = match ($font) {
            1 => 8,
            2, 3 => 14,
            default => 16,
        };

        return [strlen($text) * $width, $height];
    }

    private function resolveInternalPosition(int $x, int $y, int $width, int $height, int $font, string $align, string $valign): array
    {
        $align = strtolower($align);
        $valign = strtolower($valign);

        if ($align === 'center') {
            $x = (int) ceil($x - ($width / 2));
        } elseif ($align === 'right') {
            $x = (int) ceil($x - $width) + 1;
        }

        if ($font === 1) {
            $topCorrection = 1;
            $bottomCorrection = 2;
        } elseif ($font === 3) {
            $topCorrection = 2;
            $bottomCorrection = 4;
        } else {
            $topCorrection = 3;
            $bottomCorrection = 4;
        }

        switch ($valign) {
            case 'top':
                $y += $topCorrection;
                break;

            case 'center':
            case 'middle':
                $y = (int) round($y - ($height / 2)) + $topCorrection;
                break;

            default:
                $y = (int) round($y - $height) + $bottomCorrection;
                break;
        }

        return [$x, $y];
    }

    private function isGdCore($value): bool
    {
        if (class_exists('GdImage', false) && $value instanceof \GdImage) {
            return true;
        }

        return is_resource($value) && get_resource_type($value) === 'gd';
    }
}
