<?php

declare(strict_types=1);

// Isolated Docker UI server: route dynamic URLs containing dots (e.g. login-as email), serve only public assets directly.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$request = \App\Kernel\Http\Request::fromGlobals();
$public = dirname(__DIR__, 2) . '/public';
$file = realpath($public . $request->path);
if ($file !== false && str_starts_with($file, $public . '/assets/') && is_file($file)) {
    return false;
}
require $public . '/index.php';
