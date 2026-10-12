<?php

namespace App\Domain\Library\SmartPlaylist;

/**
 * Describes a smart playlist's rules for clients: each rule as a short English phrase built from the
 * SmartFields labels ("Genre is Rock", "Times played at least 3", "Last played in the last 30 days"),
 * and the whole definition as one sentence ("Genre is Rock and Times played at least 3, up to 50 tracks").
 */
final class SmartPlaylistDescriber
{
    /** The stored (normalized) definition as the API shows it: every rule also carries its phrase in `text`. */
    public static function definition(?array $rules): array
    {
        $definition = SmartPlaylistBuilder::normalize($rules);
        $definition['rules'] = array_map(fn (array $rule) => $rule + ['text' => self::rule($rule)], $definition['rules']);

        return $definition;
    }

    /** One sentence for the whole definition. */
    public static function summary(?array $rules): string
    {
        $definition = SmartPlaylistBuilder::normalize($rules);
        $phrases = array_map(self::rule(...), $definition['rules']);
        $conditions = $phrases === []
            ? 'Every track in the library'
            : implode($definition['match'] === 'any' ? ' or ' : ' and ', $phrases);
        $limit = $definition['limit'];

        return $conditions.', up to '.$limit.' '.($limit === 1 ? 'track' : 'tracks');
    }

    /** @param  array{field: string, operator: string, value: string|int}  $rule  a normalized rule */
    public static function rule(array $rule): string
    {
        $field = SmartFields::find($rule['field']);
        if ($field === null) {
            return $rule['field'];
        }

        $operator = $field->operators[$rule['operator']] ?? $rule['operator'];
        if (!$field->needsValue()) {
            return "{$field->label}: {$operator}";
        }

        $value = $field->type === SmartField::SELECT ? ($field->options()[(string) $rule['value']] ?? $rule['value']) : $rule['value'];
        $unit = $field->unit === '' ? '' : ' '.$field->unit;

        return "{$field->label} {$operator} {$value}{$unit}";
    }
}
