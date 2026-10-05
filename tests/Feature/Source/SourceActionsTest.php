<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\SourceRepository;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceStatus;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Sources\SourceController;
use App\Tests\Support\WorkspaceTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SourceController::class)]
final class SourceActionsTest extends WorkspaceTestCase
{
    public function testOwnerCreatesAndAdminEditsConfigurationButCannotSetStatus(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        $created = $this->post($base, ['name' => 'Источник', 'type' => 'telegram', 'reference' => 'https://t.me/Sample_Channel', 'status' => 'active', 'selection_rules_json' => '{"ai":true}']);
        self::assertSame(302, $created->status);
        $context = $this->contextFor($workspace, $owner);
        $repository = $this->app->container()->get(SourceRepository::class);
        $source = $repository->all($context)[0];
        self::assertSame($base . '/' . $source->publicId, $created->header('Location'));
        self::assertFalse($source->enabled);
        self::assertSame(SourceStatus::NotConnected, $source->status);
        self::assertStringContainsString('Источник добавлен.', $this->get($created->header('Location'))->body);
        $this->actAs($this->memberOf($workspace, 'admin@example.com', Role::Admin));
        $saved = $this->post($base . '/' . $source->publicId, ['name' => 'Изменён', 'type' => 'telegram', 'reference' => '@Other_Channel', 'enabled' => '1', 'status' => 'active']);
        self::assertSame(302, $saved->status);
        $updated = $repository->find($context, $source->publicId);
        self::assertTrue($updated?->enabled);
        self::assertSame(SourceStatus::NotConnected, $updated->status);
        self::assertSame('Изменён', $updated->name);
        self::assertStringContainsString('Источник сохранён.', $this->get((string) $saved->header('Location'))->body);
        self::assertSame(200, $this->get($base . '/' . $source->publicId . '/edit')->status);
        self::assertSame(['billing.trial_started', 'workspace.created', 'source.created', 'source.updated'], $this->auditActions($workspace));
    }

    public function testDuplicateOnCreateOrEditReturnsAFieldErrorAndKeepsInput(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(SourceService::class);
        $service->create($context, 'Первый', 'telegram', '@sample_channel');
        $other = $service->create($context, 'Второй', 'telegram', '@other_channel', true);
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        foreach (['' => '/new', '/' . $other->publicId => '/' . $other->publicId . '/edit'] as $suffix => $form) {
            $response = $this->post($base . $suffix, ['name' => 'Сохраните это имя', 'type' => 'telegram', 'reference' => 'https://t.me/SAMPLE_CHANNEL/']);
            self::assertSame($base . $form, $response->header('Location'));
            $page = $this->get($base . $form);
            self::assertSame(200, $page->status);
            self::assertStringContainsString('уже добавлен', $page->body);
            self::assertStringContainsString('value="Сохраните это имя"', $page->body);
            self::assertStringContainsString('value="https://t.me/SAMPLE_CHANNEL/"', $page->body);
            self::assertStringNotContainsString('name="enabled" value="1" checked', $page->body);
        }
        self::assertSame(2, $this->db->table('sources')->count());
    }

    public function testInvalidInputIncludingArraysNeverWritesSources(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        $valid = ['name' => 'Источник', 'type' => 'telegram', 'reference' => '@sample_channel'];
        foreach ([['name' => ''], ['name' => str_repeat('я', 256)], ['type' => 'rss'], ['reference' => 'https://t.me/sample/123'], ['name' => ['nested']], ['type' => ['telegram']], ['reference' => ['sample']], ['enabled' => ['1']], ['enabled' => 'yes']] as $invalid) {
            $response = $this->post($base, array_replace($valid, $invalid));
            self::assertSame($base . '/new', $response->header('Location'));
            $page = $this->get($base . '/new');
            self::assertSame(200, $page->status);
            self::assertStringContainsString('role="alert"', $page->body);
            self::assertSame(0, $this->db->table('sources')->count());
        }
    }

    public function testCreateAndUpdateNeedCsrfAndDisabledCanBeSavedAgain(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($context, 'Источник', 'telegram', '@sample_channel', true);
        $this->actAs($owner);
        $base = $this->base($workspace) . '/sources';
        foreach ([$base, $base . '/' . $source->publicId] as $url) {
            self::assertSame(419, $this->request('POST', $url)->status);
        }
        $this->post($base . '/' . $source->publicId, ['name' => 'Источник', 'type' => 'telegram', 'reference' => '@sample_channel']);
        self::assertFalse($this->app->container()->get(SourceRepository::class)->find($context, $source->publicId)?->enabled);
        self::assertSame(1, $this->db->table('sources')->count());
    }
}
