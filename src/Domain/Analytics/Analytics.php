<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Writes the raw stream of business events into `analytics_events` (`user_registered`, `email_verified`, `channel_connected`,
 * `trial_started`, `payment_succeeded`, `visit`, ...). Server side only: nothing is sent to a third party.
 *
 * Revenue, MRR and the lifecycle counts on the dashboard are computed from the money journal and the base tables, not from these events, so
 * they cannot drift when an event is missed; the stream is for the funnel's visit step, for later features (AI use, referrals) and for ad-hoc
 * questions. Props never hold secrets or free text typed by people.
 */
final class Analytics
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly FirstTouch $firstTouch,
    ) {
    }

    /**
     * @param array<string, scalar|null> $props
     * @param string|null $onceKey a second event with the same key is ignored ("first post of workspace 12")
     * @return bool whether a row was written
     */
    public function track(string $name, ?int $userId = null, ?int $workspaceId = null, array $props = [], ?string $onceKey = null, ?string $visitorId = null): bool
    {
        return $this->db->execute(
            'INSERT IGNORE INTO analytics_events (name, user_id, workspace_id, visitor_id, once_key, props_json, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                mb_substr($name, 0, 48),
                $userId,
                $workspaceId,
                $visitorId,
                $onceKey === null ? null : mb_substr($onceKey, 0, 100),
                $props === [] ? null : json_encode($props, JSON_THROW_ON_ERROR),
                DbTime::format($this->clock->now()),
            ],
        ) === 1;
    }

    /**
     * Turn an audit entry into the business event it stands for (called by `AuditLog`). Unmapped actions produce nothing.
     *
     * @param array<string, scalar|null> $meta
     */
    public function fromAudit(string $action, ?int $actorId, ?string $subjectId, array $meta, ?int $workspaceId): void
    {
        $kind = is_string($meta['kind'] ?? null) ? $meta['kind'] : '';
        if ($actorId !== null && ($action === 'auth.register' || $action === 'auth.social.registered')) {
            $this->firstTouch->attribute($actorId);
        }
        match ($action) {
            'selection.semantic_settings_updated', 'source.images_completed', 'source.images_failed', 'source.images_stale',
            'discovery.refreshed', 'discovery.imported', 'source.video_completed', 'source.video_failed', 'source.text_completed', 'source.text_failed', 'source.text_stale' => $this->track(str_replace('.', '_', $action), $actorId, $workspaceId),
            'auth.register', 'auth.social.registered' => $this->track('user_registered', $actorId, null, ['method' => $action === 'auth.register' ? 'email' : 'social'], 'user_registered:' . $actorId),
            'auth.email.verified' => $this->track('email_verified', $actorId, null, [], 'email_verified:' . $actorId),
            'channel.connected' => $this->channelConnected($actorId, $workspaceId, $meta),
            'post.published' => $this->track('first_post_published', $actorId, $workspaceId, [], 'first_post_published:' . $workspaceId),
            'billing.trial_started' => $this->track('trial_started', null, $workspaceId, ['plan' => (string) ($meta['plan'] ?? '')], 'trial_started:' . $workspaceId),
            'billing.payment_succeeded' => $this->track(
                match ($kind) {
                    'upgrade' => 'subscription_upgraded',
                    'renewal' => 'subscription_renewed',
                    default => 'subscription_started',
                },
                null,
                $workspaceId,
                ['plan' => (string) ($meta['plan'] ?? ''), 'period' => (string) ($meta['period'] ?? ''), 'amount' => (int) ($meta['amount'] ?? 0), 'provider' => (string) ($meta['provider'] ?? '')],
                'payment_ok:' . $subjectId,
            ),
            'billing.payment_failed' => $this->track('payment_failed', null, $workspaceId, [], 'payment_failed:' . $subjectId),
            'billing.refunded' => $this->track('payment_refunded', $actorId, $workspaceId, ['amount' => (int) ($meta['amount'] ?? 0)]),
            'billing.renewal_canceled' => $this->track('subscription_cancelled', $actorId, $workspaceId),
            'billing.downgraded_to_free' => $this->track('subscription_expired', null, $workspaceId, ['reason' => (string) ($meta['reason'] ?? '')]),
            default => null,
        };
    }

    /**
     * @param array<string, scalar|null> $meta
     */
    private function channelConnected(?int $actorId, ?int $workspaceId, array $meta): bool
    {
        return $this->track('channel_connected', $actorId, $workspaceId, ['platform' => (string) ($meta['platform'] ?? '')]);
    }

    /**
     * A fresh anonymous visitor id (kept in the session only).
     */
    public static function newVisitorId(): string
    {
        return (string) new Ulid();
    }
}
