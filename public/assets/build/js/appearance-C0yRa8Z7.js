//#region assets/interface-standard/appearance.ts
/**
* Keep the root colour scheme in step with the operating-system appearance while a page stays open.
*
* Every theme token resolves through `light-dark()`, so Chromium and Firefox re-resolve them when the
* appearance changes. WebKit keeps some computed colours from the previous scheme when only the media
* state moves, which is the stale-background finding V2-QA-014 recorded. Restating the scheme as an
* explicit root attribute changes an inherited computed value, so every descendant recomputes and
* repaints in place; focus, form state and the document identity are untouched.
*/
var darkAppearance = "(prefers-color-scheme: dark)";
/**
* Resolve the scheme the browser currently prefers.
*/
function preferredScheme(media) {
	return media.matches ? "dark" : "light";
}
/**
* Mirror the preferred scheme onto `data-kumwe-scheme` now and on every later change.
*/
function setupAppearance(root = document.documentElement) {
	if (typeof window.matchMedia !== "function") return;
	const media = window.matchMedia(darkAppearance);
	const apply = (scheme) => {
		if (root.getAttribute("data-kumwe-scheme") !== scheme) root.setAttribute("data-kumwe-scheme", scheme);
	};
	apply(preferredScheme(media));
	media.addEventListener("change", () => apply(preferredScheme(media)));
}
//#endregion
export { setupAppearance as t };
