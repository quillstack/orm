<?php

declare(strict_types=1);

namespace Quillstack\Orm\Casting;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Quillstack\Orm\Exceptions\OrmException;

/**
 * Brings what a database hands back to the type a property declares.
 *
 * Drivers are not consistent about this: the same column comes back as an int from one and
 * as a string from another, so an entity typed `int` would be right on one database and
 * broken on the next.
 */
class Caster
{
    public static function to(?string $type, mixed $value): mixed
    {
        if ($type === null || $value === null) {
            return $value;
        }

        return match ($type) {
            'int' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => (bool) $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => self::toObject($type, $value),
        };
    }

    /**
     * What a property holds on its way back to the database.
     */
    public static function from(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    private static function toObject(string $type, mixed $value): mixed
    {
        if (is_a($type, BackedEnum::class, true)) {
            if (!is_int($value) && !is_string($value)) {
                throw new OrmException("Cannot read `{$type}` from what the database returned");
            }

            return $type::from($value);
        }

        if (is_a($type, DateTimeInterface::class, true)) {
            return new DateTimeImmutable(is_scalar($value) ? (string) $value : 'now');
        }

        return $value;
    }
}
