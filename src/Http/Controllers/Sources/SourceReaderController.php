<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sources;

use App\Domain\Source\SourceIngress;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/** Internal HTTP ingress: dedicated bearer secret, bounded payloads, sanitized operational errors and commit-before-ACK. */
final class SourceReaderController
{
    public function __construct(private readonly Config $config, private readonly SourceIngress $ingress, private readonly LoggerInterface $logger)
    {
    }

    public function sources(Request $request): Response
    {
        $this->authenticate($request);
        return Response::json(['version' => 1, 'sources' => $this->ingress->enabled()])->withHeader('Cache-Control', 'no-store');
    }

    public function event(Request $request): Response
    {
        $this->authenticate($request);
        if (strlen($request->rawBody) > 524288) {
            throw new HttpException(413, 'Too large');
        }
        try {
            $saved = $this->ingress->accept($request->json());
            return Response::json(['ack' => true, 'event_id' => $request->json()['event_id'], 'duplicate' => !$saved]);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Database diagnostics may include post contents: never attach the exception or payload to logs.
            $this->logger->warning('Source ingress failed', ['error_type' => $e::class]);
            return Response::json(['ack' => false], 503);
        }
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
