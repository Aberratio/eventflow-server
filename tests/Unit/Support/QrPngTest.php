<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ZmierzymyCzas\Support\QrCode;
use ZmierzymyCzas\Support\QrPng;

final class QrPngTest extends TestCase
{
    public function testRendersValidPngWhoseMatrixMatchesEncoder(): void
    {
        $content = QrCode::startCode();
        $png = QrPng::render($content);

        self::assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
        self::assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($png));

        // Walk the chunks, verifying every CRC, and collect IHDR and IDAT.
        $offset = 8;
        $idat = '';
        $ihdr = null;
        $types = [];
        while ($offset < strlen($png)) {
            $length = (int) unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $crc = (int) unpack('N', substr($png, $offset + 8 + $length, 4))[1];
            self::assertSame(crc32($type . $data), $crc, 'CRC of ' . $type);
            $types[] = $type;
            if ($type === 'IHDR') {
                $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            }
            $offset += 12 + $length;
        }
        self::assertSame(['IHDR', 'IDAT', 'IEND'], $types);
        self::assertSame(strlen($png), $offset);

        $size = QrPng::pixelSize($content);
        self::assertSame($size, $ihdr['width']);
        self::assertSame($size, $ihdr['height']);
        self::assertSame(8, $ihdr['depth']);
        self::assertSame(0, $ihdr['type']);

        $raw = (string) gzuncompress($idat);
        self::assertSame($size * ($size + 1), strlen($raw));

        $matrix = QrPng::matrix($content);
        $modules = count($matrix);
        self::assertSame($size, ($modules + 2 * QrPng::QUIET_ZONE_MODULES) * QrPng::MODULE_PX);
        $pixel = static fn (int $x, int $y): int => ord($raw[$y * ($size + 1) + 1 + $x]);
        for ($row = 0; $row < $modules; $row++) {
            for ($col = 0; $col < $modules; $col++) {
                $centreX = (QrPng::QUIET_ZONE_MODULES + $col) * QrPng::MODULE_PX + intdiv(QrPng::MODULE_PX, 2);
                $centreY = (QrPng::QUIET_ZONE_MODULES + $row) * QrPng::MODULE_PX + intdiv(QrPng::MODULE_PX, 2);
                self::assertSame($matrix[$row][$col] ? 0 : 255, $pixel($centreX, $centreY), "module $row,$col");
            }
        }
        // The quiet zone is light, and the three finder patterns start dark.
        self::assertSame(255, $pixel(0, 0));
        self::assertTrue($matrix[0][0] && $matrix[0][$modules - 1] && $matrix[$modules - 1][0]);
        self::assertGreaterThan(21, $modules);
    }

    public function testIsDeterministic(): void
    {
        self::assertSame(QrPng::render('pqr_abc'), QrPng::render('pqr_abc'));
    }

    public function testEmptyContentThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrPng::render('');
    }
}
