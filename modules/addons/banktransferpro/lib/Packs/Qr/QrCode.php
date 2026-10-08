<?php

declare(strict_types=1);

namespace BankTransferPro\Packs\Qr;

/**
 * Dependency-free QR Code (Model 2) encoder: byte mode, error correction level M,
 * versions 1-15 (up to 415 bytes). Enough for UPI / PayNow / Pix payloads, and it keeps
 * the module independently deployable (no Composer runtime dependency, no JS library).
 *
 * Output is an inline SVG so stock WHMCS themes need no extra assets.
 */
final class QrCode
{
    public const MAX_VERSION = 15;

    /** Level M format-info error-correction bits. */
    private const ECL_FORMAT_BITS = 0;

    /**
     * Level M block layout per version: list of [blockCount, totalCodewordsPerBlock, dataCodewordsPerBlock].
     *
     * @var array<int, list<array{int, int, int}>>
     */
    private const BLOCKS = [
        1 => [[1, 26, 16]],
        2 => [[1, 44, 28]],
        3 => [[1, 70, 44]],
        4 => [[2, 50, 32]],
        5 => [[2, 67, 43]],
        6 => [[4, 43, 27]],
        7 => [[4, 49, 31]],
        8 => [[2, 60, 38], [2, 61, 39]],
        9 => [[3, 58, 36], [2, 59, 37]],
        10 => [[4, 69, 43], [1, 70, 44]],
        11 => [[1, 80, 50], [4, 81, 51]],
        12 => [[6, 58, 36], [2, 59, 37]],
        13 => [[8, 59, 37], [1, 60, 38]],
        14 => [[4, 64, 40], [5, 65, 41]],
        15 => [[5, 65, 41], [5, 66, 42]],
    ];

