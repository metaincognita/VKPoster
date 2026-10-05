<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\SourceService;
use App\Domain\Workspace\Role;
use App\Tests\Support\SourceSelectionTestCase;

final class SourceTextPagesTest extends SourceSelectionTestCase
{
    public function testMaterialPageProcessingHistoryEscapingAndStaleResult(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $this->ingest($source, [$this->message(10, '<script>Original</script>')]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $url = $this->base($workspace) . '/sources/' . $source->publicId . '/items/' . $item['public_id'];
        $this->actAs($owner);
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Демонстрационный провайдер', $page->body);
        self::assertStringContainsString('Пока нет результатов обработки', $page->body);
        self::assertStringContainsString('&lt;script&gt;Original&lt;/script&gt;', $page->body);
        self::assertStringNotContainsString('<script>Original</script>', $page->body);
        self::assertSame($url, $this->post($url . '/process', ['revision' => 'wrong'])->header('Location'));
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        $selection = $this->app->container()->get(SelectionService::class);
        $selection->decide($ctx, $source, $item['public_id'], true);
        $repo = $this->app->container()->get(MaterialRepository::class);
        $revision = MaterialRepository::revision($item, $repo->messages($ctx, $source, (int) $item['id']));
        self::assertSame($url, $this->post($url . '/process', ['revision' => $revision, 'mode' => 'rewrite'])->header('Location'));
        self::assertStringContainsString('Тестовая редакция:', $this->get($url)->body);
        self::assertSame($url, $this->post($url . '/process', ['revision' => $revision, 'mode' => 'custom', 'instruction' => 'KEEP INPUT', 'max_length' => '0'])->header('Location'));
        $invalid = $this->get($url);
        self::assertStringContainsString('KEEP INPUT', $invalid->body);
        self::assertStringContainsString('aria-invalid="true"', $invalid->body);
        self::assertSame(1, $this->db->table('source_text_processings')->count());
        $this->post($url . '/process', ['revision' => $revision]);
        self::assertSame(2, $this->db->table('source_text_processings')->count());
        $selection->decide($ctx, $source, $item['public_id'], false);
        self::assertStringContainsString('Результат сохранён для предыдущей ревизии', $this->get($url)->body);
        self::assertStringContainsString('Открыть материал', $this->get($this->base($workspace) . '/sources/' . $source->publicId)->body);
        self::assertSame(0, $this->db->table('posts')->count());
    }

    public function testAuthPermissionsCsrfConsentAndItemIdor(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(SourceService::class);
        $source = $service->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $other = $service->create($ctx, 'Other', 'telegram', '@other_channel', true);
        $this->ingest($source, [$this->message(10)]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $base = $this->base($workspace) . '/sources/';
        $url = $base . $source->publicId . '/items/' . $item['public_id'];
        self::assertSame('/login', $this->get($url)->header('Location'));
        self::assertSame('/login', $this->post($url . '/process')->header('Location'));
        $this->actAs($this->memberOf($workspace, 'editor@example.com', Role::Editor));
        self::assertSame(403, $this->get($url)->status);
        self::assertSame(403, $this->post($url . '/process')->status);
        $this->actAs($this->createUser('outsider@example.com'));
        self::assertSame(404, $this->get($url)->status);
        self::assertSame(404, $this->post($url . '/process')->status);
        $this->actAs($owner);
        self::assertSame(419, $this->request('POST', $url . '/process')->status);
        self::assertSame(404, $this->get($base . $other->publicId . '/items/' . $item['public_id'])->status);
        self::assertSame(404, $this->post($base . $other->publicId . '/items/' . $item['public_id'] . '/process')->status);
        self::assertSame(405, $this->get($url . '/process')->status);
        $this->db->execute('UPDATE users SET consent_version = ? WHERE id = ?', ['2020-01-01', $owner->id]);
        self::assertSame('/consent', $this->get($url)->header('Location'));
        self::assertSame('/consent', $this->post($url . '/process')->header('Location'));
        self::assertSame(0, $this->db->table('source_text_processings')->count());
    }
}
