<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\SourceService;
use App\Domain\Workspace\Role;
use App\Tests\Support\SourceSelectionTestCase;

final class SourceSelectionPagesTest extends SourceSelectionTestCase
{
    public function testRuleFormManualDecisionsAndFiltering(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Source', 'telegram', '@sample_channel', true);
        $this->ingest($source, [$this->message(10, '<script>Наука</script> #космос')]);
        $this->ingest($source, [$this->message(11, 'Спорт')]);
        $this->actAs($owner);
        $url = $this->base($workspace) . '/sources/' . $source->publicId;
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Требует проверки', $page->body);
        self::assertStringContainsString('Сохранить правила', $page->body);
        self::assertStringNotContainsString('Правила отбора появятся позже', $page->body);
        self::assertSame($url, $this->post($url . '/selection-rules', ['include_keywords' => 'НАУКА', 'include_hashtags' => '#космос', 'content_types' => ['text'], 'links' => 'no', 'forwarded' => 'no'])->header('Location'));
        $accepted = $this->get($url . '?selection_status=approved');
        self::assertSame(200, $accepted->status);
        self::assertStringContainsString('&lt;script&gt;Наука&lt;/script&gt;', $accepted->body);
        self::assertStringNotContainsString('<script>Наука</script>', $accepted->body);
        self::assertStringNotContainsString('Спорт', $accepted->body);
        self::assertStringContainsString('Спорт', $this->get($url . '?selection_status=rejected')->body);
        self::assertStringContainsString('Нет материалов с выбранным статусом', $this->get($url . '?selection_status=needs_review')->body);
        self::assertSame(422, $this->get($url . '?selection_status=invalid')->status);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $action = $url . '/items/' . $item['public_id'] . '/selection';
        self::assertSame(302, $this->post($action, ['decision' => 'rejected'])->status);
        self::assertStringContainsString('Отклонено вручную.', $this->get($url)->body);
        self::assertSame(302, $this->post($action, ['decision' => 'approved'])->status);
        self::assertStringContainsString('Принято вручную.', $this->get($url)->body);
        self::assertSame(422, $this->post($action, ['decision' => 'needs_review'])->status);
        self::assertSame(405, $this->get($action)->status);
    }

    public function testInvalidRulesPreserveInputAndDoNotChangeSavedRules(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Source', 'telegram', '@sample_channel', true);
        $this->actAs($owner);
        $url = $this->base($workspace) . '/sources/' . $source->publicId;
        self::assertSame(302, $this->post($url . '/selection-rules', ['include_keywords' => 'KEEP INPUT', 'include_hashtags' => '#invalid tag'])->status);
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('KEEP INPUT', $page->body);
        self::assertStringContainsString('#invalid tag', $page->body);
        self::assertStringContainsString('aria-invalid="true"', $page->body);
        self::assertSame(0, $this->db->table('source_selection_rules')->count());
        self::assertSame(302, $this->post($url . '/selection-rules', ['include_keywords' => [], 'content_types' => 'photo'])->status);
        self::assertSame(200, $this->get($url)->status);
    }

    public function testPermissionsCsrfConsentAndSourceItemIdor(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $service = $this->app->container()->get(SourceService::class);
        $source = $service->create($this->contextFor($workspace, $owner), 'Source', 'telegram', '@sample_channel', true);
        $other = $service->create($this->contextFor($workspace, $owner), 'Other', 'telegram', '@other_channel', true);
        $this->ingest($other, [$this->message(10)]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $url = $this->base($workspace) . '/sources/' . $source->publicId;
        $action = $url . '/items/' . $item['public_id'] . '/selection';
        self::assertSame('/login', $this->post($url . '/selection-rules')->header('Location'));
        $this->actAs($this->memberOf($workspace, 'member@example.com', Role::Editor));
        self::assertSame(403, $this->post($url . '/selection-rules')->status);
        self::assertSame(403, $this->post($action, ['decision' => 'approved'])->status);
        $this->actAs($this->createUser('outsider@example.com'));
        self::assertSame(404, $this->post($url . '/selection-rules')->status);
        self::assertSame(404, $this->post($action, ['decision' => 'approved'])->status);
        $this->actAs($owner);
        self::assertSame(419, $this->request('POST', $url . '/selection-rules')->status);
        self::assertSame(419, $this->request('POST', $action, ['decision' => 'approved'])->status);
        self::assertSame(404, $this->post($action, ['decision' => 'approved'])->status);
        [, $otherWorkspace] = $this->ownerWithWorkspace('second@example.com');
        self::assertSame(404, $this->post($this->base($otherWorkspace) . '/sources/' . $source->publicId . '/selection-rules')->status);
        $this->db->execute('UPDATE users SET consent_version = ? WHERE id = ?', ['2020-01-01', $owner->id]);
        self::assertSame('/consent', $this->post($url . '/selection-rules')->header('Location'));
        self::assertSame('/consent', $this->post($action, ['decision' => 'approved'])->header('Location'));
        self::assertSame('needs_review', $this->decision()['selection_status']);
        self::assertSame(0, $this->db->table('source_selection_rules')->count());
    }
}
