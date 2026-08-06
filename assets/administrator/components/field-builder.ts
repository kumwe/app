import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-field-builder')
export class KumweFieldBuilder extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('click', this.handleClick);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    super.disconnectedCallback();
  }

  private readonly handleClick = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const add = target.closest<HTMLElement>('[data-add-field]');
    if (add) {
      event.preventDefault();
      this.addRow();
      return;
    }
    const remove = target.closest<HTMLElement>('[data-remove-field]');
    if (remove) {
      event.preventDefault();
      const row = remove.closest<HTMLElement>('[data-field-row]');
      if (row && this.querySelectorAll('[data-field-row]').length > 1) row.remove();
    }
  };

  private addRow(): void {
    const template = this.querySelector<HTMLTemplateElement>('template[data-field-template]');
    const rows = this.querySelector<HTMLElement>('[data-field-rows]');
    if (!template || !rows) return;
    const index = this.querySelectorAll('[data-field-row]').length;
    const fragment = template.content.cloneNode(true) as DocumentFragment;
    fragment.querySelectorAll<HTMLElement>('[name], [for], [id]').forEach((element) => {
      for (const attribute of ['name', 'for', 'id']) {
        const value = element.getAttribute(attribute);
        if (value) element.setAttribute(attribute, value.replaceAll('__INDEX__', String(index)));
      }
    });
    rows.append(fragment);
    rows.querySelectorAll<HTMLInputElement>('[data-field-row]:last-child input').item(0)?.focus();
  }

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-field-builder': KumweFieldBuilder; } }
