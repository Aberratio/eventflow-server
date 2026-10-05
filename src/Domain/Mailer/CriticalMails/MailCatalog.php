<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

/**
 * The full 15-item mail catalog from 17-katalog-maili-i-kontrakt-z-mailerem.md
 * §1, as a static lookup: which Central Mailer priority a mail type uses (§2 —
 * `technical` only for authentication, 1-3), whether an organizer may override
 * its template per Wydarzenie (§5 — 4-11 and 13-14 are overridable, 1-3 and 12
 * are systemic/admin-only), the closed list of variables it may reference (§5,
 * validated at template-save time by MailTemplateRepository and substituted at
 * render time by MailRenderer), and a built-in default subject/body so sending
 * works before any admin has ever opened the template editor.
 *
 * This is pure data — no behavior, no I/O. CriticalMailer is the only class
 * that reads from it.
 */
final class MailCatalog
{
    // Central Mailer priorities (CentralMailerClient::VALID_PRIORITIES): the
    // catalog's "technical" queue maps 1:1 to Central Mailer's own `technical`
    // priority; "standard" maps to `normal` (17 §2).
    public const PRIORITY_TECHNICAL = 'technical';
    public const PRIORITY_STANDARD = 'normal';

    public const EMAIL_VERIFICATION = 'email_verification';
    public const PASSWORD_RESET = 'password_reset';
    public const ACCOUNT_LOCKED = 'account_locked';
    public const ACCOUNT_SETUP = 'account_setup';
    public const REGISTRATION_CREATED = 'registration_created';
    public const PAYMENT_CONFIRMED = 'payment_confirmed';
    public const IDENTITY_CODE = 'identity_code';
    public const UNPAID_REMINDER = 'unpaid_reminder';
    public const EVENT_CHANGED = 'event_changed';
    public const MODERATION_DECISION = 'moderation_decision';
    public const RESULTS_PUBLISHED = 'results_published';
    public const VIRTUAL_RESULT_VERDICT = 'virtual_result_verdict';
    public const SHOP_ORDER_STATUS = 'shop_order_status';
    public const CHALLENGE_COMPLETED = 'challenge_completed';
    public const REFUND_APPROVED = 'refund_approved';

