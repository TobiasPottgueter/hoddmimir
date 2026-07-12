<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveStorageIdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveStorageIdValidatorTest extends TestCase
{
    #[DataProvider('canonicalIds')]
    public function testCanonicalBoundariesAreAccepted(string $value): void
    {
        self::assertTrue(PveStorageIdValidator::isValid($value));
    }

    /** @return iterable<string, array{string}> */
    public static function canonicalIds(): iterable
    {
        yield 'minimum lowercase' => ['aa'];
        yield 'minimum uppercase' => ['AZ'];
        yield 'digit suffix' => ['a0'];
        yield 'every separator inside' => ['a.-_Z9'];
        yield 'all letter boundaries' => ['aAzZ'];
        yield 'all digit boundaries' => ['a09'];
    }

    #[DataProvider('nonCanonicalIds')]
    public function testEveryGrammarBoundaryRejectsNonCanonicalInput(string $value): void
    {
        self::assertFalse(PveStorageIdValidator::isValid($value));
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalIds(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte' => ['a'];
        yield 'digit first' => ['0a'];
        yield 'separator first' => ['-a'];
        yield 'dot last' => ['a.'];
        yield 'underscore last' => ['a_'];
        yield 'hyphen last' => ['a-'];
        yield 'slash middle' => ['a/b'];
        yield 'space middle' => ['a b'];
        yield 'lower ascii before A' => ['a@b'];
        yield 'ascii between Z and a' => ['a[b'];
        yield 'ascii after z' => ['a{b'];
        yield 'ascii before zero' => ['a/b'];
        yield 'ascii after nine' => ['a:b'];
        yield 'unicode' => ['aä'];
    }
}
