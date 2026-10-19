<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer;

/** B-03: partial masking of a recipient address for the mail log ("jan.kowalski@x.pl" -> "j***@x.pl"). */
final class MailRecipientMask
{
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return $email === '' ? '' : '***';
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
