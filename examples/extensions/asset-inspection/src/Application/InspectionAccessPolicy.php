<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\Identity\Domain\Capability;

/**
 * Applies the example page's row scope and sensitive-field disclosure before rendering.
 *
 * The generated business-record runtime independently enforces the signed definition's `restricted`
 * field classification. This small policy exists to prove that contributed application data follows the
 * same fail-closed pattern: foreign-site and below-threshold rows disappear, while the restricted note
 * remains absent unless a future operator-owned profile explicitly permits detail disclosure.
 *
 * @since  2.0.0
 */
final readonly class InspectionAccessPolicy
{
    /**
     * Capability permitted to see site-scoped, non-sensitive summaries.
     *
     * @var    string
     * @since  2.0.0
     */
    public const VIEW = 'kumwe.asset-inspection-example.view';

    /**
     * Capability permitted to manage workflow and see the illustrative restricted field.
     *
     * @var    string
     * @since  2.0.0
     */
    public const MANAGE = 'kumwe.asset-inspection-example.manage';

    /**
     * Bind page disclosure to the same signed policy profile deployment acceptance applies to records.
     *
     * @param  InspectionPolicyProfile  $profile  Typed row predicate and per-use field allowlists.
     *
     * @since  2.0.0
     */
    public function __construct(private InspectionPolicyProfile $profile)
    {
    }

    /**
     * Require the manager capability used by the administrator contribution.
     *
     * @param   ExecutionContext  $context  Authenticated administrator context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When no human principal with manager authority is present.
     *
     * @since   2.0.0
     */
    public function assertManager(ExecutionContext $context): void
    {
        if (!$this->has($context, self::MANAGE)) {
            throw new InvalidArgumentException('The asset-inspection example manager capability is required.');
        }
    }

    /**
     * Require either the read-only view capability or the stronger manager capability.
     *
     * @param   ExecutionContext  $context  Authenticated administrator or portal context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the principal holds neither owned capability.
     *
     * @since   2.0.0
     */
    public function assertViewer(ExecutionContext $context): void
    {
        if (!$this->has($context, self::VIEW) && !$this->has($context, self::MANAGE)) {
            throw new InvalidArgumentException('The asset-inspection example view capability is required.');
        }
    }

    /**
     * Filter illustrative summaries through site scope, typed row policy, and explicit field disclosure.
     *
     * @param   ExecutionContext  $context  Context supplying principal authority and exact site scope.
     * @param   list<array{site_identifier: string, reference: string, risk_score: int, internal_note: string}>
     *          $rows  Typed illustrative rows used by the contributed proof pages.
     *
     * @return  list<array<string, int|string>>  Rows and fields admitted by the signed viewer profile.
     *
     * @throws  InvalidArgumentException  When the principal holds neither owned capability.
     *
     * @since   2.0.0
     */
    public function summaries(ExecutionContext $context, array $rows): array
    {
        $this->assertViewer($context);
        $site = $context->site()->identifier();
        $visible = [];
        foreach ($rows as $row) {
            if (
                $row['site_identifier'] !== $site
                || !$this->profile->records()->allows(['risk_score' => $row['risk_score']])
            ) {
                continue;
            }
            $item = [];
            if ($this->profile->fields()->allows(FieldAccessUsage::Detail, 'reference')) {
                $item['reference'] = $row['reference'];
            }
            if ($this->profile->fields()->allows(FieldAccessUsage::Detail, 'risk_score')) {
                $item['risk_score'] = $row['risk_score'];
            }
            if (
                $this->has($context, self::MANAGE)
                && $this->profile->fields()->allows(FieldAccessUsage::Detail, 'internal_note')
            ) {
                $item['internal_note'] = $row['internal_note'];
            }
            $visible[] = $item;
        }

        return $visible;
    }

    /**
     * Test an owned capability against the resolved principal without accepting a system-only context.
     *
     * @param   ExecutionContext  $context     Context whose principal is inspected.
     * @param   string            $capability  Canonical owned capability identifier.
     *
     * @return  bool  True only for an authenticated principal carrying the capability.
     *
     * @since   2.0.0
     */
    private function has(ExecutionContext $context, string $capability): bool
    {
        return $context->principal()?->hasCapability(Capability::fromString($capability)) ?? false;
    }
}
