<?php

namespace App\Tests\Entity;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenTest extends TestCase
{
    public function testTokenIsSingleUseAndNeverStoresPlainValue(): void
    {
        $plain = 'secure-token-value';
        $token = new PasswordResetToken(new User('a@example.test', 'A', 'B'), hash('sha256', $plain), new \DateTimeImmutable('+1 hour'));
        self::assertTrue($token->isUsable());
        self::assertTrue($token->matches($plain));
        self::assertFalse($token->matches('not-the-token'));
        $token->consume();
        self::assertFalse($token->isUsable());
    }
}
