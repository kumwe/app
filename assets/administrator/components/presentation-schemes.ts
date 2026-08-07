import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-presentation-schemes')
export class KumwePresentationSchemes extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('click', this.handleClick);
    this.addEventListener('input', this.handleInput);
    this.synchronize();
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('input', this.handleInput);
    super.disconnectedCallback();
  }

  private readonly handleClick = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.closest('[data-add-presentation-scheme]')) {
      event.preventDefault();
      this.addScheme();
      return;
    }
    const remove = target.closest<HTMLElement>('[data-remove-presentation-scheme]');
    if (!remove) return;
    event.preventDefault();
    const rows = this.rows();
    if (rows.length <= 1) return;
    remove.closest<HTMLElement>('[data-presentation-scheme-row]')?.remove();
    this.synchronize();
  };

  private readonly handleInput = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    const row = target.closest<HTMLElement>('[data-presentation-scheme-row]');
    if (!row) return;
    if (target.matches('[data-presentation-scheme-name]')) {
      const heading = row.querySelector<HTMLElement>('[data-presentation-scheme-heading]');
      if (heading) heading.textContent = target.value.trim() || 'Unnamed scheme';
    }
    if (target.matches('[data-presentation-scheme-name], [data-presentation-scheme-handle]')) {
      this.synchronize();
    }
  };

  private addScheme(): void {
    const rows = this.rows();
    if (rows.length >= 12) return;
    const template = this.querySelector<HTMLTemplateElement>('template[data-presentation-scheme-template]');
    const container = this.querySelector<HTMLElement>('[data-presentation-scheme-rows]');
    if (!template || !container) return;
    const index = this.nextIndex();
    const fragment = template.content.cloneNode(true) as DocumentFragment;
    fragment.querySelectorAll<HTMLElement>('[name], [for], [id], [value]').forEach((element) => {
      for (const attribute of ['name', 'for', 'id', 'value']) {
        const value = element.getAttribute(attribute);
        if (value?.includes('__INDEX__')) {
          element.setAttribute(attribute, value.replaceAll('__INDEX__', String(index)));
        }
      }
    });
    container.append(fragment);
    this.synchronize();
    this.rows().at(-1)?.querySelector<HTMLInputElement>('[data-presentation-scheme-name]')?.focus();
  }

  private synchronize(): void {
    const rows = this.rows();
    rows.forEach((row) => {
      const remove = row.querySelector<HTMLButtonElement>('[data-remove-presentation-scheme]');
      if (remove) remove.disabled = rows.length <= 1;
    });
    const add = this.querySelector<HTMLButtonElement>('[data-add-presentation-scheme]');
    if (add) add.disabled = rows.length >= 12;

    const active = document.querySelector<HTMLSelectElement>('[data-active-presentation-scheme]');
    if (!active) return;
    const selected = active.value;
    const schemes = rows.map((row) => ({
      handle: row.querySelector<HTMLInputElement>('[data-presentation-scheme-handle]')?.value.trim() ?? '',
      name: row.querySelector<HTMLInputElement>('[data-presentation-scheme-name]')?.value.trim() ?? '',
    })).filter((scheme) => scheme.handle !== '');
    active.replaceChildren(...schemes.map((scheme) => {
      const option = document.createElement('option');
      option.value = scheme.handle;
      option.textContent = scheme.name || scheme.handle;
      return option;
    }));
    active.value = schemes.some((scheme) => scheme.handle === selected)
      ? selected
      : (schemes[0]?.handle ?? '');
  }

  private rows(): HTMLElement[] {
    return [...this.querySelectorAll<HTMLElement>('[data-presentation-scheme-row]')];
  }

  private nextIndex(): number {
    const indices = [...this.querySelectorAll<HTMLInputElement>('input[name^="scheme_"][name$="_handle"]')]
      .map((input) => /^scheme_(\d+)_handle$/.exec(input.name)?.[1])
      .filter((value): value is string => value !== undefined)
      .map(Number);
    return indices.length === 0 ? 0 : Math.max(...indices) + 1;
  }

  override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-presentation-schemes': KumwePresentationSchemes;
  }
}
