<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/** Bounded binary transfer over the existing dedicated reader trust boundary. Never logs payloads or exceptions. */
final class ImageReaderController
{
    public function __construct(private readonly Config $config, private readonly ImageWorkflow $images)
    {
    }
    public function jobs(Request $request): Response
    {
        $this->authenticate($request);
        $raw = $request->query['source_ids'] ?? null;
        if ($raw !== null && (!is_string($raw) || strlen($raw) > 27000)) {
            throw new HttpException(422, 'Invalid reader sources');
        }
        $ids = $raw === null ? null : ($raw === '' ? [] : explode(',', $raw));
        return Response::json(['version' => 1, 'jobs' => $this->images->jobs($ids)]);
    }
    public function result(Request $request): Response
    {
        $this->authenticate($request);
        if (strlen($request->rawBody) > 22500000) {
            throw new HttpException(413, 'Too large');
        }
        return Response::json($this->images->accept($request->json()));
    }
    private function authenticate(Request $request): void
    {
        $secret = $this->config->string('sources.reader_secret');
        if (strlen($secret) < 32) {
            throw new HttpException(404, 'Reader disabled');
        }
        if (!hash_equals('Bearer ' . $secret, $request->header('Authorization') ?? '')) {
            throw new HttpException(403, 'Forbidden');
        }
    }
}
