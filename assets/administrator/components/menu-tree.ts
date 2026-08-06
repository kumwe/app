import { LitElement, css, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-menu-tree')
export class KumweMenuTree extends LitElement {
  static override styles = css`:host { display: contents; }`;
  override createRenderRoot(): HTMLElement { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.querySelectorAll<HTMLElement>('[data-menu-item]').forEach((item) => {
      item.setAttribute('draggable', 'true');
      item.addEventListener('dragstart', this.dragStart);
      item.addEventListener('dragover', this.dragOver);
      item.addEventListener('drop', this.drop);
      item.addEventListener('dragend', this.dragEnd);
    });
  }

  private readonly dragStart = (event: DragEvent): void => {
    const item = event.currentTarget;
    if (!(item instanceof HTMLElement) || !event.dataTransfer) return;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', item.dataset.menuItem ?? '');
    item.dataset.dragging = '';
  };

  private readonly dragOver = (event: DragEvent): void => {
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
  };

  private readonly drop = (event: DragEvent): void => {
    event.preventDefault();
    const target = event.currentTarget;
    if (!(target instanceof HTMLElement) || !event.dataTransfer) return;
    const sourceId = event.dataTransfer.getData('text/plain');
    const source = this.querySelector<HTMLElement>(`[data-menu-item="${CSS.escape(sourceId)}"]`);
    if (!source || source === target) return;
    target.before(source);
    this.renumber();
  };

  private readonly dragEnd = (event: DragEvent): void => {
    const item = event.currentTarget;
    if (item instanceof HTMLElement) delete item.dataset.dragging;
  };

  private renumber(): void {
    const order: string[] = [];
    this.querySelectorAll<HTMLElement>('[data-menu-item]').forEach((item, index) => {
      const identifier = item.dataset.menuItem;
      if (identifier) order.push(identifier);
      const position = item.querySelector<HTMLInputElement>('[data-position-input]');
      if (position) position.value = String(index);
    });
    const orderInput = this.querySelector<HTMLInputElement>('[data-order-input]');
    if (orderInput) orderInput.value = order.join(',');
  }

  override render() { return html`<slot></slot>`; }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-menu-tree': KumweMenuTree; } }
