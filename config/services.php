<?php

declare(strict_types=1);

use App\Domain\Auth\PasswordPolicy;
use App\Domain\Billing\Entitlements;
use App\Domain\Help\HelpArticles;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Settings\Settings;
use App\Domain\Billing\PriceCalculator;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\TBank\TBankGateway;
use App\Integrations\Payments\YooKassa\YooKassaGateway;
use App\Domain\Channel\ConnectCodes;
use App\Integrations\Social\Fake\FakeAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Integrations\Social\Max\MaxAdapter;
use App\Integrations\Social\Max\MaxClientFactory;
use App\Integrations\Social\Max\MaxRateGate;
use App\Integrations\Social\Telegram\TelegramAdapter;
use App\Integrations\Social\Vk\VkAdapter;
use App\Integrations\Social\Vk\VkApi;
use App\Integrations\Social\Vk\VkCommunities;
use App\Integrations\Social\Vk\VkOAuth;
use App\Integrations\Social\Vk\VkRateGate;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Domain\Media\FfprobeVideoProbe;
use App\Domain\Media\FolderRepository;
use App\Domain\Media\ImageProcessor;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaRepository;
use App\Domain\Media\MediaService;
use App\Domain\Media\MediaUsageChecker;
use App\Domain\Post\PostUsageChecker;
use App\Domain\Media\VideoProbe;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\MediaStorageFactory;
use App\Domain\Workspace\Permissions;
use App\Http\WorkspaceNav;
use App\Domain\Auth\RegistrationService;
use App\Domain\Auth\RememberMe;
use App\Kernel\Config;
use App\Kernel\Console\Command\SeedCommand;
use App\Kernel\Container;
use App\Kernel\Database\Connection;
use App\Kernel\Database\Migrator;
use App\Kernel\HealthCheck;
use App\Kernel\Http\RequestContext;
use App\Kernel\Http\Router;
use App\Kernel\HttpClient\GuzzleHttpClient;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Log\LoggerFactory;
use App\Kernel\Mail\Mailer;
use App\Kernel\Mail\SymfonyMailer;
use App\Kernel\Queue\Schedule;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\Csrf;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Session\RedisSessionStore;
use App\Kernel\Session\SessionStore;
use App\Kernel\View\Translator;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\SystemClock;
use Psr\Log\LoggerInterface;

/**
 * Explicit service wiring: everything the autowirer cannot infer from type hints
 * (scalars, interfaces, shared resources). `$base` is the project root.
 */
