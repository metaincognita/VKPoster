<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workspace;

use App\Domain\Audit\AuditActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Journal labels and one-line details built from audit metadata.
 */
#[CoversClass(AuditActions::class)]
final class AuditActionsTest extends TestCase
{
    public function testLabelsFallBackToTheTechnicalName(): void
    {
        self::assertSame('Изменена роль', AuditActions::label('member.role_changed'));
        self::assertSame('something.new', AuditActions::label('something.new'));
    }

    public function testDetailsUseRussianRoleNames(): void
    {
        self::assertSame('a@b.ru · Автор', AuditActions::describe('member.invited', ['email' => 'a@b.ru', 'role' => 'author']));
        self::assertSame('a@b.ru: Наблюдатель → Редактор', AuditActions::describe('member.role_changed', ['email' => 'a@b.ru', 'from' => 'viewer', 'to' => 'editor']));
        self::assertSame('«Зерно» · UTC', AuditActions::describe('workspace.updated', ['name' => 'Зерно', 'timezone' => 'UTC']));
        self::assertSame('личное пространство', AuditActions::describe('workspace.created', ['personal' => true]));
        self::assertSame('', AuditActions::describe('workspace.created', ['personal' => false]));
        self::assertSame('новый владелец: a@b.ru', AuditActions::describe('workspace.ownership_transferred', ['email' => 'a@b.ru']));
    }

    public function testUnknownActionsAndOddMetadataNeverLeakRawData(): void
    {
        self::assertSame('', AuditActions::describe('post.published', ['secret' => 'token-123']));
        self::assertSame('', AuditActions::describe('member.invited', ['email' => ['nested'], 'role' => null]));
        self::assertSame('x', AuditActions::describe('member.joined', ['role' => 'x']));
    }

    public function testGroupsAreListed(): void
    {
        self::assertArrayHasKey('member', AuditActions::groups());
        self::assertArrayHasKey('billing', AuditActions::groups());
    }

    public function testSourcesHaveLabelsDetailsAndTheirOwnGroup(): void
    {
        self::assertSame('Добавлен источник', AuditActions::label('source.created'));
        self::assertSame('Изменён источник', AuditActions::label('source.updated'));
        self::assertSame('«Новости»', AuditActions::describe('source.created', ['name' => 'Новости']));
        self::assertSame('«Новости»', AuditActions::describe('source.updated', ['name' => 'Новости']));
        self::assertSame('Источники', AuditActions::groups()['source']);
    }
}
