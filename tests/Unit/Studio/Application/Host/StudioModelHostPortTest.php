<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Studio\Application\Host\StudioModelHostPort;
use Kumwe\App\Studio\Application\Projection\StudioContentProjectionService;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\StudioProducerRequest;
use Kumwe\Producer\Error\HostRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

/**
 * Proves the read-only model port collapses projection denial into one non-disclosing refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioModelHostPort::class)]
final class StudioModelHostPortTest extends TestCase
{
    /**
     * A denied Content listing is answered as not-found without disclosing the policy reason.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testADeniedModelListIsAnsweredAsNotFound(): void
    {
        $request = StudioProducerRequest::authorized(
            'studio.operation/model.list',
            new stdClass(),
            capabilities: ['studio.mode.content'],
        );
        $port = (new StudioModelHostPort(self::projectionWithoutContentRead()))
            ->forRequest($request->authority);

        try {
            $port->list($request->arguments(), $request->context());
            self::fail('The denied model listing was unexpectedly served.');
        } catch (HostRefusal $refused) {
            self::assertSame('not-found', $refused->error()->category());
            self::assertSame('studio.model/not-found', $refused->error()->diagnostics()[0]->code());
        }
    }

    /**
     * Build the real projection service around the canonical deny-by-default authorization gateway.
     *
     * Only the definition service's gateway dependency is initialized: `contentTypes()` authorizes
     * before touching any store, so a context without `content.read` is refused by live policy
     * exactly as production would refuse it, and no repository state is ever needed.
     *
     * @return  StudioContentProjectionService  Projection service whose listing is denied by policy.
     *
     * @since  2.0.0
     */
    private static function projectionWithoutContentRead(): StudioContentProjectionService
    {
        $definitions = (new ReflectionClass(ContentModelService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ContentModelService::class, 'authorization'))
            ->setValue($definitions, AuthorizationContext::gateway());
        $projection = (new ReflectionClass(StudioContentProjectionService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(StudioContentProjectionService::class, 'models'))
            ->setValue($projection, $definitions);

        return $projection;
    }
}
