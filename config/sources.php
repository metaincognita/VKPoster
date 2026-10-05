<?php

declare(strict_types=1);

use App\Kernel\Env;

/** Dedicated reader authentication; never exposed by a page or used as a Telegram credential. */
return static fn (Env $env): array => ['reader_secret' => $env->string('SOURCES_READER_SECRET')];
