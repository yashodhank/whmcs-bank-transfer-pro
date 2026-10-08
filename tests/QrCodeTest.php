<?php

declare(strict_types=1);

namespace BankTransferPro\Tests;

use BankTransferPro\Packs\Qr\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    private const UPI = 'upi://pay?pa=name@bank&pn=Securiace%20Technologies&am=1500.00&cu=INR&tn=BTP-10482-X';

    public function testVersionGrowsWithPayloadAndSizeMatchesSpec(): void
    {
        $this->assertCount(21, QrCode::matrix('A'));
        $this->assertCount(37, QrCode::matrix(self::UPI));
        $this->assertCount(57, QrCode::matrix(str_repeat('x', 200)));
    }

    public function testFinderPatternsAndTimingAreInPlace(): void
    {
        $matrix = QrCode::matrix(self::UPI);
        $size = count($matrix);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$row, $col]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($matrix[$row][$col + $i], 'finder top edge');
                $this->assertTrue($matrix[$row + 6][$col + $i], 'finder bottom edge');
                $this->assertTrue($matrix[$row + $i][$col], 'finder left edge');
                $this->assertTrue($matrix[$row + $i][$col + 6], 'finder right edge');
            }
            $this->assertTrue($matrix[$row + 3][$col + 3], 'finder centre');
            $this->assertFalse($matrix[$row + 1][$col + 1], 'finder ring');
        }

        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $matrix[6][$i], 'horizontal timing');
            $this->assertSame($i % 2 === 0, $matrix[$i][6], 'vertical timing');
        }
        $this->assertTrue($matrix[$size - 8][8], 'dark module');
    }

    /**
     * Golden matrix. The pattern was decoded back to the exact payload with an independent
     * reader (OpenCV QRCodeDetector) when it was recorded, so any change to the encoder,
     * mask choice or Reed-Solomon parity shows up here.
     */
    public function testEncodingIsStable(): void
    {
        $rows = array_map(
            static fn (array $row): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row)),
            QrCode::matrix(self::UPI)
        );

        $this->assertSame('ab1529587a9711eaf3e6feecbca57788eeb126b9', sha1(implode("\n", $rows)));
    }

    public function testSvgIsSelfContainedAndEscapesTheLabel(): void
    {
        $svg = QrCode::svg(self::UPI, 'UPI "ID" <QR>');

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('viewBox="0 0 45 45"', $svg);
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('aria-label="UPI &quot;ID&quot; &lt;QR&gt;"', $svg);
        $this->assertStringNotContainsString('<QR>', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('upi://', $svg, 'payload must not be echoed into the markup');
    }

    public function testOversizedPayloadIsRejected(): void
    {
        $this->assertTrue(QrCode::fits(str_repeat('a', 412)));
        $this->assertFalse(QrCode::fits(str_repeat('a', 413)));

        $this->expectException(\InvalidArgumentException::class);
        QrCode::matrix(str_repeat('a', 413));
    }
}
