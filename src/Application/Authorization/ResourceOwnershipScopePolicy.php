<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;

/**
 * The frozen table of which ownership levels each resource category may be held at.
 *
 * This is what makes "accounting is isolated by design" a property of the build rather than of an
 * operator's discipline. The core table below is PHP source, not configuration: there is no environment
 * variable, settings row or manifest key that turns a ledger into shared property, and an extension that
 * contributes an accounting category inherits the rule rather than choosing one. Categories core has not
 * reserved may be declared once by whoever contributes them, and a category nobody declares falls back to
 * `SiteOnly`, so a new resource family is isolated until someone deliberately opts it into sharing.
 *
 * The catalogue only answers questions; enforcement happens where an owner is constructed, in
 * `ResourceOwnership::of()`, so an impermissible pairing never reaches the registry to be refused there.
 *
 * @since  2.0.0
 */
final class ResourceOwnershipScopePolicy
{
    /**
     * Categories whose rule is fixed by this build and cannot be declared, widened, or overridden.
     *
     * The first seven rows are the business-group table decided in ADR 0001: a legal entity's books,
     * ledgers and pay runs are owned by one site only, while the master data four businesses share —
     * clients, people, price lists, products and services — may be widened to a declared group. The rest
     * are the categories core itself carries, listed in full so the contract is complete rather than
     * exemplary, and so an extension cannot quietly reclassify one of them.
     *
     * @var    array<string, OwnershipScopeRule>
     * @since  2.0.0
     */
    private const RESERVED = [
        'accounting_document' => OwnershipScopeRule::SiteOnly,
        'ledger' => OwnershipScopeRule::SiteOnly,
        'pay_run' => OwnershipScopeRule::SiteOnly,
        'client' => OwnershipScopeRule::SiteOrGroup,
        'person' => OwnershipScopeRule::SiteOrGroup,
        'price_list' => OwnershipScopeRule::SiteOrGroup,
        'product_service' => OwnershipScopeRule::SiteOrGroup,
        'site_group' => OwnershipScopeRule::SiteOrGroup,
        'administrator' => OwnershipScopeRule::SiteOnly,
        'administrator_session' => OwnershipScopeRule::SiteOnly,
        'api_token' => OwnershipScopeRule::SiteOnly,
        'approval_request' => OwnershipScopeRule::SiteOnly,
        'audit_trail' => OwnershipScopeRule::SiteGroupOrInstallation,
        'automation_installation' => OwnershipScopeRule::SiteGroupOrInstallation,
        'business_definition' => OwnershipScopeRule::SiteOnly,
        'business_record' => OwnershipScopeRule::SiteOnly,
        'business_report' => OwnershipScopeRule::SiteOnly,
        'business_schema' => OwnershipScopeRule::SiteOnly,
        'capability' => OwnershipScopeRule::SiteGroupOrInstallation,
        'content' => OwnershipScopeRule::SiteOnly,
        'content_type' => OwnershipScopeRule::SiteOnly,
        'database_schema' => OwnershipScopeRule::SiteOnly,
        'extension' => OwnershipScopeRule::SiteGroupOrInstallation,
        'extension_runtime_map' => OwnershipScopeRule::SiteGroupOrInstallation,
        'extension_trust_key' => OwnershipScopeRule::SiteGroupOrInstallation,
        'grant' => OwnershipScopeRule::SiteGroupOrInstallation,
        'job' => OwnershipScopeRule::SiteOnly,
        'media' => OwnershipScopeRule::SiteOnly,
        'menu' => OwnershipScopeRule::SiteOnly,
        'menu_item' => OwnershipScopeRule::SiteOnly,
        'organization' => OwnershipScopeRule::SiteOnly,
        'organization_membership' => OwnershipScopeRule::SiteOnly,
        'portal_session' => OwnershipScopeRule::SiteOnly,
        'queue' => OwnershipScopeRule::SiteOnly,
        'resource_policy' => OwnershipScopeRule::SiteOnly,
        'role' => OwnershipScopeRule::SiteGroupOrInstallation,
        'schedule' => OwnershipScopeRule::SiteOnly,
        'separation_duty_rule' => OwnershipScopeRule::SiteOnly,
        'site' => OwnershipScopeRule::SiteOnly,
        'step_up_credential' => OwnershipScopeRule::SiteOnly,
        'theme' => OwnershipScopeRule::SiteGroupOrInstallation,
        'user' => OwnershipScopeRule::SiteGroupOrInstallation,
        'workflow' => OwnershipScopeRule::SiteOnly,
        'workspace' => OwnershipScopeRule::SiteOnly,
    ];

    /**
     * Rules declared for categories core has not reserved, keyed by category.
     *
     * @var    array<string, OwnershipScopeRule>
     * @since  2.0.0
     */
    private array $declared = [];

    /**
     * Declare the ownership levels a contributed resource category may be held at.
     *
     * An extension contributing a new resource family calls this once, before the first resource of that
     * family is created. Redeclaring the same rule is accepted so that a repeated bootstrap is harmless;
     * anything else is refused, because a category whose rule can change is a category whose isolation
     * can be negotiated.
     *
     * @param   string              $category  Authorization resource type the rule applies to.
     * @param   OwnershipScopeRule  $rule      Levels resources of that category may be owned at.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the category is reserved by core, is not a valid resource
     *          type, or was already declared with a different rule.
     *
     * @since   2.0.0
     */
    public function register(string $category, OwnershipScopeRule $rule): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $category) !== 1) {
            throw new InvalidArgumentException('An ownership-scope category must be a lowercase identifier.');
        }
        if (isset(self::RESERVED[$category])) {
            throw new InvalidArgumentException(sprintf(
                'Resource category %s has an ownership-scope rule fixed by this build and cannot be redeclared.',
                $category,
            ));
        }
        $existing = $this->declared[$category] ?? null;
        if ($existing !== null && $existing !== $rule) {
            throw new InvalidArgumentException(sprintf(
                'Resource category %s is already declared as %s.',
                $category,
                $existing->value,
            ));
        }

        $this->declared[$category] = $rule;
    }

    /**
     * The rule governing one resource category.
     *
     * @param   string  $category  Authorization resource type being asked about.
     *
     * @return  OwnershipScopeRule  The reserved rule, the declared rule, or `SiteOnly` when neither
     *          exists, which keeps an unknown category isolated instead of shareable.
     *
     * @since   2.0.0
     */
    public function rule(string $category): OwnershipScopeRule
    {
        return self::RESERVED[$category] ?? $this->declared[$category] ?? OwnershipScopeRule::SiteOnly;
    }

    /**
     * Whether a category may be owned at a level.
     *
     * @param   string               $category  Authorization resource type being asked about.
     * @param   OwnershipScopeLevel  $level     Level an ownership row would be written at.
     *
     * @return  bool  True only when the category's rule admits the level.
     *
     * @since   2.0.0
     */
    public function permits(string $category, OwnershipScopeLevel $level): bool
    {
        return $this->rule($category)->permits($level);
    }

    /**
     * The complete table this build freezes, for documentation and administration screens.
     *
     * @return  array<string, OwnershipScopeRule>  Reserved and declared categories in category order.
     *
     * @since   2.0.0
     */
    public function table(): array
    {
        $table = self::RESERVED + $this->declared;
        ksort($table, SORT_STRING);

        return $table;
    }
}
