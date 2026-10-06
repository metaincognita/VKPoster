<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\ImageProcessing\ImageRepository;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\VideoProcessing\VideoRepository;
use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Content\VideoProcessing\VideoWorkflow;
use App\Domain\Source\Source;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/** Thin workspace video UI; generation and snapshot checks belong to the independent domain module. */
final class VideoProcessingController
{
    public function __construct(private readonly SourceRepository $sources, private readonly MaterialRepository $materials, private readonly ImageRepository $images, private readonly VideoRepository $videos, private readonly VideoWorkflow $workflow, private readonly View $view, private readonly FormFlash $flash, private readonly \App\Kernel\Config $config)
    {
    }
    public function show(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $itemId = $this->parameter($request, 'itemId');
        $item = $this->materials->item($ctx, $source, $itemId);
        $revision = MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id']));
        $selection = $this->materials->selection($ctx, $source, (int) $item['id']);
        $hash = MaterialRepository::selectionHash($selection);
        $approved = ($selection['selection_status'] ?? '') === 'approved';
        $texts = ['' => 'Исходный текст'];
        foreach ($this->materials->history($ctx, $source, (int) $item['id']) as $text) {
            if ($approved && $text['status'] === 'completed' && $text['revision_hash'] === $revision && $text['selection_hash'] === $hash) {
                $texts[(string) $text['public_id']] = 'Обработанный текст · версия ' . $text['settings_version'];
            }
        }
        $images = ['' => 'Без изображения'];
        foreach ($this->images->history($ctx, $source, (int) $item['id']) as $image) {
            if ($approved && $image['status'] === 'completed' && $image['revision_hash'] === $revision && $image['selection_hash'] === $hash) {
                foreach ($image['variants'] as $variant) {
                    if ($image['selected_variant'] === $variant['public_id']) {
                        $images[(string) $variant['public_id']] = 'Выбранное изображение · Telegram ' . $image['telegram_message_id'] . ' · ' . $variant['width'] . '×' . $variant['height'];
                    }
                }
            }
        }
        $history = $this->videos->history($ctx, $source, (int) $item['id']);
        foreach ($history as &$run) {
            $run['current'] = $this->workflow->current($ctx, $source, $itemId, $run);
            $run['settings'] = json_decode((string) $run['settings_json'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($run);
        return $this->view->response('workspace/sources/videos.twig', ['workspace' => $ctx, 'source' => $source, 'material' => $item, 'revision' => $revision, 'approved' => $approved, 'texts' => $texts, 'images' => $images, 'fake_provider' => $this->config->string('content_providers.video', 'fake') === 'fake', 'history' => $history, 'settings' => VideoSettings::fromInput([])->form(), 'base' => '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $itemId]);
    }
    public function request(Request $request): Response
    {
        return $this->action($request, 'request');
    }
    public function run(Request $request): Response
    {
        return $this->action($request, 'run');
    }
    public function choose(Request $request): Response
    {
        return $this->action($request, 'choose');
    }
    private function action(Request $request, string $action): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $source = $this->source($request);
        $itemId = $this->parameter($request, 'itemId');
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $itemId . '/videos';
        try {
            if ($action === 'request') {
                $this->workflow->request($ctx, $source, $itemId, WorkspaceRequest::text($request->input('revision')), VideoSettings::fromInput($request->body));
                $this->flash->toast('Задание создано. Запустите генерацию в истории.', 'success');
            } elseif ($action === 'run') {
                $status = $this->workflow->run($ctx, $source, $itemId, WorkspaceRequest::text($request->input('job')));
                $this->flash->toast($status === 'completed' ? 'Обработка завершена. Результат доступен в истории.' : 'Состояние задания: ' . ($status === 'processing' ? 'выполняется' : 'ошибка. Подробности — в истории.'), $status === 'failed' ? 'error' : 'success');
            } else {
                $this->workflow->choose($ctx, $source, $itemId, WorkspaceRequest::text($request->input('job')));
                $this->flash->toast('Итоговая версия выбрана.', 'success');
            }
        } catch (SourceException $e) {
            $safe = [];
            foreach (array_keys(VideoSettings::fromInput([])->form()) as $key) {
                $safe[$key] = is_string($request->body[$key] ?? null) ? mb_substr($request->body[$key], 0, 4000) : '';
            }
            $this->flash->invalid($safe, [$e->field => [$e->getMessage()]]);
        } catch (HttpException $e) {
            if ($e->status !== 409 && $e->status !== 422) {
                throw $e;
            }
            $this->flash->toast($e->getMessage(), 'error');
        }
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
