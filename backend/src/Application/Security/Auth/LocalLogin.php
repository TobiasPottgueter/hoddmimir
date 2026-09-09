<?php

declare(strict_types=1);

namespace App\Application\Security\Auth;

use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Audit\SecurityAuditRecorder;
use App\Application\Security\PlaintextSecret;
use App\Domain\Security\GlobalLoginThrottlePolicy;
use App\Domain\Security\LoginThrottlePolicy;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\SessionPolicy;
use App\Domain\Shared\Clock;
use SensitiveParameter;

final readonly class LocalLogin
{
    private const string DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$GTJ95i3XUCxgMcOl0Fh+8w$dQLhmdQxd4npp03DfS+oQOpobOZ2cdR47ykxbKaLuQg';
    public function __construct(
        private SecurityTransaction $transaction,
        private AuthenticationStore $identities,
        private LoginThrottleStore $throttles,
        private WebSessionStore $sessions,
        private PasswordHasher $passwords,
        private OpaqueTokenGenerator $tokens,
        private CsrfTokenDeriver $csrfTokens,
        private OpaqueSecretHasher $digests,
        private SecurityIdentifierGenerator $ids,
        private SecurityAuditRecorder $audit,
        private Clock $clock,
        private LoginThrottlePolicy $throttlePolicy = new LoginThrottlePolicy(),
        private GlobalLoginThrottlePolicy $globalThrottlePolicy = new GlobalLoginThrottlePolicy(),
        private SessionPolicy $sessionPolicy = new SessionPolicy(),
    ) {
    }

    public function authenticate(
        string $username,
        #[SensitiveParameter] string $password,
        string $packedIp,
        string $correlationId,
    ): LoginResult {
        $normalized = new NormalizedUsername($username);
        $secret = PlaintextSecret::fromString($password);

        $result = $this->transaction->run(function () use ($normalized, $secret, $packedIp, $correlationId): ?LoginResult {
            $now = $this->clock->now();
            $this->throttles->performMaintenance($now);
            $globalThrottle = $this->throttles->lockGlobalThrottle();
            $ipThrottle = $this->throttles->lockIpThrottle($packedIp);
            $throttle = $this->throttles->lockThrottle($normalized, $packedIp);
            if ($globalThrottle->isLockedAt($now)
                || (null !== $ipThrottle && $ipThrottle->isLockedAt($now))
                || (null !== $throttle && $throttle->isLockedAt($now))) {
                return null;
            }

            $identity = $this->identities->findEnabled($normalized);
            $verificationHash = null === $identity ? new PasswordHash(self::DUMMY_PASSWORD_HASH) : $identity->passwordHash;
            $passwordMatches = $this->passwords->verify($secret, $verificationHash);
            if (null === $identity || !$passwordMatches) {
                $this->throttles->saveGlobalThrottle($this->globalThrottlePolicy->recordFailure($globalThrottle, $now));
                $ipThrottle = null === $ipThrottle
                    ? $this->throttlePolicy->firstFailure($now)
                    : $this->throttlePolicy->recordFailure($ipThrottle, $now);
                $this->throttles->saveIpThrottle($packedIp, $ipThrottle);
                if (null !== $identity) {
                    $throttle = null === $throttle
                        ? $this->throttlePolicy->firstFailure($now)
                        : $this->throttlePolicy->recordFailure($throttle, $now);
                    $this->throttles->save($normalized, $packedIp, $throttle);
                }
                $this->audit->record(AuditEventType::LoginFailed, AuditOutcome::Denied, $correlationId, reasonCode: 'authentication_failed');
                return null;
            }

            $this->throttles->clearIpThrottle($packedIp);
            $this->throttles->clear($normalized, $packedIp);
            $sessionToken = $this->tokens->generate();
            $csrfToken = $this->csrfTokens->derive($sessionToken);
            $window = $this->sessionPolicy->issue($now);
            $sessionId = $this->ids->generate();
            $this->sessions->create(
                $sessionId,
                $identity->userId,
                $this->digests->digestToken($sessionToken),
                $this->digests->digestToken($csrfToken),
                $window,
            );
            $principal = new AuthenticatedPrincipal($identity->userId, $identity->username, $identity->permissions);
            $this->audit->record(AuditEventType::LoginSucceeded, AuditOutcome::Succeeded, $correlationId, $identity->userId, $sessionId, 'session', $sessionId);
            $this->audit->record(AuditEventType::SessionCreated, AuditOutcome::Succeeded, $correlationId, $identity->userId, $sessionId, 'session', $sessionId);

            return new LoginResult($sessionToken, $csrfToken, $principal, $window);
        });
        if (null === $result) {
            throw new AuthenticationFailed();
        }

        return $result;
    }
}
