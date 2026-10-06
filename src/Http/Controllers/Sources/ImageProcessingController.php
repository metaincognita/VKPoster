<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\ImageProcessing\ImageFiles;
use App\Domain\Content\ImageProcessing\ImageRepository;
use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Source;
use App\Domain\Source\SourceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Workspace UI and guarded image commands; pixel work belongs to the independent domain module. */
final class ImageProcessingController
{
    public function __construct(private readonly SourceRepository $sources, private readonly MaterialRepository $materials, private readonly ImageRepository $repository, private readonly ImageWorkflow $images, private readonly ImageFiles $files, private readonly View $view, private readonly FormFlash $flash)
    {
    }
    public function show(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $item = $this->materials->item($ctx, $source, $this->parameter($request, 'itemId'));
        $revision = MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id']));
        $history = $this->repository->history($ctx, $source, (int) $item['id']);
        foreach ($history as &$run) {
            $run['current'] = $this->images->current($ctx, $source, $item, (string) $run['revision_hash'], (string) $run['selection_hash']);
        }
        unset($run);
        return $this->view->response('workspace/sources/images.twig', ['workspace' => $ctx, 'source' => $source, 'material' => $item, 'revision' => $revision, 'approved' => ($this->materials->selection($ctx, $source, (int) $item['id'])['selection_status'] ?? '') === 'approved', 'history' => $history, 'base' => '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id']]);
    }
    public function request(Request $request): Response
    {
        return $this->action($request, false);
    }
    public function choose(Request $request): Response
    {
        return $this->action($request, true);
    }
    public function preview(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $item = $this->materials->item($ctx, $source, $this->parameter($request, 'itemId'));
        $variant = $this->repository->variant($ctx, $source, (int) $item['id'], $this->parameter($request, 'variantId'));
        return Response::stream($this->files->preview((string) $variant['preview_key']), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
    private function action(Request $request, bool $choose): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $itemId = $this->parameter($request, 'itemId');
        try {
            if ($choose) {
                $this->images->choose($ctx, $source, $itemId, WorkspaceRequest::text($request->input('variant')), $request->input('confirmed') === '1');
                $this->flash->toast('Изображение выбрано.', 'success');
            } else {
                $count = $this->images->request($ctx, $source, $itemId, WorkspaceRequest::text($request->input('revision')));
                $this->flash->toast($count > 0 ? 'Задания сохранены. Запущенный reader загрузит изображения.' : 'Нет новых заданий: фото уже ожидают загрузки либо отсутствуют.', 'success');
            }
        } catch (HttpException $e) {
            if ($e->status !== 409 && $e->status !== 422) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
        }
        return Response::redirect('/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $itemId . '/images');
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
