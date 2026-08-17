<?php
declare(strict_types=1);

namespace RobThree\Auth;

class TwoFactorAuth
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const SUPPORTED_ALGORITHMS = [
        'sha1' => true,
        'sha256' => true,
        'sha512' => true,
        'md5' => true,
    ];

    private const QR_QUIET_ZONE = 4;
    private const QR_MODE_BYTE = 0b0100;
    private const QR_EC_LEVEL_BITS = 0x01; // L
    private const QR_TYPE_INFO_POLY = 0x537;
    private const QR_TYPE_INFO_MASK = 0x5412;
    private const QR_VERSION_INFO_POLY = 0x1F25;
    private const QR_RULE_N1 = 3;
    private const QR_RULE_N2 = 3;
    private const QR_RULE_N3 = 40;
    private const QR_RULE_N4 = 10;

    private const QR_TYPE_INFO_COORDINATES = [
        [8, 0],
        [8, 1],
        [8, 2],
        [8, 3],
        [8, 4],
        [8, 5],
        [8, 7],
        [8, 8],
        [7, 8],
        [5, 8],
        [4, 8],
        [3, 8],
        [2, 8],
        [1, 8],
        [0, 8],
    ];

    // Enough for typical otpauth payloads with email + issuer without dragging the full vendor matrix tables.
    private const QR_VERSIONS = [
        8 => [
            'dimension' => 49,
            'alignment' => [6, 24, 42],
            'data_codewords' => 194,
            'ec_codewords' => 24,
            'blocks' => [[2, 97]],
        ],
        9 => [
            'dimension' => 53,
            'alignment' => [6, 26, 46],
            'data_codewords' => 232,
            'ec_codewords' => 30,
            'blocks' => [[2, 116]],
        ],
        10 => [
            'dimension' => 57,
            'alignment' => [6, 28, 50],
            'data_codewords' => 274,
            'ec_codewords' => 18,
            'blocks' => [[2, 68], [2, 69]],
        ],
        11 => [
            'dimension' => 61,
            'alignment' => [6, 30, 54],
            'data_codewords' => 324,
            'ec_codewords' => 20,
            'blocks' => [[4, 81]],
        ],
        12 => [
            'dimension' => 65,
            'alignment' => [6, 32, 58],
            'data_codewords' => 370,
            'ec_codewords' => 24,
            'blocks' => [[2, 92], [2, 93]],
        ],
    ];

    private static ?array $base32Lookup = null;
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    private string $algorithm;
    private int $period;
    private int $digits;
    private ?string $issuer;

    public function __construct(?string $issuer = null, int $digits = 6, int $period = 30, string $algorithm = 'sha1')
    {
        if ($digits <= 0) {
            throw new \InvalidArgumentException('Digits must be greater than 0');
        }

        if ($period <= 0) {
            throw new \InvalidArgumentException('Period must be greater than 0');
        }

        $algorithm = strtolower(trim($algorithm));

        if (!isset(self::SUPPORTED_ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException('Unsupported algorithm');
        }

        $this->issuer = $issuer;
        $this->digits = $digits;
        $this->period = $period;
        $this->algorithm = $algorithm;

        self::initBase32Lookup();
        self::initGaloisField();
    }

    public function createSecret(int $bits = 80, bool $requirecryptosecure = true): string
    {
        if ($bits <= 0) {
            throw new \InvalidArgumentException('Bits must be greater than 0');
        }

        if ($requirecryptosecure !== false && !function_exists('random_bytes')) {
            throw new \RuntimeException('random_bytes is not available');
        }

        $length = (int) ceil($bits / 5);
        $random = random_bytes($length);
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[ord($random[$i]) & 31];
        }

        return $secret;
    }

    public function getCode(string $secret, ?int $time = null): string
    {
        return $this->calculateCode(self::base32Decode($secret), $time ?? time());
    }

    public function getQRCodeImageAsDataUri(string $label, string $secret, int $size = 200): string
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException('Size must be greater than 0');
        }

        $svg = self::renderQrSvg($this->buildQrText($label, $secret), $size);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function verifyCode(string $secret, string $code, int $discrepancy = 1, ?int $time = null, &$timeslice = 0): bool
    {
        $timeslice = 0;
        $timestamp = $time ?? time();
        $discrepancy = max(0, $discrepancy);

        try {
            $secretKey = self::base32Decode($secret);
        } catch (\Throwable $e) {
            return false;
        }

        $verified = false;

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $ts = $timestamp + ($i * $this->period);
            $slice = self::getTimeSlice($ts, $this->period);
            $expected = $this->calculateCode($secretKey, $ts);
            $matched = self::timingSafeEquals($expected, $code);
            $timeslice = $matched ? $slice : $timeslice;
            $verified = $verified || $matched;
        }

        return $verified;
    }

    private function calculateCode(string $secretKey, int $time): string
    {
        $slice = self::getTimeSlice($time, $this->period);
        $timestamp = pack('N2', 0, $slice);
        $hash = hash_hmac($this->algorithm, $timestamp, $secretKey, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $value = unpack('N', $part);
        $code = ($value[1] & 0x7FFFFFFF) % (10 ** $this->digits);

        return str_pad((string) $code, $this->digits, '0', STR_PAD_LEFT);
    }

    private function buildQrText(string $label, string $secret): string
    {
        return 'otpauth://totp/' . rawurlencode($label)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode((string) $this->issuer)
            . '&period=' . $this->period
            . '&algorithm=' . rawurlencode(strtoupper($this->algorithm))
            . '&digits=' . $this->digits;
    }

    private static function base32Decode(string $value): string
    {
        self::initBase32Lookup();
        $value = strtoupper(trim($value));

        if ($value === '') {
            return '';
        }

        if (preg_match('/[^A-Z2-7=]/', $value)) {
            throw new \InvalidArgumentException('Invalid base32 string');
        }

        $buffer = '';
        $output = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '=') {
                continue;
            }

            $buffer .= str_pad(decbin(self::$base32Lookup[$char]), 5, '0', STR_PAD_LEFT);

            while (strlen($buffer) >= 8) {
                $output .= chr(bindec(substr($buffer, 0, 8)));
                $buffer = substr($buffer, 8);
            }
        }

        if ($buffer !== '' && trim($buffer, '0') !== '') {
            throw new \InvalidArgumentException('Invalid base32 padding');
        }

        return $output;
    }

    private static function initBase32Lookup(): void
    {
        if (self::$base32Lookup !== null) {
            return;
        }

        self::$base32Lookup = array_flip(str_split(self::BASE32_ALPHABET));
    }

    private static function initGaloisField(): void
    {
        if (self::$gfExp !== null && self::$gfLog !== null) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $value;
            self::$gfLog[$value] = $i;
            $value <<= 1;

            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    private static function timingSafeEquals(string $safe, string $user): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($safe, $user);
        }

        if (strlen($safe) !== strlen($user)) {
            return false;
        }

        $result = 0;
        $length = strlen($safe);

        for ($i = 0; $i < $length; $i++) {
            $result |= ord($safe[$i]) ^ ord($user[$i]);
        }

        return $result === 0;
    }

    private static function getTimeSlice(int $time, int $period): int
    {
        return (int) floor($time / $period);
    }

    private static function renderQrSvg(string $content, int $size): string
    {
        $version = self::chooseQrVersion($content);
        $dataBits = self::codewordsToBits(self::buildQrCodewords($content, $version));
        $baseMatrix = self::buildQrBaseMatrix($version);
        $bestMatrix = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix = self::copyMatrix($baseMatrix);
            self::placeQrData($matrix, $dataBits, $mask);
            self::placeQrFormatInfo($matrix, $mask);
            self::placeQrVersionInfo($matrix, $version);
            $penalty = self::calculateQrPenalty($matrix);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMatrix = $matrix;
            }
        }

        return self::matrixToSvg($bestMatrix, $size);
    }

    private static function chooseQrVersion(string $content): array
    {
        $length = strlen($content);

        foreach (self::QR_VERSIONS as $number => $version) {
            $countBits = $number <= 9 ? 8 : 16;
            $requiredBits = 4 + $countBits + ($length * 8);

            if ($requiredBits <= ($version['data_codewords'] * 8)) {
                $version['number'] = $number;
                $version['count_bits'] = $countBits;

                return $version;
            }
        }

        throw new \RuntimeException('QR payload is too large');
    }

    private static function buildQrCodewords(string $content, array $version): array
    {
        $bits = [];
        self::appendBits($bits, self::QR_MODE_BYTE, 4);
        self::appendBits($bits, strlen($content), $version['count_bits']);

        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            self::appendBits($bits, ord($content[$i]), 8);
        }

        $capacityBits = $version['data_codewords'] * 8;
        $remainingBits = $capacityBits - count($bits);

        for ($i = 0; $i < min(4, $remainingBits); $i++) {
            $bits[] = 0;
        }

        while ((count($bits) % 8) !== 0) {
            $bits[] = 0;
        }

        $dataBytes = self::bitsToBytes($bits);
        $paddingBytes = [0xEC, 0x11];
        $paddingIndex = 0;

        while (count($dataBytes) < $version['data_codewords']) {
            $dataBytes[] = $paddingBytes[$paddingIndex % 2];
            $paddingIndex++;
        }

        $blocks = [];
        $offset = 0;

        foreach ($version['blocks'] as $group) {
            [$count, $size] = $group;

            for ($i = 0; $i < $count; $i++) {
                $blocks[] = array_slice($dataBytes, $offset, $size);
                $offset += $size;
            }
        }

        $ecBlocks = [];
        $maxDataCount = 0;

        foreach ($blocks as $block) {
            $ecBlocks[] = self::generateEcCodewords($block, $version['ec_codewords']);
            $maxDataCount = max($maxDataCount, count($block));
        }

        $interleaved = [];

        for ($i = 0; $i < $maxDataCount; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $interleaved[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $version['ec_codewords']; $i++) {
            foreach ($ecBlocks as $block) {
                $interleaved[] = $block[$i];
            }
        }

        return $interleaved;
    }

    private static function generateEcCodewords(array $data, int $ecCodewords): array
    {
        self::initGaloisField();
        $generator = [1];

        for ($i = 0; $i < $ecCodewords; $i++) {
            $generator = self::multiplyPolynomials($generator, [1, self::$gfExp[$i]]);
        }

        $remainder = array_fill(0, $ecCodewords, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];

            for ($i = 0; $i < $ecCodewords - 1; $i++) {
                $remainder[$i] = $remainder[$i + 1];
            }

            $remainder[$ecCodewords - 1] = 0;

            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $index => $coefficient) {
                if ($index === 0) {
                    continue;
                }

                $remainder[$index - 1] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    private static function multiplyPolynomials(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);

        foreach ($left as $i => $leftValue) {
            foreach ($right as $j => $rightValue) {
                $result[$i + $j] ^= self::gfMultiply($leftValue, $rightValue);
            }
        }

        return $result;
    }

    private static function gfMultiply(int $left, int $right): int
    {
        self::initGaloisField();

        if ($left === 0 || $right === 0) {
            return 0;
        }

        return self::$gfExp[self::$gfLog[$left] + self::$gfLog[$right]];
    }

    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function bitsToBytes(array $bits): array
    {
        $bytes = [];
        $totalBits = count($bits);

        for ($i = 0; $i < $totalBits; $i += 8) {
            $byte = 0;

            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }

            $bytes[] = $byte;
        }

        return $bytes;
    }

    private static function codewordsToBits(array $codewords): array
    {
        $bits = [];

        foreach ($codewords as $byte) {
            self::appendBits($bits, $byte, 8);
        }

        return $bits;
    }

    private static function buildQrBaseMatrix(array $version): array
    {
        $dimension = $version['dimension'];
        $matrix = array_fill(0, $dimension, array_fill(0, $dimension, null));

        self::placeFinder($matrix, 0, 0);
        self::placeFinder($matrix, $dimension - 7, 0);
        self::placeFinder($matrix, 0, $dimension - 7);
        self::placeAlignmentPatterns($matrix, $version['alignment']);
        self::placeTimingPatterns($matrix);
        $matrix[$dimension - 8][8] = 1;
        self::reserveFormatInfo($matrix);
        self::reserveVersionInfo($matrix, $version['number']);

        return $matrix;
    }

    private static function placeFinder(array &$matrix, int $startX, int $startY): void
    {
        $dimension = count($matrix);

        for ($y = -1; $y <= 7; $y++) {
            for ($x = -1; $x <= 7; $x++) {
                $targetX = $startX + $x;
                $targetY = $startY + $y;

                if ($targetX < 0 || $targetY < 0 || $targetX >= $dimension || $targetY >= $dimension) {
                    continue;
                }

                $isSeparator = $x === -1 || $x === 7 || $y === -1 || $y === 7;
                $isBorder = $x === 0 || $x === 6 || $y === 0 || $y === 6;
                $isCenter = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                $matrix[$targetY][$targetX] = (!$isSeparator && ($isBorder || $isCenter)) ? 1 : 0;
            }
        }
    }

    private static function placeAlignmentPatterns(array &$matrix, array $centers): void
    {
        $count = count($centers);

        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                $centerX = $centers[$x];
                $centerY = $centers[$y];

                if ($centerX === null || $centerY === null) {
                    continue;
                }

                if ($matrix[$centerY][$centerX] !== null) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $targetX = $centerX + $dx;
                        $targetY = $centerY + $dy;
                        $distance = max(abs($dx), abs($dy));
                        $matrix[$targetY][$targetX] = ($distance !== 1) ? 1 : 0;
                    }
                }
            }
        }
    }

    private static function placeTimingPatterns(array &$matrix): void
    {
        $dimension = count($matrix);

        for ($i = 8; $i < $dimension - 8; $i++) {
            $bit = ($i + 1) % 2;

            if ($matrix[6][$i] === null) {
                $matrix[6][$i] = $bit;
            }

            if ($matrix[$i][6] === null) {
                $matrix[$i][6] = $bit;
            }
        }
    }

    private static function reserveFormatInfo(array &$matrix): void
    {
        $dimension = count($matrix);

        foreach (self::QR_TYPE_INFO_COORDINATES as [$x, $y]) {
            $matrix[$y][$x] = 0;
        }

        for ($i = 0; $i < 15; $i++) {
            if ($i < 8) {
                $targetX = $dimension - $i - 1;
                $targetY = 8;
            } else {
                $targetX = 8;
                $targetY = $dimension - 7 + ($i - 8);
            }

            $matrix[$targetY][$targetX] = 0;
        }
    }

    private static function reserveVersionInfo(array &$matrix, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $dimension = count($matrix);

        for ($y = 0; $y < 6; $y++) {
            for ($x = 0; $x < 3; $x++) {
                $matrix[$dimension - 11 + $x][$y] = 0;
                $matrix[$y][$dimension - 11 + $x] = 0;
            }
        }
    }

    private static function placeQrData(array &$matrix, array $bits, int $maskPattern): void
    {
        $dimension = count($matrix);
        $bitIndex = 0;
        $direction = -1;
        $x = $dimension - 1;
        $y = $dimension - 1;
        $totalBits = count($bits);

        while ($x > 0) {
            if ($x === 6) {
                $x--;
            }

            while ($y >= 0 && $y < $dimension) {
                for ($columnOffset = 0; $columnOffset < 2; $columnOffset++) {
                    $targetX = $x - $columnOffset;

                    if ($matrix[$y][$targetX] !== null) {
                        continue;
                    }

                    $bit = $bitIndex < $totalBits ? $bits[$bitIndex] : 0;
                    $bitIndex++;

                    if (self::getMaskBit($maskPattern, $targetX, $y)) {
                        $bit ^= 1;
                    }

                    $matrix[$y][$targetX] = $bit;
                }

                $y += $direction;
            }

            $direction = -$direction;
            $y += $direction;
            $x -= 2;
        }

        if ($bitIndex < $totalBits) {
            throw new \RuntimeException('Not all QR bits were consumed');
        }
    }

    private static function placeQrFormatInfo(array &$matrix, int $maskPattern): void
    {
        $dimension = count($matrix);
        $typeInfo = (self::QR_EC_LEVEL_BITS << 3) | $maskPattern;
        $bits = (($typeInfo << 10) | self::calculateBchCode($typeInfo, self::QR_TYPE_INFO_POLY)) ^ self::QR_TYPE_INFO_MASK;

        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> $i) & 1;
            [$x1, $y1] = self::QR_TYPE_INFO_COORDINATES[$i];
            $matrix[$y1][$x1] = $bit;

            if ($i < 8) {
                $x2 = $dimension - $i - 1;
                $y2 = 8;
            } else {
                $x2 = 8;
                $y2 = $dimension - 7 + ($i - 8);
            }

            $matrix[$y2][$x2] = $bit;
        }
    }

    private static function placeQrVersionInfo(array &$matrix, array $version): void
    {
        if ($version['number'] < 7) {
            return;
        }

        $dimension = count($matrix);
        $bits = ($version['number'] << 12) | self::calculateBchCode($version['number'], self::QR_VERSION_INFO_POLY);
        $bitIndex = 0;

        for ($y = 0; $y < 6; $y++) {
            for ($x = 0; $x < 3; $x++) {
                $bit = ($bits >> $bitIndex) & 1;
                $matrix[$dimension - 11 + $x][$y] = $bit;
                $matrix[$y][$dimension - 11 + $x] = $bit;
                $bitIndex++;
            }
        }
    }

    private static function calculateBchCode(int $value, int $poly): int
    {
        $polyMsb = self::findMsbSet($poly);
        $value <<= $polyMsb - 1;

        while (self::findMsbSet($value) >= $polyMsb) {
            $value ^= $poly << (self::findMsbSet($value) - $polyMsb);
        }

        return $value;
    }

    private static function findMsbSet(int $value): int
    {
        $digits = 0;

        while ($value !== 0) {
            $value >>= 1;
            $digits++;
        }

        return $digits;
    }

    private static function getMaskBit(int $pattern, int $x, int $y): bool
    {
        $product = $x * $y;

        return match ($pattern) {
            0 => (($x + $y) & 1) === 0,
            1 => ($y & 1) === 0,
            2 => ($x % 3) === 0,
            3 => (($x + $y) % 3) === 0,
            4 => ((intdiv($y, 2) + intdiv($x, 3)) & 1) === 0,
            5 => (($product & 1) + ($product % 3)) === 0,
            6 => (((($product & 1) + ($product % 3)) & 1) === 0),
            7 => (((($product % 3) + (($x + $y) & 1)) & 1) === 0),
            default => throw new \InvalidArgumentException('Invalid QR mask pattern'),
        };
    }

    private static function calculateQrPenalty(array $matrix): int
    {
        return self::calculatePenaltyRule1($matrix)
            + self::calculatePenaltyRule2($matrix)
            + self::calculatePenaltyRule3($matrix)
            + self::calculatePenaltyRule4($matrix);
    }

    private static function calculatePenaltyRule1(array $matrix): int
    {
        return self::calculatePenaltyRuns($matrix, true) + self::calculatePenaltyRuns($matrix, false);
    }

    private static function calculatePenaltyRuns(array $matrix, bool $horizontal): int
    {
        $dimension = count($matrix);
        $outerLimit = $horizontal ? $dimension : $dimension;
        $innerLimit = $horizontal ? $dimension : $dimension;
        $penalty = 0;

        for ($outer = 0; $outer < $outerLimit; $outer++) {
            $previous = null;
            $runLength = 0;

            for ($inner = 0; $inner < $innerLimit; $inner++) {
                $bit = $horizontal ? $matrix[$outer][$inner] : $matrix[$inner][$outer];

                if ($bit === $previous) {
                    $runLength++;
                    continue;
                }

                if ($runLength >= 5) {
                    $penalty += self::QR_RULE_N1 + ($runLength - 5);
                }

                $previous = $bit;
                $runLength = 1;
            }

            if ($runLength >= 5) {
                $penalty += self::QR_RULE_N1 + ($runLength - 5);
            }
        }

        return $penalty;
    }

    private static function calculatePenaltyRule2(array $matrix): int
    {
        $dimension = count($matrix);
        $penalty = 0;

        for ($y = 0; $y < $dimension - 1; $y++) {
            for ($x = 0; $x < $dimension - 1; $x++) {
                $value = $matrix[$y][$x];

                if ($value === $matrix[$y][$x + 1]
                    && $value === $matrix[$y + 1][$x]
                    && $value === $matrix[$y + 1][$x + 1]
                ) {
                    $penalty++;
                }
            }
        }

        return $penalty * self::QR_RULE_N2;
    }

    private static function calculatePenaltyRule3(array $matrix): int
    {
        $dimension = count($matrix);
        $penalty = 0;

        for ($y = 0; $y < $dimension; $y++) {
            for ($x = 0; $x < $dimension; $x++) {
                if ($x + 6 < $dimension
                    && $matrix[$y][$x] === 1
                    && $matrix[$y][$x + 1] === 0
                    && $matrix[$y][$x + 2] === 1
                    && $matrix[$y][$x + 3] === 1
                    && $matrix[$y][$x + 4] === 1
                    && $matrix[$y][$x + 5] === 0
                    && $matrix[$y][$x + 6] === 1
                    && (
                        ($x + 10 < $dimension
                            && $matrix[$y][$x + 7] === 0
                            && $matrix[$y][$x + 8] === 0
                            && $matrix[$y][$x + 9] === 0
                            && $matrix[$y][$x + 10] === 0)
                        || ($x - 4 >= 0
                            && $matrix[$y][$x - 1] === 0
                            && $matrix[$y][$x - 2] === 0
                            && $matrix[$y][$x - 3] === 0
                            && $matrix[$y][$x - 4] === 0)
                    )
                ) {
                    $penalty += self::QR_RULE_N3;
                }

                if ($y + 6 < $dimension
                    && $matrix[$y][$x] === 1
                    && $matrix[$y + 1][$x] === 0
                    && $matrix[$y + 2][$x] === 1
                    && $matrix[$y + 3][$x] === 1
                    && $matrix[$y + 4][$x] === 1
                    && $matrix[$y + 5][$x] === 0
                    && $matrix[$y + 6][$x] === 1
                    && (
                        ($y + 10 < $dimension
                            && $matrix[$y + 7][$x] === 0
                            && $matrix[$y + 8][$x] === 0
                            && $matrix[$y + 9][$x] === 0
                            && $matrix[$y + 10][$x] === 0)
                        || ($y - 4 >= 0
                            && $matrix[$y - 1][$x] === 0
                            && $matrix[$y - 2][$x] === 0
                            && $matrix[$y - 3][$x] === 0
                            && $matrix[$y - 4][$x] === 0)
                    )
                ) {
                    $penalty += self::QR_RULE_N3;
                }
            }
        }

        return $penalty;
    }

    private static function calculatePenaltyRule4(array $matrix): int
    {
        $dimension = count($matrix);
        $darkModules = 0;

        foreach ($matrix as $row) {
            foreach ($row as $bit) {
                if ($bit === 1) {
                    $darkModules++;
                }
            }
        }

        $totalModules = $dimension * $dimension;
        $variance = (int) (abs(($darkModules / $totalModules) - 0.5) * 20);

        return $variance * self::QR_RULE_N4;
    }

    private static function copyMatrix(array $matrix): array
    {
        $copy = [];

        foreach ($matrix as $row) {
            $copy[] = $row;
        }

        return $copy;
    }

    private static function matrixToSvg(array $matrix, int $size): string
    {
        $dimension = count($matrix);
        $fullSize = $dimension + (self::QR_QUIET_ZONE * 2);
        $path = '';

        for ($y = 0; $y < $dimension; $y++) {
            $x = 0;

            while ($x < $dimension) {
                if ($matrix[$y][$x] !== 1) {
                    $x++;
                    continue;
                }

                $start = $x;

                while ($x < $dimension && $matrix[$y][$x] === 1) {
                    $x++;
                }

                $run = $x - $start;
                $drawX = $start + self::QR_QUIET_ZONE;
                $drawY = $y + self::QR_QUIET_ZONE;
                $path .= 'M' . $drawX . ' ' . $drawY . 'h' . $run . 'v1H' . $drawX . 'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $fullSize . ' ' . $fullSize . '" shape-rendering="crispEdges">'
            . '<rect width="' . $fullSize . '" height="' . $fullSize . '" fill="#fff"/>'
            . '<path fill="#000" d="' . $path . '"/>'
            . '</svg>';
    }
}
