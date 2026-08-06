import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-workflow-builder')
export class KumweWorkflowBuilder extends LitElement {
  static override styles = css`:host { display: contents; }`;
  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('click', this.handleClick);
    this.addEventListener('input', this.handleInput);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('input', this.handleInput);
    super.disconnectedCallback();
  }

  private readonly handleClick = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const add = target.closest<HTMLElement>('[data-add-workflow-row]');
    if (add) {
      event.preventDefault();
      this.addRow(add.dataset.addWorkflowRow ?? 'state');
      return;
    }
    const remove = target.closest<HTMLElement>('[data-remove-workflow-row]');
    if (remove) {
      event.preventDefault();
      remove.closest<HTMLElement>('[data-workflow-row]')?.remove();
    }
  };

  private readonly handleInput = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.matches('[data-state-key]')) return;
    const initial = target.closest<HTMLElement>('[data-workflow-row]')
      ?.querySelector<HTMLInputElement>('input[type="radio"][name="initial_state_key"]');
    if (initial) initial.value = target.value;
  };

  private addRow(kind: string): void {
    const template = this.querySelector<HTMLTemplateElement>(`template[data-${kind}-template]`);
    const rows = this.querySelector<HTMLElement>(`[data-${kind}-rows]`);
    if (!template || !rows) return;
    const index = rows.querySelectorAll('[data-workflow-row]').length;
    const fragment = template.content.cloneNode(true) as DocumentFragment;
    fragment.querySelectorAll<HTMLElement>('[name], [for], [id]').forEach((element) => {
      for (const attribute of ['name', 'for', 'id']) {
        const value = element.getAttribute(attribute);
        if (value) element.setAttribute(attribute, value.replaceAll('__INDEX__', String(index)));
      }
    });
    rows.append(fragment);
    rows.querySelectorAll<HTMLInputElement>('[data-workflow-row]:last-child input').item(0)?.focus();
  }

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-workflow-builder': KumweWorkflowBuilder; } }
