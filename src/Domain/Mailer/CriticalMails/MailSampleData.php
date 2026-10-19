<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

/**
 * B-03: realistic example values for every placeholder a mail type may reference
 * (MailCatalog::variablesFor()), used only by the template preview. Values are fixed and obviously
 * fictional so a preview is reproducible and can never leak real data.
 */
final class MailSampleData
{
    private const SAMPLES = [
        'verificationLink' => 'https://zmierzymyczas.pl/auth/verify?token=PRZYKLAD',
        'resetLink' => 'https://zmierzymyczas.pl/auth/reset?token=PRZYKLAD',
        'expiresInMinutes' => '60',
        'expiresInHours' => '72',
        'reason' => 'Przykładowy powód',
        'supportContact' => 'biuro@zmierzymyczas.pl',
        'setupLink' => 'https://zmierzymyczas.pl/auth/setup?token=PRZYKLAD',
        'eventName' => 'Przykładowy Bieg Uliczny 2026',
        'distanceName' => '10 km',
        'amountDue' => '80,00 zł',
        'myStartsLink' => 'https://zmierzymyczas.pl/moje-starty',
        'competitorName' => 'Jan Kowalski',
        'payNowLink' => 'https://zmierzymyczas.pl/platnosc/PRZYKLAD',
        'expiresAt' => '2026-12-31 23:59',
        'changeSummary' => 'Zmieniono godzinę startu na 11:00.',
        'refundStatus' => 'Zwrot zostanie przyznany automatycznie.',
        'decision' => 'Zaakceptowano',
        'resultsLink' => 'https://zmierzymyczas.pl/wyniki/przyklad',
        'orderNumber' => 'ZC-2026-0001',
        'status' => 'W realizacji',
        'trackingNumber' => 'PL1234567890',
        'challengeName' => 'Przykładowe wyzwanie',
        'rewardLink' => 'https://zmierzymyczas.pl/nagrody/PRZYKLAD',
        'amount' => '80,00 zł',
        'route' => 'Zwrot na kartę płatniczą',
    ];

    /**
     * @param array<string, string> $overrides e.g. the real event name for the per-event preview
     * @return array<string, string> one value per variable of the mail type, in catalog order
     * @throws \InvalidArgumentException for an unknown mail type
     */
    public static function for(string $mailType, array $overrides = []): array
    {
        $out = [];
        foreach (MailCatalog::variablesFor($mailType) as $variable) {
            $out[$variable] = $overrides[$variable] ?? self::SAMPLES[$variable] ?? '[' . $variable . ']';
        }

        return $out;
    }
}
