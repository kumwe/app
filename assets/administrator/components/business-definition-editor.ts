import { LitElement, html } from 'lit';

export class KumweDefinitionEditor extends LitElement {
  protected override createRenderRoot(): HTMLElement | DocumentFragment { return this; }

  protected override render() { return html`<slot></slot>`; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('click', this.handleClick);
    this.addEventListener('toggle', this.handleToggle, true);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('toggle', this.handleToggle, true);
    super.disconnectedCallback();
  }

  private handleToggle = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLDetailsElement) || !target.open || target.dataset.row !== 'field') return;
    this.closeFieldRows(target);
  };

  private handleClick = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const remove = target.closest<HTMLElement>('[data-remove]');
    if (remove) {
      remove.closest<HTMLElement>('[data-row]')?.remove();
      return;
    }
    const add = target.closest<HTMLElement>('[data-add]');
    const kind = add?.dataset.add;
    if (!kind) return;
    const template = this.querySelector<HTMLTemplateElement>(`template[data-template="${kind}"]`);
    const rows = this.querySelector<HTMLElement>(`[data-rows="${kind}"]`);
    if (!template || !rows || rows.children.length >= this.limit(kind)) return;
    if (kind === 'field') this.closeFieldRows();
    const index = this.nextIndex(kind);
    const wrapper = document.createElement('template');
    wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index));
    rows.append(wrapper.content.cloneNode(true));
    rows.lastElementChild?.querySelector<HTMLElement>('input, select, textarea')?.focus();
  };

  private closeFieldRows(except?: HTMLDetailsElement): void {
    for (const row of this.querySelectorAll<HTMLDetailsElement>('details[data-row="field"][open]')) {
      if (row !== except) row.open = false;
    }
  }

  private nextIndex(kind: string): number {
    const names = Array.from(this.querySelectorAll<HTMLInputElement | HTMLSelectElement>(`[name^="${kind}_"]`))
      .map((input) => Number(input.name.match(new RegExp(`^${kind}_(\\d+)_`))?.[1] ?? -1));
    return Math.max(-1, ...names) + 1;
  }

  private limit(kind: string): number {
    return ({ field: 256, relationship: 128, view: 64, action: 64, transition: 128 } as Record<string, number>)[kind] ?? 0;
  }
}

customElements.define('kumwe-definition-editor', KumweDefinitionEditor);
