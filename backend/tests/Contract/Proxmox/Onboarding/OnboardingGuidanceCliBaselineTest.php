<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingGuidanceProvider;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use PHPUnit\Framework\TestCase;

final class OnboardingGuidanceCliBaselineTest extends TestCase
{
    public function testEveryGuidanceCommandIsSupportedByEveryPinnedOfficialCliSchema(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 5).'/docs/proxmox-official-cli-baseline.json');
        self::assertIsString($raw);
        $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['format', 'sources'], array_keys($document));
        self::assertSame(1, $document['format']);
        $sources = $document['sources'];
        self::assertIsArray($sources);
        self::assertSame(['pve-7', 'pve-8', 'pve-9', 'pbs-3', 'pbs-4'], array_column($sources, 'id'));

        foreach ($sources as $source) {
            self::assertIsArray($source);
            self::assertSame(['id', 'product', 'major', 'repository', 'ref', 'files', 'commands'], array_keys($source));
            $ref = $this->string($source['ref'] ?? null);
            $repository = $this->string($source['repository'] ?? null);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/D', $ref);
            self::assertStringStartsWith('https://git.proxmox.com/', $repository);
            self::assertIsArray($source['files']);
            foreach ($source['files'] as $path => $hash) {
                self::assertIsString($path);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $this->string($hash));
            }
            $product = OnboardingProduct::from($this->string($source['product'] ?? null));
            $commands = (new OnboardingGuidanceProvider())->guidance($product)->toArray()['commands'];
            $schemas = $this->schemas($source['commands'] ?? null);
            foreach ($commands as $command) {
                $text = $command['command'];
                self::assertTrue($this->commandMatches($text, $schemas), sprintf(
                    '%s violates the positional/option schema of %s.',
                    $text,
                    $this->string($source['id'] ?? null),
                ));
            }
        }
    }

    public function testMatcherRejectsMissingAndExtraPositionalsAndHonorsQuotedValues(): void
    {
        $schemas = [
            'pveum user token add' => ['positionals' => 2, 'options' => ['privsep']],
            'pveum role add' => ['positionals' => 1, 'options' => ['privs']],
        ];
        self::assertTrue($this->commandMatches("pveum role add Role --privs 'VM.Audit Sys.Audit'", $schemas));
        self::assertTrue($this->commandMatches('pveum user token add user@pve scan --privsep 1', $schemas));
        self::assertFalse($this->commandMatches('pveum user token add user@pve --privsep 1', $schemas));
        self::assertFalse($this->commandMatches('pveum user token add user@pve scan extra --privsep 1', $schemas));
        self::assertFalse($this->commandMatches('pveum user token add user@pve scan --unknown 1', $schemas));
        self::assertFalse($this->commandMatches('pveum user token add user@pve scan --privsep', $schemas));
    }

    /**
     * @param array<string, array{positionals: int, options: list<string>}> $schemas
     */
    private function commandMatches(string $command, array $schemas): bool
    {
        $tokens = $this->shellTokens($command);
        if (null === $tokens) {
            return false;
        }
        $prefix = null;
        $prefixLength = 0;
        foreach (array_keys($schemas) as $candidate) {
            $candidateTokens = explode(' ', $candidate);
            if (count($candidateTokens) <= $prefixLength
                || array_slice($tokens, 0, count($candidateTokens)) !== $candidateTokens) {
                continue;
            }
            $prefix = $candidate;
            $prefixLength = count($candidateTokens);
        }
        if (null === $prefix) {
            return false;
        }
        $schema = $schemas[$prefix];
        $positionals = 0;
        for ($index = $prefixLength; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            if (!str_starts_with($token, '--')) {
                ++$positionals;
                continue;
            }
            $option = substr($token, 2);
            if (!in_array($option, $schema['options'], true) || !isset($tokens[$index + 1])
                || str_starts_with($tokens[$index + 1], '--')) {
                return false;
            }
            ++$index;
        }

        return $positionals === $schema['positionals'];
    }

    /** @return list<string>|null */
    private function shellTokens(string $command): ?array
    {
        $tokens = [];
        $token = '';
        $quote = null;
        $escaped = false;
        for ($index = 0; $index < strlen($command); ++$index) {
            $character = $command[$index];
            if ($escaped) {
                $token .= $character;
                $escaped = false;
                continue;
            }
            if ('\\' === $character && "'" !== $quote) {
                $escaped = true;
                continue;
            }
            if (null !== $quote) {
                if ($character === $quote) {
                    $quote = null;
                } else {
                    $token .= $character;
                }
                continue;
            }
            if ("'" === $character || '"' === $character) {
                $quote = $character;
                continue;
            }
            if (' ' === $character) {
                if ('' !== $token) {
                    $tokens[] = $token;
                    $token = '';
                }
                continue;
            }
            $token .= $character;
        }
        if (null !== $quote || $escaped) {
            return null;
        }
        if ('' !== $token) {
            $tokens[] = $token;
        }
        return $tokens;
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return array<string, array{positionals: int, options: list<string>}> */
    private function schemas(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $command => $schema) {
            self::assertIsString($command);
            self::assertIsArray($schema);
            self::assertSame(['positionals', 'options'], array_keys($schema));
            $positionals = $schema['positionals'] ?? null;
            $options = $schema['options'] ?? null;
            self::assertIsInt($positionals);
            self::assertGreaterThanOrEqual(0, $positionals);
            self::assertIsArray($options);
            self::assertContainsOnlyString($options);
            /** @var list<string> $options */
            $result[$command] = ['positionals' => $positionals, 'options' => $options];
        }

        return $result;
    }
}
