import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-schema-form')
export class KumweSchemaForm extends LitElement {
  static override styles = css`:host { display: contents; }`;
  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.querySelectorAll<HTMLTextAreaElement>('textarea[maxlength]').forEach((textarea) => {
      const output = document.createElement('output');
      output.className = 'character-count';
      output.setAttribute('aria-live', 'polite');
      const update = (): void => { output.value = `${textarea.value.length} / ${textarea.maxLength}`; };
      textarea.insertAdjacentElement('afterend', output);
      textarea.addEventListener('input', update);
      update();
    });
  }

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-schema-form': KumweSchemaForm; } }
