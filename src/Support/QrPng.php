<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR code as PNG bytes without GD/Imagick (neither is guaranteed on the hosting).
 * bacon/bacon-qr-code only builds the module matrix (its renderers need imagick); the PNG is
 * written here: 8-bit greyscale, black modules on white, with a quiet zone. Central Mailer sniffs
 * the attachment MIME type from the bytes and accepts image/png only, so this must be a real PNG.
 */
final class QrPng
{
    public const MODULE_PX = 6;
    public const QUIET_ZONE_MODULES = 4;

    /** PNG (8-bit greyscale, black on white) of a QR code (error correction M, byte mode) for $content. */
    public static function render(string $content, int $modulePx = self::MODULE_PX, int $quietZone = self::QUIET_ZONE_MODULES): string
    {
        $matrix = self::matrix($content);
        $modules = count($matrix);
        $size = ($modules + 2 * $quietZone) * $modulePx;

        $lightRow = "\x00" . str_repeat("\xFF", $size);
        $quietBand = str_repeat($lightRow, $quietZone * $modulePx);
        $raw = $quietBand;
        foreach ($matrix as $row) {
            $pixels = str_repeat("\xFF", $quietZone * $modulePx);
            foreach ($row as $dark) {
                $pixels .= str_repeat($dark ? "\x00" : "\xFF", $modulePx);
            }
            $pixels .= str_repeat("\xFF", $quietZone * $modulePx);
            $raw .= str_repeat("\x00" . $pixels, $modulePx);
        }
        $raw .= $quietBand;

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0))
            . self::chunk('IDAT', (string) gzcompress($raw, 9))
            . self::chunk('IEND', '');
    }

    /** @return list<list<bool>> true = dark module */
    public static function matrix(string $content): array
    {
        if ($content === '') {
            throw new \InvalidArgumentException('QR content must not be empty.');
        }

        $byteMatrix = Encoder::encode($content, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $width = $byteMatrix->getWidth();
        $matrix = [];
        for ($y = 0; $y < $width; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $row[] = $byteMatrix->get($x, $y) === 1;
            }
            $matrix[] = $row;
        }

        return $matrix;
    }

    /** Pixel width (and height) of the image: (modules + 2 * quietZone) * modulePx. */
    public static function pixelSize(string $content, int $modulePx = self::MODULE_PX, int $quietZone = self::QUIET_ZONE_MODULES): int
    {
        return (count(self::matrix($content)) + 2 * $quietZone) * $modulePx;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
