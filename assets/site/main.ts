import './styles.css';
import '../interface-standard/kis-tabs';
import '../interface-standard/kis-master-detail';
import '../interface-standard/kis-drawer';
import { setupCopyValues } from '../interface-standard/copy-value';
import { setupValidationReveal } from '../interface-standard/reveal-validation';

const toggle = document.querySelector<HTMLButtonElement>('[data-site-navigation-toggle]');
const navigation = document.querySelector<HTMLElement>('[data-site-navigation]');
if (toggle && navigation) {
  document.documentElement.dataset.siteNavigationEnhanced = '';
  toggle.addEventListener('click', () => {
    const open = navigation.toggleAttribute('data-open');
    toggle.setAttribute('aria-expanded', String(open));
  });
}

const documentNav = document.querySelector<HTMLElement>('[data-document-nav]');
const documentSections = Array.from(
  document.querySelectorAll<HTMLElement>('[data-document-section][id]'),
);
if (documentNav && documentSections.length > 0 && 'IntersectionObserver' in window) {
  const links = new Map<string, HTMLAnchorElement>();
  for (const link of documentNav.querySelectorAll<HTMLAnchorElement>('a[href^="#"]')) {
    links.set(decodeURIComponent(link.hash.slice(1)), link);
  }
  const setCurrent = (id: string): void => {
    for (const [target, link] of links) {
      if (target === id) {
        link.setAttribute('aria-current', 'location');
      } else {
        link.removeAttribute('aria-current');
      }
    }
  };
  const observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible[0]) {
        setCurrent(visible[0].target.id);
      }
    },
    { rootMargin: '-40% 0px -50% 0px' },
  );
  for (const section of documentSections) {
    observer.observe(section);
  }
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.documentElement.style.scrollBehavior = 'smooth';
  }
}

setupCopyValues();
setupValidationReveal();
