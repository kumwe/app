import { LitElement, css, html, nothing } from 'lit';
import { customElement, property, state } from 'lit/decorators.js';

interface CommandItem {
  label: string;
  href: string;
  description: string;
  keywords: string;
}

@customElement('kumwe-command-palette')
export class KumweCommandPalette extends LitElement {
  @property({ type: String }) accessor source = 'administrator-command-data';
  @state() private accessor open = false;
  @state() private accessor query = '';
  private items: CommandItem[] = [];

  static override styles = css`
    :host { display: contents; }
    dialog { width: min(680px, calc(100vw - 2rem)); max-height: min(680px, calc(100vh - 2rem)); padding: 0; border: 1px solid var(--kumwe-border-strong); border-radius: var(--kumwe-radius-xl); background: var(--kumwe-surface-elevated); color: var(--kumwe-text); box-shadow: var(--kumwe-shadow-xl); }
    dialog::backdrop { background: rgb(10 20 36 / .58); backdrop-filter: blur(5px); }
    form { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: 1rem; background: var(--kumwe-surface-elevated); border-bottom: 1px solid var(--kumwe-border); }
    input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: inherit; font: 600 1.05rem/1.4 var(--kumwe-font-sans); }
    button { border: 0; border-radius: .55rem; padding: .45rem .6rem; background: var(--kumwe-surface-subtle); color: var(--kumwe-text-muted); cursor: pointer; }
    ul { display: grid; gap: .35rem; margin: 0; padding: .65rem; list-style: none; overflow: auto; }
    a { display: grid; gap: .15rem; padding: .85rem .9rem; border-radius: .7rem; color: var(--kumwe-text); text-decoration: none; }
    a:hover, a:focus-visible { outline: 0; background: var(--kumwe-accent-soft); color: var(--kumwe-accent-strong); }
    strong { font-size: .94rem; }
    span { color: var(--kumwe-text-muted); font-size: .82rem; }
    .empty { padding: 2.2rem 1rem; color: var(--kumwe-text-muted); text-align: center; }
  `;

  override connectedCallback(): void {
    super.connectedCallback();
    const source = document.getElementById(this.source);
    if (source?.textContent) {
      try {
        const parsed: unknown = JSON.parse(source.textContent);
        if (Array.isArray(parsed)) this.items = parsed.filter(this.isCommandItem);
      } catch { this.items = []; }
    }
    window.addEventListener('keydown', this.handleShortcut);
    document.querySelectorAll<HTMLElement>('[data-open-command-palette]').forEach((button) => {
      button.addEventListener('click', this.show);
    });
  }

  override disconnectedCallback(): void {
    window.removeEventListener('keydown', this.handleShortcut);
    super.disconnectedCallback();
  }

  private readonly isCommandItem = (value: unknown): value is CommandItem => {
    if (typeof value !== 'object' || value === null) return false;
    const item = value as Partial<CommandItem>;
    return typeof item.label === 'string' && typeof item.href === 'string'
      && typeof item.description === 'string' && typeof item.keywords === 'string';
  };

  private readonly handleShortcut = (event: KeyboardEvent): void => {
    // An embedded surface that already consumed the shortcut (the Studio shell binds
    // Ctrl/Meta+K for its own command palette) keeps it; stealing focus into this
    // modal dialog would break that surface's palette.
    if (event.defaultPrevented) return;
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.show();
    }
  };

  private readonly show = (): void => {
    this.open = true;
    this.updateComplete.then(() => {
      this.renderRoot.querySelector<HTMLDialogElement>('dialog')?.showModal();
      this.renderRoot.querySelector<HTMLInputElement>('input')?.focus();
    }).catch(() => undefined);
  };

  private close(): void {
    this.renderRoot.querySelector<HTMLDialogElement>('dialog')?.close();
    this.open = false;
    this.query = '';
  }

  private get results(): CommandItem[] {
    const query = this.query.trim().toLocaleLowerCase();
    if (!query) return this.items.slice(0, 12);
    return this.items.filter((item) => `${item.label} ${item.description} ${item.keywords}`.toLocaleLowerCase().includes(query)).slice(0, 12);
  }

  override render() {
    if (!this.open) return nothing;
    return html`<dialog @close=${() => { this.open = false; }} @click=${(event: MouseEvent) => {
      if (event.target === event.currentTarget) this.close();
    }}>
      <form method="dialog" @submit=${(event: SubmitEvent) => event.preventDefault()}>
        <span aria-hidden="true">⌕</span>
        <input aria-label="Search administrator commands" placeholder="Search pages and actions…" .value=${this.query} @input=${(event: InputEvent) => {
          this.query = (event.currentTarget as HTMLInputElement).value;
        }}>
        <button type="button" @click=${() => this.close()} aria-label="Close command palette">Esc</button>
      </form>
      ${this.results.length === 0 ? html`<p class="empty">No matching administrator action.</p>` : html`<ul>
        ${this.results.map((item) => html`<li><a href=${item.href}><strong>${item.label}</strong><span>${item.description}</span></a></li>`)}
      </ul>`}
    </dialog>`;
  }
}

declare global { interface HTMLElementTagNameMap { 'kumwe-command-palette': KumweCommandPalette; } }
