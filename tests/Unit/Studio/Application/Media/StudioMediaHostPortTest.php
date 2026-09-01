<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Media;

use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\Studio\Application\Media\StudioMediaHostPort;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the media port translates App authorization denial into one non-disclosing refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaHostPort::class)]
final class StudioMediaHostPortTest extends TestCase
{
    /**
     * A use-case denial surfaces as the fixed forbidden refusal without policy details.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnAuthorizationDenialIsTranslatedToTheFixedForbiddenRefusal(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/media.get',
            (object) ['assetId' => 'assets/denied'],
        );
        $media = self::createStub(StudioMediaOperations::class);
        $media->method('get')->willThrowException(new AuthorizationDenied(
            'subject-1',
            'media.read',
            'media',
            'assets/denied',
            'default',
            'policy/test-v1',
            'global_grant_required',
        ));
        $port = (new StudioMediaHostPort($media))->forRequest($request->authority);

        try {
            $port->get($request->arguments(), $request->context());
            self::fail('The denied media read was unexpectedly served.');
        } catch (HostRefusal $refused) {
            self::assertSame('forbidden', $refused->error()->category());
            self::assertSame('studio.media/permission-refused', $refused->error()->diagnostics()[0]->code());
            self::assertFalse($refused->commitsState());
        }
    }
}
