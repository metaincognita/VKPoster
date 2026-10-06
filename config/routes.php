<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ConsentController;
use App\Http\Controllers\Account\FeedbackController;
use App\Http\Controllers\Account\LoginMethodsController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\AnnouncementController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Dev\DevOAuthController;
use App\Http\Controllers\Dev\FakePaymentController;
use App\Http\Controllers\Dev\DevLoginController;
use App\Http\Controllers\Dev\DevUiController;
use App\Http\Controllers\Channels\ChannelController;
use App\Http\Controllers\Channels\MaxConnectController;
use App\Http\Controllers\Channels\VkConnectController;
use App\Http\Controllers\Sources\SourceController;
use App\Http\Controllers\Sources\SourceReaderController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Webhooks\MaxWebhookController;
use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Webhooks\TelegramWebhookController;
use App\Http\Controllers\Workspace\AuditController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\SettingsController;
use App\Http\Controllers\Workspace\TeamController;
use App\Http\Controllers\Workspace\WorkspaceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Site\HelpController;
use App\Http\Controllers\Site\LegalController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\StatusController;
use App\Http\Controllers\Media\FolderController;
use App\Http\Controllers\Media\MediaController;
use App\Http\Controllers\Media\MediaFileController;
use App\Http\Controllers\Media\PickerController;
use App\Http\Controllers\Posts\CalendarController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\TemplateController;
use App\Http\Controllers\Media\WatermarkController;
use App\Http\Middleware\AuthenticateOrSigned;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\Authorize;
use App\Http\Middleware\Guest;
use App\Http\Middleware\OptionalAuthenticate;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RequireConsent;
use App\Http\Middleware\RequireVerifiedEmail;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminAuditController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DesignController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\ContentController;
use App\Http\Controllers\Admin\MailTemplatesController;
use App\Http\Controllers\Admin\CampaignsController;
use App\Http\Controllers\Admin\AnnouncementsAdminController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\PrivacyController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Site\ThemeController;
use App\Http\Controllers\Site\UnsubscribeController;
use App\Http\Middleware\AdminAuditTrail;
use App\Http\Middleware\RequireStaffPermission;
use App\Http\Controllers\Admin\BillingAdminController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WorkspacesController;
use App\Http\Middleware\DenyWhenImpersonating;
use App\Http\Middleware\RequireAdminUnlock;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\TrackVisit;
use App\Kernel\Http\Router;

