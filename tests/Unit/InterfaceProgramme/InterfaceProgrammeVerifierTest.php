<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\InterfaceProgramme;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InterfaceProgrammeVerifierTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProgrammeRecordsReportsAndFindingsMatchGraphicalSources(): void
    {
        $this->expectOutputRegex(
            '/KIS programme verified: \d+ surfaces, \d+ templates, \d+ navigation entries, '
            . '\d+ generated instances, \d+ actors, \d+ tasks, \d+ journeys, \d+ work items, '
            . '\d+ findings, \d+ verification reports\./',
        );

        require dirname(__DIR__, 3) . '/tools/verify-interface-programme.php';
    }
}
