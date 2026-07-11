<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class ObjectPropertyInspector
{
    public static function exists(object $object, string $property): bool
    {
        return property_exists($object, $property);
    }
}
