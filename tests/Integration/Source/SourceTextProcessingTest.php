<?php

declare(strict_types=1);

namespace App\Tests\Integration\Source;

use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextProcessor;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Audit\AuditLog;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Source;
use App\Domain\Source\SourceService;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Ai\TextProvider;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

final class SourceTextProcessingTest extends SourceSelectionTestCase
{
    /** @return array{Source, WorkspaceContext, array<string, mixed>, string} */
    private function fixture(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $this->ingest($source, [$this->message(10, 'Исходный текст')]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $repository = $this->app->container()->get(MaterialRepository::class);
        return [$source, $ctx, $item, MaterialRepository::revision($item, $repository->messages($ctx, $source, (int) $item['id']))];
    }

    public function testApprovedOnlyHistorySettingsVersionsAndNoSourceMutation(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $service = $this->app->container()->get(ContentProcessor::class);
        foreach (['needs_review', 'rejected'] as $status) {
            if ($status === 'rejected') {
                $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], false);
            }
            try {
                $service->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
                self::fail('Unapproved material must not be processed');
            } catch (HttpException $e) {
                self::assertSame(409, $e->status);
            }
        }
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        self::assertSame('completed', $service->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([])));
        self::assertSame('completed', $service->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'rewrite'])));
        $history = $this->app->container()->get(MaterialRepository::class)->history($ctx, $source, (int) $item['id']);
        self::assertCount(2, $history);
        self::assertSame([2, 1], array_column($history, 'settings_version'));
        self::assertSame('Исходный текст', $history[1]['processed_text']);
        self::assertSame('Тестовая редакция: Исходный текст', $history[0]['processed_text']);
        self::assertSame('Исходный текст', $history[0]['original_text']);
        self::assertSame('rewrite', json_decode($history[0]['settings_json'], true)['mode']);
        self::assertSame($item, $this->db->table('source_items')->first());
        self::assertSame(0, $this->db->table('posts')->count());
        $now = $this->app->container()->get(Clock::class)->now();
        self::assertSame(2, $this->app->container()->get(\App\Domain\Admin\OperationalStats::class)->sourceText($now->modify('-1 day'), $now->modify('+1 day'))['completed']);
        $this->app->container()->get(\App\Domain\Analytics\MetricsAggregator::class)->day($now);
        $metric = $this->db->table('metrics_daily')->where('metric', '=', 'source_text_attempts')->where('dim', '=', 'completed')->first();
        self::assertNotNull($metric);
        self::assertSame(2, (int) $metric['value']);
        self::assertSame(2, $this->db->table('analytics_events')->where('name', '=', 'source_text_completed')->where('workspace_id', '=', $ctx->workspaceId)->count());
    }

    public function testOldRevisionCannotStartAndAlbumRevisionIncludesEachMember(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $this->ingest($source, [$this->message(10, 'Изменённый текст', extra: ['edit_date' => '2026-10-05T11:00:00Z'])]);
        try {
            $this->app->container()->get(ContentProcessor::class)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
            self::fail('Old revision must be refused');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        $this->ingest($source, [$this->message(20, 'Подпись', '777')]);
        $album = $this->db->table('source_items')->where('grouped_id', '=', '777')->first();
        self::assertNotNull($album);
        $repo = $this->app->container()->get(MaterialRepository::class);
        $first = MaterialRepository::revision($album, $repo->messages($ctx, $source, (int) $album['id']));
        $this->ingest($source, [$this->message(21, '', '777')]);
        $second = MaterialRepository::revision($album, $repo->messages($ctx, $source, (int) $album['id']));
        self::assertNotSame($first, $second);
    }

    public function testProviderFailureIsSafeAndPreviousResultSurvives(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $this->app->container()->get(ContentProcessor::class)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
        $provider = $this->createMock(TextProvider::class);
        $provider->method('name')->willReturn('fake');
        $provider->method('generate')->willThrowException(new \RuntimeException('secret-and-full-content-must-not-leak'));
        self::assertSame('failed', $this->processor($provider)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'edit'])));
        $history = $this->app->container()->get(MaterialRepository::class)->history($ctx, $source, (int) $item['id']);
        self::assertCount(2, $history);
        self::assertNull($history[0]['processed_text']);
        self::assertStringNotContainsString('secret', $history[0]['error']);
        self::assertNotNull($history[0]['finished_at']);
        self::assertSame('completed', $history[1]['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('concurrentChanges')]
    public function testConcurrentTelegramEditAndRejectionInvalidateProviderResult(bool $edit, bool $reject): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $selection = $this->app->container()->get(SelectionService::class);
        $selection->decide($ctx, $source, $item['public_id'], true);
        $provider = $this->createMock(TextProvider::class);
        $provider->method('name')->willReturn('fake');
        $provider->method('generate')->willReturnCallback(function () use ($source, $ctx, $item, $selection, $edit, $reject): string {
            // Simulates changes committed while the provider is outside the short transaction.
            if ($edit) {
                $this->ingest($source, [$this->message(10, 'Правка во время обработки', extra: ['edit_date' => '2026-10-05T12:00:00Z'])]);
            }
            if ($reject) {
                $selection->decide($ctx, $source, $item['public_id'], false);
            }
            return 'Previous output';
        });
        self::assertSame('stale', $this->processor($provider)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'edit'])));
        $row = $this->db->table('source_text_processings')->first();
        self::assertNotNull($row);
        self::assertSame('Previous output', $row['processed_text']);
        self::assertSame('Исходный текст', $row['original_text']);
        self::assertSame('stale', $row['status']);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function concurrentChanges(): iterable
    {
        yield 'edit only' => [true, false];
        yield 'rejection only' => [false, true];
        yield 'both' => [true, true];
    }

    public function testWorkspaceAndSourceIsolation(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        [$other, $workspace] = $this->ownerWithWorkspace('other@example.com');
        $foreign = $this->contextFor($workspace, $other);
        self::assertSame([], $this->app->container()->get(MaterialRepository::class)->history($foreign, $source, (int) $item['id']));
        try {
            $this->app->container()->get(ContentProcessor::class)->process($foreign, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
            self::fail('Foreign source refused');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
        $otherSource = $this->app->container()->get(SourceService::class)->create($ctx, 'Other', 'telegram', '@other_channel', true);
        try {
            $this->app->container()->get(ContentProcessor::class)->process($ctx, $otherSource, $item['public_id'], $revision, TextSettings::fromInput([]));
            self::fail('Foreign item refused');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testProcessingMigrationRollbackReplay(): void
    {
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_05_000023_create_source_text_processings.php';
        $discovery = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $origins = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $origins->down($this->db);
        $discovery->down($this->db);
        $migration->down($this->db);
        $migration->up($this->db);
        $discovery->up($this->db);
        $origins->up($this->db);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertContains('settings_version', array_column($this->db->select('SHOW COLUMNS FROM source_text_processings'), 'Field'));
    }

    private function processor(TextProvider $provider): ContentProcessor
    {
        $c = $this->app->container();
        return new ContentProcessor($this->db, $c->get(Clock::class), $c->get(MaterialRepository::class), new TextProcessor($provider), $c->get(AuditLog::class));
    }
}
