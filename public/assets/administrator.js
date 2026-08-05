(() => {
  'use strict';

  const navigation = document.querySelector('[data-admin-navigation]');
  const toggle = document.querySelector('[data-navigation-toggle]');
  if (navigation instanceof HTMLElement && toggle instanceof HTMLButtonElement) {
    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      navigation.toggleAttribute('data-open', !expanded);
    });
  }

  for (const form of document.querySelectorAll('form[data-confirm]')) {
    form.addEventListener('submit', (event) => {
      const message = form.getAttribute('data-confirm');
      if (message && !window.confirm(message)) event.preventDefault();
    });
  }

  for (const textarea of document.querySelectorAll('textarea[name="schema"], textarea[name="states"], textarea[name="transitions"], textarea[name="data"]')) {
    textarea.addEventListener('keydown', (event) => {
      if (event.key !== 'Tab') return;
      event.preventDefault();
      const start = textarea.selectionStart;
      const end = textarea.selectionEnd;
      textarea.setRangeText('  ', start, end, 'end');
    });
  }
})();
