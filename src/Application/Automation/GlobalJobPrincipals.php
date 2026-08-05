<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use LogicException;

/** Trusted registry of the narrowly-scoped principals allowed to execute global jobs. */
final readonly class GlobalJobPrincipals
{
    /** @var array<string, SystemPrincipal> */
    private array $principals;

    public function __construct(SystemPrincipal ...$principals)
    {
        $indexed = [];
        foreach ($principals as $principal) {
            $identity = $principal->identity();
            if (!in_array(
                $identity,
                [SystemIdentity::ExtensionMaterializer, SystemIdentity::InstallationMaintenance],
                true,
            )) {
                throw new InvalidArgumentException('A global job principal uses an unsupported system identity.');
            }
            if (isset($indexed[$identity->value])) {
                throw new InvalidArgumentException('A global job system identity was registered more than once.');
            }
            $indexed[$identity->value] = $principal;
        }
        $this->principals = $indexed;
    }

    public function context(
        string $jobType,
        JobExecutionScope $scope,
        string $requestId,
        string $correlationId,
    ): ExecutionContext {
        $identity = $scope->systemIdentity($jobType);
        $principal = $this->principals[$identity->value]
            ?? throw new LogicException(sprintf(
                'No trusted system principal is registered for installation-global job type "%s".',
                $jobType,
            ));

        return $principal->context(SiteContext::default(), $requestId, $correlationId);
    }
}
