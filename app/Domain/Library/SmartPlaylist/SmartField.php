<?php

namespace App\Domain\Library\SmartPlaylist;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * One thing a smart playlist rule can test about a track. `apply` adds the
 * conditions for an operator and value to a track query; the builder combines
 * the rules with AND or OR around it.
 */
final class SmartField
{
    public const SELECT = 'select';

    public const NUMBER = 'number';

    public const TEXT = 'text';

    /** A yes/no test: the operators carry the answer, there is no value. */
    public const BOOL = 'bool';

    /**
     * @param  array<string, string>  $operators  operator => label
     * @param  (Closure(): array<string, string>)|null  $options  value => label of a select field
     * @param  Closure(Builder, string, mixed): void  $apply
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $operators,
        public readonly Closure $apply,
        private readonly ?Closure $options = null,
        public readonly string $unit = '',
    ) {}

    /** @return array<string, string> */
    public function options(): array
    {
        return $this->options ? ($this->options)() : [];
    }

    public function hasOperator(string $operator): bool
    {
        return isset($this->operators[$operator]);
    }

    public function needsValue(): bool
    {
        return $this->type !== self::BOOL;
    }

    /** A usable value for this field, null when it is missing or not one of the options / not a number. */
    public function cleanValue(mixed $value): string|int|null
    {
        if (!$this->needsValue()) {
            return '';
        }

        return match ($this->type) {
            self::NUMBER => is_numeric($value) && $value >= 0 ? (int) $value : null,
            self::SELECT => is_scalar($value) && isset($this->options()[(string) $value]) ? (string) $value : null,
            default => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null,
        };
    }

    public function apply(Builder $query, string $operator, mixed $value): void
    {
        ($this->apply)($query, $operator, $value);
    }
}
