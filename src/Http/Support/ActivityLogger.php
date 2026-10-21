<?php

declare(strict_types=1);

namespace ZmierzymyCzas\Http\Support;

use ZmierzymyCzas\Domain\Admin\AdminRepository;

/**
 * Server-authored activity log. The actor always comes from the verified session (`$auth`),
 * never from the request body, so an entry cannot be forged as someone else. `targetName` is a
 * deliberate snapshot of the target's name at the time of the action, not a foreign-key lookup.
 */
final class ActivityLogger
{
    public function __construct(private readonly AdminRepository $admin)
    {
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed>|null $metadata optional rich JSON snapshot (e.g. the competitor merge entry)
     */
    public function log(array $auth, string $action, string $description, ?string $targetType = null, ?string $targetId = null, ?string $targetName = null, ?array $metadata = null): void
    {
        $this->admin->insertActivityLog([
            'userId' => (string) ($auth['sub'] ?? ''),
            'action' => $action,
            'description' => $description,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'targetName' => $targetName,
            'metadata' => $metadata,
        ]);
    }
}
