<?php

declare(strict_types=1);

namespace RaceProof\Laravel\Tests\Unit;

use Closure;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RaceProof\Laravel\Exceptions\RaceProofException;
use RaceProof\Laravel\Support\ConfigValue;

final class ConfigValueTest extends TestCase
{
    public function test_it_returns_typed_values_and_defaults(): void
    {
        $config = new Repository([
            'string' => 'value',
            'integer' => 10,
            'boolean' => true,
            'list' => ['one', 'two'],
        ]);

        self::assertSame('value', ConfigValue::string($config, 'string'));
        self::assertSame(10, ConfigValue::integer($config, 'integer', 5));
        self::assertSame(5, ConfigValue::integer($config, 'missing-integer', 5));
        self::assertTrue(ConfigValue::boolean($config, 'boolean', false));
        self::assertFalse(ConfigValue::boolean($config, 'missing-boolean', false));
        self::assertSame(['one', 'two'], ConfigValue::stringList($config, 'list'));
        self::assertSame([], ConfigValue::stringList($config, 'missing-list'));
    }

    /** @return iterable<string, array{Closure(Repository): void}> */
    public static function invalidValues(): iterable
    {
        yield 'string' => [static fn (Repository $config) => ConfigValue::string($config, 'invalid-string')];
        yield 'integer' => [static fn (Repository $config) => ConfigValue::integer($config, 'invalid-integer', 1)];
        yield 'boolean' => [static fn (Repository $config) => ConfigValue::boolean($config, 'invalid-boolean', false)];
        yield 'associative list' => [static fn (Repository $config) => ConfigValue::stringList($config, 'associative')];
        yield 'non-string member' => [static fn (Repository $config) => ConfigValue::stringList($config, 'member')];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_mistyped_configuration(Closure $operation): void
    {
        $config = new Repository([
            'invalid-string' => 123,
            'invalid-integer' => '123',
            'invalid-boolean' => 1,
            'associative' => ['name' => 'value'],
            'member' => ['valid', 123],
        ]);

        $this->expectException(RaceProofException::class);

        $operation($config);
    }
}
