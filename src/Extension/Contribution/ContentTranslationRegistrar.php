<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

/**
 * Additive capability for packages that contribute content in more than one language.
 *
 * This interface is separate from the frozen contribution SPI-2 registrar so every existing provider
 * stays source compatible: a package that publishes content in one language never sees it, and one that
 * publishes locale variants requires this capability explicitly before its `content.translation_groups`
 * declarations are reconciled. The concrete owner-bound registrar implements it alongside the others,
 * so an extension declares its translation-group behaviour through exactly the path it declares
 * capabilities, routes and business definitions through.
 *
 * It exists because content translation must work for content contributed by extensions, not only for
 * core content — and because a package admitted against a contract with no locale dimension would have
 * to be migrated to gain one. This is the whole of the route by which a language reaches an
 * extension's content.
 *
 * @since  2.0.0
 */
interface ContentTranslationRegistrar
{
    /**
     * Publish one manifest-reconciled content set and the languages it is prepared to appear in.
     *
     * @param   TranslationGroupDeclaration  $declaration  Owner-bound declaration naming the content set,
     *          the locales it publishes and the locale it falls back to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function contentTranslationGroup(TranslationGroupDeclaration $declaration): void;
}
