<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Billing\Entitlements;
use App\Domain\Status\PlatformStatus;
use App\Domain\User\User;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Http\Middleware\ResolveWorkspace;
use App\Kernel\Http\RequestContext;
use App\Support\Clock;

/**
 * Feeds the application shell (sidebar, workspace switcher) and the Twig helpers `can()`,
 * `current_workspace()`, `my_workspaces()` and `workspace_nav()`.
 *
 * Inside `/w/{id}/…` the workspace is the resolved one; on account pages (outside any workspace) the
 * last used workspace is shown so the sidebar does not jump, after checking the membership again.
 */
final class WorkspaceNav
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly WorkspaceRepository $workspaces,
        private readonly Permissions $permissions,
        private readonly Entitlements $entitlements,
        private readonly Clock $clock,
        private readonly PlatformStatus $status,
    ) {
    }

    /**
     * Whether the current member holds a permission in the workspace of this request.
     */
    public function can(string $permission): bool
    {
        $workspace = $this->context->workspace();

        return $workspace instanceof WorkspaceContext && $this->permissions->allows($workspace->role, $permission);
    }

    public function current(): ?WorkspaceContext
    {
        $resolved = $this->context->workspace();
        if ($resolved instanceof WorkspaceContext) {
            return $resolved;
        }
        $user = $this->context->user();
        $last = $this->context->session()?->get(ResolveWorkspace::SESSION_KEY);
        if (!$user instanceof User || !is_string($last)) {
            return null;
        }
        $workspace = $this->workspaces->findByPublicId($last);
        $membership = $workspace === null ? null : $this->workspaces->membership($workspace->id, $user->id);

        return $workspace !== null && $membership !== null ? WorkspaceContext::from($workspace, $membership) : null;
    }

    /**
     * Workspaces for the switcher.
     *
     * @return list<array{name: string, href: string, role: string, current: bool}>
     */
    public function mine(): array
    {
        $user = $this->context->user();
        if (!$user instanceof User) {
            return [];
        }

        $current = $this->current()?->workspacePublicId;

        return array_map(static fn (array $row): array => [
            'name' => $row['workspace']->name,
            'href' => '/w/' . $row['workspace']->publicId,
            'role' => $row['role']->label(),
            'current' => $row['workspace']->publicId === $current,
        ], $this->workspaces->forUser($user->id));
    }

    /**
     * The plan card at the bottom of the sidebar: the plan, what is left of the trial and the month's posts. It links to the billing page
     * for the owner only (for everyone else it is information, not a way in).
     *
     * @return array{name: string, trial_days: int|null, posts_used: int, posts_limit: int|null, href: string|null}|null
     */
    public function planCard(): ?array
    {
        $workspace = $this->current();
        if ($workspace === null) {
            return null;
        }
        $now = $this->clock->now();
        $entitlement = $this->entitlements->for($workspace->workspaceId);
        $trialEnds = $entitlement->subscription?->isTrial() === true ? $entitlement->subscription->trialEndsAt : null;

        return [
            'name' => $entitlement->plan->name,
            'trial_days' => $trialEnds === null ? null : max(0, (int) ceil(($trialEnds->getTimestamp() - $now->getTimestamp()) / 86400)),
            'posts_used' => $this->entitlements->postsInMonth($workspace->workspaceId, $now),
            'posts_limit' => $entitlement->limit('posts_per_month'),
            'href' => $this->permissions->allows($workspace->role, 'workspace.billing') ? '/w/' . $workspace->workspacePublicId . '/billing' : null,
        ];
    }

    /**
     * Sentences for the banner "a network you publish to is having trouble", for the current workspace only.
     *
     * @return list<string>
     */
    public function platformNotices(): array
    {
        $workspace = $this->current();
        if ($workspace === null) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($h): ?string => $h->notice, $this->status->problemsFor($workspace->workspaceId)), static fn (?string $n): bool => $n !== null));
    }

    /**
     * Sidebar entries for the current workspace, limited to what the member may open.
     *
     * @return list<array{id: string, label: string, icon: string, href: string}>
     */
    public function items(): array
    {
        $workspace = $this->current();
        if ($workspace === null) {
            return [
                ['id' => 'dashboard', 'label' => 'Обзор', 'icon' => 'layout-dashboard', 'href' => '/app'],
                ['id' => 'settings', 'label' => 'Безопасность', 'icon' => 'settings', 'href' => '/account/security'],
            ];
        }
        $base = '/w/' . $workspace->workspacePublicId;
        $items = [['id' => 'dashboard', 'label' => 'Обзор', 'icon' => 'layout-dashboard', 'href' => $base]];
        if ($this->permissions->allows($workspace->role, 'calendar.view')) {
            $items[] = ['id' => 'calendar', 'label' => 'Календарь', 'icon' => 'calendar-days', 'href' => $base . '/calendar'];
        }
        if ($this->permissions->allows($workspace->role, 'posts.draft')) {
            $items[] = ['id' => 'editor', 'label' => 'Новый пост', 'icon' => 'pencil', 'href' => $base . '/posts/new'];
        }
        if ($this->permissions->allows($workspace->role, 'channels.view')) {
            $items[] = ['id' => 'channels', 'label' => 'Каналы', 'icon' => 'share-2', 'href' => $base . '/channels'];
        }
        if ($this->permissions->allows($workspace->role, 'sources.view')) {
            $items[] = ['id' => 'sources', 'label' => 'Источники', 'icon' => 'share-2', 'href' => $base . '/sources'];
        }
        if ($this->permissions->allows($workspace->role, 'discovery.view')) {
            $items[] = ['id' => 'radar', 'label' => 'Радар', 'icon' => 'search', 'href' => $base . '/radar'];
        }
        if ($this->permissions->allows($workspace->role, 'media.view')) {
            $items[] = ['id' => 'media', 'label' => 'Медиатека', 'icon' => 'images', 'href' => $base . '/media'];
        }
        if ($this->permissions->allows($workspace->role, 'members.manage')) {
            $items[] = ['id' => 'team', 'label' => 'Команда', 'icon' => 'users', 'href' => $base . '/team'];
        }
        if ($this->permissions->allows($workspace->role, 'audit.view')) {
            $items[] = ['id' => 'audit', 'label' => 'Журнал действий', 'icon' => 'list', 'href' => $base . '/audit'];
        }
        if ($this->permissions->allows($workspace->role, 'workspace.settings')) {
            $items[] = ['id' => 'workspace', 'label' => 'Пространство', 'icon' => 'building-2', 'href' => $base . '/settings'];
        }
        if ($this->permissions->allows($workspace->role, 'workspace.billing')) {
            $items[] = ['id' => 'billing', 'label' => 'Тариф и оплата', 'icon' => 'credit-card', 'href' => $base . '/billing'];
        }
        $items[] = ['id' => 'settings', 'label' => 'Безопасность', 'icon' => 'shield', 'href' => '/account/security'];

        return $items;
    }
}
