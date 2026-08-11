import './styles.css';
import '../interface-standard/kis-tabs';
import '../interface-standard/kis-master-detail';
import '../interface-standard/kis-drawer';
import { setupCopyValues } from '../interface-standard/copy-value';
import { setupValidationReveal } from '../interface-standard/reveal-validation';

const toggle = document.querySelector<HTMLButtonElement>('[data-site-navigation-toggle]');
const navigation = document.querySelector<HTMLElement>('[data-site-navigation]');
if (toggle && navigation) {
  toggle.addEventListener('click', () => {
    const open = navigation.toggleAttribute('data-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
}

setupCopyValues();
setupValidationReveal();
