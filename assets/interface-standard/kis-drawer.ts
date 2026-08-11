import { LitElement, html } from 'lit';
import { customElement } from 'lit/decorators.js';

const focusable = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

@customElement('kumwe-drawer')
export class KumweDrawer extends LitElement {
  private invokingControl: HTMLElement | null = null;

  protected override createRenderRoot(): HTMLElement | DocumentFragment { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.dataset.enhanced = '';
    const panel = this.panel();
    if (panel) {
      panel.hidden = true;
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
    }
    this.addEventListener('click', this.handleClick);
    this.addEventListener('keydown', this.handleKeydown);
    this.addEventListener('input', this.markDirty);
    this.addEventListener('change', this.markDirty);
    this.addEventListener('kis:reveal-target', this.handleRevealTarget);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('keydown', this.handleKeydown);
    this.removeEventListener('input', this.markDirty);
    this.removeEventListener('change', this.markDirty);
    this.removeEventListener('kis:reveal-target', this.handleRevealTarget);
    super.disconnectedCallback();
  }

  private panel(): HTMLElement | null {
    return this.querySelector<HTMLElement>('[data-kis-drawer-panel]');
  }

  private open(control: HTMLElement, focusFirst = true): void {
    const panel = this.panel();
    if (!panel) return;
    this.invokingControl = control;
    panel.hidden = false;
    this.setAttribute('data-open', '');
    control.setAttribute('aria-expanded', 'true');
    document.body.classList.add('kis-drawer-locked');
    if (focusFirst) panel.querySelector<HTMLElement>(focusable)?.focus();
  }

  private close(force = false): void {
    const panel = this.panel();
    if (!panel) return;
    if (!force && panel.hasAttribute('data-dirty') && !window.confirm('Discard the unsaved drawer changes?')) {
      return;
    }
    panel.hidden = true;
    panel.removeAttribute('data-dirty');
    this.removeAttribute('data-open');
    this.querySelector<HTMLElement>('[data-kis-drawer-open]')?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('kis-drawer-locked');
    this.invokingControl?.focus();
    this.invokingControl = null;
  }

  private readonly markDirty = (event: Event): void => {
    if (event.target instanceof Element && event.target.closest('[data-kis-drawer-panel]')) {
      this.panel()?.setAttribute('data-dirty', '');
    }
  };

  private readonly handleRevealTarget = (event: Event): void => {
    if (!(event.target instanceof Element) || !this.contains(event.target)) return;
    const control = this.querySelector<HTMLElement>('[data-kis-drawer-open]');
    if (control && !this.hasAttribute('data-open')) this.open(control, false);
  };

  private readonly handleClick = (event: MouseEvent): void => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const open = target.closest<HTMLElement>('[data-kis-drawer-open]');
    if (open) this.open(open);
    if (target.closest('[data-kis-drawer-close]')) this.close();
  };

  private readonly handleKeydown = (event: KeyboardEvent): void => {
    const panel = this.panel();
    if (!panel || panel.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = Array.from(panel.querySelectorAll<HTMLElement>(focusable));
    if (controls.length === 0) {
      event.preventDefault();
      panel.focus();
      return;
    }
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  };

  protected override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-drawer': KumweDrawer;
  }
}
