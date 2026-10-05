<?php

declare(strict_types=1);

namespace App\Tests\Integration\Source;

use App\Domain\Source\Source;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceStatus;
use App\Domain\Source\SourceType;
use App\Tests\Support\WorkspaceTestCase;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Source::class)]
#[CoversClass(SourceType::class)]
#[CoversClass(SourceStatus::class)]
#[CoversClass(SourceRepository::class)]
#[CoversClass(SourceService::class)]
final class SourceDomainTest extends WorkspaceTestCase
{
    public function testCreationAndUpdateKeepSystemStatusWithoutCreatingJobsOrPosts(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(SourceService::class);
        $source = $service->create($context, ' Тестовый источник ', 'telegram', 'https://t.me/Sample_Channel');

        self::assertSame('Тестовый источник', $source->name);
        self::assertSame('sample_channel', $source->telegramUsername);
        self::assertSame('https://t.me/sample_channel', $source->telegramUrl());
        self::assertSame(SourceType::Telegram, $source->type);
        self::assertSame('Telegram', $source->type->label());
        self::assertSame(SourceStatus::NotConnected, $source->status);
        self::assertSame('Не подключён', $source->status->label());
        self::assertFalse($source->enabled);
        self::assertEquals($this->clock->now(), $source->createdAt);
        $this->clock->advance(60);
        $updated = $service->update($context, $source, 'Новое название', 'telegram', '@Other_Channel', true);
        self::assertSame($source->publicId, $updated->publicId);
        self::assertSame($source->id, $updated->id);
        self::assertSame('other_channel', $updated->telegramUsername);
        self::assertSame('Новое название', $updated->name);
        self::assertTrue($updated->enabled);
        self::assertSame(SourceStatus::NotConnected, $updated->status);
        self::assertEquals($source->createdAt, $updated->createdAt);
        self::assertEquals($this->clock->now(), $updated->updatedAt);
        self::assertSame(['billing.trial_started', 'workspace.created', 'source.created', 'source.updated'], $this->auditActions($workspace));
        foreach (['posts', 'jobs', 'channels'] as $table) {
            self::assertSame(0, $this->db->table($table)->count());
        }
    }

    public function testDuplicateCreateAndUpdateAreAtomicAndTheSameSourceCanBeEdited(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(SourceService::class);
        $repository = $this->app->container()->get(SourceRepository::class);
        $a = $service->create($context, 'Первый', 'telegram', '@Sample_Channel');
        $b = $service->create($context, 'Второй', 'telegram', '@Other_Channel');
        foreach ([null, $b] as $target) {
            try {
                if ($target === null) {
                    $service->create($context, 'Дубликат', 'telegram', 'https://t.me/SAMPLE_CHANNEL/', true);
                } else {
                    $service->update($context, $target, 'Дубликат', 'telegram', 'https://t.me/SAMPLE_CHANNEL/', true);
                }
                self::fail('A duplicate source was accepted.');
            } catch (SourceException $e) {
                self::assertSame('reference', $e->field);
            }
        }
        self::assertCount(2, $repository->all($context));
        self::assertSame('Второй', $repository->find($context, $b->publicId)?->name);
        self::assertSame(['billing.trial_started', 'workspace.created', 'source.created', 'source.created'], $this->auditActions($workspace));
        self::assertSame($a->id, $service->update($context, $a, 'Первый снова', 'telegram', '@sample_channel', false)->id);
    }

    public function testWorkspaceIsolationAlsoAppliesToUpdatesAndUniqueness(): void
    {
        [$ownerA, $workspaceA] = $this->ownerWithWorkspace();
        [$ownerB, $workspaceB] = $this->ownerWithWorkspace('other@example.com');
        $a = $this->contextFor($workspaceA, $ownerA);
        $b = $this->contextFor($workspaceB, $ownerB);
        $service = $this->app->container()->get(SourceService::class);
        $repository = $this->app->container()->get(SourceRepository::class);
        $source = $service->create($a, 'Первый', 'telegram', '@sample_channel');
        self::assertNull($repository->find($b, $source->publicId));
        self::assertSame([], $repository->all($b));
        $other = $service->create($b, 'Второй', 'telegram', '@sample_channel');
        self::assertNotSame($source->publicId, $other->publicId);
        try {
            $service->update($b, $source, 'Чужое изменение', 'telegram', '@hijacked_channel', true);
            self::fail('Cross-workspace update was accepted.');
        } catch (LogicException) {
            self::assertSame('Первый', $repository->find($a, $source->publicId)?->name);
            self::assertSame(['billing.trial_started', 'workspace.created', 'source.created'], $this->auditActions($workspaceB));
        }
        $this->db->execute('DELETE FROM workspaces WHERE id = ?', [$workspaceA->id]);
        self::assertCount(1, $repository->all($b));
        self::assertSame(1, $this->db->table('sources')->count());
    }

    public function testUniqueIndexProtectsDirectAndCaseVariantWrites(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $repository = $this->app->container()->get(SourceRepository::class);
        $repository->create($context, 'Первый', SourceType::Telegram, 'sample_channel', false);
        $this->expectException(PDOException::class);
        $repository->create($context, 'Второй', SourceType::Telegram, 'SAMPLE_CHANNEL', false);
    }

    public function testInvalidDomainInputCannotBePersisted(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(SourceService::class);
        foreach ([['', 'telegram', '@sample'], [str_repeat('я', 256), 'telegram', '@sample'], ['Имя', 'rss', '@sample'], ['Имя', 'telegram', 'https://example.com']] as [$name, $type, $reference]) {
            try {
                $service->create($context, $name, $type, $reference);
                self::fail('Invalid source was persisted.');
            } catch (SourceException) {
                self::assertSame(0, $this->db->table('sources')->count());
            }
        }
    }
}
