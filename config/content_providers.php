<?php

declare(strict_types=1);

use App\Kernel\Env;
use App\Kernel\Exception\ConfigException;

/** Explicit opt-in; missing credentials never cause a silent fallback or a paid call in tests. */
return static function (Env $env): array {
    $result = [];
    foreach (['text' => ['fake', 'openai'], 'semantic' => ['fake', 'openai'], 'image_search' => ['fake', 'tineye'], 'image_enhancement' => ['fake', 'disabled', 'replicate'], 'video' => ['fake', 'replicate']] as $name => $allowed) {
        $value = $env->string('CONTENT_' . strtoupper($name) . '_PROVIDER', 'fake');
        if (!in_array($value, $allowed, true)) {
            throw new ConfigException('Invalid content provider selection.');
        }
        $result[$name] = $value;
    }
    $credentials = ['openai_key' => $env->string('OPENAI_API_KEY'), 'openai_model' => $env->string('OPENAI_CONTENT_MODEL', 'gpt-5.4-mini-2026-03-17'), 'tineye_key' => $env->string('TINEYE_API_KEY'), 'replicate_token' => $env->string('REPLICATE_API_TOKEN')];
    // Missing credentials disable execution at the adapter/automation boundary, not the whole app.
    foreach (['openai_key', 'tineye_key', 'replicate_token'] as $key) {
        $credentials[$key] = trim($credentials[$key]);
    }
    return $result + $credentials;
};
