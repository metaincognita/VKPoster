<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\AdminStats;
use App\Domain\Admin\OperationalStats;
use App\Domain\Admin\SystemStatus;
use App\Domain\Settings\Settings;
use App\Http\Admin\AdminInput;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;

/**
 * Admin: operational statistics (publishing, networks, storage, AI) and the state of the system (queue, processes, databases, disk, errors).
 */
final class StatsController
{
    private const PERIODS = ['1' => 'Сутки', '7' => '7 дней', '30' => '30 дней'];

    public function __construct(
        private readonly View $view,
        private readonly OperationalStats $stats,
        private readonly SystemStatus $system,
        private readonly AdminStats $summary,
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    public function publishing(Request $request): Response
    {
        $days = (int) AdminInput::choice($request, 'days', array_map('strval', array_keys(self::PERIODS)), '7');
        $now = $this->clock->now();
        $to = $now->modify('+1 minute');
        $from = $now->modify('-' . $days . ' days');
        $hours = $this->stats->errorsByHour($now, 48);
        $labels = array_map(static fn (string $h): string => substr($h, 8, 2) . '.' . substr($h, 5, 2) . ' ' . substr($h, 11, 5), $hours['hours']);
        $roles = ['telegram' => 'info', 'vk' => 'p', 'max' => 'warn'];
        $datasets = [];
        foreach ($hours['platforms'] as $platform => $values) {
            $datasets[] = ['label' => $platform, 'data' => $values, 'role' => $roles[$platform] ?? 'muted'];
        }
        $off = array_values(array_filter((array) $this->settings->get('platforms.off', []), 'is_string'));

        return $this->view->response('admin/stats.twig', [
            'periods' => self::PERIODS,
            'days' => (string) $days,
            'publications' => $this->stats->publications($from, $to),
            'error_chart' => $datasets === [] ? null : ['title' => 'Доля ошибок по часам, %', 'type' => 'line', 'stacked' => false, 'format' => 'percent', 'currency' => 'RUB', 'labels' => $labels, 'datasets' => $datasets],
            'hour_attempts' => $hours,
            'channels' => $this->summary->channels(),
            'broken_channels' => $this->summary->brokenChannels(10),
            'platforms_enabled' => array_values(array_filter(array_map('strval', $this->config->array('platforms.enabled')), static fn (string $v): bool => $v !== '')),
            'platforms_off' => $off,
            'storage' => $this->stats->storage(),
            'ai' => $this->stats->ai($from, $to),
            'semantic_selection' => $this->stats->semanticSelection($from, $to),
            'content_drafts' => $this->stats->contentDrafts($from, $to),
            'source_text' => $this->stats->sourceText($from, $to),
        ]);
    }

    public function system(): Response
    {
        return $this->view->response('admin/system.twig', [
            'queue' => $this->system->queue(),
            'checks' => $this->system->checks(),
            'build' => $this->system->build(),
            'errors' => $this->system->errors(20),
        ]);
    }
}
