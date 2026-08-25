import { n as setupCopyValues, t as setupValidationReveal } from "./reveal-validation-T3VErm9b.js";
//#region assets/site/main.ts
var toggle = document.querySelector("[data-site-navigation-toggle]");
var navigation = document.querySelector("[data-site-navigation]");
if (toggle && navigation) {
	document.documentElement.dataset.siteNavigationEnhanced = "";
	toggle.addEventListener("click", () => {
		const open = navigation.toggleAttribute("data-open");
		toggle.setAttribute("aria-expanded", String(open));
	});
}
var documentNav = document.querySelector("[data-document-nav]");
var documentSections = Array.from(document.querySelectorAll("[data-document-section][id]"));
if (documentNav && documentSections.length > 0 && "IntersectionObserver" in window) {
	const links = /* @__PURE__ */ new Map();
	for (const link of documentNav.querySelectorAll("a[href^=\"#\"]")) links.set(decodeURIComponent(link.hash.slice(1)), link);
	const setCurrent = (id) => {
		for (const [target, link] of links) if (target === id) link.setAttribute("aria-current", "location");
		else link.removeAttribute("aria-current");
	};
	const observer = new IntersectionObserver((entries) => {
		const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
		if (visible[0]) setCurrent(visible[0].target.id);
	}, { rootMargin: "-40% 0px -50% 0px" });
	for (const section of documentSections) observer.observe(section);
	if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) document.documentElement.style.scrollBehavior = "smooth";
}
setupCopyValues();
setupValidationReveal();
//#endregion
