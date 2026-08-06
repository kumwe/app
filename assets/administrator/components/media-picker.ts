import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-media-picker')
export class KumweMediaPicker extends LitElement {
  static override styles = css`:host { display: contents; }`;
  override createRenderRoot(): HTMLElement { return this; }
  private target = '';

  override connectedCallback(): void {
    super.connectedCallback();
    document.addEventListener('click', this.handleDocumentClick);
    this.addEventListener('click', this.handlePickerClick);
    this.addEventListener('input', this.handleFilter);
  }

  override disconnectedCallback(): void {
    document.removeEventListener('click', this.handleDocumentClick);
    this.removeEventListener('click', this.handlePickerClick);
    this.removeEventListener('input', this.handleFilter);
    super.disconnectedCallback();
  }

  private readonly handleDocumentClick = (event: Event): void => {
    const trigger = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-open-media-picker]') : null;
    if (!trigger) return;
    this.target = trigger.dataset.mediaTarget ?? '';
    this.querySelector<HTMLDialogElement>('dialog')?.showModal();
  };

  private readonly handlePickerClick = (event: Event): void => {
    const choice = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-select-media]') : null;
    if (!choice) return;
    const input = document.getElementById(this.target);
    if (!(input instanceof HTMLInputElement)) return;
    input.value = choice.dataset.mediaUrl ?? '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    this.querySelector<HTMLDialogElement>('dialog')?.close();
    input.focus();
  };

  private readonly handleFilter = (event: Event): void => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-media-filter]')) return;
    const query = input.value.trim().toLocaleLowerCase();
    this.querySelectorAll<HTMLElement>('[data-select-media]').forEach((item) => {
      item.hidden = query !== '' && !(item.dataset.mediaName ?? '').toLocaleLowerCase().includes(query);
    });
  };

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-media-picker': KumweMediaPicker; } }
