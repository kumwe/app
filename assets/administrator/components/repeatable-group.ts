const INDEX_TOKEN = 'INDEX';

const nextIndex = (rows: HTMLElement): number => {
  let highest = -1;
  for (const input of rows.querySelectorAll<HTMLInputElement>('[name^="field__"]')) {
    const segments = input.name.split('__');
    for (const segment of segments) {
      if (/^\d+$/.test(segment)) {
        highest = Math.max(highest, Number(segment));
      }
    }
  }
  return highest + 1;
};

const substituteIndex = (row: HTMLElement, index: number): void => {
  for (const element of row.querySelectorAll<HTMLElement>('[name], [id], [data-media-target], label[for]')) {
    for (const attribute of ['name', 'id', 'data-media-target', 'for']) {
      const value = element.getAttribute(attribute);
      if (value !== null && value.includes(INDEX_TOKEN)) {
        element.setAttribute(attribute, value.replaceAll(INDEX_TOKEN, String(index)));
      }
    }
  }
  const title = row.querySelector<HTMLElement>('.repeatable-row-title');
  if (title) {
    title.textContent = `${title.textContent ?? ''} ${index + 1}`.trim();
  }
};

const setup = (group: HTMLElement): void => {
  const rows = group.querySelector<HTMLElement>('[data-repeatable-rows]');
  const template = group.querySelector<HTMLTemplateElement>('[data-repeatable-template]');
  const add = group.querySelector<HTMLButtonElement>('[data-repeatable-add]');
  if (!rows || !template || !add) {
    return;
  }
  const limit = Number(group.dataset.repeatableMax ?? '');
  const enforceLimit = (): void => {
    add.disabled = Number.isFinite(limit) && limit > 0
      && rows.querySelectorAll('[data-repeatable-row]').length >= limit;
  };
  add.addEventListener('click', () => {
    const fragment = template.content.cloneNode(true) as DocumentFragment;
    const row = fragment.querySelector<HTMLElement>('[data-repeatable-row]');
    if (!row) {
      return;
    }
    substituteIndex(row, nextIndex(rows));
    rows.append(row);
    enforceLimit();
    row.querySelector<HTMLElement>('input, textarea, select')?.focus();
  });
  group.addEventListener('click', (event) => {
    const target = event.target;
    if (target instanceof HTMLElement && target.closest('[data-repeatable-remove]')) {
      target.closest('[data-repeatable-row]')?.remove();
      enforceLimit();
    }
  });
  enforceLimit();
};

for (const group of document.querySelectorAll<HTMLElement>('[data-repeatable]')) {
  setup(group);
}
