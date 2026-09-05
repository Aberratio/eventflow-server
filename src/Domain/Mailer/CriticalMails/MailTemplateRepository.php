<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Domain\Mailer\CriticalMails;

use ZmierzymyCzas\Domain\Repository;

/**
 * CRUD on `mail_templates` plus the resolution rule from 17-katalog-maili §5:
 * an organizer's per-Wydarzenie override wins when one exists, otherwise the
 * platform falls back to the Admin's global default, silently and
 * automatically — never a blocked send. `event_id = ''` is this table's
 * storage convention for "the global row" (see the migration's doc comment).
 *
 * Every write goes through MailRenderer::assertOnlyKnownVariables() first —
 * this is the "walidacja przy zapisie" the spec asks for; render() at send
 * time stays deliberately lenient (see MailRenderer's doc comment).
 */
final class MailTemplateRepository extends Repository
{
    private const GLOBAL_SCOPE = '';

    private const COLUMNS = 'id, mail_type AS mailType, event_id AS eventId, subject, body_html AS bodyHtml, '
        . 'version, updated_by AS updatedBy, created_at AS createdAt, updated_at AS updatedAt';

    public function __construct(\PDO $pdo, private readonly MailRenderer $renderer = new MailRenderer())
    {
        parent::__construct($pdo);
    }

    /**
     * The effective template for a mail type, applied to a specific event when
     * that mail type is overridable and the event has its own row — otherwise
     * (systemic mail type, no override, or no $eventId given) the global
     * default. Falls back further to MailCatalog's built-in default when even
     * the global row has never been saved, so sending works before any admin
     * has touched the template editor.
     *
     * @return array{subject: string, bodyHtml: string, version: int, source: string}
     */
    public function resolve(string $mailType, ?string $eventId): array
    {
        if ($eventId !== null && $eventId !== '' && MailCatalog::isOverridable($mailType)) {
            $override = $this->findRow($mailType, $eventId);
            if ($override !== null) {
                return [
                    'subject' => $override['subject'],
                    'bodyHtml' => $override['bodyHtml'],
                    'version' => $override['version'],
                    'source' => 'event_override',
                ];
            }
        }

        $global = $this->findRow($mailType, self::GLOBAL_SCOPE);
        if ($global !== null) {
            return [
                'subject' => $global['subject'],
                'bodyHtml' => $global['bodyHtml'],
                'version' => $global['version'],
                'source' => 'global',
            ];
        }

        $catalogDefault = MailCatalog::get($mailType);

        return [
            'subject' => $catalogDefault['defaultSubject'],
            'bodyHtml' => $catalogDefault['defaultBodyHtml'],
            'version' => 1,
            'source' => 'catalog_default',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findRow(string $mailType, string $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM mail_templates WHERE mail_type = :mailType AND event_id = :eventId'
        );
        $stmt->execute(['mailType' => $mailType, 'eventId' => $eventId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Admin-only global default (17 §5: pozycje 1-3, 12 are edited exclusively
     * this way; 4-11/13-14 also have a global row as the organizer's fallback).
     *
     * @return array<string, mixed>
     */
    public function saveGlobal(string $mailType, string $subject, string $bodyHtml, string $actorId): array
    {
        return $this->save($mailType, self::GLOBAL_SCOPE, $subject, $bodyHtml, $actorId);
    }

    /**
     * Organizer override for one Wydarzenie. Rejected outright for a systemic
     * mail type (1-3, 12) — those are "nietykalne dla organizatora" per §5,
     * not just hidden in a UI that a direct API call could still bypass.
     *
     * @return array<string, mixed>
     */
    public function saveEventOverride(string $mailType, string $eventId, string $subject, string $bodyHtml, string $actorId): array
    {
        if (!MailCatalog::isOverridable($mailType)) {
            throw new \InvalidArgumentException('mail_type_not_overridable: ' . $mailType);
        }
        if ($eventId === '') {
            throw new \InvalidArgumentException('event_id_required');
        }

        return $this->save($mailType, $eventId, $subject, $bodyHtml, $actorId);
    }

    /** Removing an override reverts that event to the global default — the same silent fallback as never having set one. */
    public function deleteEventOverride(string $mailType, string $eventId): void
    {
        $this->pdo->prepare('DELETE FROM mail_templates WHERE mail_type = :mailType AND event_id = :eventId AND event_id <> :global')
            ->execute(['mailType' => $mailType, 'eventId' => $eventId, 'global' => self::GLOBAL_SCOPE]);
    }

    /**
     * Every effective template for one event: the overridable mail types with
     * whichever row applies (override or fallback) plus a flag telling the
     * caller whether it is actually overridden — the minimal read an
     * organizer-facing template editor screen needs, without a frontend build
     * in this ticket (server-only, like E1.6-E1.9 before it).
     *
     * @return array<int, array{mailType: string, subject: string, bodyHtml: string, version: int, source: string, overridable: bool}>
     */
    public function listForEvent(string $eventId): array
    {
        $out = [];
        foreach (MailCatalog::all() as $mailType => $definition) {
            $resolved = $this->resolve($mailType, $eventId);
            $out[] = [
                'mailType' => $mailType,
                'overridable' => $definition['overridable'],
                ...$resolved,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function save(string $mailType, string $eventId, string $subject, string $bodyHtml, string $actorId): array
    {
        MailCatalog::get($mailType); // throws on an unknown mail type
        $this->renderer->assertOnlyKnownVariables($subject, $bodyHtml, MailCatalog::variablesFor($mailType));

        $existing = $this->findRow($mailType, $eventId);
        $now = date(DATE_ATOM);

        if ($existing === null) {
            $id = $this->id();
            $this->insert('mail_templates', [
                'id' => $id,
                'mail_type' => $mailType,
                'event_id' => $eventId,
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'version' => 1,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->findRow($mailType, $eventId) ?? [];
        }

        $this->updateById('mail_templates', (string) $existing['id'], [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'version' => ((int) $existing['version']) + 1,
            'updated_by' => $actorId,
            'updated_at' => $now,
        ]);

        return $this->findRow($mailType, $eventId) ?? [];
    }
}
