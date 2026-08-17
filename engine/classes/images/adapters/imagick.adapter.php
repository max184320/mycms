<?php

declare(strict_types=1);

namespace DleImages;

use function DleImages\fit_image_crop;
use function DleImages\format_image_color;
use function DleImages\image_anchor_offset;
use function DleImages\image_mime;
use function DleImages\imagick_image_pixel;
use function DleImages\normalize_image_color;
use function DleImages\normalize_image_format;
use function DleImages\normalize_text_options;
use function DleImages\resize_image_dimensions;

final class ImagickDriver extends Driver
{
    protected function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    public function name(): string
    {
        return 'imagick';
    }

    public function newImage(int $width, int $height, $background = null): Image
    {
        $core = new \Imagick();
        $core->newImage(max(1, $width), max(1, $height), imagick_image_pixel(normalize_image_color($background)), 'png');
        $core->setType(\Imagick::IMGTYPE_UNDEFINED);
        $core->setImageType(\Imagick::IMGTYPE_UNDEFINED);
        $core->setColorspace(\Imagick::COLORSPACE_UNDEFINED);

        $image = new Image($this, $core);
        $image->mime = image_mime('png');

        return $image;
    }

    public function initFromPath(string $path): Image
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('Unable to find file (%s).', $path));
        }

        $core = new \Imagick();

        try {
            $core->setBackgroundColor(new \ImagickPixel('transparent'));
            $core->readImage($path);
            $core = $this->removeAnimation($core);
            $core->setImageOrientation(\Imagick::ORIENTATION_UNDEFINED);
        } catch (\ImagickException $exception) {
            throw new \RuntimeException(sprintf('Unable to read image from path (%s).', $path), 0, $exception);
        }

        $image = new Image($this, $core);
        $image->setFileInfoFromPath($path);
        $image->mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) ?: $core->getImageMimeType();

        return $image;
    }

    public function initFromBinary(string $binary): Image
    {
        $core = new \Imagick();

        try {
            $core->setBackgroundColor(new \ImagickPixel('transparent'));
            $core->readImageBlob($binary);
            $core = $this->removeAnimation($core);
            $core->setImageOrientation(\Imagick::ORIENTATION_UNDEFINED);
        } catch (\ImagickException $exception) {
            throw new \RuntimeException('Unable to read image from binary data.', 0, $exception);
        }

        $image = new Image($this, $core);
        $mime = @finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $binary);
        $image->mime = is_string($mime) && $mime !== '' ? strtolower($mime) : image_mime('png');

        return $image;
    }

    public function width(Image $image): int
    {
        return $image->getCore()->getImageWidth();
    }

    public function height(Image $image): int
    {
        return $image->getCore()->getImageHeight();
    }

    public function resize(Image $image, ?int $width, ?int $height, bool $keepAspect = false, bool $preventUpsize = false): void
    {
        $target = resize_image_dimensions($this->width($image), $this->height($image), $width, $height, $keepAspect, $preventUpsize);
        $image->getCore()->scaleImage($target['width'], $target['height']);
    }

    public function fit(Image $image, int $width, ?int $height = null, bool $preventUpsize = false, string $position = 'center'): void
    {
        $height = $height ?? $width;
        $crop = fit_image_crop($this->width($image), $this->height($image), $width, $height, $position);
        $target = resize_image_dimensions($crop['width'], $crop['height'], $width, $height, true, $preventUpsize);

        $image->getCore()->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
        $image->getCore()->scaleImage($target['width'], $target['height']);
        $image->getCore()->setImagePage(0, 0, 0, 0);
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

        $canvas = $this->newImage($width, $height, $background);
        $draw = new \ImagickDraw();
        $maskColor = $canvas->pickColor(0, 0, 'hex');
        $maskColor = $maskColor === '#ff0000' ? '#00ff00' : '#ff0000';
        $draw->setFillColor($maskColor);
        $draw->rectangle($dstX, $dstY, $dstX + $copyWidth - 1, $dstY + $copyHeight - 1);
        $canvas->getCore()->drawImage($draw);
        $canvas->getCore()->transparentPaintImage($maskColor, 0, 0, false);
        $canvas->getCore()->setImageColorspace($image->getCore()->getImageColorspace());

        $work = clone $image->getCore();
        $work->cropImage($copyWidth, $copyHeight, $srcX, $srcY);
        $canvas->getCore()->compositeImage($work, \Imagick::COMPOSITE_DEFAULT, $dstX, $dstY);
        $canvas->getCore()->setImagePage(0, 0, 0, 0);

        $image->setCore($canvas->getCore());
    }

    public function pickColor(Image $image, int $x, int $y, string $format = 'array')
    {
        if ($x < 0 || $y < 0 || $x >= $this->width($image) || $y >= $this->height($image)) {
            throw new \InvalidArgumentException('Color pick coordinates are outside the image canvas.');
        }

        $rgba = normalize_image_color($image->getCore()->getImagePixelColor($x, $y));

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

        $image->getCore()->compositeImage($watermark->getCore(), \Imagick::COMPOSITE_DEFAULT, $dstX, $dstY);
    }

    public function rotate(Image $image, float $angle, $background = null): void
    {
        $image->getCore()->rotateImage(imagick_image_pixel(normalize_image_color($background)), fmod($angle, 360) * -1);
    }

    public function opacity(Image $image, int $transparency): void
    {
        $transparency = max(0, min(100, $transparency));
        $divisor = $transparency > 0 ? (100 / $transparency) : 1000;
        $image->getCore()->evaluateImage(\Imagick::EVALUATE_DIVIDE, $divisor, \Imagick::CHANNEL_ALPHA);
    }

    public function text(Image $image, string $text, int $x = 0, int $y = 0, array $options = []): void
    {
        $options = normalize_text_options($options);

        if (!is_string($options['file']) || $options['file'] === '' || !is_file($options['file'])) {
            throw new \RuntimeException('Font file must be provided to apply text to image.');
        }

        $draw = new \ImagickDraw();
        $draw->setStrokeAntialias(true);
        $draw->setTextAntialias(true);
        $draw->setFont($options['file']);
        $draw->setFontSize($options['size']);
        $draw->setFillColor(imagick_image_pixel(normalize_image_color($options['color'])));

        if (method_exists($draw, 'setTextKerning')) {
            $draw->setTextKerning($options['kerning']);
        }

        $draw->setTextAlignment(match (strtolower((string) ($options['align'] ?? ''))) {
            'center' => \Imagick::ALIGN_CENTER,
            'right' => \Imagick::ALIGN_RIGHT,
            default => \Imagick::ALIGN_LEFT,
        });

        if (strtolower((string) ($options['valign'] ?? 'bottom')) !== 'bottom') {
            switch (strtolower((string) $options['valign'])) {
                case 'center':
                case 'middle':
                    $dimensions = $image->getCore()->queryFontMetrics($draw, $text);
                    $y += (int) round(($dimensions['textHeight'] ?? 0) * 0.65 / 2);
                    break;

                case 'top':
                    $dimensions = $image->getCore()->queryFontMetrics($draw, $text, false);
                    $y += (int) round($dimensions['characterHeight'] ?? 0);
                    break;
            }
        }

        $image->getCore()->annotateImage($draw, $x, $y, ((int) $options['angle']) * -1, $text);
    }

    public function measureText(string $text, array $options = []): array
    {
        $options = normalize_text_options($options);

        if ($text === '') {
            return ['width' => 0, 'height' => 0];
        }

        if (!is_string($options['file']) || $options['file'] === '' || !is_file($options['file'])) {
            throw new \RuntimeException('Font file must be provided to calculate text size.');
        }

        $draw = new \ImagickDraw();
        $draw->setStrokeAntialias(true);
        $draw->setTextAntialias(true);
        $draw->setFont($options['file']);
        $draw->setFontSize($options['size']);

        if (method_exists($draw, 'setTextKerning')) {
            $draw->setTextKerning($options['kerning']);
        }

        $dimensions = (new \Imagick())->queryFontMetrics($draw, $text);

        return [
            'width' => (int) abs($dimensions['textWidth'] ?? 0),
            'height' => (int) abs($dimensions['textHeight'] ?? 0),
        ];
    }

    public function encode(Image $image, ?string $format = null, int $quality = 90): string
    {
        $normalized = normalize_image_format($format, $image->mime);
        $core = clone $image->getCore();

        return match ($normalized) {
            'data-url' => $this->encodeDataUrl($image, $quality),
            'gif' => $this->encodeGif($image, $core),
            'png' => $this->encodePng($image, $core),
            'jpg' => $this->encodeJpeg($image, $core, $quality),
            'webp' => $this->encodeWebp($image, $core, $quality),
            'avif' => $this->encodeAvif($image, $core, $quality),
            'heic' => $this->encodeHeic($image, $core, $quality),
            default => throw new \RuntimeException(sprintf('Encoding format (%s) is not supported by Imagick driver.', $normalized)),
        };
    }

    private function encodeDataUrl(Image $image, int $quality): string
    {
        $mime = $image->mime();

        return 'data:' . $mime . ';base64,' . base64_encode($this->encode($image, $mime, $quality));
    }

    public function flip(Image $image, string $mode = 'h'): void
    {
        if (in_array(strtolower($mode), ['2', 'v', 'vert', 'vertical'], true)) {
            $image->getCore()->flipImage();
            return;
        }

        $image->getCore()->flopImage();
    }

    public function exif(Image $image, ?string $key = null)
    {
        $path = $image->basePath();

        if ($path !== null && is_file($path) && function_exists('exif_read_data')) {
            try {
                $data = @exif_read_data($path);

                if (is_array($data)) {
                    return $key !== null ? ($data[$key] ?? false) : $data;
                }
            } catch (\Throwable $exception) {
            }
        }

        $core = $image->getCore();

        if ($key !== null) {
            if (strcasecmp($key, 'Orientation') === 0) {
                try {
                    return $core->getImageOrientation();
                } catch (\Throwable $exception) {
                    return false;
                }
            }

            return $core->getImageProperty('exif:' . $key);
        }

        $result = [];

        foreach ($core->getImageProperties('exif:*') as $property => $value) {
            if (strpos($property, 'exif:') === 0) {
                $result[substr($property, 5)] = $value;
            }
        }

        if ($result === []) {
            try {
                $result['Orientation'] = $core->getImageOrientation();
            } catch (\Throwable $exception) {
            }
        }

        return $result;
    }

    private function encodeJpeg(Image $image, \Imagick $core, int $quality): string
    {
        $core->setImageBackgroundColor('white');
        $core->setBackgroundColor('white');
        $core = $core->mergeImageLayers(\Imagick::LAYERMETHOD_MERGE);
        $core->setFormat('jpeg');
        $core->setImageFormat('jpeg');
        $core->setCompression(\Imagick::COMPRESSION_JPEG);
        $core->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $core->setCompressionQuality($quality);
        $core->setImageCompressionQuality($quality);
        $image->mime = image_mime('jpg');

        return (string) $core->getImagesBlob();
    }

    private function encodePng(Image $image, \Imagick $core): string
    {
        $core->setFormat('png');
        $core->setImageFormat('png');
        $core->setCompression(\Imagick::COMPRESSION_ZIP);
        $core->setImageCompression(\Imagick::COMPRESSION_ZIP);
        $image->mime = image_mime('png');

        return (string) $core->getImagesBlob();
    }

    private function encodeGif(Image $image, \Imagick $core): string
    {
        $core->setFormat('gif');
        $core->setImageFormat('gif');
        $core->setCompression(\Imagick::COMPRESSION_LZW);
        $core->setImageCompression(\Imagick::COMPRESSION_LZW);
        $image->mime = image_mime('gif');

        return (string) $core->getImagesBlob();
    }

    private function encodeWebp(Image $image, \Imagick $core, int $quality): string
    {
        if (!\Imagick::queryFormats('WEBP')) {
            throw new \RuntimeException('WebP format is not supported by this Imagick installation.');
        }

        $core->setImageBackgroundColor(new \ImagickPixel('transparent'));
        $core = $core->mergeImageLayers(\Imagick::LAYERMETHOD_MERGE);
        $core->setFormat('webp');
        $core->setImageFormat('webp');
        $core->setCompression(\Imagick::COMPRESSION_JPEG);
        $core->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $core->setCompressionQuality($quality);
        $core->setImageCompressionQuality($quality);
        $image->mime = image_mime('webp');

        return (string) $core->getImagesBlob();
    }

    private function encodeAvif(Image $image, \Imagick $core, int $quality): string
    {
        if (!\Imagick::queryFormats('AVIF')) {
            throw new \RuntimeException('AVIF format is not supported by this Imagick installation.');
        }

        $core->setFormat('avif');
        $core->setImageFormat('avif');
        $core->setCompression(\Imagick::COMPRESSION_UNDEFINED);
        $core->setImageCompression(\Imagick::COMPRESSION_UNDEFINED);
        $core->setCompressionQuality($quality);
        $core->setImageCompressionQuality($quality);
        $image->mime = image_mime('avif');

        return (string) $core->getImagesBlob();
    }

    private function encodeHeic(Image $image, \Imagick $core, int $quality): string
    {
        if (!\Imagick::queryFormats('HEIC')) {
            throw new \RuntimeException('HEIC format is not supported by this Imagick installation.');
        }

        $core->setFormat('heic');
        $core->setImageFormat('heic');
        $core->setCompression(\Imagick::COMPRESSION_UNDEFINED);
        $core->setImageCompression(\Imagick::COMPRESSION_UNDEFINED);
        $core->setCompressionQuality($quality);
        $core->setImageCompressionQuality($quality);
        $image->mime = image_mime('heic');

        return (string) $core->getImagesBlob();
    }

    private function removeAnimation(\Imagick $object): \Imagick
    {
        $imagick = new \Imagick();

        foreach ($object as $frame) {
            $imagick->addImage($frame->getImage());
            break;
        }

        $object->clear();

        return $imagick;
    }
}
