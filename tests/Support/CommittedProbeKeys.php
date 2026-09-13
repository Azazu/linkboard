<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Uid\Uuid;

/**
 * Users and API keys written through a plain, bounded PDO and COMMITTED — the
 * deep-probe lookup runs in a child process (and the prod kernel in another),
 * so rows inside the suite's rolled-back transaction would be invisible to
 * them. Everything created is deleted by cleanup() (keys cascade from users).
 */
final class CommittedProbeKeys
{
    /** @var list<string> */
    private array $userIds = [];

    private function __construct(private readonly \PDO $pdo)
    {
    }

    public static function connect(string $databaseUrl): self
    {
        $parts = parse_url($databaseUrl);
        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('not a database URL');
        }
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/'));

        return new self(new \PDO($dsn, $parts['user'] ?? null, isset($parts['pass']) ? rawurldecode($parts['pass']) : null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 2,
        ]));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** @return string the user's id */
    public function user(bool $admin = true, bool $blocked = false): string
    {
        $id = Uuid::v7()->toRfc4122();
        $statement = $this->pdo->prepare('INSERT INTO users (id, email, password_hash, roles, is_blocked, created_at, updated_at) VALUES (:id, :email, :hash, :roles, :blocked, now(), now())');
        $statement->bindValue('id', $id);
        $statement->bindValue('email', 'probe-'.bin2hex(random_bytes(6)).'@example.com');
        $statement->bindValue('hash', 'not-a-real-hash');
        $statement->bindValue('roles', json_encode($admin ? ['ROLE_ADMIN'] : [], \JSON_THROW_ON_ERROR));
        $statement->bindValue('blocked', $blocked, \PDO::PARAM_BOOL);
        $statement->execute();
        $this->userIds[] = $id;

        return $id;
    }

    /** @return string the key's plaintext (`lb_` + 40 characters) */
    public function key(string $userId, ?\DateTimeImmutable $expiresAt = null, bool $revoked = false): string
    {
        $plaintext = 'lb_'.substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(40))), 0, 40);
        $statement = $this->pdo->prepare('INSERT INTO api_keys (id, user_id, name, key_hash, prefix, expires_at, revoked_at, created_at) VALUES (:id, :user, :name, :hash, :prefix, :expires, :revoked, now())');
        $statement->execute([
            'id' => Uuid::v7()->toRfc4122(),
            'user' => $userId,
            'name' => 'probe monitor',
            'hash' => hash('sha256', $plaintext),
            'prefix' => substr($plaintext, 0, 8),
            'expires' => $expiresAt?->format(\DATE_ATOM),
            'revoked' => $revoked ? (new \DateTimeImmutable('-1 hour'))->format(\DATE_ATOM) : null,
        ]);

        return $plaintext;
    }

    public function revoke(string $plaintext): void
    {
        $statement = $this->pdo->prepare('UPDATE api_keys SET revoked_at = now() WHERE key_hash = :hash');
        $statement->execute(['hash' => hash('sha256', $plaintext)]);
    }

    public function lastUsedAt(string $plaintext): ?string
    {
        $statement = $this->pdo->prepare('SELECT last_used_at FROM api_keys WHERE key_hash = :hash');
        $statement->execute(['hash' => hash('sha256', $plaintext)]);
        $value = $statement->fetchColumn();

        return \is_string($value) ? $value : null;
    }

    /** Holds `LOCK TABLE api_keys IN ACCESS EXCLUSIVE MODE` until unlock(): a lookup on the table stalls. */
    public function lockApiKeys(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('LOCK TABLE api_keys IN ACCESS EXCLUSIVE MODE');
    }

    public function unlock(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function cleanup(): void
    {
        $this->unlock();
        foreach ($this->userIds as $id) {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute(['id' => $id]);
        }
        $this->userIds = [];
    }
}
