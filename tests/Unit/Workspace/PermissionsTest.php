<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workspace;

use App\Domain\Workspace\MemberPolicy;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\Role;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The role × permission matrix from `config/permissions.php` against the table in the stage plan, and the
 * hierarchy rules of `MemberPolicy`.
 */
#[CoversClass(Permissions::class)]
#[CoversClass(MemberPolicy::class)]
#[CoversClass(Role::class)]
final class PermissionsTest extends TestCase
{
    private const MATRIX = [
        'sources.view' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'sources.manage' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'workspace.billing' => ['owner' => true, 'admin' => false, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'workspace.delete' => ['owner' => true, 'admin' => false, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'workspace.transfer' => ['owner' => true, 'admin' => false, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'workspace.settings' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'members.manage' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'channels.manage' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'audit.view' => ['owner' => true, 'admin' => true, 'editor' => false, 'author' => false, 'viewer' => false, 'client' => false],
        'posts.publish' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => false, 'viewer' => false, 'client' => false],
        'posts.draft' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => false, 'client' => false],
        'channels.view' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => true, 'client' => false],
        'media.view' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => true, 'client' => false],
        'media.upload' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => false, 'client' => false],
        'media.manage' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => false, 'viewer' => false, 'client' => false],
        'calendar.view' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => true, 'client' => true],
        'analytics.view' => ['owner' => true, 'admin' => true, 'editor' => true, 'author' => true, 'viewer' => true, 'client' => true],
    ];

    private function permissions(): Permissions
    {
        $factory = require dirname(__DIR__, 3) . '/config/permissions.php';
        /** @var array<string, list<string>> $matrix */
        $matrix = $factory(new \App\Kernel\Env([]));

        return new Permissions($matrix);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function matrix(): iterable
    {
        foreach (self::MATRIX as $permission => $roles) {
            foreach ($roles as $role => $allowed) {
                yield $role . ' / ' . $permission => [$role, $permission, $allowed];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testMatrixMatchesThePlan(string $role, string $permission, bool $allowed): void
    {
        self::assertSame($allowed, $this->permissions()->allows(Role::from($role), $permission));
    }

    public function testEveryConfiguredPermissionIsCoveredByTheTable(): void
    {
        $names = $this->permissions()->names();
        sort($names);
        $expected = array_keys(self::MATRIX);
        sort($expected);

        self::assertSame($expected, $names, 'a new permission needs a row in this test');
    }

    public function testUnknownPermissionFailsLoudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->permissions()->allows(Role::Owner, 'members.manag');
    }

    public function testOwnerIsNeverAssignable(): void
    {
        self::assertNotContains(Role::Owner, Role::assignable());
        self::assertCount(5, Role::assignable());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function invitations(): iterable
    {
        yield 'owner invites admin' => ['owner', 'admin', true];
        yield 'owner invites client' => ['owner', 'client', true];
        yield 'owner cannot invite another owner' => ['owner', 'owner', false];
        yield 'admin invites editor' => ['admin', 'editor', true];
        yield 'admin cannot invite admin' => ['admin', 'admin', false];
        yield 'admin cannot invite owner' => ['admin', 'owner', false];
        yield 'editor cannot invite' => ['editor', 'viewer', false];
        yield 'client cannot invite' => ['client', 'client', false];
    }

    #[DataProvider('invitations')]
    public function testWhoMayInviteWhom(string $actor, string $invitedAs, bool $allowed): void
    {
        $policy = new MemberPolicy($this->permissions());

        self::assertSame($allowed, $policy->canInvite(Role::from($actor), Role::from($invitedAs)));
    }

    public function testRoleChangesFollowTheHierarchy(): void
    {
        $policy = new MemberPolicy($this->permissions());

        self::assertTrue($policy->canChangeRole(Role::Owner, Role::Admin, Role::Editor));
        self::assertTrue($policy->canChangeRole(Role::Admin, Role::Viewer, Role::Author));
        self::assertFalse($policy->canChangeRole(Role::Admin, Role::Admin, Role::Editor), 'an admin cannot demote another admin');
        self::assertFalse($policy->canChangeRole(Role::Admin, Role::Editor, Role::Admin), 'an admin cannot promote to admin');
        self::assertFalse($policy->canChangeRole(Role::Owner, Role::Owner, Role::Admin), 'the owner cannot be demoted');
        self::assertFalse($policy->canChangeRole(Role::Owner, Role::Admin, Role::Owner), 'ownership moves only by transfer');
        self::assertFalse($policy->canChangeRole(Role::Editor, Role::Viewer, Role::Author));
    }

    public function testRemovalAndLeaving(): void
    {
        $policy = new MemberPolicy($this->permissions());

        self::assertTrue($policy->canRemove(Role::Owner, Role::Admin));
        self::assertTrue($policy->canRemove(Role::Admin, Role::Client));
        self::assertFalse($policy->canRemove(Role::Admin, Role::Admin));
        self::assertFalse($policy->canRemove(Role::Admin, Role::Owner));
        self::assertFalse($policy->canRemove(Role::Owner, Role::Owner));
        self::assertFalse($policy->canLeave(Role::Owner));
        self::assertTrue($policy->canLeave(Role::Admin));
        self::assertTrue($policy->canTransfer(Role::Owner));
        self::assertFalse($policy->canTransfer(Role::Admin));
        self::assertSame([Role::Editor, Role::Author, Role::Viewer, Role::Client], $policy->assignableBy(Role::Admin));
    }
}
