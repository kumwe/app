//#region node_modules/@lit/reactive-element/css-tag.js
/**
* @license
* Copyright 2019 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/
var t$2 = globalThis;
var e$2 = t$2.ShadowRoot && (void 0 === t$2.ShadyCSS || t$2.ShadyCSS.nativeShadow) && "adoptedStyleSheets" in Document.prototype && "replace" in CSSStyleSheet.prototype;
var s$2 = Symbol();
var o$4 = /* @__PURE__ */ new WeakMap();
var n$3 = class {
	constructor(t, e, o) {
		if (this._$cssResult$ = !0, o !== s$2) throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");
		this.cssText = t, this.t = e;
	}
	get styleSheet() {
		let t = this.o;
		const s = this.t;
		if (e$2 && void 0 === t) {
			const e = void 0 !== s && 1 === s.length;
			e && (t = o$4.get(s)), void 0 === t && ((this.o = t = new CSSStyleSheet()).replaceSync(this.cssText), e && o$4.set(s, t));
		}
		return t;
	}
	toString() {
		return this.cssText;
	}
};
var r$4 = (t) => new n$3("string" == typeof t ? t : t + "", void 0, s$2);
var i$3 = (t, ...e) => {
	return new n$3(1 === t.length ? t[0] : e.reduce((e, s, o) => e + ((t) => {
		if (!0 === t._$cssResult$) return t.cssText;
		if ("number" == typeof t) return t;
		throw Error("Value passed to 'css' function must be a 'css' function result: " + t + ". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.");
	})(s) + t[o + 1], t[0]), t, s$2);
};
var S$1 = (s, o) => {
	if (e$2) s.adoptedStyleSheets = o.map((t) => t instanceof CSSStyleSheet ? t : t.styleSheet);
	else for (const e of o) {
		const o = document.createElement("style"), n = t$2.litNonce;
		void 0 !== n && o.setAttribute("nonce", n), o.textContent = e.cssText, s.appendChild(o);
	}
};
var c$2 = e$2 ? (t) => t : (t) => t instanceof CSSStyleSheet ? ((t) => {
	let e = "";
	for (const s of t.cssRules) e += s.cssText;
	return r$4(e);
})(t) : t;
//#endregion
//#region node_modules/@lit/reactive-element/reactive-element.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/ var { is: i$2, defineProperty: e$1, getOwnPropertyDescriptor: h$1, getOwnPropertyNames: r$3, getOwnPropertySymbols: o$3, getPrototypeOf: n$2 } = Object, a$1 = globalThis, c$1 = a$1.trustedTypes, l$1 = c$1 ? c$1.emptyScript : "", p$1 = a$1.reactiveElementPolyfillSupport, d$1 = (t, s) => t, u$1 = {
	toAttribute(t, s) {
		switch (s) {
			case Boolean:
				t = t ? l$1 : null;
				break;
			case Object:
			case Array: t = null == t ? t : JSON.stringify(t);
		}
		return t;
	},
	fromAttribute(t, s) {
		let i = t;
		switch (s) {
			case Boolean:
				i = null !== t;
				break;
			case Number:
				i = null === t ? null : Number(t);
				break;
			case Object:
			case Array: try {
				i = JSON.parse(t);
			} catch (t) {
				i = null;
			}
		}
		return i;
	}
}, f$1 = (t, s) => !i$2(t, s), b$1 = {
	attribute: !0,
	type: String,
	converter: u$1,
	reflect: !1,
	useDefault: !1,
	hasChanged: f$1
};
Symbol.metadata ??= Symbol("metadata"), a$1.litPropertyMetadata ??= /* @__PURE__ */ new WeakMap();
var y$1 = class extends HTMLElement {
	static addInitializer(t) {
		this._$Ei(), (this.l ??= []).push(t);
	}
	static get observedAttributes() {
		return this.finalize(), this._$Eh && [...this._$Eh.keys()];
	}
	static createProperty(t, s = b$1) {
		if (s.state && (s.attribute = !1), this._$Ei(), this.prototype.hasOwnProperty(t) && ((s = Object.create(s)).wrapped = !0), this.elementProperties.set(t, s), !s.noAccessor) {
			const i = Symbol(), h = this.getPropertyDescriptor(t, i, s);
			void 0 !== h && e$1(this.prototype, t, h);
		}
	}
	static getPropertyDescriptor(t, s, i) {
		const { get: e, set: r } = h$1(this.prototype, t) ?? {
			get() {
				return this[s];
			},
			set(t) {
				this[s] = t;
			}
		};
		return {
			get: e,
			set(s) {
				const h = e?.call(this);
				r?.call(this, s), this.requestUpdate(t, h, i);
			},
			configurable: !0,
			enumerable: !0
		};
	}
	static getPropertyOptions(t) {
		return this.elementProperties.get(t) ?? b$1;
	}
	static _$Ei() {
		if (this.hasOwnProperty(d$1("elementProperties"))) return;
		const t = n$2(this);
		t.finalize(), void 0 !== t.l && (this.l = [...t.l]), this.elementProperties = new Map(t.elementProperties);
	}
	static finalize() {
		if (this.hasOwnProperty(d$1("finalized"))) return;
		if (this.finalized = !0, this._$Ei(), this.hasOwnProperty(d$1("properties"))) {
			const t = this.properties, s = [...r$3(t), ...o$3(t)];
			for (const i of s) this.createProperty(i, t[i]);
		}
		const t = this[Symbol.metadata];
		if (null !== t) {
			const s = litPropertyMetadata.get(t);
			if (void 0 !== s) for (const [t, i] of s) this.elementProperties.set(t, i);
		}
		this._$Eh = /* @__PURE__ */ new Map();
		for (const [t, s] of this.elementProperties) {
			const i = this._$Eu(t, s);
			void 0 !== i && this._$Eh.set(i, t);
		}
		this.elementStyles = this.finalizeStyles(this.styles);
	}
	static finalizeStyles(s) {
		const i = [];
		if (Array.isArray(s)) {
			const e = new Set(s.flat(1 / 0).reverse());
			for (const s of e) i.unshift(c$2(s));
		} else void 0 !== s && i.push(c$2(s));
		return i;
	}
	static _$Eu(t, s) {
		const i = s.attribute;
		return !1 === i ? void 0 : "string" == typeof i ? i : "string" == typeof t ? t.toLowerCase() : void 0;
	}
	constructor() {
		super(), this._$Ep = void 0, this.isUpdatePending = !1, this.hasUpdated = !1, this._$Em = null, this._$Ev();
	}
	_$Ev() {
		this._$ES = new Promise((t) => this.enableUpdating = t), this._$AL = /* @__PURE__ */ new Map(), this._$E_(), this.requestUpdate(), this.constructor.l?.forEach((t) => t(this));
	}
	addController(t) {
		(this._$EO ??= /* @__PURE__ */ new Set()).add(t), void 0 !== this.renderRoot && this.isConnected && t.hostConnected?.();
	}
	removeController(t) {
		this._$EO?.delete(t);
	}
	_$E_() {
		const t = /* @__PURE__ */ new Map(), s = this.constructor.elementProperties;
		for (const i of s.keys()) this.hasOwnProperty(i) && (t.set(i, this[i]), delete this[i]);
		t.size > 0 && (this._$Ep = t);
	}
	createRenderRoot() {
		const t = this.shadowRoot ?? this.attachShadow(this.constructor.shadowRootOptions);
		return S$1(t, this.constructor.elementStyles), t;
	}
	connectedCallback() {
		this.renderRoot ??= this.createRenderRoot(), this.enableUpdating(!0), this._$EO?.forEach((t) => t.hostConnected?.());
	}
	enableUpdating(t) {}
	disconnectedCallback() {
		this._$EO?.forEach((t) => t.hostDisconnected?.());
	}
	attributeChangedCallback(t, s, i) {
		this._$AK(t, i);
	}
	_$ET(t, s) {
		const i = this.constructor.elementProperties.get(t), e = this.constructor._$Eu(t, i);
		if (void 0 !== e && !0 === i.reflect) {
			const h = (void 0 !== i.converter?.toAttribute ? i.converter : u$1).toAttribute(s, i.type);
			this._$Em = t, null == h ? this.removeAttribute(e) : this.setAttribute(e, h), this._$Em = null;
		}
	}
	_$AK(t, s) {
		const i = this.constructor, e = i._$Eh.get(t);
		if (void 0 !== e && this._$Em !== e) {
			const t = i.getPropertyOptions(e), h = "function" == typeof t.converter ? { fromAttribute: t.converter } : void 0 !== t.converter?.fromAttribute ? t.converter : u$1;
			this._$Em = e;
			const r = h.fromAttribute(s, t.type);
			this[e] = r ?? this._$Ej?.get(e) ?? r, this._$Em = null;
		}
	}
	requestUpdate(t, s, i, e = !1, h) {
		if (void 0 !== t) {
			const r = this.constructor;
			if (!1 === e && (h = this[t]), i ??= r.getPropertyOptions(t), !((i.hasChanged ?? f$1)(h, s) || i.useDefault && i.reflect && h === this._$Ej?.get(t) && !this.hasAttribute(r._$Eu(t, i)))) return;
			this.C(t, s, i);
		}
		!1 === this.isUpdatePending && (this._$ES = this._$EP());
	}
	C(t, s, { useDefault: i, reflect: e, wrapped: h }, r) {
		i && !(this._$Ej ??= /* @__PURE__ */ new Map()).has(t) && (this._$Ej.set(t, r ?? s ?? this[t]), !0 !== h || void 0 !== r) || (this._$AL.has(t) || (this.hasUpdated || i || (s = void 0), this._$AL.set(t, s)), !0 === e && this._$Em !== t && (this._$Eq ??= /* @__PURE__ */ new Set()).add(t));
	}
	async _$EP() {
		this.isUpdatePending = !0;
		try {
			await this._$ES;
		} catch (t) {
			Promise.reject(t);
		}
		const t = this.scheduleUpdate();
		return null != t && await t, !this.isUpdatePending;
	}
	scheduleUpdate() {
		return this.performUpdate();
	}
	performUpdate() {
		if (!this.isUpdatePending) return;
		if (!this.hasUpdated) {
			if (this.renderRoot ??= this.createRenderRoot(), this._$Ep) {
				for (const [t, s] of this._$Ep) this[t] = s;
				this._$Ep = void 0;
			}
			const t = this.constructor.elementProperties;
			if (t.size > 0) for (const [s, i] of t) {
				const { wrapped: t } = i, e = this[s];
				!0 !== t || this._$AL.has(s) || void 0 === e || this.C(s, void 0, i, e);
			}
		}
		let t = !1;
		const s = this._$AL;
		try {
			t = this.shouldUpdate(s), t ? (this.willUpdate(s), this._$EO?.forEach((t) => t.hostUpdate?.()), this.update(s)) : this._$EM();
		} catch (s) {
			throw t = !1, this._$EM(), s;
		}
		t && this._$AE(s);
	}
	willUpdate(t) {}
	_$AE(t) {
		this._$EO?.forEach((t) => t.hostUpdated?.()), this.hasUpdated || (this.hasUpdated = !0, this.firstUpdated(t)), this.updated(t);
	}
	_$EM() {
		this._$AL = /* @__PURE__ */ new Map(), this.isUpdatePending = !1;
	}
	get updateComplete() {
		return this.getUpdateComplete();
	}
	getUpdateComplete() {
		return this._$ES;
	}
	shouldUpdate(t) {
		return !0;
	}
	update(t) {
		this._$Eq &&= this._$Eq.forEach((t) => this._$ET(t, this[t])), this._$EM();
	}
	updated(t) {}
	firstUpdated(t) {}
};
y$1.elementStyles = [], y$1.shadowRootOptions = { mode: "open" }, y$1[d$1("elementProperties")] = /* @__PURE__ */ new Map(), y$1[d$1("finalized")] = /* @__PURE__ */ new Map(), p$1?.({ ReactiveElement: y$1 }), (a$1.reactiveElementVersions ??= []).push("2.1.2");
//#endregion
//#region node_modules/lit-html/lit-html.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/
var t$1 = globalThis;
var i$1 = (t) => t;
var s$1 = t$1.trustedTypes;
var e = s$1 ? s$1.createPolicy("lit-html", { createHTML: (t) => t }) : void 0;
var h = "$lit$";
var o$2 = `lit$${Math.random().toFixed(9).slice(2)}$`;
var n$1 = "?" + o$2;
var r$2 = `<${n$1}>`;
var l = document;
var c = () => l.createComment("");
var a = (t) => null === t || "object" != typeof t && "function" != typeof t;
var u = Array.isArray;
var d = (t) => u(t) || "function" == typeof t?.[Symbol.iterator];
var f = "[ 	\n\f\r]";
var v = /<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g;
var _ = /-->/g;
var m = />/g;
var p = RegExp(`>|${f}(?:([^\\s"'>=/]+)(${f}*=${f}*(?:[^ \t\n\f\r"'\`<>=]|("|')|))|$)`, "g");
var g = /'/g;
var $ = /"/g;
var y = /^(?:script|style|textarea|title)$/i;
var x = (t) => (i, ...s) => ({
	_$litType$: t,
	strings: i,
	values: s
});
var b = x(1);
var E = Symbol.for("lit-noChange");
var A = Symbol.for("lit-nothing");
var C = /* @__PURE__ */ new WeakMap();
var P = l.createTreeWalker(l, 129);
function V(t, i) {
	if (!u(t) || !t.hasOwnProperty("raw")) throw Error("invalid template strings array");
	return void 0 !== e ? e.createHTML(i) : i;
}
var N = (t, i) => {
	const s = t.length - 1, e = [];
	let n, l = 2 === i ? "<svg>" : 3 === i ? "<math>" : "", c = v;
	for (let i = 0; i < s; i++) {
		const s = t[i];
		let a, u, d = -1, f = 0;
		for (; f < s.length && (c.lastIndex = f, u = c.exec(s), null !== u);) f = c.lastIndex, c === v ? "!--" === u[1] ? c = _ : void 0 !== u[1] ? c = m : void 0 !== u[2] ? (y.test(u[2]) && (n = RegExp("</" + u[2], "g")), c = p) : void 0 !== u[3] && (c = p) : c === p ? ">" === u[0] ? (c = n ?? v, d = -1) : void 0 === u[1] ? d = -2 : (d = c.lastIndex - u[2].length, a = u[1], c = void 0 === u[3] ? p : "\"" === u[3] ? $ : g) : c === $ || c === g ? c = p : c === _ || c === m ? c = v : (c = p, n = void 0);
		const x = c === p && t[i + 1].startsWith("/>") ? " " : "";
		l += c === v ? s + r$2 : d >= 0 ? (e.push(a), s.slice(0, d) + h + s.slice(d) + o$2 + x) : s + o$2 + (-2 === d ? i : x);
	}
	return [V(t, l + (t[s] || "<?>") + (2 === i ? "</svg>" : 3 === i ? "</math>" : "")), e];
};
var S = class S {
	constructor({ strings: t, _$litType$: i }, e) {
		let r;
		this.parts = [];
		let l = 0, a = 0;
		const u = t.length - 1, d = this.parts, [f, v] = N(t, i);
		if (this.el = S.createElement(f, e), P.currentNode = this.el.content, 2 === i || 3 === i) {
			const t = this.el.content.firstChild;
			t.replaceWith(...t.childNodes);
		}
		for (; null !== (r = P.nextNode()) && d.length < u;) {
			if (1 === r.nodeType) {
				if (r.hasAttributes()) for (const t of r.getAttributeNames()) if (t.endsWith(h)) {
					const i = v[a++], s = r.getAttribute(t).split(o$2), e = /([.?@])?(.*)/.exec(i);
					d.push({
						type: 1,
						index: l,
						name: e[2],
						strings: s,
						ctor: "." === e[1] ? I : "?" === e[1] ? L : "@" === e[1] ? z : H
					}), r.removeAttribute(t);
				} else t.startsWith(o$2) && (d.push({
					type: 6,
					index: l
				}), r.removeAttribute(t));
				if (y.test(r.tagName)) {
					const t = r.textContent.split(o$2), i = t.length - 1;
					if (i > 0) {
						r.textContent = s$1 ? s$1.emptyScript : "";
						for (let s = 0; s < i; s++) r.append(t[s], c()), P.nextNode(), d.push({
							type: 2,
							index: ++l
						});
						r.append(t[i], c());
					}
				}
			} else if (8 === r.nodeType) if (r.data === n$1) d.push({
				type: 2,
				index: l
			});
			else {
				let t = -1;
				for (; -1 !== (t = r.data.indexOf(o$2, t + 1));) d.push({
					type: 7,
					index: l
				}), t += o$2.length - 1;
			}
			l++;
		}
	}
	static createElement(t, i) {
		const s = l.createElement("template");
		return s.innerHTML = t, s;
	}
};
function M(t, i, s = t, e) {
	if (i === E) return i;
	let h = void 0 !== e ? s._$Co?.[e] : s._$Cl;
	const o = a(i) ? void 0 : i._$litDirective$;
	return h?.constructor !== o && (h?._$AO?.(!1), void 0 === o ? h = void 0 : (h = new o(t), h._$AT(t, s, e)), void 0 !== e ? (s._$Co ??= [])[e] = h : s._$Cl = h), void 0 !== h && (i = M(t, h._$AS(t, i.values), h, e)), i;
}
var R = class {
	constructor(t, i) {
		this._$AV = [], this._$AN = void 0, this._$AD = t, this._$AM = i;
	}
	get parentNode() {
		return this._$AM.parentNode;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	u(t) {
		const { el: { content: i }, parts: s } = this._$AD, e = (t?.creationScope ?? l).importNode(i, !0);
		P.currentNode = e;
		let h = P.nextNode(), o = 0, n = 0, r = s[0];
		for (; void 0 !== r;) {
			if (o === r.index) {
				let i;
				2 === r.type ? i = new k(h, h.nextSibling, this, t) : 1 === r.type ? i = new r.ctor(h, r.name, r.strings, this, t) : 6 === r.type && (i = new Z(h, this, t)), this._$AV.push(i), r = s[++n];
			}
			o !== r?.index && (h = P.nextNode(), o++);
		}
		return P.currentNode = l, e;
	}
	p(t) {
		let i = 0;
		for (const s of this._$AV) void 0 !== s && (void 0 !== s.strings ? (s._$AI(t, s, i), i += s.strings.length - 2) : s._$AI(t[i])), i++;
	}
};
var k = class k {
	get _$AU() {
		return this._$AM?._$AU ?? this._$Cv;
	}
	constructor(t, i, s, e) {
		this.type = 2, this._$AH = A, this._$AN = void 0, this._$AA = t, this._$AB = i, this._$AM = s, this.options = e, this._$Cv = e?.isConnected ?? !0;
	}
	get parentNode() {
		let t = this._$AA.parentNode;
		const i = this._$AM;
		return void 0 !== i && 11 === t?.nodeType && (t = i.parentNode), t;
	}
	get startNode() {
		return this._$AA;
	}
	get endNode() {
		return this._$AB;
	}
	_$AI(t, i = this) {
		t = M(this, t, i), a(t) ? t === A || null == t || "" === t ? (this._$AH !== A && this._$AR(), this._$AH = A) : t !== this._$AH && t !== E && this._(t) : void 0 !== t._$litType$ ? this.$(t) : void 0 !== t.nodeType ? this.T(t) : d(t) ? this.k(t) : this._(t);
	}
	O(t) {
		return this._$AA.parentNode.insertBefore(t, this._$AB);
	}
	T(t) {
		this._$AH !== t && (this._$AR(), this._$AH = this.O(t));
	}
	_(t) {
		this._$AH !== A && a(this._$AH) ? this._$AA.nextSibling.data = t : this.T(l.createTextNode(t)), this._$AH = t;
	}
	$(t) {
		const { values: i, _$litType$: s } = t, e = "number" == typeof s ? this._$AC(t) : (void 0 === s.el && (s.el = S.createElement(V(s.h, s.h[0]), this.options)), s);
		if (this._$AH?._$AD === e) this._$AH.p(i);
		else {
			const t = new R(e, this), s = t.u(this.options);
			t.p(i), this.T(s), this._$AH = t;
		}
	}
	_$AC(t) {
		let i = C.get(t.strings);
		return void 0 === i && C.set(t.strings, i = new S(t)), i;
	}
	k(t) {
		u(this._$AH) || (this._$AH = [], this._$AR());
		const i = this._$AH;
		let s, e = 0;
		for (const h of t) e === i.length ? i.push(s = new k(this.O(c()), this.O(c()), this, this.options)) : s = i[e], s._$AI(h), e++;
		e < i.length && (this._$AR(s && s._$AB.nextSibling, e), i.length = e);
	}
	_$AR(t = this._$AA.nextSibling, s) {
		for (this._$AP?.(!1, !0, s); t !== this._$AB;) {
			const s = i$1(t).nextSibling;
			i$1(t).remove(), t = s;
		}
	}
	setConnected(t) {
		void 0 === this._$AM && (this._$Cv = t, this._$AP?.(t));
	}
};
var H = class {
	get tagName() {
		return this.element.tagName;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	constructor(t, i, s, e, h) {
		this.type = 1, this._$AH = A, this._$AN = void 0, this.element = t, this.name = i, this._$AM = e, this.options = h, s.length > 2 || "" !== s[0] || "" !== s[1] ? (this._$AH = Array(s.length - 1).fill(/* @__PURE__ */ new String()), this.strings = s) : this._$AH = A;
	}
	_$AI(t, i = this, s, e) {
		const h = this.strings;
		let o = !1;
		if (void 0 === h) t = M(this, t, i, 0), o = !a(t) || t !== this._$AH && t !== E, o && (this._$AH = t);
		else {
			const e = t;
			let n, r;
			for (t = h[0], n = 0; n < h.length - 1; n++) r = M(this, e[s + n], i, n), r === E && (r = this._$AH[n]), o ||= !a(r) || r !== this._$AH[n], r === A ? t = A : t !== A && (t += (r ?? "") + h[n + 1]), this._$AH[n] = r;
		}
		o && !e && this.j(t);
	}
	j(t) {
		t === A ? this.element.removeAttribute(this.name) : this.element.setAttribute(this.name, t ?? "");
	}
};
var I = class extends H {
	constructor() {
		super(...arguments), this.type = 3;
	}
	j(t) {
		this.element[this.name] = t === A ? void 0 : t;
	}
};
var L = class extends H {
	constructor() {
		super(...arguments), this.type = 4;
	}
	j(t) {
		this.element.toggleAttribute(this.name, !!t && t !== A);
	}
};
var z = class extends H {
	constructor(t, i, s, e, h) {
		super(t, i, s, e, h), this.type = 5;
	}
	_$AI(t, i = this) {
		if ((t = M(this, t, i, 0) ?? A) === E) return;
		const s = this._$AH, e = t === A && s !== A || t.capture !== s.capture || t.once !== s.once || t.passive !== s.passive, h = t !== A && (s === A || e);
		e && this.element.removeEventListener(this.name, this, s), h && this.element.addEventListener(this.name, this, t), this._$AH = t;
	}
	handleEvent(t) {
		"function" == typeof this._$AH ? this._$AH.call(this.options?.host ?? this.element, t) : this._$AH.handleEvent(t);
	}
};
var Z = class {
	constructor(t, i, s) {
		this.element = t, this.type = 6, this._$AN = void 0, this._$AM = i, this.options = s;
	}
	get _$AU() {
		return this._$AM._$AU;
	}
	_$AI(t) {
		M(this, t);
	}
};
var B = t$1.litHtmlPolyfillSupport;
B?.(S, k), (t$1.litHtmlVersions ??= []).push("3.3.3");
var D = (t, i, s) => {
	const e = s?.renderBefore ?? i;
	let h = e._$litPart$;
	if (void 0 === h) {
		const t = s?.renderBefore ?? null;
		e._$litPart$ = h = new k(i.insertBefore(c(), t), t, void 0, s ?? {});
	}
	return h._$AI(t), h;
};
//#endregion
//#region node_modules/lit-element/lit-element.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/ var s = globalThis;
var i = class extends y$1 {
	constructor() {
		super(...arguments), this.renderOptions = { host: this }, this._$Do = void 0;
	}
	createRenderRoot() {
		const t = super.createRenderRoot();
		return this.renderOptions.renderBefore ??= t.firstChild, t;
	}
	update(t) {
		const r = this.render();
		this.hasUpdated || (this.renderOptions.isConnected = this.isConnected), super.update(t), this._$Do = D(r, this.renderRoot, this.renderOptions);
	}
	connectedCallback() {
		super.connectedCallback(), this._$Do?.setConnected(!0);
	}
	disconnectedCallback() {
		super.disconnectedCallback(), this._$Do?.setConnected(!1);
	}
	render() {
		return E;
	}
};
i._$litElement$ = !0, i["finalized"] = !0, s.litElementHydrateSupport?.({ LitElement: i });
var o$1 = s.litElementPolyfillSupport;
o$1?.({ LitElement: i });
(s.litElementVersions ??= []).push("4.2.2");
//#endregion
//#region node_modules/@lit/reactive-element/decorators/custom-element.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/
var t = (t) => (e, o) => {
	void 0 !== o ? o.addInitializer(() => {
		customElements.define(t, e);
	}) : customElements.define(t, e);
};
//#endregion
//#region node_modules/@lit/reactive-element/decorators/property.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/ var o = {
	attribute: !0,
	type: String,
	converter: u$1,
	reflect: !1,
	hasChanged: f$1
};
var r$1 = (t = o, e, r) => {
	const { kind: n, metadata: i } = r;
	let s = globalThis.litPropertyMetadata.get(i);
	if (void 0 === s && globalThis.litPropertyMetadata.set(i, s = /* @__PURE__ */ new Map()), "setter" === n && ((t = Object.create(t)).wrapped = !0), s.set(r.name, t), "accessor" === n) {
		const { name: o } = r;
		return {
			set(r) {
				const n = e.get.call(this);
				e.set.call(this, r), this.requestUpdate(o, n, t, !0, r);
			},
			init(e) {
				return void 0 !== e && this.C(o, void 0, t, e), e;
			}
		};
	}
	if ("setter" === n) {
		const { name: o } = r;
		return function(r) {
			const n = this[o];
			e.call(this, r), this.requestUpdate(o, n, t, !0, r);
		};
	}
	throw Error("Unsupported decorator location: " + n);
};
function n(t) {
	return (e, o) => "object" == typeof o ? r$1(t, e, o) : ((t, e, o) => {
		const r = e.hasOwnProperty(o);
		return e.constructor.createProperty(o, t), r ? Object.getOwnPropertyDescriptor(e, o) : void 0;
	})(t, e, o);
}
//#endregion
//#region node_modules/@lit/reactive-element/decorators/state.js
/**
* @license
* Copyright 2017 Google LLC
* SPDX-License-Identifier: BSD-3-Clause
*/ function r(r) {
	return n({
		...r,
		state: !0,
		attribute: !1
	});
}
//#endregion
//#region \0@oxc-project+runtime@0.143.0/helpers/esm/decorate.js
function __decorate(decorators, target, key, desc) {
	var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
	if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
	else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
	return c > 3 && r && Object.defineProperty(target, key, r), r;
}
//#endregion
//#region assets/administrator/components/command-palette.ts
var KumweCommandPalette = class KumweCommandPalette extends i {
	#_source_accessor_storage = "administrator-command-data";
	get source() {
		return this.#_source_accessor_storage;
	}
	set source(value) {
		this.#_source_accessor_storage = value;
	}
	#_open_accessor_storage = false;
	get open() {
		return this.#_open_accessor_storage;
	}
	set open(value) {
		this.#_open_accessor_storage = value;
	}
	#_query_accessor_storage = "";
	get query() {
		return this.#_query_accessor_storage;
	}
	set query(value) {
		this.#_query_accessor_storage = value;
	}
	items = [];
	static styles = i$3`
    :host { display: contents; }
    dialog { width: min(680px, calc(100vw - 2rem)); max-height: min(680px, calc(100vh - 2rem)); padding: 0; border: 1px solid var(--kumwe-border-strong); border-radius: var(--kumwe-radius-xl); background: var(--kumwe-surface-elevated); color: var(--kumwe-text); box-shadow: var(--kumwe-shadow-xl); }
    dialog::backdrop { background: rgb(10 20 36 / .58); backdrop-filter: blur(5px); }
    form { position: sticky; top: 0; display: flex; gap: .75rem; align-items: center; padding: 1rem; background: var(--kumwe-surface-elevated); border-bottom: 1px solid var(--kumwe-border); }
    input { min-width: 0; flex: 1; border: 0; outline: 0; background: transparent; color: inherit; font: 600 1.05rem/1.4 var(--kumwe-font-sans); }
    button { border: 0; border-radius: .55rem; padding: .45rem .6rem; background: var(--kumwe-surface-subtle); color: var(--kumwe-text-muted); cursor: pointer; }
    ul { display: grid; gap: .35rem; margin: 0; padding: .65rem; list-style: none; overflow: auto; }
    a { display: grid; gap: .15rem; padding: .85rem .9rem; border-radius: .7rem; color: var(--kumwe-text); text-decoration: none; }
    a:hover, a:focus-visible { outline: 0; background: var(--kumwe-accent-soft); color: var(--kumwe-accent-strong); }
    strong { font-size: .94rem; }
    span { color: var(--kumwe-text-muted); font-size: .82rem; }
    .empty { padding: 2.2rem 1rem; color: var(--kumwe-text-muted); text-align: center; }
  `;
	connectedCallback() {
		super.connectedCallback();
		const source = document.getElementById(this.source);
		if (source?.textContent) try {
			const parsed = JSON.parse(source.textContent);
			if (Array.isArray(parsed)) this.items = parsed.filter(this.isCommandItem);
		} catch {
			this.items = [];
		}
		window.addEventListener("keydown", this.handleShortcut);
		document.querySelectorAll("[data-open-command-palette]").forEach((button) => {
			button.addEventListener("click", this.show);
		});
	}
	disconnectedCallback() {
		window.removeEventListener("keydown", this.handleShortcut);
		super.disconnectedCallback();
	}
	isCommandItem = (value) => {
		if (typeof value !== "object" || value === null) return false;
		const item = value;
		return typeof item.label === "string" && typeof item.href === "string" && typeof item.description === "string" && typeof item.keywords === "string";
	};
	handleShortcut = (event) => {
		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
			event.preventDefault();
			this.show();
		}
	};
	show = () => {
		this.open = true;
		this.updateComplete.then(() => {
			this.renderRoot.querySelector("dialog")?.showModal();
			this.renderRoot.querySelector("input")?.focus();
		}).catch(() => void 0);
	};
	close() {
		this.renderRoot.querySelector("dialog")?.close();
		this.open = false;
		this.query = "";
	}
	get results() {
		const query = this.query.trim().toLocaleLowerCase();
		if (!query) return this.items.slice(0, 12);
		return this.items.filter((item) => `${item.label} ${item.description} ${item.keywords}`.toLocaleLowerCase().includes(query)).slice(0, 12);
	}
	render() {
		if (!this.open) return A;
		return b`<dialog @close=${() => {
			this.open = false;
		}} @click=${(event) => {
			if (event.target === event.currentTarget) this.close();
		}}>
      <form method="dialog" @submit=${(event) => event.preventDefault()}>
        <span aria-hidden="true">⌕</span>
        <input aria-label="Search administrator commands" placeholder="Search pages and actions…" .value=${this.query} @input=${(event) => {
			this.query = event.currentTarget.value;
		}}>
        <button type="button" @click=${() => this.close()} aria-label="Close command palette">Esc</button>
      </form>
      ${this.results.length === 0 ? b`<p class="empty">No matching administrator action.</p>` : b`<ul>
        ${this.results.map((item) => b`<li><a href=${item.href}><strong>${item.label}</strong><span>${item.description}</span></a></li>`)}
      </ul>`}
    </dialog>`;
	}
};
__decorate([n({ type: String })], KumweCommandPalette.prototype, "source", null);
__decorate([r()], KumweCommandPalette.prototype, "open", null);
__decorate([r()], KumweCommandPalette.prototype, "query", null);
KumweCommandPalette = __decorate([t("kumwe-command-palette")], KumweCommandPalette);
//#endregion
//#region assets/administrator/components/field-builder.ts
var KumweFieldBuilder = class KumweFieldBuilder extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		if (target.closest("[data-add-field]")) {
			event.preventDefault();
			this.addRow();
			return;
		}
		const remove = target.closest("[data-remove-field]");
		if (remove) {
			event.preventDefault();
			const row = remove.closest("[data-field-row]");
			if (row && this.querySelectorAll("[data-field-row]").length > 1) row.remove();
		}
	};
	addRow() {
		const template = this.querySelector("template[data-field-template]");
		const rows = this.querySelector("[data-field-rows]");
		if (!template || !rows) return;
		const index = this.querySelectorAll("[data-field-row]").length;
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id"
			]) {
				const value = element.getAttribute(attribute);
				if (value) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		rows.append(fragment);
		rows.querySelectorAll("[data-field-row]:last-child input").item(0)?.focus();
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweFieldBuilder = __decorate([t("kumwe-field-builder")], KumweFieldBuilder);
//#endregion
//#region assets/administrator/components/workflow-builder.ts
var KumweWorkflowBuilder = class KumweWorkflowBuilder extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
		this.addEventListener("input", this.handleInput);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		this.removeEventListener("input", this.handleInput);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const add = target.closest("[data-add-workflow-row]");
		if (add) {
			event.preventDefault();
			this.addRow(add.dataset.addWorkflowRow ?? "state");
			return;
		}
		const remove = target.closest("[data-remove-workflow-row]");
		if (remove) {
			event.preventDefault();
			remove.closest("[data-workflow-row]")?.remove();
		}
	};
	handleInput = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement) || !target.matches("[data-state-key]")) return;
		const initial = target.closest("[data-workflow-row]")?.querySelector("input[type=\"radio\"][name=\"initial_state_key\"]");
		if (initial) initial.value = target.value;
	};
	addRow(kind) {
		const template = this.querySelector(`template[data-${kind}-template]`);
		const rows = this.querySelector(`[data-${kind}-rows]`);
		if (!template || !rows) return;
		const index = rows.querySelectorAll("[data-workflow-row]").length;
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id"
			]) {
				const value = element.getAttribute(attribute);
				if (value) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		rows.append(fragment);
		rows.querySelectorAll("[data-workflow-row]:last-child input").item(0)?.focus();
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweWorkflowBuilder = __decorate([t("kumwe-workflow-builder")], KumweWorkflowBuilder);
//#endregion
//#region assets/administrator/components/menu-tree.ts
var KumweMenuTree = class KumweMenuTree extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelectorAll("[data-menu-item]").forEach((item) => {
			item.setAttribute("draggable", "true");
			item.addEventListener("dragstart", this.dragStart);
			item.addEventListener("dragover", this.dragOver);
			item.addEventListener("drop", this.drop);
			item.addEventListener("dragend", this.dragEnd);
		});
	}
	dragStart = (event) => {
		const item = event.currentTarget;
		if (!(item instanceof HTMLElement) || !event.dataTransfer) return;
		event.dataTransfer.effectAllowed = "move";
		event.dataTransfer.setData("text/plain", item.dataset.menuItem ?? "");
		item.dataset.dragging = "";
	};
	dragOver = (event) => {
		event.preventDefault();
		if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
	};
	drop = (event) => {
		event.preventDefault();
		const target = event.currentTarget;
		if (!(target instanceof HTMLElement) || !event.dataTransfer) return;
		const sourceId = event.dataTransfer.getData("text/plain");
		const source = this.querySelector(`[data-menu-item="${CSS.escape(sourceId)}"]`);
		if (!source || source === target) return;
		target.before(source);
		this.renumber();
	};
	dragEnd = (event) => {
		const item = event.currentTarget;
		if (item instanceof HTMLElement) delete item.dataset.dragging;
	};
	renumber() {
		const order = [];
		this.querySelectorAll("[data-menu-item]").forEach((item, index) => {
			const identifier = item.dataset.menuItem;
			if (identifier) order.push(identifier);
			const position = item.querySelector("[data-position-input]");
			if (position) position.value = String(index);
		});
		const orderInput = this.querySelector("[data-order-input]");
		if (orderInput) orderInput.value = order.join(",");
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweMenuTree = __decorate([t("kumwe-menu-tree")], KumweMenuTree);
//#endregion
//#region assets/administrator/components/schema-form.ts
var KumweSchemaForm = class KumweSchemaForm extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelectorAll("textarea[maxlength]").forEach((textarea) => {
			const output = document.createElement("output");
			output.className = "character-count";
			output.setAttribute("aria-live", "polite");
			const update = () => {
				output.value = `${textarea.value.length} / ${textarea.maxLength}`;
			};
			textarea.insertAdjacentElement("afterend", output);
			textarea.addEventListener("input", update);
			update();
		});
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweSchemaForm = __decorate([t("kumwe-schema-form")], KumweSchemaForm);
//#endregion
//#region assets/administrator/components/media-picker.ts
var KumweMediaPicker = class KumweMediaPicker extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	target = "";
	connectedCallback() {
		super.connectedCallback();
		document.addEventListener("click", this.handleDocumentClick);
		this.addEventListener("click", this.handlePickerClick);
		this.addEventListener("input", this.handleFilter);
	}
	disconnectedCallback() {
		document.removeEventListener("click", this.handleDocumentClick);
		this.removeEventListener("click", this.handlePickerClick);
		this.removeEventListener("input", this.handleFilter);
		super.disconnectedCallback();
	}
	handleDocumentClick = (event) => {
		const trigger = event.target instanceof Element ? event.target.closest("[data-open-media-picker]") : null;
		if (!trigger) return;
		this.target = trigger.dataset.mediaTarget ?? "";
		this.querySelector("dialog")?.showModal();
	};
	handlePickerClick = (event) => {
		const choice = event.target instanceof Element ? event.target.closest("[data-select-media]") : null;
		if (!choice) return;
		const input = document.getElementById(this.target);
		if (!(input instanceof HTMLInputElement)) return;
		input.value = choice.dataset.mediaUrl ?? "";
		input.dispatchEvent(new Event("input", { bubbles: true }));
		input.dispatchEvent(new Event("change", { bubbles: true }));
		this.querySelector("dialog")?.close();
		input.focus();
	};
	handleFilter = (event) => {
		const input = event.target;
		if (!(input instanceof HTMLInputElement) || !input.matches("[data-media-filter]")) return;
		const query = input.value.trim().toLocaleLowerCase();
		this.querySelectorAll("[data-select-media]").forEach((item) => {
			item.hidden = query !== "" && !(item.dataset.mediaName ?? "").toLocaleLowerCase().includes(query);
		});
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweMediaPicker = __decorate([t("kumwe-media-picker")], KumweMediaPicker);
//#endregion
//#region assets/administrator/components/job-fields.ts
var KumweJobFields = class KumweJobFields extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("change", this.handleChange);
		this.updateFields();
	}
	disconnectedCallback() {
		this.removeEventListener("change", this.handleChange);
		super.disconnectedCallback();
	}
	handleChange = (event) => {
		if (event.target instanceof HTMLSelectElement && event.target.matches("[data-job-type]")) this.updateFields();
	};
	updateFields() {
		const type = this.querySelector("[data-job-type]")?.value ?? "";
		this.querySelectorAll("[data-job-fields]").forEach((group) => {
			const active = group.dataset.jobFields === type;
			group.hidden = !active;
			group.querySelectorAll("input, select").forEach((input) => {
				input.disabled = !active;
			});
		});
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweJobFields = __decorate([t("kumwe-job-fields")], KumweJobFields);
//#endregion
//#region assets/administrator/components/rich-text.ts
var KumweRichText = class KumweRichText extends i {
	createRenderRoot() {
		return this;
	}
	firstUpdated() {
		const source = this.querySelector("textarea");
		const editor = this.querySelector("[data-rich-text-editor]");
		if (!source || !editor) return;
		editor.innerHTML = this.toEditorHtml(source.value);
		editor.addEventListener("input", () => {
			source.value = this.toSource(editor);
			source.dispatchEvent(new Event("input", { bubbles: true }));
		});
		source.addEventListener("invalid", () => editor.focus());
		this.querySelectorAll("[data-rich-text-command]").forEach((button) => {
			button.addEventListener("click", () => {
				const command = button.dataset.richTextCommand;
				editor.focus();
				if (command === "createLink") {
					const url = window.prompt("Link URL");
					if (url) document.execCommand("createLink", false, url);
				} else if (command === "formatBlock") document.execCommand("formatBlock", false, "h2");
				else if (command) document.execCommand(command);
				editor.dispatchEvent(new Event("input", { bubbles: true }));
			});
		});
	}
	render() {
		return b`
      <div class="rich-text-shell js-only">
        <div class="rich-text-toolbar" role="toolbar" aria-label="Text formatting">
          <button type="button" data-rich-text-command="bold" aria-label="Bold"><strong>B</strong></button>
          <button type="button" data-rich-text-command="formatBlock" aria-label="Heading">Heading</button>
          <button type="button" data-rich-text-command="insertUnorderedList" aria-label="Bulleted list">List</button>
          <button type="button" data-rich-text-command="createLink" aria-label="Add link">Link</button>
        </div>
        <div class="rich-text-editor" contenteditable="true" role="textbox" aria-multiline="true"
          aria-label="Rich text editor" data-rich-text-editor></div>
        <p class="field-help">Use the toolbar for headings, emphasis, lists, and safe links.</p>
      </div>
      <slot></slot>
    `;
	}
	toEditorHtml(source) {
		const lines = source.replace(/\r\n?/g, "\n").split("\n");
		const blocks = [];
		let list = [];
		const flushList = () => {
			if (list.length === 0) return;
			blocks.push(`<ul>${list.map((item) => `<li>${this.inlineToHtml(item)}</li>`).join("")}</ul>`);
			list = [];
		};
		for (const line of lines) {
			if (line.startsWith("- ")) {
				list.push(line.slice(2));
				continue;
			}
			flushList();
			if (line.startsWith("## ")) blocks.push(`<h2>${this.inlineToHtml(line.slice(3))}</h2>`);
			else if (line.trim() === "") blocks.push("<p><br></p>");
			else blocks.push(`<p>${this.inlineToHtml(line)}</p>`);
		}
		flushList();
		return blocks.join("");
	}
	inlineToHtml(source) {
		return source.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>").replace(/\[([^\]]+)]\((https?:\/\/[^\s)]+|mailto:[^\s)]+|\/(?!\/)[^\s)]*|#[^\s)]+)\)/g, "<a href=\"$2\">$1</a>");
	}
	toSource(editor) {
		return Array.from(editor.children).map((element) => this.blockToSource(element)).filter((value, index, all) => value !== "" || index > 0 && all[index - 1] !== "").join("\n");
	}
	blockToSource(element) {
		if (element.tagName === "UL" || element.tagName === "OL") return Array.from(element.children).map((item) => `- ${this.inlineToSource(item)}`).join("\n");
		return `${/^H[1-6]$/.test(element.tagName) ? "## " : ""}${this.inlineToSource(element)}`.trimEnd();
	}
	inlineToSource(element) {
		let output = "";
		element.childNodes.forEach((node) => {
			if (node.nodeType === Node.TEXT_NODE) output += node.textContent ?? "";
			else if (node instanceof HTMLBRElement) output += "\n";
			else if (node instanceof HTMLAnchorElement) output += `[${this.inlineToSource(node)}](${node.href})`;
			else if (node instanceof HTMLElement && ["B", "STRONG"].includes(node.tagName)) output += `**${this.inlineToSource(node)}**`;
			else if (node instanceof Element) output += this.inlineToSource(node);
		});
		return output;
	}
};
KumweRichText = __decorate([t("kumwe-rich-text")], KumweRichText);
//#endregion
//#region assets/administrator/components/presentation-schemes.ts
var KumwePresentationSchemes = class KumwePresentationSchemes extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
		this.addEventListener("input", this.handleInput);
		this.synchronize();
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		this.removeEventListener("input", this.handleInput);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		if (target.closest("[data-add-presentation-scheme]")) {
			event.preventDefault();
			this.addScheme();
			return;
		}
		const remove = target.closest("[data-remove-presentation-scheme]");
		if (!remove) return;
		event.preventDefault();
		if (this.rows().length <= 1) return;
		remove.closest("[data-presentation-scheme-row]")?.remove();
		this.synchronize();
	};
	handleInput = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLInputElement)) return;
		const row = target.closest("[data-presentation-scheme-row]");
		if (!row) return;
		if (target.matches("[data-presentation-scheme-name]")) {
			const heading = row.querySelector("[data-presentation-scheme-heading]");
			if (heading) heading.textContent = target.value.trim() || "Unnamed scheme";
		}
		if (target.matches("[data-presentation-scheme-name], [data-presentation-scheme-handle]")) this.synchronize();
	};
	addScheme() {
		if (this.rows().length >= 12) return;
		const template = this.querySelector("template[data-presentation-scheme-template]");
		const container = this.querySelector("[data-presentation-scheme-rows]");
		if (!template || !container) return;
		const index = this.nextIndex();
		const fragment = template.content.cloneNode(true);
		fragment.querySelectorAll("[name], [for], [id], [value]").forEach((element) => {
			for (const attribute of [
				"name",
				"for",
				"id",
				"value"
			]) {
				const value = element.getAttribute(attribute);
				if (value?.includes("__INDEX__")) element.setAttribute(attribute, value.replaceAll("__INDEX__", String(index)));
			}
		});
		container.append(fragment);
		this.synchronize();
		this.rows().at(-1)?.querySelector("[data-presentation-scheme-name]")?.focus();
	}
	synchronize() {
		const rows = this.rows();
		rows.forEach((row) => {
			const remove = row.querySelector("[data-remove-presentation-scheme]");
			if (remove) remove.disabled = rows.length <= 1;
		});
		const add = this.querySelector("[data-add-presentation-scheme]");
		if (add) add.disabled = rows.length >= 12;
		const active = document.querySelector("[data-active-presentation-scheme]");
		if (!active) return;
		const selected = active.value;
		const schemes = rows.map((row) => ({
			handle: row.querySelector("[data-presentation-scheme-handle]")?.value.trim() ?? "",
			name: row.querySelector("[data-presentation-scheme-name]")?.value.trim() ?? ""
		})).filter((scheme) => scheme.handle !== "");
		active.replaceChildren(...schemes.map((scheme) => {
			const option = document.createElement("option");
			option.value = scheme.handle;
			option.textContent = scheme.name || scheme.handle;
			return option;
		}));
		active.value = schemes.some((scheme) => scheme.handle === selected) ? selected : schemes[0]?.handle ?? "";
	}
	rows() {
		return [...this.querySelectorAll("[data-presentation-scheme-row]")];
	}
	nextIndex() {
		const indices = [...this.querySelectorAll("input[name^=\"scheme_\"][name$=\"_handle\"]")].map((input) => /^scheme_(\d+)_handle$/.exec(input.name)?.[1]).filter((value) => value !== void 0).map(Number);
		return indices.length === 0 ? 0 : Math.max(...indices) + 1;
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumwePresentationSchemes = __decorate([t("kumwe-presentation-schemes")], KumwePresentationSchemes);
//#endregion
//#region assets/administrator/components/business-definition-editor.ts
var KumweDefinitionEditor = class extends i {
	createRenderRoot() {
		return this;
	}
	render() {
		return b`<slot></slot>`;
	}
	connectedCallback() {
		super.connectedCallback();
		this.addEventListener("click", this.handleClick);
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.handleClick);
		super.disconnectedCallback();
	}
	handleClick = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const remove = target.closest("[data-remove]");
		if (remove) {
			remove.closest("[data-row]")?.remove();
			return;
		}
		const kind = target.closest("[data-add]")?.dataset.add;
		if (!kind) return;
		const template = this.querySelector(`template[data-template="${kind}"]`);
		const rows = this.querySelector(`[data-rows="${kind}"]`);
		if (!template || !rows || rows.children.length >= this.limit(kind)) return;
		const index = this.nextIndex(kind);
		const wrapper = document.createElement("template");
		wrapper.innerHTML = template.innerHTML.replaceAll("__INDEX__", String(index));
		rows.append(wrapper.content.cloneNode(true));
		rows.lastElementChild?.querySelector("input, select, textarea")?.focus();
	};
	nextIndex(kind) {
		const names = Array.from(this.querySelectorAll(`[name^="${kind}_"]`)).map((input) => Number(input.name.match(new RegExp(`^${kind}_(\\d+)_`))?.[1] ?? -1));
		return Math.max(-1, ...names) + 1;
	}
	limit(kind) {
		return {
			field: 256,
			relationship: 128,
			view: 64,
			action: 64,
			transition: 128
		}[kind] ?? 0;
	}
};
customElements.define("kumwe-definition-editor", KumweDefinitionEditor);
//#endregion
//#region assets/administrator/components/business-surface.ts
var KumweBusinessSurface = class KumweBusinessSurface extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelector("[data-business-page-search]")?.addEventListener("input", this.filterPage);
		this.querySelectorAll("form").forEach((form) => {
			form.addEventListener("submit", this.markBusy);
		});
		this.querySelector(".business-error-summary")?.focus();
	}
	disconnectedCallback() {
		this.querySelector("[data-business-page-search]")?.removeEventListener("input", this.filterPage);
		this.querySelectorAll("form").forEach((form) => {
			form.removeEventListener("submit", this.markBusy);
		});
		super.disconnectedCallback();
	}
	filterPage = (event) => {
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement)) return;
		const term = input.value.trim().toLocaleLowerCase();
		let visible = 0;
		this.querySelectorAll("[data-business-record-row]").forEach((row) => {
			const matches = term === "" || (row.textContent ?? "").toLocaleLowerCase().includes(term);
			row.hidden = !matches;
			if (matches) visible += 1;
		});
		const empty = this.querySelector("[data-business-search-empty]");
		if (empty) empty.hidden = visible > 0;
	};
	markBusy = (event) => {
		const form = event.currentTarget;
		if (!(form instanceof HTMLFormElement)) return;
		form.setAttribute("aria-busy", "true");
		const submitter = event.submitter;
		if (submitter instanceof HTMLButtonElement) submitter.setAttribute("aria-disabled", "true");
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessSurface = __decorate([t("kumwe-business-surface")], KumweBusinessSurface);
var KumweBusinessTable = class KumweBusinessTable extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessTable = __decorate([t("kumwe-business-table")], KumweBusinessTable);
var KumweBusinessOrderedLines = class KumweBusinessOrderedLines extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.dataset.enhanced = "";
		this.addEventListener("click", this.move);
		this.addEventListener("change", this.selectionChanged);
		this.updateButtons();
	}
	disconnectedCallback() {
		this.removeEventListener("click", this.move);
		this.removeEventListener("change", this.selectionChanged);
		super.disconnectedCallback();
	}
	selectionChanged = (event) => {
		if (event.target instanceof HTMLSelectElement && event.target.matches("[data-business-order-select]")) this.sync();
	};
	move = (event) => {
		const target = event.target;
		if (!(target instanceof HTMLElement)) return;
		const button = target.closest("[data-move]");
		const item = button?.closest("[data-record-id]");
		if (!button || !item) return;
		if (button.dataset.move === "up" && item.previousElementSibling) item.previousElementSibling.before(item);
		else if (button.dataset.move === "down" && item.nextElementSibling) item.nextElementSibling.after(item);
		else return;
		this.sync();
		button.focus();
	};
	sync() {
		const selections = Array.from(this.querySelectorAll("[data-business-order-select]"));
		this.updateButtons();
		const announcement = this.querySelector("[data-business-order-status]");
		if (announcement) announcement.textContent = `Order updated: ${selections.map((selection) => selection.selectedOptions[0]?.textContent?.trim() ?? "").filter((label) => label !== "").join(", ")}.`;
	}
	updateButtons() {
		const items = Array.from(this.querySelectorAll("[data-record-id]"));
		items.forEach((item, index) => {
			const position = item.querySelector("[data-business-order-position]");
			const up = item.querySelector("[data-move=\"up\"]");
			const down = item.querySelector("[data-move=\"down\"]");
			if (position) position.textContent = `Position ${index + 1}`;
			if (up) up.disabled = index === 0;
			if (down) down.disabled = index === items.length - 1;
		});
		if (!this.querySelector("[data-business-order-status]")) {
			const status = document.createElement("p");
			status.className = "sr-only";
			status.dataset.businessOrderStatus = "";
			status.setAttribute("aria-live", "polite");
			this.append(status);
		}
	}
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessOrderedLines = __decorate([t("kumwe-business-ordered-lines")], KumweBusinessOrderedLines);
var KumweBusinessConfirmation = class KumweBusinessConfirmation extends i {
	static styles = i$3`:host { display: contents; }`;
	createRenderRoot() {
		return this;
	}
	connectedCallback() {
		super.connectedCallback();
		this.querySelector("[data-business-confirm-check]")?.addEventListener("change", this.updateConfirmation);
		this.updateConfirmation();
	}
	disconnectedCallback() {
		this.querySelector("[data-business-confirm-check]")?.removeEventListener("change", this.updateConfirmation);
		super.disconnectedCallback();
	}
	updateConfirmation = () => {
		const checkbox = this.querySelector("[data-business-confirm-check]");
		const submit = this.querySelector("[data-business-confirm-form] button[type=\"submit\"]");
		if (submit) submit.disabled = !checkbox?.checked;
	};
	render() {
		return b`<slot></slot>`;
	}
};
KumweBusinessConfirmation = __decorate([t("kumwe-business-confirmation")], KumweBusinessConfirmation);
//#endregion
//#region assets/administrator/main.ts
document.documentElement.classList.add("js");
var focusableSelector = "a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex=\"-1\"])";
function setupNavigation() {
	const shell = document.querySelector("[data-administrator-shell]");
	const toggle = document.querySelector("[data-navigation-toggle]");
	const backdrop = document.querySelector("[data-navigation-backdrop]");
	if (!shell || !toggle) return;
	const setOpen = (open) => {
		shell.toggleAttribute("data-navigation-open", open);
		toggle.setAttribute("aria-expanded", String(open));
		document.body.classList.toggle("navigation-locked", open);
		if (open) shell.querySelector(focusableSelector)?.focus();
		else toggle.focus();
	};
	toggle.addEventListener("click", () => setOpen(!shell.hasAttribute("data-navigation-open")));
	backdrop?.addEventListener("click", () => setOpen(false));
	shell.querySelectorAll(".administrator-navigation a").forEach((link) => {
		link.addEventListener("click", () => setOpen(false));
	});
	window.addEventListener("keydown", (event) => {
		if (event.key === "Escape" && shell.hasAttribute("data-navigation-open")) setOpen(false);
	});
}
function setupConfirmations() {
	document.querySelectorAll("[data-confirm]").forEach((element) => {
		element.addEventListener("click", (event) => {
			const message = element.dataset.confirm ?? "Continue with this action?";
			if (!window.confirm(message)) event.preventDefault();
		});
	});
}
function setupDismissibleNotices() {
	document.querySelectorAll("[data-dismiss-notice]").forEach((button) => {
		button.addEventListener("click", () => button.closest("[role=\"status\"], [role=\"alert\"]")?.remove());
	});
}
function setupContentTypeSelector() {
	const selector = document.querySelector("[data-content-type-selector]");
	if (!selector) return;
	selector.addEventListener("change", () => {
		const url = new URL(window.location.href);
		url.searchParams.set("content_type", selector.value);
		window.location.assign(url);
	});
}
function setupSlugSuggestion() {
	const title = document.querySelector("[data-title-input]");
	const slug = document.querySelector("[data-slug-input]");
	if (!title || !slug) return;
	let userEdited = slug.value.trim() !== "";
	slug.addEventListener("input", () => {
		userEdited = slug.value.trim() !== "";
	});
	title.addEventListener("input", () => {
		if (userEdited) return;
		slug.value = title.value.normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 160);
	});
}
function setupCopyButtons() {
	document.querySelectorAll("[data-copy-value]").forEach((button) => {
		button.addEventListener("click", async () => {
			await navigator.clipboard.writeText(button.dataset.copyValue ?? "");
			const label = button.textContent;
			button.textContent = "Copied";
			window.setTimeout(() => {
				button.textContent = label;
			}, 1600);
		});
	});
}
function setupNavigationTargets() {
	document.querySelectorAll("[data-navigation-target-form]").forEach((form) => {
		const type = form.querySelector("[data-navigation-target-type]");
		const contentField = form.querySelector("[data-navigation-content-field]");
		const urlField = form.querySelector("[data-navigation-url-field]");
		const content = form.querySelector("[data-navigation-content]");
		const url = urlField?.querySelector("input");
		const title = form.querySelector("[data-navigation-title]");
		const slug = form.querySelector("[data-navigation-slug]");
		if (!type || !contentField || !urlField || !content || !url) return;
		const sync = () => {
			const contentTarget = type.value === "content";
			contentField.hidden = type.value === "url";
			urlField.hidden = contentTarget;
			content.disabled = type.value === "url";
			url.disabled = contentTarget;
			content.required = contentTarget;
			url.required = !contentTarget;
		};
		type.addEventListener("change", sync);
		content.addEventListener("change", () => {
			const option = content.selectedOptions[0];
			if (!option || option.value === "") return;
			if (title && title.value.trim() === "") title.value = option.textContent?.split(" · ")[0]?.trim() ?? "";
			if (slug && slug.value.trim() === "") slug.value = option.dataset.slug ?? "";
		});
		sync();
	});
}
setupNavigation();
setupConfirmations();
setupDismissibleNotices();
setupContentTypeSelector();
setupSlugSuggestion();
setupCopyButtons();
setupNavigationTargets();
//#endregion