    /** @var array<int, list<int>> */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
        11 => [6, 30, 54],
        12 => [6, 32, 58],
        13 => [6, 34, 62],
        14 => [6, 26, 46, 66],
        15 => [6, 26, 48, 70],
    ];

    /** @var list<int> */
    private static array $exp = [];

    /** @var list<int> */
    private static array $log = [];

    /**
     * @return list<list<bool>> square module matrix, true = dark
     */
    public static function matrix(string $text): array
    {
        $version = self::pickVersion(strlen($text));
        $codewords = self::interleave(self::dataCodewords($text, $version), $version);
        $size = 17 + 4 * $version;

        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $function = array_fill(0, $size, array_fill(0, $size, false));
        self::drawFunctionPatterns($modules, $function, $version, $size);
        self::placeData($modules, $function, $codewords, $size);

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            self::applyMask($candidate, $function, $mask, $size);
            self::drawFormatBits($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
        }

        self::applyMask($modules, $function, $bestMask, $size);
        self::drawFormatBits($modules, $bestMask, $size);

        return $modules;
    }

    /**
     * Inline SVG, black on white, with a standard 4-module quiet zone.
     */
    public static function svg(string $text, string $label = 'QR code', int $quietZone = 4): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $total = $size + 2 * $quietZone;

        $path = '';
        for ($y = 0; $y < $size; $y++) {
            $x = 0;
            while ($x < $size) {
                if (! $matrix[$y][$x]) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $size && $matrix[$y][$x]) {
                    $x++;
                }
                $path .= 'M' . ($start + $quietZone) . ' ' . ($y + $quietZone) . 'h' . ($x - $start) . 'v1h-' . ($x - $start) . 'z';
            }
        }

        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        return '<svg xmlns="http://www.w3.org/2000/svg" class="btp-qr__svg" viewBox="0 0 ' . $total . ' ' . $total
            . '" shape-rendering="crispEdges" role="img" aria-label="' . $safeLabel . '">'
            . '<title>' . $safeLabel . '</title>'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/></svg>';
    }

    public static function fits(string $text): bool
    {
        try {
            self::pickVersion(strlen($text));

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private static function pickVersion(int $byteLength): int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $countBits = $version <= 9 ? 8 : 16;
            if (4 + $countBits + 8 * $byteLength <= self::dataCapacity($version) * 8) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('Payload is too long for the QR encoder.');
    }

    private static function dataCapacity(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version] as [$count, , $data]) {
            $total += $count * $data;
        }

        return $total;
    }

    /**
     * @return list<int>
     */
    private static function dataCodewords(string $text, int $version): array
    {
        $bits = [];
        self::appendBits($bits, 0b0100, 4);
        self::appendBits($bits, strlen($text), $version <= 9 ? 8 : 16);
        foreach (str_split($text) as $char) {
            self::appendBits($bits, ord($char), 8);
        }

        $capacityBits = self::dataCapacity($version) * 8;
        self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $value = 0;
            foreach ($byte as $bit) {
                $value = ($value << 1) | $bit;
            }
            $codewords[] = $value;
        }

        $pad = 0xEC;
        while (count($codewords) < self::dataCapacity($version)) {
            $codewords[] = $pad;
            $pad = $pad === 0xEC ? 0x11 : 0xEC;
        }

        return $codewords;
    }

    /**
     * @param list<int> $bits
     */
    private static function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * Split into blocks, append Reed-Solomon parity, interleave.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private static function interleave(array $data, int $version): array
    {
        $blocks = [];
        $parity = [];
        $offset = 0;
        foreach (self::BLOCKS[$version] as [$count, $total, $dataLength]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($data, $offset, $dataLength);
                $offset += $dataLength;
                $blocks[] = $block;
                $parity[] = self::reedSolomon($block, $total - $dataLength);
            }
        }

        $result = [];
        $maxData = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        $parityLength = count($parity[0]);
        for ($i = 0; $i < $parityLength; $i++) {
            foreach ($parity as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $degree): array
    {
        $divisor = array_fill(0, $degree, 0);
        $divisor[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $divisor[$j] = self::gfMul($divisor[$j], $root);
                if ($j + 1 < $degree) {
                    $divisor[$j] ^= $divisor[$j + 1];
                }
            }
            $root = self::gfMul($root, 2);
        }

        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMul($divisor[$i], $factor);
            }
        }

        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        if (self::$exp === []) {
            $value = 1;
            for ($i = 0; $i < 255; $i++) {
                self::$exp[$i] = $value;
                self::$log[$value] = $i;
                $value <<= 1;
                if ($value & 0x100) {
                    $value ^= 0x11D;
                }
            }
            for ($i = 255; $i < 512; $i++) {
                self::$exp[$i] = self::$exp[$i - 255];
            }
        }

        if ($x === 0 || $y === 0) {
            return 0;
        }

        return self::$exp[self::$log[$x] + self::$log[$y]];
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $function
     */
    private static function drawFunctionPatterns(array &$modules, array &$function, int $version, int $size): void
    {
        for ($i = 0; $i < $size; $i++) {
            self::setFunction($modules, $function, 6, $i, $i % 2 === 0);
            self::setFunction($modules, $function, $i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $x >= $size || $y < 0 || $y >= $size) {
                        continue;
                    }
                    $distance = max(abs($dx), abs($dy));
                    self::setFunction($modules, $function, $x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }

        $positions = self::ALIGNMENT[$version];
        $last = count($positions) - 1;
        foreach ($positions as $i => $px) {
            foreach ($positions as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        self::setFunction($modules, $function, $px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        self::drawFormatBits($modules, 0, $size, $function);

        if ($version >= 7) {
            $remainder = $version;
            for ($i = 0; $i < 12; $i++) {
                $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $remainder;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $b = intdiv($i, 3);
                self::setFunction($modules, $function, $a, $b, $bit);
                self::setFunction($modules, $function, $b, $a, $bit);
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>>|null $function when given, the cells are also flagged as function modules
     */
    private static function drawFormatBits(array &$modules, int $mask, int $size, ?array &$function = null): void
    {
        $data = (self::ECL_FORMAT_BITS << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$function): void {
            $modules[$y][$x] = $dark;
            if ($function !== null) {
                $function[$y][$x] = true;
            }
        };
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $function
     */
    private static function setFunction(array &$modules, array &$function, int $x, int $y, bool $dark): void
    {
        $modules[$y][$x] = $dark;
        $function[$y][$x] = true;
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $function
     * @param list<int> $codewords
     */
    private static function placeData(array &$modules, array $function, array $codewords, int $size): void
    {
        $totalBits = count($codewords) * 8;
        $index = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vertical : $vertical;
                    if ($function[$y][$x] || $index >= $totalBits) {
                        continue;
                    }
                    $modules[$y][$x] = (($codewords[$index >> 3] >> (7 - ($index & 7))) & 1) === 1;
                    $index++;
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $function
     */
    private static function applyMask(array &$modules, array $function, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($function[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $modules[$y][$x] = ! $modules[$y][$x];
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $penalty = 0;
        $dark = 0;

        $lines = [];
        for ($y = 0; $y < $size; $y++) {
            $lines[] = $modules[$y];
            $dark += count(array_filter($modules[$y]));
        }
        for ($x = 0; $x < $size; $x++) {
            $lines[] = array_column($modules, $x);
        }

        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i < $size; $i++) {
                if ($line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $penalty += 3 + $run - 5;
                }
                $run = 1;
            }
            if ($run >= 5) {
                $penalty += 3 + $run - 5;
            }

            $string = implode('', array_map(static fn (bool $cell): string => $cell ? '1' : '0', $line));
            $penalty += 40 * (substr_count($string, '10111010000') + substr_count($string, '00001011101'));
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $value = $modules[$y][$x];
                if ($value === $modules[$y][$x + 1] && $value === $modules[$y + 1][$x] && $value === $modules[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        $total = $size * $size;
        $penalty += (int) (intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1) * 10;

        return $penalty;
    }
}
