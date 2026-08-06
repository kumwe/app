import './styles.css';

const toggle = document.querySelector<HTMLButtonElement>('[data-site-navigation-toggle]');
const navigation = document.querySelector<HTMLElement>('[data-site-navigation]');
if (toggle && navigation) {
  toggle.addEventListener('click', () => {
    const open = navigation.toggleAttribute('data-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
}
