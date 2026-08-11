import { n as setupCopyValues, t as setupValidationReveal } from "./reveal-validation-CTLFnA7Q.js";
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
setupCopyValues();
setupValidationReveal();
//#endregion
