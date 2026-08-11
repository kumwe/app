import { LitElement, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-master-detail')
export class KumweMasterDetail extends LitElement {
  protected override createRenderRoot(): HTMLElement | DocumentFragment { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.dataset.enhanced = '';
    this.addEventListener('click', this.handleClick);
    this.addEventListener('keydown', this.handleKeydown);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('keydown', this.handleKeydown);
    super.disconnectedCallback();
  }

  private setOpen(open: boolean): void {
    const toggle = this.querySelector<HTMLButtonElement>('[data-kis-catalog-toggle]');
    this.toggleAttribute('data-catalog-open', open);
    toggle?.setAttribute('aria-expanded', String(open));
    if (open) {
      this.querySelector<HTMLElement>('[data-kis-catalog] a, [data-kis-catalog] button')?.focus();
    } else {
      toggle?.focus();
    }
  }

  private readonly handleClick = (event: MouseEvent): void => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('[data-kis-catalog-toggle]')) {
      this.setOpen(!this.hasAttribute('data-catalog-open'));
    } else if (target.closest('[data-kis-catalog] a')) {
      this.setOpen(false);
    }
  };

  private readonly handleKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'Escape' || !this.hasAttribute('data-catalog-open')) return;
    event.preventDefault();
    this.setOpen(false);
  };

  protected override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-master-detail': KumweMasterDetail;
  }
}