return static function (Router $router): void {
    $router->get('/', [HomeController::class, 'index'])->name('home')->middleware(TrackVisit::class, OptionalAuthenticate::class);
    $router->get('/healthz', [HealthController::class, 'show'])->name('health');
    $router->get('/internal/source-image-jobs', [\App\Http\Controllers\Sources\ImageReaderController::class, 'jobs'])->middleware([RateLimit::class, ['bucket' => 'source-images-list', 'max' => 120, 'seconds' => 60]]);
    $router->post('/internal/source-image-results', [\App\Http\Controllers\Sources\ImageReaderController::class, 'result'])->withoutCsrf()->middleware([RateLimit::class, ['bucket' => 'source-images-results', 'max' => 60, 'seconds' => 60]]);
    $router->get('/internal/sources', [SourceReaderController::class, 'sources'])->middleware([RateLimit::class, ['bucket' => 'source-reader-list', 'max' => 120, 'seconds' => 60]]);
    $router->post('/internal/source-events', [SourceReaderController::class, 'event'])->withoutCsrf()->middleware([RateLimit::class, ['bucket' => 'source-reader-events', 'max' => 600, 'seconds' => 60]]);

    // Public site: legal documents, the knowledge base, the status of the networks, and what search engines may read.
    $router->get('/legal/{slug:[a-z]{3,20}}', [LegalController::class, 'show'])->name('legal.show')->middleware(OptionalAuthenticate::class);
    $router->get('/help', [HelpController::class, 'index'])->name('help')->middleware(OptionalAuthenticate::class);
    $router->get('/help/{slug:[a-z0-9-]{1,60}}', [HelpController::class, 'show'])->name('help.show')->middleware(OptionalAuthenticate::class);
    $router->get('/status', [StatusController::class, 'show'])->name('status')->middleware(OptionalAuthenticate::class);
    $router->get('/theme.css', [ThemeController::class, 'css']);
    $router->get('/unsubscribe/{user:[0-9]{1,12}}', [UnsubscribeController::class, 'show']);
    $router->post('/unsubscribe/{user:[0-9]{1,12}}', [UnsubscribeController::class, 'confirm'])->withoutCsrf();
    $router->get('/robots.txt', [SeoController::class, 'robots']);
    $router->get('/sitemap.xml', [SeoController::class, 'sitemap']);

    // One-time links carry a 43-character base64url token (see AuthTokens).
    $token = '{token:[A-Za-z0-9_-]{43}}';
    $provider = '{provider:[a-z]{2,10}}';

    // Guests only: signed-in visitors are sent to /app.
    $router->group('', [Guest::class], static function (Router $r) use ($provider): void {
        $r->get('/register', [RegisterController::class, 'show'])->name('register')->middleware(TrackVisit::class);
        $r->post('/register', [RegisterController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'register', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/register/done', [RegisterController::class, 'done'])->name('register.done');
        $r->get('/login', [LoginController::class, 'show'])->name('login')->middleware(TrackVisit::class);
        $r->post('/login', [LoginController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'login', 'max' => 20, 'seconds' => 600]]);
        $r->get('/login/2fa', [LoginController::class, 'twoFactorShow'])->name('login.2fa');
        $r->post('/login/2fa', [LoginController::class, 'twoFactorStore'])->middleware([RateLimit::class, ['bucket' => 'login-2fa', 'max' => 20, 'seconds' => 600]]);
        $r->get('/password/forgot', [PasswordResetController::class, 'forgotShow'])->name('auth.forgot');
        $r->post('/password/forgot', [PasswordResetController::class, 'forgotStore'])->middleware([RateLimit::class, ['bucket' => 'forgot', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/password/forgot/sent', [PasswordResetController::class, 'forgotSent']);
        // Social sign-in: `provider` is checked against the enabled providers inside the controller (404 otherwise).
        $r->get('/auth/' . $provider . '/redirect', [SocialController::class, 'redirect'])->middleware([RateLimit::class, ['bucket' => 'oauth-start', 'max' => 60, 'seconds' => 600]]);
        $r->get('/auth/social/consent', [SocialController::class, 'consentShow'])->name('auth.social.consent');
        $r->post('/auth/social/consent', [SocialController::class, 'consentStore'])->middleware([RateLimit::class, ['bucket' => 'oauth-consent', 'max' => 20, 'seconds' => 600]]);
    });

    // The provider sends the browser back here, signed in (account linking) or not.
    $router->get('/auth/' . $provider . '/callback', [SocialController::class, 'callback'])->name('auth.callback')->middleware([RateLimit::class, ['bucket' => 'oauth-callback', 'max' => 30, 'seconds' => 600]]);

    // Links from emails work in any browser, signed in or not.
    $router->get('/password/reset/' . $token, [PasswordResetController::class, 'resetShow'])->name('auth.reset.show');
    $router->post('/password/reset/' . $token, [PasswordResetController::class, 'resetStore'])->middleware([RateLimit::class, ['bucket' => 'reset', 'max' => 10, 'seconds' => 3600]]);
    $router->get('/email/verify/' . $token, [EmailVerificationController::class, 'show'])->name('auth.verify.show');
    $router->post('/email/verify/' . $token, [EmailVerificationController::class, 'confirm'])->middleware([RateLimit::class, ['bucket' => 'verify', 'max' => 20, 'seconds' => 3600]]);
    $router->get('/email/change/' . $token, [SecurityController::class, 'emailConfirmShow'])->name('account.email.confirm.show');
    $router->post('/email/change/' . $token, [SecurityController::class, 'emailConfirm'])->middleware([RateLimit::class, ['bucket' => 'email-change', 'max' => 20, 'seconds' => 3600]]);

    // Signed in; the email may still be unconfirmed.
    $router->group('', [Authenticate::class], static function (Router $r) use ($provider): void {
        $r->post('/logout', [LoginController::class, 'logout'])->name('logout');
        $r->post('/logout/all', [LoginController::class, 'logoutAll'])->name('logout.all')->middleware(DenyWhenImpersonating::class);
        $r->post('/impersonation/stop', [ImpersonationController::class, 'stop']);
        $r->get('/feedback', [FeedbackController::class, 'show'])->name('feedback');
        $r->post('/feedback', [FeedbackController::class, 'send'])->middleware([RateLimit::class, ['bucket' => 'feedback', 'max' => 10, 'seconds' => 3600]]);
        $r->get('/consent', [ConsentController::class, 'show'])->name('consent');
        $r->post('/consent', [ConsentController::class, 'accept'])->middleware([RateLimit::class, ['bucket' => 'consent', 'max' => 30, 'seconds' => 600]]);
        $r->get('/email/verification', [EmailVerificationController::class, 'notice'])->name('auth.verify.notice');
        $r->post('/email/verification/resend', [EmailVerificationController::class, 'resend']);

        // Security of the account is the person's own: closed while support acts as them.
        $r->group('', [DenyWhenImpersonating::class], static function (Router $a) use ($provider): void {
            $a->get('/account/security', [SecurityController::class, 'show'])->name('account.security');
            $a->post('/account/password', [SecurityController::class, 'changePassword']);
            $a->post('/account/email', [SecurityController::class, 'requestEmailChange']);
            $a->post('/account/sessions/{id:[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}}/revoke', [SecurityController::class, 'revokeSession']);
            $a->get('/account/login-methods', [LoginMethodsController::class, 'show'])->name('account.login_methods');
            $a->post('/account/login-methods/password', [LoginMethodsController::class, 'setPassword'])->middleware([RateLimit::class, ['bucket' => 'set-password', 'max' => 10, 'seconds' => 600]]);
            $a->post('/account/login-methods/' . $provider . '/link', [LoginMethodsController::class, 'link']);
            $a->post('/account/login-methods/' . $provider . '/unlink', [LoginMethodsController::class, 'unlink']);
            $a->get('/account/notifications', [NotificationController::class, 'show'])->name('account.notifications');
            $a->post('/account/notifications', [NotificationController::class, 'save']);
            $a->post('/account/notifications/marketing', [NotificationController::class, 'saveMarketing']);
            $a->post('/announcements/{id:[0-9]{1,12}}/dismiss', [AnnouncementController::class, 'dismiss']);
            $a->post('/account/notifications/telegram/link', [NotificationController::class, 'linkTelegram'])->middleware([RateLimit::class, ['bucket' => 'telegram-link', 'max' => 10, 'seconds' => 3600]]);
            $a->post('/account/notifications/telegram/unlink', [NotificationController::class, 'unlinkTelegram']);
            $a->post('/account/2fa/start', [TwoFactorController::class, 'start']);
            $a->get('/account/2fa/setup', [TwoFactorController::class, 'setup'])->name('account.2fa.setup');
            $a->get('/account/2fa/qr.svg', [TwoFactorController::class, 'qr']);
            $a->post('/account/2fa/confirm', [TwoFactorController::class, 'confirm']);
            $a->post('/account/2fa/disable', [TwoFactorController::class, 'disable']);
            $a->post('/account/2fa/recovery-codes', [TwoFactorController::class, 'regenerateCodes']);
        });
    });

    // The invitation page is public: the link in the email is the secret. Accepting needs an account (see below).
    $router->get('/invitations/' . $token, [InvitationController::class, 'show'])->name('invitation.show');

    // The application itself needs a confirmed email.
    $ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
    $router->group('', [Authenticate::class, RequireVerifiedEmail::class, RequireConsent::class], static function (Router $r) use ($token, $ulid): void {
        $r->get('/app', [AppController::class, 'dashboard'])->name('app');
        $r->get('/workspaces/new', [WorkspaceController::class, 'create'])->name('workspace.new');
        // VK ID sends the browser back here (a fixed address registered in the VK application); the controller checks the workspace and the right.
        $r->get('/channels/connect/vk/callback', [VkConnectController::class, 'callback'])->middleware([RateLimit::class, ['bucket' => 'vk-callback', 'max' => 30, 'seconds' => 600]]);
        $r->post('/workspaces', [WorkspaceController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'workspace-create', 'max' => 10, 'seconds' => 3600]]);
        $r->post('/invitations/' . $token . '/accept', [InvitationController::class, 'accept'])->middleware([RateLimit::class, ['bucket' => 'invitation-accept', 'max' => 20, 'seconds' => 3600]]);

        // Everything inside a workspace: `ResolveWorkspace` answers 404 unless the user is a member; `Authorize` checks the role.
        $r->group('/w/{workspaceId:' . $ulid . '}', [ResolveWorkspace::class], static function (Router $w) use ($ulid): void {
            $w->get('', [WorkspaceController::class, 'home'])->name('workspace.home');
            $w->post('/leave', [TeamController::class, 'leave']);

            $w->group('', [[Authorize::class, ['permission' => 'members.manage']]], static function (Router $t) use ($ulid): void {
                $t->get('/team', [TeamController::class, 'show'])->name('workspace.team');
                $t->post('/team/invitations', [TeamController::class, 'invite'])->middleware([RateLimit::class, ['bucket' => 'invite', 'max' => 30, 'seconds' => 3600]]);
                $t->post('/team/invitations/{invitationId:' . $ulid . '}/revoke', [TeamController::class, 'revokeInvitation']);
                $t->post('/team/members/{memberId:' . $ulid . '}/role', [TeamController::class, 'changeRole']);
                $t->post('/team/members/{memberId:' . $ulid . '}/remove', [TeamController::class, 'remove']);
            });
            $w->post('/team/transfer', [TeamController::class, 'transfer'])->middleware([Authorize::class, ['permission' => 'workspace.transfer']], DenyWhenImpersonating::class);

            $w->group('', [[Authorize::class, ['permission' => 'workspace.settings']]], static function (Router $t): void {
                $t->get('/settings', [SettingsController::class, 'show'])->name('workspace.settings');
                $t->post('/settings', [SettingsController::class, 'update']);
            });
            $w->post('/delete', [SettingsController::class, 'delete'])->middleware([Authorize::class, ['permission' => 'workspace.delete']], DenyWhenImpersonating::class);
            // Plan and payment: the owner only. `pay` sends the browser on to the provider (a plain page load, see BillingController::checkout).
            $w->group('/billing', [[Authorize::class, ['permission' => 'workspace.billing']], DenyWhenImpersonating::class], static function (Router $b) use ($ulid): void {
                $b->get('', [BillingController::class, 'overview'])->name('workspace.billing');
                $b->get('/plans', [BillingController::class, 'plans'])->name('workspace.billing.plans');
                $b->post('/checkout', [BillingController::class, 'checkout'])->middleware([RateLimit::class, ['bucket' => 'billing-checkout', 'max' => 20, 'seconds' => 3600]]);
                $b->get('/pay/{paymentId:' . $ulid . '}', [BillingController::class, 'pay'])->middleware([RateLimit::class, ['bucket' => 'billing-pay', 'max' => 60, 'seconds' => 3600]]);
                $b->get('/return', [BillingController::class, 'returned'])->middleware([RateLimit::class, ['bucket' => 'billing-return', 'max' => 120, 'seconds' => 600]]);
                $b->post('/renewal/cancel', [BillingController::class, 'cancelRenewal']);
                $b->post('/renewal/resume', [BillingController::class, 'resumeRenewal']);
                $b->post('/change/cancel', [BillingController::class, 'unschedule']);
                $b->post('/card/remove', [BillingController::class, 'forgetCard']);
                $b->get('/invoices/{invoiceId:' . $ulid . '}/receipt', [BillingController::class, 'receipt'])->name('workspace.billing.receipt');
            });
            // Media library. Viewing: everyone who works on posts; uploading: authors and up; changes: editors and up.
            $w->group('', [[Authorize::class, ['permission' => 'media.view']]], static function (Router $m) use ($ulid): void {
                $m->get('/media', [MediaController::class, 'index'])->name('workspace.media');
                $m->get('/media/{mediaId:' . $ulid . '}', [MediaController::class, 'show'])->name('workspace.media.show');
            });
            $w->group('', [[Authorize::class, ['permission' => 'media.upload']]], static function (Router $m): void {
                $m->post('/media/upload', [MediaController::class, 'upload'])->middleware([RateLimit::class, ['bucket' => 'media-upload', 'max' => 300, 'seconds' => 600]]);
                $m->post('/media/upload-url', [MediaController::class, 'uploadUrl'])->middleware([RateLimit::class, ['bucket' => 'media-url', 'max' => 30, 'seconds' => 600]]);
            });
            $w->group('', [[Authorize::class, ['permission' => 'media.manage']]], static function (Router $m) use ($ulid): void {
                $item = '/media/{mediaId:' . $ulid . '}';
                $m->post($item . '/rename', [MediaController::class, 'rename']);
                $m->post($item . '/move', [MediaController::class, 'move']);
                $m->post($item . '/delete', [MediaController::class, 'delete']);
                $m->post('/media/folders', [FolderController::class, 'create']);
                $m->post('/media/folders/{folderId:' . $ulid . '}/rename', [FolderController::class, 'rename']);
                $m->post('/media/folders/{folderId:' . $ulid . '}/delete', [FolderController::class, 'delete']);
                $m->get('/media/watermarks', [WatermarkController::class, 'show'])->name('workspace.media.watermarks');
                $m->post('/media/watermarks', [WatermarkController::class, 'create'])->middleware([RateLimit::class, ['bucket' => 'watermark-upload', 'max' => 20, 'seconds' => 600]]);
                $mark = '/media/watermarks/{watermarkId:' . $ulid . '}';
                $m->get($mark . '/logo', [WatermarkController::class, 'logo']);
                $m->get($mark . '/preview', [WatermarkController::class, 'preview'])->middleware([RateLimit::class, ['bucket' => 'watermark-preview', 'max' => 120, 'seconds' => 60]]);
                $m->post($mark . '/update', [WatermarkController::class, 'update']);
                $m->post($mark . '/delete', [WatermarkController::class, 'delete']);
            });
            $w->group('/radar', [[Authorize::class, ['permission' => 'discovery.view']]], static function (Router $r) use ($ulid): void {
                $r->get('', [\App\Http\Controllers\Discovery\RadarController::class, 'index']);
                $r->get('/materials/{itemId:' . $ulid . '}', [\App\Http\Controllers\Discovery\RadarController::class, 'material']);
                $r->group('', [[Authorize::class, ['permission' => 'discovery.manage']]], static function (Router $m) use ($ulid): void {
                    $m->post('/semantic-settings', [\App\Http\Controllers\Discovery\RadarController::class, 'semantic'])->middleware([RateLimit::class, ['bucket' => 'semantic-settings', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/refresh', [\App\Http\Controllers\Discovery\RadarController::class, 'refresh'])->middleware([RateLimit::class, ['bucket' => 'discovery-refresh', 'max' => 5, 'seconds' => 60]]);
                    $m->post('/items/{discoveryId:' . $ulid . '}/import', [\App\Http\Controllers\Discovery\RadarController::class, 'import']);
                    $m->post('/clusters/{clusterId:' . $ulid . '}/ignore', [\App\Http\Controllers\Discovery\RadarController::class, 'ignore']);
                    $m->post('/materials/{itemId:' . $ulid . '}/selection', [\App\Http\Controllers\Discovery\RadarController::class, 'decide']);
                    $m->post('/materials/{itemId:' . $ulid . '}/process', [\App\Http\Controllers\Discovery\RadarController::class, 'process'])->middleware([RateLimit::class, ['bucket' => 'discovery-processing', 'max' => 30, 'seconds' => 60]]);
                });
            });
            // Sources and selection remain separate from the publishing pipeline.
            $w->group('/sources', [[Authorize::class, ['permission' => 'sources.view']]], static function (Router $s) use ($ulid): void {
                $s->get('', [SourceController::class, 'index'])->name('workspace.sources');
                $s->group('', [[Authorize::class, ['permission' => 'sources.manage']]], static function (Router $m) use ($ulid): void {
                    $m->get('/new', [SourceController::class, 'new']);
                    $m->post('', [SourceController::class, 'create']);
                    $m->get('/{sourceId:' . $ulid . '}/edit', [SourceController::class, 'edit']);
                    $m->post('/{sourceId:' . $ulid . '}', [SourceController::class, 'update']);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/process', [\App\Http\Controllers\Sources\ProcessingController::class, 'process'])->middleware([RateLimit::class, ['bucket' => 'source-text-processing', 'max' => 30, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/images', [\App\Http\Controllers\Sources\ImageProcessingController::class, 'request'])->middleware([RateLimit::class, ['bucket' => 'source-images-request', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/images/select', [\App\Http\Controllers\Sources\ImageProcessingController::class, 'choose']);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/videos', [\App\Http\Controllers\Sources\VideoProcessingController::class, 'request'])->middleware([RateLimit::class, ['bucket' => 'source-video-request', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/videos/run', [\App\Http\Controllers\Sources\VideoProcessingController::class, 'run'])->middleware([RateLimit::class, ['bucket' => 'source-video-run', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/videos/select', [\App\Http\Controllers\Sources\VideoProcessingController::class, 'choose'])->middleware([RateLimit::class, ['bucket' => 'source-video-choose', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/semantic-settings', [\App\Http\Controllers\Sources\SelectionController::class, 'semantic'])->middleware([RateLimit::class, ['bucket' => 'semantic-settings', 'max' => 10, 'seconds' => 60]]);
                    $m->post('/{sourceId:' . $ulid . '}/selection-rules', [\App\Http\Controllers\Sources\SelectionController::class, 'rules']);
                    $m->post('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/selection', [\App\Http\Controllers\Sources\SelectionController::class, 'decide']);
                });
                $s->get('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/videos', [\App\Http\Controllers\Sources\VideoProcessingController::class, 'show']);
                $s->get('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/images', [\App\Http\Controllers\Sources\ImageProcessingController::class, 'show']);
                $s->get('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}/images/{variantId:' . $ulid . '}/preview', [\App\Http\Controllers\Sources\ImageProcessingController::class, 'preview']);
                $s->get('/{sourceId:' . $ulid . '}/items/{itemId:' . $ulid . '}', [\App\Http\Controllers\Sources\ProcessingController::class, 'show']);
                $s->get('/{sourceId:' . $ulid . '}', [SourceController::class, 'show'])->name('workspace.sources.show');
            });
            // Channels. Everyone who works on posts may look; connecting and changing is for owners and administrators.
            $w->get('/channels', [ChannelController::class, 'index'])->name('workspace.channels')->middleware([Authorize::class, ['permission' => 'channels.view']]);
            $w->get('/channels/{channelId:' . $ulid . '}/avatar', [ChannelController::class, 'avatar'])->middleware([Authorize::class, ['permission' => 'channels.view']]);
            $w->group('/channels', [[Authorize::class, ['permission' => 'channels.manage']]], static function (Router $c) use ($ulid): void {
                $c->get('/connect/vk', [VkConnectController::class, 'show']);
                $c->get('/connect/vk/start', [VkConnectController::class, 'start'])->middleware([RateLimit::class, ['bucket' => 'vk-connect', 'max' => 30, 'seconds' => 3600]]);
                $c->get('/connect/vk/choose', [VkConnectController::class, 'choose']);
                $c->post('/connect/vk/choose', [VkConnectController::class, 'connect'])->middleware([RateLimit::class, ['bucket' => 'vk-choose', 'max' => 30, 'seconds' => 3600]]);
                $c->get('/connect/telegram', [ChannelController::class, 'connectTelegram']);
                $c->post('/connect/telegram/code', [ChannelController::class, 'issueCode'])->middleware([RateLimit::class, ['bucket' => 'channel-code', 'max' => 20, 'seconds' => 3600]]);
                $c->get('/connect/telegram/status/{codeId:' . $ulid . '}', [ChannelController::class, 'codeStatus'])->middleware([RateLimit::class, ['bucket' => 'channel-code-status', 'max' => 600, 'seconds' => 600]]);
                $c->post('/connect/telegram/own', [ChannelController::class, 'connectOwn'])->middleware([RateLimit::class, ['bucket' => 'channel-own-bot', 'max' => 15, 'seconds' => 3600]]);
                $c->get('/connect/max', [MaxConnectController::class, 'show']);
                $c->post('/connect/max/code', [MaxConnectController::class, 'issueCode'])->middleware([RateLimit::class, ['bucket' => 'channel-code', 'max' => 20, 'seconds' => 3600]]);
                $c->get('/connect/max/status/{codeId:' . $ulid . '}', [MaxConnectController::class, 'codeStatus'])->middleware([RateLimit::class, ['bucket' => 'channel-code-status', 'max' => 600, 'seconds' => 600]]);
                $c->post('/connect/max/own', [MaxConnectController::class, 'connectOwn'])->middleware([RateLimit::class, ['bucket' => 'channel-own-bot', 'max' => 15, 'seconds' => 3600]]);
                // The test network: the controller answers 404 unless it is enabled (never in production).
                $c->get('/connect/fake', [ChannelController::class, 'fakeForm']);
                $c->post('/connect/fake', [ChannelController::class, 'connectFake']);
                $item = '/{channelId:' . $ulid . '}';
                $c->post($item . '/check', [ChannelController::class, 'check'])->middleware([RateLimit::class, ['bucket' => 'channel-check', 'max' => 60, 'seconds' => 600]]);
                $c->post($item . '/pause', [ChannelController::class, 'pause']);
                $c->post($item . '/resume', [ChannelController::class, 'resume']);
                $c->post($item . '/rename', [ChannelController::class, 'rename']);
                $c->post($item . '/delete', [ChannelController::class, 'delete']);
            });
            // Posts. Looking (the calendar and a post's page): everyone with calendar access; writing drafts: authors and up;
            // planning, moving, cancelling, retrying: editors and up (`PostService` repeats the check, and adds the per-post rules).
            $w->group('', [[Authorize::class, ['permission' => 'calendar.view']]], static function (Router $p) use ($ulid): void {
                $p->get('/calendar', [CalendarController::class, 'show'])->name('workspace.calendar');
                $p->get('/posts/{postId:' . $ulid . '}', [PostController::class, 'show'])->name('workspace.post');
            });
            $w->group('', [[Authorize::class, ['permission' => 'posts.draft']]], static function (Router $p) use ($ulid): void {
                $p->get('/posts/new', [PostController::class, 'create'])->name('workspace.post.new');
                $p->post('/posts', [PostController::class, 'store'])->middleware([RateLimit::class, ['bucket' => 'post-save', 'max' => 120, 'seconds' => 600]]);
                $p->post('/posts/autosave', [PostController::class, 'autosave'])->middleware([RateLimit::class, ['bucket' => 'post-autosave', 'max' => 600, 'seconds' => 600]]);
                $p->post('/posts/validate', [PostController::class, 'validate'])->middleware([RateLimit::class, ['bucket' => 'post-validate', 'max' => 600, 'seconds' => 600]]);
                $p->post('/posts/preview', [PostController::class, 'preview'])->middleware([RateLimit::class, ['bucket' => 'post-preview', 'max' => 600, 'seconds' => 600]]);
                $item = '/posts/{postId:' . $ulid . '}';
                $p->get($item . '/edit', [PostController::class, 'edit']);
                $p->post($item, [PostController::class, 'update'])->middleware([RateLimit::class, ['bucket' => 'post-save', 'max' => 120, 'seconds' => 600]]);
                $p->post($item . '/duplicate', [PostController::class, 'duplicate']);
                $p->post($item . '/delete', [PostController::class, 'delete']);
                $p->get('/templates', [TemplateController::class, 'index'])->name('workspace.templates');
                $p->post('/templates', [TemplateController::class, 'store']);
                $p->post('/templates/{templateId:' . $ulid . '}/delete', [TemplateController::class, 'delete']);
            });
            $w->group('', [[Authorize::class, ['permission' => 'posts.publish']]], static function (Router $p) use ($ulid): void {
                $item = '/posts/{postId:' . $ulid . '}';
                $p->post($item . '/move', [PostController::class, 'move'])->middleware([RateLimit::class, ['bucket' => 'post-move', 'max' => 300, 'seconds' => 600]]);
                $p->post($item . '/cancel', [PostController::class, 'cancel']);
                $p->post($item . '/edit-published', [PostController::class, 'editPublished']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/retry', [PostController::class, 'retry']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/settle', [PostController::class, 'settle']);
                $p->post($item . '/publications/{publicationId:' . $ulid . '}/remove', [PostController::class, 'removeFromNetwork']);
            });
            $w->get('/media-picker', [PickerController::class, 'list'])->middleware([Authorize::class, ['permission' => 'posts.draft']]);
            $w->get('/audit', [AuditController::class, 'show'])->name('workspace.audit')->middleware([Authorize::class, ['permission' => 'audit.view']]);
        });
    });

    // The back office. Staff only (anybody else gets 404), two-factor protected, with a fresh code every 8 hours (and after 30 idle minutes) and
    // its own rate limit. Every route names the permission it needs (`config/admin_permissions.php`); every change is audited.
    $router->group('/admin', [Authenticate::class, RequireVerifiedEmail::class, [RateLimit::class, ['bucket' => 'admin', 'max' => 600, 'seconds' => 600]], RequireStaff::class], static function (Router $a): void {
        $a->get('/unlock', [AdminController::class, 'unlockShow'])->name('admin.unlock');
        $a->post('/unlock', [AdminController::class, 'unlock'])->middleware([RateLimit::class, ['bucket' => 'admin-unlock', 'max' => 10, 'seconds' => 600]]);
        $a->group('', [RequireAdminUnlock::class, AdminAuditTrail::class], static function (Router $s): void {
            $ulid26 = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';
            $can = static fn (string $permission): array => [RequireStaffPermission::class, ['permission' => $permission]];
            $s->get('', [DashboardController::class, 'index'])->name('admin')->middleware($can('dashboard.view'));
            $s->post('/dashboard/refresh', [DashboardController::class, 'refresh'])->middleware($can('stats.view'));
            $s->get('/users', [UsersController::class, 'index'])->name('admin.users')->middleware($can('users.view'));
            $s->get('/search', [SearchController::class, 'index'])->name('admin.search')->middleware($can('dashboard.view'));
            $s->get('/users/export', [UsersController::class, 'export'])->middleware($can('users.export'));
            $s->get('/users/{id:[0-9]{1,12}}', [UsersController::class, 'show'])->middleware($can('users.view'));
            $s->post('/users/{id:[0-9]{1,12}}/signout', [UsersController::class, 'signOut'])->middleware($can('users.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/reset-2fa', [UsersController::class, 'resetTwoFactor'])->middleware($can('users.secure'));
            $s->post('/users/{id:[0-9]{1,12}}/verify-email', [UsersController::class, 'verifyEmail'])->middleware($can('users.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/note', [UsersController::class, 'note'])->middleware($can('users.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/grant', [UsersController::class, 'grant'])->middleware($can('grants.manage'));
            $s->get('/privacy', [PrivacyController::class, 'index'])->name('admin.privacy')->middleware($can('privacy.manage'));
            $s->post('/privacy', [PrivacyController::class, 'open'])->middleware($can('privacy.manage'));
            $s->get('/privacy/{publicId:' . $ulid26 . '}/download', [PrivacyController::class, 'download'])->middleware($can('privacy.manage'));
            $s->post('/privacy/{publicId:' . $ulid26 . '}/anonymize', [PrivacyController::class, 'anonymize'])->middleware($can('privacy.manage'));
            $s->post('/privacy/{publicId:' . $ulid26 . '}/reject', [PrivacyController::class, 'reject'])->middleware($can('privacy.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/block', [UsersController::class, 'block'])->middleware($can('users.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/unblock', [UsersController::class, 'unblock'])->middleware($can('users.manage'));
            $s->post('/users/{id:[0-9]{1,12}}/impersonate', [UsersController::class, 'impersonate'])->middleware($can('users.impersonate'), [RateLimit::class, ['bucket' => 'admin-impersonate', 'max' => 30, 'seconds' => 3600]]);
            $s->get('/workspaces', [WorkspacesController::class, 'index'])->name('admin.workspaces')->middleware($can('workspaces.view'));
            $s->get('/workspaces/{publicId:' . $ulid26 . '}', [WorkspacesController::class, 'show'])->middleware($can('workspaces.view'));
            $s->post('/workspaces/{publicId:' . $ulid26 . '}/grant', [WorkspacesController::class, 'grant'])->middleware($can('grants.manage'));
            $s->get('/subscriptions', [BillingAdminController::class, 'subscriptions'])->name('admin.subscriptions')->middleware($can('finance.view'));
            $s->get('/payments', [BillingAdminController::class, 'payments'])->name('admin.payments')->middleware($can('finance.view'));
            $s->get('/payments/export', [FinanceController::class, 'exportCsv'])->middleware($can('finance.view'));
            $s->post('/payments/fees', [FinanceController::class, 'saveFees'])->middleware($can('finance.manage'));
            $s->get('/payments/{paymentId:' . $ulid26 . '}', [FinanceController::class, 'payment'])->middleware($can('finance.view'));
            $s->post('/payments/{paymentId:' . $ulid26 . '}/reconcile', [FinanceController::class, 'reconcile'])->middleware($can('finance.manage'), [RateLimit::class, ['bucket' => 'admin-reconcile', 'max' => 60, 'seconds' => 3600]]);
            $s->get('/webhooks', [FinanceController::class, 'webhooks'])->name('admin.webhooks')->middleware($can('finance.view'));
            $s->get('/webhooks/{id:[0-9]{1,12}}', [FinanceController::class, 'webhook'])->middleware($can('finance.view'));
            $s->post('/webhooks/{id:[0-9]{1,12}}/replay', [FinanceController::class, 'replay'])->middleware($can('finance.manage'), [RateLimit::class, ['bucket' => 'admin-reconcile', 'max' => 60, 'seconds' => 3600]]);
            $s->post('/payments/{paymentId:' . $ulid26 . '}/refund', [BillingAdminController::class, 'refund'])->middleware($can('finance.manage'), [RateLimit::class, ['bucket' => 'admin-refund', 'max' => 30, 'seconds' => 3600]]);
            $s->get('/plans', [BillingAdminController::class, 'plans'])->name('admin.plans')->middleware($can('finance.view'));
            $s->get('/plans/{code:[a-z0-9_]{1,32}}', [BillingAdminController::class, 'editPlan'])->middleware($can('plans.manage'));
            $s->post('/plans/{code:[a-z0-9_]{1,32}}', [BillingAdminController::class, 'updatePlan'])->middleware($can('plans.manage'));
            $s->get('/promo', [OperationsController::class, 'promo'])->name('admin.promo')->middleware($can('finance.view'));
            $s->get('/stats', [StatsController::class, 'publishing'])->name('admin.stats')->middleware($can('stats.view'));
            $s->get('/system', [StatsController::class, 'system'])->name('admin.system')->middleware($can('system.view'));
            $s->get('/queues', [OperationsController::class, 'queues'])->name('admin.queues')->middleware($can('system.view'));
            $s->post('/queues/failed/{id:[0-9]{1,12}}/retry', [OperationsController::class, 'retry'])->middleware($can('ops.manage'));
            $s->post('/queues/failed/{id:[0-9]{1,12}}/discard', [OperationsController::class, 'discard'])->middleware($can('ops.manage'));
            $s->get('/channels', [OperationsController::class, 'channels'])->name('admin.channels')->middleware($can('system.view'));
            $s->get('/platforms', [OperationsController::class, 'platforms'])->name('admin.platforms')->middleware($can('system.view'));
            $s->post('/platforms', [OperationsController::class, 'savePlatforms'])->middleware($can('ops.manage'));
            $s->get('/audit', [AdminAuditController::class, 'index'])->name('admin.audit')->middleware($can('audit.view'));
            $s->get('/audit/export', [AdminAuditController::class, 'export'])->middleware($can('audit.view'));
            $s->get('/design', [DesignController::class, 'show'])->name('admin.design')->middleware($can('design.manage'));
            $s->post('/design', [DesignController::class, 'save'])->middleware($can('design.manage'));
            $s->post('/design/reset', [DesignController::class, 'reset'])->middleware($can('design.manage'));
            $s->get('/settings', [SiteSettingsController::class, 'show'])->name('admin.settings')->middleware($can('settings.manage'));
            $s->post('/settings', [SiteSettingsController::class, 'save'])->middleware($can('settings.manage'));
            $s->post('/settings/report', [SiteSettingsController::class, 'saveReport'])->middleware($can('settings.manage'));
            $s->post('/settings/report/test', [SiteSettingsController::class, 'testReport'])->middleware($can('settings.manage'), [RateLimit::class, ['bucket' => 'admin-report-test', 'max' => 10, 'seconds' => 3600]]);
            $s->post('/settings/invites', [SiteSettingsController::class, 'createCode'])->middleware($can('settings.manage'));
            $s->post('/settings/invites/{id:[0-9]{1,12}}/revoke', [SiteSettingsController::class, 'revokeCode'])->middleware($can('settings.manage'));
            $s->get('/announcements', [AnnouncementsAdminController::class, 'index'])->name('admin.announcements')->middleware($can('content.manage'));
            $s->post('/announcements', [AnnouncementsAdminController::class, 'save'])->middleware($can('content.manage'));
            $s->get('/announcements/{id:[0-9]{1,12}}', [AnnouncementsAdminController::class, 'edit'])->middleware($can('content.manage'));
            $s->post('/announcements/{id:[0-9]{1,12}}', [AnnouncementsAdminController::class, 'save'])->middleware($can('content.manage'));
            $s->post('/announcements/{id:[0-9]{1,12}}/toggle', [AnnouncementsAdminController::class, 'toggle'])->middleware($can('content.manage'));
            $s->post('/announcements/{id:[0-9]{1,12}}/delete', [AnnouncementsAdminController::class, 'delete'])->middleware($can('content.manage'));
            $s->get('/campaigns', [CampaignsController::class, 'index'])->name('admin.campaigns')->middleware($can('campaigns.manage'));
            $s->get('/campaigns/new', [CampaignsController::class, 'create'])->middleware($can('campaigns.manage'));
            $s->post('/campaigns', [CampaignsController::class, 'save'])->middleware($can('campaigns.manage'));
            $s->get('/campaigns/{publicId:' . $ulid26 . '}', [CampaignsController::class, 'show'])->middleware($can('campaigns.manage'));
            $s->post('/campaigns/{publicId:' . $ulid26 . '}', [CampaignsController::class, 'save'])->middleware($can('campaigns.manage'));
            $s->post('/campaigns/{publicId:' . $ulid26 . '}/test', [CampaignsController::class, 'test'])->middleware($can('campaigns.manage'), [RateLimit::class, ['bucket' => 'admin-campaign-test', 'max' => 30, 'seconds' => 3600]]);
            $s->post('/campaigns/{publicId:' . $ulid26 . '}/start', [CampaignsController::class, 'start'])->middleware($can('campaigns.manage'));
            $s->post('/campaigns/{publicId:' . $ulid26 . '}/cancel', [CampaignsController::class, 'cancel'])->middleware($can('campaigns.manage'));
            $s->get('/mail-templates', [MailTemplatesController::class, 'index'])->name('admin.mail_templates')->middleware($can('content.manage'));
            $s->get('/mail-templates/{template:[a-z0-9_]{1,40}}', [MailTemplatesController::class, 'edit'])->middleware($can('content.manage'));
            $s->post('/mail-templates/{template:[a-z0-9_]{1,40}}', [MailTemplatesController::class, 'save'])->middleware($can('content.manage'));
            $s->post('/mail-templates/{template:[a-z0-9_]{1,40}}/reset', [MailTemplatesController::class, 'reset'])->middleware($can('content.manage'));
            $s->get('/content', [ContentController::class, 'index'])->name('admin.content')->middleware($can('content.manage'));
            $s->get('/content/new', [ContentController::class, 'newDocument'])->middleware($can('content.manage'));
            $s->get('/content/texts', [ContentController::class, 'texts'])->middleware($can('content.manage'));
            $s->post('/content/texts', [ContentController::class, 'saveTexts'])->middleware($can('content.manage'));
            $s->post('/content/faq', [ContentController::class, 'saveFaq'])->middleware($can('content.manage'));
            $s->post('/content/preview', [ContentController::class, 'preview'])->middleware($can('content.manage'));
            $s->post('/content', [ContentController::class, 'save'])->middleware($can('content.manage'));
            $s->post('/content/revisions/{id:[0-9]{1,12}}/restore', [ContentController::class, 'restore'])->middleware($can('content.manage'));
            $s->get('/content/{kind:legal|help}/{slug:[a-z0-9-]{1,60}}', [ContentController::class, 'document'])->middleware($can('content.manage'));
            $s->post('/content/{kind:legal|help}/{slug:[a-z0-9-]{1,60}}/discard', [ContentController::class, 'discard'])->middleware($can('content.manage'));
            $s->get('/support', [SupportController::class, 'index'])->name('admin.support')->middleware($can('support.view'));
            $s->get('/support/{publicId:' . $ulid26 . '}', [SupportController::class, 'show'])->middleware($can('support.view'));
            $s->post('/support/{publicId:' . $ulid26 . '}/reply', [SupportController::class, 'reply'])->middleware($can('support.manage'));
            $s->post('/support/{publicId:' . $ulid26 . '}', [SupportController::class, 'update'])->middleware($can('support.manage'));
            $s->get('/staff', [StaffController::class, 'index'])->name('admin.staff')->middleware($can('staff.manage'));
            $s->post('/staff', [StaffController::class, 'assign'])->middleware($can('staff.manage'));
            $s->post('/staff/{id:[0-9]{1,12}}/remove', [StaffController::class, 'remove'])->middleware($can('staff.manage'));
        });
    });

    // Library files: a signed-in member of the owning workspace, or a signed expiring link (checked in the controller).
    $router->get('/media/{id:' . $ulid . '}/{variant:[a-z0-9-]{1,20}}', [MediaFileController::class, 'show'])->name('media.file')->middleware(AuthenticateOrSigned::class);

    // Telegram calls this for every update of the shared bot: authenticity is the secret in the path plus a header (see TelegramWebhook).
    $router->post('/webhooks/telegram/{secret:[A-Za-z0-9_-]{16,128}}', [TelegramWebhookController::class, 'receive'])->withoutCsrf();
    // MAX calls this for every update of the shared bot: the secret in the path plus the X-Max-Bot-Api-Secret header (see MaxWebhook).
    $router->post('/webhooks/max/{secret:[A-Za-z0-9_-]{16,128}}', [MaxWebhookController::class, 'receive'])->withoutCsrf();

    // Payment providers call this for every payment event: authenticity is checked by the provider's gateway before anything is read.
    $router->post('/webhooks/billing/{provider:[a-z]{3,12}}', [PaymentWebhookController::class, 'receive'])->withoutCsrf();

    $router->get('/dev/login-as/{id:[^/]+}', [DevLoginController::class, 'loginAs']);
    // The payment page of the test provider (404 outside local/testing).
    $router->get('/dev/billing/pay/{paymentId:' . $ulid . '}', [FakePaymentController::class, 'show']);
    $router->post('/dev/billing/pay/{paymentId:' . $ulid . '}', [FakePaymentController::class, 'answer']);
    // Fake social sign-in provider (404 unless DEV_OAUTH_FAKE is on outside production).
    $router->get('/dev/oauth/fake', [DevOAuthController::class, 'show']);
    $router->get('/dev/oauth/fake/approve', [DevOAuthController::class, 'approve']);

    // Design-system showcase and prototypes: the controller answers 404 outside APP_ENV=local|testing.
    $router->get('/dev/ui', [DevUiController::class, 'showcase'])->name('dev.ui');
    $router->get('/dev/proto/onboarding', [DevUiController::class, 'onboarding'])->name('dev.onboarding');
    $router->get('/dev/proto/channels', [DevUiController::class, 'channels'])->name('dev.channels');
    $router->get('/dev/proto/editor', [DevUiController::class, 'editor'])->name('dev.editor');
    $router->get('/dev/proto/calendar', [DevUiController::class, 'calendar'])->name('dev.calendar');
    $router->get('/dev/proto/dashboard', [DevUiController::class, 'dashboard'])->name('dev.dashboard');
    $router->get('/dev/layouts/auth', [DevUiController::class, 'layoutAuth'])->name('dev.layout.auth');
    $router->get('/dev/layouts/landing', [DevUiController::class, 'layoutLanding'])->name('dev.layout.landing');
    $router->get('/dev/layouts/admin', [DevUiController::class, 'layoutAdmin'])->name('dev.layout.admin');
};
