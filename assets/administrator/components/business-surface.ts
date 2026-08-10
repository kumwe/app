import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-business-surface')
export class KumweBusinessSurface extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.querySelector<HTMLInputElement>('[data-business-page-search]')
      ?.addEventListener('input', this.filterPage);
    this.querySelectorAll<HTMLFormElement>('form').forEach((form) => {
      form.addEventListener('submit', this.markBusy);
    });
    this.querySelector<HTMLElement>('.business-error-summary')?.focus();
  }

  override disconnectedCallback(): void {
    this.querySelector<HTMLInputElement>('[data-business-page-search]')
      ?.removeEventListener('input', this.filterPage);
    this.querySelectorAll<HTMLFormElement>('form').forEach((form) => {
      form.removeEventListener('submit', this.markBusy);
    });
    super.disconnectedCallback();
  }

  private readonly filterPage = (event: Event): void => {
    const input = event.currentTarget;
    if (!(input instanceof HTMLInputElement)) return;
    const term = input.value.trim().toLocaleLowerCase();
    let visible = 0;
    this.querySelectorAll<HTMLTableRowElement>('[data-business-record-row]').forEach((row) => {
      const matches = term === '' || (row.textContent ?? '').toLocaleLowerCase().includes(term);
      row.hidden = !matches;
      if (matches) visible += 1;
    });
    const empty = this.querySelector<HTMLElement>('[data-business-search-empty]');
    if (empty) empty.hidden = visible > 0;
  };

  private readonly markBusy = (event: SubmitEvent): void => {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement)) return;
    form.setAttribute('aria-busy', 'true');
    const submitter = event.submitter;
    if (submitter instanceof HTMLButtonElement) submitter.setAttribute('aria-disabled', 'true');
  };

  override render() { return html`<slot></slot>`; }
}

@customElement('kumwe-business-table')
export class KumweBusinessTable extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override render() { return html`<slot></slot>`; }
}

@customElement('kumwe-business-ordered-lines')
export class KumweBusinessOrderedLines extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.dataset.enhanced = '';
    this.addEventListener('click', this.move);
    this.addEventListener('change', this.selectionChanged);
    this.updateButtons();
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.move);
    this.removeEventListener('change', this.selectionChanged);
    super.disconnectedCallback();
  }

  private readonly selectionChanged = (event: Event): void => {
    if (event.target instanceof HTMLSelectElement && event.target.matches('[data-business-order-select]')) {
      this.sync();
    }
  };

  private readonly move = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const button = target.closest<HTMLButtonElement>('[data-move]');
    const item = button?.closest<HTMLLIElement>('[data-record-id]');
    if (!button || !item) return;
    if (button.dataset.move === 'up' && item.previousElementSibling) {
      item.previousElementSibling.before(item);
    } else if (button.dataset.move === 'down' && item.nextElementSibling) {
      item.nextElementSibling.after(item);
    } else {
      return;
    }
    this.sync();
    button.focus();
  };

  private sync(): void {
    const selections = Array.from(
      this.querySelectorAll<HTMLSelectElement>('[data-business-order-select]'),
    );
    this.updateButtons();
    const announcement = this.querySelector<HTMLElement>('[data-business-order-status]');
    if (announcement) {
      const labels = selections.map((selection) => selection.selectedOptions[0]?.textContent?.trim() ?? '');
      announcement.textContent = `Order updated: ${labels.filter((label) => label !== '').join(', ')}.`;
    }
  }

  private updateButtons(): void {
    const items = Array.from(this.querySelectorAll<HTMLLIElement>('[data-record-id]'));
    items.forEach((item, index) => {
      const position = item.querySelector<HTMLElement>('[data-business-order-position]');
      const up = item.querySelector<HTMLButtonElement>('[data-move="up"]');
      const down = item.querySelector<HTMLButtonElement>('[data-move="down"]');
      if (position) position.textContent = `Position ${index + 1}`;
      if (up) up.disabled = index === 0;
      if (down) down.disabled = index === items.length - 1;
    });
    if (!this.querySelector('[data-business-order-status]')) {
      const status = document.createElement('p');
      status.className = 'sr-only';
      status.dataset.businessOrderStatus = '';
      status.setAttribute('aria-live', 'polite');
      this.append(status);
    }
  }

  override render() { return html`<slot></slot>`; }
}

@customElement('kumwe-business-confirmation')
export class KumweBusinessConfirmation extends LitElement {
  static override styles = css`:host { display: contents; }`;

  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    const checkbox = this.querySelector<HTMLInputElement>('[data-business-confirm-check]');
    checkbox?.addEventListener('change', this.updateConfirmation);
    this.updateConfirmation();
  }

  override disconnectedCallback(): void {
    this.querySelector<HTMLInputElement>('[data-business-confirm-check]')
      ?.removeEventListener('change', this.updateConfirmation);
    super.disconnectedCallback();
  }

  private readonly updateConfirmation = (): void => {
    const checkbox = this.querySelector<HTMLInputElement>('[data-business-confirm-check]');
    const submit = this.querySelector<HTMLButtonElement>('[data-business-confirm-form] button[type="submit"]');
    if (submit) submit.disabled = !checkbox?.checked;
  };

  override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-business-surface': KumweBusinessSurface;
    'kumwe-business-table': KumweBusinessTable;
    'kumwe-business-ordered-lines': KumweBusinessOrderedLines;
    'kumwe-business-confirmation': KumweBusinessConfirmation;
  }
}
