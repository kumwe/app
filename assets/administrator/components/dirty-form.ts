import { LitElement, html } from 'lit';
import { customElement } from 'lit/decorators.js';

/**
 * Announces unsaved form state and warns once before leaving an edited server-rendered form.
 *
 * The server remains authoritative. This controller only observes a descendant form, updates a live
 * status message, and removes its warning before an ordinary submission proceeds.
 */
@customElement('kumwe-dirty-form')
export class KumweDirtyForm extends LitElement {
  private form: HTMLFormElement | null = null;

  private status: HTMLElement | null = null;

  private readonly markDirty = (): void => {
    if (this.hasAttribute('data-dirty')) {
      return;
    }

    this.setAttribute('data-dirty', '');
    if (this.status !== null) {
      this.status.textContent = 'Unsaved changes';
    }
  };

  private readonly clearDirty = (): void => {
    this.removeAttribute('data-dirty');
    if (this.status !== null) {
      this.status.textContent = 'Changes are saved when you submit this form.';
    }
  };

  private readonly warnBeforeLeave = (event: BeforeUnloadEvent): void => {
    if (!this.hasAttribute('data-dirty')) {
      return;
    }

    event.preventDefault();
    event.returnValue = '';
  };

  protected override createRenderRoot(): HTMLElement | DocumentFragment {
    return this;
  }

  override connectedCallback(): void {
    super.connectedCallback();
    this.form = this.querySelector('form');
    this.status = this.querySelector<HTMLElement>('[data-kis-dirty-status]');
    this.form?.addEventListener('input', this.markDirty);
    this.form?.addEventListener('change', this.markDirty);
    this.form?.addEventListener('submit', this.clearDirty);
    window.addEventListener('beforeunload', this.warnBeforeLeave);
  }

  override disconnectedCallback(): void {
    this.form?.removeEventListener('input', this.markDirty);
    this.form?.removeEventListener('change', this.markDirty);
    this.form?.removeEventListener('submit', this.clearDirty);
    window.removeEventListener('beforeunload', this.warnBeforeLeave);
    this.form = null;
    this.status = null;
    super.disconnectedCallback();
  }

  protected override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-dirty-form': KumweDirtyForm;
  }
}
