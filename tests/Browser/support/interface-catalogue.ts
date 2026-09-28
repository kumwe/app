import { readdirSync, readFileSync } from 'node:fs';

/**
 * The nine Version 2 interface languages, in the order ADR 0002 states them.
 *
 * `en-GB` is the source; every other catalogue is authored against it. The list is repeated here rather
 * than derived from the catalogue directory so that a catalogue silently dropped from
 * `resources/localization/messages/` fails the locale matrix instead of shrinking it.
 */
export const interfaceLocales = [
  'en-GB',
  'en-US',
  'af',
  'de',
  'he',
  'ar',
  'es',
  'pt-BR',
  'zh-Hans',
] as const;

export type InterfaceLocale = (typeof interfaceLocales)[number];

/** Locales written from the right; every other Version 2 locale is left-to-right. */
export const rightToLeftInterfaceLocales: readonly InterfaceLocale[] = ['he', 'ar'];

/** Locales whose wording is English, so identical-to-source text is expected rather than a defect. */
const englishLocales: readonly InterfaceLocale[] = ['en-GB', 'en-US'];

interface CatalogueUnit {
  source: string;
  target: string;
}

const catalogues = new Map<InterfaceLocale, Map<string, CatalogueUnit>>();

/** Decode the five predefined XML entities; the catalogues use no other escaping. */
function decodeXml(value: string): string {
  return value
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&amp;', '&');
}

/**
 * Read one authored XLIFF catalogue into identifier-keyed source and target text.
 *
 * The browser suite reads the authored XLIFF rather than the compiled PHP because it runs under Node,
 * and `composer translation:check` already proves the two carry identical wording. The source catalogue
 * has no `<target>`, so its source text stands in for the target.
 */
function catalogue(locale: InterfaceLocale): Map<string, CatalogueUnit> {
  const cached = catalogues.get(locale);
  if (cached !== undefined) {
    return cached;
  }

  const document = readFileSync(
    new URL(`../../../resources/localization/messages/${locale}.xlf`, import.meta.url),
    'utf8',
  );
  const units = new Map<string, CatalogueUnit>();
  for (const match of document.matchAll(/<unit id="([^"]+)">([\s\S]*?)<\/unit>/gu)) {
    const [, identifier, body] = match;
    if (identifier === undefined || body === undefined) {
      continue;
    }
    const source = /<source>([\s\S]*?)<\/source>/u.exec(body)?.[1];
    const target = /<target>([\s\S]*?)<\/target>/u.exec(body)?.[1];
    if (source === undefined) {
      throw new Error(`${locale}.xlf unit ${identifier} carries no source.`);
    }
    units.set(identifier, {
      source: decodeXml(source),
      target: decodeXml(target ?? source),
    });
  }
  if (units.size === 0) {
    throw new Error(`${locale}.xlf carries no translation unit.`);
  }
  catalogues.set(locale, units);

  return units;
}

/**
 * Render one message the way the interface does for a pattern with at most simple arguments.
 *
 * ICU's doubled apostrophe becomes one, and each `{name}` is replaced by its value. A pattern that
 * selects (`plural`, `select`, `selectordinal`) is refused rather than approximated, because a spec
 * asserting against a guessed plural form would be asserting against this helper, not the product.
 */
export function message(
  locale: InterfaceLocale,
  identifier: string,
  parameters: Readonly<Record<string, string | number>> = {},
): string {
  const unit = catalogue(locale).get(identifier);
  if (unit === undefined) {
    throw new Error(`The ${locale} catalogue carries no message ${identifier}.`);
  }
  if (/\{\s*[\w-]+\s*,/u.test(unit.target)) {
    throw new Error(`${identifier} is a selecting ICU pattern; assert it through the rendered page instead.`);
  }

  return unit.target
    .replaceAll("''", "'")
    .replaceAll(/\{\s*([\w-]+)\s*\}/gu, (_placeholder, name: string) => {
      const value = parameters[name];
      if (value === undefined) {
        throw new Error(`${identifier} needs a value for {${name}}.`);
      }

      return String(value);
    });
}

/**
 * Answer the source-language wording that must not remain visible in a translated render.
 *
 * Every plain (argument-free) source message that carries a letter, and whose translation in `locale`
 * differs from the source, is returned. A catalogue whose translation legitimately equals the source —
 * a product name, a code, or a word the language shares with English — is left out, because seeing it is
 * correct. English locales return an empty set: identical wording is what they are meant to show.
 */
export function untranslatedSourceWording(locale: InterfaceLocale): Set<string> {
  if (englishLocales.includes(locale)) {
    return new Set();
  }

  const shared = new Set<string>();
  const differing = new Set<string>();
  for (const unit of catalogue(locale).values()) {
    const source = unit.source.replaceAll("''", "'").trim();
    if (source.includes('{') || source.includes('<') || !/\p{L}/u.test(source)) {
      continue;
    }
    if (unit.target.replaceAll("''", "'").trim() === source) {
      shared.add(source);
    } else {
      differing.add(source);
    }
  }
  // One English phrase can be the source of several identifiers. If any of them is legitimately kept
  // identical in this language, the phrase is correct to see and cannot be reported as untranslated.
  for (const source of shared) {
    differing.delete(source);
  }

  return differing;
}

/**
 * Answer the plain translated wording of `locale` that differs from the source, for evidence counts.
 *
 * A visible string found in this set is proof the page rendered a translation rather than a fallback.
 */
export function translatedWording(locale: InterfaceLocale): Set<string> {
  const wording = new Set<string>();
  for (const unit of catalogue(locale).values()) {
    const target = unit.target.replaceAll("''", "'").trim();
    if (target.includes('{') || target.includes('<') || target === unit.source.replaceAll("''", "'").trim()) {
      continue;
    }
    wording.add(target);
  }

  return wording;
}

/**
 * Answer every piece of wording the example extensions installed by the browser fixture declare.
 *
 * An extension's labels and descriptions resolve through the extension's own catalogue under the
 * contribution contract; an example that ships English only is not an untranslated core surface, even
 * where one of its labels happens to equal a core source message. Every string in each
 * `examples/extensions/<name>/kumwe.json` manifest is collected.
 */
export function extensionDeclaredWording(): Set<string> {
  const root = new URL('../../../examples/extensions/', import.meta.url);
  const wording = new Set<string>();
  const collect = (value: unknown): void => {
    if (typeof value === 'string') {
      wording.add(value.trim());
    } else if (Array.isArray(value)) {
      value.forEach(collect);
    } else if (value !== null && typeof value === 'object') {
      Object.values(value).forEach(collect);
    }
  };
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    if (!entry.isDirectory()) {
      continue;
    }
    try {
      collect(JSON.parse(readFileSync(new URL(`${entry.name}/kumwe.json`, root), 'utf8')));
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
        throw error;
      }
    }
  }

  return wording;
}

/** Whether `locale` lays out from the right. */
export function isRightToLeft(locale: InterfaceLocale): boolean {
  return rightToLeftInterfaceLocales.includes(locale);
}
