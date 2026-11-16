<?php
declare(strict_types=1);

namespace Eshcol\Ats;

/** Who did what, and when. Written for every sign-in, change and file access. */
final class Audit
{
    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, ?array $detail = null): void
    {
        try {
            App::get()->db->insert('audit_log', [
                'user_id' => $_SESSION['uid'] ?? null,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : Http::ip(),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the action being audited.
        }
    }
}
