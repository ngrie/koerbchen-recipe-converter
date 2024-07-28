<?php

declare(strict_types=1);

namespace Ngrie\KoerbchenRecipeConverter\Util;

final readonly class FirebaseHelper
{
    public static function getValue(?array $structure): mixed
    {
        if (null === $structure) {
            return null;
        }

        $keys = array_values(array_filter(
            array_keys($structure),
            static fn (string $key) => str_ends_with($key, 'Value'),
        ));
        if (0 === count($keys)) {
            throw new \InvalidArgumentException('Given structure does not contain "*Value" elements.');
        }

        $value = $structure[$keys[0]];

        if ('integerValue' === $keys[0]) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
            if (false === $value) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a valid integer.', $structure[$keys[0]]));
            }
        }

        if ('arrayValue' === $keys[0] && 0 === count($value)) {
            return null;
        }

        if (is_string($value) && '' === trim($value)) {
            $value = null;
        }

        return $value;
    }
}
