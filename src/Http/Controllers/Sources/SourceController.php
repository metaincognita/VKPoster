<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Source\Source;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Domain\Source\SourceItemRepository;
use App\Domain\Source\SourceService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Workspace source configuration pages. All routes are authorized and mutations require CSRF. */
final class SourceController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly SourceRepository $sources,
        private readonly SourceService $service,
        private readonly SourceItemRepository $items,
        private readonly \App\Domain\Source\Selection\SelectionService $selection,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->view->response('workspace/sources/index.twig', [
            'workspace' => $context,
            'base' => $this->base($request),
            'sources' => $this->sources->all($context),
        ]);
    }

    public function new(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function show(Request $request): Response
    {
        return $this->view->response('workspace/sources/show.twig', [
            'workspace' => WorkspaceRequest::context($request),
            'base' => $this->base($request),
            'source' => $this->source($request),
            'incoming_items' => $this->items->recent(WorkspaceRequest::context($request), $this->source($request), WorkspaceRequest::text($request->query['selection_status'] ?? null)),
            'selection_filter' => WorkspaceRequest::text($request->query['selection_status'] ?? null),
            'selection_rules' => ($this->selection->rules(WorkspaceRequest::context($request), $this->source($request)) ?? \App\Domain\Source\Selection\SelectionRules::fromInput([]))->form(),
            'rules_configured' => $this->selection->rules(WorkspaceRequest::context($request), $this->source($request)) !== null,
        ]);
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, $this->source($request));
    }

    public function create(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        return $this->save($request, $this->source($request));
    }

    private function form(Request $request, ?Source $source): Response
    {
        return $this->view->response('workspace/sources/form.twig', [
            'workspace' => WorkspaceRequest::context($request),
            'base' => $this->base($request),
            'source' => $source,
        ]);
    }

    private function save(Request $request, ?Source $source): Response
    {
        $input = [
            'name' => WorkspaceRequest::text($request->input('name')),
            'type' => WorkspaceRequest::text($request->input('type')),
            'reference' => WorkspaceRequest::text($request->input('reference')),
            'enabled' => $request->input('enabled') === '1' ? '1' : '0',
        ];
        $base = $this->base($request);
        try {
            if (!in_array($request->input('enabled'), [null, '0', '1'], true)) {
                throw new SourceException('Укажите, включён ли источник, и сохраните форму ещё раз.', 'form');
            }
            $context = WorkspaceRequest::context($request);
            $saved = $source === null
                ? $this->service->create($context, $input['name'], $input['type'], $input['reference'], $input['enabled'] === '1')
                : $this->service->update($context, $source, $input['name'], $input['type'], $input['reference'], $input['enabled'] === '1');
        } catch (SourceException $e) {
            $this->flash->invalid($input, [$e->field => [$e->getMessage()]]);

            return Response::redirect($base . ($source === null ? '/new' : '/' . $source->publicId . '/edit'));
        }
        $this->flash->toast($source === null ? 'Источник добавлен.' : 'Источник сохранён.');

        return Response::redirect($base . '/' . $saved->publicId);
    }

    private function source(Request $request): Source
    {
        $params = $request->attribute('route_params');
        $publicId = is_array($params) ? WorkspaceRequest::text($params['sourceId'] ?? null) : '';

        return $this->sources->find(WorkspaceRequest::context($request), $publicId)
            ?? throw new HttpException(404, 'Not found');
    }

    private function base(Request $request): string
    {
        return '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/sources';
    }
}
