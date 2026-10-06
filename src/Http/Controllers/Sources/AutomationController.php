<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\Automation\AutomationPolicies;
use App\Domain\Source\SourceException;
use App\Domain\Source\SourceRepository;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/** Thin, authorized CSRF-protected policy command; no provider execution in a browser request. */
final class AutomationController
{
    public function __construct(private readonly AutomationPolicies $policies, private readonly SourceRepository $sources, private readonly FormFlash $flash)
    {
    }
    public function save(Request $request): Response
    {
        $ctx = WorkspaceRequest::context($request);
        $params = $request->attribute('route_params');
        $id = is_array($params) ? WorkspaceRequest::text($params['sourceId'] ?? null) : '';
        $source = $id === '' ? null : ($this->sources->find($ctx, $id) ?? throw new HttpException(404, 'Not found'));
        $url = '/w/' . $ctx->workspacePublicId . ($source === null ? '/radar' : '/sources/' . $source->publicId);
        try {
            $input = $source === null ? $request->body + ['provider_types' => []] : $request->body;
            $this->policies->save($ctx, $source, $input);
            $this->flash->toast('Настройки автоматизации сохранены. Публикация автоматически не запускается.');
        } catch (SourceException $e) {
            $safe = [];
            foreach (\App\Domain\Content\Automation\AutomationSettings::defaults() as $key => $default) {
                $value = $request->body[$key] ?? (is_bool($default) ? '0' : $default);
                if (is_scalar($value)) {
                    $safe['automation_' . $key] = mb_substr((string) $value, 0, 64);
                }
            }
            $this->flash->invalid($safe, ['automation_' . $e->field => [$e->getMessage()]]);
            $this->flash->toast('Проверьте настройки автоматизации и сохраните ещё раз.', 'error');
        }
        return Response::redirect($url);
    }
}
