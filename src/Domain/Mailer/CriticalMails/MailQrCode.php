<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

use ZmierzymyCzas\Support\QrPng;

/**
 * PP.19: the inline QR image (a `cid:` PNG attachment) plus the HTML block that shows it next to the
 * code as text. CriticalMailer appends the block after the rendered template, so organizer template
 * overrides keep the QR too. The QR content is the raw token (pqr_... / zqr_...), exactly what
 * Biuro Zawodów scans; no URL wrapping.
 */
final class MailQrCode
{
    public const START_CODE_CONTENT_ID = 'qr-kod-startowy';
    public const START_CODE_FILENAME = 'kod-startowy.png';
    public const START_CODE_LABEL = 'Twój kod startowy';

    public const IDENTITY_CODE_CONTENT_ID = 'qr-kod-tozsamosci';
    public const IDENTITY_CODE_FILENAME = 'kod-tozsamosci.png';
    public const IDENTITY_CODE_LABEL = 'Kod tożsamości Zawodnika';

    /** @return array{filename: string, contentBase64: string, contentId: string, inline: true} */
    public static function attachment(string $code, string $contentId, string $filename): array
    {
        return [
            'filename' => $filename,
            'contentBase64' => base64_encode(QrPng::render($code)),
            'contentId' => $contentId,
            'inline' => true,
        ];
    }

    public static function htmlBlock(string $code, string $contentId, string $label): string
    {
        $px = QrPng::pixelSize($code);

        return '<p><strong>' . self::e($label) . '</strong></p>'
            . '<p><img src="cid:' . $contentId . '" alt="' . self::e($label) . ' (kod QR)" width="' . $px
            . '" height="' . $px . '" style="display:block"></p>'
            . '<p style="font-family:monospace;font-size:13px">' . self::e($code) . '</p>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
