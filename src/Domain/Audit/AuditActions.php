<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Workspace\Role;

/**
 * Human-readable names of audit actions for the workspace journal. Actions missing from the map are
 * shown by their technical name, so a new event is never hidden.
 */
final class AuditActions
{
    /** @var array<string, string> */
    private const LABELS = [
        'source.images_requested' => 'Запрошена обработка изображений',
        'source.images_completed' => 'Изображения сохранены',
        'source.images_failed' => 'Ошибка обработки изображений',
        'source.images_stale' => 'Попытка обработки изображений устарела',
        'source.image_selected' => 'Выбрана версия изображения',
        'selection.semantic_settings_updated' => 'Обновлены настройки смыслового отбора',
        'discovery.refreshed' => 'Радар обновлён',
        'discovery.ignored' => 'Тема радара проигнорирована',
        'discovery.imported' => 'Материал радара импортирован',
        'discovery.material_approved' => 'Материал радара принят',
        'discovery.material_rejected' => 'Материал радара отклонён',
        'source.video_requested' => 'Создано задание генерации видео',
        'source.video_started' => 'Начата генерация видео',
        'source.video_completed' => 'Генерация видео завершена',
        'source.video_failed' => 'Ошибка генерации видео',
        'source.video_selected' => 'Выбрана итоговая версия видео',
        'content.draft_created' => 'Создан черновик из материала',
        'source.text_started' => 'Начата обработка текста',
        'source.text_completed' => 'Текст обработан',
        'source.text_failed' => 'Ошибка обработки текста',
        'source.text_stale' => 'Обработка текста устарела',
        'source.rules_updated' => 'Изменены правила отбора',
        'source.item_approved' => 'Материал принят вручную',
        'source.item_rejected' => 'Материал отклонён вручную',
        'source.created' => 'Добавлен источник',
        'source.updated' => 'Изменён источник',
        'workspace.created' => 'Создано пространство',
        'workspace.updated' => 'Изменены настройки пространства',
        'workspace.deleted' => 'Пространство удалено',
        'workspace.ownership_transferred' => 'Владение передано',
        'member.invited' => 'Приглашён участник',
        'member.invitation_revoked' => 'Приглашение отозвано',
        'member.joined' => 'Участник присоединился',
        'member.role_changed' => 'Изменена роль',
        'member.removed' => 'Участник исключён',
        'member.left' => 'Участник вышел',
        'channel.connected' => 'Подключён канал',
        'channel.disconnected' => 'Отключён канал',
        'channel.paused' => 'Канал поставлен на паузу',
        'channel.resumed' => 'Канал возобновлён',
        'channel.renamed' => 'Канал переименован',
        'media.deleted' => 'Удалён файл из медиатеки',
        'media.watermark_created' => 'Добавлен водяной знак',
        'media.watermark_updated' => 'Изменён водяной знак',
        'media.watermark_deleted' => 'Удалён водяной знак',
        'post.created' => 'Создан черновик поста',
        'post.updated' => 'Изменён пост',
        'post.published' => 'Опубликован пост',
        'post.publish_now' => 'Пост отправлен на публикацию',
        'post.scheduled' => 'Запланирован пост',
        'post.rescheduled' => 'Пост перенесён',
        'post.cancelled' => 'Пост отменён',
        'post.duplicated' => 'Пост продублирован',
        'post.deleted' => 'Пост удалён',
        'post.retried' => 'Публикация запущена повторно',
        'post.settled_sent' => 'Отмечено: пост вышел',
        'post.settled_dropped' => 'Отмечено: пост не вышел',
        'post.removed_from_network' => 'Пост удалён из соцсети',
        'post.edited_published' => 'Изменён текст опубликованного поста',
        'template.created' => 'Создан шаблон поста',
        'template.deleted' => 'Удалён шаблон поста',
        'billing.payment' => 'Платёж',
    ];

    /** @var array<string, string> filter value => label of the group of actions */
    private const GROUPS = [
        'source' => 'Источники',
        'workspace' => 'Пространство',
        'member' => 'Команда',
        'channel' => 'Каналы',
        'media' => 'Медиатека',
        'post' => 'Публикации',
        'billing' => 'Оплата',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * One-line details of an entry for the journal, built from its metadata (never the raw JSON, so nothing
     * unexpected can leak onto the page). Empty when the action says everything itself.
     *
     * @param array<string, mixed> $meta
     */
    public static function describe(string $action, array $meta): string
    {
        $text = static fn (string $key): string => is_scalar($meta[$key] ?? null) ? (string) $meta[$key] : '';
        $role = static function (string $key) use ($text): string {
            $value = $text($key);

            return Role::tryFrom($value)?->label() ?? $value;
        };
        $parts = match ($action) {
            'source.created', 'source.updated' => [$text('name') === '' ? '' : '«' . $text('name') . '»'],
            'member.invited', 'member.invitation_revoked', 'member.removed' => [$text('email'), $role('role')],
            'member.joined', 'member.left' => [$role('role')],
            'member.role_changed' => [($text('email') === '' ? '' : $text('email') . ': ') . $role('from') . ' → ' . $role('to')],
            'workspace.updated' => [$text('name') === '' ? '' : '«' . $text('name') . '»', $text('timezone')],
            'media.deleted', 'media.watermark_created', 'media.watermark_updated', 'media.watermark_deleted' => [$text('name') === '' ? '' : '«' . $text('name') . '»'],
            'channel.connected', 'channel.disconnected', 'channel.paused', 'channel.resumed', 'channel.renamed' => [$text('name') === '' ? '' : '«' . $text('name') . '»', $text('platform')],
            'post.created', 'post.updated', 'post.scheduled', 'post.publish_now', 'post.rescheduled', 'post.cancelled', 'post.duplicated', 'post.deleted', 'post.published', 'post.edited_published' => [$text('title') === '' ? '' : '«' . $text('title') . '»'],
            'template.created', 'template.deleted' => [$text('name') === '' ? '' : '«' . $text('name') . '»'],
            'workspace.deleted' => [$text('name') === '' ? '' : '«' . $text('name') . '»'],
            'workspace.created' => [($meta['personal'] ?? false) === true ? 'личное пространство' : ''],
            'workspace.ownership_transferred' => [$text('email') === '' ? '' : 'новый владелец: ' . $text('email')],
            default => [],
        };

        return implode(' · ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return self::GROUPS;
    }
}
