<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsoleAuthorizer::class)]
final class ConsoleAuthorizerTest extends TestCase
{
    public function testBindsAnExplicitNonDefaultSiteToTheTokenAndContext(): void
    {
        $token = str_repeat('a', 40);
        $file = $this->tokenFile($token);
        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::once())->method('verify')->with(
            $token,
            'kumwe-cli',
            'management',
            'corporate',
        )->willReturn(AuthorizationContext::principal(['content.read']));

        try {
            $context = (new ConsoleAuthorizer($tokens))->require([
                'site' => 'Corporate',
                'token-file' => $file,
            ], 'content.read');
        } finally {
            unlink($file);
        }

        self::assertSame('corporate', $context->site()->identifier());
    }

    public function testMissingOrInvalidSiteFailsBeforeReadingOrVerifyingTheToken(): void
    {
        foreach ([null, '../corporate'] as $site) {
            $tokens = $this->createMock(AccessTokenVerifier::class);
            $tokens->expects(self::never())->method('verify');
            $options = ['token-file' => '/not/read'];
            if ($site !== null) {
                $options['site'] = $site;
            }

            try {
                (new ConsoleAuthorizer($tokens))->require($options, 'content.read');
                self::fail('An invalid CLI site selection was accepted.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    /**
     * Proves approval inspection accepts any one independent visibility grant without widening authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRequireAnyAcceptsAnIndependentApprovalVisibilityGrant(): void
    {
        $token = str_repeat('b', 40);
        $file = $this->tokenFile($token);
        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::once())->method('verify')->with(
            $token,
            'kumwe-cli',
            'management',
            'default',
        )->willReturn(AuthorizationContext::principal(['business.approval.approve']));

        try {
            $context = (new ConsoleAuthorizer($tokens))->requireAny([
                'site' => 'default',
                'token-file' => $file,
            ], [
                'business.approval.request',
                'business.approval.approve',
                'business.approval.manage',
            ]);
        } finally {
            unlink($file);
        }

        self::assertSame(AuthorizationContext::SUBJECT, $context->actorId());
    }

    private function tokenFile(string $token): string
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-console-token-');
        if (!is_string($file) || file_put_contents($file, $token) === false || !chmod($file, 0600)) {
            self::fail('A protected test token file could not be created.');
        }

        return $file;
    }
}
