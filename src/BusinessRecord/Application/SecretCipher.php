<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;

interface SecretCipher
{
    public function encrypt(string $plaintext, string $associatedData): EncryptedEnvelope;

    public function decrypt(EncryptedEnvelope $envelope, string $associatedData): string;
}
