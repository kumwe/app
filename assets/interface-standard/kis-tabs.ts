import { LitElement, html } from 'lit';
import { customElement } from 'lit/decorators.js';

@customElement('kumwe-tabs')
export class KumweTabs extends LitElement {
  private pendingInvalid: { panel: string; target: HTMLElement } | null = null;

  private invalidActivationTimer: number | null = null;

  private submissionRestoreTimer: number | null = null;

  private revealedTab: string | null = null;

  protected override createRenderRoot(): HTMLElement | DocumentFragment { return this; }

  override connectedCallback(): void {
    super.connectedCallback();
    this.addEventListener('click', this.handleClick);
    this.addEventListener('keydown', this.handleKeydown);
    this.addEventListener('invalid', this.handleInvalid, true);
    this.addEventListener('kis:reveal-target', this.handleRevealTarget);
    window.addEventListener('popstate', this.handleLocationChange);
    window.addEventListener('hashchange', this.handleLocationChange);
    this.dataset.enhanced = '';
    this.activate(this.locationTab() ?? this.selectedTab(), false, false);
  }

  override disconnectedCallback(): void {
    this.removeEventListener('click', this.handleClick);
    this.removeEventListener('keydown', this.handleKeydown);
    this.removeEventListener('invalid', this.handleInvalid, true);
    this.removeEventListener('kis:reveal-target', this.handleRevealTarget);
    window.removeEventListener('popstate', this.handleLocationChange);
    window.removeEventListener('hashchange', this.handleLocationChange);
    if (this.invalidActivationTimer !== null) window.clearTimeout(this.invalidActivationTimer);
    if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
    this.invalidActivationTimer = null;
    this.submissionRestoreTimer = null;
    this.pendingInvalid = null;
    this.revealedTab = null;
    super.disconnectedCallback();
  }

  private tabs(): HTMLAnchorElement[] {
    return Array.from(this.querySelectorAll<HTMLAnchorElement>('[role="tab"][data-kis-tab]'));
  }

  private panels(): HTMLElement[] {
    return Array.from(this.querySelectorAll<HTMLElement>('[role="tabpanel"][data-kis-tab-panel]'));
  }

  private selectedTab(): string {
    return this.querySelector<HTMLAnchorElement>('[role="tab"][aria-selected="true"]')?.dataset.kisTab
      ?? this.tabs()[0]?.dataset.kisTab
      ?? '';
  }

  private locationTab(): string | null {
    const hashTarget = window.location.hash === ''
      ? null
      : document.getElementById(window.location.hash.slice(1));
    if (hashTarget instanceof HTMLElement && this.contains(hashTarget)) {
      return hashTarget.closest<HTMLElement>('[data-kis-tab-panel]')?.dataset.kisTabPanel ?? null;
    }

    const parameter = this.dataset.kisTabParameter ?? 'tab';
    return new URL(window.location.href).searchParams.get(parameter);
  }

  private activate(identifier: string, updateLocation: boolean, focus: boolean): void {
    const tabs = this.tabs();
    const selected = tabs.find((tab) => tab.dataset.kisTab === identifier) ?? tabs[0];
    if (!selected) return;

    for (const tab of tabs) {
      const active = tab === selected;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
    }
    for (const panel of this.panels()) {
      panel.hidden = panel.dataset.kisTabPanel !== selected.dataset.kisTab;
    }
    for (const input of this.querySelectorAll<HTMLInputElement>('input[type="hidden"][name="return_tab"]')) {
      if (input.closest('kumwe-tabs') === this) input.value = selected.dataset.kisTab ?? '';
    }

    if (updateLocation) {
      const destination = new URL(selected.href, window.location.href);
      window.history.pushState({}, '', `${destination.pathname}${destination.search}${destination.hash}`);
    }
    if (focus) selected.focus();
  }

  private revealAllPanels(): void {
    for (const panel of this.panels()) panel.hidden = false;
  }

  private prepareForSubmission(): void {
    if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
    this.revealedTab = this.selectedTab();
    this.revealAllPanels();
    this.submissionRestoreTimer = window.setTimeout(() => {
      const identifier = this.revealedTab;
      this.submissionRestoreTimer = null;
      this.revealedTab = null;
      if (identifier === null || !this.isConnected || this.pendingInvalid !== null) return;
      this.activate(identifier, false, false);
    }, 0);
  }

  private readonly handleClick = (event: MouseEvent): void => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (!event.defaultPrevented && target.closest('button[type="submit"], input[type="submit"]')) {
      this.prepareForSubmission();
      return;
    }

    const tab = target.closest<HTMLAnchorElement>('[role="tab"][data-kis-tab]');
    if (!tab || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const destination = new URL(tab.href, window.location.href);
    if (destination.origin !== window.location.origin || destination.pathname !== window.location.pathname) return;
    event.preventDefault();
    this.activate(tab.dataset.kisTab ?? '', true, true);
  };

  private readonly handleKeydown = (event: KeyboardEvent): void => {
    const target = event.target;
    if (!(target instanceof HTMLAnchorElement) || target.getAttribute('role') !== 'tab') {
      return;
    }

    const tabs = this.tabs();
    const current = tabs.indexOf(target);
    if (current < 0) return;
    let next = current;
    if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
    else if (event.key === 'ArrowLeft') next = (current - 1 + tabs.length) % tabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabs.length - 1;
    else return;

    event.preventDefault();
    this.activate(tabs[next]?.dataset.kisTab ?? '', true, true);
  };

  private readonly handleInvalid = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const panel = target.closest<HTMLElement>('[data-kis-tab-panel]');
    const identifier = panel?.dataset.kisTabPanel;
    if (!identifier) return;

    // Native constraint validation dispatches one non-bubbling event per invalid control before the
    // browser focuses the first failure. Keep every panel visible for that complete pass; switching on
    // each event would hide the first control before the user agent can focus it.
    if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
    this.submissionRestoreTimer = null;
    this.revealedTab = null;
    this.revealAllPanels();
    if (this.pendingInvalid !== null) return;
    this.pendingInvalid = { panel: identifier, target };
    this.invalidActivationTimer = window.setTimeout(() => {
      const pending = this.pendingInvalid;
      this.pendingInvalid = null;
      this.invalidActivationTimer = null;
      if (pending === null || !this.isConnected) return;
      this.activate(pending.panel, true, false);
      pending.target.focus();
    }, 0);
  };

  private readonly handleRevealTarget = (event: Event): void => {
    if (!(event.target instanceof Element) || !this.contains(event.target)) return;
    const panel = event.target.closest<HTMLElement>('[data-kis-tab-panel]');
    if (panel?.dataset.kisTabPanel) this.activate(panel.dataset.kisTabPanel, true, false);
  };

  private readonly handleLocationChange = (): void => {
    const identifier = this.locationTab();
    if (identifier !== null) this.activate(identifier, false, false);
  };

  protected override render() { return html`<slot></slot>`; }
}

declare global {
  interface HTMLElementTagNameMap {
    'kumwe-tabs': KumweTabs;
  }
}
