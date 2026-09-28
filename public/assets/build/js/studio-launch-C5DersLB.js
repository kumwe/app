import { t as __vitePreload } from "./administrator-DMnX8CNp.js";
import { i as coreLayoutInitialProperties, o as isCoreLayoutBlockType, t as computePreviewDraftDigest } from "./preview-identity-Bvgz1vbs.js";
//#region assets/administrator/components/studio-launch.ts
/**
* Start module for the contextual Studio mount on the Content editor.
*
* The pinned Studio browser module never scans the document on its own: importing it has no side
* effect, and `autoMountStudio()` is the opt-in that discovers `[data-kumwe-studio]` targets and
* mounts the Producer-emitted deployment next to each one. This module is that opt-in. It imports
* the module from the exact URL PHP resolved (the page carries a `modulepreload` with the manifest
* integrity for the same URL, so the module map already holds the integrity-checked bytes), mounts
* every target, and owns what the shell deliberately leaves to the host: swapping between the
* page builder and the structured form, dirty-state confirmation, navigation when the shell
* asks to return, the interface-locale message catalogue, and the authenticated preview surface.
*
* The page builder is the default surface. An editor who switched to the structured form is
* remembered across navigations, and `?surface=form` or `?surface=studio` names the surface
* explicitly. A page that opens on the form defers the mount until the editor asks for the page
* builder: a mount nobody looks at costs the module download and would leave a second copy of
* every content field in the document.
*
* `autoMountStudio()` resolves only once a session is open. On a create target that offers more
* than a blank start, Studio first attaches its create-source chooser to the mount and awaits the
* editor's choice, so the surface is brought in front as soon as Studio attaches its first element
* rather than when the mount promise settles; a hidden chooser could never be answered.
*
* Every host port call below goes to the exact endpoint the PHP-emitted deployment names for that
* operation, with the same-origin credentials and CSRF header it prescribes. Nothing here derives a
* route from a base path, and no response is ever turned into local success: a refusal is shown as
* the host's own message.
*
* The status element reports the launch through `data-studio-launch-state`: `pending` as rendered,
* then `deferred` or `loading`, then `ready` or `failed`; Studio's own `error` and `saved` follow.
*/
var MOUNT_SELECTOR = "[data-kumwe-studio][data-studio-module-url]";
/** The elements Studio attaches to the mount: the create-source chooser, then the contextual shell. */
var STUDIO_ELEMENT_SELECTOR = "kumwe-studio-hosted-start, kumwe-studio-contextual";
/** Where the editor's last surface choice is remembered. */
var SURFACE_PREFERENCE_KEY = "kumwe.studio.surface";
/** The Studio message namespaces the contextual shell reads its labels from. */
var MESSAGE_NAMESPACES = ["studio.shell", "studio.contextual"];
/** The qualified `session.extensions` member PHP carries the preview channel coordinates in. */
var PREVIEW_EXTENSION = "kumwe.app/preview";
/** Prefix of a Blueprint that exists only in the browser session and cannot be previewed yet. */
var DRAFT_BLUEPRINT_PREFIX = "content-blueprint:draft/";
function isStudioBrowserModule(candidate) {
	return typeof candidate === "object" && candidate !== null && typeof candidate.autoMountStudio === "function" && typeof candidate.StudioAuthoringControlRegistry === "function";
}
function isSurface(candidate) {
	return candidate === "studio" || candidate === "form";
}
function requestedSurface() {
	const requested = new URL(window.location.href).searchParams.get("surface");
	return isSurface(requested) ? requested : null;
}
function rememberedSurface() {
	try {
		const remembered = window.localStorage.getItem(SURFACE_PREFERENCE_KEY);
		return isSurface(remembered) ? remembered : null;
	} catch {
		return null;
	}
}
function rememberSurface(surface) {
	try {
		window.localStorage.setItem(SURFACE_PREFERENCE_KEY, surface);
	} catch {}
}
function returnPathOf(detail) {
	const extension = detail?.result?.session?.extensions?.["kumwe.app/return"];
	if (typeof extension !== "object" || extension === null) return void 0;
	const path = extension.path;
	return typeof path === "string" && path.startsWith("/administrator/") ? path : void 0;
}
/** Read the inert deployment block PHP associated with the mount; the shell has already proven it. */
function readDeployment(mount) {
	const id = mount.dataset.kumweStudio ?? "";
	const block = id === "" ? null : document.getElementById(id);
	if (!(block instanceof HTMLScriptElement) || block.type !== "application/json") return void 0;
	try {
		const parsed = JSON.parse(block.textContent ?? "");
		if (typeof parsed !== "object" || parsed === null) return void 0;
		const candidate = parsed;
		if (typeof candidate.session?.sessionGeneration !== "string" || typeof candidate.session.protocolVersion !== "string" || typeof candidate.session.locale?.resolved !== "string" || typeof candidate.session.resourceContext?.key !== "string" || typeof candidate.transport?.routing?.endpoints !== "object" || typeof candidate.transport.authentication?.csrf?.headerName !== "string" || typeof candidate.transport.authentication.csrf.token !== "string") return;
		return candidate;
	} catch {
		return;
	}
}
function previewChannelOf(deployment) {
	const extension = deployment.session.extensions?.[PREVIEW_EXTENSION];
	if (typeof extension !== "object" || extension === null) return void 0;
	const candidate = extension;
	if (typeof candidate.channelId !== "string" || typeof candidate.documentPath !== "string" || typeof candidate.origin !== "string" || typeof candidate.sourceId !== "string" || new URL(candidate.origin).origin !== window.location.origin || !candidate.documentPath.startsWith("/administrator/")) return;
	return candidate;
}
function hostErrorMessage(body) {
	if (typeof body !== "object" || body === null) return void 0;
	const message = body.message?.defaultMessage;
	return typeof message === "string" && message !== "" ? message : void 0;
}
/** One exact host port call over the endpoint the deployment routes for that operation. */
var HostPortClient = class {
	deployment;
	previewSequence = 0;
	constructor(deployment) {
		this.deployment = deployment;
	}
	async call(route, args, preview) {
		const endpoint = this.deployment.transport.routing.endpoints[route];
		if (endpoint === void 0) throw new Error(`The deployment routes no ${route} operation.`);
		const { csrf } = this.deployment.transport.authentication;
		const headers = {
			"content-type": "application/json",
			[csrf.headerName]: csrf.token
		};
		if (preview !== void 0) {
			headers["X-Kumwe-Studio-Preview-Channel"] = preview.channelId;
			headers["X-Kumwe-Studio-Preview-Source"] = preview.sourceId;
			headers["X-Kumwe-Studio-Preview-Sequence"] = String(this.previewSequence);
			this.previewSequence += 1;
		}
		const response = await fetch(endpoint, {
			body: JSON.stringify({
				arguments: args,
				context: {
					locale: this.deployment.session.locale.resolved,
					operationId: `studio.operation/${route.replace("/", ".")}`,
					protocolVersion: this.deployment.session.protocolVersion,
					requestId: `studio-request/${crypto.randomUUID()}`,
					resourceContextKey: this.deployment.session.resourceContext.key,
					sessionGeneration: this.deployment.session.sessionGeneration
				}
			}),
			credentials: "same-origin",
			headers,
			method: "POST"
		});
		let body;
		try {
			body = await response.json();
		} catch {
			throw new Error(`The ${route} response was not JSON.`);
		}
		if (!response.ok) throw new Error(hostErrorMessage(body) ?? `The host refused ${route} (${String(response.status)}).`);
		if (typeof body !== "object" || body === null || !("value" in body)) throw new Error(`The ${route} response carried no value.`);
		return body;
	}
};
/** Apply the interface-locale Studio catalogue the localization port serves to the mounted shell. */
async function localizeShell(client, shell) {
	const result = await client.call("localization/messages", {
		locale: client.deployment.session.locale.resolved,
		namespaces: MESSAGE_NAMESPACES
	});
	if (typeof result.value !== "object" || result.value === null) return;
	const messages = {};
	for (const [key, pattern] of Object.entries(result.value)) if (typeof pattern === "string") messages[key] = { defaultMessage: pattern };
	shell.messages = messages;
}
/**
* The host-owned authenticated preview beside the shell.
*
* It renders the item's last accepted composition through the origin-pinned, replay-resistant
* preview channel and loads the single-use document into a same-origin frame, validating that the
* frame really shows that document before revealing it. The pinned Studio hosted runtime cannot
* stage the live draft for its in-shell preview, so this surface deliberately previews accepted
* revisions only: unsaved work is named as such rather than rendered from the browser's own state.
*/
function setupPreview(region, shell, client, channel) {
	const button = region.querySelector("[data-studio-preview-action]");
	const frame = region.querySelector("[data-studio-contextual-preview]");
	const status = region.querySelector("[data-studio-preview-status]");
	if (button === null || frame === null || status === null || channel === void 0) return { markStale: () => void 0 };
	const messages = {
		failed: status.dataset.messageFailed ?? "The preview could not be rendered.",
		ready: status.dataset.messageReady ?? "The preview shows the last saved composition of this item.",
		rendering: status.dataset.messageRendering ?? "Rendering the preview.",
		stale: status.dataset.messageStale ?? "The item changed since this preview; preview it again.",
		unsaved: status.dataset.messageUnsaved ?? "Save the item before previewing it."
	};
	let documentSequence = 0;
	let generation = 0;
	const setState = (state, text) => {
		status.dataset.studioPreviewState = state;
		status.textContent = text;
	};
	button.hidden = false;
	setState("idle", "");
	const render = async () => {
		const snapshot = shell.snapshot;
		const blueprint = snapshot?.state.blueprint;
		if (snapshot === void 0 || blueprint === void 0 || shell.dirty === true || blueprint.id.startsWith(DRAFT_BLUEPRINT_PREFIX)) {
			setState("unsaved", messages.unsaved);
			return;
		}
		const attempt = ++generation;
		button.disabled = true;
		frame.hidden = true;
		setState("rendering", messages.rendering);
		try {
			const requestId = `requests/preview-${crypto.randomUUID()}`;
			const markers = (await client.call("preview/render", { payload: {
				artifactId: blueprint.id,
				draftDigest: await computePreviewDraftDigest(blueprint, { subtle: crypto.subtle }),
				draftRevision: blueprint.revision,
				requestId,
				viewport: "expanded"
			} }, channel)).value.markers;
			if (attempt !== generation) return;
			const url = new URL(channel.documentPath, channel.origin);
			url.search = new URLSearchParams({
				channel: channel.channelId,
				context: client.deployment.session.resourceContext.key,
				generation: client.deployment.session.sessionGeneration,
				render: requestId,
				sequence: String(documentSequence),
				source: channel.sourceId
			}).toString();
			documentSequence += 1;
			await loadPreviewDocument(frame, url, requestId, Array.isArray(markers) ? markers.length : 0);
			if (attempt !== generation) return;
			frame.hidden = false;
			setState("ready", messages.ready);
		} catch (error) {
			if (attempt !== generation) return;
			frame.hidden = true;
			setState("failed", error instanceof Error && error.message !== "" ? error.message : messages.failed);
		} finally {
			if (attempt === generation) button.disabled = false;
		}
	};
	button.addEventListener("click", () => {
		render();
	});
	return { markStale: () => {
		generation += 1;
		button.disabled = false;
		if (!frame.hidden || status.dataset.studioPreviewState === "ready") {
			frame.hidden = true;
			setState("stale", messages.stale);
		}
	} };
}
/** Load one single-use preview document and prove the frame shows exactly that same-origin document. */
function loadPreviewDocument(frame, url, requestId, markerCount) {
	return new Promise((resolve, reject) => {
		let settled = false;
		const finish = (error) => {
			if (settled) return;
			settled = true;
			window.clearTimeout(timeout);
			frame.removeEventListener("load", onLoad);
			if (error === void 0) resolve();
			else reject(error);
		};
		const onLoad = () => {
			try {
				const loaded = frame.contentDocument;
				const location = frame.contentWindow?.location;
				if (loaded === null || location === void 0 || location.origin !== window.location.origin || location.pathname !== url.pathname || new URLSearchParams(location.search).get("render") !== requestId || loaded.contentType !== "text/html" || loaded.querySelector("[data-kis-surface=\"core.administrator.content-editor\"]") === null || loaded.querySelectorAll("[data-studio-preview-marker]").length !== markerCount) throw new Error("The preview document did not load its same-origin HTML contract.");
				finish();
			} catch (error) {
				finish(error instanceof Error ? error : /* @__PURE__ */ new Error("The preview document was invalid."));
			}
		};
		const timeout = window.setTimeout(() => finish(/* @__PURE__ */ new Error("The preview document did not finish loading.")), 1e4);
		frame.addEventListener("load", onLoad);
		frame.src = url.toString();
	});
}
/**
* Answer the canvas's insert request with a host-allocated node, exactly as the Blueprint surface does.
*
* Studio leaves node identity to the host: the palette, the keyboard command palette and a drop all
* dispatch the same request, and the host executes one canonical `insert-node` command through the
* shell's own command session, so the contextual session's history, dirty state and validation stay
* authoritative in the browser until an explicit save sends them to PHP.
*/
function insertRequested(shell, detail, sessionGeneration) {
	const canvas = shell.blueprintElement;
	const document = canvas?.document;
	if (canvas === void 0 || document === void 0) return;
	const properties = isCoreLayoutBlockType(detail.definition.type) ? coreLayoutInitialProperties(detail.definition.type) : {};
	const slots = Object.fromEntries(detail.definition.slots.map(({ id }) => [id, []]));
	let position = document.roots.length;
	if (detail.parentId !== null && detail.slot !== void 0) position = findNode(document.roots, detail.parentId)?.slots[detail.slot]?.length ?? 0;
	const nodeId = `nodes/${crypto.randomUUID()}`;
	canvas.execute({
		artifactId: document.id,
		baseStateVersion: canvas.stateVersion,
		contractVersion: "0.1-draft",
		expectedRevision: document.revision,
		id: `commands/insert-${crypto.randomUUID()}`,
		kind: "command",
		payload: {
			destination: {
				...detail.parentId === null ? {} : { parentNodeId: detail.parentId },
				position,
				...detail.slot === void 0 ? {} : { slot: detail.slot }
			},
			node: {
				authoring: { mode: isCoreLayoutBlockType(detail.definition.type) ? "structural" : "content" },
				bindings: {},
				id: nodeId,
				properties,
				slots,
				type: detail.definition.type,
				version: detail.definition.version
			}
		},
		sessionGeneration,
		type: "studio.command/insert-node"
	});
	canvas.selectNode(nodeId);
}
function findNode(nodes, id) {
	for (const node of nodes) {
		if (node.id === id) return node;
		for (const children of Object.values(node.slots)) {
			const found = findNode(children, id);
			if (found !== void 0) return found;
		}
	}
}
async function setupStudioLaunch() {
	const mount = document.querySelector(MOUNT_SELECTOR);
	const region = mount?.closest("[data-studio-authoring-region]") ?? null;
	const form = document.querySelector("[data-studio-authoring-fallback-form]");
	const formShell = form?.closest(".editor-grid") ?? form;
	const status = region?.querySelector("[data-studio-launch-status]") ?? null;
	const toggle = region?.querySelector("[data-studio-surface-toggle]") ?? null;
	if (mount === null || region === null || formShell === null || status === null || toggle === null) return;
	const moduleUrl = mount.dataset.studioModuleUrl ?? "";
	let currentReturnPath = mount.dataset.studioReturnPath ?? "/administrator/content";
	const deployment = readDeployment(mount);
	const client = deployment === void 0 ? void 0 : new HostPortClient(deployment);
	let shell;
	let preview = { markStale: () => void 0 };
	let leaving = false;
	const labels = {
		showForm: toggle.dataset.labelForm ?? "Use the structured form",
		showStudio: toggle.dataset.labelStudio ?? "Use the page builder",
		deferred: status.dataset.messageDeferred ?? "The page builder loads when you switch to it.",
		failed: status.dataset.messageFailed ?? "The Studio page builder could not start; the structured form remains available.",
		loading: status.textContent ?? "",
		ready: status.dataset.messageReady ?? "The Studio page builder is ready."
	};
	const requested = requestedSurface();
	if (requested !== null) rememberSurface(requested);
	let surface = requested ?? rememberedSurface() ?? "studio";
	const show = (next) => {
		surface = next;
		region.dataset.studioSurface = next;
		mount.hidden = next !== "studio";
		formShell.hidden = next !== "form";
		toggle.textContent = next === "studio" ? labels.showForm : labels.showStudio;
		toggle.setAttribute("aria-pressed", next === "form" ? "true" : "false");
	};
	let revealed = false;
	const reveal = () => {
		if (revealed) return;
		revealed = true;
		if (status.dataset.studioLaunchState === "loading") {
			status.textContent = labels.ready;
			status.dataset.studioLaunchState = "ready";
		}
		toggle.hidden = false;
		show(surface);
	};
	const attached = new MutationObserver(() => {
		if (mount.querySelector(STUDIO_ELEMENT_SELECTOR) === null) return;
		attached.disconnect();
		reveal();
	});
	const fail = (error) => {
		attached.disconnect();
		status.textContent = labels.failed;
		status.dataset.studioLaunchState = "failed";
		show("form");
		toggle.hidden = true;
		console.error("Studio page builder failed to mount.", error);
	};
	let launched = false;
	const launch = async () => {
		if (launched) return;
		launched = true;
		status.textContent = labels.loading;
		status.dataset.studioLaunchState = "loading";
		try {
			const imported = await __vitePreload(() => import(
				/* @vite-ignore */
				moduleUrl
), []);
			if (!isStudioBrowserModule(imported)) throw new TypeError("The Studio browser module does not export the hosted runtime.");
			const registry = new imported.StudioAuthoringControlRegistry({ strictContentSecurityPolicy: true });
			attached.observe(mount, { childList: true });
			const report = await imported.autoMountStudio({ hosted: () => ({ authoringControlRegistry: registry }) });
			attached.disconnect();
			const handle = report.handles[0];
			if (report.failures.length > 0 || handle === void 0) {
				fail(report.failures[0]?.error ?? /* @__PURE__ */ new Error("No Studio target was mounted."));
				return;
			}
			shell = handle.element;
			if (client !== void 0 && deployment !== void 0) {
				await localizeShell(client, shell).catch((error) => {
					console.error("Studio message catalogue unavailable; built-in labels remain.", error);
				});
				preview = setupPreview(region, shell, client, previewChannelOf(deployment));
			}
			reveal();
		} catch (error) {
			fail(error);
		}
	};
	toggle.addEventListener("click", () => {
		const next = surface === "studio" ? "form" : "studio";
		rememberSurface(next);
		show(next);
		if (next === "studio") launch();
	});
	mount.addEventListener("studio-contextual-return-request", (event) => {
		if (event.detail?.returnContext === void 0) return;
		if (shell?.dirty === true && !window.confirm(status.dataset.messageDiscard ?? "Discard unsaved changes and return?")) {
			event.preventDefault();
			return;
		}
		leaving = true;
		window.location.assign(currentReturnPath);
	});
	mount.addEventListener("studio-contextual-save-complete", (event) => {
		const next = returnPathOf(event.detail);
		if (next !== void 0) currentReturnPath = next;
		status.textContent = status.dataset.messageSaved ?? "Saved.";
		status.dataset.studioLaunchState = "saved";
		preview.markStale();
	});
	mount.addEventListener("studio-insert-request", (event) => {
		if (shell === void 0 || deployment === void 0) return;
		try {
			insertRequested(shell, event.detail, deployment.session.sessionGeneration);
		} catch (error) {
			console.error("Studio refused the requested block insertion.", error);
		}
	});
	mount.addEventListener("studio-host-error", (event) => {
		const detail = event.detail;
		status.textContent = detail?.error?.message?.defaultMessage ?? labels.failed;
		status.dataset.studioLaunchState = "error";
	});
	window.addEventListener("beforeunload", (event) => {
		if (leaving || shell?.dirty !== true) return;
		event.preventDefault();
	});
	if (surface === "form") {
		status.textContent = labels.deferred;
		status.dataset.studioLaunchState = "deferred";
		toggle.hidden = false;
		show("form");
		return;
	}
	await launch();
}
//#endregion
export { setupStudioLaunch };
