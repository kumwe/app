<?php

declare(strict_types=1);

namespace Kumwe\CMS\Localization\Application;

/**
 * Which site, and optionally which organization within it, the administered override layers apply to.
 *
 * The two upper steps of the override chain are scoped rather than global, so a resolver needs to
 * know whose wording it is resolving before it can read them. Holding the pair as one value keeps
 * an organization override from being read without the site it belongs to, which is what stops one
 * site's terminology from reaching another's.
 *
 * @since  2.0.0
 */
final readonly class TranslationScope
{
    /**
     * Bind a scope to a site and, when there is one, an organization inside it.
     *
     * @param  string   $site          Site identifier whose overrides apply.
     * @param  ?string  $organization  Organization identifier whose overrides apply, or null when the
     *         unit of work is not inside one.
     *
     * @since  2.0.0
     */
    public function __construct(public string $site, public ?string $organization = null)
    {
    }

    /**
     * The scope of an installation that has no site context yet, such as a boot-time console command.
     *
     * @return  self  Scope naming the default site and no organization.
     *
     * @since   2.0.0
     */
    public static function default(): self
    {
        return new self('default');
    }

    /**
     * A stable key two scopes compare on, used to memoise a resolved chain per unit of work.
     *
     * @return  string  Site identifier, then the organization identifier when there is one.
     *
     * @since   2.0.0
     */
    public function key(): string
    {
        return $this->organization === null ? $this->site : $this->site . '/' . $this->organization;
    }
}