    /**
     * @return array<string, array{
     *   priority: string,
     *   overridable: bool,
     *   variables: array<int, string>,
     *   defaultSubject: string,
     *   defaultBodyHtml: string,
     * }>
     */
    public static function all(): array
    {
        return [
            // 1-3: uwierzytelnianie — `technical` queue, systemic (§2, §5).
            self::EMAIL_VERIFICATION => [
                'priority' => self::PRIORITY_TECHNICAL,
                'overridable' => false,
                'variables' => ['verificationLink'],
                'defaultSubject' => 'Potwierdź swój adres e-mail',
                'defaultBodyHtml' => '<p>Dziękujemy za rejestrację. Potwierdź swój adres e-mail, klikając '
                    . '<a href="{{verificationLink}}">ten link</a>.</p>',
            ],
            self::PASSWORD_RESET => [
                'priority' => self::PRIORITY_TECHNICAL,
                'overridable' => false,
                'variables' => ['resetLink', 'expiresInMinutes'],
                'defaultSubject' => 'Reset hasła',
                'defaultBodyHtml' => '<p>Otrzymaliśmy prośbę o reset hasła. Link jest ważny przez '
                    . '{{expiresInMinutes}} minut: <a href="{{resetLink}}">{{resetLink}}</a>.</p>'
                    . '<p>Jeśli to nie Ty, zignoruj tę wiadomość.</p>',
            ],
            self::ACCOUNT_LOCKED => [
                'priority' => self::PRIORITY_TECHNICAL,
                'overridable' => false,
                'variables' => ['reason', 'supportContact'],
                'defaultSubject' => 'Twoje konto zostało zablokowane',
                'defaultBodyHtml' => '<p>Twoje konto zostało zablokowane. Powód: {{reason}}.</p>'
                    . '<p>W razie pytań skontaktuj się z nami: {{supportContact}}.</p>',
            ],
            // PP.82: password-set link for an account an admin created (systemic, technical queue).
            self::ACCOUNT_SETUP => [
                'priority' => self::PRIORITY_TECHNICAL,
                'overridable' => false,
                'variables' => ['setupLink', 'expiresInHours'],
                'defaultSubject' => 'Ustaw hasło do swojego konta w Zmierzymy Czas',
                'defaultBodyHtml' => '<p>Administrator założył dla Ciebie konto w serwisie Zmierzymy Czas. '
                    . 'Aby się zalogować, ustaw hasło: <a href="{{setupLink}}">{{setupLink}}</a>.</p>'
                    . '<p>Link jest ważny przez {{expiresInHours}} godz. Później możesz ustawić hasło przez '
                    . '„Nie pamiętam hasła” na stronie logowania.</p>'
                    . '<p>Jeśli nie spodziewasz się tej wiadomości, zignoruj ją.</p>',
            ],
            // 4-11, 13-14: nadpisywalne per Wydarzenie (§5), `normal` queue (§2).
            self::REGISTRATION_CREATED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'distanceName', 'amountDue'],
                'defaultSubject' => 'Potwierdzenie zapisu na {{eventName}}',
                'defaultBodyHtml' => '<p>Zapisaliśmy Cię na dystans {{distanceName}} wydarzenia {{eventName}}.</p>'
                    . '<p>Kwota do zapłaty: {{amountDue}}.</p>',
            ],
            self::PAYMENT_CONFIRMED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'distanceName', 'myStartsLink'],
                'defaultSubject' => 'Płatność potwierdzona — {{eventName}}',
                'defaultBodyHtml' => '<p>Twoja płatność za dystans {{distanceName}} wydarzenia {{eventName}} '
                    . 'została potwierdzona.</p><p>Pełny bilet PDF pobierzesz w '
                    . '<a href="{{myStartsLink}}">Moich startach</a>.</p>',
            ],
            self::IDENTITY_CODE => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['competitorName'],
                'defaultSubject' => 'Kod tożsamości dla {{competitorName}}',
                'defaultBodyHtml' => '<p>Utworzono profil Zawodnika: {{competitorName}}.</p>'
                    . '<p>Kod tożsamości znajdziesz w profilu na platformie.</p>',
            ],
            self::UNPAID_REMINDER => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'amountDue', 'payNowLink', 'expiresAt'],
                'defaultSubject' => 'Dokończ płatność za {{eventName}}',
                'defaultBodyHtml' => '<p>Twój zapis na {{eventName}} wciąż czeka na opłacenie '
                    . '({{amountDue}}). Okno płatności zamyka się {{expiresAt}}.</p>'
                    . '<p><a href="{{payNowLink}}">Zapłać teraz</a>.</p>',
            ],
            self::EVENT_CHANGED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'changeSummary', 'refundStatus'],
                'defaultSubject' => 'Zmiana w wydarzeniu {{eventName}}',
                'defaultBodyHtml' => '<p>W wydarzeniu {{eventName}} zaszła zmiana: {{changeSummary}}.</p>'
                    . '<p>{{refundStatus}}</p>',
            ],
            self::MODERATION_DECISION => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'decision', 'reason'],
                'defaultSubject' => 'Decyzja moderacji: {{eventName}}',
                'defaultBodyHtml' => '<p>Wydarzenie {{eventName}}: {{decision}}.</p><p>{{reason}}</p>',
            ],
            self::RESULTS_PUBLISHED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'resultsLink'],
                'defaultSubject' => 'Wyniki wydarzenia {{eventName}} są dostępne',
                'defaultBodyHtml' => '<p>Wyniki wydarzenia {{eventName}} zostały opublikowane.</p>'
                    . '<p><a href="{{resultsLink}}">Zobacz wyniki</a>.</p>',
            ],
            self::VIRTUAL_RESULT_VERDICT => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'decision', 'reason'],
                'defaultSubject' => 'Weryfikacja wyniku wirtualnego: {{eventName}}',
                'defaultBodyHtml' => '<p>Twój wynik wirtualny w {{eventName}} został: {{decision}}.</p>'
                    . '<p>{{reason}}</p>',
            ],
            // 12: sklep jest własnością platformy, nie organizatora — systemic (§5).
            self::SHOP_ORDER_STATUS => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => false,
                'variables' => ['orderNumber', 'status', 'trackingNumber'],
                'defaultSubject' => 'Zamówienie {{orderNumber}}: {{status}}',
                'defaultBodyHtml' => '<p>Status Twojego zamówienia {{orderNumber}}: {{status}}.</p>'
                    . '<p>Numer śledzenia: {{trackingNumber}}</p>',
            ],
            self::CHALLENGE_COMPLETED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['challengeName', 'rewardLink'],
                'defaultSubject' => 'Ukończyłeś wyzwanie {{challengeName}}!',
                'defaultBodyHtml' => '<p>Gratulacje, ukończyłeś wyzwanie {{challengeName}}.</p>'
                    . '<p><a href="{{rewardLink}}">Wybierz swoją nagrodę</a>.</p>',
            ],
            self::REFUND_APPROVED => [
                'priority' => self::PRIORITY_STANDARD,
                'overridable' => true,
                'variables' => ['eventName', 'amount', 'route'],
                'defaultSubject' => 'Zwrot pieniędzy zatwierdzony — {{eventName}}',
                'defaultBodyHtml' => '<p>Zatwierdziliśmy zwrot {{amount}} za {{eventName}}.</p>'
                    . '<p>Sposób zwrotu: {{route}}.</p>',
            ],
        ];
    }

    /** @return array{priority: string, overridable: bool, variables: array<int, string>, defaultSubject: string, defaultBodyHtml: string} */
    public static function get(string $mailType): array
    {
        $catalog = self::all();
        if (!isset($catalog[$mailType])) {
            throw new \InvalidArgumentException('unknown_mail_type: ' . $mailType);
        }

        return $catalog[$mailType];
    }

    public static function isOverridable(string $mailType): bool
    {
        return self::get($mailType)['overridable'];
    }

    public static function priorityFor(string $mailType): string
    {
        return self::get($mailType)['priority'];
    }

    /** @return array<int, string> */
    public static function variablesFor(string $mailType): array
    {
        return self::get($mailType)['variables'];
    }
}
