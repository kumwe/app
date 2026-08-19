<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Identity\Domain;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailAddress::class)]
final class EmailAddressTest extends TestCase
{
    public function testNormalizesAndComparesAddresses(): void
    {
        $email = EmailAddress::fromString(' Person@Example.COM ');

        self::assertSame('person@example.com', $email->value());
        self::assertSame('person@example.com', (string) $email);
        self::assertTrue($email->equals(EmailAddress::fromString('person@example.com')));
    }

    public function testRejectsAnInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailAddress::fromString("not-an-email\r\nBcc: victim@example.com");
    }
}
