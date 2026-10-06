<?php

declare(strict_types=1);

namespace App\Domain\Content\Processing;

use App\Integrations\Ai\TextProvider;
use RuntimeException;

/** Text-only transformations and deterministic output constraints; no storage, selection or publishing. */
final class TextProcessor
{
    public function __construct(private readonly TextProvider $provider)
    {
    }

    public function providerName(TextSettings $settings): string
    {
        return $settings->mode === 'unchanged' ? 'local' : $this->provider->name();
    }

    /** @param list<array<string, mixed>> $messages */
    public function process(string $original, array $messages, string $username, TextSettings $settings): string
    {
        $input = $original;
        if ($messages !== []) {
            $parts = [];
            foreach ($messages as $message) {
                $text = (string) $message['text'];
                $entities = json_decode((string) $message['entities_json'], true, 32, JSON_THROW_ON_ERROR);
                $ranges = [];
                if (is_array($entities)) {
                    foreach ($entities as $entity) {
                        if (!is_array($entity) || !in_array($entity['_'] ?? '', ['MessageEntityTextUrl', 'MessageEntityUrl'], true) || !is_int($entity['offset'] ?? null) || !is_int($entity['length'] ?? null)) {
                            continue;
                        }
                        $target = is_string($entity['url'] ?? null) ? $entity['url'] : '';
                        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
                        $start = $entity['offset'] * 2;
                        $length = $entity['length'] * 2;
                        if ($start < 0 || $length <= 0 || $start + $length > strlen($utf16) || !mb_check_encoding(substr($utf16, $start, $length), 'UTF-16LE')) {
                            continue;
                        }
                        $label = mb_convert_encoding(substr($utf16, $start, $length), 'UTF-8', 'UTF-16LE');
                        $url = $entity['_'] === 'MessageEntityTextUrl' ? $target : $label;
                        if ((!$settings->keepSource && $this->sourceUrl($url, $username)) || (!$settings->keepLinks && $entity['_'] === 'MessageEntityUrl')) {
                            $ranges[] = [$start, $length, ''];
                        } elseif ($settings->keepLinks && $entity['_'] === 'MessageEntityTextUrl' && strlen($url) <= 2048 && preg_match('~^https?://[^\s<>]+$~iu', $url) === 1) {
                            // Plain-text output must retain hidden Telegram link targets as well as labels.
                            $ranges[] = [$start + $length, 0, mb_convert_encoding(' (' . $url . ')', 'UTF-16LE', 'UTF-8')];
                        }
                    }
                }
                rsort($ranges);
                $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
                foreach ($ranges as [$start, $length, $replacement]) {
                    $utf16 = substr_replace($utf16, $replacement, $start, $length);
                }
                $parts[] = mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
            }
            $input = implode("\n", array_filter($parts, static fn (string $t): bool => $t !== ''));
        }
        $input = $this->constrain($input, $username, $settings, false);
        $output = $settings->mode === 'unchanged' ? $input : $this->provider->generate($input, $settings);
        if (!mb_check_encoding($output, 'UTF-8') || strlen($output) > 200000) {
            throw new RuntimeException('Invalid text output');
        }
        return $this->constrain($output, $username, $settings, true);
    }

    private function sourceUrl(string $url, string $username): bool
    {
        return $username !== '' && preg_match('~^https?://(?:t\.me|telegram\.me)/' . preg_quote($username, '~') . '(?:[/#?]|$)~iu', $url) === 1;
    }

    private function constrain(string $text, string $username, TextSettings $settings, bool $limit): string
    {
        if (!$settings->keepSource && $username !== '') {
            $text = preg_replace('~(?<![\pL\pN_])@' . preg_quote($username, '~') . '(?![\pL\pN_])|(?:https?://)?(?:t\.me|telegram\.me)/' . preg_quote($username, '~') . '(?![\pL\pN_])[^\s]*~iu', '', $text) ?? $text;
        }
        if (!$settings->keepLinks) {
            $text = preg_replace('~(?:https?://|www\.|(?:t|telegram)\.me/)[^\s<>]+~iu', '', $text) ?? $text;
        }
        // Repeat the whole list so removing one phrase cannot expose an earlier forbidden phrase.
        do {
            $before = $text;
            foreach ($settings->forbidden as $phrase) {
                $text = preg_replace('~' . preg_quote($phrase, '~') . '~iu', '', $text) ?? $text;
            }
        } while ($text !== $before);
        if ($limit) {
            $text = mb_substr($text, 0, $settings->maxLength);
        }
        return $text;
    }
}
