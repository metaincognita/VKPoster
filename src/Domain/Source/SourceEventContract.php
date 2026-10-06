<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Kernel\Exception\HttpException;
use App\Support\DbTime;
use DateTimeImmutable;

/** Validates v1 reader events before writing; payloads contain metadata, never binary media or credentials. */
final class SourceEventContract
{
    /** @param array<string, mixed> $event */
    public function validate(array $event): void
    {
        if (($event['version'] ?? null) !== 1 || !is_string($event['event_id'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $event['event_id']) !== 1
            || !is_string($event['source_id'] ?? null) || preg_match('/\A[0-9A-Z]{26}\z/', $event['source_id']) !== 1) {
            throw new HttpException(422, 'Invalid event envelope');
        }
        if (isset($event['connection_version']) && (!is_int($event['connection_version']) || $event['connection_version'] < 1)) {
            throw new HttpException(422, 'Invalid source connection');
        }
        $payload = $event['payload'] ?? null;
        if (!is_array($payload) || strlen(json_encode($event, JSON_THROW_ON_ERROR)) > 524288 || $this->containsSecret($event)) {
            throw new HttpException(422, 'Invalid payload');
        }
        if (($event['kind'] ?? null) === 'status') {
            if (!in_array($payload['status'] ?? null, ['connected', 'error'], true)) {
                throw new HttpException(422, 'Invalid status');
            }
            if ($payload['status'] === 'connected') {
                $this->id($payload['peer_id'] ?? null);
            }
            if (isset($payload['error_code']) && !in_array($payload['error_code'], ['resolve_failed', 'read_failed', 'auth_required'], true)) {
                throw new HttpException(422, 'Invalid error code');
            }
            return;
        }
        if (($event['kind'] ?? null) !== 'item') {
            throw new HttpException(422, 'Invalid kind');
        }
        $this->id($payload['peer_id'] ?? null);
        $group = $payload['grouped_id'] ?? null;
        if ($group !== null) {
            $this->id($group);
        }
        $messages = $payload['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages) || $messages === [] || count($messages) > 100 || ($group === null && count($messages) !== 1)) {
            throw new HttpException(422, 'Invalid messages');
        }
        $seen = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !is_int($message['message_id'] ?? null) || $message['message_id'] < 1 || $message['message_id'] > 2147483647
                || ($message['channel_id'] ?? null) !== $payload['peer_id'] || ($message['grouped_id'] ?? null) !== $group
                || !is_string($message['text'] ?? null) || mb_strlen($message['text']) > 65536
                || !is_array($message['entities'] ?? null) || !array_is_list($message['entities'])
                || !is_string($message['content_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $message['content_hash']) !== 1
                || (($message['media'] ?? null) !== null && !is_array($message['media'])) || isset($seen[$message['message_id']])) {
                throw new HttpException(422, 'Invalid message');
            }
            $seen[$message['message_id']] = true;
            $date = $this->date($message['date'] ?? null);
            if (($message['edit_date'] ?? null) !== null && $this->date($message['edit_date']) < $date) {
                throw new HttpException(422, 'Invalid edit date');
            }
        }
    }

    public function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+]00:00)\z/', $value) !== 1) {
            throw new HttpException(422, 'Invalid UTC date');
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new HttpException(422, 'Invalid date');
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new HttpException(422, 'Invalid date');
        }
        return DbTime::format($date);
    }

    private function id(mixed $id): void
    {
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,19}\z/', $id) !== 1) {
            throw new HttpException(422, 'Invalid Telegram id');
        }
    }

    /** @param array<array-key, mixed> $data */
    private function containsSecret(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/access_hash|file_reference|api_hash|session|phone|otp|authorization|secret|token/i', $key) === 1) {
                return true;
            }
            if (is_array($value) && $this->containsSecret($value)) {
                return true;
            }
        }
        return false;
    }
}
