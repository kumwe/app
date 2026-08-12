/**
 * Progressively enhance the server-rendered resource-policy authoring stages.
 *
 * The complete native form remains in the document for no-JavaScript use. Enhancement hides only the
 * inactive stage, changes canonical step URLs through history state so entered values remain intact,
 * restores every stage before native constraint validation, and focuses the first invalid stage.
 */
class PolicyStepFlowController {
  private pendingInvalid: { step: string; target: HTMLElement } | null = null;

  private submissionRestoreTimer: number | null = null;

  private revealedStep: string | null = null;

  public constructor(private readonly root: HTMLElement) {}

  public connect(): void {
    if (this.root.hasAttribute('data-enhanced')) return;
    this.root.addEventListener('click', this.handleClick);
    this.root.addEventListener('invalid', this.handleInvalid, true);
    this.root.addEventListener('kis:reveal-target', this.handleRevealTarget);
    window.addEventListener('popstate', this.handleLocationChange);
    window.addEventListener('hashchange', this.handleLocationChange);
    this.root.dataset.enhanced = '';

    const locationStep = this.locationStep();
    this.activate(locationStep ?? this.selectedStep(), false, locationStep !== null);
  }

  private navigationLinks(): HTMLAnchorElement[] {
    return Array.from(
      this.root.querySelectorAll<HTMLAnchorElement>('[data-kis-policy-step-link]'),
    );
  }

  private panels(): HTMLElement[] {
    return Array.from(
      this.root.querySelectorAll<HTMLElement>('[data-kis-policy-step-panel]'),
    );
  }

  private selectedStep(): string {
    return this.root.dataset.kisActiveStep
      ?? this.root.querySelector<HTMLAnchorElement>('[data-kis-policy-step-link][aria-current="step"]')
        ?.dataset.kisPolicyStepLink
      ?? this.panels()[0]?.dataset.kisPolicyStepPanel
      ?? '';
  }

  private locationStep(): string | null {
    const parameter = this.root.dataset.kisStepParameter ?? 'step';
    const requested = new URL(window.location.href).searchParams.get(parameter);
    if (requested !== null && this.panel(requested) !== null) return requested;

    const hashTarget = window.location.hash === ''
      ? null
      : document.getElementById(window.location.hash.slice(1));
    return hashTarget instanceof HTMLElement && this.root.contains(hashTarget)
      ? hashTarget.closest<HTMLElement>('[data-kis-policy-step-panel]')?.dataset.kisPolicyStepPanel ?? null
      : null;
  }

  private panel(step: string): HTMLElement | null {
    return this.panels().find((panel) => panel.dataset.kisPolicyStepPanel === step) ?? null;
  }

  private activate(step: string, updateLocation: boolean, focus: boolean): void {
    const panel = this.panel(step) ?? this.panels()[0];
    const selected = panel?.dataset.kisPolicyStepPanel;
    if (!panel || !selected) return;

    this.root.dataset.kisActiveStep = selected;
    for (const link of this.navigationLinks()) {
      const active = link.dataset.kisPolicyStepLink === selected;
      link.classList.toggle('primary', active);
      if (active) link.setAttribute('aria-current', 'step');
      else link.removeAttribute('aria-current');
    }
    for (const candidate of this.panels()) {
      const active = candidate === panel;
      candidate.hidden = !active;
      candidate.toggleAttribute('data-kis-step-current', active);
      candidate.querySelector<HTMLElement>('[data-kis-current-step-label]')?.toggleAttribute(
        'hidden',
        !active,
      );
    }

    if (updateLocation) {
      const destinationLink = this.navigationLinks().find(
        (link) => link.dataset.kisPolicyStepLink === selected,
      );
      if (destinationLink) {
        const destination = new URL(destinationLink.href, window.location.href);
        window.history.pushState({}, '', `${destination.pathname}${destination.search}${destination.hash}`);
      }
    }

    const label = this.navigationLinks().find(
      (link) => link.dataset.kisPolicyStepLink === selected,
    )?.textContent?.trim();
    const announcement = this.root.querySelector<HTMLElement>('[data-kis-policy-step-announcement]');
    if (announcement && label) announcement.textContent = `${label} is active.`;
    if (focus) panel.focus();
  }

  private revealAllPanels(): void {
    for (const panel of this.panels()) panel.hidden = false;
  }

  private prepareForSubmission(): void {
    if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
    this.revealedStep = this.selectedStep();
    this.revealAllPanels();
    this.submissionRestoreTimer = window.setTimeout(() => {
      const step = this.revealedStep;
      this.submissionRestoreTimer = null;
      this.revealedStep = null;
      if (step === null || !this.root.isConnected || this.pendingInvalid !== null) return;
      this.activate(step, false, false);
    }, 0);
  }

  private readonly handleClick = (event: MouseEvent): void => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (!event.defaultPrevented && target.closest('button[type="submit"], input[type="submit"]')) {
      this.prepareForSubmission();
      return;
    }

    const link = target.closest<HTMLAnchorElement>(
      '[data-kis-policy-step-link], [data-kis-policy-step-target]',
    );
    if (
      !link
      || event.button !== 0
      || event.metaKey
      || event.ctrlKey
      || event.shiftKey
      || event.altKey
    ) return;
    const destination = new URL(link.href, window.location.href);
    if (
      destination.origin !== window.location.origin
      || destination.pathname !== window.location.pathname
    ) return;

    const step = link.dataset.kisPolicyStepLink ?? link.dataset.kisPolicyStepTarget;
    if (!step || this.panel(step) === null) return;
    event.preventDefault();
    this.activate(step, true, true);
  };

  private readonly handleInvalid = (event: Event): void => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const panel = target.closest<HTMLElement>('[data-kis-policy-step-panel]');
    const step = panel?.dataset.kisPolicyStepPanel;
    if (!step) return;

    if (this.submissionRestoreTimer !== null) window.clearTimeout(this.submissionRestoreTimer);
    this.submissionRestoreTimer = null;
    this.revealedStep = null;
    this.revealAllPanels();
    if (this.pendingInvalid !== null) return;
    this.pendingInvalid = { step, target };
    window.setTimeout(() => {
      const pending = this.pendingInvalid;
      this.pendingInvalid = null;
      if (pending === null || !this.root.isConnected) return;
      this.activate(pending.step, true, false);
      pending.target.focus();
    }, 0);
  };

  private readonly handleRevealTarget = (event: Event): void => {
    if (!(event.target instanceof Element) || !this.root.contains(event.target)) return;
    const panel = event.target.closest<HTMLElement>('[data-kis-policy-step-panel]');
    if (panel?.dataset.kisPolicyStepPanel) {
      this.activate(panel.dataset.kisPolicyStepPanel, true, false);
    }
  };

  private readonly handleLocationChange = (): void => {
    const step = this.locationStep();
    if (step !== null) this.activate(step, false, true);
  };
}

/** Enhance every bounded policy flow present in the current administrator response. */
export function setupPolicyStepFlows(): void {
  document.querySelectorAll<HTMLElement>('[data-kis-policy-step-flow]').forEach((root) => {
    new PolicyStepFlowController(root).connect();
  });
}
