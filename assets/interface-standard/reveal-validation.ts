export function setupValidationReveal(root: Document = document): void {
  root.addEventListener('click', (event) => {
    const source = event.target;
    if (!(source instanceof Element)) return;
    const link = source.closest<HTMLAnchorElement>('.kis-validation-summary a[href^="#"]');
    if (!link) return;
    const identifier = decodeURIComponent(link.hash.slice(1));
    const target = root.getElementById(identifier);
    if (!(target instanceof HTMLElement)) return;

    event.preventDefault();
    target.dispatchEvent(new CustomEvent('kis:reveal-target', { bubbles: true, composed: true }));
    window.requestAnimationFrame(() => {
      target.focus({ preventScroll: true });
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      window.history.replaceState({}, '', `#${encodeURIComponent(identifier)}`);
    });
  });
}
