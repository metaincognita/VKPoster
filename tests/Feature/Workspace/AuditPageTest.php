<?php

declare(strict_types=1);

namespace App\Tests\Feature\Workspace;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\AuditReader;
use App\Domain\Workspace\Role;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The workspace journal: who sees it, what it lists, filters and paging, and isolation between workspaces.
 */
#[CoversClass(AuditReader::class)]
final class AuditPageTest extends WorkspaceTestCase
{
    public function testOwnerAndAdminSeeTheJournalOthersDoNot(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $admin = $this->memberOf($workspace, 'admin@example.com', Role::Admin);
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);

        $this->actAs($owner);
        self::assertSame(200, $this->get($this->base($workspace) . '/audit')->status);
        $this->actAs($admin);
        self::assertSame(200, $this->get($this->base($workspace) . '/audit')->status);
        $this->actAs($editor);
        self::assertSame(403, $this->get($this->base($workspace) . '/audit')->status);
    }

    public function testRoleChangesAndInvitationsAppearWithWhoDidThem(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor, 'Пётр');
        $this->actAs($owner);
        $memberId = (string) $this->db->select('SELECT public_id FROM workspace_members WHERE user_id = ?', [$editor->id])[0]['public_id'];
        $this->post($this->base($workspace) . '/team/members/' . $memberId . '/role', ['role' => 'author']);
        $this->post($this->base($workspace) . '/team/invitations', ['email' => 'guest@example.com', 'role' => 'viewer']);

        $page = $this->get($this->base($workspace) . '/audit')->body;

        self::assertStringContainsString('Изменена роль', $page);
        self::assertStringContainsString('editor@example.com: Редактор → Автор', $page);
        self::assertStringContainsString('Приглашён участник', $page);
        self::assertStringContainsString('guest@example.com', $page);
        self::assertStringContainsString('Создано пространство', $page);
        self::assertStringContainsString('Ольга', $page);
    }

    public function testFiltersNarrowTheList(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $audit = $this->app->container()->get(AuditLog::class);
        $audit->record('post.published', $editor->id, 'post', '1', [], $workspace->id);
        $audit->record('member.invited', $owner->id, 'invitation', 'x', ['email' => 'zed@example.com', 'role' => 'viewer'], $workspace->id);
        $this->actAs($owner);
        $url = $this->base($workspace) . '/audit';

        $posts = $this->get($url . '?group=post')->body;
        self::assertStringContainsString('Опубликован пост', $posts);
        self::assertStringNotContainsString('zed@example.com', $posts);

        $byOwner = $this->get($url . '?actor=' . $owner->id)->body;
        self::assertStringContainsString('zed@example.com', $byOwner);
        self::assertStringNotContainsString('Опубликован пост', $byOwner);

        $today = $this->clock->now()->setTimezone(new \DateTimeZone($workspace->timezone))->format('Y-m-d');
        self::assertStringContainsString('zed@example.com', $this->get($url . '?from=' . $today . '&to=' . $today)->body);
        $future = $this->clock->now()->setTimezone(new \DateTimeZone($workspace->timezone))->modify('+5 days')->format('Y-m-d');
        self::assertStringContainsString('Ничего не нашли', $this->get($url . '?from=' . $future)->body);
        self::assertStringContainsString('zed@example.com', $this->get($url . '?from=garbage&actor=abc&group=%27%20OR%201')->body, 'junk filters are ignored');
    }

    public function testEventsOfOtherWorkspacesAndSignInsAreNotShown(): void
    {
        [$ownerA, $a] = $this->ownerWithWorkspace('a@example.com');
        [$ownerB, $b] = $this->ownerWithWorkspace('b@example.com', 'Борис');
        $audit = $this->app->container()->get(AuditLog::class);
        $audit->record('member.invited', $ownerB->id, 'invitation', 'y', ['email' => 'secret-b@example.com'], $b->id);
        $audit->record('auth.login', $ownerA->id);
        $this->actAs($ownerA);

        $page = $this->get($this->base($a) . '/audit')->body;

        self::assertStringNotContainsString('secret-b@example.com', $page);
        self::assertStringNotContainsString('auth.login', $page);
    }

    public function testPaging(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $audit = $this->app->container()->get(AuditLog::class);
        for ($i = 0; $i < AuditReader::PAGE_SIZE + 5; ++$i) {
            $audit->record('post.scheduled', $owner->id, 'post', (string) $i, [], $workspace->id);
        }
        $this->actAs($owner);
        $url = $this->base($workspace) . '/audit';

        $first = $this->get($url)->body;
        self::assertSame(AuditReader::PAGE_SIZE, substr_count($first, 'Запланирован пост'));
        self::assertStringContainsString('Страница 1 из 2', $first);
        $second = $this->get($url . '?page=2')->body;
        self::assertSame(5, substr_count($second, 'Запланирован пост'));
        self::assertStringContainsString('Создано пространство', $second, 'the oldest record is on the last page');
        self::assertStringContainsString('Страница 2 из 2', $this->get($url . '?page=99')->body, 'an out-of-range page is clamped');
    }

    public function testEmptyJournalExplainsItself(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->db->execute('DELETE FROM audit_log');
        $this->actAs($owner);

        self::assertStringContainsString('Журнал пока пуст', $this->get($this->base($workspace) . '/audit')->body);
    }
}
