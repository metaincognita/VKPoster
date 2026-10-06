<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Source\Source;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Material detail and CSRF-protected text processing endpoints; domain owns approval and revision checks. */
final class ProcessingController
{
    public function __construct(private readonly SourceRepository $sources, private readonly MaterialRepository $materials, private readonly ContentProcessor $processor, private readonly View $view, private readonly FormFlash $flash, private readonly \App\Domain\Source\Selection\SemanticSelection $semantic, private readonly \App\Domain\Source\Selection\SelectionService $selectionService, private readonly \App\Domain\Content\Publishing\ContentDraftService $drafts)
    {
    }

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $item = $this->materials->item($context, $source, $this->parameter($request, 'itemId'));
        $history = $this->materials->history($context, $source, (int) $item['id']);
        $revision = MaterialRepository::revision($item, $this->materials->messages($context, $source, (int) $item['id']));
        $selection = $this->materials->selection($context, $source, (int) $item['id']);
        $selectionHash = MaterialRepository::selectionHash($selection);
        foreach ($history as &$attempt) {
            $attempt['current'] = $attempt['status'] === 'completed' && $attempt['revision_hash'] === $revision && $attempt['selection_hash'] === $selectionHash && ($selection['selection_status'] ?? '') === 'approved';
        }
        unset($attempt);
        $deterministic = $this->selectionService->deterministic($context->workspaceId, $source->id, $item, $this->materials->messages($context, $source, (int) $item['id']));
        return $this->view->response('workspace/sources/item.twig', ['workspace' => $context, 'source' => $source, 'material' => $item,
            'base' => '/w/' . $context->workspacePublicId . '/sources', 'history' => $history, 'revision' => $revision,
            'deterministic' => $deterministic,
            'semantic_current' => $this->semantic->current($context->workspaceId, $source->id, 'material', (int) $item['id'], $revision, $deterministic),
            'semantic_history' => $this->semantic->history($context->workspaceId, 'material', (int) $item['id']),
            'semantic_enabled' => $this->semantic->settings($context->workspaceId, $source->id)['settings']->enabled,
            'selection_detail' => $selection,
            'draft_videos' => $this->drafts->videoChoices($context, $source, (string) $item['public_id']), 'created_drafts' => $this->drafts->history($context, (int) $item['id']), 'selection_status' => $selection['selection_status'] ?? 'needs_review',
            'settings' => $history === [] ? TextSettings::fromInput([])->form() : json_decode((string) $history[0]['settings_json'], true, 32, JSON_THROW_ON_ERROR)]);
    }

    public function process(Request $request): Response
    {
        $source = $this->source($request);
        $url = '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $this->parameter($request, 'itemId');
        try {
            $settings = TextSettings::fromInput($request->body);
            $status = $this->processor->process(WorkspaceRequest::context($request), $source, $this->parameter($request, 'itemId'), WorkspaceRequest::text($request->input('revision')), $settings);
        } catch (SourceException $e) {
            $safe = [];
            foreach (array_keys(TextSettings::fromInput([])->form()) as $key) {
                $safe[$key] = is_string($request->body[$key] ?? null) ? mb_substr($request->body[$key], 0, 10000) : '';
            }
            $this->flash->invalid($safe, [$e->field => [$e->getMessage()]]);
            return Response::redirect($url);
        } catch (HttpException $e) {
            if ($e->status !== 409 && $e->status !== 422) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
            return Response::redirect($url);
        }
        $this->flash->toast($status === 'completed' ? 'Текст обработан. Результат сохранён.' : 'Обработка не завершена. Подробности — в истории.', $status === 'completed' ? 'success' : 'error');
        return Response::redirect($url);
    }

    private function source(Request $request): Source
    {
        return $this->sources->find(WorkspaceRequest::context($request), $this->parameter($request, 'sourceId')) ?? throw new HttpException(404, 'Not found');
    }

    private function parameter(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');
        return is_array($params) ? WorkspaceRequest::text($params[$name] ?? null) : '';
    }
}
