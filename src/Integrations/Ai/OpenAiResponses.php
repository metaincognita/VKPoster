<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Integrations\ContentProviders\ProviderHttp;
use App\Integrations\ContentProviders\ProviderMetadata;
use App\Integrations\ContentProviders\ProviderException;

/** Responses API with strict JSON output, separated trust roles and allowlisted token/cost metadata. */
final class OpenAiResponses implements ProviderMetadata
{
    /** @var array<string,mixed> */
    private array $usage = [];
    public function __construct(private readonly ProviderHttp $http, private readonly string $key, private readonly string $model = 'gpt-5.4-mini-2026-03-17')
    {
    }
    /**
     * @param array<string,mixed> $material
     * @param array<string,mixed> $schema
     * @return array<string,mixed> */
    public function structured(string $policy, array $material, array $schema): array
    {
        $this->usage = [];
        if ($this->key === '' || preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $this->model) !== 1) {
            throw new ProviderException('not_configured');
        }
        $response = $this->http->json('POST', 'https://api.openai.com/v1/responses', ['headers' => ['Authorization' => 'Bearer ' . $this->key], 'json' => [
            'model' => $this->model, 'store' => false, 'max_output_tokens' => 8192,
            'input' => [['role' => 'developer', 'content' => $policy], ['role' => 'user', 'content' => json_encode(['untrusted_material' => $material], JSON_THROW_ON_ERROR)]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'content_result', 'strict' => true, 'schema' => $schema]],
        ]]);
        $usage = $response['usage'] ?? [];
        $this->usage = ['model' => $this->model];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
            if (is_array($usage) && is_int($usage[$field] ?? null) && $usage[$field] >= 0) {
                $this->usage[$field] = $usage[$field];
            }
        }
        if ($this->model === 'gpt-5.4-mini-2026-03-17' && isset($this->usage['input_tokens'], $this->usage['output_tokens'])) {
            $cached = is_array($usage) && is_array($usage['input_tokens_details'] ?? null) && is_int($usage['input_tokens_details']['cached_tokens'] ?? null) ? max(0, min($this->usage['input_tokens'], $usage['input_tokens_details']['cached_tokens'])) : 0;
            $this->usage['cached_input_tokens'] = $cached;
            $this->usage['estimated_cost_microusd'] = (int) ceil((($this->usage['input_tokens'] - $cached) * 750 + $cached * 75 + $this->usage['output_tokens'] * 4500) / 1000);
            $this->usage['price_snapshot'] = '2026-10-06';
        }
        if (($response['status'] ?? '') !== 'completed' || !is_array($response['output'] ?? null)) {
            throw new ProviderException('incomplete_response');
        }
        $text = '';
        foreach ($response['output'] as $message) {
            if (!is_array($message) || ($message['type'] ?? '') !== 'message' || !is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $part) {
                if (!is_array($part) || ($part['type'] ?? '') !== 'output_text' || !is_string($part['text'] ?? null)) {
                    throw new ProviderException('refused_response');
                }
                $text .= $part['text'];
            }
        }
        try {
            $result = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ProviderException('invalid_output');
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new ProviderException('invalid_output');
        }
        return $result;
    }
    public function metadata(): array
    {
        return $this->usage;
    }
}
