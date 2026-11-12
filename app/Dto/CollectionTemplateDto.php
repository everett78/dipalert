<?php

namespace App\Dto;

use App\Models\Product;

/**
 * What a collection suggests to its products: change thresholds and the
 * attribute fields.
 */
final class CollectionTemplateDto
{
    public const string TYPE_TEXT = 'text';

    public const string TYPE_NUMBER = 'number';

    /**
     * @param  list<array{key: string, label: string, type: string, unit: ?string}>  $attributes
     */
    public function __construct(
        public readonly ?float $notifyChangePercent = null,
        public readonly ?float $notifyChangeAmount = null,
        public readonly ?string $notifyChangeDirection = null,
        public readonly array $attributes = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        $attributes = [];

        foreach ((array) ($data['attributes'] ?? []) as $field) {
            $key = trim((string) data_get($field, 'key', ''));

            if ($key === '') {
                continue;
            }

            $type = data_get($field, 'type') === self::TYPE_NUMBER ? self::TYPE_NUMBER : self::TYPE_TEXT;
            $unit = data_get($field, 'unit');

            $attributes[] = [
                'key' => $key,
                'label' => (string) (data_get($field, 'label') ?: $key),
                'type' => $type,
                'unit' => filled($unit) ? (string) $unit : null,
            ];
        }

        $direction = data_get($data, 'notify_change_direction');

        return new self(
            notifyChangePercent: self::number(data_get($data, 'notify_change_percent')),
            notifyChangeAmount: self::number(data_get($data, 'notify_change_amount')),
            notifyChangeDirection: in_array($direction, [Product::CHANGE_DOWN, Product::CHANGE_UP, Product::CHANGE_BOTH], true) ? $direction : null,
            attributes: $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'notify_change_percent' => $this->notifyChangePercent,
            'notify_change_amount' => $this->notifyChangeAmount,
            'notify_change_direction' => $this->notifyChangeDirection,
            'attributes' => $this->attributes,
        ];
    }

    protected static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
