<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\SourceService;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Sources\SourceController;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(SourceController::class)]
final class SourcePagesTest extends WorkspaceTestCase
{
    public function testGuestAndWorkspaceOutsiderCannotOpenSources(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $url = $this->base($workspace) . '/sources';
        self::assertStringContainsString('/login', (string) $this->get($url)->header('Location'));
        $this->actAs($this->createUser('outsider@example.com'));
        self::assertSame(404, $this->get($url)->status);
    }

    public function testOutdatedConsentBlocksEverySourcePageAndMutation(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Источник', 'telegram', '@sample_channel');
        $this->actAs($owner);
        $this->db->execute('UPDATE users SET consent_version = ? WHERE id = ?', ['2020-01-01', $owner->id]);
        $base = $this->base($workspace) . '/sources';
        foreach (['', '/new', '/' . $source->publicId, '/' . $source->publicId . '/edit'] as $suffix) {
            $response = $this->get($base . $suffix);
            self::assertSame(302, $response->status);
            self::assertSame('/consent', $response->header('Location'));
        }
        $audit = $this->auditActions($workspace);
        foreach (['', '/' . $source->publicId] as $suffix) {
            $response = $this->post($base . $suffix, ['name' => 'Не сохранять', 'type' => 'telegram', 'reference' => '@other_channel', 'enabled' => '1']);
            self::assertSame(302, $response->status);
            self::assertSame('/consent', $response->header('Location'));
        }
        self::assertSame($audit, $this->auditActions($workspace));
        self::assertSame(1, $this->db->table('sources')->count());
        $row = $this->db->table('sources')->where('id', '=', $source->id)->first();
        self::assertSame('Источник', $row['name'] ?? null);
        self::assertSame('sample_channel', $row['telegram_username'] ?? null);
        self::assertSame('0', (string) ($row['enabled'] ?? ''));
    }

    public function testEmptyListNewFormAndNavigationAreAvailableToOwner(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        $list = $this->get($base);
        self::assertSame(200, $list->status);
        self::assertStringContainsString('Пока нет источников', $list->body);
        self::assertStringContainsString('href="' . $base . '"', $list->body);
        $form = $this->get($base . '/new');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('name="enabled" value="1"', $form->body);
        self::assertStringNotContainsString('name="enabled" value="1" checked', $form->body);
        self::assertStringNotContainsString('name="status"', $form->body);
    }

    public function testListDetailAndEditEscapeNamesAndShowSelectionRules(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), '<script>alert(1)</script>', 'telegram', '@sample_channel');
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        foreach ([$base, $base . '/' . $source->publicId, $base . '/' . $source->publicId . '/edit'] as $url) {
            $page = $this->get($url);
            self::assertSame(200, $page->status);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body);
            self::assertStringNotContainsString('<script>alert(1)</script>', $page->body);
        }
        $detail = $this->get($base . '/' . $source->publicId);
        self::assertStringContainsString('Сохранить правила', $detail->body);
        self::assertStringContainsString('Не подключён', $detail->body);
        self::assertStringContainsString('https://t.me/sample_channel', $detail->body);
    }

    /** @return iterable<string, array{Role}> */
    public static function deniedRoles(): iterable
    {
        foreach ([Role::Editor, Role::Author, Role::Viewer, Role::Client] as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('deniedRoles')]
    public function testRolesWithoutSourcePermissionsCannotReadOrWrite(Role $role): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Источник', 'telegram', '@sample_channel');
        $this->actAs($this->memberOf($workspace, 'member@example.com', $role));
        $base = $this->base($workspace) . '/sources';
        foreach (['', '/new', '/' . $source->publicId, '/' . $source->publicId . '/edit'] as $suffix) {
            self::assertSame(403, $this->get($base . $suffix)->status);
        }
        self::assertSame(403, $this->post($base)->status);
        self::assertSame(403, $this->post($base . '/' . $source->publicId)->status);
        self::assertStringNotContainsString('href="' . $base . '"', $this->get($this->base($workspace))->body);
    }

    public function testForeignSourceIsNotFoundInsideAnotherOwnedWorkspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Источник', 'telegram', '@sample_channel');
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com');
        $this->actAs($other);
        $url = $this->base($otherWorkspace) . '/sources/' . $source->publicId;
        self::assertSame(404, $this->get($url)->status);
        self::assertSame(404, $this->get($url . '/edit')->status);
        self::assertSame(404, $this->post($url)->status);
    }
}
