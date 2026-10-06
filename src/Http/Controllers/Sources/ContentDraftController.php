<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\Publishing\ContentDraftService;
use App\Domain\Media\MediaException;
use App\Domain\Post\PostException;
use App\Domain\Source\SourceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Storage\StorageException;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/** CSRF-protected material export; all approval, provenance and library decisions stay in the domain. */
final class ContentDraftController
{
    public function __construct(private readonly ContentDraftService $drafts, private readonly SourceRepository $sources, private readonly FormFlash $flash)
    {
    }

    public function create(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $params = is_array($params) ? $params : [];
        $sourceId = WorkspaceRequest::text($params['sourceId'] ?? '');
        $source = $sourceId === '' ? null : ($this->sources->find($ctx, $sourceId) ?? throw new HttpException(404, 'Not found'));
        $itemId = WorkspaceRequest::text($params['itemId'] ?? '');
        $back = '/w/' . $ctx->workspacePublicId . ($source === null ? '/radar/materials/' . $itemId : '/sources/' . $source->publicId . '/items/' . $itemId);
        try {
            $post = $this->drafts->create($ctx, $source, $itemId, WorkspaceRequest::text($request->input('revision')), WorkspaceRequest::text($request->input('text_version')), WorkspaceRequest::text($request->input('video_version')));
            $this->flash->toast('Черновик готов. Его можно редактировать в обычном редакторе.');
            return Response::redirect('/w/' . $ctx->workspacePublicId . '/posts/' . $post->publicId . ($post->status->isEditablePlan() ? '/edit' : ''));
        } catch (HttpException $e) {
            if ($e->status !== 409 && $e->status !== 422) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
        } catch (PostException|MediaException $e) {
            $this->flash->toast($e->getMessage(), 'error');
        } catch (StorageException) {
            $this->flash->toast('Не удалось прочитать медиа. Повторите попытку.', 'error');
        }
        $this->flash->invalid(['text_version' => mb_substr(WorkspaceRequest::text($request->input('text_version')), 0, 26), 'video_version' => mb_substr(WorkspaceRequest::text($request->input('video_version')), 0, 26)], []);
        return Response::redirect($back);
    }
}
