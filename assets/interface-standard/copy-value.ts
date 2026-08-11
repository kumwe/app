export function setupCopyValues(root: ParentNode = document): void {
  root.querySelectorAll<HTMLButtonElement>('[data-copy-value]').forEach((button) => {
    button.addEventListener('click', async () => {
      await navigator.clipboard.writeText(button.dataset.copyValue ?? '');
      const label = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = label; }, 1600);
    });
  });
}
