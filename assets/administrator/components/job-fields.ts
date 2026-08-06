import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-job-fields')
export class KumweJobFields extends LitElement {
  static override styles = css`:host { display: contents; }`;
  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('change', this.handleChange);
    this.updateFields();
  }

  override disconnectedCallback(): void {
    this.removeEventListener('change', this.handleChange);
    super.disconnectedCallback();
  }

  private readonly handleChange = (event: Event): void => {
    if (event.target instanceof HTMLSelectElement && event.target.matches('[data-job-type]')) this.updateFields();
  };

  private updateFields(): void {
    const type = this.querySelector<HTMLSelectElement>('[data-job-type]')?.value ?? '';
    this.querySelectorAll<HTMLElement>('[data-job-fields]').forEach((group) => {
      const active = group.dataset.jobFields === type;
      group.hidden = !active;
      group.querySelectorAll<HTMLInputElement | HTMLSelectElement>('input, select').forEach((input) => {
        input.disabled = !active;
      });
    });
  }

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-job-fields': KumweJobFields; } }
