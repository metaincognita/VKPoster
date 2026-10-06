<?php

declare(strict_types=1);

namespace App\Domain\Content\VideoProcessing;

use App\Domain\Source\SourceException;
use Symfony\Component\Uid\Ulid;

/** Immutable generation settings; references are resolved within the current workspace before persistence. */
final readonly class VideoSettings
{
    private function __construct(public string $aspectRatio, public int $duration, public string $instruction, public string $textVersion, public string $imageVersion)
    {
    }
    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        $values = [];
        foreach (['aspect_ratio' => '9:16', 'duration' => '10', 'instruction' => '', 'text_version' => '', 'image_version' => ''] as $key => $default) {
            $value = $input[$key] ?? $default;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new SourceException('Проверьте значение поля.', $key);
            }
            $values[$key] = trim($value);
        }
        if (!in_array($values['aspect_ratio'], ['9:16', '16:9', '1:1'], true)) {
            throw new SourceException('Выберите соотношение сторон.', 'aspect_ratio');
        }
        if (!ctype_digit($values['duration']) || (int) $values['duration'] < 1 || (int) $values['duration'] > 60) {
            throw new SourceException('Укажите длительность от 1 до 60 секунд.', 'duration');
        }
        if (mb_strlen($values['instruction']) > 4000) {
            throw new SourceException('Инструкция — до 4000 символов.', 'instruction');
        }
        foreach (['text_version', 'image_version'] as $key) {
            if ($values[$key] !== '' && !Ulid::isValid($values[$key])) {
                throw new SourceException('Выберите актуальную версию.', $key);
            }
        }
        return new self($values['aspect_ratio'], (int) $values['duration'], $values['instruction'], $values['text_version'], $values['image_version']);
    }
    /** @return array<string,string> */
    public function form(): array
    {
        return ['aspect_ratio' => $this->aspectRatio, 'duration' => (string) $this->duration, 'instruction' => $this->instruction, 'text_version' => $this->textVersion, 'image_version' => $this->imageVersion];
    }
}
