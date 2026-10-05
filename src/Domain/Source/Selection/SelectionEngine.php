<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

/** Pure evaluator of one complete logical item, including all album captions and Telegram entities. */
final class SelectionEngine
{
    /** @param list<array<string, mixed>> $messages */
    public function evaluate(?SelectionRules $rules, string $text, string $type, array $messages): SelectionResult
    {
        if ($rules === null) {
            return new SelectionResult('needs_review', 'Правила отбора ещё не настроены.', 'rules.not_configured');
        }
        $v = $rules->values;
        $text = mb_strtolower($text);
        preg_match_all('/(?<![\p{L}\p{N}_])#([\p{L}\p{N}_]+)/u', $text, $matches);
        $hashtags = $matches[1];
        $matched = [];
        foreach (['keywords', 'hashtags'] as $category) {
            foreach (['exclude', 'include'] as $kind) {
                $key = $kind . '_' . $category;
                $list = $v[$key];
                $matched[$key] = false;
                foreach (is_array($list) ? $list : [] as $word) {
                    if ($category === 'keywords' ? str_contains($text, $word) : in_array($word, $hashtags, true)) {
                        $matched[$key] = true;
                    }
                }
            }
        }
        foreach (['exclude_keywords', 'exclude_hashtags'] as $key) {
            if ($matched[$key]) {
                return new SelectionResult('rejected', $key === 'exclude_keywords' ? 'Найдено исключающее ключевое слово.' : 'Найден исключающий хэштег.', $key);
            }
        }
        foreach (['include_keywords', 'include_hashtags'] as $key) {
            if ($v[$key] !== [] && !$matched[$key]) {
                return new SelectionResult('rejected', $key === 'include_keywords' ? 'Нет включающих ключевых слов.' : 'Нет включающих хэштегов.', $key);
            }
        }
        if ($v['content_types'] !== [] && (!is_array($v['content_types']) || !in_array($type, $v['content_types'], true))) {
            return new SelectionResult('rejected', 'Тип материала не подходит.', 'content_types');
        }
        $links = preg_match('~(?:https?://|www\.)\S+~iu', $text) === 1;
        $forwarded = false;
        $unknownForward = $messages === [];
        foreach ($messages as $message) {
            $entities = $message['entities'] ?? [];
            foreach (is_array($entities) ? $entities : [] as $entity) {
                if (is_array($entity) && in_array($entity['_'] ?? $entity['type'] ?? '', ['MessageEntityUrl', 'MessageEntityTextUrl', 'url', 'text_link'], true)) {
                    $links = true;
                }
            }
            $forward = $message['forward'] ?? null;
            if ($forward !== null) {
                $forwarded = true;
            } elseif (($message['forward_known'] ?? false) !== true) {
                $unknownForward = true;
            }
        }
        if ($v['links'] !== 'any' && $links !== ($v['links'] === 'yes')) {
            return new SelectionResult('rejected', $links ? 'Материал содержит ссылку.' : 'Материал не содержит ссылок.', 'links');
        }
        if ($v['forwarded'] !== 'any') {
            if (!$forwarded && $unknownForward) {
                return new SelectionResult('needs_review', 'Нет достоверных данных о пересылке.', 'forwarded.unknown');
            }
            if ($forwarded !== ($v['forwarded'] === 'yes')) {
                return new SelectionResult('rejected', $forwarded ? 'Материал является пересылкой.' : 'Материал не является пересылкой.', 'forwarded');
            }
        }
        return new SelectionResult('approved', 'Материал соответствует всем правилам.', 'rules.matched');
    }
}
