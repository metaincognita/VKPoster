<?php

declare(strict_types=1);

namespace App\Http\Controllers\Discovery;

use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\ContentDiscovery\ContentDiscovery;
use App\Domain\ContentDiscovery\DiscoveryMaterialGateway;
use App\Domain\ContentDiscovery\DiscoveryRepository;
use App\Domain\Source\SourceException;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Radar views and guarded commands; discovery, scoring and content import remain independent domain services. */
final class RadarController
{
    public function __construct(private readonly ContentDiscovery $discovery, private readonly DiscoveryRepository $repository, private readonly DiscoveryMaterialGateway $gateway, private readonly MaterialRepository $materials, private readonly ContentProcessor $processor, private readonly View $view, private readonly FormFlash $flash, private readonly \App\Domain\Source\Selection\SemanticSelection $semantic, private readonly \App\Domain\Source\Selection\SelectionService $selectionService, private readonly \App\Domain\Content\Publishing\ContentDraftService $drafts, private readonly \App\Domain\Content\Automation\AutomationPolicies $automation)
    {
    }
    public function index(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $status = WorkspaceRequest::text($request->query['status'] ?? 'new');
        return $this->view->response('workspace/radar/index.twig', ['workspace' => $ctx, 'base' => '/w/' . $ctx->workspacePublicId . '/radar', 'automation_settings' => $this->automation->settings($ctx->workspaceId, null), 'automation_run' => $this->automation->latest($ctx->workspaceId, null), 'automation_url' => $this->base($request) . '/automation', 'automation_permission' => 'discovery.manage', 'status' => $status, 'clusters' => $this->repository->clusters($ctx, $status, WorkspaceRequest::text($request->query['ranking'] ?? 'trend')), 'runs' => $this->repository->runs($ctx), 'ranking' => WorkspaceRequest::text($request->query['ranking'] ?? 'trend'), 'semantic_settings' => $this->semantic->settings($ctx->workspaceId, null)['settings']->snapshot(), 'semantic_url' => $this->base($request) . '/semantic-settings', 'semantic_permission' => 'discovery.manage']);
    }
    public function semantic(Request $request): Response
    {
        try {
            $this->selectionService->saveSemantic(WorkspaceRequest::context($request), null, \App\Domain\Source\Selection\SemanticSettings::fromInput($request->body));
            $this->flash->toast('Смысловые настройки Радара сохранены.');
        } catch (SourceException $e) {
            $safe = [];
            foreach (['enabled', 'criteria', 'min_score', 'min_confidence', 'uncertain_mode'] as $key) {
                $safe[$key] = is_string($request->body[$key] ?? null) ? mb_substr($request->body[$key], 0, 4000) : '';
            }
            $this->flash->invalid($safe, [$e->field => [$e->getMessage()]]);
        }
        return Response::redirect($this->base($request));
    }
    public function refresh(Request $request): Response
    {
        $count = $this->discovery->refresh(WorkspaceRequest::context($request));
        $this->flash->toast('Новых материалов: ' . $count . '. Результаты providers — в истории обнаружения.', 'success');
        return Response::redirect($this->base($request));
    }
    public function ignore(Request $request): Response
    {
        $this->discovery->ignore(WorkspaceRequest::context($request), $this->parameter($request, 'clusterId'));
        $this->flash->toast('Тема проигнорирована.', 'success');
        return Response::redirect($this->base($request));
    }
    public function import(Request $request): Response
    {
        try {
            $id = $this->gateway->import(WorkspaceRequest::context($request), $this->parameter($request, 'discoveryId'));
            $this->flash->toast('Материал добавлен. Проверьте и примите его перед обработкой.', 'success');
            return Response::redirect($this->base($request) . '/materials/' . $id);
        } catch (HttpException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
            return Response::redirect($this->base($request));
        }
    }
    public function material(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $itemId = $this->parameter($request, 'itemId');
        $item = $this->repository->material($ctx, $itemId);
        $revision = MaterialRepository::revision($item, []);
        $selection = $this->materials->selection($ctx, null, (int) $item['id']);
        $hash = MaterialRepository::selectionHash($selection);
        $history = $this->materials->history($ctx, null, (int) $item['id']);
        foreach ($history as &$row) {
            $row['current'] = $row['revision_hash'] === $revision && $row['selection_hash'] === $hash && $row['status'] === 'completed' && ($selection['selection_status'] ?? '') === 'approved';
        }
        unset($row);
        return $this->view->response('workspace/sources/item.twig', ['workspace' => $ctx, 'base' => $this->base($request), 'material_origin' => 'discovery', 'material_base' => $this->base($request) . '/materials/' . $itemId, 'processing_permission' => 'discovery.manage', 'material' => $item, 'deterministic' => $this->selectionService->deterministic($ctx->workspaceId, null, $item, []), 'semantic_current' => $this->semantic->current($ctx->workspaceId, null, 'material', (int) $item['id'], $revision), 'semantic_history' => $this->semantic->history($ctx->workspaceId, 'material', (int) $item['id']), 'semantic_enabled' => $this->semantic->settings($ctx->workspaceId, null)['settings']->enabled, 'selection_detail' => $selection, 'draft_videos' => $this->drafts->videoChoices($ctx, null, $itemId), 'created_drafts' => $this->drafts->history($ctx, (int) $item['id']), 'selection_status' => $selection['selection_status'] ?? 'needs_review', 'selection_reason' => $selection['reason'] ?? '', 'revision' => $revision, 'history' => $history, 'settings' => $history === [] ? TextSettings::fromInput([])->form() : json_decode((string) $history[0]['settings_json'], true, 32, JSON_THROW_ON_ERROR)]);
    }
    public function decide(Request $request): Response
    {
        $decision = $request->input('decision');
        if ($decision !== 'approve' && $decision !== 'reject') {
            throw new HttpException(422, 'Выберите решение из списка.');
        }
        $this->gateway->decide(WorkspaceRequest::context($request), $this->parameter($request, 'itemId'), $decision === 'approve');
        return Response::redirect($this->base($request) . '/materials/' . $this->parameter($request, 'itemId'));
    }
    public function process(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $itemId = $this->parameter($request, 'itemId');
        $this->repository->material($ctx, $itemId);
        try {
            $status = $this->processor->process($ctx, null, $itemId, WorkspaceRequest::text($request->input('revision')), TextSettings::fromInput($request->body));
            $this->flash->toast($status === 'completed' ? 'Текст обработан. Результат сохранён.' : 'Обработка не завершена. Подробности — в истории.', $status === 'completed' ? 'success' : 'error');
        } catch (SourceException $e) {
            $safe = [];
            foreach (array_keys(TextSettings::fromInput([])->form()) as $key) {
                $safe[$key] = is_string($request->body[$key] ?? null) ? mb_substr($request->body[$key], 0, 10000) : '';
            }
            $this->flash->invalid($safe, [$e->field => [$e->getMessage()]]);
        } catch (HttpException $e) {
            if ($e->status !== 409 && $e->status !== 422) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect($this->base($request) . '/materials/' . $itemId);
    }
    private function base(Request $request): string
    {
        return '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/radar';
    }
    private function parameter(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');
        return is_array($params) ? WorkspaceRequest::text($params[$name] ?? null) : '';
    }
}
