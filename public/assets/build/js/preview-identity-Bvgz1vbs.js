//#region node_modules/@kumwe/studio-core/dist/canonical.js
function canonicalStringify(e, t = {}) {
	let r = t.maximumDepth ?? 64;
	if (!Number.isInteger(r) || r < 1) throw RangeError(`Canonical serialization depth must be a positive integer.`);
	return n(e, r, 0);
}
function canonicalUtf8Bytes(t, n = {}) {
	let r = canonicalStringify(t, n), i = [];
	for (let e of r) {
		let t = e.codePointAt(0);
		if (t === void 0) break;
		t <= 127 ? i.push(t) : t <= 2047 ? i.push(192 | t >> 6, 128 | t & 63) : t <= 65535 ? i.push(224 | t >> 12, 128 | t >> 6 & 63, 128 | t & 63) : i.push(240 | t >> 18, 128 | t >> 12 & 63, 128 | t >> 6 & 63, 128 | t & 63);
	}
	return Uint8Array.from(i);
}
function n(e, t, i) {
	if (e === null) return `null`;
	switch (typeof e) {
		case `boolean`: return e ? `true` : `false`;
		case `number`:
			if (!Number.isFinite(e)) throw TypeError(`Canonical JSON cannot represent a non-finite number.`);
			return JSON.stringify(Object.is(e, -0) ? 0 : e);
		case `string`: return JSON.stringify(e);
		case `object`: break;
		default: throw TypeError(`Canonical JSON cannot represent a ${typeof e} value.`);
	}
	if (i >= t) throw RangeError(`Canonical serialization exceeds the depth limit of ${t}.`);
	if (Array.isArray(e)) return `[${e.map((e) => {
		if (e === void 0) throw TypeError(`Canonical JSON arrays cannot contain undefined entries.`);
		return n(e, t, i + 1);
	}).join(`,`)}]`;
	let a = Object.getPrototypeOf(e);
	if (a !== Object.prototype && a !== null) throw TypeError(`Canonical JSON only serializes plain objects and arrays.`);
	let o = Object.keys(e).sort(r), s = [];
	for (let r of o) {
		if (r === `__proto__` || r === `prototype` || r === `constructor`) throw TypeError(`Canonical JSON forbids the object member name ${r}.`);
		let a = e[r];
		a !== void 0 && s.push(`${JSON.stringify(r)}:${n(a, t, i + 1)}`);
	}
	return `{${s.join(`,`)}}`;
}
function r(e, t) {
	return e < t ? -1 : +(e > t);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/clone.js
function cloneContractValue(e) {
	return JSON.parse(JSON.stringify(e));
}
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/types.js
var STUDIO_CONTRACT_VERSION = `0.1-draft`;
var STUDIO_WIRE_PROTOCOL_VERSION = `0.1.0-draft.2`;
var STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE = `studio.host/stale-session-generation`;
//#endregion
//#region node_modules/@kumwe/studio-core/dist/layout.js
var CORE_LAYOUT_BLOCK_TYPES = Object.freeze({
	columns: `studio.core/columns`,
	grid: `studio.core/grid`,
	section: `studio.core/section`,
	stack: `studio.core/stack`
});
var CORE_LAYOUT_THEME_CONTROLS = Object.freeze({
	alignment: `layout-alignment`,
	collapse: `layout-collapse`,
	direction: `layout-direction`,
	spacing: `layout-spacing`,
	visibility: `layout-visibility`
});
var a = Object.freeze([{
	capability: `studio.renderer/layout`,
	surface: `preview`,
	versions: `^1.0.0`
}, {
	capability: `studio.renderer/layout`,
	surface: `web`,
	versions: `^1.0.0`
}]);
var o = [
	`center`,
	`end`,
	`start`,
	`stretch`
];
var s = [
	`preserve`,
	`stack`,
	`wrap`
];
var c = [`block`, `inline`];
var l = [
	`comfortable`,
	`compact`,
	`none`,
	`spacious`
];
var u = [`hidden`, `visible`];
function isCoreLayoutBlockType(e) {
	return Object.values(CORE_LAYOUT_BLOCK_TYPES).includes(e);
}
function createCoreLayoutBlockDefinitions(e = {}) {
	let r = g([...Object.values(CORE_LAYOUT_BLOCK_TYPES), ...e.acceptedChildTypes ?? []]), i = cloneContractValue(e.rendererRequirements ?? a);
	if (i.length === 0) throw RangeError(`Core layout blocks require at least one trusted renderer capability.`);
	return [
		f(`section`, r, i),
		f(`stack`, r, i),
		f(`grid`, r, i),
		f(`columns`, r, i)
	];
}
function coreLayoutInitialProperties(e) {
	switch (e) {
		case CORE_LAYOUT_BLOCK_TYPES.section: return {};
		case CORE_LAYOUT_BLOCK_TYPES.stack: return { direction: `block` };
		case CORE_LAYOUT_BLOCK_TYPES.grid:
		case CORE_LAYOUT_BLOCK_TYPES.columns: return {
			collapse: `stack`,
			columns: 1
		};
	}
}
function f(i, a, o) {
	let s = CORE_LAYOUT_BLOCK_TYPES[i], c = `${i.charAt(0).toUpperCase()}${i.slice(1)}`, l = [
		CORE_LAYOUT_THEME_CONTROLS.alignment,
		CORE_LAYOUT_THEME_CONTROLS.spacing,
		CORE_LAYOUT_THEME_CONTROLS.visibility
	];
	return i === `stack` && l.push(CORE_LAYOUT_THEME_CONTROLS.direction), (i === `grid` || i === `columns`) && l.push(CORE_LAYOUT_THEME_CONTROLS.collapse), {
		accessibility: {
			accessibleName: i === `section` ? `derived` : `not-applicable`,
			category: i === `section` ? `landmark` : `structural`,
			keyboard: {
				defaultMessage: `Use the outline commands to insert, move, and reorder layout children.`,
				key: `studio.blocks/layout-keyboard`
			},
			outputChecks: [`studio.check/reading-order`, `studio.check/reflow`],
			reducedMotion: `not-applicable`
		},
		category: `studio.category/layout`,
		contractVersion: STUDIO_CONTRACT_VERSION,
		editingModes: [`blueprint`, `content`],
		icon: {
			kind: `symbol`,
			value: i
		},
		kind: `block-definition`,
		label: {
			defaultMessage: c,
			key: `studio.blocks/${i}`
		},
		owner: {
			id: `studio.core/blocks`,
			version: `1.0.0`
		},
		ports: [],
		propertyControls: l.map((e) => ({
			control: `studio.control/${e}`,
			property: h(e)
		})),
		propertySchema: m(i),
		rendererRequirements: cloneContractValue([...o]),
		revision: `layout-${i}-r1`,
		slots: [p(i, a)],
		themeControls: l,
		type: s,
		version: `1.0.0`
	};
}
function p(e, n) {
	let r = e === `section` ? `content` : `items`;
	return {
		accepts: { types: cloneContractValue(n) },
		id: r,
		label: {
			defaultMessage: e === `section` ? `Content` : `Items`,
			key: e === `section` ? `studio.blocks/section-content` : `studio.blocks/layout-items`
		},
		maximum: 100,
		minimum: 0,
		ordered: !0
	};
}
function m(e) {
	let t = {
		alignment: { enum: [...o] },
		spacing: { enum: [...l] },
		visibility: { enum: [...u] }
	};
	return e === `stack` && (t.direction = { enum: [...c] }), (e === `grid` || e === `columns`) && (t.collapse = { enum: [...s] }, t.columns = {
		maximum: 12,
		minimum: 1,
		type: `integer`
	}), {
		additionalProperties: !1,
		properties: t,
		type: `object`
	};
}
function h(e) {
	switch (e) {
		case CORE_LAYOUT_THEME_CONTROLS.alignment: return `alignment`;
		case CORE_LAYOUT_THEME_CONTROLS.collapse: return `collapse`;
		case CORE_LAYOUT_THEME_CONTROLS.direction: return `direction`;
		case CORE_LAYOUT_THEME_CONTROLS.spacing: return `spacing`;
		case CORE_LAYOUT_THEME_CONTROLS.visibility: return `visibility`;
		default: throw RangeError(`Unknown core layout control ${e}.`);
	}
}
function g(e) {
	let t = [...new Set(e)];
	if (t.sort((e, t) => e < t ? -1 : +(e > t)), t.length === 0) throw RangeError(`A core layout slot requires at least one accepted block type.`);
	return t;
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-identity.js
function canonicalPreviewDraftBytes(t, n = {}) {
	return canonicalUtf8Bytes(t, n);
}
async function computePreviewDraftDigest(e, t = {}) {
	let n = t.subtle ?? globalThis.crypto.subtle, i = t.maximumDepth === void 0 ? {} : { maximumDepth: t.maximumDepth }, a = Uint8Array.from(canonicalPreviewDraftBytes(e, i)), o = await n.digest(`SHA-256`, a);
	return [...new Uint8Array(o)].map((e) => e.toString(16).padStart(2, `0`)).join(``);
}
//#endregion
export { createCoreLayoutBlockDefinitions as a, STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE as c, canonicalStringify as d, coreLayoutInitialProperties as i, STUDIO_WIRE_PROTOCOL_VERSION as l, CORE_LAYOUT_BLOCK_TYPES as n, isCoreLayoutBlockType as o, CORE_LAYOUT_THEME_CONTROLS as r, STUDIO_CONTRACT_VERSION as s, computePreviewDraftDigest as t, cloneContractValue as u };
