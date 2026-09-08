<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Domain\Policy\BackupDefaults;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\Compression;
use App\Domain\Policy\RetentionPolicy;
use InvalidArgumentException;

final readonly class BackupDefaultsMapper
{
    public const array FIELDS = [
        'defaultBackupMode' => 'default_backup_mode', 'defaultCompression' => 'default_compression',
        'defaultLegacyMaxfiles' => 'default_legacy_maxfiles', 'defaultKeepAll' => 'default_keep_all',
        'defaultKeepLast' => 'default_keep_last', 'defaultKeepHourly' => 'default_keep_hourly',
        'defaultKeepDaily' => 'default_keep_daily', 'defaultKeepWeekly' => 'default_keep_weekly',
        'defaultKeepMonthly' => 'default_keep_monthly', 'defaultKeepYearly' => 'default_keep_yearly',
    ];

    /** @param array<string, mixed> $row */
    public function fromRow(array $row): BackupDefaults
    {
        $mode = $row['default_backup_mode'] ?? null;
        $compression = $row['default_compression'] ?? null;
        $legacy = $this->count($row['default_legacy_maxfiles'] ?? null);
        $keepAll = $row['default_keep_all'] ?? null;
        if (null !== $keepAll && !\in_array($keepAll, [0, 1, '0', '1', false, true], true)) {
            throw new InvalidArgumentException('Invalid default keep-all.');
        }
        $counts = [];
        foreach (['last', 'hourly', 'daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $counts[] = $this->count($row['default_keep_'.$period] ?? null);
        }
        $hasPrune = null !== $keepAll || [] !== array_filter($counts, static fn (?int $value): bool => null !== $value);
        if (null !== $legacy && $hasPrune) {
            throw new InvalidArgumentException('Default legacy retention and prune rules cannot be combined.');
        }
        return new BackupDefaults(
            null === $mode ? null : (BackupMode::tryFrom($this->text($mode)) ?? throw new InvalidArgumentException('Invalid default backup mode.')),
            null === $compression ? null : (Compression::tryFrom($this->text($compression)) ?? throw new InvalidArgumentException('Invalid default compression.')),
            null !== $legacy ? RetentionPolicy::legacyMaxFiles($legacy)
                : ($hasPrune ? RetentionPolicy::prune(null === $keepAll ? null : (bool) $keepAll, ...$counts) : null),
        );
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function payloadData(array $payload): array
    {
        $row = [];
        foreach (self::FIELDS as $field => $column) {
            $row[$column] = $payload[$field] ?? null;
        }
        $this->fromRow($row);
        return $row;
    }

    private function count(mixed $value): ?int
    {
        if (null === $value) return null;
        if (!is_int($value) && !(is_string($value) && 1 === preg_match('/^[1-9][0-9]{0,6}$/D', $value))) {
            throw new InvalidArgumentException('Invalid default retention count.');
        }
        $count = (int) $value;
        if ($count < 1 || $count > 1_000_000) throw new InvalidArgumentException('Invalid default retention count.');
        return $count;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('Invalid default backup option.');
        return $value;
    }
}