return static function (Container $c, string $base): void {
    $c->factory(Clock::class, static fn (): Clock => new SystemClock());

    $c->factory(LoggerInterface::class, static function (Container $c) use ($base): LoggerInterface {
        $config = $c->get(Config::class);

        return LoggerFactory::create(
            $base . '/storage/logs',
            $config->string('app.log_level', 'info'),
            !$config->env()->bool('LOG_DISABLE_STDERR'),
        );
    });

    $c->factory(Connection::class, static function (Container $c): Connection {
        $config = $c->get(Config::class);

        return new Connection(
            $config->string('database.host'),
            $config->int('database.port', 3306),
            $config->string('database.database'),
            $config->string('database.username'),
            $config->string('database.password'),
        );
    });

    $c->factory(Migrator::class, static fn (Container $c): Migrator => new Migrator($c->get(Connection::class), $base . '/database/migrations'));

    $c->factory(\Redis::class, static function (Container $c): \Redis {
        $config = $c->get(Config::class);
        $redis = new \Redis();
        $redis->connect($config->string('database.redis.host'), $config->int('database.redis.port', 6379), 2.0);
        $redis->select($config->int('database.redis.db', 0));

        return $redis;
    });

    $c->factory(SessionStore::class, static fn (Container $c): SessionStore => new RedisSessionStore($c->get(\Redis::class)));

    $c->factory(Crypto::class, static function (Container $c): Crypto {
        $config = $c->get(Config::class);
        $keys = [$config->string('security.key_id') => $config->string('security.key')];
        foreach ($config->array('security.old_keys') as $id => $key) {
            $keys[(string) $id] = is_string($key) ? $key : '';
        }

        return new Crypto($config->string('security.key_id'), $keys);
    });

    $c->factory(PasswordHasher::class, static function (Container $c): PasswordHasher {
        $config = $c->get(Config::class);

        return new PasswordHasher($config->int('security.argon.memory_kib', 65536), $config->int('security.argon.time_cost', 4));
    });

    $c->factory(Mailer::class, static function (Container $c): Mailer {
        $config = $c->get(Config::class);

        return new SymfonyMailer($config->string('mail.dsn'), $config->string('mail.from'), $config->string('mail.from_name'));
    });

    $c->factory(PasswordPolicy::class, static function (Container $c) use ($base): PasswordPolicy {
        return new PasswordPolicy(
            $c->get(HttpClientInterface::class),
            $c->get(LoggerInterface::class),
            $c->get(Config::class)->bool('auth.hibp_enabled'),
            $base . '/resources/data/common-passwords.txt',
        );
    });

    $c->factory(RememberMe::class, static fn (Container $c): RememberMe => new RememberMe(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(Config::class)->int('auth.remember_days', 30),
    ));

    $c->factory(RegistrationService::class, static fn (Container $c): RegistrationService => new RegistrationService(
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(\App\Domain\Auth\AuthTokens::class),
        $c->get(\App\Domain\Auth\AuthMailer::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(LegalDocuments::class)->consentVersion(),
        $c->get(Clock::class),
    ));

    $c->factory(\App\Domain\Auth\Social\SocialAuthService::class, static fn (Container $c): \App\Domain\Auth\Social\SocialAuthService => new \App\Domain\Auth\Social\SocialAuthService(
        $c->get(\App\Domain\Auth\Social\IdentityRepository::class),
        $c->get(\App\Domain\User\UserRepository::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(\App\Kernel\Security\RateLimiter::class),
        $c->get(Clock::class),
        $c->get(\App\Domain\Workspace\WorkspaceService::class),
        $c->get(LegalDocuments::class)->consentVersion(),
        $c->get(\App\Domain\Auth\RegistrationGate::class),
    ));

    $c->factory(LegalDocuments::class, static fn (Container $c): LegalDocuments => new LegalDocuments($base . '/resources/legal', $c->get(Connection::class)));

    $c->factory(HelpArticles::class, static fn (Container $c): HelpArticles => new HelpArticles($base . '/resources/help', $c->get(Connection::class)));

    $c->factory(HttpClientInterface::class, static fn (Container $c): HttpClientInterface => $c->get(GuzzleHttpClient::class));

    $c->factory(Translator::class, static fn (): Translator => new Translator($base . '/resources/lang', 'ru'));

    $c->factory(Settings::class, static fn (Container $c): Settings => new Settings($c->get(Connection::class), $c->get(Clock::class), $c->get(\Redis::class)));

    $c->factory(Permissions::class, static function (Container $c): Permissions {
        $matrix = [];
        foreach ($c->get(Config::class)->array('permissions') as $permission => $roles) {
            $matrix[(string) $permission] = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        }

        return new Permissions($matrix);
    });

    $c->factory(\App\Domain\Admin\StaffAccess::class, static function (Container $c): \App\Domain\Admin\StaffAccess {
        $matrix = [];
        foreach ($c->get(Config::class)->array('admin_permissions') as $permission => $roles) {
            $matrix[(string) $permission] = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
        }

        return new \App\Domain\Admin\StaffAccess($c->get(Connection::class), $c->get(Clock::class), $matrix);
    });

    $c->factory(View::class, static function (Container $c) use ($base): View {
        $view = new View(
            $c->get(Config::class),
            $c->get(RequestContext::class),
            $c->get(Csrf::class),
            $c->get(Router::class),
            $c->get(Translator::class),
            $base . '/templates',
            $base . '/public',
            $base . '/storage/cache/twig',
        );
        // Workspace-aware helpers live above the Kernel, so they are attached here.
        $nav = $c->get(WorkspaceNav::class);
        $view->registerFunction('can', $nav->can(...));
        $view->registerFunction('current_workspace', $nav->current(...));
        $view->registerFunction('my_workspaces', $nav->mine(...));
        $view->registerFunction('workspace_nav', $nav->items(...));
        $view->registerFunction('plan_card', $nav->planCard(...));
        $view->registerFunction('platform_notices', $nav->platformNotices(...));
        $view->registerFunction('impersonating', $c->get(\App\Http\Auth\Impersonation::class)->target(...));
        $view->registerFunction('theme_version', $c->get(\App\Domain\Design\ThemeColors::class)->version(...));
        $view->registerFunction('site_text', $c->get(\App\Domain\Content\SiteContent::class)->text(...));
        $view->registerFunction('announcements', $c->get(\App\Http\AnnouncementFeed::class)->current(...));
        $view->registerFunction('admin_support_open', $c->get(\App\Domain\Support\Tickets::class)->openCount(...));
        $view->registerFunction('bytes', \App\Domain\Admin\SystemStatus::bytes(...));
        $adminNav = $c->get(\App\Http\Admin\AdminNav::class);
        $view->registerFunction('admin_can', $adminNav->can(...));
        $view->registerFunction('admin_role', $adminNav->role(...));
        $view->registerFunction('money', \App\Support\Money::format(...));

        return $view;
    });

    $c->factory(\App\Domain\Admin\SystemStatus::class, static fn (Container $c): \App\Domain\Admin\SystemStatus => new \App\Domain\Admin\SystemStatus(
        $c->get(Connection::class),
        $c->get(\Redis::class),
        $c->get(\App\Support\Heartbeat::class),
        $c->get(Clock::class),
        $c->get(Config::class),
        $base . '/storage/logs/app.log',
        $base . '/storage',
    ));
    $c->factory(MediaStorage::class, static fn (Container $c): MediaStorage => MediaStorageFactory::create($c->get(Config::class), $base));
    $c->factory(MediaLimits::class, static fn (Container $c): MediaLimits => MediaLimits::fromConfig($c->get(Config::class)));
    $c->factory(VideoProbe::class, static fn (): VideoProbe => new FfprobeVideoProbe());
    // A library file that a planned post still needs cannot be deleted.
    $c->factory(MediaUsageChecker::class, static fn (Container $c): MediaUsageChecker => $c->get(PostUsageChecker::class));
    $c->factory(MediaService::class, static fn (Container $c): MediaService => new MediaService(
        $c->get(MediaRepository::class),
        $c->get(FolderRepository::class),
        $c->get(MediaStorage::class),
        $c->get(ImageProcessor::class),
        $c->get(VideoProbe::class),
        $c->get(MediaLimits::class),
        $c->get(MediaUsageChecker::class),
        $c->get(\App\Domain\Audit\AuditLog::class),
        $c->get(Clock::class),
        $c->get(Entitlements::class),
        $base . '/storage/tmp',
    ));

    // Social platforms: one adapter class per network. A new network = one more line in the list below (and its flag in PLATFORMS_ENABLED).
    $c->factory(TelegramClientFactory::class, static fn (Container $c): TelegramClientFactory => new TelegramClientFactory($c->get(HttpClientInterface::class)));
    $c->factory(MaxClientFactory::class, static fn (Container $c): MaxClientFactory => new MaxClientFactory(
        $c->get(HttpClientInterface::class),
        $c->get(Config::class)->string('platforms.max.api_base', 'https://platform-api2.max.ru'),
    ));
    $c->factory(MaxAdapter::class, static fn (Container $c): MaxAdapter => new MaxAdapter(
        $c->get(MaxClientFactory::class),
        $c->get(\App\Integrations\Social\Max\MaxInspector::class),
        new MaxRateGate($c->get(\Redis::class)),
    ));
    $c->factory(VkApi::class, static fn (Container $c): VkApi => new VkApi(
        $c->get(HttpClientInterface::class),
        new VkRateGate($c->get(\Redis::class)),
        $c->get(Config::class)->string('platforms.vk.api_version', '5.199'),
    ));
    $c->factory(VkOAuth::class, static function (Container $c): VkOAuth {
        $config = $c->get(Config::class);

        return new VkOAuth($c->get(HttpClientInterface::class), $config->string('platforms.vk.client_id'), $config->string('platforms.vk.client_secret'), $config->string('platforms.vk.scope', 'wall photos video docs groups'));
    });
    $c->factory(VkAdapter::class, static fn (Container $c): VkAdapter => new VkAdapter(
        $c->get(VkApi::class),
        $c->get(VkCommunities::class),
        $c->get(Config::class)->int('platforms.vk.posts_per_day', 50),
    ));
    $c->factory(FakeAdapter::class, static fn (Container $c): FakeAdapter => new FakeAdapter($c->get(LoggerInterface::class)));
    $c->factory(PlatformRegistry::class, static function (Container $c): PlatformRegistry {
        $config = $c->get(Config::class);

        return new PlatformRegistry(
            [$c->get(TelegramAdapter::class), $c->get(VkAdapter::class), $c->get(MaxAdapter::class), $c->get(FakeAdapter::class)],
            array_values(array_filter(array_map('strval', $config->array('platforms.enabled')), static fn (string $v): bool => $v !== '')),
            !$config->isProduction(),
            // The owner can switch a platform off in the admin area (a kill switch for an outage or a lost key); read on every call.
            static fn (): array => array_values(array_filter((array) $c->get(Settings::class)->get('platforms.off', []), 'is_string')),
        );
    });
    $c->factory(ConnectCodes::class, static fn (Container $c): ConnectCodes => new ConnectCodes(
        $c->get(Connection::class),
        $c->get(Clock::class),
        $c->get(Config::class)->int('platforms.channels.connect_code_ttl', 900),
    ));

    // Billing. The price calculator needs the renewal window; every payment provider is built from its own settings.
    $c->factory(PriceCalculator::class, static fn (Container $c): PriceCalculator => new PriceCalculator($c->get(Config::class)->int('billing.renewal.lead_days', 3)));
    $c->factory(YooKassaGateway::class, static function (Container $c): YooKassaGateway {
        $config = $c->get(Config::class);

        return new YooKassaGateway(
            $c->get(HttpClientInterface::class),
            $config->string('billing.yookassa.shop_id'),
            $config->string('billing.yookassa.secret_key'),
            $config->string('billing.yookassa.api_base', 'https://api.yookassa.ru/v3'),
            // The sender check cannot be switched off in production.
            $config->bool('billing.yookassa.verify_ip', true) || $config->isProduction(),
            $config->string('billing.tax.tax_system'),
            $config->string('billing.tax.vat'),
            $config->string('billing.tax.item_name'),
        );
    });
    $c->factory(TBankGateway::class, static function (Container $c): TBankGateway {
        $config = $c->get(Config::class);

        return new TBankGateway(
            $c->get(HttpClientInterface::class),
            $config->string('billing.tbank.terminal_key'),
            $config->string('billing.tbank.password'),
            $config->string('billing.tbank.api_base', 'https://securepay.tinkoff.ru/v2'),
            rtrim($config->string('app.url'), '/') . '/webhooks/billing/tbank',
            $config->string('billing.tax.tax_system'),
            $config->string('billing.tax.vat'),
            $config->string('billing.tax.item_name'),
        );
    });
    $c->factory(GatewayRegistry::class, static function (Container $c): GatewayRegistry {
        $config = $c->get(Config::class);

        return new GatewayRegistry(
            [$c->get(YooKassaGateway::class), $c->get(TBankGateway::class), $c->get(FakeGateway::class)],
            array_values(array_filter(array_map('strval', $config->array('billing.gateways')), static fn (string $v): bool => $v !== '')),
            !$config->isProduction(),
        );
    });

    $c->factory(\App\Integrations\Ai\OpenAiResponses::class, static fn (Container $c): \App\Integrations\Ai\OpenAiResponses => new \App\Integrations\Ai\OpenAiResponses($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(Config::class)->string('content_providers.openai_key'), $c->get(Config::class)->string('content_providers.openai_model')));
    $c->factory(\App\Integrations\ContentProviders\ReplicateApi::class, static fn (Container $c): \App\Integrations\ContentProviders\ReplicateApi => new \App\Integrations\ContentProviders\ReplicateApi($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(Config::class)->string('content_providers.replicate_token')));
    $c->factory(\App\Integrations\Images\TinEyeImageSearchProvider::class, static fn (Container $c): \App\Integrations\Images\TinEyeImageSearchProvider => new \App\Integrations\Images\TinEyeImageSearchProvider($c->get(\App\Integrations\ContentProviders\ProviderHttp::class), $c->get(\App\Integrations\ContentProviders\SafeDownloads::class), $c->get(Config::class)->string('content_providers.tineye_key')));

    $c->factory(\App\Integrations\Ai\TextProvider::class, static fn (Container $c): \App\Integrations\Ai\TextProvider => $c->get(Config::class)->string('content_providers.text') === 'openai' ? $c->get(\App\Integrations\Ai\OpenAiTextProvider::class) : new \App\Integrations\Ai\FakeTextProvider(!$c->get(Config::class)->isProduction()));

    $c->factory(\App\Integrations\Selection\SemanticSelectionProvider::class, static fn (Container $c): \App\Integrations\Selection\SemanticSelectionProvider => $c->get(Config::class)->string('content_providers.semantic') === 'openai' ? $c->get(\App\Integrations\Selection\OpenAiSemanticSelectionProvider::class) : new \App\Integrations\Selection\FakeSemanticSelectionProvider(!$c->get(Config::class)->isProduction()));
    $c->factory(\App\Integrations\Discovery\DiscoveryProviders::class, static fn (Container $c): \App\Integrations\Discovery\DiscoveryProviders => new \App\Integrations\Discovery\DiscoveryProviders([
        new \App\Integrations\Discovery\FakeTelegramDiscoveryProvider(!$c->get(Config::class)->isProduction()),
        new \App\Integrations\Discovery\FakeWebNewsDiscoveryProvider(!$c->get(Config::class)->isProduction()),
        new \App\Integrations\Discovery\FakeSocialDiscoveryProvider(!$c->get(Config::class)->isProduction()),
    ]));

    $c->factory(\App\Integrations\Video\VideoProvider::class, static fn (Container $c): \App\Integrations\Video\VideoProvider => $c->get(Config::class)->string('content_providers.video') === 'replicate' ? $c->get(\App\Integrations\Video\ReplicateVideoProvider::class) : new \App\Integrations\Video\FakeVideoProvider(!$c->get(Config::class)->isProduction()));
    $c->factory(\App\Integrations\Images\ImageSearchProvider::class, static fn (Container $c): \App\Integrations\Images\ImageSearchProvider => $c->get(Config::class)->string('content_providers.image_search') === 'tineye' ? $c->get(\App\Integrations\Images\TinEyeImageSearchProvider::class) : new \App\Integrations\Images\FakeImageSearchProvider());
    $c->factory(\App\Integrations\Images\ImageEnhancementProvider::class, static fn (Container $c): \App\Integrations\Images\ImageEnhancementProvider => match ($c->get(Config::class)->string('content_providers.image_enhancement')) {
        'replicate' => $c->get(\App\Integrations\Images\ReplicateImageEnhancementProvider::class), 'disabled' => new \App\Integrations\Images\DisabledImageEnhancementProvider(), default => new \App\Integrations\Images\FakeImageEnhancementProvider(!$c->get(Config::class)->isProduction())
    });

    $c->factory(HealthCheck::class, static fn (Container $c): HealthCheck => new HealthCheck($c->get(Config::class)->env()->all()));

    $c->factory(Schedule::class, static function (Container $c) use ($base): Schedule {
        $schedule = new Schedule($c, $c->get(LoggerInterface::class));
        $define = require $base . '/config/schedule.php';
        $define($schedule);

        return $schedule;
    });

    $c->factory(SeedCommand::class, static fn (Container $c): SeedCommand => new SeedCommand(
        $c->get(Connection::class),
        $c->get(Config::class),
        $base . '/database/seeds',
    ));
};
