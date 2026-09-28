import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * The interface locale the Studio acceptance journey runs in.
 *
 * The journey is written once and parameterized by locale: `KUMWE_STUDIO_JOURNEY_LOCALE` names a catalogue in
 * resources/localization/messages, and every label the journey looks for is read from that catalogue by its
 * message identifier, so a translated catalogue drives the same journey without a copy of it.
 */
export const journeyLocale = process.env.KUMWE_STUDIO_JOURNEY_LOCALE ?? 'en-GB';

const catalogues = new Map<string, Map<string, string>>();

function decode(value: string): string {
  return value
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&apos;', "'")
    .replaceAll('&amp;', '&');
}

function catalogue(locale: string): Map<string, string> {
  const cached = catalogues.get(locale);
  if (cached !== undefined) return cached;
  const path = resolve(import.meta.dirname, '../../../resources/localization/messages', `${locale}.xlf`);
  const xml = readFileSync(path, 'utf8');
  const messages = new Map<string, string>();
  for (const unit of xml.matchAll(/<unit id="([^"]+)">([\s\S]*?)<\/unit>/gu)) {
    const [, id, body] = unit;
    if (id === undefined || body === undefined) continue;
    const target = /<target>([\s\S]*?)<\/target>/u.exec(body)?.[1];
    const source = /<source>([\s\S]*?)<\/source>/u.exec(body)?.[1];
    const text = target ?? source;
    if (text !== undefined) messages.set(id, decode(text));
  }
  catalogues.set(locale, messages);
  return messages;
}

/**
 * Answer the journey locale's text for one message identifier, falling back to the source catalogue.
 *
 * @param   id          App message identifier, for example `core.studio.contextual.save-item`.
 * @param   parameters  Placeholder values substituted into `{name}` markers.
 *
 * @returns The localized text the interface renders.
 */
export function text(id: string, parameters: Record<string, string> = {}): string {
  const message = catalogue(journeyLocale).get(id) ?? catalogue('en-GB').get(id);
  if (message === undefined) throw new Error(`No catalogue carries the message ${id}.`);
  return Object.entries(parameters).reduce((value, [name, replacement]) => value.replaceAll(`{${name}}`, replacement), message);
}

/** The text of one Studio contextual-shell message, by its Studio wire name. */
export function studio(name: string, parameters: Record<string, string> = {}): string {
  return text(`core.studio.contextual.${name}`, parameters);
}

/** Append the journey locale to an administrator path when it is not the source locale. */
export function localized(path: string): string {
  if (journeyLocale === 'en-GB') return path;
  return `${path}${path.includes('?') ? '&' : '?'}locale=${encodeURIComponent(journeyLocale)}`;
}
