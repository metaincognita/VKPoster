<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Source\Source;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/** CSRF-protected workspace rule and manual selection mutations, authorized by sources.manage. */
final class SelectionController
{
    public function __construct(private readonly SourceRepository $sources, private readonly SelectionService $selection, private readonly FormFlash $flash)
    {
    }

    public function rules(Request $request): Response
    {
        $source = $this->source($request);
        $input = $request->body;
        try {
            $rules = SelectionRules::fromInput($input);
            $this->selection->saveRules(WorkspaceRequest::context($request), $source, $rules);
        } catch (SourceException $e) {
            $safe = [];
            foreach (['include_keywords', 'exclude_keywords', 'include_hashtags', 'exclude_hashtags', 'links', 'forwarded'] as $key) {
                $safe[$key] = is_string($input[$key] ?? null) ? $input[$key] : '';
            }
            $safe['content_types'] = is_array($input['content_types'] ?? null) ? array_values(array_filter($input['content_types'], static fn (mixed $v): bool => is_string($v) && in_array($v, ['text', 'photo', 'album'], true))) : [];
            $this->flash->invalid($safe, [$e->field => [$e->getMessage()]]);
            return Response::redirect($this->url($request, $source));
        }
        $this->flash->toast('Правила сохранены. Автоматические решения пересчитаны.');
        return Response::redirect($this->url($request, $source));
    }

    public function decide(Request $request): Response
    {
        $source = $this->source($request);
        $decision = $request->input('decision');
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'Invalid selection decision');
        }
        $this->selection->decide(WorkspaceRequest::context($request), $source, $this->parameter($request, 'itemId'), $decision === 'approved');
        $this->flash->toast($decision === 'approved' ? 'Материал принят.' : 'Материал отклонён.');
        return Response::redirect($this->url($request, $source));
    }

    private function source(Request $request): Source
    {
        return $this->sources->find(WorkspaceRequest::context($request), $this->parameter($request, 'sourceId')) ?? throw new HttpException(404, 'Not found');
    }

    private function parameter(Request $request, string $key): string
    {
        $params = $request->attribute('route_params');
        return is_array($params) ? WorkspaceRequest::text($params[$key] ?? null) : '';
    }

    private function url(Request $request, Source $source): string
    {
        return '/w/' . WorkspaceRequest::context($request)->workspacePublicId . '/sources/' . $source->publicId;
    }
}
