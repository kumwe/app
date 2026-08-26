import { c as A, d as i$1, l as b, s as i, u as w } from "./reveal-validation-g1jDnck7.js";
import { t as __vitePreload } from "./administrator-BNhXpvVW.js";
//#region node_modules/@kumwe/studio-core/dist/canonical.js
var DEFAULT_MAXIMUM_DEPTH = 64;
/**
* Serialize a bounded JSON value into the canonical cross-language form the
* portability contract defines for checksums: UTF-8 JSON, object members
* sorted by Unicode code unit, arrays in semantic order, minimal ECMA-404
* string escaping, and finite numbers only. Every conforming runtime MUST
* produce byte-identical output for the same value.
*/
function canonicalStringify(value, options = {}) {
	const maximumDepth = options.maximumDepth ?? DEFAULT_MAXIMUM_DEPTH;
	if (!Number.isInteger(maximumDepth) || maximumDepth < 1) throw new RangeError("Canonical serialization depth must be a positive integer.");
	return serialize(value, maximumDepth, 0);
}
/**
* The canonical UTF-8 bytes of a value; checksums in Studio contracts are
* computed over exactly these bytes with the algorithm the referencing
* contract names (SRI-style sha256 unless stated otherwise). Encoding is
* implemented locally so the core stays free of DOM and platform globals.
*/
function canonicalUtf8Bytes(value, options = {}) {
	const text = canonicalStringify(value, options);
	const bytes = [];
	for (const character of text) {
		const codePoint = character.codePointAt(0);
		if (codePoint === void 0) break;
		if (codePoint <= 127) bytes.push(codePoint);
		else if (codePoint <= 2047) bytes.push(192 | codePoint >> 6, 128 | codePoint & 63);
		else if (codePoint <= 65535) bytes.push(224 | codePoint >> 12, 128 | codePoint >> 6 & 63, 128 | codePoint & 63);
		else bytes.push(240 | codePoint >> 18, 128 | codePoint >> 12 & 63, 128 | codePoint >> 6 & 63, 128 | codePoint & 63);
	}
	return Uint8Array.from(bytes);
}
function serialize(value, maximumDepth, depth) {
	if (value === null) return "null";
	switch (typeof value) {
		case "boolean": return value ? "true" : "false";
		case "number":
			if (!Number.isFinite(value)) throw new TypeError("Canonical JSON cannot represent a non-finite number.");
			return JSON.stringify(Object.is(value, -0) ? 0 : value);
		case "string": return JSON.stringify(value);
		case "object": break;
		default: throw new TypeError(`Canonical JSON cannot represent a ${typeof value} value.`);
	}
	if (depth >= maximumDepth) throw new RangeError(`Canonical serialization exceeds the depth limit of ${maximumDepth}.`);
	if (Array.isArray(value)) return `[${value.map((item) => {
		if (item === void 0) throw new TypeError("Canonical JSON arrays cannot contain undefined entries.");
		return serialize(item, maximumDepth, depth + 1);
	}).join(",")}]`;
	const prototype = Object.getPrototypeOf(value);
	if (prototype !== Object.prototype && prototype !== null) throw new TypeError("Canonical JSON only serializes plain objects and arrays.");
	const members = Object.keys(value).sort(compareCodeUnits$5);
	const parts = [];
	for (const member of members) {
		if (member === "__proto__" || member === "prototype" || member === "constructor") throw new TypeError(`Canonical JSON forbids the object member name ${member}.`);
		const memberValue = value[member];
		if (memberValue === void 0) continue;
		parts.push(`${JSON.stringify(member)}:${serialize(memberValue, maximumDepth, depth + 1)}`);
	}
	return `{${parts.join(",")}}`;
}
function compareCodeUnits$5(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/clone.js
function cloneContractValue(value) {
	return JSON.parse(JSON.stringify(value));
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/binding-projection.js
/**
* Projects the active model into field-binding affordances and diagnostics.
*
* The function never changes a model, Blueprint, block definition, workflow,
* translation, or permission value. It accepts only already-authorized model
* projections and returns detached JSON-compatible snapshots. Field IDs, not
* labels or storage names, are the only binding coordinates.
*/
function projectBlueprintFieldBindings(blueprint, model, definitions) {
	const documentSnapshot = cloneContractValue(blueprint);
	const modelSnapshot = cloneContractValue(model);
	const definitionSnapshots = cloneContractValue(definitions);
	const diagnostics = [];
	const modelReference = cloneContractValue(documentSnapshot.model);
	const modelCompatible = appendModelCoordinateDiagnostics(documentSnapshot, modelSnapshot, diagnostics);
	const indexedDefinitions = /* @__PURE__ */ new Map();
	for (const definition of definitionSnapshots) indexedDefinitions.set(blockKey$1(definition.type, definition.version), definition);
	const context = {
		blueprintId: documentSnapshot.id,
		definitions: indexedDefinitions,
		diagnostics,
		fields: modelCompatible ? flattenModelFields(modelSnapshot.fields) : [],
		modelCompatible,
		modelReference
	};
	const nodes = [];
	visitNodes(documentSnapshot.roots, (node) => {
		nodes.push(projectNode(node, context));
	});
	return cloneContractValue({
		diagnostics,
		model: modelReference,
		nodes
	});
}
function appendModelCoordinateDiagnostics(blueprint, model, diagnostics) {
	let compatible = true;
	for (const mismatch of [
		{
			actual: model.id,
			code: "studio.binding/model-id-mismatch",
			expected: blueprint.model.id,
			member: "id"
		},
		{
			actual: model.version,
			code: "studio.binding/model-version-mismatch",
			expected: blueprint.model.version,
			member: "version"
		},
		{
			actual: model.revision,
			code: "studio.binding/model-revision-mismatch",
			expected: blueprint.model.revision,
			member: "revision"
		}
	]) {
		if (mismatch.actual === mismatch.expected) continue;
		compatible = false;
		diagnostics.push(diagnostic$1(mismatch.code, `The projected model ${mismatch.member} {actual} does not match the Blueprint lock {expected}.`, "error", {
			actual: mismatch.actual,
			expected: mismatch.expected,
			member: mismatch.member
		}, { artifactId: blueprint.id }));
	}
	return compatible;
}
function projectNode(node, context) {
	const declaredPorts = context.definitions.get(blockKey$1(node.type, node.version))?.ports ?? [];
	const declaredIds = new Set(declaredPorts.map((port) => port.id));
	const preservedPortIds = Object.keys(node.bindings).filter((port) => !declaredIds.has(port)).sort(compareCodeUnits$4);
	const ports = [...declaredPorts.map((port) => projectPort(node, port, context)), ...preservedPortIds.map((port) => projectMissingPort(node, port, context))];
	return {
		nodeId: node.id,
		ports
	};
}
function projectPort(node, port, context) {
	const candidates = context.modelCompatible ? context.fields.filter((candidate) => candidate.field.authoring?.hidden !== true && fieldMatchesPort(candidate.field, port)).map(({ field, fieldPath }) => candidateProjection(field, fieldPath)) : [];
	const binding = node.bindings[port.id];
	if (binding === void 0) {
		if (port.required) context.diagnostics.push(diagnostic$1("studio.binding/required-port-unbound", "Required block port {port} is not bound to a source.", "warning", { port: port.id }, {
			artifactId: context.blueprintId,
			nodeId: node.id
		}));
		return {
			candidates,
			multiple: port.multiple,
			port: port.id,
			required: port.required,
			status: "unbound",
			valueType: port.valueType
		};
	}
	if (binding.source.kind !== "entry-field") return {
		binding: cloneContractValue(binding),
		candidates,
		multiple: port.multiple,
		port: port.id,
		required: port.required,
		status: "non-field-source",
		valueType: port.valueType
	};
	const fieldPath = [...binding.source.fieldPath];
	if (!context.modelCompatible) return invalidProjection(port, binding, fieldPath, candidates);
	const resolution = resolveFieldPath(context.fields, fieldPath);
	if (resolution === void 0) {
		context.diagnostics.push(diagnostic$1("studio.binding/field-missing", "Binding port {port} addresses field path {fieldPath}, which the locked model no longer declares.", "error", {
			fieldPath: fieldPath.join("."),
			port: port.id
		}, {
			artifactId: context.blueprintId,
			fieldPath,
			nodeId: node.id
		}));
		return invalidProjection(port, binding, fieldPath, candidates);
	}
	if (fieldIsMultiple(resolution.field) !== port.multiple) {
		context.diagnostics.push(diagnostic$1("studio.binding/field-cardinality-incompatible", "Binding port {port} and field {fieldPath} no longer have compatible cardinality.", "error", {
			fieldPath: fieldPath.join("."),
			port: port.id
		}, {
			artifactId: context.blueprintId,
			fieldPath,
			nodeId: node.id
		}));
		return invalidProjection(port, binding, fieldPath, candidates);
	}
	if (!fieldKindMatchesValueType(resolution.field, port.valueType)) {
		context.diagnostics.push(diagnostic$1("studio.binding/field-kind-incompatible", "Binding port {port} expects {valueType}, but field {fieldPath} now projects as {fieldKind}.", "error", {
			fieldKind: effectiveFieldKind(resolution.field),
			fieldPath: fieldPath.join("."),
			port: port.id,
			valueType: port.valueType
		}, {
			artifactId: context.blueprintId,
			fieldPath,
			nodeId: node.id
		}));
		return invalidProjection(port, binding, fieldPath, candidates);
	}
	return {
		binding: cloneContractValue(binding),
		boundFieldPath: fieldPath,
		candidates,
		multiple: port.multiple,
		port: port.id,
		required: port.required,
		status: "resolved",
		valueType: port.valueType
	};
}
function projectMissingPort(node, port, context) {
	const binding = node.bindings[port];
	if (binding === void 0) return {
		candidates: [],
		port,
		status: "invalid"
	};
	context.diagnostics.push(diagnostic$1("studio.binding/port-missing", "Binding port {port} is not declared by the locked block definition.", "error", { port }, {
		artifactId: context.blueprintId,
		nodeId: node.id
	}));
	return {
		binding: cloneContractValue(binding),
		...binding.source.kind === "entry-field" ? { boundFieldPath: [...binding.source.fieldPath] } : {},
		candidates: [],
		port,
		status: "invalid"
	};
}
function invalidProjection(port, binding, fieldPath, candidates) {
	return {
		binding: cloneContractValue(binding),
		boundFieldPath: [...fieldPath],
		candidates,
		multiple: port.multiple,
		port: port.id,
		required: port.required,
		status: "invalid",
		valueType: port.valueType
	};
}
function candidateProjection(field, fieldPath) {
	return {
		cardinality: field.cardinality,
		...field.authoring?.control === void 0 ? {} : { control: field.authoring.control },
		fieldPath: [...fieldPath],
		...field.itemKind === void 0 ? {} : { itemKind: field.itemKind },
		kind: field.kind,
		label: cloneContractValue(field.label)
	};
}
function flattenModelFields(fields) {
	const flattened = [];
	const visit = (siblings, prefix) => {
		const ordered = siblings.map((field, index) => ({
			field,
			index
		})).sort((left, right) => (left.field.authoring?.order ?? Number.MAX_SAFE_INTEGER) - (right.field.authoring?.order ?? Number.MAX_SAFE_INTEGER) || left.index - right.index);
		for (const { field } of ordered) {
			const fieldPath = [...prefix, field.id];
			flattened.push({
				field,
				fieldPath
			});
			if (field.kind === "object" && field.cardinality === "one" && field.fields !== void 0) visit(field.fields, fieldPath);
		}
	};
	visit(fields, []);
	return flattened;
}
function resolveFieldPath(fields, fieldPath) {
	return fields.find((candidate) => samePath(candidate.fieldPath, fieldPath));
}
function fieldMatchesPort(field, port) {
	return fieldIsMultiple(field) === port.multiple && fieldKindMatchesValueType(field, port.valueType);
}
function fieldIsMultiple(field) {
	return field.cardinality === "many";
}
function fieldKindMatchesValueType(field, valueType) {
	const kind = effectiveFieldKind(field);
	if (kind === valueType) return true;
	if (valueType === "text") return kind === "string" || kind === "enum";
	if (valueType === "number") return kind === "decimal" || kind === "integer";
	return false;
}
function effectiveFieldKind(field) {
	return field.kind === "collection" ? field.itemKind ?? "object" : field.kind;
}
function visitNodes(nodes, visit) {
	for (const node of nodes) {
		visit(node);
		for (const slot of Object.keys(node.slots).sort(compareCodeUnits$4)) visitNodes(node.slots[slot] ?? [], visit);
	}
}
function diagnostic$1(code, defaultMessage, severity, parameters, location) {
	return {
		code,
		...location === void 0 ? {} : { location },
		message: {
			defaultMessage,
			key: code
		},
		...parameters === void 0 ? {} : { parameters },
		severity
	};
}
function blockKey$1(type, version) {
	return `${type}@${version}`;
}
function samePath(left, right) {
	return left.length === right.length && left.every((value, index) => value === right[index]);
}
function compareCodeUnits$4(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/commands.js
var StudioCommandError = class extends Error {
	code;
	constructor(code, message) {
		super(message);
		this.name = "StudioCommandError";
		this.code = code;
	}
};
function applyCommand(document, command) {
	if (command.artifactId !== document.id) throw new StudioCommandError("node-not-found", `Command targets ${command.artifactId}, not Blueprint ${document.id}.`);
	const next = cloneContractValue(document);
	if (command.type === "studio.command/batch") for (const operation of assertBatchOperations(command.payload.operations)) applyOperation(next, operation);
	else if (command.type === "studio.command/apply-pattern") applyPattern(next, command.payload);
	else if (command.type === "studio.command/reset-inherited-property") resetInheritedProperty(next, command.payload);
	else applyOperation(next, command);
	return next;
}
function assertBatchOperations(operations) {
	if (operations.length === 0 || operations.length > 100) throw new StudioCommandError("invalid-batch", `A batch must contain between 1 and 100 operations, not ${operations.length}.`);
	for (const operation of operations) {
		const type = operation.type;
		if (type === "studio.command/batch" || type === "studio.command/apply-pattern" || type === "studio.command/reset-inherited-property") throw new StudioCommandError("invalid-batch", `A batch cannot contain a ${type.slice(type.indexOf("/") + 1)} operation.`);
	}
	return operations;
}
/**
* Applies one batchable operation to a document in place. Exported for the
* package-internal hybrid-bound trial evaluation in `modes.ts`; the public
* reducer surface remains `applyCommand`.
*/
function applyOperation(document, operation) {
	switch (operation.type) {
		case "studio.command/insert-node":
		case "studio.command/restore-node":
			assertSubtreeIdsAbsent(document, operation.payload.node);
			insertAt(resolveTargetCollection(document, operation.payload.destination), operation.payload.destination.position, cloneContractValue(operation.payload.node));
			break;
		case "studio.command/remove-node": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			location.collection.splice(location.index, 1);
			dropEmptySlotCollection(document, location);
			break;
		}
		case "studio.command/move-node": {
			const source = findNode$1(document.roots, operation.payload.nodeId);
			if (source === void 0) throw nodeNotFound(operation.payload.nodeId);
			const parentNodeId = operation.payload.destination.parentNodeId;
			if (parentNodeId === operation.payload.nodeId || parentNodeId !== void 0 && findNode$1([source.node], parentNodeId) !== void 0) throw new StudioCommandError("illegal-move", "A node cannot be moved into itself.");
			const [moving] = source.collection.splice(source.index, 1);
			if (moving === void 0) throw nodeNotFound(operation.payload.nodeId);
			dropEmptySlotCollection(document, source);
			insertAt(resolveTargetCollection(document, operation.payload.destination), operation.payload.destination.position, moving);
			break;
		}
		case "studio.command/duplicate-node": {
			const source = findNode$1(document.roots, operation.payload.nodeId);
			if (source === void 0) throw nodeNotFound(operation.payload.nodeId);
			const idMap = assertCompleteIdMap(document, source.node, operation.payload.idMap);
			const copy = remapSubtree(cloneContractValue(source.node), idMap);
			if (operation.payload.destination === void 0) insertAt(source.collection, source.index + 1, copy);
			else insertAt(resolveTargetCollection(document, operation.payload.destination), operation.payload.destination.position, copy);
			break;
		}
		case "studio.command/reorder-children": {
			const collection = resolveExistingCollection(document, operation.payload.parentNodeId, operation.payload.slot);
			if (!isPermutation(collection.map((node) => node.id), operation.payload.order)) throw new StudioCommandError("invalid-order", "The requested order is not a permutation of the current children.");
			const byId = new Map(collection.map((node) => [node.id, node]));
			const reordered = operation.payload.order.map((nodeId) => {
				const node = byId.get(nodeId);
				if (node === void 0) throw new StudioCommandError("invalid-order", "The requested order is not a permutation of the current children.");
				return node;
			});
			collection.splice(0, collection.length, ...reordered);
			break;
		}
		case "studio.command/set-property": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			if (operation.payload.viewport === void 0) setOwnMapValue(location.node.properties, operation.payload.property, cloneContractValue(operation.payload.value));
			else {
				const responsive = location.node.responsive ??= {};
				let values = ownMapValue(responsive, operation.payload.property);
				if (values === void 0) {
					values = {};
					setOwnMapValue(responsive, operation.payload.property, values);
				}
				setOwnMapValue(values, operation.payload.viewport, cloneContractValue(operation.payload.value));
			}
			break;
		}
		case "studio.command/unset-property": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			if (operation.payload.viewport === void 0) {
				if (ownMapValue(location.node.properties, operation.payload.property) === void 0) throw propertyNotFound(operation.payload.nodeId, operation.payload.property);
				deleteOwnMapValue(location.node.properties, operation.payload.property);
			} else {
				const responsive = location.node.responsive;
				const values = responsive === void 0 ? void 0 : ownMapValue(responsive, operation.payload.property);
				if (responsive === void 0 || values === void 0 || ownMapValue(values, operation.payload.viewport) === void 0) throw propertyNotFound(operation.payload.nodeId, operation.payload.property, operation.payload.viewport);
				deleteOwnMapValue(values, operation.payload.viewport);
				if (Object.keys(values).length === 0) deleteOwnMapValue(responsive, operation.payload.property);
				if (Object.keys(responsive).length === 0) delete location.node.responsive;
			}
			break;
		}
		case "studio.command/set-size-role": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			if (operation.payload.viewport === void 0) setOwnMapValue(location.node.sizeRoles ??= {}, operation.payload.axis, operation.payload.role);
			else {
				const responsiveSizeRoles = location.node.responsiveSizeRoles ??= {};
				let roles = ownMapValue(responsiveSizeRoles, operation.payload.axis);
				if (roles === void 0) {
					roles = {};
					setOwnMapValue(responsiveSizeRoles, operation.payload.axis, roles);
				}
				setOwnMapValue(roles, operation.payload.viewport, operation.payload.role);
			}
			break;
		}
		case "studio.command/unset-size-role": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			if (operation.payload.viewport === void 0) {
				const sizeRoles = location.node.sizeRoles;
				if (sizeRoles === void 0 || ownMapValue(sizeRoles, operation.payload.axis) === void 0) throw sizeRoleNotFound(operation.payload.nodeId, operation.payload.axis);
				deleteOwnMapValue(sizeRoles, operation.payload.axis);
				if (Object.keys(sizeRoles).length === 0) delete location.node.sizeRoles;
			} else {
				const responsiveSizeRoles = location.node.responsiveSizeRoles;
				const roles = responsiveSizeRoles === void 0 ? void 0 : ownMapValue(responsiveSizeRoles, operation.payload.axis);
				if (responsiveSizeRoles === void 0 || roles === void 0 || ownMapValue(roles, operation.payload.viewport) === void 0) throw sizeRoleNotFound(operation.payload.nodeId, operation.payload.axis, operation.payload.viewport);
				deleteOwnMapValue(roles, operation.payload.viewport);
				if (Object.keys(roles).length === 0) deleteOwnMapValue(responsiveSizeRoles, operation.payload.axis);
				if (Object.keys(responsiveSizeRoles).length === 0) delete location.node.responsiveSizeRoles;
			}
			break;
		}
		case "studio.command/set-binding": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			setOwnMapValue(location.node.bindings, operation.payload.port, cloneContractValue(operation.payload.binding));
			break;
		}
		case "studio.command/remove-binding": {
			const location = findNode$1(document.roots, operation.payload.nodeId);
			if (location === void 0) throw nodeNotFound(operation.payload.nodeId);
			if (ownMapValue(location.node.bindings, operation.payload.port) === void 0) throw bindingNotFound(operation.payload.nodeId, operation.payload.port);
			deleteOwnMapValue(location.node.bindings, operation.payload.port);
			break;
		}
		default: assertNever(operation);
	}
}
function assertCompleteIdMap(document, source, idMap) {
	const subtreeIds = collectSubtreeIds(source);
	const provided = /* @__PURE__ */ new Map();
	for (const [from, to] of Object.entries(idMap)) provided.set(from, to);
	if (provided.size !== subtreeIds.size) throw incompleteIdMap();
	const assigned = /* @__PURE__ */ new Set();
	for (const from of subtreeIds) {
		const to = provided.get(from);
		if (to === void 0) throw incompleteIdMap();
		if (assigned.has(to)) throw new StudioCommandError("invalid-id-map", `The identifier map assigns ${to} more than once.`);
		assigned.add(to);
		if (findNode$1(document.roots, to) !== void 0) throw new StudioCommandError("duplicate-node", `Node identifier ${to} is already present.`);
	}
	return provided;
}
function incompleteIdMap() {
	return new StudioCommandError("invalid-id-map", "The identifier map must remap every node of the duplicated subtree exactly once.");
}
function collectSubtreeIds(node) {
	const identifiers = /* @__PURE__ */ new Set();
	const stack = [node];
	while (stack.length > 0) {
		const current = stack.pop();
		if (current === void 0) break;
		identifiers.add(current.id);
		for (const children of Object.values(current.slots)) stack.push(...children);
	}
	return identifiers;
}
function assertSubtreeIdsAbsent(document, node) {
	for (const nodeId of collectSubtreeIds(node)) if (findNode$1(document.roots, nodeId) !== void 0) throw new StudioCommandError("duplicate-node", `Node identifier ${nodeId} is already present.`);
}
function remapSubtree(node, idMap) {
	const stack = [node];
	while (stack.length > 0) {
		const current = stack.pop();
		if (current === void 0) break;
		const mapped = idMap.get(current.id);
		if (mapped === void 0) throw incompleteIdMap();
		current.id = mapped;
		for (const children of Object.values(current.slots)) stack.push(...children);
	}
	return node;
}
function applyPattern(document, payload) {
	const subtreeIds = /* @__PURE__ */ new Set();
	for (const node of payload.nodes) for (const nodeId of collectSubtreeIds(node)) subtreeIds.add(nodeId);
	const provided = /* @__PURE__ */ new Map();
	for (const [from, to] of Object.entries(payload.idMap)) provided.set(from, to);
	if (provided.size !== subtreeIds.size) throw incompleteIdMap();
	const assigned = /* @__PURE__ */ new Set();
	for (const from of subtreeIds) {
		const to = provided.get(from);
		if (to === void 0) throw incompleteIdMap();
		if (assigned.has(to)) throw new StudioCommandError("invalid-id-map", `The identifier map assigns ${to} more than once.`);
		assigned.add(to);
		if (findNode$1(document.roots, to) !== void 0) throw new StudioCommandError("duplicate-node", `Node identifier ${to} is already present.`);
	}
	const collection = resolveTargetCollection(document, payload.destination);
	for (const [index, node] of payload.nodes.entries()) {
		const copy = remapSubtree(cloneContractValue(node), provided);
		setOwnMapValue(copy.extensions ??= {}, "studio.pattern/source", {
			id: payload.pattern.id,
			revision: payload.pattern.revision,
			version: payload.pattern.version
		});
		insertAt(collection, payload.destination.position + index, copy);
	}
}
function resolveInheritedPropertyOverrides(document, payload) {
	const location = findNode$1(document.roots, payload.nodeId);
	if (location === void 0) throw nodeNotFound(payload.nodeId);
	const responsive = location.node.responsive;
	const values = responsive === void 0 ? void 0 : ownMapValue(responsive, payload.property);
	if (responsive === void 0 || values === void 0 || Object.keys(values).length === 0) throw new StudioCommandError("property-not-found", `Property ${payload.property} has no responsive overrides on node ${payload.nodeId}.`);
	return {
		node: location.node,
		responsive,
		values
	};
}
function resetInheritedProperty(document, payload) {
	const { node, responsive } = resolveInheritedPropertyOverrides(document, payload);
	deleteOwnMapValue(responsive, payload.property);
	if (Object.keys(responsive).length === 0) delete node.responsive;
}
function dropEmptySlotCollection(document, location) {
	if (location.collection.length > 0 || location.parentNodeId === void 0 || location.slot === void 0) return;
	const parent = findNode$1(document.roots, location.parentNodeId)?.node;
	if (parent !== void 0 && ownMapValue(parent.slots, location.slot) === location.collection) deleteOwnMapValue(parent.slots, location.slot);
}
function isPermutation(current, requested) {
	if (current.length !== requested.length) return false;
	const remaining = /* @__PURE__ */ new Map();
	for (const nodeId of current) remaining.set(nodeId, (remaining.get(nodeId) ?? 0) + 1);
	for (const nodeId of requested) {
		const count = remaining.get(nodeId);
		if (count === void 0 || count === 0) return false;
		remaining.set(nodeId, count - 1);
	}
	return true;
}
function resolveExistingCollection(document, parentNodeId, slot) {
	if (parentNodeId === void 0) return document.roots;
	if (slot === void 0) throw new StudioCommandError("parent-not-found", "A parent destination requires a named slot.");
	const parent = findNode$1(document.roots, parentNodeId)?.node;
	if (parent === void 0) throw new StudioCommandError("parent-not-found", `Parent node ${parentNodeId} was not found.`);
	const collection = ownMapValue(parent.slots, slot);
	if (collection === void 0) throw new StudioCommandError("invalid-order", `Slot ${slot} on node ${parentNodeId} has no children to reorder.`);
	return collection;
}
function assertNever(value) {
	throw new StudioCommandError("unsupported-command", `Unsupported Blueprint command type: ${safeCommandType(value)}.`);
}
function safeCommandType(value) {
	if (typeof value === "object" && value !== null && "type" in value && typeof value.type === "string" && value.type.length <= 160 && /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u.test(value.type)) return value.type;
	return "unknown";
}
function resolveTargetCollection(document, destination) {
	if (destination.parentNodeId === void 0) return document.roots;
	if (destination.slot === void 0) throw new StudioCommandError("parent-not-found", "A parent destination requires a named slot.");
	const parent = findNode$1(document.roots, destination.parentNodeId)?.node;
	if (parent === void 0) throw new StudioCommandError("parent-not-found", `Parent node ${destination.parentNodeId} was not found.`);
	let collection = ownMapValue(parent.slots, destination.slot);
	if (collection === void 0) {
		collection = [];
		setOwnMapValue(parent.slots, destination.slot, collection);
	}
	return collection;
}
function ownMapValue(map, key) {
	return Object.hasOwn(map, key) ? map[key] : void 0;
}
function setOwnMapValue(map, key, value) {
	Object.defineProperty(map, key, {
		configurable: true,
		enumerable: true,
		value,
		writable: true
	});
}
function deleteOwnMapValue(map, key) {
	if (Object.hasOwn(map, key)) delete map[key];
}
function insertAt(collection, index, node) {
	if (!Number.isInteger(index) || index < 0 || index > collection.length) throw new StudioCommandError("invalid-index", `Insertion index ${index} is outside the slot.`);
	collection.splice(index, 0, node);
}
function findNode$1(nodes, nodeId, parentNodeId, slot) {
	for (const [index, node] of nodes.entries()) {
		if (node.id === nodeId) {
			const location = {
				collection: nodes,
				index,
				node
			};
			if (parentNodeId !== void 0 && slot !== void 0) {
				location.parentNodeId = parentNodeId;
				location.slot = slot;
			}
			return location;
		}
		for (const [slotName, children] of Object.entries(node.slots)) {
			const nested = findNode$1(children, nodeId, node.id, slotName);
			if (nested !== void 0) return nested;
		}
	}
}
function nodeNotFound(nodeId) {
	return new StudioCommandError("node-not-found", `Node ${nodeId} was not found.`);
}
function propertyNotFound(nodeId, property, viewport) {
	return new StudioCommandError("property-not-found", `Property ${viewport === void 0 ? property : `${property} for viewport ${viewport}`} is not set on node ${nodeId}.`);
}
function sizeRoleNotFound(nodeId, axis, viewport) {
	return new StudioCommandError("property-not-found", `No size role is set on node ${nodeId} for ${viewport === void 0 ? `axis ${axis}` : `axis ${axis} for viewport ${viewport}`}.`);
}
function bindingNotFound(nodeId, port) {
	return new StudioCommandError("binding-not-found", `Binding ${port} is not present on node ${nodeId}.`);
}
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/types.js
var STUDIO_CONTRACT_VERSION = "0.1-draft";
var STUDIO_WIRE_PROTOCOL_VERSION = "0.1.0-draft.2";
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/guards.js
var qualifiedName = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u;
var localName = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u;
var previewDigest = /^[a-f0-9]{64}$/u;
var previewMarker = /^studio\.preview\/node\/([a-f0-9]{64})\/(0|[1-9][0-9]{0,4})$/u;
var hostErrorCategories = /* @__PURE__ */ new Set([
	"cancelled",
	"conflict",
	"forbidden",
	"incompatible",
	"internal",
	"invalid-request",
	"limit-exceeded",
	"not-found",
	"rate-limited",
	"unauthenticated",
	"unavailable",
	"validation-failed"
]);
function isHostPortError(value) {
	return isRecord$6(value) && hasExactKeys$1(value, [
		"contractVersion",
		"kind",
		"category",
		"message",
		"retryable"
	], [
		"correlationId",
		"diagnostics",
		"retryAfterMilliseconds",
		"revision"
	]) && value.contractVersion === "0.1-draft" && value.kind === "host-error" && typeof value.category === "string" && hostErrorCategories.has(value.category) && isMessageReference$2(value.message) && typeof value.retryable === "boolean" && (value.correlationId === void 0 || isStableId$2(value.correlationId)) && (value.revision === void 0 || isRevision$1(value.revision)) && (value.retryAfterMilliseconds === void 0 || isNonNegativeInteger(value.retryAfterMilliseconds) && value.retryAfterMilliseconds <= 864e5) && (value.diagnostics === void 0 || isArrayOf(value.diagnostics, isDiagnostic, 1e3));
}
function isPreviewMessage(value) {
	if (!isRecord$6(value) || !hasExactKeys$1(value, [
		"contractVersion",
		"kind",
		"channelId",
		"sessionGeneration",
		"sequence",
		"type",
		"payload"
	]) || value.contractVersion !== "0.1-draft" || value.kind !== "preview-message" || !isStableId$2(value.channelId) || !isRevision$1(value.sessionGeneration) || !isNonNegativeInteger(value.sequence) || typeof value.type !== "string" || !isRecord$6(value.payload)) return false;
	switch (value.type) {
		case "studio.preview/ready": return isReadyPayload(value.payload);
		case "studio.preview/render": return isRenderPayload(value.payload);
		case "studio.preview/rendered": return isPreviewRenderedPayload(value.payload);
		case "studio.preview/select": return isSelectPayload(value.payload);
		case "studio.preview/measure": return isMeasurePayload(value.payload);
		case "studio.preview/measurements": return isMeasurementsPayload(value.payload);
		case "studio.preview/error": return isErrorPayload(value.payload);
		case "studio.preview/reload":
		case "studio.preview/teardown": return isReasonPayload(value.payload);
		case "studio.preview/activated": return isActivatedPayload(value.payload);
		case "studio.preview/viewport": return isViewportPayload(value.payload);
		case "studio.preview/dispose": return isDisposePayload(value.payload);
		default: return false;
	}
}
function isActivatedPayload(value) {
	return hasExactKeys$1(value, ["interaction", "marker"]) && isPreviewMarker(value.marker) && (value.interaction === "activate" || value.interaction === "context-menu" || value.interaction === "focus");
}
/** A semantic role and explicit dimensions are alternatives, never a merge. */
function isViewportPayload(value) {
	const keys = Object.keys(value);
	if (keys.length === 0 || keys.some((key) => ![
		"height",
		"viewport",
		"width"
	].includes(key))) return false;
	const hasRole = Object.hasOwn(value, "viewport");
	const hasWidth = Object.hasOwn(value, "width");
	const hasHeight = Object.hasOwn(value, "height");
	if (hasRole === (hasWidth || hasHeight)) return false;
	if (hasRole) return isLocalName(value.viewport);
	return (!hasWidth || isBoundedDimension(value.width)) && (!hasHeight || isBoundedDimension(value.height));
}
function isBoundedDimension(value) {
	return typeof value === "number" && Number.isSafeInteger(value) && value >= 240 && value <= 1e4;
}
function isDisposePayload(value) {
	if (!isQualifiedName$2(value.reason)) return false;
	if (Object.keys(value).some((key) => key !== "draftDigest" && key !== "reason")) return false;
	return value.draftDigest === void 0 || typeof value.draftDigest === "string" && previewDigest.test(value.draftDigest);
}
function isReasonPayload(value) {
	return hasExactKeys$1(value, ["reason"]) && isQualifiedName$2(value.reason);
}
function isReadyPayload(value) {
	return hasExactKeys$1(value, [
		"protocolVersion",
		"renderer",
		"viewports"
	]) && value.protocolVersion === "0.1.0-draft.2" && isQualifiedName$2(value.renderer) && isStringArray(value.viewports, isLocalName, 20);
}
function isRenderPayload(value) {
	return hasExactKeys$1(value, [
		"artifactId",
		"draftDigest",
		"draftRevision",
		"requestId",
		"viewport"
	]) && isStableId$2(value.artifactId) && typeof value.draftDigest === "string" && previewDigest.test(value.draftDigest) && isRevision$1(value.draftRevision) && isStableId$2(value.requestId) && isLocalName(value.viewport);
}
function isPreviewRenderedPayload(value) {
	if (!isRecord$6(value) || !hasExactKeys$1(value, [
		"requestId",
		"draftDigest",
		"markers",
		"markerMap",
		"diagnostics"
	]) || !isStableId$2(value.requestId) || typeof value.draftDigest !== "string" || !previewDigest.test(value.draftDigest) || !isStringArray(value.markers, isPreviewMarker, 1e5) || new Set(value.markers).size !== value.markers.length || !isArrayOf(value.diagnostics, isDiagnostic, 1e4) || !isMarkerMap(value.markerMap)) return false;
	const markerMap = value.markerMap;
	if (Object.keys(markerMap).length !== value.markers.length) return false;
	const nodeIds = Object.values(markerMap);
	if (new Set(nodeIds).size !== nodeIds.length) return false;
	return value.markers.every((marker, ordinal) => {
		const match = previewMarker.exec(marker);
		return match !== null && match[1] === value.draftDigest && Number(match[2]) === ordinal && Object.hasOwn(markerMap, marker);
	});
}
function isMarkerMap(value) {
	if (!isRecord$6(value)) return false;
	const entries = Object.entries(value);
	return entries.length <= 1e5 && entries.every(([marker, nodeId]) => isPreviewMarker(marker) && isStableId$2(nodeId));
}
function isMeasurePayload(value) {
	return hasExactKeys$1(value, ["requestId", "markers"]) && isStableId$2(value.requestId) && isStringArray(value.markers, isPreviewMarker, 1e3) && value.markers.length >= 1 && new Set(value.markers).size === value.markers.length;
}
function isMeasurementsPayload(value) {
	if (!hasExactKeys$1(value, [
		"requestId",
		"draftDigest",
		"measurements",
		"unknown",
		"viewport"
	]) || !isStableId$2(value.requestId) || typeof value.draftDigest !== "string" || !previewDigest.test(value.draftDigest) || !isMeasurementMap(value.measurements) || !isStringArray(value.unknown, isPreviewMarker, 1e3) || new Set(value.unknown).size !== value.unknown.length || !isViewportMetrics(value.viewport)) return false;
	const markers = [...Object.keys(value.measurements), ...value.unknown];
	return new Set(markers).size === markers.length && markers.every((marker) => previewMarker.exec(marker)?.[1] === value.draftDigest);
}
function isMeasurementMap(value) {
	if (!isRecord$6(value)) return false;
	const entries = Object.entries(value);
	return entries.length <= 1e3 && entries.every(([marker, rects]) => isPreviewMarker(marker) && isArrayOf(rects, isMarkerRect, 1e3) && rects.length >= 1);
}
function isMarkerRect(value) {
	return isRecord$6(value) && hasExactKeys$1(value, [
		"x",
		"y",
		"width",
		"height"
	]) && isCssCoordinate(value.x) && isCssCoordinate(value.y) && isCssExtent(value.width) && isCssExtent(value.height);
}
function isViewportMetrics(value) {
	return isRecord$6(value) && hasExactKeys$1(value, [
		"width",
		"height",
		"scrollX",
		"scrollY",
		"devicePixelRatio"
	]) && isCssExtent(value.width) && isCssExtent(value.height) && isCssCoordinate(value.scrollX) && isCssCoordinate(value.scrollY) && typeof value.devicePixelRatio === "number" && Number.isFinite(value.devicePixelRatio) && value.devicePixelRatio > 0 && value.devicePixelRatio <= 100;
}
function isSelectPayload(value) {
	return hasExactKeys$1(value, ["nodeId"], ["reveal"]) && isStableId$2(value.nodeId) && (value.reveal === void 0 || typeof value.reveal === "boolean");
}
function isErrorPayload(value) {
	return hasExactKeys$1(value, [
		"code",
		"message",
		"retryable"
	], ["correlationId"]) && isQualifiedName$2(value.code) && isMessageReference$2(value.message) && typeof value.retryable === "boolean" && (value.correlationId === void 0 || isStableId$2(value.correlationId));
}
function isDiagnostic(value) {
	if (!isRecord$6(value) || !hasExactKeys$1(value, [
		"code",
		"severity",
		"message"
	], [
		"location",
		"parameters",
		"remediations"
	]) || !isQualifiedName$2(value.code) || typeof value.severity !== "string" || ![
		"information",
		"warning",
		"error",
		"blocking"
	].includes(value.severity) || !isMessageReference$2(value.message)) return false;
	if (value.location !== void 0 && !isDiagnosticLocation(value.location)) return false;
	if (value.parameters !== void 0 && (!isRecord$6(value.parameters) || Object.keys(value.parameters).length > 20 || !Object.keys(value.parameters).every((key) => isSafeJsonMemberName$1(key)) || !Object.values(value.parameters).every((entry) => entry === null || typeof entry === "boolean" || typeof entry === "string" || typeof entry === "number" && Number.isFinite(entry)))) return false;
	return value.remediations === void 0 || isStringArray(value.remediations, isQualifiedName$2, 10);
}
function isDiagnosticLocation(value) {
	if (!isRecord$6(value) || !hasExactKeys$1(value, [], [
		"artifactId",
		"nodeId",
		"fieldPath",
		"jsonPointer"
	])) return false;
	return (value.artifactId === void 0 || isStableId$2(value.artifactId)) && (value.nodeId === void 0 || isStableId$2(value.nodeId)) && (value.fieldPath === void 0 || isStringArray(value.fieldPath, isLocalName, 32)) && (value.jsonPointer === void 0 || typeof value.jsonPointer === "string" && value.jsonPointer.length <= 1e3);
}
function isMessageReference$2(value) {
	return isRecord$6(value) && hasExactKeys$1(value, ["key"], ["defaultMessage"]) && isQualifiedName$2(value.key) && (value.defaultMessage === void 0 || typeof value.defaultMessage === "string" && value.defaultMessage.length > 0 && value.defaultMessage.length <= 500);
}
function hasExactKeys$1(value, required, optional = []) {
	const allowed = /* @__PURE__ */ new Set([...required, ...optional]);
	return required.every((key) => Object.hasOwn(value, key)) && Object.keys(value).every((key) => allowed.has(key));
}
function isRecord$6(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}
function isNonNegativeInteger(value) {
	return typeof value === "number" && Number.isSafeInteger(value) && value >= 0;
}
var cssPixelLimit = 1e8;
function isCssCoordinate(value) {
	return typeof value === "number" && Number.isFinite(value) && Math.abs(value) <= cssPixelLimit;
}
function isCssExtent(value) {
	return typeof value === "number" && Number.isFinite(value) && value >= 0 && value <= cssPixelLimit;
}
function isQualifiedName$2(value) {
	return typeof value === "string" && value.length <= 160 && qualifiedName.test(value);
}
function isLocalName(value) {
	return typeof value === "string" && value.length <= 100 && !isForbiddenObjectMemberName(value) && localName.test(value);
}
function isStableId$2(value) {
	return typeof value === "string" && value.length <= 240 && !isForbiddenObjectMemberName(value) && /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u.test(value);
}
/** Whether a value has the canonical preview marker grammar, optionally for one draft. */
function isPreviewMarker(value, draftDigest) {
	if (typeof value !== "string") return false;
	const match = previewMarker.exec(value);
	return match !== null && (draftDigest === void 0 || match[1] === draftDigest);
}
function isRevision$1(value) {
	return typeof value === "string" && value.length >= 1 && value.length <= 200;
}
function isStringArray(value, predicate, maximumItems) {
	return isArrayOf(value, predicate, maximumItems);
}
function isArrayOf(value, predicate, maximumItems) {
	if (!Array.isArray(value) || value.length > maximumItems || !isDenseArray$2(value)) return false;
	for (const entry of value) if (!predicate(entry)) return false;
	return true;
}
function isDenseArray$2(value) {
	if (Object.getPrototypeOf(value) !== Array.prototype || Object.getOwnPropertySymbols(value).length) return false;
	const names = Object.getOwnPropertyNames(value);
	return names.length === value.length + 1 && names[value.length] === "length" && names.slice(0, -1).every((name, index) => name === String(index));
}
function isSafeJsonMemberName$1(value) {
	if (value.length === 0 || value.length > 200 || isForbiddenObjectMemberName(value)) return false;
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code <= 31 || code === 127) return false;
	}
	return true;
}
function isForbiddenObjectMemberName(value) {
	return value === "__proto__" || value === "prototype" || value === "constructor";
}
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/host-failure.js
/**
* The only rejection wrapper a typed host port exposes to Studio callers.
*
* Keeping the serializable `HostPortError` under one public `error` member
* prevents transports and adapters from leaking implementation exceptions,
* stack traces, response objects, or other host-private state across the
* authority boundary.
*/
var HostPortFailure = class extends Error {
	error;
	constructor(error) {
		if (!isHostPortError(error)) throw new TypeError("HostPortFailure requires a canonical HostPortError.");
		super(error.message.defaultMessage ?? error.message.key);
		this.name = "HostPortFailure";
		this.error = error;
	}
};
/** Whether an unknown rejection is the public typed host-port wrapper. */
function isHostPortFailure(value) {
	return value instanceof Error && "error" in value && isHostPortError(value.error);
}
var authoring_message_catalog_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json",
	title: "Studio authoring message catalog",
	type: "object",
	additionalProperties: false,
	required: [
		"$schema",
		"kind",
		"contractVersion",
		"catalogVersion",
		"locale",
		"messages"
	],
	properties: {
		"$schema": { "const": "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json" },
		"kind": { "const": "authoring-message-catalog" },
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"catalogVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"locale": { "$ref": "common.schema.json#/$defs/locale" },
		"messages": {
			"type": "object",
			"minProperties": 1,
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/qualifiedName" },
			"additionalProperties": { "$ref": "#/$defs/message" }
		}
	},
	$defs: { "message": {
		"type": "object",
		"additionalProperties": false,
		"required": ["defaultMessage", "parameters"],
		"properties": {
			"defaultMessage": {
				"type": "string",
				"minLength": 1,
				"maxLength": 2e3,
				"pattern": "^[^<>]*(?![\\s\\S])"
			},
			"parameters": {
				"type": "array",
				"maxItems": 50,
				"uniqueItems": true,
				"items": { "$ref": "common.schema.json#/$defs/localName" }
			}
		}
	} }
};
var block_definition_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/block-definition.schema.json",
	title: "Studio block definition",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"type",
		"version",
		"revision",
		"owner",
		"label",
		"category",
		"propertySchema",
		"slots",
		"ports",
		"editingModes",
		"themeControls",
		"rendererRequirements",
		"accessibility"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "block-definition" },
		"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"category": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"icon": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["kind", "value"],
			"properties": {
				"kind": { "const": "symbol" },
				"value": { "oneOf": [{ "$ref": "common.schema.json#/$defs/localName" }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] }
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"path",
				"integrity"
			],
			"properties": {
				"kind": { "const": "asset" },
				"path": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
				"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		}] },
		"propertySchema": {
			"type": "object",
			"minProperties": 1,
			"maxProperties": 500,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
		},
		"propertyControls": {
			"type": "array",
			"maxItems": 500,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["property", "control"],
				"properties": {
					"property": { "$ref": "common.schema.json#/$defs/localName" },
					"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"help": { "$ref": "common.schema.json#/$defs/messageReference" }
				}
			}
		},
		"slots": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"minimum",
					"maximum",
					"ordered",
					"accepts"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"minimum": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e4
					},
					"maximum": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e4
					},
					"ordered": { "type": "boolean" },
					"accepts": {
						"type": "object",
						"additionalProperties": false,
						"required": ["types"],
						"properties": { "types": {
							"type": "array",
							"minItems": 1,
							"maxItems": 1e3,
							"uniqueItems": true,
							"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
						} }
					}
				}
			}
		},
		"ports": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"valueType",
					"required",
					"multiple"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"valueType": { "oneOf": [{ "enum": [
						"text",
						"rich-text",
						"boolean",
						"integer",
						"decimal",
						"money",
						"date",
						"date-time",
						"media",
						"resource"
					] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
					"required": { "type": "boolean" },
					"multiple": { "type": "boolean" },
					"authoring": {
						"type": "object",
						"additionalProperties": false,
						"properties": {
							"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"profile": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"readOnly": { "type": "boolean" }
						}
					}
				}
			}
		},
		"editingModes": {
			"type": "array",
			"minItems": 1,
			"maxItems": 2,
			"uniqueItems": true,
			"items": { "enum": ["blueprint", "content"] }
		},
		"themeControls": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/localName" }
		},
		"rendererRequirements": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"surface",
					"capability",
					"versions"
				],
				"properties": {
					"surface": { "enum": [
						"web",
						"email",
						"document",
						"native",
						"preview"
					] },
					"capability": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" }
				}
			}
		},
		"accessibility": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"category",
				"accessibleName",
				"keyboard",
				"reducedMotion",
				"outputChecks"
			],
			"properties": {
				"category": { "enum": [
					"structural",
					"landmark",
					"interactive",
					"media",
					"text",
					"decorative",
					"data-display",
					"composite"
				] },
				"accessibleName": { "enum": [
					"not-applicable",
					"required-property",
					"required-binding",
					"derived"
				] },
				"keyboard": { "$ref": "common.schema.json#/$defs/messageReference" },
				"reducedMotion": { "enum": [
					"not-applicable",
					"required",
					"disable-motion"
				] },
				"outputChecks": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"fallback": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"versions",
				"lossless"
			],
			"properties": {
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
				"lossless": {
					"type": "boolean",
					"const": true
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var binding_projection_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/binding-projection-vector.schema.json",
	title: "Studio field-binding projection conformance vector",
	description: "One complete Blueprint, exact host-projected content model, locked block definitions, and the normalized field-binding affordances and diagnostics every implementation must reproduce.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"blueprint",
		"model",
		"blockDefinitions",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "binding-projection-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"profile": { "const": "studio.profile/binding-projection-v1" },
		"blueprint": { "$ref": "blueprint.schema.json" },
		"model": { "$ref": "content-model.schema.json" },
		"blockDefinitions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 1e3,
			"items": { "$ref": "block-definition.schema.json" }
		},
		"expect": { "$ref": "#/$defs/projection" }
	},
	$defs: {
		"fieldPath": {
			"type": "array",
			"minItems": 1,
			"maxItems": 32,
			"items": { "$ref": "common.schema.json#/$defs/localName" }
		},
		"candidate": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"fieldPath",
				"kind",
				"cardinality"
			],
			"properties": {
				"fieldPath": { "$ref": "#/$defs/fieldPath" },
				"kind": { "$ref": "content-model.schema.json#/$defs/field/properties/kind" },
				"itemKind": { "$ref": "content-model.schema.json#/$defs/field/properties/itemKind" },
				"cardinality": { "enum": ["one", "many"] },
				"control": { "$ref": "common.schema.json#/$defs/qualifiedName" }
			}
		},
		"port": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"port",
				"status",
				"candidates"
			],
			"properties": {
				"port": { "$ref": "common.schema.json#/$defs/localName" },
				"status": { "enum": [
					"invalid",
					"non-field-source",
					"resolved",
					"unbound"
				] },
				"bindingSourceKind": { "enum": [
					"context-value",
					"entry-field",
					"query-reference",
					"resource-reference",
					"static-value"
				] },
				"boundFieldPath": { "$ref": "#/$defs/fieldPath" },
				"candidates": {
					"type": "array",
					"maxItems": 1e3,
					"items": { "$ref": "#/$defs/candidate" }
				},
				"multiple": { "type": "boolean" },
				"required": { "type": "boolean" },
				"valueType": {
					"type": "string",
					"minLength": 1,
					"maxLength": 200
				}
			}
		},
		"node": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "ports"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"ports": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/port" }
				}
			}
		},
		"diagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": ["code", "severity"],
			"properties": {
				"code": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"severity": { "enum": [
					"information",
					"warning",
					"error",
					"blocking"
				] },
				"location": { "$ref": "common.schema.json#/$defs/diagnosticLocation" }
			}
		},
		"projection": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"model",
				"nodes",
				"diagnostics"
			],
			"properties": {
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"nodes": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/node" }
				},
				"diagnostics": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/diagnostic" }
				}
			}
		}
	}
};
var authoring_web_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/authoring-web-vector.schema.json",
	title: "Studio portable web-authoring conformance vector",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"requirements",
		"given",
		"lanes",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "authoring-web-vector" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"requirements": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"uniqueItems": true,
			"items": {
				"type": "string",
				"pattern": "^SR-[0-9]{3}$"
			}
		},
		"given": { "$ref": "#/$defs/given" },
		"lanes": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": { "$ref": "#/$defs/lane" }
		},
		"expect": { "$ref": "#/$defs/observation" }
	},
	$defs: {
		"region": { "enum": [
			"canvas",
			"command-palette",
			"inspector",
			"outline",
			"palette",
			"preview",
			"viewport"
		] },
		"given": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"document",
				"direction",
				"locale",
				"readOnly",
				"reducedMotion",
				"selection",
				"viewport"
			],
			"properties": {
				"document": { "$ref": "blueprint.schema.json" },
				"direction": { "enum": ["ltr", "rtl"] },
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"readOnly": { "type": "boolean" },
				"reducedMotion": { "type": "boolean" },
				"selection": { "oneOf": [{ "$ref": "common.schema.json#/$defs/stableId" }, { "type": "null" }] },
				"viewport": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"height",
						"width",
						"zoomPercent"
					],
					"properties": {
						"height": {
							"type": "integer",
							"minimum": 256,
							"maximum": 1e4
						},
						"width": {
							"type": "integer",
							"minimum": 320,
							"maximum": 1e4
						},
						"zoomPercent": {
							"type": "integer",
							"minimum": 100,
							"maximum": 400
						}
					}
				}
			}
		},
		"lane": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"name",
				"surface",
				"steps"
			],
			"properties": {
				"name": { "$ref": "common.schema.json#/$defs/localName" },
				"surface": { "enum": [
					"keyboard",
					"pointer",
					"structural-control"
				] },
				"steps": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "#/$defs/action" }
				}
			}
		},
		"action": { "oneOf": [
			{ "$ref": "#/$defs/focusNode" },
			{ "$ref": "#/$defs/keyAction" },
			{ "$ref": "#/$defs/dragNode" },
			{ "$ref": "#/$defs/activateCommand" },
			{ "$ref": "#/$defs/editProperty" }
		] },
		"focusNode": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"region"
			],
			"properties": {
				"kind": { "const": "focus-node" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"region": { "$ref": "#/$defs/region" }
			}
		},
		"keyAction": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"key",
				"modifiers",
				"region"
			],
			"properties": {
				"kind": { "const": "key" },
				"key": { "enum": [
					"ArrowDown",
					"ArrowLeft",
					"ArrowRight",
					"ArrowUp",
					"Delete",
					"End",
					"Enter",
					"Escape",
					"Home",
					"Tab",
					"d",
					"y",
					"z"
				] },
				"modifiers": {
					"type": "array",
					"maxItems": 4,
					"uniqueItems": true,
					"items": { "enum": [
						"Alt",
						"Control",
						"Meta",
						"Shift"
					] }
				},
				"region": { "$ref": "#/$defs/region" }
			}
		},
		"dragNode": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"destination"
			],
			"properties": {
				"kind": { "const": "drag-node" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "command.schema.json#/$defs/destination" }
			}
		},
		"activateCommand": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"region",
				"type",
				"payload"
			],
			"properties": {
				"kind": { "const": "activate-command" },
				"region": { "$ref": "#/$defs/region" },
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"payload": { "$ref": "#/$defs/jsonObject" }
			}
		},
		"editProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"kind",
				"nodeId",
				"property",
				"value"
			],
			"properties": {
				"kind": { "const": "edit-property" },
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"jsonObject": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"observation": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"announcements",
				"commands",
				"dirty",
				"document",
				"focus",
				"selection"
			],
			"properties": {
				"announcements": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["key", "politeness"],
						"properties": {
							"key": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"politeness": { "enum": ["assertive", "polite"] }
						}
					}
				},
				"commands": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["type", "payload"],
						"properties": {
							"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"payload": { "$ref": "#/$defs/jsonObject" }
						}
					}
				},
				"dirty": { "type": "boolean" },
				"document": { "$ref": "blueprint.schema.json" },
				"focus": {
					"type": "object",
					"additionalProperties": false,
					"required": ["region"],
					"properties": {
						"region": { "$ref": "#/$defs/region" },
						"nodeId": { "$ref": "common.schema.json#/$defs/stableId" }
					}
				},
				"selection": { "oneOf": [{ "$ref": "common.schema.json#/$defs/stableId" }, { "type": "null" }] }
			}
		}
	}
};
var blueprint_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/blueprint.schema.json",
	title: "Studio Blueprint",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"status",
		"label",
		"model",
		"dependencyLock",
		"roots"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "blueprint" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"status": { "enum": [
			"draft",
			"published",
			"retired"
		] },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
		"dependencyLock": {
			"type": "object",
			"additionalProperties": false,
			"required": ["theme", "blocks"],
			"properties": {
				"theme": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blocks": {
					"type": "array",
					"maxItems": 1e3,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": [
							"type",
							"version",
							"revision"
						],
						"properties": {
							"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
							"revision": { "$ref": "common.schema.json#/$defs/revision" },
							"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
						}
					}
				},
				"plugins": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
				}
			}
		},
		"roots": {
			"type": "array",
			"minItems": 0,
			"maxItems": 1e4,
			"items": { "$ref": "#/$defs/node" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "status": { "const": "published" } },
			"required": ["status"]
		},
		"then": { "properties": { "roots": {
			"type": "array",
			"minItems": 1
		} } }
	}],
	$defs: {
		"sizeRoleAxis": { "enum": ["inline", "block"] },
		"node": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"type",
				"version",
				"properties",
				"bindings",
				"slots",
				"authoring"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"properties": {
					"type": "object",
					"maxProperties": 500,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				},
				"bindings": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": { "$ref": "#/$defs/binding" }
				},
				"slots": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": {
						"type": "array",
						"maxItems": 1e4,
						"items": { "$ref": "#/$defs/node" }
					}
				},
				"responsive": {
					"type": "object",
					"maxProperties": 100,
					"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
					"additionalProperties": {
						"type": "object",
						"maxProperties": 20,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				},
				"sizeRoles": {
					"type": "object",
					"maxProperties": 2,
					"propertyNames": { "$ref": "#/$defs/sizeRoleAxis" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/localName" }
				},
				"responsiveSizeRoles": {
					"type": "object",
					"maxProperties": 2,
					"propertyNames": { "$ref": "#/$defs/sizeRoleAxis" },
					"additionalProperties": {
						"type": "object",
						"maxProperties": 20,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/localName" }
					}
				},
				"authoring": {
					"type": "object",
					"additionalProperties": false,
					"required": ["mode"],
					"properties": {
						"mode": { "enum": [
							"locked",
							"content",
							"variant",
							"structural",
							"designer"
						] },
						"allowedBlocks": {
							"type": "array",
							"maxItems": 1e3,
							"uniqueItems": true,
							"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
						},
						"requiredPermission": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"slots": {
							"type": "object",
							"maxProperties": 100,
							"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
							"additionalProperties": {
								"type": "object",
								"additionalProperties": false,
								"required": ["composable"],
								"properties": {
									"composable": { "const": true },
									"allowedBlocks": {
										"type": "array",
										"maxItems": 1e3,
										"uniqueItems": true,
										"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
									}
								}
							}
						}
					}
				},
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"binding": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"source",
				"transforms",
				"onNull",
				"onError"
			],
			"properties": {
				"source": { "$ref": "#/$defs/bindingSource" },
				"transforms": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/transform" }
				},
				"onNull": { "enum": [
					"empty",
					"hide",
					"fallback",
					"error"
				] },
				"onError": { "enum": [
					"hide",
					"fallback",
					"error"
				] },
				"fallback": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"bindingSource": { "oneOf": [
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "fieldPath"],
				"properties": {
					"kind": { "const": "entry-field" },
					"fieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					}
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "key"],
				"properties": {
					"kind": { "const": "context-value" },
					"key": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["kind", "value"],
				"properties": {
					"kind": { "const": "static-value" },
					"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"resourceType",
					"id"
				],
				"properties": {
					"kind": { "const": "resource-reference" },
					"resourceType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"id": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"query",
					"version",
					"parameters"
				],
				"properties": {
					"kind": { "const": "query-reference" },
					"query": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"parameters": {
						"type": "object",
						"maxProperties": 50,
						"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		] },
		"transform": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operator",
				"version",
				"arguments"
			],
			"properties": {
				"operator": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"arguments": {
					"type": "object",
					"maxProperties": 20,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		}
	}
};
var command_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/command.schema.json",
	title: "Studio command",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"type",
		"artifactId",
		"sessionGeneration",
		"baseStateVersion",
		"payload"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "command" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"artifactId": { "$ref": "common.schema.json#/$defs/stableId" },
		"expectedRevision": { "$ref": "common.schema.json#/$defs/revision" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"baseStateVersion": {
			"type": "integer",
			"minimum": 0
		},
		"groupId": { "$ref": "common.schema.json#/$defs/stableId" },
		"payload": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		}
	},
	allOf: [
		{
			"if": { "properties": { "type": { "const": "studio.command/insert-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/insertNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/remove-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/removeNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/restore-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/restoreNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/move-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/moveNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/duplicate-node" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/duplicateNode" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/reorder-children" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/reorderChildren" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/unset-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/unsetProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/reset-inherited-property" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/resetInheritedProperty" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-size-role" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setSizeRole" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/unset-size-role" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/unsetSizeRole" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-binding" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setBinding" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/remove-binding" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/removeBinding" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/set-field-value" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/setFieldValue" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/apply-pattern" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/applyPattern" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/add-model-field" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/addModelField" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.command/batch" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/batch" } } }
		}
	],
	$defs: {
		"destination": {
			"type": "object",
			"additionalProperties": false,
			"required": ["position"],
			"properties": {
				"parentNodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"slot": { "$ref": "common.schema.json#/$defs/localName" },
				"position": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e5
				}
			},
			"dependentRequired": {
				"parentNodeId": ["slot"],
				"slot": ["parentNodeId"]
			}
		},
		"insertNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["node", "destination"],
			"properties": {
				"node": { "$ref": "blueprint.schema.json#/$defs/node" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"removeNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId"],
			"properties": { "nodeId": { "$ref": "common.schema.json#/$defs/stableId" } }
		},
		"restoreNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["node", "destination"],
			"properties": {
				"node": { "$ref": "blueprint.schema.json#/$defs/node" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"moveNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "destination"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "#/$defs/destination" }
			}
		},
		"duplicateNode": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "idMap"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"destination": { "$ref": "#/$defs/destination" },
				"idMap": {
					"type": "object",
					"minProperties": 1,
					"maxProperties": 5e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		},
		"reorderChildren": {
			"type": "object",
			"additionalProperties": false,
			"required": ["order"],
			"properties": {
				"parentNodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"slot": { "$ref": "common.schema.json#/$defs/localName" },
				"order": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e4,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			},
			"dependentRequired": {
				"parentNodeId": ["slot"],
				"slot": ["parentNodeId"]
			}
		},
		"setProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"property",
				"value"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"unsetProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "property"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"resetInheritedProperty": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "property"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"property": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setSizeRole": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"axis",
				"role"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"axis": { "$ref": "blueprint.schema.json#/$defs/sizeRoleAxis" },
				"role": { "$ref": "common.schema.json#/$defs/localName" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"unsetSizeRole": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "axis"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"axis": { "$ref": "blueprint.schema.json#/$defs/sizeRoleAxis" },
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setBinding": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"nodeId",
				"port",
				"binding"
			],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "common.schema.json#/$defs/localName" },
				"binding": { "$ref": "blueprint.schema.json#/$defs/binding" }
			}
		},
		"removeBinding": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId", "port"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"setFieldValue": {
			"type": "object",
			"additionalProperties": false,
			"required": ["fieldPath", "value"],
			"properties": {
				"fieldPath": {
					"type": "array",
					"minItems": 1,
					"maxItems": 32,
					"items": { "$ref": "common.schema.json#/$defs/localName" }
				},
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"addModelField": {
			"type": "object",
			"additionalProperties": false,
			"required": ["field"],
			"properties": {
				"field": { "$ref": "content-model.schema.json#/$defs/field" },
				"position": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e3
				}
			}
		},
		"applyPattern": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"pattern",
				"nodes",
				"destination",
				"idMap"
			],
			"properties": {
				"pattern": {
					"type": "object",
					"additionalProperties": false,
					"required": [
						"id",
						"version",
						"revision"
					],
					"properties": {
						"id": { "$ref": "common.schema.json#/$defs/stableId" },
						"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
						"revision": { "$ref": "common.schema.json#/$defs/revision" }
					}
				},
				"nodes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "blueprint.schema.json#/$defs/node" }
				},
				"destination": { "$ref": "#/$defs/destination" },
				"idMap": {
					"type": "object",
					"minProperties": 1,
					"maxProperties": 5e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		},
		"batch": {
			"type": "object",
			"additionalProperties": false,
			"required": ["operations"],
			"properties": { "operations": {
				"type": "array",
				"minItems": 1,
				"maxItems": 100,
				"items": { "$ref": "#/$defs/batchOperation" }
			} }
		},
		"batchOperation": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "payload"],
			"properties": {
				"type": { "allOf": [{ "$ref": "common.schema.json#/$defs/qualifiedName" }, { "not": { "enum": [
					"studio.command/apply-pattern",
					"studio.command/batch",
					"studio.command/reset-inherited-property"
				] } }] },
				"payload": {
					"type": "object",
					"maxProperties": 1e3,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			},
			"allOf": [
				{
					"if": { "properties": { "type": { "const": "studio.command/insert-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/insertNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/remove-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/removeNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/restore-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/restoreNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/move-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/moveNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/duplicate-node" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/duplicateNode" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/reorder-children" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/reorderChildren" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-property" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setProperty" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/unset-property" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/unsetProperty" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-size-role" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setSizeRole" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/unset-size-role" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/unsetSizeRole" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-binding" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setBinding" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/remove-binding" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/removeBinding" } } }
				},
				{
					"if": { "properties": { "type": { "const": "studio.command/set-field-value" } } },
					"then": { "properties": { "payload": { "$ref": "#/$defs/setFieldValue" } } }
				}
			]
		}
	}
};
var command_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/command-vector.schema.json",
	title: "Studio canonical command vector",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"initial",
		"command",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "command-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"mode": { "enum": [
			"blueprint",
			"content",
			"hybrid",
			"model",
			"read-only"
		] },
		"initial": { "$ref": "blueprint.schema.json" },
		"command": { "$ref": "command.schema.json" },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["document"],
			"properties": { "document": { "$ref": "blueprint.schema.json" } }
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["errorCode"],
			"properties": { "errorCode": { "$ref": "#/$defs/commandErrorCode" } }
		}] },
		"inverse": { "$ref": "command.schema.json" }
	},
	$defs: { "commandErrorCode": { "enum": [
		"artifact-not-draft",
		"binding-not-found",
		"duplicate-field",
		"duplicate-node",
		"illegal-move",
		"invalid-batch",
		"invalid-id-map",
		"invalid-index",
		"invalid-order",
		"locale-mismatch",
		"mode-forbidden",
		"node-not-found",
		"parent-not-found",
		"property-not-found",
		"read-only-session",
		"stale-generation",
		"stale-state",
		"unsupported-command"
	] } }
};
var common_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/common.schema.json",
	title: "Studio common contract types",
	type: "object",
	additionalProperties: false,
	$defs: {
		"contractVersion": {
			"type": "string",
			"const": "0.1-draft"
		},
		"semanticVersion": {
			"type": "string",
			"pattern": "^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\\+([0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*))?(?![\\s\\S])",
			"maxLength": 100
		},
		"versionRange": {
			"type": "string",
			"minLength": 1,
			"maxLength": 120,
			"pattern": "^[0-9A-Za-z.*+<>=~^| -]+(?![\\s\\S])"
		},
		"qualifiedName": {
			"type": "string",
			"pattern": "^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*(?![\\s\\S])",
			"maxLength": 160
		},
		"localName": {
			"type": "string",
			"pattern": "^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] },
			"maxLength": 100
		},
		"safeJsonMemberName": {
			"type": "string",
			"minLength": 1,
			"maxLength": 200,
			"pattern": "^[^\\u0000-\\u001F\\u007F]+(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] }
		},
		"packageRelativePath": {
			"type": "string",
			"pattern": "^[A-Za-z0-9@][A-Za-z0-9._@-]*(?:/[A-Za-z0-9@][A-Za-z0-9._@-]*)*(?![\\s\\S])",
			"maxLength": 240
		},
		"stableId": {
			"type": "string",
			"pattern": "^[A-Za-z0-9][A-Za-z0-9._:/-]*(?![\\s\\S])",
			"not": { "enum": [
				"__proto__",
				"prototype",
				"constructor"
			] },
			"maxLength": 240
		},
		"revision": {
			"type": "string",
			"minLength": 1,
			"maxLength": 200
		},
		"integrity": {
			"type": "string",
			"pattern": "^(?:sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=|sha384-[A-Za-z0-9+/]{64}|sha512-[A-Za-z0-9+/]{85}[AQgw]==)(?![\\s\\S])",
			"maxLength": 200
		},
		"locale": {
			"type": "string",
			"pattern": "^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*(?![\\s\\S])",
			"maxLength": 50
		},
		"canonicalDecimal": {
			"type": "string",
			"pattern": "^(?:-?(?:0|[1-9][0-9]*)\\.[0-9]+|0|-?[1-9][0-9]*)(?![\\s\\S])",
			"maxLength": 40
		},
		"currencyCode": {
			"type": "string",
			"pattern": "^[A-Z]{3}(?![\\s\\S])"
		},
		"moneyValue": {
			"type": "object",
			"additionalProperties": false,
			"required": ["amount", "currency"],
			"properties": {
				"amount": { "$ref": "#/$defs/canonicalDecimal" },
				"currency": { "$ref": "#/$defs/currencyCode" }
			}
		},
		"rfc3339Date": {
			"type": "string",
			"pattern": "^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])(?![\\s\\S])"
		},
		"rfc3339Instant": {
			"type": "string",
			"pattern": "^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:(?:[0-5][0-9]|60)(?:\\.[0-9]{1,9})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])(?![\\s\\S])",
			"maxLength": 40
		},
		"messageReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["key"],
			"properties": {
				"key": { "$ref": "#/$defs/qualifiedName" },
				"defaultMessage": {
					"type": "string",
					"minLength": 1,
					"maxLength": 500
				}
			}
		},
		"ownerReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "version"],
			"properties": {
				"id": { "$ref": "#/$defs/qualifiedName" },
				"version": { "$ref": "#/$defs/semanticVersion" }
			}
		},
		"artifactReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "version"],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"version": { "$ref": "#/$defs/semanticVersion" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"lockedArtifactReference": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"version",
				"revision"
			],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"version": { "$ref": "#/$defs/semanticVersion" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"resolvedEntryReference": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "revision"],
			"properties": {
				"id": { "$ref": "#/$defs/stableId" },
				"revision": { "$ref": "#/$defs/revision" },
				"integrity": { "$ref": "#/$defs/integrity" }
			}
		},
		"jsonValue": { "oneOf": [
			{ "type": "null" },
			{ "type": "boolean" },
			{ "type": "number" },
			{ "type": "string" },
			{
				"type": "array",
				"maxItems": 1e4,
				"items": { "$ref": "#/$defs/jsonValue" }
			},
			{
				"type": "object",
				"maxProperties": 1e4,
				"propertyNames": { "$ref": "#/$defs/safeJsonMemberName" },
				"additionalProperties": { "$ref": "#/$defs/jsonValue" }
			}
		] },
		"extensions": {
			"type": "object",
			"maxProperties": 100,
			"propertyNames": { "$ref": "#/$defs/qualifiedName" },
			"additionalProperties": { "$ref": "#/$defs/jsonValue" }
		},
		"diagnosticLocation": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"artifactId": { "$ref": "#/$defs/stableId" },
				"nodeId": { "$ref": "#/$defs/stableId" },
				"fieldPath": {
					"type": "array",
					"maxItems": 32,
					"items": { "$ref": "#/$defs/localName" }
				},
				"jsonPointer": {
					"type": "string",
					"maxLength": 1e3
				}
			}
		},
		"diagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"code",
				"severity",
				"message"
			],
			"properties": {
				"code": { "$ref": "#/$defs/qualifiedName" },
				"severity": { "enum": [
					"information",
					"warning",
					"error",
					"blocking"
				] },
				"message": { "$ref": "#/$defs/messageReference" },
				"location": { "$ref": "#/$defs/diagnosticLocation" },
				"parameters": {
					"type": "object",
					"maxProperties": 20,
					"propertyNames": { "$ref": "#/$defs/safeJsonMemberName" },
					"additionalProperties": { "oneOf": [
						{ "type": "string" },
						{ "type": "number" },
						{ "type": "boolean" },
						{ "type": "null" }
					] }
				},
				"remediations": {
					"type": "array",
					"maxItems": 10,
					"items": { "$ref": "#/$defs/qualifiedName" }
				}
			}
		}
	}
};
var content_model_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/content-model.schema.json",
	title: "Studio content model",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"status",
		"label",
		"fields",
		"relationships"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "content-model" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"status": { "enum": [
			"draft",
			"published",
			"retired"
		] },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"fields": {
			"type": "array",
			"minItems": 0,
			"maxItems": 1e3,
			"items": { "$ref": "#/$defs/field" }
		},
		"relationships": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "#/$defs/relationship" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "status": { "const": "published" } },
			"required": ["status"]
		},
		"then": { "properties": { "fields": {
			"type": "array",
			"minItems": 1
		} } }
	}],
	$defs: {
		"field": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"label",
				"required",
				"localized",
				"cardinality"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"kind": { "oneOf": [{ "enum": [
					"string",
					"rich-text",
					"boolean",
					"integer",
					"decimal",
					"money",
					"date",
					"date-time",
					"enum",
					"media",
					"resource",
					"object",
					"collection"
				] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"description": { "$ref": "common.schema.json#/$defs/messageReference" },
				"required": { "type": "boolean" },
				"localized": { "type": "boolean" },
				"cardinality": { "enum": ["one", "many"] },
				"semanticRole": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"defaultValue": { "$ref": "common.schema.json#/$defs/jsonValue" },
				"authoring": { "$ref": "#/$defs/authoringMetadata" },
				"constraints": {
					"type": "object",
					"additionalProperties": false,
					"properties": {
						"minimum": {
							"type": "string",
							"pattern": "^-?(0|[1-9][0-9]*)(?:\\.[0-9]+)?(?![\\s\\S])",
							"maxLength": 200
						},
						"maximum": {
							"type": "string",
							"pattern": "^-?(0|[1-9][0-9]*)(?:\\.[0-9]+)?(?![\\s\\S])",
							"maxLength": 200
						},
						"scale": {
							"type": "integer",
							"minimum": 0,
							"maximum": 100
						},
						"minLength": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e7
						},
						"maxLength": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e7
						},
						"validator": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"validatorArguments": {
							"type": "object",
							"maxProperties": 20,
							"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
							"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
						},
						"minItems": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e5
						},
						"maxItems": {
							"type": "integer",
							"minimum": 0,
							"maximum": 1e5
						},
						"allowedMediaKinds": {
							"type": "array",
							"maxItems": 50,
							"uniqueItems": true,
							"items": { "enum": [
								"image",
								"video",
								"audio",
								"document",
								"archive",
								"other"
							] }
						}
					}
				},
				"enumValues": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e3,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["value", "label"],
						"properties": {
							"value": { "$ref": "common.schema.json#/$defs/localName" },
							"label": { "$ref": "common.schema.json#/$defs/messageReference" }
						}
					}
				},
				"fields": {
					"type": "array",
					"maxItems": 1e3,
					"items": { "$ref": "#/$defs/field" }
				},
				"itemKind": { "oneOf": [{ "enum": [
					"string",
					"rich-text",
					"boolean",
					"integer",
					"decimal",
					"money",
					"date",
					"date-time",
					"enum",
					"media",
					"resource",
					"object"
				] }, { "$ref": "common.schema.json#/$defs/qualifiedName" }] },
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		},
		"authoringMetadata": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"placeholder": { "$ref": "common.schema.json#/$defs/messageReference" },
				"group": { "$ref": "common.schema.json#/$defs/localName" },
				"order": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e5
				},
				"readOnly": { "type": "boolean" },
				"hidden": { "type": "boolean" },
				"width": { "enum": [
					"full",
					"half",
					"third",
					"two-thirds"
				] }
			}
		},
		"relationship": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"label",
				"sourceField",
				"targetModel",
				"required",
				"onDelete"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/localName" },
				"kind": { "enum": [
					"one-to-one",
					"many-to-one",
					"one-to-many",
					"many-to-many"
				] },
				"label": { "$ref": "common.schema.json#/$defs/messageReference" },
				"sourceField": { "$ref": "common.schema.json#/$defs/localName" },
				"targetModel": { "$ref": "common.schema.json#/$defs/artifactReference" },
				"targetField": { "$ref": "common.schema.json#/$defs/localName" },
				"required": { "type": "boolean" },
				"onDelete": { "enum": [
					"restrict",
					"nullify",
					"detach"
				] },
				"authoring": {
					"type": "object",
					"additionalProperties": false,
					"required": ["control", "allowCreate"],
					"properties": {
						"control": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"allowCreate": { "type": "boolean" },
						"displayField": { "$ref": "common.schema.json#/$defs/localName" },
						"searchProvider": { "$ref": "common.schema.json#/$defs/qualifiedName" }
					}
				},
				"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
			}
		}
	}
};
var corpus_manifest_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/corpus-manifest.schema.json",
	title: "Studio corpus integrity manifest",
	description: "The digest of every file in the published conformance corpus, grouped by the directory it ships in. A host that vendors the corpus verifies its copy against this manifest, so a stale or altered fixture is detected before it silently changes what a conformance claim means. The schema manifest covers the schemas; this covers everything replayed against them.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"groups"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "corpus-manifest" },
		"groups": {
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"files",
					"group",
					"path"
				],
				"properties": {
					"files": {
						"type": "array",
						"maxItems": 5e3,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["digest", "file"],
							"properties": {
								"digest": { "$ref": "common.schema.json#/$defs/integrity" },
								"file": {
									"type": "string",
									"minLength": 1,
									"maxLength": 200
								}
							}
						}
					},
					"group": {
						"description": "The stable name of the corpus group.",
						"$ref": "common.schema.json#/$defs/localName"
					},
					"path": {
						"description": "The package-relative directory the group ships in.",
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}
	}
};
var canonical_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/canonical-vector.schema.json",
	title: "Studio canonical serialization vector",
	description: "One canonical serialization case: a bounded JSON value and either the exact canonical string it serializes to with the digest of its UTF-8 bytes, or the stable reason the canonical form refuses it. Checksums across the contract are computed over exactly these bytes, so an implementation that reproduces this corpus computes the same digests as every other - which is what makes a vendored-corpus check and a stored-document round-trip comparable across languages.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"value",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "canonical-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"value": { "description": "The input value. Deliberately looser than the canonical JSON value shape so a rejection vector can carry a value the canonical form refuses, such as a forbidden member name. Values the canonical form cannot represent at all - non-finite numbers, undefined - are not expressible in JSON and are proven in implementation tests rather than here." },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["canonical", "digest"],
			"properties": {
				"canonical": {
					"description": "The exact canonical string, byte for byte.",
					"type": "string",
					"maxLength": 2e4
				},
				"digest": {
					"description": "The SRI-style sha256 digest of the canonical UTF-8 bytes.",
					"$ref": "common.schema.json#/$defs/integrity"
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["rejected"],
			"properties": { "rejected": {
				"description": "The stable reason the canonical form refuses the value.",
				"enum": ["depth-exceeded", "forbidden-member"]
			} }
		}] },
		"maximumDepth": {
			"description": "The depth bound to serialize under. Absent means the contract default of 64.",
			"type": "integer",
			"minimum": 1,
			"maximum": 64
		}
	}
};
var design_vocabulary_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/design-vocabulary.schema.json",
	title: "Studio design vocabulary contribution",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"designControls",
		"recipes"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "design-vocabulary" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"designControls": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"kind",
					"label",
					"choices"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"kind": { "enum": [
						"color-role",
						"typography-role",
						"spacing-role",
						"size-role",
						"radius-role",
						"shadow-role",
						"motion-role",
						"layer-role",
						"enum",
						"boolean",
						"integer"
					] },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"description": { "$ref": "common.schema.json#/$defs/messageReference" },
					"choices": {
						"type": "array",
						"minItems": 1,
						"maxItems": 500,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["id", "label"],
							"properties": {
								"id": { "$ref": "common.schema.json#/$defs/localName" },
								"label": { "$ref": "common.schema.json#/$defs/messageReference" },
								"deprecated": { "type": "boolean" }
							}
						}
					}
				}
			}
		},
		"recipes": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"blockType",
					"label",
					"designValues"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"blockType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"designValues": {
						"type": "object",
						"maxProperties": 100,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var entry_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/entry.schema.json",
	title: "Studio content entry",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"revision",
		"model",
		"status",
		"values"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "entry" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
		"status": { "enum": [
			"draft",
			"in-review",
			"published",
			"archived"
		] },
		"locale": { "$ref": "common.schema.json#/$defs/locale" },
		"translationOf": { "$ref": "common.schema.json#/$defs/stableId" },
		"workflowState": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"values": {
			"type": "object",
			"maxProperties": 1e4,
			"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"compositionOverrides": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/stableId" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var field_adapter_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/field-adapter.schema.json",
	title: "Studio field adapter contribution",
	description: "The declarative payload behind a `field-adapter` plugin contribution: a control an extension offers for editing a declared field kind. The declaration is inert data - it names the control, the field kinds it accepts, the capability its executable half requires, and the bounded option schema an author configures it through. It never carries markup, styles or code.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"control",
		"fieldKinds"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "field-adapter" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"control": {
			"description": "The control identifier a field's authoring metadata names to select this adapter.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"fieldKinds": {
			"description": "The field kinds this control accepts. A field of any other kind never resolves to it.",
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"optionSchema": {
			"description": "The bounded schema an author's control options validate against, inside the Studio Schema Profile exactly like a contributed block's property schema.",
			"type": "object",
			"maxProperties": 100,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		},
		"requiredCapability": {
			"description": "The capability the executable half requires. A declaration without one is inspectable but never executed.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var host_capabilities_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-capabilities.schema.json",
	title: "Studio host capabilities",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"host",
		"protocolVersions",
		"ports",
		"capabilities"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-capabilities" },
		"host": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"version",
				"generation"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"generation": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"protocolVersions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/semanticVersion" }
		},
		"ports": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"version",
					"operations"
				],
				"properties": {
					"id": { "$ref": "host-operations.schema.json#/$defs/portCapability" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"operations": {
						"description": "The operations this host actually implements, closed by the operation registry so a capability document cannot advertise an operation that is not on the wire.",
						"type": "array",
						"minItems": 1,
						"maxItems": 100,
						"uniqueItems": true,
						"items": { "$ref": "host-operations.schema.json#/$defs/operationCapability" }
					}
				}
			}
		},
		"capabilities": {
			"type": "array",
			"maxItems": 500,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["id", "version"],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"configuration": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var host_error_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-error.schema.json",
	title: "Studio host port error",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"category",
		"message",
		"retryable"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-error" },
		"category": { "enum": [
			"invalid-request",
			"unauthenticated",
			"forbidden",
			"not-found",
			"conflict",
			"validation-failed",
			"incompatible",
			"limit-exceeded",
			"rate-limited",
			"unavailable",
			"cancelled",
			"internal"
		] },
		"correlationId": { "$ref": "common.schema.json#/$defs/stableId" },
		"message": { "$ref": "common.schema.json#/$defs/messageReference" },
		"retryable": { "type": "boolean" },
		"retryAfterMilliseconds": {
			"type": "integer",
			"minimum": 0,
			"maximum": 864e5
		},
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"diagnostics": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
		}
	}
};
var host_operations_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-operations.schema.json",
	title: "Studio host port operation registry",
	description: "The closed registry binding every host port operation to its names: the wire operation a transport addresses, the typed method a client calls, and the capability identifier a host advertises. A host cannot publish a truthful capability document without it, because the vocabularies must map one to one. Adding an operation is an additive protocol change; renaming or removing one is breaking.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"operations"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-operations" },
		"operations": {
			"type": "array",
			"minItems": 1,
			"maxItems": 200,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"capability",
					"expectsRevision",
					"method",
					"mutating",
					"operation",
					"port",
					"portCapability",
					"required",
					"route"
				],
				"properties": {
					"capability": { "$ref": "#/$defs/operationCapability" },
					"expectsRevision": {
						"description": "The operation is concurrency-protected: the request envelope carries expectedRevision and a mismatch conflicts.",
						"type": "boolean"
					},
					"method": {
						"description": "The typed method name a client port interface exposes, which differs from the wire name where the wire spelling is not a valid identifier.",
						"type": "string",
						"pattern": "^[a-z][A-Za-z0-9]*(?![\\s\\S])",
						"maxLength": 100
					},
					"mutating": {
						"description": "The operation changes host state, so it is authorized, audited and idempotent when retried.",
						"type": "boolean"
					},
					"operation": { "$ref": "common.schema.json#/$defs/localName" },
					"port": { "$ref": "#/$defs/portName" },
					"portCapability": { "$ref": "#/$defs/portCapability" },
					"required": {
						"description": "The port must be present for an editable session; an absent optional port degrades with diagnostics.",
						"type": "boolean"
					},
					"route": { "$ref": "#/$defs/operationRoute" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	$defs: {
		"operationCapability": {
			"description": "The capability identifier a host advertises for one port operation.",
			"enum": [
				"studio.operation/artifact.dependencies",
				"studio.operation/artifact.load",
				"studio.operation/artifact.publish",
				"studio.operation/artifact.save",
				"studio.operation/artifact.unpublish",
				"studio.operation/localization.messages",
				"studio.operation/media.abort-upload",
				"studio.operation/media.authorize-upload",
				"studio.operation/media.complete-upload",
				"studio.operation/media.get",
				"studio.operation/media.import-external",
				"studio.operation/media.list",
				"studio.operation/media.upload-status",
				"studio.operation/model.get",
				"studio.operation/model.list",
				"studio.operation/permission.explain",
				"studio.operation/permission.refresh",
				"studio.operation/preview.cancel",
				"studio.operation/preview.render",
				"studio.operation/recovery.discard",
				"studio.operation/recovery.load",
				"studio.operation/recovery.store",
				"studio.operation/resource.search",
				"studio.operation/telemetry.emit"
			]
		},
		"operationRoute": {
			"description": "The transport route segment addressing one port operation.",
			"enum": [
				"artifact/dependencies",
				"artifact/load",
				"artifact/publish",
				"artifact/save",
				"artifact/unpublish",
				"localization/messages",
				"media/abort-upload",
				"media/authorize-upload",
				"media/complete-upload",
				"media/get",
				"media/import-external",
				"media/list",
				"media/upload-status",
				"model/get",
				"model/list",
				"permission/explain",
				"permission/refresh",
				"preview/cancel",
				"preview/render",
				"recovery/discard",
				"recovery/load",
				"recovery/store",
				"resource/search",
				"telemetry/emit"
			]
		},
		"portCapability": {
			"description": "The capability identifier a host advertises for a whole port.",
			"enum": [
				"studio.port/artifact",
				"studio.port/localization",
				"studio.port/media",
				"studio.port/model",
				"studio.port/permission",
				"studio.port/preview",
				"studio.port/recovery",
				"studio.port/resource",
				"studio.port/telemetry"
			]
		},
		"portName": { "enum": [
			"artifact",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] }
	}
};
var host_request_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-request.schema.json",
	title: "Studio host port request",
	description: "The body of one host port operation call: the argument the operation takes and the envelope every operation carries. A host validates this shape before dispatching, so a malformed envelope is refused as invalid-request rather than partially honoured.",
	type: "object",
	additionalProperties: false,
	required: ["context"],
	properties: {
		"arguments": {
			"description": "The operation argument. Absent for operations that take only the envelope.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		},
		"context": { "$ref": "#/$defs/requestContext" }
	},
	$defs: { "requestContext": {
		"description": "The request envelope. Actor identity and authorization evidence are attached by the trusted transport and never appear here: a Studio-supplied actor value is display context, never authentication.",
		"type": "object",
		"additionalProperties": false,
		"required": [
			"operationId",
			"protocolVersion",
			"requestId",
			"resourceContextKey",
			"sessionGeneration"
		],
		"properties": {
			"expectedRevision": {
				"description": "The revision the caller believes is current. Required by every operation the registry marks expectsRevision; a mismatch conflicts and the host returns the safe current revision.",
				"$ref": "common.schema.json#/$defs/revision"
			},
			"idempotencyKey": {
				"description": "Present when a mutation may be retried. A host that has already accepted this key for this operation returns the original outcome rather than applying the mutation twice.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"locale": {
				"description": "The locale for localized diagnostics. Absent means the session locale.",
				"$ref": "common.schema.json#/$defs/locale"
			},
			"operationId": {
				"description": "The capability identifier of the operation being invoked, closed by the operation registry.",
				"$ref": "host-operations.schema.json#/$defs/operationCapability"
			},
			"protocolVersion": {
				"description": "The negotiated wire version. A version the host does not support is refused as incompatible before any work.",
				"$ref": "common.schema.json#/$defs/semanticVersion"
			},
			"requestId": {
				"description": "Unique per call, used for correlation. It never identifies the actor.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"resourceContextKey": {
				"description": "The opaque, non-secret, non-bearer context key. The host resolves and verifies the canonical context from this key rather than trusting client-supplied scope values; a stale, altered or cross-session key is rejected without disclosing private resource existence.",
				"$ref": "common.schema.json#/$defs/stableId"
			},
			"sessionGeneration": {
				"description": "The generation the session was opened at. A superseded generation is refused, because a permission or context change mints a new one.",
				"$ref": "common.schema.json#/$defs/revision"
			},
			"traceContext": {
				"description": "Trace correlation permitted by privacy policy. It carries no actor identity, credential or private resource value.",
				"type": "object",
				"maxProperties": 10,
				"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
				"additionalProperties": {
					"type": "string",
					"maxLength": 200
				}
			}
		}
	} }
};
var host_result_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-result.schema.json",
	title: "Studio host port result",
	description: "The body a host returns when a port operation succeeds. A failure is never expressed here: it carries the canonical host error document instead, so a client distinguishes outcomes by shape rather than by guessing from a status code.",
	type: "object",
	additionalProperties: false,
	required: ["value"],
	properties: {
		"revision": {
			"description": "The accepted resource revision. Every operation the registry marks expectsRevision returns the revision it advanced to, so a client never re-reads to learn what it just wrote.",
			"$ref": "common.schema.json#/$defs/revision"
		},
		"value": {
			"description": "The normalized operation result. Operations that answer with nothing return null rather than omitting the member, so absence is explicit.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		}
	}
};
var host_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-vector.schema.json",
	title: "Studio canonical host conformance vector",
	description: "One canonical host-port exchange: the host state a conforming implementation is seeded with, the request envelope and argument Studio sends, and either the accepted result or the exact error category the closed taxonomy requires. Every precondition is a condition a real host can reproduce - a seeded revision, a withheld permission, an unknown identifier, an unsupported wire version - so the corpus is replayable by any implementation in any language without executing Studio code.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"port",
		"operation",
		"given",
		"context",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": {
			"description": "The conformance profile whose assertion set this vector belongs to.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"port": { "enum": [
			"artifact",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] },
		"operation": { "$ref": "common.schema.json#/$defs/localName" },
		"given": {
			"description": "The reproducible host state before the request. A conforming host seeds exactly this state; nothing here is a test double.",
			"type": "object",
			"additionalProperties": false,
			"required": ["artifacts", "permissions"],
			"properties": {
				"artifacts": {
					"description": "Artifact identities the host already stores, with the revision each is stored at.",
					"type": "array",
					"maxItems": 20,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": [
							"id",
							"kind",
							"revision"
						],
						"properties": {
							"id": { "$ref": "common.schema.json#/$defs/stableId" },
							"kind": { "enum": [
								"blueprint",
								"content-model",
								"entry"
							] },
							"revision": { "$ref": "common.schema.json#/$defs/revision" }
						}
					}
				},
				"permissions": {
					"description": "Operations the acting identity is authorized for. An operation absent here is withheld.",
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"sessionGeneration": {
					"description": "The host's live session generation. A request naming any other generation is stale.",
					"$ref": "common.schema.json#/$defs/revision"
				}
			}
		},
		"context": {
			"description": "The request envelope every port operation carries.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operationId",
				"protocolVersion",
				"requestId",
				"resourceContextKey",
				"sessionGeneration"
			],
			"properties": {
				"expectedRevision": { "$ref": "common.schema.json#/$defs/revision" },
				"idempotencyKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"locale": { "$ref": "common.schema.json#/$defs/locale" },
				"operationId": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"protocolVersion": {
					"description": "Deliberately looser than the negotiated wire version so a vector can carry a version the host must refuse.",
					"type": "string",
					"maxLength": 100
				},
				"requestId": {
					"description": "Deliberately looser than the canonical stable identifier so a vector can carry a structurally invalid envelope the host must refuse.",
					"type": "string",
					"maxLength": 240
				},
				"resourceContextKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"argument": {
			"description": "The port argument, shaped by the operation. Absent for operations that take only the envelope.",
			"$ref": "common.schema.json#/$defs/jsonValue"
		},
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome"],
			"properties": {
				"outcome": { "const": "result" },
				"revision": {
					"description": "The accepted revision the result carries. A mutation MUST advance it beyond the stored revision.",
					"$ref": "common.schema.json#/$defs/revision"
				},
				"revisionAdvances": {
					"description": "True when the operation mutates and the returned revision must differ from the stored one.",
					"type": "boolean"
				},
				"value": {
					"description": "The value shape the result carries.",
					"enum": [
						"artifact",
						"artifact-references",
						"content-model",
						"content-models",
						"media-asset",
						"media-page",
						"message-bundle",
						"null",
						"permission-explanation",
						"permission-snapshot",
						"recovery-envelope",
						"rendered",
						"search-page",
						"upload-accepted",
						"upload-grant"
					]
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "category"],
			"properties": {
				"outcome": { "const": "error" },
				"category": { "enum": [
					"cancelled",
					"conflict",
					"forbidden",
					"incompatible",
					"internal",
					"invalid-request",
					"limit-exceeded",
					"not-found",
					"rate-limited",
					"unauthenticated",
					"unavailable",
					"validation-failed"
				] },
				"retryable": {
					"description": "The retry classification the error MUST carry.",
					"type": "boolean"
				},
				"revision": {
					"description": "The safe current revision a conflict MUST return so the client can resolve without a second read.",
					"$ref": "common.schema.json#/$defs/revision"
				},
				"messageMustNotContain": {
					"description": "Values the user-facing message MUST NOT echo, so a rejection never discloses private resource existence or request internals.",
					"type": "array",
					"minItems": 1,
					"maxItems": 10,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}] }
	}
};
var host_sequence_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/host-sequence-vector.schema.json",
	title: "Studio canonical host sequence conformance vector",
	description: "A deterministic sequence of host-port invocations, settlements, logical-clock advances and renderer completions. Every stateful precondition is bounded JSON so another runtime can reproduce retries, rate-limit windows and in-flight cancellation without wall-clock sleeps or executable fixture callbacks.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"assertions",
		"idempotencyPolicy",
		"given",
		"steps",
		"expectFinal"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "host-sequence-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": { "const": "studio.profile/host-baseline-v2" },
		"assertions": {
			"type": "array",
			"minItems": 1,
			"maxItems": 12,
			"uniqueItems": true,
			"items": { "$ref": "#/$defs/assertion" }
		},
		"idempotencyPolicy": { "$ref": "#/$defs/idempotencyPolicy" },
		"given": { "$ref": "#/$defs/given" },
		"steps": {
			"type": "array",
			"minItems": 2,
			"maxItems": 24,
			"items": { "oneOf": [
				{ "$ref": "#/$defs/invokeStep" },
				{ "$ref": "#/$defs/settleStep" },
				{ "$ref": "#/$defs/advanceClockStep" },
				{ "$ref": "#/$defs/releasePreviewRenderStep" }
			] }
		},
		"expectFinal": { "$ref": "#/$defs/finalState" }
	},
	$defs: {
		"assertion": { "enum": [
			"operation-id-mismatch-refusal",
			"idempotent-in-flight-coalescing",
			"idempotent-completed-replay",
			"idempotency-changed-argument-refusal",
			"idempotency-changed-context-refusal",
			"idempotency-resource-scope-separation",
			"canonical-number-equivalence",
			"failed-attempt-retry",
			"fixed-window-rate-limit",
			"fixed-window-reset",
			"in-flight-preview-cancellation",
			"cross-context-cancellation-isolation",
			"late-preview-result-discard"
		] },
		"idempotencyPolicy": {
			"description": "The exact map key and canonical intent preimage used by this profile. Scope fields select an accepted record; the argument and listed semantic context fields form its canonical JSON fingerprint. Optional semantic fields that are absent are omitted, not serialized as null. Canonical JSON applies its number grammar, including normalization of negative zero to zero. Per-attempt request and trace correlation never affect intent.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"scopeFields",
				"argumentCanonicalization",
				"intentContextFields",
				"absentOptionalFields",
				"numberNormalization",
				"excludedContextFields"
			],
			"properties": {
				"scopeFields": { "const": [
					"idempotencyKey",
					"operationId",
					"resourceContextKey",
					"sessionGeneration"
				] },
				"argumentCanonicalization": { "const": "canonical-json" },
				"intentContextFields": { "const": [
					"expectedRevision",
					"locale",
					"protocolVersion"
				] },
				"absentOptionalFields": { "const": "omit" },
				"numberNormalization": { "const": "canonical-json" },
				"excludedContextFields": { "const": ["requestId", "traceContext"] }
			}
		},
		"port": { "enum": [
			"artifact",
			"localization",
			"media",
			"model",
			"permission",
			"preview",
			"recovery",
			"resource",
			"telemetry"
		] },
		"artifactSeed": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"kind",
				"revision",
				"status"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"kind": { "enum": [
					"blueprint",
					"content-model",
					"entry"
				] },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published",
					"retired"
				] }
			},
			"allOf": [{
				"if": {
					"properties": { "kind": { "const": "entry" } },
					"required": ["kind"]
				},
				"then": { "properties": { "status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published"
				] } } },
				"else": { "properties": { "status": { "enum": [
					"draft",
					"published",
					"retired"
				] } } }
			}]
		},
		"rateLimit": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"operationId",
				"maximumRequests",
				"windowMilliseconds",
				"retryAfterMilliseconds"
			],
			"properties": {
				"operationId": { "$ref": "host-operations.schema.json#/$defs/operationCapability" },
				"maximumRequests": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e3
				},
				"windowMilliseconds": {
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				},
				"retryAfterMilliseconds": {
					"description": "The retry delay at logical time zero after the fixed-window bound is reached. It equals the initial window duration.",
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				}
			}
		},
		"given": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"artifacts",
				"permissions",
				"sessionGeneration"
			],
			"properties": {
				"artifacts": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/artifactSeed" }
				},
				"permissions": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"rateLimits": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/rateLimit" }
				},
				"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"requestContext": {
			"description": "The canonical host request envelope. Malformed single exchanges remain in the original host corpus; an intentional registered-capability mismatch is an executable invalid-request assertion here.",
			"$ref": "host-request.schema.json#/$defs/requestContext"
		},
		"resultExpectation": {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome"],
			"properties": {
				"outcome": { "const": "result" },
				"revisionAdvancesFrom": { "$ref": "common.schema.json#/$defs/revision" },
				"sameAs": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "enum": ["null", "rendered"] }
			}
		},
		"errorExpectation": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"category",
				"retryable"
			],
			"properties": {
				"outcome": { "const": "error" },
				"category": { "enum": [
					"cancelled",
					"conflict",
					"forbidden",
					"incompatible",
					"internal",
					"invalid-request",
					"limit-exceeded",
					"not-found",
					"rate-limited",
					"unauthenticated",
					"unavailable",
					"validation-failed"
				] },
				"retryable": { "type": "boolean" },
				"retryAfterMilliseconds": {
					"type": "integer",
					"minimum": 0,
					"maximum": 864e5
				}
			}
		},
		"expectation": { "oneOf": [{ "$ref": "#/$defs/resultExpectation" }, { "$ref": "#/$defs/errorExpectation" }] },
		"invokeStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"port",
				"operation",
				"context",
				"completion"
			],
			"properties": {
				"action": { "const": "invoke" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"port": { "$ref": "#/$defs/port" },
				"operation": { "$ref": "common.schema.json#/$defs/localName" },
				"context": { "$ref": "#/$defs/requestContext" },
				"argument": { "$ref": "common.schema.json#/$defs/jsonValue" },
				"completion": { "enum": ["pending", "settled"] },
				"expect": { "$ref": "#/$defs/expectation" }
			},
			"allOf": [{
				"if": {
					"properties": { "completion": { "const": "settled" } },
					"required": ["completion"]
				},
				"then": {
					"properties": { "expect": { "$ref": "#/$defs/expectation" } },
					"required": ["expect"]
				},
				"else": { "not": { "required": ["expect"] } }
			}, {
				"if": {
					"properties": {
						"port": { "const": "preview" },
						"operation": { "const": "render" }
					},
					"required": ["port", "operation"]
				},
				"then": {
					"properties": { "argument": { "$ref": "preview-message.schema.json#/$defs/render" } },
					"required": ["argument"]
				}
			}]
		},
		"settleStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"invocation",
				"expect"
			],
			"properties": {
				"action": { "const": "settle" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"invocation": { "$ref": "common.schema.json#/$defs/stableId" },
				"expect": { "$ref": "#/$defs/expectation" }
			}
		},
		"advanceClockStep": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"milliseconds"
			],
			"properties": {
				"action": { "const": "advance-clock" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"milliseconds": {
					"type": "integer",
					"minimum": 1,
					"maximum": 864e5
				}
			}
		},
		"releasePreviewRenderStep": {
			"description": "A deterministic renderer completion injected after a prior preview.render invocation. It is a harness precondition, not a host port or transport operation.",
			"type": "object",
			"additionalProperties": false,
			"required": [
				"action",
				"id",
				"invocation",
				"value"
			],
			"properties": {
				"action": { "const": "release-preview-render" },
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"invocation": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "$ref": "preview-message.schema.json#/$defs/rendered" }
			}
		},
		"artifactFinalState": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"status",
				"revisionFrom"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"status": { "enum": [
					"archived",
					"draft",
					"in-review",
					"published",
					"retired"
				] },
				"revisionFrom": { "$ref": "common.schema.json#/$defs/stableId" }
			}
		},
		"recoveryFinalState": {
			"type": "object",
			"additionalProperties": false,
			"required": ["resourceContextKey", "value"],
			"properties": {
				"resourceContextKey": { "$ref": "common.schema.json#/$defs/stableId" },
				"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
			}
		},
		"finalState": {
			"type": "object",
			"additionalProperties": false,
			"required": ["pendingPreviewRenders", "previewDeliveries"],
			"properties": {
				"artifacts": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/artifactFinalState" }
				},
				"recovery": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "#/$defs/recoveryFinalState" }
				},
				"pendingPreviewRenders": { "const": 0 },
				"previewDeliveries": {
					"type": "array",
					"maxItems": 20,
					"items": {
						"type": "string",
						"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
					}
				}
			}
		}
	}
};
var inspector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/inspector.schema.json",
	title: "Studio inspector contribution",
	description: "The declarative payload behind an `inspector` plugin contribution: a panel an extension offers for inspecting or configuring the block types it declares. The declaration is inert data; whether its executable half runs, and in which realm, stays a host decision recorded in the plugin manifest.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"blockTypes",
		"placement"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "inspector" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"blockTypes": {
			"description": "The block types this inspector applies to. It never sees a node of any other type.",
			"type": "array",
			"minItems": 1,
			"maxItems": 500,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"placement": {
			"description": "Whether the panel augments the built-in inspector or replaces it for the declared block types. Replacement never removes the host's own policy and accessibility surfaces.",
			"enum": ["augment", "replace"]
		},
		"requiredCapability": {
			"description": "The capability the executable half requires. A declaration without one is inspectable but never executed.",
			"$ref": "common.schema.json#/$defs/qualifiedName"
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var media_asset_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-asset.schema.json",
	title: "Studio host-owned media asset",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"revision",
		"state",
		"mediaKind",
		"mediaType",
		"byteSize",
		"filename",
		"metadata"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-asset" },
		"id": {
			"description": "Host-assigned stable asset identity. Its presence does not grant access, readiness, publication, or delivery authority.",
			"$ref": "common.schema.json#/$defs/stableId"
		},
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"state": {
			"description": "Host lifecycle projection. An accepted stable asset can remain processing; every use and publication decision is host-policy gated.",
			"enum": [
				"processing",
				"ready",
				"rejected",
				"quarantined",
				"archived"
			]
		},
		"mediaKind": { "enum": [
			"image",
			"video",
			"audio",
			"document",
			"archive",
			"other"
		] },
		"mediaType": {
			"type": "string",
			"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
			"maxLength": 200
		},
		"byteSize": {
			"type": "integer",
			"minimum": 0,
			"maximum": 1099511627776
		},
		"filename": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"metadata": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"width": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e6
				},
				"height": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e6
				},
				"durationMilliseconds": {
					"type": "integer",
					"minimum": 0,
					"maximum": 864e6
				},
				"altText": {
					"type": "string",
					"maxLength": 5e3
				},
				"decorative": { "type": "boolean" },
				"caption": {
					"type": "string",
					"maxLength": 2e4
				},
				"credit": {
					"type": "string",
					"maxLength": 2e3
				},
				"license": {
					"type": "string",
					"maxLength": 500
				},
				"focalPoint": {
					"type": "object",
					"additionalProperties": false,
					"required": ["x", "y"],
					"properties": {
						"x": {
							"type": "number",
							"minimum": 0,
							"maximum": 1
						},
						"y": {
							"type": "number",
							"minimum": 0,
							"maximum": 1
						}
					}
				}
			}
		},
		"renditions": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"mediaType",
					"width",
					"height"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"mediaType": {
						"type": "string",
						"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
						"maxLength": 200
					},
					"width": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					},
					"height": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var media_reference_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-reference.schema.json",
	title: "Studio persisted media reference",
	description: "A small, usage-specific reference stored in an artifact. It contains no URL, binary data, storage path, credential, or host delivery authority.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"assetId",
		"usage",
		"accessibility"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-reference" },
		"assetId": { "$ref": "common.schema.json#/$defs/stableId" },
		"assetRevision": { "$ref": "common.schema.json#/$defs/revision" },
		"usage": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"renditionIntent": {
			"type": "object",
			"additionalProperties": false,
			"required": ["role"],
			"properties": {
				"role": { "$ref": "common.schema.json#/$defs/localName" },
				"fit": { "enum": [
					"contain",
					"cover",
					"fill",
					"scale-down"
				] },
				"preferredMediaTypes": {
					"type": "array",
					"maxItems": 10,
					"uniqueItems": true,
					"items": {
						"type": "string",
						"pattern": "^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+(?![\\s\\S])",
						"maxLength": 200
					}
				}
			}
		},
		"focalPoint": {
			"type": "object",
			"additionalProperties": false,
			"required": ["x", "y"],
			"properties": {
				"x": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"y": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				}
			}
		},
		"cropIntent": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": [
				"mode",
				"width",
				"height"
			],
			"properties": {
				"mode": { "const": "aspect-ratio" },
				"width": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				},
				"height": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"mode",
				"x",
				"y",
				"width",
				"height"
			],
			"properties": {
				"mode": { "const": "rectangle" },
				"x": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"y": {
					"type": "number",
					"minimum": 0,
					"maximum": 1
				},
				"width": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 1
				},
				"height": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 1
				}
			}
		}] },
		"accessibility": { "oneOf": [
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode", "altText"],
				"properties": {
					"mode": { "const": "informative" },
					"altText": {
						"type": "string",
						"minLength": 1,
						"maxLength": 5e3
					},
					"caption": {
						"type": "string",
						"maxLength": 2e4
					}
				}
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode"],
				"properties": { "mode": { "const": "decorative" } }
			},
			{
				"type": "object",
				"additionalProperties": false,
				"required": ["mode", "altFieldPath"],
				"properties": {
					"mode": { "const": "bound" },
					"altFieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					},
					"captionFieldPath": {
						"type": "array",
						"minItems": 1,
						"maxItems": 32,
						"items": { "$ref": "common.schema.json#/$defs/localName" }
					}
				}
			}
		] }
	}
};
var media_upload_grant_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-upload-grant.schema.json",
	title: "Studio media upload grant",
	description: "The host's authorization to transfer one upload. Bytes never cross the JSON port: the host issues a short-lived, single-purpose destination it controls and the client transfers directly to it, so custody, quotas and storage placement stay host-owned and a large body never traverses the port transport. A grant authorizes exactly the declared upload and expires; it is not a credential the client may reuse, cache, or apply to another upload.",
	type: "object",
	additionalProperties: false,
	required: [
		"expiresAt",
		"method",
		"plan",
		"uploadId",
		"url"
	],
	properties: {
		"expiresAt": {
			"description": "When the grant stops being honoured. A client that has not completed by then re-authorizes rather than retrying against a dead destination.",
			"$ref": "common.schema.json#/$defs/rfc3339Instant"
		},
		"headers": {
			"description": "Headers the client must send verbatim on the transfer. They carry no user credential and are never logged by the client.",
			"type": "object",
			"maxProperties": 20,
			"propertyNames": {
				"type": "string",
				"pattern": "^[A-Za-z][A-Za-z0-9-]*(?![\\s\\S])",
				"maxLength": 100
			},
			"additionalProperties": {
				"type": "string",
				"maxLength": 2e3
			}
		},
		"method": { "enum": ["POST", "PUT"] },
		"plan": {
			"description": "The bounded transfer plan the host derived from its policy. The client transfers within it and never negotiates a larger bound.",
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"resumable": { "type": "boolean" }
			}
		},
		"uploadId": {
			"description": "The opaque host-issued upload identity used to complete or abort the transfer.",
			"$ref": "common.schema.json#/$defs/stableId"
		},
		"url": {
			"description": "The absolute https destination the host controls. It is never an author-supplied value and never a Studio-derived address.",
			"type": "string",
			"pattern": "^https://",
			"maxLength": 2e3
		}
	}
};
var media_upload_session_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-upload-session.schema.json",
	title: "Studio media upload session",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"request",
		"state",
		"progress"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-upload-session" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"request": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"filename",
				"mediaType",
				"byteSize",
				"purpose"
			],
			"properties": {
				"filename": {
					"type": "string",
					"minLength": 1,
					"maxLength": 255,
					"pattern": "^[^\\u0000-\\u001F\\u007F/\\\\]+(?![\\s\\S])"
				},
				"mediaType": {
					"type": "string",
					"pattern": "^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?![\\s\\S])",
					"maxLength": 120
				},
				"byteSize": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"purpose": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"checksum": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		},
		"plan": { "$ref": "#/$defs/plan" },
		"state": { "enum": [
			"requested",
			"authorized",
			"transferring",
			"verifying",
			"complete",
			"failed",
			"cancelled"
		] },
		"progress": {
			"type": "object",
			"additionalProperties": false,
			"required": ["transferredBytes", "totalBytes"],
			"properties": {
				"transferredBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1099511627776
				},
				"totalBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1099511627776
				}
			}
		},
		"asset": { "$ref": "#/$defs/acceptedAsset" },
		"failure": { "$ref": "common.schema.json#/$defs/diagnostic" }
	},
	allOf: [
		{
			"if": {
				"properties": { "state": { "const": "complete" } },
				"required": ["state"]
			},
			"then": {
				"properties": { "asset": { "$ref": "#/$defs/acceptedAsset" } },
				"required": ["asset"]
			}
		},
		{
			"if": {
				"properties": { "state": { "const": "failed" } },
				"required": ["state"]
			},
			"then": {
				"properties": { "failure": { "$ref": "common.schema.json#/$defs/diagnostic" } },
				"required": ["failure"]
			}
		},
		{
			"if": {
				"properties": { "state": { "enum": [
					"authorized",
					"transferring",
					"verifying"
				] } },
				"required": ["state"]
			},
			"then": {
				"properties": { "plan": { "$ref": "#/$defs/plan" } },
				"required": ["plan"]
			}
		},
		{
			"if": {
				"properties": { "state": { "const": "requested" } },
				"required": ["state"]
			},
			"then": { "not": { "required": ["asset"] } }
		}
	],
	$defs: {
		"acceptedAsset": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"id",
				"revision",
				"state"
			],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"state": { "enum": [
					"processing",
					"ready",
					"rejected",
					"quarantined"
				] }
			}
		},
		"plan": {
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"resumable": { "type": "boolean" }
			}
		}
	}
};
var media_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/media-vector.schema.json",
	title: "Studio canonical media policy vector",
	description: "One canonical upload-policy decision: a host-declared policy, an upload request, and either the deterministic policy-derived plan or a stable failure code, optionally extended with cancellation and retry legality for the media-upload-session state machine.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"policy",
		"request",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "media-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"policy": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"acceptedMediaTypes",
				"maximumBytes",
				"resumable"
			],
			"properties": {
				"acceptedMediaTypes": {
					"type": "array",
					"minItems": 1,
					"maxItems": 100,
					"items": { "$ref": "#/$defs/mediaType" }
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"resumable": { "type": "boolean" }
			}
		},
		"request": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"filename",
				"mediaType",
				"byteSize",
				"purpose"
			],
			"properties": {
				"filename": {
					"description": "Deliberately looser than the media-upload-session request shape so rejection vectors can carry filenames the canonical contract refuses.",
					"type": "string",
					"maxLength": 500
				},
				"mediaType": { "$ref": "#/$defs/mediaType" },
				"byteSize": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"purpose": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"checksum": { "$ref": "common.schema.json#/$defs/integrity" }
			}
		},
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "plan"],
			"properties": {
				"outcome": { "const": "accepted" },
				"plan": { "$ref": "#/$defs/plan" }
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "code"],
			"properties": {
				"outcome": { "const": "rejected" },
				"code": { "$ref": "#/$defs/failureCode" },
				"messageMustNotContain": {
					"description": "Raw request or policy values the user-facing failure message MUST NOT echo.",
					"type": "array",
					"minItems": 1,
					"maxItems": 10,
					"items": {
						"type": "string",
						"minLength": 1,
						"maxLength": 200
					}
				}
			}
		}] },
		"cancel": {
			"description": "Cancellation legality: the session state during which cancel() is issued and the resulting terminal state. Cancellation from an active state ends in cancelled; cancellation after completion is a no-op.",
			"type": "object",
			"additionalProperties": false,
			"required": ["during", "finalState"],
			"properties": {
				"during": { "enum": [
					"complete",
					"requested",
					"transferring",
					"verifying"
				] },
				"finalState": { "enum": ["cancelled", "complete"] }
			}
		},
		"retry": {
			"description": "Retry legality for a rejected request: retry is legal only from the failed state and always runs under a fresh session identity with the identical request.",
			"type": "object",
			"additionalProperties": false,
			"required": ["freshSession"],
			"properties": { "freshSession": { "const": true } }
		}
	},
	$defs: {
		"failureCode": { "enum": ["studio.media/upload-failed", "studio.media/upload-too-large"] },
		"mediaType": {
			"type": "string",
			"pattern": "^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(?![\\s\\S])",
			"maxLength": 120
		},
		"plan": {
			"type": "object",
			"additionalProperties": false,
			"required": ["maximumBytes", "resumable"],
			"properties": {
				"maximumBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"chunkBytes": {
					"type": "integer",
					"minimum": 1024,
					"maximum": 1073741824
				},
				"resumable": { "type": "boolean" }
			}
		}
	}
};
var migration_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/migration.schema.json",
	title: "Studio migration declaration",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"artifactKinds",
		"sourceVersions",
		"targetVersion",
		"lossClassification"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "migration" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"artifactKinds": {
			"type": "array",
			"minItems": 1,
			"maxItems": 5,
			"uniqueItems": true,
			"items": { "enum": [
				"block-definition",
				"blueprint",
				"content-model",
				"entry",
				"theme"
			] }
		},
		"sourceVersions": { "$ref": "common.schema.json#/$defs/versionRange" },
		"targetVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"lossClassification": { "enum": ["lossless", "lossy"] },
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var pattern_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/pattern.schema.json",
	title: "Studio composition pattern",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"label",
		"blockDependencies",
		"roots"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "pattern" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"blockDependencies": {
			"type": "array",
			"maxItems": 200,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"version",
					"revision"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"revision": { "$ref": "common.schema.json#/$defs/revision" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"roots": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": { "$ref": "blueprint.schema.json#/$defs/node" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var plugin_manifest_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/plugin-manifest.schema.json",
	title: "Studio plugin manifest",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"owner",
		"label",
		"activation",
		"entryModules",
		"contributions",
		"requiredCapabilities",
		"optionalCapabilities",
		"dependencies",
		"permissions"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "plugin-manifest" },
		"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"activation": { "enum": ["declarative", "executable"] },
		"entryModules": {
			"type": "array",
			"maxItems": 10,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"realm",
					"path",
					"integrity"
				],
				"properties": {
					"realm": { "enum": [
						"application",
						"worker",
						"sandboxed-frame"
					] },
					"path": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"contributions": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"id",
					"version",
					"resource",
					"integrity"
				],
				"properties": {
					"kind": { "enum": [
						"block",
						"command",
						"design-vocabulary",
						"field-adapter",
						"inspector",
						"locale",
						"migration",
						"panel",
						"pattern",
						"renderer-capability",
						"test-fixture",
						"transform"
					] },
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"resource": { "$ref": "common.schema.json#/$defs/packageRelativePath" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" },
					"executable": {
						"type": "boolean",
						"default": false
					}
				}
			}
		},
		"requiredCapabilities": {
			"type": "array",
			"maxItems": 100,
			"items": { "$ref": "#/$defs/capabilityRequirement" }
		},
		"optionalCapabilities": {
			"type": "array",
			"maxItems": 100,
			"items": { "$ref": "#/$defs/capabilityRequirement" }
		},
		"dependencies": {
			"type": "array",
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"versions",
					"optional"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
					"optional": { "type": "boolean" }
				}
			}
		},
		"permissions": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"locales": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/locale" }
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	$defs: { "capabilityRequirement": {
		"type": "object",
		"additionalProperties": false,
		"required": ["id", "versions"],
		"properties": {
			"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
			"versions": { "$ref": "common.schema.json#/$defs/versionRange" }
		}
	} }
};
var preview_message_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/preview-message.schema.json",
	title: "Studio preview channel message",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"channelId",
		"sessionGeneration",
		"sequence",
		"type",
		"payload"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "preview-message" },
		"channelId": { "$ref": "common.schema.json#/$defs/stableId" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"sequence": {
			"type": "integer",
			"minimum": 0
		},
		"type": { "enum": [
			"studio.preview/activated",
			"studio.preview/dispose",
			"studio.preview/error",
			"studio.preview/measure",
			"studio.preview/measurements",
			"studio.preview/ready",
			"studio.preview/reload",
			"studio.preview/render",
			"studio.preview/rendered",
			"studio.preview/select",
			"studio.preview/teardown",
			"studio.preview/viewport"
		] },
		"payload": {
			"type": "object",
			"maxProperties": 1e3,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
		}
	},
	allOf: [
		{
			"if": { "properties": { "type": { "const": "studio.preview/ready" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/ready" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/render" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/render" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/rendered" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/rendered" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/select" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/select" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/measure" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/measure" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/measurements" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/measurements" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/error" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/error" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/reload" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/reload" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/teardown" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/teardown" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/activated" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/activated" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/viewport" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/viewport" } } }
		},
		{
			"if": { "properties": { "type": { "const": "studio.preview/dispose" } } },
			"then": { "properties": { "payload": { "$ref": "#/$defs/dispose" } } }
		}
	],
	$defs: {
		"previewMarker": {
			"description": "A deterministic marker for one node in a canonical draft: the draft digest followed by its zero-based Blueprint preorder ordinal.",
			"type": "string",
			"pattern": "^studio\\.preview/node/[0-9a-f]{64}/(?:0|[1-9][0-9]{0,4})(?![\\s\\S])",
			"maxLength": 90
		},
		"ready": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"protocolVersion",
				"renderer",
				"viewports"
			],
			"properties": {
				"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"renderer": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"viewports": {
					"type": "array",
					"maxItems": 20,
					"items": { "$ref": "common.schema.json#/$defs/localName" }
				}
			}
		},
		"render": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"artifactId",
				"draftDigest",
				"draftRevision",
				"requestId",
				"viewport"
			],
			"properties": {
				"artifactId": { "$ref": "common.schema.json#/$defs/stableId" },
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"draftRevision": { "$ref": "common.schema.json#/$defs/revision" },
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "A session-unique render attempt identifier. Retries use a new identifier even when draft and viewport are unchanged."
				},
				"viewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"rendered": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requestId",
				"draftDigest",
				"markers",
				"markerMap",
				"diagnostics"
			],
			"properties": {
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "The exact render attempt this response settles."
				},
				"markers": {
					"type": "array",
					"maxItems": 1e5,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				},
				"markerMap": {
					"type": "object",
					"maxProperties": 1e5,
					"propertyNames": { "$ref": "#/$defs/previewMarker" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				},
				"diagnostics": {
					"type": "array",
					"maxItems": 1e4,
					"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
				}
			}
		},
		"select": {
			"type": "object",
			"additionalProperties": false,
			"required": ["nodeId"],
			"properties": {
				"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
				"reveal": {
					"type": "boolean",
					"default": true
				}
			}
		},
		"measure": {
			"type": "object",
			"additionalProperties": false,
			"required": ["requestId", "markers"],
			"properties": {
				"requestId": {
					"$ref": "common.schema.json#/$defs/stableId",
					"description": "A session-unique measurement attempt identifier, never reused for another render or measurement."
				},
				"markers": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1e3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				}
			}
		},
		"measurements": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requestId",
				"draftDigest",
				"measurements",
				"unknown",
				"viewport"
			],
			"properties": {
				"requestId": { "$ref": "common.schema.json#/$defs/stableId" },
				"draftDigest": {
					"type": "string",
					"pattern": "^[a-f0-9]{64}(?![\\s\\S])"
				},
				"measurements": {
					"type": "object",
					"maxProperties": 1e3,
					"propertyNames": { "$ref": "#/$defs/previewMarker" },
					"additionalProperties": {
						"type": "array",
						"minItems": 1,
						"maxItems": 1e3,
						"items": { "$ref": "#/$defs/markerRect" }
					}
				},
				"unknown": {
					"type": "array",
					"maxItems": 1e3,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/previewMarker" }
				},
				"viewport": { "$ref": "#/$defs/viewportMetrics" }
			}
		},
		"markerRect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"x",
				"y",
				"width",
				"height"
			],
			"properties": {
				"x": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"y": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"width": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"height": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				}
			}
		},
		"viewportMetrics": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"width",
				"height",
				"scrollX",
				"scrollY",
				"devicePixelRatio"
			],
			"properties": {
				"width": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"height": {
					"type": "number",
					"minimum": 0,
					"maximum": 1e8
				},
				"scrollX": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"scrollY": {
					"type": "number",
					"minimum": -1e8,
					"maximum": 1e8
				},
				"devicePixelRatio": {
					"type": "number",
					"exclusiveMinimum": 0,
					"maximum": 100
				}
			}
		},
		"error": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"code",
				"message",
				"retryable"
			],
			"properties": {
				"code": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"message": { "$ref": "common.schema.json#/$defs/messageReference" },
				"retryable": { "type": "boolean" },
				"correlationId": { "$ref": "common.schema.json#/$defs/stableId" }
			}
		},
		"reload": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": { "reason": { "$ref": "common.schema.json#/$defs/qualifiedName" } }
		},
		"teardown": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": { "reason": { "$ref": "common.schema.json#/$defs/qualifiedName" } }
		},
		"activated": {
			"type": "object",
			"additionalProperties": false,
			"required": ["interaction", "marker"],
			"properties": {
				"interaction": {
					"description": "How the author reached the region. The renderer reports intent, never raw input events.",
					"enum": [
						"activate",
						"context-menu",
						"focus"
					]
				},
				"marker": {
					"description": "A marker from the renderer's currently accepted inventory. The channel drops invented and stale markers.",
					"$ref": "#/$defs/previewMarker"
				}
			}
		},
		"viewport": {
			"description": "A semantic viewport role and explicit dimensions are alternatives, never a merge. Each branch is closed, so a payload naming both matches neither.",
			"oneOf": [{
				"type": "object",
				"additionalProperties": false,
				"required": ["viewport"],
				"properties": { "viewport": {
					"$ref": "common.schema.json#/$defs/localName",
					"description": "The theme-declared semantic viewport role to render at."
				} }
			}, {
				"type": "object",
				"additionalProperties": false,
				"minProperties": 1,
				"properties": {
					"height": {
						"description": "Bounded CSS-pixel height.",
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					},
					"width": {
						"description": "Bounded CSS-pixel width.",
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					}
				}
			}]
		},
		"dispose": {
			"type": "object",
			"additionalProperties": false,
			"required": ["reason"],
			"properties": {
				"draftDigest": {
					"description": "Revoke only the resources held for this render. Absent revokes every draft resource the renderer holds.",
					"type": "string",
					"pattern": "^[0-9a-f]{64}(?![\\s\\S])"
				},
				"reason": { "$ref": "common.schema.json#/$defs/qualifiedName" }
			}
		}
	}
};
var preview_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/preview-vector.schema.json",
	title: "Studio preview identity conformance vector",
	description: "One complete Blueprint draft, its exact render-attempt identity, and the SHA-256 plus deterministic marker inventory every implementation must reproduce under the preview identity profile.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"protocolVersion",
		"draft",
		"render",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "preview-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 1e3
		},
		"profile": { "const": "studio.profile/preview-identity-v1" },
		"protocolVersion": { "const": "0.1.0-draft.2" },
		"draft": { "$ref": "blueprint.schema.json" },
		"render": { "$ref": "preview-message.schema.json#/$defs/render" },
		"expect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"draftDigest",
				"markers",
				"markerMap"
			],
			"properties": {
				"draftDigest": {
					"type": "string",
					"pattern": "^[0-9a-f]{64}(?![\\s\\S])"
				},
				"markers": {
					"type": "array",
					"maxItems": 1e5,
					"uniqueItems": true,
					"items": { "$ref": "preview-message.schema.json#/$defs/previewMarker" }
				},
				"markerMap": {
					"type": "object",
					"maxProperties": 1e5,
					"propertyNames": { "$ref": "preview-message.schema.json#/$defs/previewMarker" },
					"additionalProperties": { "$ref": "common.schema.json#/$defs/stableId" }
				}
			}
		}
	}
};
var renderer_web_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/renderer-web-vector.schema.json",
	title: "Studio renderer-web conformance vector",
	type: "object",
	additionalProperties: false,
	required: [
		"id",
		"coverage",
		"roots",
		"bindings",
		"media",
		"expect"
	],
	properties: {
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"coverage": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"blockTypes",
				"behaviors",
				"presentation",
				"security"
			],
			"properties": {
				"blockTypes": {
					"type": "array",
					"maxItems": 100,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				},
				"behaviors": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"accordion-native",
						"countdown",
						"dialog",
						"lightbox",
						"navigation-disclosure",
						"notice-dismiss",
						"popover",
						"slideshow",
						"tabs"
					] }
				},
				"presentation": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"alignment",
						"inverse",
						"markers",
						"motion",
						"position",
						"print",
						"responsive-visibility",
						"scrolling",
						"sizing",
						"spacing"
					] }
				},
				"security": {
					"type": "array",
					"maxItems": 50,
					"uniqueItems": true,
					"items": { "enum": [
						"active-media-deny",
						"blob-default-deny",
						"escaped-text",
						"safe-url-deny",
						"typed-data-fallback"
					] }
				}
			}
		},
		"context": {
			"type": "object",
			"additionalProperties": false,
			"properties": { "allowBlobMedia": { "type": "boolean" } }
		},
		"roots": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": { "$ref": "blueprint.schema.json#/$defs/node" }
		},
		"bindings": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"nodeId",
					"port",
					"value"
				],
				"properties": {
					"nodeId": { "$ref": "common.schema.json#/$defs/stableId" },
					"port": { "$ref": "common.schema.json#/$defs/localName" },
					"value": { "$ref": "common.schema.json#/$defs/jsonValue" }
				}
			}
		},
		"media": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"assetId",
					"src",
					"altText"
				],
				"properties": {
					"assetId": { "$ref": "common.schema.json#/$defs/stableId" },
					"src": {
						"type": "string",
						"minLength": 1,
						"maxLength": 2048
					},
					"altText": {
						"type": "string",
						"maxLength": 5e3
					},
					"mediaType": {
						"type": "string",
						"maxLength": 255
					},
					"caption": {
						"type": "string",
						"maxLength": 2e4
					},
					"width": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e5
					},
					"height": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e5
					}
				}
			}
		},
		"expect": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"htmlContains",
				"htmlExcludes",
				"cssContains",
				"enhancements"
			],
			"properties": {
				"htmlContains": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"htmlExcludes": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"cssContains": {
					"type": "array",
					"maxItems": 100,
					"items": {
						"type": "string",
						"maxLength": 1e4
					}
				},
				"enhancements": {
					"type": "array",
					"maxItems": 100,
					"items": { "enum": [
						"chart",
						"countdown",
						"diagram",
						"dialog",
						"lightbox",
						"math",
						"motion",
						"navigation",
						"notice",
						"popover",
						"slideshow",
						"tabs"
					] }
				}
			}
		}
	}
};
var rich_text_projection_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/rich-text-projection.schema.json",
	title: "Studio rich-text renderer-conformance fixture",
	type: "object",
	additionalProperties: false,
	required: [
		"description",
		"document",
		"projection"
	],
	properties: {
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"document": { "$ref": "rich-text.schema.json" },
		"projection": {
			"type": "array",
			"maxItems": 1e5,
			"items": { "$ref": "#/$defs/blockProjection" }
		}
	},
	$defs: {
		"blockProjection": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"text",
				"spans",
				"embeds"
			],
			"properties": {
				"type": { "enum": [
					"checklistItem",
					"codeBlock",
					"heading",
					"horizontalRule",
					"paragraph",
					"tableCell"
				] },
				"text": {
					"type": "string",
					"maxLength": 25e4
				},
				"spans": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/span" }
				},
				"embeds": {
					"type": "array",
					"maxItems": 1e5,
					"items": { "$ref": "#/$defs/embed" }
				}
			}
		},
		"span": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"start",
				"end",
				"marks"
			],
			"properties": {
				"start": {
					"type": "integer",
					"minimum": 0,
					"maximum": 25e4
				},
				"end": {
					"type": "integer",
					"minimum": 1,
					"maximum": 25e4
				},
				"marks": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5,
					"uniqueItems": true,
					"items": { "enum": [
						"bold",
						"code",
						"highlight",
						"italic",
						"strike"
					] }
				}
			}
		},
		"embed": {
			"type": "object",
			"additionalProperties": false,
			"required": ["index", "kind"],
			"properties": {
				"index": {
					"type": "integer",
					"minimum": 0,
					"maximum": 25e4
				},
				"kind": { "enum": ["hardBreak"] }
			}
		}
	}
};
var provenance_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/provenance.schema.json",
	title: "Studio artifact provenance record",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"artifact",
		"chain"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "provenance" },
		"artifact": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "revision"],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" }
			}
		},
		"chain": {
			"type": "array",
			"minItems": 1,
			"maxItems": 1e4,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"origin",
					"toRevision",
					"recordedAt",
					"actor"
				],
				"properties": {
					"origin": { "enum": [
						"authoring",
						"import",
						"migration",
						"pattern",
						"plugin",
						"system"
					] },
					"fromRevision": { "$ref": "common.schema.json#/$defs/revision" },
					"toRevision": { "$ref": "common.schema.json#/$defs/revision" },
					"recordedAt": { "$ref": "common.schema.json#/$defs/rfc3339Instant" },
					"actor": {
						"type": "object",
						"additionalProperties": false,
						"required": ["id"],
						"properties": {
							"id": { "$ref": "common.schema.json#/$defs/stableId" },
							"displayName": {
								"type": "string",
								"minLength": 1,
								"maxLength": 200
							}
						}
					},
					"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
					"commandCount": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e5
					},
					"source": { "$ref": "common.schema.json#/$defs/artifactReference" },
					"migrationId": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var rich_text_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/rich-text.schema.json",
	title: "Studio portable rich text",
	$ref: "#/$defs/doc",
	$defs: {
		"doc": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "doc" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"block": { "oneOf": [
			{ "$ref": "#/$defs/paragraph" },
			{ "$ref": "#/$defs/heading" },
			{ "$ref": "#/$defs/blockquote" },
			{ "$ref": "#/$defs/bulletList" },
			{ "$ref": "#/$defs/orderedList" },
			{ "$ref": "#/$defs/horizontalRule" },
			{ "$ref": "#/$defs/checklist" },
			{ "$ref": "#/$defs/table" },
			{ "$ref": "#/$defs/callout" },
			{ "$ref": "#/$defs/codeBlock" }
		] },
		"paragraph": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "paragraph" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"heading": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "heading" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["level"],
					"properties": { "level": { "enum": [
						2,
						3,
						4
					] } }
				},
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"blockquote": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "blockquote" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"bulletList": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "bulletList" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/listItem" }
				}
			}
		},
		"orderedList": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "orderedList" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["start"],
					"properties": { "start": {
						"type": "integer",
						"minimum": 1,
						"maximum": 1e6
					} }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/listItem" }
				}
			}
		},
		"listItem": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "listItem" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"horizontalRule": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "const": "horizontalRule" } }
		},
		"checklist": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "checklist" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 500,
					"items": { "$ref": "#/$defs/checklistItem" }
				}
			}
		},
		"checklistItem": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "checklistItem" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["checked", "level"],
					"properties": {
						"checked": { "type": "boolean" },
						"level": {
							"type": "integer",
							"minimum": 0,
							"maximum": 4
						}
					}
				},
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"table": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"content"
			],
			"properties": {
				"type": { "const": "table" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["header"],
					"properties": { "header": { "type": "boolean" } }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 200,
					"items": { "$ref": "#/$defs/tableRow" }
				}
			}
		},
		"tableRow": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "content"],
			"properties": {
				"type": { "const": "tableRow" },
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 50,
					"items": { "$ref": "#/$defs/tableCell" }
				}
			}
		},
		"tableCell": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": {
				"type": { "const": "tableCell" },
				"content": {
					"type": "array",
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/inline" }
				}
			}
		},
		"callout": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"content"
			],
			"properties": {
				"type": { "const": "callout" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["tone"],
					"properties": { "tone": { "enum": [
						"danger",
						"info",
						"success",
						"warning"
					] } }
				},
				"content": {
					"type": "array",
					"minItems": 1,
					"maxItems": 5e3,
					"items": { "$ref": "#/$defs/block" }
				}
			}
		},
		"codeBlock": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"type",
				"attrs",
				"text"
			],
			"properties": {
				"type": { "const": "codeBlock" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["language"],
					"properties": { "language": {
						"type": "string",
						"pattern": "^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$"
					} }
				},
				"text": {
					"type": "string",
					"maxLength": 25e4
				}
			}
		},
		"hardBreak": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "const": "hardBreak" } }
		},
		"text": {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "text"],
			"properties": {
				"type": { "const": "text" },
				"text": {
					"type": "string",
					"minLength": 1,
					"maxLength": 25e4
				},
				"marks": {
					"type": "array",
					"maxItems": 4,
					"items": { "$ref": "#/$defs/mark" }
				}
			}
		},
		"inline": { "oneOf": [{ "$ref": "#/$defs/text" }, { "$ref": "#/$defs/hardBreak" }] },
		"mark": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["type"],
			"properties": { "type": { "enum": [
				"bold",
				"code",
				"italic",
				"strike"
			] } }
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": ["type", "attrs"],
			"properties": {
				"type": { "const": "highlight" },
				"attrs": {
					"type": "object",
					"additionalProperties": false,
					"required": ["tone"],
					"properties": { "tone": { "enum": [
						"accent",
						"danger",
						"info",
						"success",
						"warning"
					] } }
				}
			}
		}] }
	}
};
var schema_profile_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/schema-profile.schema.json",
	title: "Studio Schema Profile meta-schema",
	allOf: [{ "$ref": "#/$defs/schema" }, {
		"type": "object",
		"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
		"required": ["type", "additionalProperties"],
		"properties": {
			"type": { "const": "object" },
			"additionalProperties": { "const": false }
		}
	}],
	$defs: {
		"schema": {
			"type": "object",
			"propertyNames": { "enum": [
				"$defs",
				"$ref",
				"$schema",
				"additionalProperties",
				"allOf",
				"anyOf",
				"const",
				"default",
				"dependentRequired",
				"description",
				"else",
				"enum",
				"examples",
				"exclusiveMaximum",
				"exclusiveMinimum",
				"if",
				"items",
				"maxItems",
				"maxLength",
				"maxProperties",
				"maximum",
				"minItems",
				"minLength",
				"minProperties",
				"minimum",
				"multipleOf",
				"not",
				"oneOf",
				"prefixItems",
				"properties",
				"propertyNames",
				"readOnly",
				"required",
				"then",
				"title",
				"type",
				"uniqueItems",
				"writeOnly"
			] },
			"properties": {
				"$defs": { "$ref": "#/$defs/schemaMap" },
				"$ref": {
					"type": "string",
					"maxLength": 500,
					"pattern": "^#(?:/(?:[A-Za-z0-9._!$&'()*+,;=:@-]|~[01])*)*(?![\\s\\S])"
				},
				"$schema": { "const": "https://json-schema.org/draft/2020-12/schema" },
				"additionalProperties": { "$ref": "#/$defs/subschema" },
				"allOf": { "$ref": "#/$defs/schemaArray" },
				"anyOf": { "$ref": "#/$defs/schemaArray" },
				"const": { "$ref": "#/$defs/jsonValue" },
				"default": { "$ref": "#/$defs/jsonValue" },
				"dependentRequired": {
					"type": "object",
					"maxProperties": 512,
					"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
					"additionalProperties": {
						"type": "array",
						"maxItems": 512,
						"uniqueItems": true,
						"items": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
					}
				},
				"description": {
					"type": "string",
					"maxLength": 1e4
				},
				"else": { "$ref": "#/$defs/subschema" },
				"enum": {
					"type": "array",
					"minItems": 1,
					"maxItems": 1024,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/jsonValue" }
				},
				"examples": {
					"type": "array",
					"maxItems": 100,
					"items": { "$ref": "#/$defs/jsonValue" }
				},
				"exclusiveMaximum": { "type": "number" },
				"exclusiveMinimum": { "type": "number" },
				"if": { "$ref": "#/$defs/subschema" },
				"items": { "$ref": "#/$defs/subschema" },
				"maxItems": {
					"type": "integer",
					"minimum": 0
				},
				"maxLength": {
					"type": "integer",
					"minimum": 0
				},
				"maxProperties": {
					"type": "integer",
					"minimum": 0
				},
				"maximum": { "type": "number" },
				"minItems": {
					"type": "integer",
					"minimum": 0
				},
				"minLength": {
					"type": "integer",
					"minimum": 0
				},
				"minProperties": {
					"type": "integer",
					"minimum": 0
				},
				"minimum": { "type": "number" },
				"multipleOf": {
					"type": "number",
					"exclusiveMinimum": 0
				},
				"not": { "$ref": "#/$defs/subschema" },
				"oneOf": { "$ref": "#/$defs/schemaArray" },
				"prefixItems": { "$ref": "#/$defs/schemaArray" },
				"properties": { "$ref": "#/$defs/schemaMap" },
				"propertyNames": { "$ref": "#/$defs/subschema" },
				"readOnly": { "type": "boolean" },
				"required": {
					"type": "array",
					"maxItems": 512,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" }
				},
				"then": { "$ref": "#/$defs/subschema" },
				"title": {
					"type": "string",
					"maxLength": 1e3
				},
				"type": { "oneOf": [{ "$ref": "#/$defs/typeName" }, {
					"type": "array",
					"minItems": 1,
					"maxItems": 7,
					"uniqueItems": true,
					"items": { "$ref": "#/$defs/typeName" }
				}] },
				"uniqueItems": { "type": "boolean" },
				"writeOnly": { "type": "boolean" }
			}
		},
		"schemaArray": {
			"type": "array",
			"minItems": 1,
			"maxItems": 64,
			"items": { "$ref": "#/$defs/subschema" }
		},
		"schemaMap": {
			"type": "object",
			"maxProperties": 512,
			"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
			"additionalProperties": { "$ref": "#/$defs/schema" }
		},
		"jsonValue": { "oneOf": [
			{ "type": "null" },
			{ "type": "boolean" },
			{ "type": "number" },
			{ "type": "string" },
			{
				"type": "array",
				"maxItems": 1e4,
				"items": { "$ref": "#/$defs/jsonValue" }
			},
			{
				"type": "object",
				"maxProperties": 1e3,
				"propertyNames": { "$ref": "common.schema.json#/$defs/safeJsonMemberName" },
				"additionalProperties": { "$ref": "#/$defs/jsonValue" }
			}
		] },
		"subschema": { "oneOf": [{ "type": "boolean" }, { "$ref": "#/$defs/schema" }] },
		"typeName": { "enum": [
			"array",
			"boolean",
			"integer",
			"null",
			"number",
			"object",
			"string"
		] },
		"limits": { "const": {
			"maxAlternatives": 64,
			"maxDescriptionLength": 1e4,
			"maxEnumMembers": 1024,
			"maxExamples": 100,
			"maxJsonDepth": 64,
			"maxJsonItems": 1e4,
			"maxJsonProperties": 1e3,
			"maxObjectKeyLength": 200,
			"maxPropertyNames": 512,
			"maxReferenceLength": 500,
			"maxReferences": 128,
			"maxSchemaBytes": 262144,
			"maxSchemaDepth": 32,
			"maxSchemaMapProperties": 512,
			"maxSchemaNodes": 1024,
			"maxTitleLength": 1e3
		} }
	}
};
var schema_profile_vector_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/schema-profile-vector.schema.json",
	title: "Studio property-schema profile conformance vector",
	description: "One portable admission or instance-validation assertion for studio.profile/schema-property. Rejection vectors deliberately carry schemas outside the profile, so the schema member is bounded JSON rather than a reference to the profile meta-schema.",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"description",
		"profile",
		"schema",
		"expect"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "schema-profile-vector" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"description": {
			"type": "string",
			"minLength": 1,
			"maxLength": 500
		},
		"profile": { "const": "studio.profile/schema-property" },
		"boundary": { "$ref": "#/$defs/limitBoundary" },
		"schema": { "description": "The candidate schema. This is intentionally unconstrained so invalid-root and unsafe-member vectors remain expressible; the vector file itself remains bounded by the corpus size policy. Values JSON cannot represent are covered by implementation tests." },
		"expect": { "oneOf": [{
			"type": "object",
			"additionalProperties": false,
			"required": ["outcome", "instances"],
			"properties": {
				"outcome": { "const": "accepted" },
				"instances": {
					"type": "array",
					"minItems": 1,
					"maxItems": 50,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["value", "valid"],
						"properties": {
							"value": { "$ref": "common.schema.json#/$defs/jsonValue" },
							"valid": { "type": "boolean" },
							"diagnostic": { "$ref": "#/$defs/instanceDiagnostic" }
						},
						"allOf": [{
							"if": { "properties": { "valid": { "const": true } } },
							"then": { "properties": { "diagnostic": false } },
							"else": {
								"required": ["diagnostic"],
								"properties": { "diagnostic": { "$ref": "#/$defs/instanceDiagnostic" } }
							}
						}]
					}
				}
			}
		}, {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"outcome",
				"code",
				"schemaPath"
			],
			"properties": {
				"outcome": { "const": "rejected" },
				"code": { "enum": [
					"invalid-root",
					"unsupported-keyword",
					"invalid-keyword-value",
					"unsafe-member",
					"limit-exceeded",
					"invalid-reference",
					"recursive-schema"
				] },
				"schemaPath": { "$ref": "#/$defs/jsonPointer" }
			}
		}] }
	},
	$defs: {
		"limitBoundary": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"limit",
				"position",
				"value"
			],
			"properties": {
				"limit": { "enum": [
					"maxAlternatives",
					"maxDescriptionLength",
					"maxEnumMembers",
					"maxExamples",
					"maxJsonDepth",
					"maxJsonItems",
					"maxJsonProperties",
					"maxObjectKeyLength",
					"maxPropertyNames",
					"maxReferenceLength",
					"maxReferences",
					"maxSchemaBytes",
					"maxSchemaDepth",
					"maxSchemaMapProperties",
					"maxSchemaNodes",
					"maxTitleLength"
				] },
				"position": { "enum": ["at-limit", "over-limit"] },
				"value": {
					"type": "integer",
					"minimum": 1
				}
			}
		},
		"instanceDiagnostic": {
			"type": "object",
			"additionalProperties": false,
			"required": ["instancePath", "keyword"],
			"properties": {
				"instancePath": { "$ref": "#/$defs/jsonPointer" },
				"keyword": {
					"type": "string",
					"minLength": 1,
					"maxLength": 100,
					"pattern": "^[A-Za-z$][A-Za-z0-9$]*(?![\\s\\S])"
				}
			}
		},
		"jsonPointer": {
			"type": "string",
			"maxLength": 1e3,
			"pattern": "^(?:/(?:[^~\\u0000-\\u001F\\u007F]|~[01])*)*(?![\\s\\S])"
		}
	}
};
var studio_config_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-config.schema.json",
	title: "Studio resolved session configuration",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"protocolVersion",
		"sessionId",
		"sessionGeneration",
		"mode",
		"composite",
		"sessionState",
		"actor",
		"locale",
		"displayPreferences",
		"resourceContext",
		"permissions",
		"artifacts",
		"blocks",
		"plugins",
		"hostCapabilities",
		"limits",
		"features",
		"preview"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"sessionId": { "$ref": "common.schema.json#/$defs/stableId" },
		"sessionGeneration": { "$ref": "common.schema.json#/$defs/revision" },
		"mode": { "enum": [
			"model",
			"blueprint",
			"content"
		] },
		"composite": { "enum": ["single", "hybrid"] },
		"sessionState": { "enum": ["editable", "read-only"] },
		"actor": {
			"type": "object",
			"additionalProperties": false,
			"required": ["id", "displayName"],
			"properties": {
				"id": { "$ref": "common.schema.json#/$defs/stableId" },
				"displayName": {
					"type": "string",
					"minLength": 1,
					"maxLength": 200
				}
			}
		},
		"locale": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"requested",
				"resolved",
				"fallbacks",
				"direction",
				"timeZone"
			],
			"properties": {
				"requested": { "$ref": "common.schema.json#/$defs/locale" },
				"resolved": { "$ref": "common.schema.json#/$defs/locale" },
				"fallbacks": {
					"type": "array",
					"maxItems": 10,
					"uniqueItems": true,
					"items": { "$ref": "common.schema.json#/$defs/locale" }
				},
				"direction": { "enum": ["ltr", "rtl"] },
				"timeZone": {
					"type": "string",
					"minLength": 1,
					"maxLength": 100
				}
			}
		},
		"displayPreferences": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"calendar",
				"numberingSystem",
				"hourCycle"
			],
			"properties": {
				"calendar": { "$ref": "common.schema.json#/$defs/localName" },
				"numberingSystem": { "$ref": "common.schema.json#/$defs/localName" },
				"hourCycle": { "enum": [
					"h11",
					"h12",
					"h23",
					"h24"
				] },
				"measurementSystem": { "enum": [
					"metric",
					"us",
					"uk"
				] }
			}
		},
		"resourceContext": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"key",
				"surface",
				"scopes"
			],
			"properties": {
				"key": { "$ref": "common.schema.json#/$defs/stableId" },
				"surface": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"revision": { "$ref": "common.schema.json#/$defs/revision" },
				"scopes": {
					"type": "array",
					"maxItems": 20,
					"uniqueItems": true,
					"items": {
						"type": "object",
						"additionalProperties": false,
						"required": ["kind", "id"],
						"properties": {
							"kind": { "$ref": "common.schema.json#/$defs/qualifiedName" },
							"id": { "$ref": "common.schema.json#/$defs/stableId" }
						}
					}
				},
				"resource": {
					"type": "object",
					"additionalProperties": false,
					"required": ["type", "id"],
					"properties": {
						"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
						"id": { "$ref": "common.schema.json#/$defs/stableId" }
					}
				}
			}
		},
		"permissions": {
			"type": "array",
			"maxItems": 500,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		},
		"artifacts": {
			"type": "object",
			"additionalProperties": false,
			"properties": {
				"model": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"blueprint": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" },
				"entry": { "$ref": "common.schema.json#/$defs/resolvedEntryReference" },
				"theme": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
			}
		},
		"plugins": {
			"type": "array",
			"maxItems": 100,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/lockedArtifactReference" }
		},
		"blocks": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"version",
					"revision"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"revision": { "$ref": "common.schema.json#/$defs/revision" },
					"integrity": { "$ref": "common.schema.json#/$defs/integrity" }
				}
			}
		},
		"hostCapabilities": { "$ref": "host-capabilities.schema.json" },
		"limits": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"maxNodes",
				"maxDepth",
				"maxSlotsPerNode",
				"maxChildrenPerSlot",
				"maxPropertyBytes",
				"maxExtensionBytes",
				"maxCommandBatch",
				"maxHistoryEntries",
				"maxRichTextBytes",
				"maxRichTextDepth",
				"maxPreviewRequestsPerMinute",
				"maxPreviewBytes",
				"maxMediaUploadBytes",
				"maxMediaBatch",
				"maxPluginCount",
				"maxContributionsPerPlugin",
				"maxLocaleBytes"
			],
			"properties": {
				"maxNodes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e5
				},
				"maxDepth": {
					"type": "integer",
					"minimum": 1,
					"maximum": 128
				},
				"maxSlotsPerNode": {
					"type": "integer",
					"minimum": 0,
					"maximum": 100
				},
				"maxChildrenPerSlot": {
					"type": "integer",
					"minimum": 0,
					"maximum": 1e4
				},
				"maxPropertyBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 10485760
				},
				"maxExtensionBytes": {
					"type": "integer",
					"minimum": 0,
					"maximum": 10485760
				},
				"maxCommandBatch": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e4
				},
				"maxHistoryEntries": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e5
				},
				"maxRichTextBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 10485760
				},
				"maxRichTextDepth": {
					"type": "integer",
					"minimum": 1,
					"maximum": 128
				},
				"maxPreviewRequestsPerMinute": {
					"type": "integer",
					"minimum": 1,
					"maximum": 6e4
				},
				"maxPreviewBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 52428800
				},
				"maxMediaUploadBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1099511627776
				},
				"maxMediaBatch": {
					"type": "integer",
					"minimum": 1,
					"maximum": 1e3
				},
				"maxPluginCount": {
					"type": "integer",
					"minimum": 0,
					"maximum": 100
				},
				"maxContributionsPerPlugin": {
					"type": "integer",
					"minimum": 0,
					"maximum": 5e3
				},
				"maxLocaleBytes": {
					"type": "integer",
					"minimum": 1,
					"maximum": 104857600
				}
			}
		},
		"features": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"executablePlugins",
				"customInspectors",
				"externalMediaImport",
				"clipboardMediaUpload",
				"collaboration",
				"offlineRecovery"
			],
			"properties": {
				"executablePlugins": { "type": "boolean" },
				"customInspectors": { "type": "boolean" },
				"externalMediaImport": { "type": "boolean" },
				"clipboardMediaUpload": { "type": "boolean" },
				"collaboration": { "type": "boolean" },
				"offlineRecovery": { "type": "boolean" }
			}
		},
		"preview": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"enabled",
				"sameOriginRequired",
				"allowApproximateRenderer"
			],
			"properties": {
				"enabled": { "type": "boolean" },
				"sameOriginRequired": { "type": "boolean" },
				"allowApproximateRenderer": { "type": "boolean" },
				"initialViewport": { "$ref": "common.schema.json#/$defs/localName" }
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	},
	allOf: [{
		"if": {
			"properties": { "composite": { "const": "hybrid" } },
			"required": ["composite"]
		},
		"then": { "properties": { "mode": { "enum": ["blueprint", "content"] } } }
	}]
};
var studio_chart_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-chart.schema.json",
	title: "Studio canonical chart",
	type: "object",
	additionalProperties: false,
	required: [
		"type",
		"labels",
		"datasets"
	],
	properties: {
		"type": { "enum": [
			"bar",
			"doughnut",
			"line",
			"pie"
		] },
		"title": {
			"type": "string",
			"maxLength": 500
		},
		"labels": {
			"type": "array",
			"maxItems": 200,
			"items": {
				"type": "string",
				"maxLength": 500
			}
		},
		"datasets": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": ["label", "values"],
				"properties": {
					"label": {
						"type": "string",
						"maxLength": 500
					},
					"values": {
						"type": "array",
						"maxItems": 200,
						"items": {
							"type": "number",
							"minimum": -0x38d7ea4c68000,
							"maximum": 0x38d7ea4c68000
						}
					}
				}
			}
		}
	}
};
var studio_drawing_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-drawing.schema.json",
	title: "Studio canonical drawing",
	type: "object",
	additionalProperties: false,
	required: [
		"width",
		"height",
		"alt",
		"strokes"
	],
	properties: {
		"width": {
			"type": "integer",
			"minimum": 1,
			"maximum": 4096
		},
		"height": {
			"type": "integer",
			"minimum": 1,
			"maximum": 4096
		},
		"alt": {
			"type": "string",
			"minLength": 1,
			"maxLength": 5e3
		},
		"strokes": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"color",
					"width",
					"points"
				],
				"properties": {
					"color": {
						"type": "string",
						"pattern": "^(?:#[0-9A-Fa-f]{6}|[a-z][a-z0-9-]{0,62}/[a-z][a-z0-9-]{0,62})$",
						"maxLength": 127
					},
					"width": {
						"type": "number",
						"minimum": .25,
						"maximum": 64
					},
					"points": {
						"type": "array",
						"minItems": 1,
						"maxItems": 1e4,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["x", "y"],
							"properties": {
								"x": {
									"type": "number",
									"minimum": 0,
									"maximum": 4096
								},
								"y": {
									"type": "number",
									"minimum": 0,
									"maximum": 4096
								}
							}
						}
					}
				}
			}
		}
	}
};
var studio_money_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-money.schema.json",
	title: "Studio canonical money",
	type: "object",
	additionalProperties: false,
	required: ["amount", "currency"],
	properties: {
		"amount": {
			"type": "string",
			"pattern": "^-?(?:0|[1-9][0-9]{0,17})(?:\\.[0-9]{1,6})?$",
			"maxLength": 26
		},
		"currency": {
			"type": "string",
			"pattern": "^[A-Z]{3}$"
		}
	}
};
var studio_presentation_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-presentation.schema.json",
	title: "Studio presentation intent",
	type: "object",
	maxProperties: 12,
	additionalProperties: false,
	properties: {
		"align": { "enum": [
			"center",
			"end",
			"start",
			"stretch"
		] },
		"animation": { "enum": [
			"fade",
			"none",
			"parallax",
			"scale",
			"slide"
		] },
		"height": { "enum": [
			"auto",
			"content",
			"full",
			"viewport"
		] },
		"inverse": { "type": "boolean" },
		"margin": { "enum": [
			"comfortable",
			"compact",
			"none",
			"spacious"
		] },
		"marker": { "enum": [
			"check",
			"decimal",
			"disc",
			"none"
		] },
		"padding": { "enum": [
			"comfortable",
			"compact",
			"none",
			"spacious"
		] },
		"position": { "enum": [
			"flow",
			"relative",
			"sticky"
		] },
		"print": { "enum": [
			"hide",
			"only",
			"show"
		] },
		"scrolling": { "enum": [
			"auto",
			"clip",
			"snap",
			"visible"
		] },
		"visibility": {
			"type": "object",
			"maxProperties": 3,
			"additionalProperties": false,
			"properties": {
				"compact": { "enum": ["hidden", "visible"] },
				"expanded": { "enum": ["hidden", "visible"] },
				"medium": { "enum": ["hidden", "visible"] }
			}
		},
		"width": { "enum": [
			"auto",
			"content",
			"full"
		] }
	}
};
var studio_release_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-release.schema.json",
	title: "Studio coordinated release record",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"release",
		"packages",
		"protocolVersion",
		"corpusManifestDigest",
		"claimedProfiles"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "studio-release" },
		"release": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"packages": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"@kumwe/studio-core",
				"@kumwe/studio-media",
				"@kumwe/studio-preview",
				"@kumwe/studio-protocol",
				"@kumwe/studio-renderer-web",
				"@kumwe/studio-rich-text",
				"@kumwe/studio",
				"@kumwe/studio-testkit"
			],
			"properties": {
				"@kumwe/studio-core": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-media": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-preview": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-protocol": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-renderer-web": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-rich-text": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio": { "$ref": "common.schema.json#/$defs/semanticVersion" },
				"@kumwe/studio-testkit": { "$ref": "common.schema.json#/$defs/semanticVersion" }
			}
		},
		"protocolVersion": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"corpusManifestDigest": { "$ref": "common.schema.json#/$defs/integrity" },
		"claimedProfiles": {
			"type": "array",
			"maxItems": 32,
			"uniqueItems": true,
			"items": { "$ref": "common.schema.json#/$defs/qualifiedName" }
		}
	}
};
var studio_table_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/studio-table.schema.json",
	title: "Studio table document",
	type: "object",
	additionalProperties: false,
	required: ["columns", "rows"],
	properties: {
		"caption": {
			"type": "string",
			"maxLength": 500
		},
		"columns": {
			"type": "array",
			"minItems": 1,
			"maxItems": 50,
			"items": {
				"type": "string",
				"maxLength": 500
			}
		},
		"rows": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "array",
				"minItems": 1,
				"maxItems": 50,
				"items": {
					"type": "string",
					"maxLength": 5e3
				}
			}
		}
	}
};
var theme_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/theme.schema.json",
	title: "Studio theme design profile",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"id",
		"version",
		"revision",
		"owner",
		"label",
		"viewports",
		"designControls",
		"recipes",
		"renderers",
		"blockSupport"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "theme" },
		"id": { "$ref": "common.schema.json#/$defs/stableId" },
		"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
		"revision": { "$ref": "common.schema.json#/$defs/revision" },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"label": { "$ref": "common.schema.json#/$defs/messageReference" },
		"description": { "$ref": "common.schema.json#/$defs/messageReference" },
		"viewports": {
			"type": "array",
			"minItems": 1,
			"maxItems": 20,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"label",
					"order",
					"base",
					"previewWidth"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"order": {
						"type": "integer",
						"minimum": 0,
						"maximum": 1e3
					},
					"base": { "type": "boolean" },
					"previewWidth": {
						"type": "integer",
						"minimum": 240,
						"maximum": 1e4
					}
				}
			}
		},
		"designControls": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"kind",
					"label",
					"choices"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"kind": { "enum": [
						"color-role",
						"typography-role",
						"spacing-role",
						"size-role",
						"radius-role",
						"shadow-role",
						"motion-role",
						"layer-role",
						"enum",
						"boolean",
						"integer"
					] },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"description": { "$ref": "common.schema.json#/$defs/messageReference" },
					"choices": {
						"type": "array",
						"minItems": 1,
						"maxItems": 500,
						"items": {
							"type": "object",
							"additionalProperties": false,
							"required": ["id", "label"],
							"properties": {
								"id": { "$ref": "common.schema.json#/$defs/localName" },
								"label": { "$ref": "common.schema.json#/$defs/messageReference" },
								"deprecated": { "type": "boolean" }
							}
						}
					}
				}
			}
		},
		"recipes": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"blockType",
					"label",
					"designValues"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/localName" },
					"blockType": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"label": { "$ref": "common.schema.json#/$defs/messageReference" },
					"designValues": {
						"type": "object",
						"maxProperties": 100,
						"propertyNames": { "$ref": "common.schema.json#/$defs/localName" },
						"additionalProperties": { "$ref": "common.schema.json#/$defs/jsonValue" }
					}
				}
			}
		},
		"renderers": {
			"type": "array",
			"minItems": 1,
			"maxItems": 100,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"id",
					"version",
					"surfaces",
					"exactPreview"
				],
				"properties": {
					"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"version": { "$ref": "common.schema.json#/$defs/semanticVersion" },
					"surfaces": {
						"type": "array",
						"minItems": 1,
						"maxItems": 20,
						"uniqueItems": true,
						"items": { "enum": [
							"web",
							"email",
							"document",
							"native",
							"preview"
						] }
					},
					"exactPreview": { "type": "boolean" }
				}
			}
		},
		"blockSupport": {
			"type": "array",
			"maxItems": 5e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"type",
					"versions",
					"renderer"
				],
				"properties": {
					"type": { "$ref": "common.schema.json#/$defs/qualifiedName" },
					"versions": { "$ref": "common.schema.json#/$defs/versionRange" },
					"renderer": { "$ref": "common.schema.json#/$defs/qualifiedName" }
				}
			}
		},
		"aliases": {
			"type": "array",
			"maxItems": 1e3,
			"items": {
				"type": "object",
				"additionalProperties": false,
				"required": [
					"kind",
					"from",
					"to",
					"equivalentMeaning"
				],
				"properties": {
					"kind": { "enum": [
						"viewport",
						"design-control",
						"choice",
						"recipe"
					] },
					"from": { "$ref": "common.schema.json#/$defs/localName" },
					"to": { "$ref": "common.schema.json#/$defs/localName" },
					"equivalentMeaning": {
						"type": "boolean",
						"const": true
					}
				}
			}
		},
		"extensions": { "$ref": "common.schema.json#/$defs/extensions" }
	}
};
var unresolved_contribution_schema_default = {
	$schema: "https://json-schema.org/draft/2020-12/schema",
	$id: "https://schemas.kumwe.org/studio/v1/unresolved-contribution.schema.json",
	title: "Studio unresolved contribution",
	type: "object",
	additionalProperties: false,
	required: [
		"contractVersion",
		"kind",
		"reference",
		"reason"
	],
	properties: {
		"contractVersion": { "$ref": "common.schema.json#/$defs/contractVersion" },
		"kind": { "const": "unresolved-contribution" },
		"reference": {
			"type": "object",
			"additionalProperties": false,
			"required": [
				"contribution",
				"id",
				"version"
			],
			"properties": {
				"contribution": { "enum": [
					"block",
					"command",
					"design-vocabulary",
					"field-adapter",
					"inspector",
					"locale",
					"migration",
					"panel",
					"pattern",
					"renderer-capability",
					"transform"
				] },
				"id": { "$ref": "common.schema.json#/$defs/qualifiedName" },
				"version": { "$ref": "common.schema.json#/$defs/semanticVersion" }
			}
		},
		"reason": { "enum": [
			"incompatible",
			"not-installed",
			"owner-disabled",
			"owner-revoked"
		] },
		"owner": { "$ref": "common.schema.json#/$defs/ownerReference" },
		"affectedNodes": {
			"type": "array",
			"maxItems": 1e4,
			"items": { "$ref": "common.schema.json#/$defs/stableId" }
		},
		"diagnostics": {
			"type": "array",
			"maxItems": 1e3,
			"items": { "$ref": "common.schema.json#/$defs/diagnostic" }
		}
	}
};
//#endregion
//#region node_modules/@kumwe/studio-protocol/dist/schemas.js
var authoringMessageCatalogSchema = authoring_message_catalog_schema_default;
var blockDefinitionSchema = block_definition_schema_default;
var bindingProjectionVectorSchema = binding_projection_vector_schema_default;
var authoringWebVectorSchema = authoring_web_vector_schema_default;
var blueprintSchema = blueprint_schema_default;
var commandSchema = command_schema_default;
var commandVectorSchema = command_vector_schema_default;
var commonSchema = common_schema_default;
var contentModelSchema = content_model_schema_default;
var corpusManifestSchema = corpus_manifest_schema_default;
var canonicalVectorSchema = canonical_vector_schema_default;
var designVocabularySchema = design_vocabulary_schema_default;
var entrySchema = entry_schema_default;
var fieldAdapterSchema = field_adapter_schema_default;
var hostCapabilitiesSchema = host_capabilities_schema_default;
var hostErrorSchema = host_error_schema_default;
var hostOperationsSchema = host_operations_schema_default;
var hostRequestSchema = host_request_schema_default;
var hostResultSchema = host_result_schema_default;
var hostVectorSchema = host_vector_schema_default;
var hostSequenceVectorSchema = host_sequence_vector_schema_default;
var inspectorSchema = inspector_schema_default;
var mediaAssetSchema = media_asset_schema_default;
var mediaReferenceSchema = media_reference_schema_default;
var mediaUploadGrantSchema = media_upload_grant_schema_default;
var mediaUploadSessionSchema = media_upload_session_schema_default;
var mediaVectorSchema = media_vector_schema_default;
var migrationSchema = migration_schema_default;
var patternSchema = pattern_schema_default;
var pluginManifestSchema = plugin_manifest_schema_default;
var previewMessageSchema = preview_message_schema_default;
var previewVectorSchema = preview_vector_schema_default;
var rendererWebVectorSchema = renderer_web_vector_schema_default;
var provenanceSchema = provenance_schema_default;
var richTextProjectionSchema = rich_text_projection_schema_default;
var richTextSchema = rich_text_schema_default;
var schemaProfileSchema = schema_profile_schema_default;
var schemaProfileVectorSchema = schema_profile_vector_schema_default;
var studioConfigurationSchema = studio_config_schema_default;
var studioChartSchema = studio_chart_schema_default;
var studioDrawingSchema = studio_drawing_schema_default;
var studioMoneySchema = studio_money_schema_default;
var studioPresentationSchema = studio_presentation_schema_default;
Object.freeze([
	commonSchema,
	authoringMessageCatalogSchema,
	authoringWebVectorSchema,
	blockDefinitionSchema,
	bindingProjectionVectorSchema,
	blueprintSchema,
	commandSchema,
	commandVectorSchema,
	canonicalVectorSchema,
	contentModelSchema,
	corpusManifestSchema,
	designVocabularySchema,
	entrySchema,
	fieldAdapterSchema,
	hostOperationsSchema,
	hostCapabilitiesSchema,
	hostErrorSchema,
	hostRequestSchema,
	hostResultSchema,
	hostVectorSchema,
	hostSequenceVectorSchema,
	inspectorSchema,
	mediaAssetSchema,
	mediaReferenceSchema,
	mediaUploadGrantSchema,
	mediaUploadSessionSchema,
	mediaVectorSchema,
	migrationSchema,
	patternSchema,
	pluginManifestSchema,
	previewMessageSchema,
	previewVectorSchema,
	rendererWebVectorSchema,
	provenanceSchema,
	richTextProjectionSchema,
	richTextSchema,
	schemaProfileSchema,
	schemaProfileVectorSchema,
	studioConfigurationSchema,
	studioChartSchema,
	studioDrawingSchema,
	studioMoneySchema,
	studioPresentationSchema,
	studio_release_schema_default,
	studio_table_schema_default,
	theme_schema_default,
	unresolved_contribution_schema_default
]);
//#endregion
//#region node_modules/@kumwe/studio-core/dist/profile-validator.js
/**
* An eval-free interpreting validator for the Studio Schema Profile.
*
* The Studio Schema Profile (docs/contracts/schema-profile.md,
* schemas/schema-profile.schema.json) deliberately bounds schemas to a closed
* keyword allowlist with complexity limits, which makes the profile small
* enough to interpret directly. This module walks the raw schema document at
* validation time instead of generating JavaScript, so validation runs under
* a Content-Security-Policy that forbids string-to-code compilation
* (`script-src 'self'` without `unsafe-eval`) and under Trusted Types.
*
* Supported keywords are exactly the profile's closed set — types, `enum`,
* `const`, `required`/`properties`/`additionalProperties`/`propertyNames`/
* `dependentRequired`, `items`/`prefixItems` and array bounds, string and
* number bounds, `allOf`/`anyOf`/`oneOf`/`not`/`if`/`then`/`else`,
* within-registry `$defs`/`$ref`, and the profile's annotations — plus two
* canonical-schema affordances the profile contract reserves for reviewed
* Studio schemas: `pattern` (see the ReDoS bound below) and a document-root
* `$id` so canonical documents can reference each other through the
* in-memory registry. Formats are not interpreted: the profile publishes no
* runtime `format` assertions, so the keyword is rejected at compile time
* exactly like every other keyword outside the allowlist.
*
* ReDoS bound: contributed schemas cannot carry `pattern` at all — the
* profile prohibits the keyword and `assertStudioPropertySchema` rejects it
* before this module ever sees a contributed document. Every regular
* expression interpreted here therefore comes from a reviewed lexical
* pattern in a canonical Studio schema. As defence in depth the compiler
* still enforces the profile's 500-character bound on lexical source text
* (the same bound the meta-schema places on `$ref`) and the profile's
* instance limits bound every string a pattern can be asked to match.
*
* The interpreter is pure and deterministic: no DOM, no Node APIs, no
* `Function` constructor, no shared mutable state beyond the per-validator
* error buffer that mirrors the previous compiled-validator shape. Public
* diagnostics are the ordered set of distinct failures; exact duplicates
* are collapsed so repeated reference fan-out cannot amplify output size.
*/
var DRAFT_2020_12$1 = "https://json-schema.org/draft/2020-12/schema";
var MAX_PATTERN_SOURCE_LENGTH = 500;
var MAX_REFERENCE_LENGTH$1 = 500;
var TYPE_NAMES = /* @__PURE__ */ new Set([
	"array",
	"boolean",
	"integer",
	"null",
	"number",
	"object",
	"string"
]);
var SUPPORTED_KEYWORDS = /* @__PURE__ */ new Set([
	"$defs",
	"$id",
	"$ref",
	"$schema",
	"additionalProperties",
	"allOf",
	"anyOf",
	"const",
	"default",
	"dependentRequired",
	"description",
	"else",
	"enum",
	"examples",
	"exclusiveMaximum",
	"exclusiveMinimum",
	"if",
	"items",
	"maxItems",
	"maxLength",
	"maxProperties",
	"maximum",
	"minItems",
	"minLength",
	"minProperties",
	"minimum",
	"multipleOf",
	"not",
	"oneOf",
	"pattern",
	"prefixItems",
	"properties",
	"propertyNames",
	"readOnly",
	"required",
	"then",
	"title",
	"type",
	"uniqueItems",
	"writeOnly"
]);
/**
* A compiled (pre-walked, pre-resolved) profile schema. `validate` mirrors
* the verdict-plus-`errors` shape of the code-generating validator it
* replaces: `errors` is `null` after a passing run and carries the ordered,
* distinct failures of the most recent failing run otherwise.
*/
var CompiledSchemaValidator = class {
	errors = null;
	#program;
	constructor(program) {
		this.#program = program;
	}
	validate(instance) {
		const errors = [];
		const valid = validateSubschema(this.#program.root, instance, "", errors, this.#program, /* @__PURE__ */ new Set(), /* @__PURE__ */ new WeakMap());
		const diagnostics = uniqueDiagnostics(errors);
		if (valid === diagnostics.length > 0) throw new TypeError("Schema validation verdict and diagnostics disagree.");
		this.errors = diagnostics.length > 0 ? diagnostics : null;
		return valid;
	}
};
/**
* Compiles a schema for interpretation: validates every keyword against the
* profile's closed allowlist and operand grammar, compiles bounded lexical
* patterns, and resolves every `$ref` against the in-memory registry. All
* structural errors surface here, at compile time, so validation itself is
* total. Throws `TypeError`/`RangeError` on any schema outside the profile.
*/
function compileProfileSchema(schema, options = {}) {
	if (!isSchemaNode(schema)) throw new TypeError("Schema root must be a plain JSON Schema object.");
	const documents = [];
	const documentsByUri = /* @__PURE__ */ new Map();
	const registerDocument = (root, requireId) => {
		const baseUri = root.$id;
		if (baseUri !== void 0 && typeof baseUri !== "string") throw new TypeError("Schema $id must be a string.");
		if (requireId && baseUri === void 0) throw new TypeError("Registry schema documents must declare a root $id.");
		const document = {
			baseUri,
			root,
			schemaPointers: /* @__PURE__ */ new Set()
		};
		if (baseUri !== void 0) {
			if (documentsByUri.has(baseUri)) throw new TypeError(`Schema registry declares ${baseUri} more than once.`);
			documentsByUri.set(baseUri, document);
		}
		documents.push(document);
		return document;
	};
	registerDocument(schema, false);
	for (const registered of options.schemas ?? []) {
		if (!isSchemaNode(registered)) throw new TypeError("Registry schema documents must be plain JSON Schema objects.");
		registerDocument(registered, true);
	}
	const patterns = /* @__PURE__ */ new WeakMap();
	const references = /* @__PURE__ */ new WeakMap();
	const sites = [];
	for (const document of documents) walkDocument(document, patterns, sites);
	for (const site of sites) references.set(site.node, resolveReferenceSite(site, documentsByUri));
	return new CompiledSchemaValidator({
		patterns,
		references,
		root: schema
	});
}
function walkDocument(document, patterns, sites) {
	const seen = /* @__PURE__ */ new WeakSet();
	const walkSchema = (value, pointer) => {
		const location = describeLocation(document, pointer);
		if (!isSchemaNode(value)) throw new TypeError(`${location} must be a plain JSON Schema object.`);
		if (seen.has(value)) throw new TypeError(`${location} reuses or cycles a schema object.`);
		seen.add(value);
		document.schemaPointers.add(pointer);
		for (const [keyword, operand] of sortedEntries(value)) {
			const keywordLocation = describeLocation(document, appendPointer$1(pointer, keyword));
			if (!SUPPORTED_KEYWORDS.has(keyword)) throw new TypeError(`${keywordLocation} uses keyword ${JSON.stringify(keyword)}, which the Studio schema interpreter does not support.`);
			switch (keyword) {
				case "$id":
					if (pointer !== "") throw new TypeError(`${keywordLocation} may only appear at the document root.`);
					break;
				case "$schema":
					if (operand !== DRAFT_2020_12$1) throw new TypeError(`${keywordLocation} must declare JSON Schema Draft 2020-12.`);
					break;
				case "$ref":
					if (typeof operand !== "string" || codePointLength$1(operand) > MAX_REFERENCE_LENGTH$1) throw new TypeError(`${keywordLocation} must be a string of at most ${MAX_REFERENCE_LENGTH$1} characters.`);
					sites.push({
						document,
						node: value,
						pointer,
						reference: operand
					});
					break;
				case "$defs":
				case "properties":
					walkSchemaMap(operand, appendPointer$1(pointer, keyword));
					break;
				case "additionalProperties":
				case "else":
				case "if":
				case "items":
				case "not":
				case "propertyNames":
				case "then":
					walkSubschema(operand, appendPointer$1(pointer, keyword));
					break;
				case "allOf":
				case "anyOf":
				case "oneOf":
				case "prefixItems":
					walkSchemaArray(operand, appendPointer$1(pointer, keyword));
					break;
				case "type":
					assertTypeOperand(operand, keywordLocation);
					break;
				case "enum":
					if (!isDenseArray$1(operand) || operand.length === 0) throw new TypeError(`${keywordLocation} must be a dense, non-empty JSON array.`);
					for (let index = 0; index < operand.length; index += 1) if (operand.slice(0, index).some((member) => deepEqual(member, operand[index]))) throw new TypeError(`${keywordLocation} must contain unique JSON values.`);
					break;
				case "examples":
					if (!isDenseArray$1(operand)) throw new TypeError(`${keywordLocation} must be a dense JSON array.`);
					break;
				case "const":
				case "default": break;
				case "required":
					assertNameArray(operand, keywordLocation);
					break;
				case "dependentRequired":
					if (!isSchemaNode(operand)) throw new TypeError(`${keywordLocation} must be an object of property-name arrays.`);
					for (const [name, dependents] of sortedEntries(operand)) assertNameArray(dependents, `${keywordLocation}.${name}`);
					break;
				case "maxItems":
				case "maxLength":
				case "maxProperties":
				case "minItems":
				case "minLength":
				case "minProperties":
					if (typeof operand !== "number" || !Number.isInteger(operand) || operand < 0) throw new TypeError(`${keywordLocation} must be a non-negative integer.`);
					break;
				case "exclusiveMaximum":
				case "exclusiveMinimum":
				case "maximum":
				case "minimum":
					if (typeof operand !== "number" || !Number.isFinite(operand)) throw new TypeError(`${keywordLocation} must be a finite number.`);
					break;
				case "multipleOf":
					if (typeof operand !== "number" || !Number.isFinite(operand) || operand <= 0) throw new TypeError(`${keywordLocation} must be a finite number greater than zero.`);
					break;
				case "pattern":
					patterns.set(value, compilePattern(operand, keywordLocation));
					break;
				case "readOnly":
				case "uniqueItems":
				case "writeOnly":
					if (typeof operand !== "boolean") throw new TypeError(`${keywordLocation} must be a boolean.`);
					break;
				case "description":
				case "title":
					if (typeof operand !== "string") throw new TypeError(`${keywordLocation} must be a string.`);
					break;
				default: throw new TypeError(`${keywordLocation} is not interpretable.`);
			}
		}
	};
	const walkSubschema = (value, pointer) => {
		if (typeof value === "boolean") {
			document.schemaPointers.add(pointer);
			return;
		}
		walkSchema(value, pointer);
	};
	const walkSchemaMap = (value, pointer) => {
		if (!isSchemaNode(value)) throw new TypeError(`${describeLocation(document, pointer)} must be an object of schemas.`);
		for (const [name, member] of sortedEntries(value)) walkSubschema(member, appendPointer$1(pointer, name));
	};
	const walkSchemaArray = (value, pointer) => {
		if (!isDenseArray$1(value) || value.length === 0) throw new TypeError(`${describeLocation(document, pointer)} must be a dense, non-empty array of schemas.`);
		for (const [index, member] of value.entries()) walkSubschema(member, appendPointer$1(pointer, String(index)));
	};
	walkSchema(document.root, "");
}
function compilePattern(operand, location) {
	if (typeof operand !== "string" || codePointLength$1(operand) > MAX_PATTERN_SOURCE_LENGTH) throw new TypeError(`${location} must be a lexical pattern of at most ${MAX_PATTERN_SOURCE_LENGTH} characters.`);
	try {
		return new RegExp(operand, "u");
	} catch (error) {
		throw new TypeError(`${location} is not a valid Unicode regular expression.`, { cause: error });
	}
}
function assertTypeOperand(operand, location) {
	if (typeof operand === "string") {
		if (!TYPE_NAMES.has(operand)) throw new TypeError(`${location} names an unknown JSON Schema type.`);
		return;
	}
	if (!isDenseArray$1(operand) || operand.length === 0) throw new TypeError(`${location} must be a type name or a dense, non-empty array of them.`);
	const names = /* @__PURE__ */ new Set();
	for (const member of operand) {
		if (typeof member !== "string" || !TYPE_NAMES.has(member) || names.has(member)) throw new TypeError(`${location} must list unique, known JSON Schema type names.`);
		names.add(member);
	}
}
function assertNameArray(operand, location) {
	if (!isDenseArray$1(operand)) throw new TypeError(`${location} must be a dense array of property names.`);
	const names = /* @__PURE__ */ new Set();
	for (const member of operand) {
		if (typeof member !== "string" || names.has(member)) throw new TypeError(`${location} must list unique property-name strings.`);
		names.add(member);
	}
}
function resolveReferenceSite(site, documentsByUri) {
	const location = `${describeLocation(site.document, site.pointer)}/$ref`;
	const hashIndex = site.reference.indexOf("#");
	const uriPart = hashIndex === -1 ? site.reference : site.reference.slice(0, hashIndex);
	const fragment = hashIndex === -1 ? "" : site.reference.slice(hashIndex + 1);
	let target;
	if (uriPart === "") target = site.document;
	else {
		const resolved = resolveDocumentUri(site.document.baseUri, uriPart, location);
		const found = documentsByUri.get(resolved);
		if (found === void 0) throw new TypeError(`${location} references ${resolved}, which is not in the registry.`);
		target = found;
	}
	if (fragment !== "" && !fragment.startsWith("/")) throw new TypeError(`${location} must use a JSON Pointer fragment.`);
	const tokens = fragment === "" ? [] : fragment.slice(1).split("/").map((token) => unescapeToken(token, location));
	const canonical = tokens.map((token) => `/${escapeToken(token)}`).join("");
	if (canonical !== "" && !target.schemaPointers.has(canonical)) throw new TypeError(`${location} does not reference a schema position.`);
	let current = target.root;
	for (const token of tokens) if (Array.isArray(current)) {
		const index = Number(token);
		if (!Number.isInteger(index) || index < 0 || index >= current.length) throw new TypeError(`${location} does not resolve to a schema.`);
		current = current[index];
	} else if (isSchemaNode(current) && Object.hasOwn(current, token)) current = current[token];
	else throw new TypeError(`${location} does not resolve to a schema.`);
	if (typeof current === "boolean" || isSchemaNode(current)) return current;
	throw new TypeError(`${location} does not resolve to a schema.`);
}
function resolveDocumentUri(base, uriPart, location) {
	if (/^[A-Za-z][A-Za-z0-9+.-]*:/u.test(uriPart)) return uriPart;
	if (base === void 0) throw new TypeError(`${location} uses a relative reference without a document base URI.`);
	if (uriPart.startsWith("/") || uriPart.split("/").some((segment) => segment === ".." || segment === ".")) throw new TypeError(`${location} must stay within the schema registry root.`);
	return base.slice(0, base.lastIndexOf("/") + 1) + uriPart;
}
function validateSubschema(schema, instance, path, errors, program, active, memo) {
	if (typeof schema === "boolean") {
		if (!schema) errors.push({
			instancePath: path,
			keyword: "false",
			message: "boolean schema is false"
		});
		return schema;
	}
	const cached = memoizedVerdict(memo, schema, path, instance);
	if (cached !== void 0) {
		for (const diagnostic of cached.diagnostics) errors.push({ ...diagnostic });
		return cached.valid;
	}
	if (active.has(schema)) throw new RangeError("Schema evaluation cycled without consuming instance input.");
	active.add(schema);
	const firstNewError = errors.length;
	let valid;
	try {
		valid = validateSchemaNode(schema, instance, path, errors, program, active, memo);
	} finally {
		active.delete(schema);
	}
	const diagnostics = uniqueDiagnostics(errors.slice(firstNewError));
	if (valid === diagnostics.length > 0) throw new TypeError("Subschema validation verdict and diagnostics disagree.");
	memoizeVerdict(memo, schema, path, instance, {
		diagnostics,
		valid
	});
	return valid;
}
function validateSchemaNode(schema, instance, path, errors, program, active, memo) {
	let valid = true;
	const fail = (keyword, message, at = path) => {
		valid = false;
		errors.push({
			instancePath: at,
			keyword,
			message
		});
	};
	if (schema.$ref !== void 0) {
		const target = program.references.get(schema);
		if (target === void 0) throw new TypeError("Schema reference was not resolved at compile time.");
		if (!validateSubschema(target, instance, path, errors, program, active, memo)) valid = false;
	}
	const typeOperand = schema.type;
	if (typeof typeOperand === "string") {
		if (!matchesType(typeOperand, instance)) fail("type", `must be ${typeOperand}`);
	} else if (Array.isArray(typeOperand)) {
		if (!typeOperand.some((name) => typeof name === "string" && matchesType(name, instance))) fail("type", `must be ${typeOperand.join(",")}`);
	}
	if (schema.enum !== void 0 && Array.isArray(schema.enum)) {
		if (!schema.enum.some((member) => deepEqual(member, instance))) fail("enum", "must be equal to one of the allowed values");
	}
	if (Object.hasOwn(schema, "const") && !deepEqual(schema.const, instance)) fail("const", "must be equal to constant");
	validateCombinators(schema, instance, path, errors, program, active, memo, fail);
	if (typeof instance === "string") validateStringKeywords(schema, instance, fail, program);
	else if (typeof instance === "number" && Number.isFinite(instance)) validateNumberKeywords(schema, instance, fail);
	else if (Array.isArray(instance)) {
		if (!validateArrayKeywords(schema, instance, path, errors, program, memo, fail)) valid = false;
	} else if (isObjectInstance(instance)) {
		if (!validateObjectKeywords(schema, instance, path, errors, program, memo, fail)) valid = false;
	}
	return valid;
}
function validateCombinators(schema, instance, path, errors, program, active, memo, fail) {
	const speculate = (subschema) => {
		return validateSubschema(subschema, instance, path, [], program, active, memo);
	};
	if (Array.isArray(schema.allOf)) {
		for (const member of schema.allOf) if (!validateSubschema(member, instance, path, errors, program, active, memo)) fail("allOf", "must match all schemas in allOf");
	}
	if (Array.isArray(schema.anyOf)) {
		if (!schema.anyOf.some((member) => speculate(member))) fail("anyOf", "must match a schema in anyOf");
	}
	if (Array.isArray(schema.oneOf)) {
		let matches = 0;
		for (const member of schema.oneOf) if (speculate(member) && (matches += 1) > 1) break;
		if (matches !== 1) fail("oneOf", "must match exactly one schema in oneOf");
	}
	if (schema.not !== void 0 && speculate(schema.not)) fail("not", "must NOT be valid");
	if (schema.if !== void 0) {
		const branch = speculate(schema.if) ? schema.then : schema.else;
		if (branch !== void 0 && !validateSubschema(branch, instance, path, errors, program, active, memo)) fail("if", "must match the conditional schema");
	}
}
function validateStringKeywords(schema, instance, fail, program) {
	const minLength = schema.minLength;
	const maxLength = schema.maxLength;
	if (typeof minLength === "number" || typeof maxLength === "number") {
		const length = codePointLength$1(instance);
		if (typeof minLength === "number" && length < minLength) fail("minLength", `must NOT have fewer than ${minLength} characters`);
		if (typeof maxLength === "number" && length > maxLength) fail("maxLength", `must NOT have more than ${maxLength} characters`);
	}
	if (typeof schema.pattern === "string") {
		const pattern = program.patterns.get(schema);
		if (pattern === void 0) throw new TypeError("Schema pattern was not compiled.");
		if (!pattern.test(instance)) fail("pattern", `must match pattern "${schema.pattern}"`);
	}
}
function validateNumberKeywords(schema, instance, fail) {
	if (typeof schema.minimum === "number" && instance < schema.minimum) fail("minimum", `must be >= ${schema.minimum}`);
	if (typeof schema.maximum === "number" && instance > schema.maximum) fail("maximum", `must be <= ${schema.maximum}`);
	if (typeof schema.exclusiveMinimum === "number" && instance <= schema.exclusiveMinimum) fail("exclusiveMinimum", `must be > ${schema.exclusiveMinimum}`);
	if (typeof schema.exclusiveMaximum === "number" && instance >= schema.exclusiveMaximum) fail("exclusiveMaximum", `must be < ${schema.exclusiveMaximum}`);
	if (typeof schema.multipleOf === "number") {
		if (!isCanonicalDecimalMultiple(instance, schema.multipleOf)) fail("multipleOf", `must be multiple of ${schema.multipleOf}`);
	}
}
function isCanonicalDecimalMultiple(instance, divisor) {
	const value = canonicalDecimal(instance);
	const multiple = canonicalDecimal(divisor);
	const exponentDifference = value.exponent - multiple.exponent;
	if (exponentDifference >= 0) return value.coefficient * 10n ** BigInt(exponentDifference) % multiple.coefficient === 0n;
	return value.coefficient % (multiple.coefficient * 10n ** BigInt(-exponentDifference)) === 0n;
}
function canonicalDecimal(value) {
	const source = JSON.stringify(Object.is(value, -0) ? 0 : value);
	const match = /^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/u.exec(source);
	if (match === null) throw new TypeError("Canonical decimal conversion requires a finite number.");
	const fraction = match[3] ?? "";
	let coefficient = BigInt(`${match[1] ?? ""}${match[2]}${fraction}`);
	let exponent = Number(match[4] ?? 0) - fraction.length;
	while (coefficient !== 0n && coefficient % 10n === 0n) {
		coefficient /= 10n;
		exponent += 1;
	}
	return {
		coefficient,
		exponent
	};
}
function validateArrayKeywords(schema, instance, path, errors, program, memo, fail) {
	let valid = true;
	const child = (subschema, index) => {
		if (!validateSubschema(subschema, instance[index], `${path}/${index}`, errors, program, /* @__PURE__ */ new Set(), memo)) valid = false;
	};
	const prefixItems = Array.isArray(schema.prefixItems) ? schema.prefixItems : void 0;
	const prefixLength = prefixItems?.length ?? 0;
	if (prefixItems !== void 0) for (let index = 0; index < Math.min(prefixLength, instance.length); index += 1) child(prefixItems[index], index);
	const items = schema.items;
	if (items !== void 0 && instance.length > prefixLength) {
		if (items === false) fail("items", `must NOT have more than ${prefixLength} items`);
		else if (items !== true) for (let index = prefixLength; index < instance.length; index += 1) child(items, index);
	}
	if (typeof schema.minItems === "number" && instance.length < schema.minItems) fail("minItems", `must NOT have fewer than ${schema.minItems} items`);
	if (typeof schema.maxItems === "number" && instance.length > schema.maxItems) fail("maxItems", `must NOT have more than ${schema.maxItems} items`);
	if (schema.uniqueItems === true) {
		const duplicate = findDuplicateIndexes(instance);
		if (duplicate !== void 0) fail("uniqueItems", `must NOT have duplicate items (items ## ${duplicate[0]} and ${duplicate[1]} are identical)`);
	}
	return valid;
}
function validateObjectKeywords(schema, instance, path, errors, program, memo, fail) {
	let valid = true;
	const memberNames = Object.keys(instance).filter((name) => instance[name] !== void 0).sort(compareCodeUnits$3);
	const present = (name) => Object.hasOwn(instance, name) && instance[name] !== void 0;
	const properties = isSchemaNode(schema.properties) ? schema.properties : void 0;
	if (properties !== void 0) {
		for (const [name, subschema] of sortedEntries(properties)) if (present(name) && !validateSubschema(subschema, instance[name], `${path}/${escapeToken(name)}`, errors, program, /* @__PURE__ */ new Set(), memo)) valid = false;
	}
	if (Array.isArray(schema.required)) {
		for (const name of sortedStrings(schema.required)) if (!present(name)) fail("required", `must have required property '${name}'`);
	}
	const additional = schema.additionalProperties;
	if (additional !== void 0) for (const name of memberNames) {
		if (properties !== void 0 && Object.hasOwn(properties, name)) continue;
		if (additional === false) fail("additionalProperties", "must NOT have additional properties");
		else if (additional !== true && !validateSubschema(additional, instance[name], `${path}/${escapeToken(name)}`, errors, program, /* @__PURE__ */ new Set(), memo)) valid = false;
	}
	const propertyNames = schema.propertyNames;
	if (propertyNames !== void 0) {
		for (const name of memberNames) if (!validateSubschema(propertyNames, name, path, [], program, /* @__PURE__ */ new Set(), memo)) fail("propertyNames", `property name '${name}' is invalid`);
	}
	const dependentRequired = schema.dependentRequired;
	if (isSchemaNode(dependentRequired)) for (const [trigger, dependents] of sortedEntries(dependentRequired)) {
		if (!present(trigger) || !Array.isArray(dependents)) continue;
		for (const dependent of sortedStrings(dependents)) if (!present(dependent)) fail("dependentRequired", `must have property ${dependent} when property ${trigger} is present`);
	}
	if (typeof schema.minProperties === "number" && memberNames.length < schema.minProperties) fail("minProperties", `must NOT have fewer than ${schema.minProperties} properties`);
	if (typeof schema.maxProperties === "number" && memberNames.length > schema.maxProperties) fail("maxProperties", `must NOT have more than ${schema.maxProperties} properties`);
	return valid;
}
function memoizedVerdict(memo, schema, path, instance) {
	return (memo.get(schema)?.get(path))?.get(instance);
}
function memoizeVerdict(memo, schema, path, instance, result) {
	let locations = memo.get(schema);
	if (locations === void 0) {
		locations = /* @__PURE__ */ new Map();
		memo.set(schema, locations);
	}
	let instances = locations.get(path);
	if (instances === void 0) {
		instances = /* @__PURE__ */ new Map();
		locations.set(path, instances);
	}
	instances.set(instance, result);
}
function uniqueDiagnostics(errors) {
	const keys = /* @__PURE__ */ new Set();
	const diagnostics = [];
	for (const error of errors) {
		const key = JSON.stringify([
			error.instancePath,
			error.keyword,
			error.message
		]);
		if (keys.has(key)) continue;
		keys.add(key);
		diagnostics.push({ ...error });
	}
	return diagnostics;
}
function matchesType(name, instance) {
	switch (name) {
		case "array": return Array.isArray(instance);
		case "boolean": return typeof instance === "boolean";
		case "integer": return typeof instance === "number" && Number.isFinite(instance) && instance % 1 === 0;
		case "null": return instance === null;
		case "number": return typeof instance === "number" && Number.isFinite(instance);
		case "object": return isObjectInstance(instance);
		case "string": return typeof instance === "string";
		default: return false;
	}
}
function findDuplicateIndexes(instance) {
	for (let second = 1; second < instance.length; second += 1) for (let first = 0; first < second; first += 1) if (deepEqual(instance[second], instance[first])) return [first, second];
}
/** JSON-value deep equality (order-insensitive for objects). */
function deepEqual(a, b) {
	if (a === b) return true;
	if (Array.isArray(a) && Array.isArray(b)) {
		if (a.length !== b.length) return false;
		for (let index = 0; index < a.length; index += 1) if (!deepEqual(a[index], b[index])) return false;
		return true;
	}
	if (typeof a === "object" && typeof b === "object" && a !== null && b !== null && !Array.isArray(a) && !Array.isArray(b)) {
		const aKeys = Object.keys(a);
		const bRecord = b;
		if (aKeys.length !== Object.keys(b).length) return false;
		for (const key of aKeys) if (!Object.hasOwn(b, key) || !deepEqual(a[key], bRecord[key])) return false;
		return true;
	}
	return false;
}
/** String length in Unicode code points, as JSON Schema requires. */
function codePointLength$1(value) {
	let length = 0;
	for (let index = 0; index < value.length; index += 1) {
		length += 1;
		const code = value.charCodeAt(index);
		if (code >= 55296 && code <= 56319 && index + 1 < value.length) {
			if ((value.charCodeAt(index + 1) & 64512) === 56320) index += 1;
		}
	}
	return length;
}
function isObjectInstance(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
function isSchemaNode(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
function isDenseArray$1(value) {
	if (!Array.isArray(value)) return false;
	const keys = Object.keys(value);
	return keys.length === value.length && keys.every((key, index) => key === String(index));
}
function sortedEntries(value) {
	return Object.entries(value).sort(([left], [right]) => compareCodeUnits$3(left, right));
}
function sortedStrings(values) {
	const strings = [];
	for (const value of values) if (typeof value === "string") strings.push(value);
	return strings.sort(compareCodeUnits$3);
}
function compareCodeUnits$3(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
function appendPointer$1(pointer, token) {
	return `${pointer}/${escapeToken(token)}`;
}
function escapeToken(token) {
	return token.replaceAll("~", "~0").replaceAll("/", "~1");
}
function unescapeToken(token, location) {
	if (/(?:~[^01]|~$)/u.test(token)) throw new TypeError(`${location} is not a valid JSON Pointer reference.`);
	return token.replaceAll("~1", "/").replaceAll("~0", "~");
}
function describeLocation(document, pointer) {
	return `${document.baseUri ?? "schema"}#${pointer}`;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/schema-profile.js
var DRAFT_2020_12 = "https://json-schema.org/draft/2020-12/schema";
var MAX_ALTERNATIVES = 64;
var MAX_DESCRIPTION_LENGTH = 1e4;
var MAX_ENUM_MEMBERS = 1024;
var MAX_EXAMPLES = 100;
var MAX_JSON_DEPTH = 64;
var MAX_JSON_ITEMS = 1e4;
var MAX_JSON_PROPERTIES = 1e3;
var MAX_REFERENCES = 128;
var MAX_REFERENCE_LENGTH = 500;
var MAX_SCHEMA_BYTES = 262144;
var MAX_SCHEMA_DEPTH = 32;
var MAX_SCHEMA_MAP_PROPERTIES = 512;
var MAX_SCHEMA_NODES = 1024;
var MAX_OBJECT_KEY_LENGTH = 200;
var MAX_PROPERTY_NAMES = 512;
var MAX_TITLE_LENGTH = 1e3;
Object.freeze({
	maxAlternatives: MAX_ALTERNATIVES,
	maxDescriptionLength: MAX_DESCRIPTION_LENGTH,
	maxEnumMembers: MAX_ENUM_MEMBERS,
	maxExamples: MAX_EXAMPLES,
	maxJsonDepth: MAX_JSON_DEPTH,
	maxJsonItems: MAX_JSON_ITEMS,
	maxJsonProperties: MAX_JSON_PROPERTIES,
	maxObjectKeyLength: MAX_OBJECT_KEY_LENGTH,
	maxPropertyNames: MAX_PROPERTY_NAMES,
	maxReferenceLength: MAX_REFERENCE_LENGTH,
	maxReferences: MAX_REFERENCES,
	maxSchemaBytes: MAX_SCHEMA_BYTES,
	maxSchemaDepth: MAX_SCHEMA_DEPTH,
	maxSchemaMapProperties: MAX_SCHEMA_MAP_PROPERTIES,
	maxSchemaNodes: MAX_SCHEMA_NODES,
	maxTitleLength: MAX_TITLE_LENGTH
});
Object.freeze([
	"invalid-root",
	"unsupported-keyword",
	"invalid-keyword-value",
	"unsafe-member",
	"limit-exceeded",
	"invalid-reference",
	"recursive-schema"
]);
/** A deterministic admission failure suitable for cross-runtime corpus comparison. */
var StudioSchemaProfileError = class extends TypeError {
	code;
	/** JSON Pointer to the rejected schema location; the empty string is the root. */
	schemaPath;
	constructor(code, schemaPath, message, options) {
		super(message, options);
		this.name = "StudioSchemaProfileError";
		this.code = code;
		this.schemaPath = schemaPath;
	}
};
var allowedKeywords = /* @__PURE__ */ new Set([
	"$defs",
	"$ref",
	"$schema",
	"additionalProperties",
	"allOf",
	"anyOf",
	"const",
	"default",
	"dependentRequired",
	"description",
	"else",
	"enum",
	"examples",
	"exclusiveMaximum",
	"exclusiveMinimum",
	"if",
	"items",
	"maxItems",
	"maxLength",
	"maxProperties",
	"maximum",
	"minItems",
	"minLength",
	"minProperties",
	"minimum",
	"multipleOf",
	"not",
	"oneOf",
	"prefixItems",
	"properties",
	"propertyNames",
	"readOnly",
	"required",
	"then",
	"title",
	"type",
	"uniqueItems",
	"writeOnly"
]);
var typeNames = /* @__PURE__ */ new Set([
	"array",
	"boolean",
	"integer",
	"null",
	"number",
	"object",
	"string"
]);
var SchemaByteLimitError = class extends RangeError {};
var SchemaBytePreflightDeferred = class extends TypeError {};
/**
* Admit and compile one contributed block property schema. The alpha profile
* is deliberately object-rooted, closed, local-reference-only, non-recursive,
* and format-free. The returned interpreter performs no code generation.
*/
function compileStudioPropertySchema(schema) {
	if (!isRecord$5(schema)) reject("invalid-root", "", "Studio property schema root must be a JSON Schema object.");
	try {
		assertCanonicalSchemaByteBudget(schema);
	} catch (error) {
		if (error instanceof SchemaByteLimitError) reject("limit-exceeded", "", `Studio property schema exceeds ${MAX_SCHEMA_BYTES} canonical UTF-8 bytes.`);
		if (error instanceof SchemaBytePreflightDeferred) {} else reject("invalid-root", "", "Studio property schema must be a bounded canonical JSON document.", error);
	}
	const state = {
		references: 0,
		schemaNodes: 0,
		seen: /* @__PURE__ */ new WeakSet()
	};
	const admissionFailures = [];
	captureAdmissionFailure(() => visitSchema(schema, "", 1, state), admissionFailures);
	captureAdmissionFailure(() => assertNonRecursiveSchema(schema), admissionFailures);
	captureAdmissionFailure(() => assertClosedObjectRoot(schema), admissionFailures);
	const admissionFailure = firstAdmissionFailure(schema, admissionFailures);
	if (admissionFailure !== void 0) throw admissionFailure;
	try {
		return compileProfileSchema(schema);
	} catch (error) {
		reject("invalid-keyword-value", "", "Studio property schema does not compile under the strict profile.", error);
	}
}
/** Assert that a value is an admitted Studio property schema. */
function assertStudioPropertySchema(schema) {
	compileStudioPropertySchema(schema);
}
function visitSchema(value, path, depth, state) {
	if (!isRecord$5(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a JSON Schema object.`);
	trackObject(value, path, state);
	trackSchemaNode(path, depth, state);
	for (const [keyword, operand] of boundedSchemaEntries(value)) {
		const keywordPath = appendPointer(path, keyword);
		assertSafeObjectKey(keyword, path);
		if (!allowedKeywords.has(keyword)) reject("unsupported-keyword", keywordPath, `${displayPath(keywordPath)} uses keyword ${JSON.stringify(keyword)}, which is not allowed by the Studio Schema Profile.`);
		switch (keyword) {
			case "$defs":
			case "properties":
				visitSchemaMap(operand, keywordPath, depth + 1, state);
				break;
			case "additionalProperties":
			case "else":
			case "if":
			case "items":
			case "not":
			case "propertyNames":
			case "then":
				visitSubschema(operand, keywordPath, depth + 1, state);
				break;
			case "allOf":
			case "anyOf":
			case "oneOf":
			case "prefixItems":
				visitSchemaArray(operand, keywordPath, depth + 1, state);
				break;
			case "$ref":
				visitReference(operand, keywordPath, state);
				break;
			case "$schema":
				if (operand !== DRAFT_2020_12) reject("invalid-keyword-value", keywordPath, `${displayPath(keywordPath)} must declare JSON Schema Draft 2020-12.`);
				break;
			case "enum":
				visitEnum(operand, keywordPath, 1, state);
				break;
			case "examples":
				visitExamples(operand, keywordPath, 1, state);
				break;
			case "dependentRequired":
				visitDependentRequired(operand, keywordPath, state);
				break;
			case "required":
				visitNameArray(operand, keywordPath, MAX_PROPERTY_NAMES, state);
				break;
			case "type":
				visitType(operand, keywordPath, state);
				break;
			case "description":
				visitBoundedString(operand, keywordPath, MAX_DESCRIPTION_LENGTH);
				break;
			case "title":
				visitBoundedString(operand, keywordPath, MAX_TITLE_LENGTH);
				break;
			case "maxItems":
			case "maxLength":
			case "maxProperties":
			case "minItems":
			case "minLength":
			case "minProperties":
				visitNonNegativeInteger(operand, keywordPath);
				break;
			case "exclusiveMaximum":
			case "exclusiveMinimum":
			case "maximum":
			case "minimum":
				visitFiniteNumber(operand, keywordPath);
				break;
			case "multipleOf":
				visitFiniteNumber(operand, keywordPath);
				if (operand <= 0) reject("invalid-keyword-value", keywordPath, `${displayPath(keywordPath)} must be greater than zero.`);
				break;
			case "readOnly":
			case "uniqueItems":
			case "writeOnly":
				if (typeof operand !== "boolean") reject("invalid-keyword-value", keywordPath, `${displayPath(keywordPath)} must be a boolean.`);
				break;
			case "const":
			case "default": visitJsonValue(operand, keywordPath, 1, state);
		}
	}
}
function visitSchemaMap(value, path, depth, state) {
	if (!isRecord$5(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be an object of schemas.`);
	trackObject(value, path, state);
	const keys = Object.keys(value);
	if (keys.length > MAX_SCHEMA_MAP_PROPERTIES) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_SCHEMA_MAP_PROPERTIES} schema entries.`);
	for (const name of keys.sort(compareCodeUnits$2)) {
		assertSafeObjectKey(name, path);
		visitSchema(value[name], appendPointer(path, name), depth, state);
	}
}
function visitSchemaArray(value, path, depth, state) {
	if (!Array.isArray(value) || !isDenseArray(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a dense JSON array of schemas.`);
	if (value.length === 0) reject("invalid-keyword-value", path, `${displayPath(path)} must contain at least one schema.`);
	if (value.length > MAX_ALTERNATIVES) reject("limit-exceeded", path, `${displayPath(path)} must contain at most ${MAX_ALTERNATIVES} schemas.`);
	trackObject(value, path, state);
	for (const [index, schema] of value.entries()) visitSubschema(schema, appendPointer(path, String(index)), depth, state);
}
function visitSubschema(value, path, depth, state) {
	if (typeof value === "boolean") {
		trackSchemaNode(path, depth, state);
		return;
	}
	visitSchema(value, path, depth, state);
}
function trackSchemaNode(path, depth, state) {
	if (depth > MAX_SCHEMA_DEPTH) reject("limit-exceeded", path, `${displayPath(path)} exceeds the Studio Schema Profile depth limit.`);
	state.schemaNodes += 1;
	if (state.schemaNodes > MAX_SCHEMA_NODES) reject("limit-exceeded", path, `Studio property schema exceeds ${MAX_SCHEMA_NODES} schema nodes.`);
}
function visitReference(value, path, state) {
	if (!isPortableLocalReference(value)) reject("invalid-reference", path, `${displayPath(path)} must be a bounded local JSON Pointer reference.`);
	state.references += 1;
	if (state.references > MAX_REFERENCES) reject("limit-exceeded", path, `Studio property schema exceeds ${MAX_REFERENCES} references.`);
}
function visitEnum(value, path, depth, state) {
	if (!Array.isArray(value) || !isDenseArray(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a dense JSON array.`);
	if (value.length === 0) reject("invalid-keyword-value", path, `${displayPath(path)} must contain at least one value.`);
	if (value.length > MAX_ENUM_MEMBERS) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_ENUM_MEMBERS} members.`);
	trackObject(value, path, state);
	const members = /* @__PURE__ */ new Set();
	for (const [index, member] of value.entries()) {
		visitJsonValue(member, appendPointer(path, String(index)), depth, state);
		const canonical = canonicalStringify(member, { maximumDepth: 65 });
		if (members.has(canonical)) reject("invalid-keyword-value", appendPointer(path, String(index)), `${displayPath(path)} must contain unique JSON values.`);
		members.add(canonical);
	}
}
function visitExamples(value, path, depth, state) {
	if (!Array.isArray(value) || !isDenseArray(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a dense JSON array.`);
	if (value.length > MAX_EXAMPLES) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_EXAMPLES} examples.`);
	trackObject(value, path, state);
	for (const [index, example] of value.entries()) visitJsonValue(example, appendPointer(path, String(index)), depth, state);
}
function visitDependentRequired(value, path, state) {
	if (!isRecord$5(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be an object of property-name arrays.`);
	trackObject(value, path, state);
	const keys = Object.keys(value);
	if (keys.length > MAX_SCHEMA_MAP_PROPERTIES) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_SCHEMA_MAP_PROPERTIES} dependency entries.`);
	for (const name of keys.sort(compareCodeUnits$2)) {
		assertSafeObjectKey(name, path);
		visitNameArray(value[name], appendPointer(path, name), MAX_PROPERTY_NAMES, state);
	}
}
function visitNameArray(value, path, maximum, state) {
	if (!Array.isArray(value) || !isDenseArray(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a dense array of property names.`);
	if (value.length > maximum) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${maximum} property names.`);
	trackObject(value, path, state);
	const names = /* @__PURE__ */ new Set();
	for (const [index, name] of value.entries()) {
		if (typeof name !== "string") reject("invalid-keyword-value", appendPointer(path, String(index)), `${displayPath(path)} must contain only property-name strings.`);
		assertSafeObjectKey(name, path, appendPointer(path, String(index)));
		if (names.has(name)) reject("invalid-keyword-value", appendPointer(path, String(index)), `${displayPath(path)} must list unique property names.`);
		names.add(name);
	}
}
function visitType(value, path, state) {
	if (typeof value === "string") {
		if (!typeNames.has(value)) reject("invalid-keyword-value", path, `${displayPath(path)} names an unknown JSON Schema type.`);
		return;
	}
	if (!Array.isArray(value) || !isDenseArray(value) || value.length === 0 || value.length > 7) reject("invalid-keyword-value", path, `${displayPath(path)} must be a type name or a non-empty array of at most seven names.`);
	trackObject(value, path, state);
	const names = /* @__PURE__ */ new Set();
	for (const [index, name] of value.entries()) {
		if (typeof name !== "string" || !typeNames.has(name) || names.has(name)) reject("invalid-keyword-value", appendPointer(path, String(index)), `${displayPath(path)} must list unique, known JSON Schema type names.`);
		names.add(name);
	}
}
function visitBoundedString(value, path, maximum) {
	if (typeof value !== "string") reject("invalid-keyword-value", path, `${displayPath(path)} must be a string.`);
	if (codePointLength(value) > maximum) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${maximum} characters.`);
}
function visitNonNegativeInteger(value, path) {
	if (typeof value !== "number" || !Number.isInteger(value) || value < 0) reject("invalid-keyword-value", path, `${displayPath(path)} must be a non-negative integer.`);
}
function visitFiniteNumber(value, path) {
	if (typeof value !== "number" || !Number.isFinite(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a finite number.`);
}
function visitJsonValue(value, path, depth, state) {
	if (value === null || typeof value === "boolean" || typeof value === "string" || typeof value === "number" && Number.isFinite(value)) return;
	if (depth > MAX_JSON_DEPTH) reject("limit-exceeded", path, `${displayPath(path)} exceeds the Studio Schema Profile JSON depth limit.`);
	if (Array.isArray(value)) {
		if (!isDenseArray(value)) reject("invalid-keyword-value", path, `${displayPath(path)} must be a dense JSON array.`);
		trackObject(value, path, state);
		if (value.length > MAX_JSON_ITEMS) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_JSON_ITEMS} JSON items.`);
		for (const [index, entry] of value.entries()) visitJsonValue(entry, appendPointer(path, String(index)), depth + 1, state);
		return;
	}
	if (isRecord$5(value)) {
		trackObject(value, path, state);
		const keys = Object.keys(value);
		if (keys.length > MAX_JSON_PROPERTIES) reject("limit-exceeded", path, `${displayPath(path)} exceeds ${MAX_JSON_PROPERTIES} JSON properties.`);
		for (const key of keys.sort(compareCodeUnits$2)) {
			assertSafeObjectKey(key, path);
			visitJsonValue(value[key], appendPointer(path, key), depth + 1, state);
		}
		return;
	}
	reject("invalid-keyword-value", path, `${displayPath(path)} is not JSON-compatible.`);
}
function assertClosedObjectRoot(schema) {
	if (schema.additionalProperties !== false) reject("invalid-root", "/additionalProperties", "Studio property schema root must declare additionalProperties: false.");
	if (schema.type !== "object") reject("invalid-root", "/type", "Studio property schema root must declare exactly type \"object\".");
}
function captureAdmissionFailure(action, failures) {
	try {
		action();
	} catch (error) {
		if (error instanceof StudioSchemaProfileError) {
			failures.push(error);
			return;
		}
		throw error;
	}
}
function firstAdmissionFailure(root, failures) {
	let first;
	for (const failure of failures) if (first === void 0 || compareAdmissionPaths(root, failure.schemaPath, first.schemaPath) < 0) first = failure;
	return first;
}
/**
* Compare two diagnostic locations in the same order the admission grammar
* visits them: object members by UTF-16 code unit, array members by numeric
* index, and a container before any descendant. Missing root invariants are
* virtual object members, so they participate without a special-case pass
* precedence.
*/
function compareAdmissionPaths(root, left, right) {
	const leftTokens = pointerTokens(left);
	const rightTokens = pointerTokens(right);
	let parent = root;
	const sharedLength = Math.min(leftTokens.length, rightTokens.length);
	for (let index = 0; index < sharedLength; index += 1) {
		const leftToken = leftTokens[index];
		const rightToken = rightTokens[index];
		if (leftToken === void 0 || rightToken === void 0) break;
		if (leftToken !== rightToken) {
			if (Array.isArray(parent)) {
				const leftIndex = Number(leftToken);
				const rightIndex = Number(rightToken);
				if (Number.isSafeInteger(leftIndex) && Number.isSafeInteger(rightIndex)) return leftIndex - rightIndex;
			}
			return compareCodeUnits$2(leftToken, rightToken);
		}
		if ((isRecord$5(parent) || Array.isArray(parent)) && Object.hasOwn(parent, leftToken)) parent = parent[leftToken];
		else parent = void 0;
	}
	return leftTokens.length - rightTokens.length;
}
function pointerTokens(pointer) {
	if (pointer === "") return [];
	return pointer.slice(1).split("/").map((token) => token.replaceAll("~1", "/").replaceAll("~0", "~"));
}
function assertSafeObjectKey(key, path, rejectionPath = appendPointer(path, key)) {
	if (codePointLength(key) > MAX_OBJECT_KEY_LENGTH) reject("limit-exceeded", rejectionPath, `${displayPath(path)} contains an object member name longer than ${MAX_OBJECT_KEY_LENGTH} characters.`);
	if (key.length === 0 || key === "__proto__" || key === "constructor" || key === "prototype" || containsControlCharacter(key)) reject("unsafe-member", rejectionPath, `${displayPath(path)} contains forbidden object member name ${JSON.stringify(key)}.`);
}
function assertNonRecursiveSchema(root) {
	const failures = [];
	const indexes = /* @__PURE__ */ new Map();
	const adjacency = [];
	const reverseAdjacency = [];
	const referenceSites = [];
	const expanded = /* @__PURE__ */ new WeakSet();
	let eligibleReferences = 0;
	const appendGraphPath = (parent, token) => ({
		parent,
		token
	});
	const graphPathPointer = (path) => {
		const tokens = [];
		let current = path;
		while (current !== void 0) {
			tokens.push(current.token);
			current = current.parent;
		}
		let pointer = "";
		for (let index = tokens.length - 1; index >= 0; index -= 1) {
			const token = tokens[index];
			if (token !== void 0) pointer = appendPointer(pointer, token);
		}
		return pointer;
	};
	const ensureNode = (node) => {
		const existing = indexes.get(node);
		if (existing !== void 0) return existing;
		const index = adjacency.length;
		indexes.set(node, index);
		adjacency.push([]);
		reverseAdjacency.push([]);
		return index;
	};
	const connect = (source, target) => {
		adjacency[source]?.push(target);
		reverseAdjacency[target]?.push(source);
	};
	ensureNode(root);
	const stack = [{
		depth: 1,
		diagnosticsEligible: true,
		node: root,
		path: void 0
	}];
	while (stack.length > 0) {
		const frame = stack.pop();
		if (frame === void 0 || expanded.has(frame.node)) continue;
		expanded.add(frame.node);
		const source = ensureNode(frame.node);
		const children = [];
		const addChild = (value, path, diagnosticsEligible = frame.diagnosticsEligible) => {
			if (!isRecord$5(value)) return;
			const target = ensureNode(value);
			connect(source, target);
			const depth = frame.depth + 1;
			children.push({
				depth,
				diagnosticsEligible: diagnosticsEligible && depth <= MAX_SCHEMA_DEPTH,
				node: value,
				path
			});
		};
		for (const [keyword, operand] of boundedSchemaEntries(frame.node)) {
			const keywordPath = appendGraphPath(frame.path, keyword);
			switch (keyword) {
				case "$defs":
				case "properties":
					if (isRecord$5(operand)) {
						const names = Object.keys(operand);
						const childrenEligible = names.length <= MAX_SCHEMA_MAP_PROPERTIES;
						if (childrenEligible) names.sort(compareCodeUnits$2);
						for (const name of names) addChild(operand[name], appendGraphPath(keywordPath, name), frame.diagnosticsEligible && childrenEligible);
					}
					break;
				case "$ref":
					if (isPortableLocalReference(operand)) {
						const reportsDiagnostic = frame.diagnosticsEligible && (eligibleReferences += 1) <= MAX_REFERENCES;
						const referencePath = reportsDiagnostic ? graphPathPointer(keywordPath) : "";
						try {
							const target = resolveLocalReference(root, operand, referencePath);
							if (!target.schemaPosition) {
								if (reportsDiagnostic) failures.push(new StudioSchemaProfileError("invalid-reference", referencePath, `Local schema reference ${operand} does not resolve to a schema position.`));
							} else if (isRecord$5(target.value)) {
								const targetIndex = ensureNode(target.value);
								connect(source, targetIndex);
								if (reportsDiagnostic) referenceSites.push({
									path: referencePath,
									source,
									target: targetIndex
								});
							}
						} catch (error) {
							if (error instanceof StudioSchemaProfileError) {
								if (reportsDiagnostic) failures.push(error);
							} else throw error;
						}
					}
					break;
				case "additionalProperties":
				case "else":
				case "if":
				case "items":
				case "not":
				case "propertyNames":
				case "then":
					addChild(operand, keywordPath);
					break;
				case "allOf":
				case "anyOf":
				case "oneOf":
				case "prefixItems": if (Array.isArray(operand)) {
					const childrenEligible = operand.length > 0 && operand.length <= MAX_ALTERNATIVES && isDenseArray(operand);
					for (let index = 0; index < operand.length; index += 1) if (Object.hasOwn(operand, index)) addChild(operand[index], appendGraphPath(keywordPath, String(index)), frame.diagnosticsEligible && childrenEligible);
				}
			}
		}
		for (let index = children.length - 1; index >= 0; index -= 1) {
			const child = children[index];
			if (child !== void 0) stack.push(child);
		}
	}
	const components = stronglyConnectedComponents(adjacency, reverseAdjacency);
	for (const site of referenceSites) if (components[site.source] === components[site.target]) failures.push(new StudioSchemaProfileError("recursive-schema", site.path, "Recursive contributed schemas are not admitted by the alpha profile."));
	const failure = firstAdmissionFailure(root, failures);
	if (failure !== void 0) throw failure;
}
function stronglyConnectedComponents(adjacency, reverseAdjacency) {
	const visited = new Uint8Array(adjacency.length);
	const finishOrder = [];
	for (let start = 0; start < adjacency.length; start += 1) {
		if (visited[start] !== 0) continue;
		visited[start] = 1;
		const stack = [{
			edge: 0,
			node: start
		}];
		while (stack.length > 0) {
			const frame = stack[stack.length - 1];
			if (frame === void 0) break;
			const target = (adjacency[frame.node] ?? [])[frame.edge];
			if (target !== void 0) {
				frame.edge += 1;
				if (visited[target] === 0) {
					visited[target] = 1;
					stack.push({
						edge: 0,
						node: target
					});
				}
			} else {
				finishOrder.push(frame.node);
				stack.pop();
			}
		}
	}
	const components = new Int32Array(adjacency.length);
	components.fill(-1);
	let component = 0;
	for (let order = finishOrder.length - 1; order >= 0; order -= 1) {
		const start = finishOrder[order];
		if (start === void 0 || components[start] !== -1) continue;
		components[start] = component;
		const stack = [start];
		while (stack.length > 0) {
			const node = stack.pop();
			if (node === void 0) continue;
			for (const source of reverseAdjacency[node] ?? []) if (components[source] === -1) {
				components[source] = component;
				stack.push(source);
			}
		}
		component += 1;
	}
	return components;
}
function resolveLocalReference(root, reference, path) {
	if (reference === "#") return {
		schemaPosition: true,
		value: root
	};
	let current = root;
	let position = "schema";
	for (const encodedToken of reference.slice(2).split("/")) {
		const token = encodedToken.replaceAll("~1", "/").replaceAll("~0", "~");
		let nextPosition = "other";
		if (position === "schema" && isRecord$5(current)) switch (token) {
			case "$defs":
			case "properties":
				nextPosition = "schema-map";
				break;
			case "additionalProperties":
			case "else":
			case "if":
			case "items":
			case "not":
			case "propertyNames":
			case "then":
				nextPosition = "schema";
				break;
			case "allOf":
			case "anyOf":
			case "oneOf":
			case "prefixItems": nextPosition = "schema-array";
		}
		else if (position === "schema-map" && isRecord$5(current)) nextPosition = "schema";
		else if (position === "schema-array" && Array.isArray(current)) nextPosition = "schema";
		if (!isRecord$5(current) && !Array.isArray(current)) reject("invalid-reference", path, `Local schema reference ${reference} does not resolve to a schema.`);
		if (!Object.hasOwn(current, token)) reject("invalid-reference", path, `Local schema reference ${reference} does not resolve to a schema.`);
		current = current[token];
		position = nextPosition;
	}
	if (typeof current !== "boolean" && !isRecord$5(current)) reject("invalid-reference", path, `Local schema reference ${reference} does not resolve to a schema.`);
	return {
		schemaPosition: position === "schema",
		value: current
	};
}
function reject(code, schemaPath, message, cause) {
	throw new StudioSchemaProfileError(code, schemaPath, message, cause === void 0 ? void 0 : { cause });
}
function appendPointer(pointer, token) {
	return `${pointer}/${token.replaceAll("~", "~0").replaceAll("/", "~1")}`;
}
function displayPath(path) {
	return path === "" ? "schema root" : path;
}
function containsControlCharacter(value) {
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code <= 31 || code === 127) return true;
	}
	return false;
}
function isPortableLocalReference(value) {
	return typeof value === "string" && codePointLength(value) <= MAX_REFERENCE_LENGTH && !containsControlCharacter(value) && /^#(?:\/(?:[A-Za-z0-9._!$&'()*+,;=:@-]|~[01])*)*$/u.test(value);
}
function codePointLength(value) {
	let length = 0;
	for (let index = 0; index < value.length; index += 1) {
		length += 1;
		const code = value.charCodeAt(index);
		if (code >= 55296 && code <= 56319 && index + 1 < value.length) {
			if ((value.charCodeAt(index + 1) & 64512) === 56320) index += 1;
		}
	}
	return length;
}
/**
* Enforce the canonical byte ceiling without materialising or sorting the
* canonical document. Object order cannot change encoded length. This pass
* is iterative so an over-deep untrusted value cannot exhaust the JavaScript
* call stack before the published schema/JSON depth checks run.
*/
function assertCanonicalSchemaByteBudget(root) {
	const stack = [root];
	const seen = /* @__PURE__ */ new WeakSet();
	let bytes = 0;
	const consume = (amount) => {
		bytes += amount;
		if (bytes > MAX_SCHEMA_BYTES) throw new SchemaByteLimitError();
	};
	while (stack.length > 0) {
		const value = stack.pop();
		if (value === null) {
			consume(4);
			continue;
		}
		switch (typeof value) {
			case "boolean":
				consume(value ? 4 : 5);
				continue;
			case "number":
				if (!Number.isFinite(value)) throw new SchemaBytePreflightDeferred();
				consume(JSON.stringify(Object.is(value, -0) ? 0 : value).length);
				continue;
			case "string":
				consumeCanonicalJsonString(value, consume);
				continue;
			case "object": break;
			default: throw new SchemaBytePreflightDeferred();
		}
		if (seen.has(value)) throw new SchemaBytePreflightDeferred();
		seen.add(value);
		if (Array.isArray(value)) {
			const members = value;
			consume(2 + Math.max(0, members.length - 1));
			if (!isDenseArray(members)) throw new SchemaBytePreflightDeferred();
			for (let index = members.length - 1; index >= 0; index -= 1) {
				const member = members[index];
				if (member === void 0) throw new SchemaBytePreflightDeferred();
				stack.push(member);
			}
			continue;
		}
		if (!isRecord$5(value)) throw new SchemaBytePreflightDeferred();
		const keys = Object.keys(value);
		consume(2 + Math.max(0, keys.length - 1));
		for (let index = keys.length - 1; index >= 0; index -= 1) {
			const key = keys[index];
			if (key === void 0) continue;
			const member = value[key];
			if (member === void 0) throw new SchemaBytePreflightDeferred();
			consumeCanonicalJsonString(key, consume);
			consume(1);
			stack.push(member);
		}
	}
}
function consumeCanonicalJsonString(value, consume) {
	consume(2);
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code === 34 || code === 92 || code === 8 || code === 9 || code === 10 || code === 12 || code === 13) consume(2);
		else if (code <= 31) consume(6);
		else if (code <= 127) consume(1);
		else if (code <= 2047) consume(2);
		else if (code >= 55296 && code <= 56319) {
			const next = value.charCodeAt(index + 1);
			if (index + 1 < value.length && (next & 64512) === 56320) {
				consume(4);
				index += 1;
			} else consume(6);
		} else if (code >= 56320 && code <= 57343) consume(6);
		else consume(3);
	}
}
/**
* Sort at most the closed keyword set plus the first invalid member. This
* preserves deterministic first-error precedence without sorting an
* arbitrarily large attacker-controlled schema object.
*/
function boundedSchemaEntries(value) {
	const keys = Object.keys(value);
	if (keys.length <= allowedKeywords.size) return keys.sort(compareCodeUnits$2).map((key) => [key, value[key]]);
	const candidates = [];
	let firstInvalid;
	for (const key of keys) if (allowedKeywords.has(key)) candidates.push(key);
	else if (firstInvalid === void 0 || compareCodeUnits$2(key, firstInvalid) < 0) firstInvalid = key;
	if (firstInvalid !== void 0) candidates.push(firstInvalid);
	return candidates.sort(compareCodeUnits$2).map((key) => [key, value[key]]);
}
function isDenseArray(value) {
	const keys = Object.keys(value);
	return keys.length === value.length && keys.every((key, index) => key === String(index));
}
function compareCodeUnits$2(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
function trackObject(value, path, state) {
	if (state.seen.has(value)) reject("invalid-root", path, `${displayPath(path)} reuses or cycles a JSON object.`);
	state.seen.add(value);
}
function isRecord$5(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/registry.js
var BlockRegistry = class {
	#definitions = /* @__PURE__ */ new Map();
	constructor(definitions = []) {
		for (const definition of definitions) this.register(definition);
	}
	register(definition, options = {}) {
		assertStudioPropertySchema(definition.propertySchema);
		if (options.verifiedIntegrity !== void 0 && !isIntegrity(options.verifiedIntegrity)) throw new TypeError("Host-verified block integrity must be a canonical SRI sha256/384/512 value.");
		let versions = this.#definitions.get(definition.type);
		if (versions === void 0) {
			versions = /* @__PURE__ */ new Map();
			this.#definitions.set(definition.type, versions);
		}
		if (versions.has(definition.version)) throw new Error(`Block ${definition.type}@${definition.version} is already registered.`);
		const registration = { definition: cloneContractValue(definition) };
		if (options.verifiedIntegrity !== void 0) registration.verifiedIntegrity = options.verifiedIntegrity;
		versions.set(definition.version, registration);
	}
	resolve(type, version) {
		return this.resolveRegistration(type, version)?.definition;
	}
	resolveRegistration(type, version) {
		const registration = this.#definitions.get(type)?.get(version);
		if (registration === void 0) return;
		const resolved = { definition: cloneContractValue(registration.definition) };
		if (registration.verifiedIntegrity !== void 0) resolved.verifiedIntegrity = registration.verifiedIntegrity;
		return resolved;
	}
	definitions() {
		return [...this.#definitions.values()].flatMap((versions) => [...versions.values()]).map((registration) => cloneContractValue(registration.definition));
	}
};
function isIntegrity(value) {
	return /^(?:sha256-[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=|sha384-[A-Za-z0-9+/]{64}|sha512-[A-Za-z0-9+/]{85}[AQgw]==)(?![\s\S])/u.test(value);
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/contributions.js
var StudioContributionError = class extends Error {
	diagnostics;
	constructor(message, diagnostics) {
		super(message);
		this.name = "StudioContributionError";
		this.diagnostics = diagnostics;
	}
};
var contributionValidators = {
	block: compileProfileSchema(blockDefinitionSchema, { schemas: [commonSchema] }),
	"design-vocabulary": compileProfileSchema(designVocabularySchema, { schemas: [commonSchema] }),
	"field-adapter": compileProfileSchema(fieldAdapterSchema, { schemas: [commonSchema] }),
	inspector: compileProfileSchema(inspectorSchema, { schemas: [commonSchema] }),
	migration: compileProfileSchema(migrationSchema, { schemas: [commonSchema] }),
	pattern: compileProfileSchema(patternSchema, { schemas: [commonSchema, blueprintSchema] })
};
/**
* One immutable, resolvable registry generation. A generation never changes
* after publication; lifecycle transitions publish a successor instead.
*/
var RegistryGeneration = class {
	#contributions;
	#generation;
	#owners;
	#registry;
	constructor(generation, owners, registry, contributions = []) {
		this.#contributions = new Map(contributions.map((entry) => [contributionKey(entry.kind, entry.id, entry.version), cloneContractValue(entry.payload)]));
		this.#generation = generation;
		this.#owners = owners;
		this.#registry = registry;
	}
	get generation() {
		return this.#generation;
	}
	get registry() {
		return this.#registry;
	}
	blocks() {
		return this.#registry.definitions();
	}
	owners() {
		return this.#owners.map((owner) => cloneContractValue(owner));
	}
	resolveBlock(type, version) {
		return this.#registry.resolve(type, version);
	}
	/** Resolve one of the six canonical composition payload kinds by exact identity. */
	resolveContribution(kind, id, version) {
		if (kind === "block") return this.resolveBlock(id, version);
		const payload = this.#contributions.get(contributionKey(kind, id, version));
		return payload === void 0 ? void 0 : cloneContractValue(payload);
	}
	/** Enumerate one canonical kind in deterministic identity/version order. */
	contributions(kind) {
		if (kind === "block") return this.blocks();
		const prefix = `${kind}\u0000`;
		return [...this.#contributions.entries()].filter(([key]) => key.startsWith(prefix)).sort(([left], [right]) => left.localeCompare(right)).map(([, payload]) => cloneContractValue(payload));
	}
};
var SealedBlockRegistry = class extends BlockRegistry {
	#sealed = false;
	seal() {
		this.#sealed = true;
	}
	register(...parameters) {
		if (this.#sealed) throw new Error("A published registry generation is immutable.");
		super.register(...parameters);
	}
};
/**
* The owner-aware contribution runtime. Activation is transactional: a
* rejected activation publishes no partial generation and never disturbs a
* previously active extension. Every successful transition publishes a new
* immutable generation; disabling an owner removes its executable
* contributions from resolution without touching stored documents, and a
* stale generation cannot be used for execution.
*/
var ContributionRuntime = class {
	#extensions = /* @__PURE__ */ new Map();
	#current;
	constructor(options) {
		this.#current = this.#publish(options.generation);
	}
	get current() {
		return this.#current;
	}
	activate(owner, contributions, options) {
		const candidate = normalizeContributions(contributions);
		const diagnostics = this.#collectActivationDiagnostics(owner, candidate);
		if (diagnostics.length > 0) {
			if (!this.#extensions.has(owner.id)) this.#extensions.set(owner.id, {
				contributions: normalizeContributions({ blocks: [] }),
				diagnostics,
				owner: cloneContractValue(owner),
				state: "rejected"
			});
			throw new StudioContributionError(`Activation of ${owner.id} was rejected: ${diagnostics.map((diagnostic) => diagnostic.code).join(", ")}.`, diagnostics);
		}
		this.#extensions.set(owner.id, {
			contributions: candidate,
			diagnostics: [],
			owner: cloneContractValue(owner),
			state: "active"
		});
		this.#current = this.#publish(options.generation);
		return this.#current;
	}
	disable(ownerId, options) {
		const record = this.#requireExtension(ownerId);
		record.state = "disabled";
		this.#current = this.#publish(options.generation);
		return this.#current;
	}
	reactivate(ownerId, options) {
		const record = this.#requireExtension(ownerId);
		if (record.state === "trust-revoked") throw new StudioContributionError(`Extension ${ownerId} is trust-revoked and requires a fresh verified activation.`, record.diagnostics);
		record.state = "active";
		this.#current = this.#publish(options.generation);
		return this.#current;
	}
	revokeTrust(ownerId, options) {
		const record = this.#requireExtension(ownerId);
		record.state = "trust-revoked";
		this.#current = this.#publish(options.generation);
		return this.#current;
	}
	assertCurrent(generation) {
		if (this.#current.generation !== generation) throw new StudioCommandError("stale-generation", `Registry generation ${generation} is stale; the active generation is ${this.#current.generation}.`);
		return this.#current;
	}
	inventory() {
		return [...this.#extensions.values()].map((record) => ({
			diagnostics: cloneContractValue(record.diagnostics),
			owner: cloneContractValue(record.owner),
			state: record.state
		})).sort((left, right) => left.owner.id < right.owner.id ? -1 : 1);
	}
	unresolvedNodes(document) {
		const unresolved = [];
		const stack = [...document.roots].reverse();
		while (stack.length > 0) {
			const node = stack.pop();
			if (node === void 0) break;
			if (this.#current.resolveBlock(node.type, node.version) === void 0) {
				const { owner, reason } = this.#unresolvedReason(node.type, node.version);
				const report = {
					nodeId: node.id,
					reason,
					type: node.type,
					version: node.version
				};
				if (owner !== void 0) report.owner = cloneContractValue(owner);
				unresolved.push(report);
			}
			for (const children of Object.values(node.slots)) stack.push(...[...children].reverse());
		}
		return unresolved;
	}
	/**
	* The portable unresolved-contribution documents for a Blueprint: one per
	* unresolved block type and version, carrying the affected nodes and a
	* localizable diagnostic, without interpreting node data through another
	* owner.
	*/
	unresolvedContributions(document) {
		const grouped = /* @__PURE__ */ new Map();
		for (const report of this.unresolvedNodes(document)) {
			const key = `${report.type}@${report.version}`;
			let entry = grouped.get(key);
			if (entry === void 0) {
				entry = {
					affectedNodes: [],
					contractVersion: STUDIO_CONTRACT_VERSION,
					diagnostics: [{
						code: "studio.validation/block-unavailable",
						message: {
							defaultMessage: `The ${report.type} block is currently unavailable; its content is preserved.`,
							key: "studio.validation/block-unavailable"
						},
						severity: "warning"
					}],
					kind: "unresolved-contribution",
					reason: report.reason,
					reference: {
						contribution: "block",
						id: report.type,
						version: report.version
					}
				};
				if (report.owner !== void 0) entry.owner = report.owner;
				grouped.set(key, entry);
			}
			entry.affectedNodes?.push(report.nodeId);
		}
		return [...grouped.values()];
	}
	/**
	* Resolve the lifecycle reason for any canonical contribution reference.
	* Identity is kind-scoped, so a pattern can never satisfy a field-adapter
	* reference merely because their IDs and versions match.
	*/
	unresolvedReference(reference) {
		if (!isCompositionContributionKind(reference.contribution)) return { reason: "not-installed" };
		if (this.#current.resolveContribution(reference.contribution, reference.id, reference.version) !== void 0) return;
		return this.#unresolvedContributionReason(reference.contribution, reference.id, reference.version);
	}
	#unresolvedReason(type, version) {
		return this.#unresolvedContributionReason("block", type, version);
	}
	#unresolvedContributionReason(kind, id, version) {
		for (const record of this.#extensions.values()) {
			const versions = contributionEntries(record.contributions).filter((entry) => entry.kind === kind && entry.id === id).map((entry) => entry.version);
			if (versions.length === 0) continue;
			if (!versions.includes(version)) return {
				owner: record.owner,
				reason: "incompatible"
			};
			if (record.state === "trust-revoked") return {
				owner: record.owner,
				reason: "owner-revoked"
			};
			return {
				owner: record.owner,
				reason: "owner-disabled"
			};
		}
		return { reason: "not-installed" };
	}
	#collectActivationDiagnostics(owner, candidate) {
		const diagnostics = [];
		const seen = /* @__PURE__ */ new Set();
		for (const entry of contributionEntries(candidate)) {
			if (entry.owner.id !== owner.id || entry.owner.version !== owner.version) {
				diagnostics.push(activationDiagnostic("studio.contribution/owner-mismatch", `${entry.kind} ${entry.id} declares owner ${entry.owner.id}@${entry.owner.version}.`));
				continue;
			}
			const key = contributionKey(entry.kind, entry.id, entry.version);
			if (seen.has(key)) {
				diagnostics.push(activationDiagnostic("studio.contribution/duplicate-contribution", `${entry.kind} ${entry.id}@${entry.version} is contributed twice by ${owner.id}.`));
				continue;
			}
			seen.add(key);
			const conflictingOwner = this.#ownerOfContribution(entry.kind, entry.id, owner.id);
			if (conflictingOwner !== void 0) diagnostics.push(activationDiagnostic("studio.contribution/cross-owner-collision", `${entry.kind} ${entry.id} is owned by ${conflictingOwner}.`));
			const validator = contributionValidators[entry.kind];
			if (!validator.validate(entry.payload)) {
				const first = validator.errors?.[0];
				diagnostics.push(activationDiagnostic("studio.contribution/invalid-definition", `${entry.kind} ${entry.id}@${entry.version} ${first?.instancePath ?? "document"} ${first?.message ?? "violates its canonical schema"}.`));
			}
		}
		if (diagnostics.length === 0) try {
			const dryRun = new SealedBlockRegistry();
			for (const record of this.#extensions.values()) {
				if (record.state !== "active" || record.owner.id === owner.id) continue;
				for (const block of record.contributions.blocks) dryRun.register(cloneContractValue(block));
			}
			for (const block of candidate.blocks) dryRun.register(cloneContractValue(block));
			for (const adapter of candidate.fieldAdapters) if (adapter.optionSchema !== void 0) assertStudioPropertySchema(adapter.optionSchema);
		} catch (error) {
			diagnostics.push(activationDiagnostic("studio.contribution/invalid-definition", error instanceof Error ? error.message : "A contributed definition is invalid."));
		}
		return diagnostics;
	}
	#ownerOfContribution(kind, id, exceptOwnerId) {
		for (const record of this.#extensions.values()) {
			if (record.owner.id === exceptOwnerId) continue;
			if (record.state === "purged" || record.state === "uninstalled-data-preserved") continue;
			if (contributionEntries(record.contributions).some((entry) => entry.kind === kind && entry.id === id)) return record.owner.id;
		}
	}
	#publish(generation) {
		const registry = new SealedBlockRegistry();
		const owners = [];
		const contributions = [];
		for (const record of this.#extensions.values()) {
			if (record.state !== "active") continue;
			owners.push(cloneContractValue(record.owner));
			for (const block of record.contributions.blocks) registry.register(cloneContractValue(block));
			contributions.push(...contributionEntries(record.contributions));
		}
		registry.seal();
		return new RegistryGeneration(generation, owners, registry, contributions);
	}
	#requireExtension(ownerId) {
		const record = this.#extensions.get(ownerId);
		if (record === void 0) throw new StudioContributionError(`Extension ${ownerId} is not known to the contribution runtime.`, []);
		return record;
	}
};
function normalizeContributions(contributions) {
	return {
		blocks: cloneContractValue(contributions.blocks),
		designVocabularies: cloneContractValue(contributions.designVocabularies ?? []),
		fieldAdapters: cloneContractValue(contributions.fieldAdapters ?? []),
		inspectors: cloneContractValue(contributions.inspectors ?? []),
		migrations: cloneContractValue(contributions.migrations ?? []),
		patterns: cloneContractValue(contributions.patterns ?? [])
	};
}
function contributionEntries(contributions) {
	return [
		...contributions.blocks.map((payload) => ({
			id: payload.type,
			kind: "block",
			owner: payload.owner,
			payload,
			version: payload.version
		})),
		...contributions.designVocabularies.map((payload) => ({
			id: payload.id,
			kind: "design-vocabulary",
			owner: payload.owner,
			payload,
			version: payload.version
		})),
		...contributions.fieldAdapters.map((payload) => ({
			id: payload.id,
			kind: "field-adapter",
			owner: payload.owner,
			payload,
			version: payload.version
		})),
		...contributions.inspectors.map((payload) => ({
			id: payload.id,
			kind: "inspector",
			owner: payload.owner,
			payload,
			version: payload.version
		})),
		...contributions.migrations.map((payload) => ({
			id: payload.id,
			kind: "migration",
			owner: payload.owner,
			payload,
			version: payload.version
		})),
		...contributions.patterns.map((payload) => ({
			id: payload.id,
			kind: "pattern",
			owner: payload.owner,
			payload,
			version: payload.version
		}))
	];
}
function contributionKey(kind, id, version) {
	return `${kind}\u0000${id}\u0000${version}`;
}
function isCompositionContributionKind(kind) {
	return kind === "block" || kind === "design-vocabulary" || kind === "field-adapter" || kind === "inspector" || kind === "migration" || kind === "pattern";
}
function activationDiagnostic(code, message) {
	return {
		code,
		message: {
			defaultMessage: message,
			key: "studio.contribution/activation"
		},
		severity: "blocking"
	};
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/entry-commands.js
function applyEntryCommand(entry, command) {
	if (command.artifactId !== entry.id) throw new StudioCommandError("node-not-found", `Command targets ${command.artifactId}, not entry ${entry.id}.`);
	if (command.payload.locale !== void 0 && entry.locale !== void 0 && command.payload.locale !== entry.locale) throw new StudioCommandError("locale-mismatch", `Command targets locale ${command.payload.locale}, but the entry stores ${entry.locale}.`);
	const next = cloneContractValue(entry);
	const path = command.payload.fieldPath;
	let container = next.values;
	for (const [index, segment] of path.entries()) {
		if (index === path.length - 1) {
			setOwnMember(container, segment, cloneContractValue(command.payload.value));
			break;
		}
		const child = Object.hasOwn(container, segment) ? container[segment] : void 0;
		if (child === null || typeof child !== "object" || Array.isArray(child)) throw new StudioCommandError("property-not-found", `Field path segment ${segment} does not resolve to an object value.`);
		container = child;
	}
	return next;
}
function setOwnMember(container, member, value) {
	Object.defineProperty(container, member, {
		configurable: true,
		enumerable: true,
		value,
		writable: true
	});
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/history.js
var StudioHistory = class {
	#maximumEntries;
	#current;
	#future = [];
	#past = [];
	#stateVersion = 0;
	constructor(document, maximumEntries = 100) {
		if (!Number.isInteger(maximumEntries) || maximumEntries < 1) throw new RangeError("History maximum must be a positive integer.");
		this.#current = cloneContractValue(document);
		this.#maximumEntries = maximumEntries;
	}
	get canRedo() {
		return this.#future.length > 0;
	}
	get canUndo() {
		return this.#past.length > 0;
	}
	get current() {
		return cloneContractValue(this.#current);
	}
	get stateVersion() {
		return this.#stateVersion;
	}
	/**
	* Advances the host-authored revision carried by every local snapshot.
	*
	* A successful optimistic save establishes one new base revision for the
	* complete local timeline. Rebasing the current, past, and future snapshots
	* keeps later commands and preview staging on that base without fabricating
	* an edit, changing the history topology, or advancing `stateVersion`.
	*/
	rebaseRevision(revision) {
		const rebase = (document) => ({
			...document,
			revision
		});
		this.#current = rebase(this.#current);
		this.#past = this.#past.map(rebase);
		this.#future = this.#future.map(rebase);
		return this.current;
	}
	execute(command) {
		if (command.baseStateVersion !== this.#stateVersion) throw new StudioCommandError("stale-state", `Command state ${command.baseStateVersion} does not match ${this.#stateVersion}.`);
		const next = applyCommand(this.#current, command);
		this.#past.push(this.#current);
		if (this.#past.length > this.#maximumEntries) this.#past.shift();
		this.#current = next;
		this.#future = [];
		this.#stateVersion += 1;
		return this.current;
	}
	redo() {
		const next = this.#future.pop();
		if (next === void 0) return this.current;
		this.#past.push(this.#current);
		this.#current = next;
		this.#stateVersion += 1;
		return this.current;
	}
	undo() {
		const previous = this.#past.pop();
		if (previous === void 0) return this.current;
		this.#future.push(this.#current);
		this.#current = previous;
		this.#stateVersion += 1;
		return this.current;
	}
};
//#endregion
//#region node_modules/@kumwe/studio-core/dist/layout.js
/** The portable layout block family shipped by Studio. */
var CORE_LAYOUT_BLOCK_TYPES = Object.freeze({
	columns: "studio.core/columns",
	grid: "studio.core/grid",
	section: "studio.core/section",
	stack: "studio.core/stack"
});
/** Canonical theme-control identifiers understood by every core layout block. */
var CORE_LAYOUT_THEME_CONTROLS = Object.freeze({
	alignment: "layout-alignment",
	collapse: "layout-collapse",
	direction: "layout-direction",
	spacing: "layout-spacing",
	visibility: "layout-visibility"
});
var DEFAULT_RENDERER_REQUIREMENTS = Object.freeze([{
	capability: "studio.renderer/layout",
	surface: "preview",
	versions: "^1.0.0"
}, {
	capability: "studio.renderer/layout",
	surface: "web",
	versions: "^1.0.0"
}]);
var ALIGNMENTS = [
	"center",
	"end",
	"start",
	"stretch"
];
var COLLAPSE_BEHAVIOURS = [
	"preserve",
	"stack",
	"wrap"
];
var DIRECTIONS = ["block", "inline"];
var SPACING_ROLES = [
	"comfortable",
	"compact",
	"none",
	"spacious"
];
var VISIBILITY_ROLES = ["hidden", "visible"];
/** True only for the four canonical Studio layout block types. */
function isCoreLayoutBlockType(type) {
	return Object.values(CORE_LAYOUT_BLOCK_TYPES).includes(type);
}
/**
* Creates the canonical section, stack, grid, and columns definitions. The
* family owns its bounded semantic properties while the host explicitly adds
* content block types and trusted renderer capabilities; no wildcard slot or
* renderer authority is invented.
*/
function createCoreLayoutBlockDefinitions(options = {}) {
	const acceptedTypes = stableUniqueBlockTypes([...Object.values(CORE_LAYOUT_BLOCK_TYPES), ...options.acceptedChildTypes ?? []]);
	const rendererRequirements = cloneContractValue(options.rendererRequirements ?? DEFAULT_RENDERER_REQUIREMENTS);
	if (rendererRequirements.length === 0) throw new RangeError("Core layout blocks require at least one trusted renderer capability.");
	return [
		definition("section", acceptedTypes, rendererRequirements),
		definition("stack", acceptedTypes, rendererRequirements),
		definition("grid", acceptedTypes, rendererRequirements),
		definition("columns", acceptedTypes, rendererRequirements)
	];
}
/** Minimal persisted properties for a newly inserted core layout node. */
function coreLayoutInitialProperties(type) {
	switch (type) {
		case CORE_LAYOUT_BLOCK_TYPES.section: return {};
		case CORE_LAYOUT_BLOCK_TYPES.stack: return { direction: "block" };
		case CORE_LAYOUT_BLOCK_TYPES.grid:
		case CORE_LAYOUT_BLOCK_TYPES.columns: return {
			collapse: "stack",
			columns: 1
		};
	}
}
function definition(name, acceptedTypes, rendererRequirements) {
	const type = CORE_LAYOUT_BLOCK_TYPES[name];
	const title = `${name.charAt(0).toUpperCase()}${name.slice(1)}`;
	const themeControls = [
		CORE_LAYOUT_THEME_CONTROLS.alignment,
		CORE_LAYOUT_THEME_CONTROLS.spacing,
		CORE_LAYOUT_THEME_CONTROLS.visibility
	];
	if (name === "stack") themeControls.push(CORE_LAYOUT_THEME_CONTROLS.direction);
	if (name === "grid" || name === "columns") themeControls.push(CORE_LAYOUT_THEME_CONTROLS.collapse);
	return {
		accessibility: {
			accessibleName: name === "section" ? "derived" : "not-applicable",
			category: name === "section" ? "landmark" : "structural",
			keyboard: {
				defaultMessage: "Use the outline commands to insert, move, and reorder layout children.",
				key: "studio.blocks/layout-keyboard"
			},
			outputChecks: ["studio.check/reading-order", "studio.check/reflow"],
			reducedMotion: "not-applicable"
		},
		category: "studio.category/layout",
		contractVersion: STUDIO_CONTRACT_VERSION,
		editingModes: ["blueprint", "content"],
		icon: {
			kind: "symbol",
			value: name
		},
		kind: "block-definition",
		label: {
			defaultMessage: title,
			key: `studio.blocks/${name}`
		},
		owner: {
			id: "studio.core/blocks",
			version: "1.0.0"
		},
		ports: [],
		propertyControls: themeControls.map((control) => ({
			control: `studio.control/${control}`,
			property: propertyForControl(control)
		})),
		propertySchema: propertySchema(name),
		rendererRequirements: cloneContractValue([...rendererRequirements]),
		revision: `layout-${name}-r1`,
		slots: [layoutSlot(name, acceptedTypes)],
		themeControls,
		type,
		version: "1.0.0"
	};
}
function layoutSlot(name, acceptedTypes) {
	const id = name === "section" ? "content" : "items";
	return {
		accepts: { types: cloneContractValue(acceptedTypes) },
		id,
		label: {
			defaultMessage: name === "section" ? "Content" : "Items",
			key: name === "section" ? "studio.blocks/section-content" : "studio.blocks/layout-items"
		},
		maximum: 100,
		minimum: 0,
		ordered: true
	};
}
function propertySchema(name) {
	const properties = {
		alignment: { enum: [...ALIGNMENTS] },
		spacing: { enum: [...SPACING_ROLES] },
		visibility: { enum: [...VISIBILITY_ROLES] }
	};
	if (name === "stack") properties.direction = { enum: [...DIRECTIONS] };
	if (name === "grid" || name === "columns") {
		properties.collapse = { enum: [...COLLAPSE_BEHAVIOURS] };
		properties.columns = {
			maximum: 12,
			minimum: 1,
			type: "integer"
		};
	}
	return {
		additionalProperties: false,
		properties,
		type: "object"
	};
}
function propertyForControl(control) {
	switch (control) {
		case CORE_LAYOUT_THEME_CONTROLS.alignment: return "alignment";
		case CORE_LAYOUT_THEME_CONTROLS.collapse: return "collapse";
		case CORE_LAYOUT_THEME_CONTROLS.direction: return "direction";
		case CORE_LAYOUT_THEME_CONTROLS.spacing: return "spacing";
		case CORE_LAYOUT_THEME_CONTROLS.visibility: return "visibility";
		default: throw new RangeError(`Unknown core layout control ${control}.`);
	}
}
function stableUniqueBlockTypes(values) {
	const unique = [...new Set(values)];
	unique.sort((left, right) => left < right ? -1 : left > right ? 1 : 0);
	if (unique.length === 0) throw new RangeError("A core layout slot requires at least one accepted block type.");
	return unique;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/production.js
var CORE_PRODUCTION_BLOCK_TYPES = Object.freeze({
	...CORE_LAYOUT_BLOCK_TYPES,
	accordion: "studio.core/accordion",
	accordionItem: "studio.core/accordion-item",
	article: "studio.core/article",
	attachment: "studio.core/attachment",
	audio: "studio.core/audio",
	badge: "studio.core/badge",
	callToAction: "studio.core/call-to-action",
	callout: "studio.core/callout",
	card: "studio.core/card",
	chart: "studio.core/chart",
	code: "studio.core/code",
	contentCollection: "studio.core/content-collection",
	contentReference: "studio.core/content-reference",
	countdown: "studio.core/countdown",
	cover: "studio.core/cover",
	descriptionItem: "studio.core/description-item",
	descriptionList: "studio.core/description-list",
	diagram: "studio.core/diagram",
	dialog: "studio.core/dialog",
	divider: "studio.core/divider",
	drawing: "studio.core/drawing",
	embed: "studio.core/embed",
	gallery: "studio.core/gallery",
	heading: "studio.core/heading",
	icon: "studio.core/icon",
	image: "studio.core/image",
	label: "studio.core/label",
	math: "studio.core/math",
	money: "studio.core/money",
	navigation: "studio.core/navigation",
	navigationItem: "studio.core/navigation-item",
	notice: "studio.core/notice",
	popover: "studio.core/popover",
	progress: "studio.core/progress",
	richText: "studio.core/rich-text",
	search: "studio.core/search",
	spinner: "studio.core/spinner",
	tab: "studio.core/tab",
	table: "studio.core/table",
	tabs: "studio.core/tabs",
	video: "studio.core/video"
});
var CORE_PRODUCTION_CONTROL_IDS = Object.freeze({
	chart: "studio.control/chart",
	drawing: "studio.control/drawing",
	mediaCollection: "studio.control/media-collection",
	mediaReference: "studio.control/media-reference",
	money: "studio.control/money",
	presentation: "studio.control/presentation",
	richText: "studio.control/rich-text",
	scopedCss: "studio.control/scoped-css",
	source: "studio.control/source",
	table: "studio.control/table"
});
Object.freeze([
	"studio.pattern/article",
	"studio.pattern/collection-index",
	"studio.pattern/document-header",
	"studio.pattern/faq",
	"studio.pattern/feature-grid",
	"studio.pattern/hero",
	"studio.pattern/media-gallery",
	"studio.pattern/pricing",
	"studio.pattern/product",
	"studio.pattern/tabbed-content"
]);
var VERSION = "1.0.0";
var OWNER = Object.freeze({
	id: "studio.core/blocks",
	version: VERSION
});
var WEB_RENDERERS = Object.freeze([{
	capability: "studio.renderer/semantic-web",
	surface: "preview",
	versions: "^1.0.0"
}, {
	capability: "studio.renderer/semantic-web",
	surface: "web",
	versions: "^1.0.0"
}]);
var ALL_TYPES = Object.freeze(Object.values(CORE_PRODUCTION_BLOCK_TYPES));
var CONTENT_TYPES = Object.freeze(ALL_TYPES.filter((type) => type !== CORE_PRODUCTION_BLOCK_TYPES.accordionItem && type !== CORE_PRODUCTION_BLOCK_TYPES.descriptionItem && type !== CORE_PRODUCTION_BLOCK_TYPES.navigationItem && type !== CORE_PRODUCTION_BLOCK_TYPES.tab));
var textPort = (id, required = false) => ({
	authoring: { control: "studio.control/single-line-text" },
	id,
	label: message(`port-${id}`, title(id)),
	multiple: false,
	required,
	valueType: "text"
});
var numberPort = (id, required = false) => ({
	authoring: { control: "studio.control/integer" },
	id,
	label: message(`port-${id}`, title(id)),
	multiple: false,
	required,
	valueType: "integer"
});
var richTextPort = (id = "content") => ({
	authoring: {
		control: CORE_PRODUCTION_CONTROL_IDS.richText,
		profile: "studio.rich-text/marketing"
	},
	id,
	label: message(`port-${id}`, title(id)),
	multiple: false,
	required: false,
	valueType: "rich-text"
});
var mediaPort = (id, multiple = false) => ({
	authoring: { control: multiple ? CORE_PRODUCTION_CONTROL_IDS.mediaCollection : CORE_PRODUCTION_CONTROL_IDS.mediaReference },
	id,
	label: message(`port-${id}`, title(id)),
	multiple,
	required: false,
	valueType: "media"
});
var sourcePort = (profile) => ({
	authoring: {
		control: CORE_PRODUCTION_CONTROL_IDS.source,
		profile
	},
	id: "source",
	label: message("port-source", "Source"),
	multiple: false,
	required: true,
	valueType: "text"
});
var resourcePort = (id, multiple) => ({
	authoring: { readOnly: true },
	id,
	label: message(`port-${id}`, title(id)),
	multiple,
	required: true,
	valueType: "resource"
});
var stringSchema = (maximum = 2e4) => ({
	maxLength: maximum,
	type: "string"
});
var booleanSchema = () => ({ type: "boolean" });
var enumSchema = (...values) => ({ enum: values });
var integerSchema = (minimum, maximum) => ({
	maximum,
	minimum,
	type: "integer"
});
var PRESENTATION_PROPERTY_SCHEMA = presentationPropertySchema();
var SPECS = Object.freeze({
	accordion: {
		accessibility: "composite",
		controls: { "allow-multiple": "studio.control/switch" },
		defaults: { "allow-multiple": false },
		properties: { "allow-multiple": booleanSchema() },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.accordionItem],
			id: "items",
			maximum: 50
		}]
	},
	accordionItem: {
		accessibility: "composite",
		controls: { expanded: "studio.control/switch" },
		defaults: { expanded: false },
		ports: [textPort("title", true)],
		properties: { expanded: booleanSchema() },
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 100
		}]
	},
	article: {
		accessibility: "landmark",
		defaults: {},
		ports: [textPort("title")],
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 200
		}]
	},
	attachment: {
		accessibility: "media",
		controls: { download: "studio.control/switch" },
		defaults: { download: true },
		ports: [mediaPort("asset"), textPort("label")],
		properties: { download: booleanSchema() }
	},
	audio: {
		accessibility: "media",
		controls: {
			autoplay: "studio.control/switch",
			controls: "studio.control/switch"
		},
		defaults: {
			autoplay: false,
			controls: true
		},
		ports: [mediaPort("asset"), textPort("transcript")],
		properties: {
			autoplay: booleanSchema(),
			controls: booleanSchema()
		}
	},
	badge: {
		accessibility: "text",
		controls: {
			appearance: "studio.control/select",
			tone: "studio.control/select"
		},
		defaults: {
			appearance: "solid",
			tone: "neutral"
		},
		ports: [textPort("label", true)],
		properties: {
			appearance: enumSchema("outline", "soft", "solid"),
			tone: enumSchema("error", "information", "neutral", "success", "warning")
		}
	},
	callToAction: {
		accessibility: "interactive",
		controls: {
			appearance: "studio.control/select",
			href: "studio.control/single-line-text"
		},
		defaults: {
			appearance: "primary",
			href: ""
		},
		ports: [textPort("label", true)],
		properties: {
			appearance: enumSchema("primary", "secondary", "link"),
			href: stringSchema(2048)
		}
	},
	callout: {
		accessibility: "composite",
		controls: { tone: "studio.control/select" },
		defaults: { tone: "information" },
		ports: [textPort("title"), richTextPort()],
		properties: { tone: enumSchema("information", "success", "warning", "danger") }
	},
	card: {
		accessibility: "composite",
		controls: { appearance: "studio.control/select" },
		defaults: { appearance: "plain" },
		ports: [
			mediaPort("media"),
			textPort("title"),
			richTextPort("summary")
		],
		properties: { appearance: enumSchema("plain", "bordered", "elevated") },
		slots: [{
			accepts: CONTENT_TYPES,
			id: "actions",
			maximum: 5
		}]
	},
	chart: {
		accessibility: "data-display",
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.chart,
				profile: "studio.chart/canonical"
			},
			id: "chart",
			label: message("port-chart", "Chart"),
			multiple: false,
			required: true,
			valueType: "studio.value/chart"
		}]
	},
	code: {
		accessibility: "text",
		controls: {
			language: "studio.control/single-line-text",
			"show-line-numbers": "studio.control/switch"
		},
		defaults: {
			language: "text",
			"show-line-numbers": false
		},
		ports: [sourcePort("studio.source/code")],
		properties: {
			language: stringSchema(100),
			"show-line-numbers": booleanSchema()
		}
	},
	contentCollection: {
		accessibility: "data-display",
		controls: {
			limit: "studio.control/integer",
			presentation: "studio.control/select"
		},
		defaults: {
			limit: 12,
			presentation: "cards"
		},
		ports: [resourcePort("items", true)],
		properties: {
			limit: integerSchema(1, 100),
			presentation: enumSchema("cards", "grid", "list", "slideshow")
		}
	},
	contentReference: {
		accessibility: "data-display",
		controls: { presentation: "studio.control/select" },
		defaults: { presentation: "summary" },
		ports: [resourcePort("item", false)],
		properties: { presentation: enumSchema("full", "summary", "title") }
	},
	countdown: {
		accessibility: "data-display",
		controls: {
			display: "studio.control/select",
			"expired-behavior": "studio.control/select"
		},
		defaults: {
			display: "detailed",
			"expired-behavior": "zero"
		},
		ports: [textPort("target", true), textPort("completion-message")],
		properties: {
			display: enumSchema("compact", "detailed"),
			"expired-behavior": enumSchema("hide", "message", "zero")
		}
	},
	cover: {
		accessibility: "composite",
		controls: {
			alignment: "studio.control/select",
			overlay: "studio.control/select"
		},
		defaults: {
			alignment: "center",
			overlay: "medium"
		},
		ports: [mediaPort("background")],
		properties: {
			alignment: enumSchema("center", "end", "start"),
			overlay: enumSchema("light", "medium", "none", "strong")
		},
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 100
		}]
	},
	descriptionItem: {
		accessibility: "text",
		defaults: {},
		ports: [textPort("term", true), richTextPort("description")]
	},
	descriptionList: {
		accessibility: "data-display",
		defaults: {},
		ports: [textPort("title")],
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.descriptionItem],
			id: "items",
			maximum: 100
		}]
	},
	diagram: {
		accessibility: "data-display",
		controls: { theme: "studio.control/select" },
		defaults: { theme: "neutral" },
		ports: [sourcePort("studio.source/mermaid")],
		properties: { theme: enumSchema("dark", "forest", "neutral") }
	},
	dialog: {
		accessibility: "interactive",
		controls: {
			modal: "studio.control/switch",
			presentation: "studio.control/select"
		},
		defaults: {
			modal: true,
			presentation: "modal"
		},
		ports: [textPort("trigger-label", true), textPort("title", true)],
		properties: {
			modal: booleanSchema(),
			presentation: enumSchema("modal", "offcanvas", "overlay")
		},
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 100
		}]
	},
	divider: {
		accessibility: "structural",
		controls: { style: "studio.control/select" },
		defaults: { style: "solid" },
		ports: [textPort("label")],
		properties: { style: enumSchema("dashed", "dotted", "solid") }
	},
	drawing: {
		accessibility: "media",
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.drawing,
				profile: "studio.drawing/canonical"
			},
			id: "drawing",
			label: message("port-drawing", "Drawing"),
			multiple: false,
			required: true,
			valueType: "studio.value/drawing"
		}]
	},
	embed: {
		accessibility: "media",
		controls: { "aspect-ratio": "studio.control/select" },
		defaults: { "aspect-ratio": "16:9" },
		ports: [resourcePort("resource", false)],
		properties: { "aspect-ratio": enumSchema("1:1", "4:3", "16:9", "21:9") }
	},
	gallery: {
		accessibility: "composite",
		controls: {
			autoplay: "studio.control/switch",
			columns: "studio.control/integer",
			lightbox: "studio.control/switch",
			presentation: "studio.control/select"
		},
		defaults: {
			autoplay: false,
			columns: 4,
			lightbox: false,
			presentation: "grid"
		},
		ports: [mediaPort("items", true)],
		properties: {
			autoplay: booleanSchema(),
			columns: integerSchema(1, 12),
			lightbox: booleanSchema(),
			presentation: enumSchema("grid", "slideshow")
		}
	},
	heading: {
		accessibility: "text",
		controls: { level: "studio.control/select" },
		defaults: { level: 2 },
		ports: [textPort("text", true)],
		properties: { level: integerSchema(1, 6) }
	},
	icon: {
		accessibility: "media",
		controls: {
			decorative: "studio.control/switch",
			name: "studio.control/single-line-text"
		},
		defaults: {
			decorative: true,
			name: "symbol"
		},
		ports: [textPort("alternative-text")],
		properties: {
			decorative: booleanSchema(),
			name: stringSchema(200)
		}
	},
	image: {
		accessibility: "media",
		controls: {
			fit: "studio.control/select",
			loading: "studio.control/select"
		},
		defaults: {
			fit: "cover",
			loading: "lazy"
		},
		ports: [mediaPort("asset")],
		properties: {
			fit: enumSchema("contain", "cover", "fill", "scale-down"),
			loading: enumSchema("eager", "lazy")
		}
	},
	label: {
		accessibility: "text",
		controls: { tone: "studio.control/select" },
		defaults: { tone: "neutral" },
		ports: [textPort("text", true)],
		properties: { tone: enumSchema("error", "information", "neutral", "success", "warning") }
	},
	math: {
		accessibility: "text",
		controls: { "display-mode": "studio.control/switch" },
		defaults: { "display-mode": true },
		ports: [sourcePort("studio.source/latex")],
		properties: { "display-mode": booleanSchema() }
	},
	money: {
		accessibility: "data-display",
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.money,
				profile: "studio.money/canonical"
			},
			id: "amount",
			label: message("port-amount", "Amount"),
			multiple: false,
			required: true,
			valueType: "money"
		}]
	},
	navigation: {
		accessibility: "landmark",
		controls: { presentation: "studio.control/select" },
		defaults: { presentation: "nav" },
		ports: [textPort("label")],
		properties: { presentation: enumSchema("breadcrumbs", "dotnav", "dropnav", "navbar", "nav", "pagination", "subnav", "thumbnav") },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.navigationItem],
			id: "items",
			maximum: 100
		}]
	},
	navigationItem: {
		accessibility: "interactive",
		controls: {
			current: "studio.control/switch",
			href: "studio.control/single-line-text"
		},
		defaults: {
			current: false,
			href: ""
		},
		ports: [textPort("label", true)],
		properties: {
			current: booleanSchema(),
			href: stringSchema(2048)
		},
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.navigationItem],
			id: "children",
			maximum: 50
		}]
	},
	notice: {
		accessibility: "composite",
		controls: {
			dismissible: "studio.control/switch",
			tone: "studio.control/select"
		},
		defaults: {
			dismissible: false,
			tone: "information"
		},
		ports: [textPort("title"), richTextPort()],
		properties: {
			dismissible: booleanSchema(),
			tone: enumSchema("comment", "error", "information", "success", "warning")
		}
	},
	popover: {
		accessibility: "interactive",
		controls: {
			"dismiss-on-blur": "studio.control/switch",
			placement: "studio.control/select",
			presentation: "studio.control/select"
		},
		defaults: {
			"dismiss-on-blur": true,
			placement: "auto",
			presentation: "popover"
		},
		ports: [textPort("trigger-label", true), textPort("title")],
		properties: {
			"dismiss-on-blur": booleanSchema(),
			placement: enumSchema("auto", "bottom", "left", "right", "top"),
			presentation: enumSchema("dropbar", "dropdown", "popover", "tooltip")
		},
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 100
		}]
	},
	progress: {
		accessibility: "data-display",
		controls: { maximum: "studio.control/integer" },
		defaults: { maximum: 100 },
		ports: [textPort("label"), numberPort("value", true)],
		properties: { maximum: integerSchema(1, 1e6) }
	},
	richText: {
		accessibility: "text",
		defaults: {},
		ports: [richTextPort()]
	},
	search: {
		accessibility: "interactive",
		controls: {
			action: "studio.control/single-line-text",
			"query-parameter": "studio.control/single-line-text"
		},
		defaults: {
			action: "",
			"query-parameter": "q"
		},
		ports: [textPort("label"), textPort("placeholder")],
		properties: {
			action: stringSchema(2048),
			"query-parameter": stringSchema(100)
		}
	},
	spinner: {
		accessibility: "data-display",
		controls: {
			active: "studio.control/switch",
			size: "studio.control/select"
		},
		defaults: {
			active: true,
			size: "medium"
		},
		ports: [textPort("label")],
		properties: {
			active: booleanSchema(),
			size: enumSchema("large", "medium", "small")
		}
	},
	tab: {
		accessibility: "composite",
		defaults: {},
		ports: [textPort("title", true)],
		slots: [{
			accepts: CONTENT_TYPES,
			id: "content",
			maximum: 100
		}]
	},
	tabs: {
		accessibility: "composite",
		controls: { activation: "studio.control/select" },
		defaults: { activation: "automatic" },
		properties: { activation: enumSchema("automatic", "manual") },
		slots: [{
			accepts: [CORE_PRODUCTION_BLOCK_TYPES.tab],
			id: "items",
			maximum: 30
		}]
	},
	table: {
		accessibility: "data-display",
		defaults: {},
		ports: [{
			authoring: {
				control: CORE_PRODUCTION_CONTROL_IDS.table,
				profile: "studio.table/canonical"
			},
			id: "table",
			label: message("port-table", "Table"),
			multiple: false,
			required: true,
			valueType: "studio.value/table"
		}]
	},
	video: {
		accessibility: "media",
		controls: {
			autoplay: "studio.control/switch",
			controls: "studio.control/switch",
			muted: "studio.control/switch"
		},
		defaults: {
			autoplay: false,
			controls: true,
			muted: false
		},
		ports: [
			mediaPort("asset"),
			mediaPort("poster"),
			textPort("captions")
		],
		properties: {
			autoplay: booleanSchema(),
			controls: booleanSchema(),
			muted: booleanSchema()
		}
	}
});
/** Build the entire canonical catalog with explicit allowlists and no host imports. */
function createCoreProductionBlockDefinitions() {
	const layouts = createCoreLayoutBlockDefinitions({
		acceptedChildTypes: CONTENT_TYPES,
		rendererRequirements: WEB_RENDERERS
	}).map(addPresentationCapability);
	const content = Object.keys(SPECS).map((name) => createDefinition(name, SPECS[name]));
	return [...layouts, ...content];
}
/** Minimal schema-valid persisted properties for a newly inserted production node. */
function coreProductionInitialProperties(type) {
	if (isCoreLayoutBlockType(type)) return coreLayoutInitialProperties(type);
	return cloneContractValue(SPECS[productionName(type)].defaults);
}
function isCoreProductionBlockType(type) {
	return ALL_TYPES.includes(type);
}
/** Create the ten deterministic, schema-valid starter patterns. */
function createCoreProductionPatterns() {
	return [
		pattern("article", [node("article", "stack", {}, [node("article-title", "heading", { text: "Article title" }), node("article-body", "richText", { content: richText("Start writing…") })])]),
		pattern("collection-index", [node("collection-index", "section", {}, [node("collection-heading", "heading", { text: "Latest content" }), node("collection", "contentCollection", {}, void 0, { items: query("studio.query/content") })])]),
		pattern("document-header", [node("document-header", "columns", {}, [node("document-logo", "image"), node("document-title", "heading", { text: "Document title" })])]),
		pattern("faq", [node("faq", "accordion", {}, [node("faq-item", "accordionItem", { title: "Question" }, [node("faq-answer", "richText", { content: richText("Answer") })])])]),
		pattern("feature-grid", [node("features", "grid", {}, [
			node("feature-one", "card", { title: "Feature one" }),
			node("feature-two", "card", { title: "Feature two" }),
			node("feature-three", "card", { title: "Feature three" })
		])]),
		pattern("hero", [node("hero", "section", {}, [node("hero-stack", "stack", {}, [
			node("hero-title", "heading", { text: "Build something meaningful" }),
			node("hero-copy", "richText", { content: richText("A portable Studio page.") }),
			node("hero-action", "callToAction", { label: "Get started" })
		])])]),
		pattern("media-gallery", [node("media-gallery", "gallery")]),
		pattern("pricing", [node("pricing", "card", { title: "Plan" }, [node("price", "money", { amount: {
			amount: "0.00",
			currency: "USD"
		} }), node("price-action", "callToAction", { label: "Choose plan" })])]),
		pattern("product", [node("product", "columns", {}, [node("product-media", "gallery"), node("product-copy", "stack", {}, [
			node("product-title", "heading", { text: "Product" }),
			node("product-description", "richText", { content: richText("Product description") }),
			node("product-price", "money", {}, void 0, { amount: resource("catalog/product-price", "studio.resource/money") })
		])])]),
		pattern("tabbed-content", [node("tabbed-content", "tabs", {}, [node("tab-one", "tab", { title: "First" }, [node("tab-one-copy", "richText", { content: richText("First panel") })]), node("tab-two", "tab", { title: "Second" }, [node("tab-two-copy", "richText", { content: richText("Second panel") })])])])
	];
}
function createDefinition(name, spec) {
	const type = CORE_PRODUCTION_BLOCK_TYPES[name];
	return {
		accessibility: {
			accessibleName: spec.accessibility === "decorative" || spec.accessibility === "structural" ? "not-applicable" : "derived",
			category: spec.accessibility,
			keyboard: message("block-keyboard", "Use Studio controls to edit and reorder this block."),
			outputChecks: ["studio.check/accessible-name", "studio.check/reflow"],
			reducedMotion: [
				"audio",
				"gallery",
				"video"
			].includes(name) ? "disable-motion" : "not-applicable"
		},
		category: `studio.category/${categoryFor(spec.accessibility)}`,
		contractVersion: STUDIO_CONTRACT_VERSION,
		editingModes: ["blueprint", "content"],
		icon: {
			kind: "symbol",
			value: kebab(name)
		},
		kind: "block-definition",
		label: message(`block-${kebab(name)}`, title(name)),
		owner: OWNER,
		ports: cloneContractValue([...spec.ports ?? []]),
		propertyControls: [...Object.entries(spec.controls ?? {}).map(([property, control]) => ({
			control,
			property
		})), {
			control: CORE_PRODUCTION_CONTROL_IDS.presentation,
			property: "design"
		}],
		propertySchema: {
			additionalProperties: false,
			properties: cloneContractValue({
				...spec.properties ?? {},
				design: PRESENTATION_PROPERTY_SCHEMA
			}),
			...spec.required === void 0 ? {} : { required: [...spec.required] },
			type: "object"
		},
		rendererRequirements: cloneContractValue([...WEB_RENDERERS]),
		revision: `production-${kebab(name)}-r1`,
		slots: (spec.slots ?? []).map((slot) => ({
			accepts: { types: cloneContractValue([...slot.accepts]) },
			id: slot.id,
			label: message(`slot-${slot.id}`, title(slot.id)),
			maximum: slot.maximum ?? 100,
			minimum: slot.minimum ?? 0,
			ordered: true
		})),
		themeControls: [],
		type,
		version: VERSION
	};
}
function addPresentationCapability(definition) {
	const properties = definition.propertySchema.properties;
	if (!isJsonObject(properties)) throw new TypeError(`${definition.type} property schema must declare an object property map.`);
	return {
		...definition,
		propertyControls: [...definition.propertyControls ?? [], {
			control: CORE_PRODUCTION_CONTROL_IDS.presentation,
			property: "design"
		}],
		propertySchema: {
			...definition.propertySchema,
			properties: cloneContractValue({
				...properties,
				design: PRESENTATION_PROPERTY_SCHEMA
			})
		}
	};
}
function presentationPropertySchema() {
	const schema = cloneContractValue(studioPresentationSchema);
	delete schema.$id;
	delete schema.$schema;
	delete schema.title;
	return schema;
}
function isJsonObject(value) {
	return value !== null && typeof value === "object" && !Array.isArray(value);
}
function pattern(id, roots) {
	const definitions = new Map(createCoreProductionBlockDefinitions().map((item) => [item.type, item]));
	const used = /* @__PURE__ */ new Set();
	const visit = (current) => {
		used.add(current.type);
		Object.values(current.slots).flat().forEach(visit);
	};
	roots.forEach(visit);
	return {
		blockDependencies: [...used].sort().map((type) => {
			const definition = definitions.get(type);
			if (definition === void 0) throw new Error(`Pattern ${id} uses unknown block ${type}.`);
			return {
				revision: definition.revision,
				type,
				version: definition.version
			};
		}),
		contractVersion: STUDIO_CONTRACT_VERSION,
		id: `studio.pattern/${id}`,
		kind: "pattern",
		label: message(`pattern-${id}`, title(id)),
		owner: OWNER,
		revision: `production-pattern-${id}-r1`,
		roots,
		version: VERSION
	};
}
function node(id, name, staticBindings = {}, children, dynamicBindings = {}) {
	const type = CORE_PRODUCTION_BLOCK_TYPES[name];
	const slot = name === "section" || name === "accordionItem" || name === "dialog" || name === "popover" || name === "tab" ? "content" : name === "card" ? "actions" : "items";
	const bindings = {};
	for (const [port, value] of Object.entries(staticBindings)) bindings[port] = binding({
		kind: "static-value",
		value
	});
	for (const [port, source] of Object.entries(dynamicBindings)) bindings[port] = binding(source);
	const result = {
		authoring: { mode: isCoreLayoutBlockType(type) || children !== void 0 ? "structural" : "content" },
		bindings,
		id,
		properties: coreProductionInitialProperties(type),
		slots: children === void 0 ? {} : { [slot]: children },
		type,
		version: VERSION
	};
	if (name === "grid") result.responsive = { columns: {
		expanded: 4,
		medium: 2
	} };
	return result;
}
function binding(source) {
	return {
		onError: "error",
		onNull: "empty",
		source,
		transforms: []
	};
}
function query(queryName) {
	return {
		kind: "query-reference",
		parameters: {},
		query: queryName,
		version: VERSION
	};
}
function resource(id, resourceType) {
	return {
		id,
		kind: "resource-reference",
		resourceType
	};
}
function richText(text) {
	return {
		content: [{
			content: [{
				text,
				type: "text"
			}],
			type: "paragraph"
		}],
		type: "doc"
	};
}
function productionName(type) {
	const entry = Object.entries(CORE_PRODUCTION_BLOCK_TYPES).find(([, candidate]) => candidate === type);
	if (entry === void 0 || isCoreLayoutBlockType(type)) throw new TypeError(`Unsupported production block ${type}.`);
	return entry[0];
}
function message(id, defaultMessage) {
	return {
		defaultMessage,
		key: `studio.blocks/${id}`
	};
}
function title(value) {
	return value.replace(/([a-z])([A-Z])/gu, "$1 $2").replaceAll("-", " ").replace(/^./u, (character) => character.toUpperCase());
}
function kebab(value) {
	return value.replace(/([a-z])([A-Z])/gu, "$1-$2").toLowerCase();
}
function categoryFor(category) {
	return category === "structural" || category === "landmark" ? "layout" : category;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/production-values.js
var CHART_TYPES = /* @__PURE__ */ new Set([
	"bar",
	"doughnut",
	"line",
	"pie"
]);
var MONEY_AMOUNT = /^-?(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,6})?$/u;
var CURRENCY = /^[A-Z]{3}$/u;
var DRAWING_COLOR = /^(?:#[0-9A-Fa-f]{6}|[a-z][a-z0-9-]{0,62}\/[a-z][a-z0-9-]{0,62})$/u;
/** Parse and detach one canonical chart spec, refusing library-specific configuration. */
function parseStudioChartSpec(value) {
	const record = exactRecord(value, [
		"datasets",
		"labels",
		"title",
		"type"
	], "Chart");
	if (typeof record.type !== "string" || !CHART_TYPES.has(record.type)) throw new TypeError("Chart type must be bar, doughnut, line, or pie.");
	const labels = stringArray(record.labels, 200, 500, "Chart labels");
	if (!Array.isArray(record.datasets) || record.datasets.length < 1 || record.datasets.length > 20) throw new RangeError("Chart datasets must contain between 1 and 20 datasets.");
	const result = {
		datasets: record.datasets.map((candidate, index) => {
			const dataset = exactRecord(candidate, ["label", "values"], `Chart dataset ${index}`);
			if (typeof dataset.label !== "string" || dataset.label.length > 500) throw new TypeError(`Chart dataset ${index} label must be a bounded string.`);
			if (!Array.isArray(dataset.values) || dataset.values.length > 200) throw new RangeError(`Chart dataset ${index} values exceed the 200-value limit.`);
			const values = dataset.values.map((item) => {
				if (typeof item !== "number" || !Number.isFinite(item) || Math.abs(item) > 0x38d7ea4c68000) throw new TypeError(`Chart dataset ${index} contains an invalid finite number.`);
				return item;
			});
			if (values.length !== labels.length) throw new RangeError(`Chart dataset ${index} must have one value per label.`);
			return {
				label: dataset.label,
				values
			};
		}),
		labels,
		type: record.type
	};
	if (record.title !== void 0) {
		if (typeof record.title !== "string" || record.title.length > 500) throw new TypeError("Chart title must be a bounded string.");
		result.title = record.title;
	}
	return result;
}
/** Parse bounded vector strokes and reject SVG, data URLs, and canvas commands. */
function parseStudioDrawingDocument(value) {
	const record = exactRecord(value, [
		"alt",
		"height",
		"strokes",
		"width"
	], "Drawing");
	const width = integer(record.width, 1, 4096, "Drawing width");
	const height = integer(record.height, 1, 4096, "Drawing height");
	if (typeof record.alt !== "string" || record.alt.length < 1 || record.alt.length > 5e3) throw new TypeError("Drawing alternative text must contain between 1 and 5000 characters.");
	if (!Array.isArray(record.strokes) || record.strokes.length > 5e3) throw new RangeError("Drawing strokes exceed the 5000-stroke limit.");
	const strokes = record.strokes.map((candidate, strokeIndex) => {
		const stroke = exactRecord(candidate, [
			"color",
			"points",
			"width"
		], `Drawing stroke ${strokeIndex}`);
		if (typeof stroke.color !== "string" || !DRAWING_COLOR.test(stroke.color)) throw new TypeError(`Drawing stroke ${strokeIndex} uses an invalid color token.`);
		if (typeof stroke.width !== "number" || !Number.isFinite(stroke.width) || stroke.width < .25 || stroke.width > 64) throw new RangeError(`Drawing stroke ${strokeIndex} width is outside 0.25 through 64.`);
		if (!Array.isArray(stroke.points) || stroke.points.length < 1 || stroke.points.length > 1e4) throw new RangeError(`Drawing stroke ${strokeIndex} must contain 1 through 10000 points.`);
		const points = stroke.points.map((candidatePoint, pointIndex) => {
			const point = exactRecord(candidatePoint, ["x", "y"], `Drawing point ${pointIndex}`);
			return {
				x: coordinate(point.x, width, `Drawing point ${pointIndex} x`),
				y: coordinate(point.y, height, `Drawing point ${pointIndex} y`)
			};
		});
		return {
			color: stroke.color,
			points,
			width: stroke.width
		};
	});
	return {
		alt: record.alt,
		height,
		strokes,
		width
	};
}
/** Parse exact decimal money without converting through a binary float. */
function parseStudioMoneyValue(value) {
	const record = exactRecord(value, ["amount", "currency"], "Money");
	if (typeof record.amount !== "string" || !MONEY_AMOUNT.test(record.amount)) throw new TypeError("Money amount must be a canonical decimal string with at most six places.");
	if (typeof record.currency !== "string" || !CURRENCY.test(record.currency)) throw new TypeError("Money currency must be an uppercase ISO-style three-letter code.");
	return {
		amount: record.amount,
		currency: record.currency
	};
}
/** Parse a bounded, text-only table and require one cell per declared column. */
function parseStudioTableDocument(value) {
	const record = exactRecord(value, [
		"caption",
		"columns",
		"rows"
	], "Table");
	const columns = stringArray(record.columns, 50, 500, "Table columns");
	if (columns.length === 0) throw new RangeError("Table must declare at least one column.");
	if (!Array.isArray(record.rows) || record.rows.length > 1e3) throw new RangeError("Table rows exceed the 1000-row limit.");
	const rows = record.rows.map((candidate, index) => {
		const cells = stringArray(candidate, 50, 5e3, `Table row ${index}`);
		if (cells.length !== columns.length) throw new RangeError(`Table row ${index} must contain one cell per column.`);
		return cells;
	});
	let caption;
	if (record.caption !== void 0) {
		if (typeof record.caption !== "string" || record.caption.length > 500) throw new TypeError("Table caption must be a bounded string.");
		caption = record.caption;
	}
	return {
		...caption === void 0 ? {} : { caption },
		columns,
		rows
	};
}
function exactRecord(value, keys, name) {
	if (value === null || typeof value !== "object" || Array.isArray(value) || Object.getPrototypeOf(value) !== Object.prototype) throw new TypeError(`${name} must be a plain JSON object.`);
	const record = value;
	const allowed = new Set(keys);
	const unknown = Object.keys(record).find((key) => !allowed.has(key));
	if (unknown !== void 0) throw new TypeError(`${name} contains unknown member ${unknown}.`);
	return record;
}
function stringArray(value, maximumItems, maximumLength, name) {
	if (!Array.isArray(value) || value.length > maximumItems) throw new RangeError(`${name} exceed their item limit.`);
	return value.map((item) => {
		if (typeof item !== "string" || item.length > maximumLength) throw new TypeError(`${name} must be bounded strings.`);
		return item;
	});
}
function integer(value, minimum, maximum, name) {
	if (typeof value !== "number" || !Number.isInteger(value) || value < minimum || value > maximum) throw new RangeError(`${name} must be an integer from ${minimum} through ${maximum}.`);
	return value;
}
function coordinate(value, maximum, name) {
	if (typeof value !== "number" || !Number.isFinite(value) || value < 0 || value > maximum) throw new RangeError(`${name} must be a finite coordinate inside the drawing bounds.`);
	return value;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/negotiation.js
var DEFAULT_REQUIRED_PORTS = ["studio.port/artifact"];
/**
* Resolve one session posture from a host capability document. Capability
* negotiation fails closed: without a common wire protocol version or a
* required port there is no editable session, only a diagnosable read-only
* one. Optional ports degrade with informational diagnostics instead of
* being silently assumed.
*/
function negotiateCapabilities(capabilities, options = {}) {
	const supportedVersions = options.supportedProtocolVersions ?? ["0.1.0-draft.2"];
	const requiredPorts = options.requiredPorts ?? DEFAULT_REQUIRED_PORTS;
	const optionalPorts = options.optionalPorts ?? [];
	const diagnostics = [];
	const availablePorts = capabilities.ports.map((port) => port.id);
	const available = new Set(availablePorts);
	const protocolVersion = supportedVersions.find((version) => capabilities.protocolVersions.includes(version));
	if (protocolVersion === void 0) diagnostics.push({
		code: "studio.host/no-common-protocol-version",
		message: {
			defaultMessage: "Studio and the host share no wire protocol version.",
			key: "studio.host/no-common-protocol-version"
		},
		severity: "blocking"
	});
	const missingRequiredPorts = requiredPorts.filter((port) => !available.has(port));
	for (const port of missingRequiredPorts) diagnostics.push({
		code: "studio.host/missing-required-port",
		message: {
			defaultMessage: `The host does not provide the required ${port} port.`,
			key: "studio.host/missing-required-port"
		},
		parameters: { port },
		severity: "blocking"
	});
	const missingOptionalPorts = optionalPorts.filter((port) => !available.has(port));
	for (const port of missingOptionalPorts) diagnostics.push({
		code: "studio.host/missing-optional-port",
		message: {
			defaultMessage: `The optional ${port} port is unavailable; its features are disabled.`,
			key: "studio.host/missing-optional-port"
		},
		parameters: { port },
		severity: "information"
	});
	const result = {
		availablePorts,
		diagnostics,
		missingOptionalPorts,
		missingRequiredPorts,
		sessionState: protocolVersion !== void 0 && missingRequiredPorts.length === 0 ? "editable" : "read-only"
	};
	if (protocolVersion !== void 0) result.protocolVersion = protocolVersion;
	return result;
}
Object.freeze([
	"blueprint",
	"content",
	"hybrid",
	"model",
	"read-only"
]);
var BLUEPRINT_STRUCTURE_COMMAND_TYPES = [
	"studio.command/apply-pattern",
	"studio.command/batch",
	"studio.command/duplicate-node",
	"studio.command/insert-node",
	"studio.command/move-node",
	"studio.command/remove-binding",
	"studio.command/remove-node",
	"studio.command/reorder-children",
	"studio.command/reset-inherited-property",
	"studio.command/restore-node",
	"studio.command/set-binding",
	"studio.command/set-property",
	"studio.command/set-size-role",
	"studio.command/unset-property",
	"studio.command/unset-size-role"
];
/**
* The structure commands hybrid composition may dispatch when every affected
* collection stays inside a structural authoring region. Property, binding,
* size-role, inheritance-reset, and pattern commands remain Blueprint-mode
* vocabulary.
*/
var HYBRID_STRUCTURE_OPERATION_TYPES = [
	"studio.command/duplicate-node",
	"studio.command/insert-node",
	"studio.command/move-node",
	"studio.command/remove-node",
	"studio.command/reorder-children",
	"studio.command/restore-node"
];
var PERMITTED_COMMAND_TYPES = Object.freeze({
	blueprint: immutableCommandTypeSet(BLUEPRINT_STRUCTURE_COMMAND_TYPES),
	content: immutableCommandTypeSet(["studio.command/set-field-value"]),
	hybrid: immutableCommandTypeSet([
		"studio.command/batch",
		...HYBRID_STRUCTURE_OPERATION_TYPES,
		"studio.command/set-field-value"
	]),
	model: immutableCommandTypeSet(["studio.command/add-model-field"]),
	"read-only": immutableCommandTypeSet([])
});
var HYBRID_BATCHABLE_OPERATION_TYPES = immutableCommandTypeSet(HYBRID_STRUCTURE_OPERATION_TYPES);
/**
* The deterministic mode-to-permitted-command table: one immutable set per
* session mode, shared across calls, so UIs derive disabled affordances from
* the same source the session enforces. The table is type-level only; the
* hybrid entries additionally require every affected collection to stay
* inside a structural authoring region, which the session enforces per
* command target.
*/
function permittedCommandTypes(mode) {
	return PERMITTED_COMMAND_TYPES[mode];
}
/**
* Flattens a configuration's editing mode, composite, and session state into
* the single session mode fixed at session creation: a read-only session
* state always flattens to `read-only`, the hybrid composite flattens to
* `hybrid`, and every other session keeps its authoring mode. The hybrid
* composite is invalid with Model mode, mirroring the configuration schema.
*/
function resolveSessionMode(configuration) {
	if (configuration.sessionState === "read-only") return "read-only";
	if (configuration.composite === "hybrid") {
		if (configuration.mode === "model") throw new RangeError("The hybrid composite is invalid with the model editing mode.");
		return "hybrid";
	}
	return configuration.mode;
}
/**
* Fails closed with the stable `mode-forbidden` code when the command type is
* outside the active mode's permitted set.
*/
function assertModePermitsCommandType(mode, type) {
	if (!PERMITTED_COMMAND_TYPES[mode].has(type)) throw new StudioCommandError("mode-forbidden", `Command type ${type} is not permitted in ${mode} mode.`);
}
/**
* Enforces the bounded-composition rule for one hybrid structure command:
* every collection the command inserts into, removes from, moves across, or
* reorders must be a named slot of a node whose authoring policy mode is
* `structural`; inserted block types must satisfy the structural node's
* `allowedBlocks` when declared; subtrees containing a `locked` node are
* never inserted, removed, moved, or duplicated; and document roots are
* never in bounds. Batch operations are evaluated sequentially against a
* trial state so later operations may compose nodes earlier operations
* introduced. A violation fails closed with `mode-forbidden`; a reference
* the gate cannot resolve is left for the reducer's canonical failure code.
*
* The passed document is consumed as trial scratch state and may be mutated;
* callers pass a clone.
*/
function assertHybridCommandInBounds(document, command) {
	switch (command.type) {
		case "studio.command/batch":
			for (const operation of command.payload.operations) {
				const type = operation.type;
				if (type === "studio.command/batch" || type === "studio.command/apply-pattern" || type === "studio.command/reset-inherited-property") return;
				if (!HYBRID_BATCHABLE_OPERATION_TYPES.has(operation.type)) throw new StudioCommandError("mode-forbidden", `Batch operation type ${operation.type} is not permitted in hybrid mode.`);
				assertHybridOperationInBounds(document, operation);
				try {
					applyOperation(document, operation);
				} catch {
					return;
				}
			}
			return;
		case "studio.command/apply-pattern":
		case "studio.command/reset-inherited-property": throw new StudioCommandError("mode-forbidden", `Command type ${command.type} is not permitted in hybrid mode.`);
		default: assertHybridOperationInBounds(document, command);
	}
}
function assertHybridOperationInBounds(document, operation) {
	switch (operation.type) {
		case "studio.command/insert-node":
		case "studio.command/restore-node":
			assertSubtreeUnlocked(operation.payload.node);
			assertComposableDestination(document, operation.payload.destination, operation.payload.node);
			return;
		case "studio.command/remove-node": {
			const location = locateNode(document.roots, operation.payload.nodeId);
			if (location === void 0) return;
			assertSubtreeUnlocked(location.node);
			assertComposableParent(location.parent, location.slot);
			return;
		}
		case "studio.command/move-node": {
			const location = locateNode(document.roots, operation.payload.nodeId);
			if (location === void 0) return;
			assertSubtreeUnlocked(location.node);
			assertComposableParent(location.parent, location.slot);
			assertComposableDestination(document, operation.payload.destination, location.node);
			return;
		}
		case "studio.command/duplicate-node": {
			const location = locateNode(document.roots, operation.payload.nodeId);
			if (location === void 0) return;
			assertSubtreeUnlocked(location.node);
			if (operation.payload.destination === void 0) {
				assertComposableParent(location.parent, location.slot);
				if (location.parent !== void 0) assertAllowedBlock(location.parent, location.slot, location.node);
			} else assertComposableDestination(document, operation.payload.destination, location.node);
			return;
		}
		case "studio.command/reorder-children":
			if (operation.payload.parentNodeId === void 0) throw rootsOutOfBounds();
			assertComposableParent(locateNode(document.roots, operation.payload.parentNodeId)?.node, operation.payload.slot);
			return;
		default: throw new StudioCommandError("mode-forbidden", `Batch operation type ${operation.type} is not permitted in hybrid mode.`);
	}
}
function locateNode(nodes, nodeId, parent, slot) {
	for (const node of nodes) {
		if (node.id === nodeId) {
			if (parent === void 0) return { node };
			return slot === void 0 ? {
				node,
				parent
			} : {
				node,
				parent,
				slot
			};
		}
		for (const [slotName, children] of Object.entries(node.slots)) {
			const nested = locateNode(children, nodeId, node, slotName);
			if (nested !== void 0) return nested;
		}
	}
}
function assertComposableDestination(document, destination, node) {
	if (destination.parentNodeId === void 0) throw rootsOutOfBounds();
	const parent = locateNode(document.roots, destination.parentNodeId)?.node;
	if (parent === void 0) return;
	assertComposableParent(parent, destination.slot);
	assertAllowedBlock(parent, destination.slot, node);
}
/**
* A collection is composable when its parent node declares structural
* authoring, or when the parent's per-slot composition marker names the slot
* (ADR 0013). The marker only grants composability; it never revokes what
* the node-level policy permits.
*/
function assertComposableParent(parent, slot) {
	if (parent === void 0) throw rootsOutOfBounds();
	if (parent.authoring.mode === "structural") return;
	if (slot !== void 0 && parent.authoring.slots?.[slot]?.composable === true) return;
	throw new StudioCommandError("mode-forbidden", `Hybrid composition is bounded to structural slots; node ${parent.id} declares neither structural authoring nor a composable marker for the affected slot.`);
}
function assertAllowedBlock(parent, slot, node) {
	const allowedBlocks = (slot === void 0 ? void 0 : parent.authoring.slots?.[slot])?.allowedBlocks ?? parent.authoring.allowedBlocks;
	if (allowedBlocks !== void 0 && !allowedBlocks.includes(node.type)) throw new StudioCommandError("mode-forbidden", `Block type ${node.type} is not an allowed block inside the composable region of node ${parent.id}.`);
}
function assertSubtreeUnlocked(node) {
	const stack = [node];
	while (stack.length > 0) {
		const current = stack.pop();
		if (current === void 0) break;
		if (current.authoring.mode === "locked") throw new StudioCommandError("mode-forbidden", `Node ${current.id} is locked and never changes through hybrid composition.`);
		for (const children of Object.values(current.slots)) stack.push(...children);
	}
}
function rootsOutOfBounds() {
	return new StudioCommandError("mode-forbidden", "Hybrid composition is bounded to structural slots; the document roots are out of bounds.");
}
function immutableCommandTypeSet(types) {
	const set = new Set(types);
	const forbidMutation = () => {
		throw new TypeError("The permitted command-type table is immutable.");
	};
	return Object.freeze(Object.assign(set, {
		add: forbidMutation,
		clear: forbidMutation,
		delete: forbidMutation
	}));
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/model-commands.js
function applyModelCommand(model, command) {
	if (command.artifactId !== model.id) throw new StudioCommandError("node-not-found", `Command targets ${command.artifactId}, not content model ${model.id}.`);
	if (model.status !== "draft") throw new StudioCommandError("artifact-not-draft", `Content model ${model.id} is ${model.status}; fields are added through a new draft.`);
	if (model.fields.some((field) => field.id === command.payload.field.id)) throw new StudioCommandError("duplicate-field", `Field ${command.payload.field.id} already exists on ${model.id}.`);
	const position = command.payload.position ?? model.fields.length;
	if (!Number.isInteger(position) || position < 0 || position > model.fields.length) throw new StudioCommandError("invalid-index", `Field position ${position} is outside the model's field list.`);
	const next = cloneContractValue(model);
	next.fields.splice(position, 0, cloneContractValue(command.payload.field));
	return next;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/session.js
/**
* A deterministic editing session over one Blueprint document: bounded
* history, an explicit selection model, and the session-level guards the
* command contract requires before any reducer runs. Session generation,
* mode permission, read-only state, and expected-revision checks fail
* closed; a rejected command changes neither the document, the history, nor
* the selection. The session mode is fixed at creation and decides the
* permitted command set through one deterministic table
* (`permittedCommandTypes`); hybrid mode additionally bounds structure
* commands to slots governed by structural authoring regions.
*/
var StudioSession = class {
	#history;
	#mode;
	#sessionGeneration;
	#savedRevision;
	#savedStateVersion = 0;
	#selection = [];
	constructor(options) {
		this.#history = new StudioHistory(options.document, options.maximumHistoryEntries ?? 100);
		this.#mode = resolveModeOption(options);
		this.#sessionGeneration = options.sessionGeneration;
		this.#savedRevision = options.document.revision;
	}
	get canRedo() {
		return this.#history.canRedo;
	}
	get canUndo() {
		return this.#history.canUndo;
	}
	get dirty() {
		return this.#history.stateVersion !== this.#savedStateVersion;
	}
	get document() {
		return this.#history.current;
	}
	/** The session mode fixed at creation. */
	get mode() {
		return this.#mode;
	}
	get selection() {
		return [...this.#selection];
	}
	/** The legacy read-only projection of the session mode. */
	get sessionState() {
		return this.#mode === "read-only" ? "read-only" : "editable";
	}
	get stateVersion() {
		return this.#history.stateVersion;
	}
	execute(command) {
		this.#assertWritable();
		this.#assertLiveGeneration(command);
		assertModePermitsCommandType(this.#mode, command.type);
		if (this.#mode === "hybrid") assertHybridCommandInBounds(this.#history.current, command);
		if (command.expectedRevision !== void 0 && command.expectedRevision !== this.#savedRevision) throw new StudioCommandError("stale-state", `Command expects revision ${command.expectedRevision}, but the session holds ${this.#savedRevision}.`);
		const next = this.#history.execute(command);
		this.#pruneSelection(next);
		return next;
	}
	/**
	* Applies one entry field command behind the same session guards as
	* `execute`, so no UI dispatch path bypasses the mode boundary. Entry
	* state stays host-owned: the result is returned, not recorded in the
	* Blueprint history, and the selection is untouched.
	*/
	executeEntryCommand(entry, command) {
		this.#assertWritable();
		this.#assertLiveGeneration(command);
		assertModePermitsCommandType(this.#mode, command.type);
		if (command.expectedRevision !== void 0 && command.expectedRevision !== entry.revision) throw new StudioCommandError("stale-state", `Command expects revision ${command.expectedRevision}, but the entry holds ${entry.revision}.`);
		return applyEntryCommand(entry, command);
	}
	/**
	* Applies one content-model command behind the same session guards as
	* `execute`, so no UI dispatch path bypasses the mode boundary. Model
	* state stays host-owned: the result is returned, not recorded in the
	* Blueprint history, and the selection is untouched.
	*/
	executeModelCommand(model, command) {
		this.#assertWritable();
		this.#assertLiveGeneration(command);
		assertModePermitsCommandType(this.#mode, command.type);
		if (command.expectedRevision !== void 0 && command.expectedRevision !== model.revision) throw new StudioCommandError("stale-state", `Command expects revision ${command.expectedRevision}, but the model holds ${model.revision}.`);
		return applyModelCommand(model, command);
	}
	/**
	* Records the host revision that accepted a local snapshot and rebases every
	* undo/redo snapshot onto it. Callers normally omit `stateVersion`, which
	* marks the current document clean. A host save that settles after another
	* local edit passes the captured snapshot version instead: the accepted base
	* revision advances while the newer draft stays dirty.
	*/
	markSaved(revision, stateVersion = this.#history.stateVersion) {
		if (!Number.isSafeInteger(stateVersion) || stateVersion < 0 || stateVersion > this.#history.stateVersion) throw new RangeError("A saved snapshot version must be a known non-negative state version.");
		this.#history.rebaseRevision(revision);
		this.#savedRevision = revision;
		this.#savedStateVersion = stateVersion;
	}
	get savedRevision() {
		return this.#savedRevision;
	}
	select(nodeIds) {
		const document = this.#history.current;
		const unique = [];
		for (const nodeId of nodeIds) {
			if (unique.includes(nodeId)) continue;
			if (!containsNode(document.roots, nodeId)) throw new StudioCommandError("node-not-found", `Node ${nodeId} cannot be selected because it is not in the document.`);
			unique.push(nodeId);
		}
		this.#selection = unique;
		return this.selection;
	}
	clearSelection() {
		this.#selection = [];
	}
	undo() {
		const document = this.#history.undo();
		this.#pruneSelection(document);
		return document;
	}
	redo() {
		const document = this.#history.redo();
		this.#pruneSelection(document);
		return document;
	}
	#assertWritable() {
		if (this.#mode === "read-only") throw new StudioCommandError("read-only-session", "A read-only session never applies a persistent command.");
	}
	#assertLiveGeneration(command) {
		if (command.sessionGeneration !== this.#sessionGeneration) throw new StudioCommandError("stale-generation", `Command generation ${command.sessionGeneration} does not match the active session generation.`);
	}
	#pruneSelection(document) {
		if (this.#selection.length > 0) this.#selection = this.#selection.filter((nodeId) => containsNode(document.roots, nodeId));
	}
};
function resolveModeOption(options) {
	const { mode, sessionState } = options;
	if (mode === void 0) {
		if (sessionState === void 0) throw new RangeError("A session requires an explicit mode or session state.");
		return sessionState === "read-only" ? "read-only" : "blueprint";
	}
	if (sessionState !== void 0 && sessionState === "read-only" !== (mode === "read-only")) throw new RangeError(`Session mode ${mode} contradicts session state ${sessionState}; mode read-only is the read-only state.`);
	return mode;
}
function containsNode(nodes, nodeId) {
	for (const node of nodes) {
		if (node.id === nodeId) return true;
		for (const children of Object.values(node.slots)) if (containsNode(children, nodeId)) return true;
	}
	return false;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/host-session.js
var ARTIFACT_PORT = "studio.port/artifact";
var MODEL_PORT = "studio.port/model";
var RECOVERY_PORT = "studio.port/recovery";
var RESOURCE_PORT = "studio.port/resource";
var ARTIFACT_LOAD = "studio.operation/artifact.load";
var ARTIFACT_SAVE = "studio.operation/artifact.save";
var MODEL_GET = "studio.operation/model.get";
var MODEL_LIST = "studio.operation/model.list";
var RECOVERY_STORE = "studio.operation/recovery.store";
var RECOVERY_LOAD = "studio.operation/recovery.load";
var RECOVERY_DISCARD = "studio.operation/recovery.discard";
var RESOURCE_SEARCH = "studio.operation/resource.search";
var STABLE_ID = /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u;
var QUALIFIED_NAME = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u;
var FORBIDDEN_IDENTIFIERS = /* @__PURE__ */ new Set([
	"__proto__",
	"prototype",
	"constructor"
]);
/** Public bounds enforced before a composed session invokes resource search. */
var STUDIO_RESOURCE_SEARCH_LIMITS = Object.freeze({
	maximumCursorLength: 500,
	maximumLimit: 100,
	maximumSearchLength: 500,
	minimumLimit: 1
});
var validateContentModelSchema = compileProfileSchema(contentModelSchema, { schemas: [commonSchema] });
var validateArtifactReferenceSchema = compileProfileSchema({
	$ref: "https://schemas.kumwe.org/studio/v1/common.schema.json#/$defs/artifactReference",
	$schema: "https://json-schema.org/draft/2020-12/schema"
}, { schemas: [commonSchema] });
/** A local composition/state failure, distinct from a host-port rejection. */
var StudioHostSessionError = class extends Error {
	code;
	diagnostics;
	constructor(code, message, diagnostics = []) {
		super(message);
		this.name = "StudioHostSessionError";
		this.code = code;
		this.diagnostics = cloneContractValue(diagnostics);
	}
};
/**
* Composes the DOM-free editing engine with one resolved host configuration.
*
* The current contract-safe profile opens a single Blueprint only. It does
* not fabricate entry/model state, infer a transport, stage preview drafts,
* or reconcile recovery data. Those capabilities require their own explicit
* contracts.
*/
async function openStudioSession(adapter, options) {
	const configuration = cloneContractValue(options.configuration);
	const optionalPorts = requestedOptionalPorts(configuration, options.optionalPorts);
	const negotiation = negotiateCapabilities(configuration.hostCapabilities, {
		optionalPorts,
		requiredPorts: [ARTIFACT_PORT],
		supportedProtocolVersions: [configuration.protocolVersion]
	});
	if (configuration.sessionState === "read-only") negotiation.sessionState = "read-only";
	appendProfileDiagnostics(configuration, negotiation);
	const recoveryAvailable = appendOperationDiagnostics(adapter, configuration, negotiation);
	const modelsAvailable = appendModelOperationDiagnostics(adapter, configuration, negotiation);
	const resourcesAvailable = appendResourceOperationDiagnostics(adapter, configuration, negotiation);
	if (negotiation.diagnostics.some((entry) => entry.severity === "blocking")) throw new StudioHostSessionError("configuration-blocked", "The resolved Studio configuration cannot open a Blueprint host session.", negotiation.diagnostics);
	const reference = configuration.artifacts.blueprint;
	if (reference === void 0) throw new StudioHostSessionError("configuration-blocked", "A Blueprint host session requires an explicit locked Blueprint reference.", negotiation.diagnostics);
	const identifiers = new SessionIdentifierAllocator(options.identifiers);
	const loadContext = createContext(configuration, identifiers.requestId(ARTIFACT_LOAD), { operationId: ARTIFACT_LOAD });
	const loaded = await invokeOpeningHostCall(() => adapter.artifact.load(reference, loadContext));
	if (!isBlueprintLoadResult(loaded, reference.id)) throw new StudioHostSessionError("unexpected-artifact", "The host returned an artifact outside the Blueprint session profile.", [createDiagnostic("studio.host/unexpected-artifact", "The artifact port did not return the configured Blueprint.", "blocking", { artifactId: reference.id })]);
	const document = normalizeLoadedBlueprint(loaded.value, loaded.revision);
	const session = new StudioSession({
		document,
		maximumHistoryEntries: configuration.limits.maxHistoryEntries,
		mode: resolveSessionMode(configuration),
		sessionGeneration: configuration.sessionGeneration
	});
	session.markSaved(document.revision);
	return new BoundStudioHostSession(adapter, configuration, identifiers, negotiation, recoveryAvailable, modelsAvailable, resourcesAvailable, session, document.revision);
}
var BoundStudioHostSession = class {
	diagnostics;
	negotiation;
	models;
	recovery;
	resources;
	session;
	#adapter;
	#configuration;
	#identifiers;
	#retryIntents = /* @__PURE__ */ new Map();
	#disposed = false;
	#invalidationFailure;
	#lastScheduledSave;
	#revision;
	#saveTail = Promise.resolve();
	constructor(adapter, configuration, identifiers, negotiation, recoveryAvailable, modelsAvailable, resourcesAvailable, session, revision) {
		this.#adapter = adapter;
		this.#configuration = configuration;
		this.#identifiers = identifiers;
		this.negotiation = cloneNegotiation(negotiation);
		this.diagnostics = cloneContractValue(negotiation.diagnostics);
		this.session = session;
		this.#revision = revision;
		this.recovery = recoveryAvailable ? Object.freeze({
			discard: () => this.#discardRecovery(),
			load: () => this.#loadRecovery(),
			store: (envelope) => this.#storeRecovery(envelope)
		}) : void 0;
		this.models = modelsAvailable ? Object.freeze({
			get: (reference) => this.#getModel(reference),
			list: () => this.#listModels()
		}) : void 0;
		this.resources = resourcesAvailable ? Object.freeze({ search: (query) => this.#searchResources(query) }) : void 0;
	}
	get disposed() {
		return this.#disposed;
	}
	get invalidated() {
		return this.#invalidationFailure !== void 0;
	}
	get revision() {
		return this.#revision;
	}
	dispose() {
		if (this.#disposed) return;
		this.#disposed = true;
		this.#retryIntents.clear();
		this.#identifiers.dispose();
	}
	save() {
		try {
			this.#assertActive();
			if (this.session.mode === "read-only") throw new StudioHostSessionError("read-only-session", "A read-only Studio host session cannot save.");
			const document = this.session.document;
			const snapshotFingerprint = canonicalStringify(document);
			const existing = this.#lastScheduledSave;
			if (existing?.snapshotFingerprint === snapshotFingerprint) return existing.promise;
			if (!this.session.dirty) return Promise.resolve({
				revision: this.#revision,
				value: null
			});
			const stateVersion = this.session.stateVersion;
			const scheduled = this.#saveTail.then(() => this.#saveSnapshot(document, stateVersion));
			this.#saveTail = scheduled.then(() => void 0, () => void 0);
			this.#lastScheduledSave = {
				promise: scheduled,
				snapshotFingerprint
			};
			const clear = () => {
				if (this.#lastScheduledSave?.promise === scheduled) this.#lastScheduledSave = void 0;
			};
			scheduled.then(clear, clear);
			return scheduled;
		} catch (error) {
			return Promise.reject(error instanceof Error ? error : /* @__PURE__ */ new Error("The Studio host session failed with a non-Error rejection."));
		}
	}
	async #discardRecovery() {
		this.#assertActive();
		const recovery = this.#adapter.recovery;
		if (recovery === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated recovery adapter is unavailable.");
		const fingerprint = mutationFingerprint(null, this.#configuration);
		const idempotencyKey = this.#mutationKey(RECOVERY_DISCARD, fingerprint);
		const context = createContext(this.#configuration, this.#identifiers.requestId(RECOVERY_DISCARD), {
			idempotencyKey,
			operationId: RECOVERY_DISCARD
		});
		const result = await this.#invoke(() => recovery.discard(context));
		this.#clearMutationKey(RECOVERY_DISCARD, fingerprint);
		return result;
	}
	async #loadRecovery() {
		this.#assertActive();
		const recovery = this.#adapter.recovery;
		if (recovery === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated recovery adapter is unavailable.");
		const context = createContext(this.#configuration, this.#identifiers.requestId(RECOVERY_LOAD), { operationId: RECOVERY_LOAD });
		const result = await this.#invoke(() => recovery.load(context));
		return {
			...result.revision === void 0 ? {} : { revision: result.revision },
			value: result.value === null ? null : cloneContractValue(result.value)
		};
	}
	async #getModel(reference) {
		this.#assertActive();
		if (!isArtifactReference(reference)) throw new StudioHostSessionError("invalid-model-reference", "A model read requires a canonical artifact identifier and semantic version.");
		const modelPort = this.#adapter.model;
		if (modelPort === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated model adapter is unavailable.");
		const referenceSnapshot = cloneContractValue(reference);
		const context = createContext(this.#configuration, this.#identifiers.requestId(MODEL_GET), { operationId: MODEL_GET });
		const result = await this.#invoke(() => modelPort.get(referenceSnapshot, context));
		if (!isModelGetResult(result, referenceSnapshot)) throw adapterContractFailure("studio.host/unexpected-model-result", "The model port returned a document outside the requested model coordinate.");
		return {
			...result.revision === void 0 ? {} : { revision: result.revision },
			value: cloneContractValue(result.value)
		};
	}
	async #listModels() {
		this.#assertActive();
		const modelPort = this.#adapter.model;
		if (modelPort === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated model adapter is unavailable.");
		const context = createContext(this.#configuration, this.#identifiers.requestId(MODEL_LIST), { operationId: MODEL_LIST });
		const result = await this.#invoke(() => modelPort.list(context));
		if (!isModelListResult(result)) throw adapterContractFailure("studio.host/unexpected-model-result", "The model port returned a malformed or duplicate model collection.");
		return {
			...result.revision === void 0 ? {} : { revision: result.revision },
			value: cloneContractValue(result.value).sort(compareModelCoordinates)
		};
	}
	async #saveSnapshot(snapshot, stateVersion) {
		this.#assertActive();
		const expectedRevision = this.#revision;
		const document = {
			...snapshot,
			revision: expectedRevision
		};
		const fingerprint = mutationFingerprint(document, this.#configuration, expectedRevision);
		const idempotencyKey = this.#mutationKey(ARTIFACT_SAVE, fingerprint);
		const context = createContext(this.#configuration, this.#identifiers.requestId(ARTIFACT_SAVE), {
			expectedRevision,
			idempotencyKey,
			operationId: ARTIFACT_SAVE
		});
		const result = await this.#invoke(() => this.#adapter.artifact.save(document, context));
		if (result.value !== null || !isRevision(result.revision)) throw adapterContractFailure("studio.host/missing-accepted-revision", "The artifact save did not return its accepted revision.");
		this.#revision = result.revision;
		this.session.markSaved(result.revision, stateVersion);
		this.#clearMutationKey(ARTIFACT_SAVE, fingerprint);
		return {
			revision: result.revision,
			value: null
		};
	}
	async #searchResources(query) {
		this.#assertActive();
		if (!isResourceSearchQuery(query)) throw new StudioHostSessionError("invalid-resource-query", "A resource search requires a canonical resource type, bounded limit, cursor, and search text.");
		const resource = this.#adapter.resource;
		if (resource === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated resource adapter is unavailable.");
		const querySnapshot = cloneContractValue(query);
		const context = createContext(this.#configuration, this.#identifiers.requestId(RESOURCE_SEARCH), { operationId: RESOURCE_SEARCH });
		const result = await this.#invoke(() => resource.search(querySnapshot, context));
		if (!isResourceSearchResult(result, querySnapshot)) throw adapterContractFailure("studio.host/unexpected-resource-result", "The resource port returned a malformed, mismatched, duplicate, or oversized search page.");
		return {
			...result.revision === void 0 ? {} : { revision: result.revision },
			value: cloneContractValue(result.value)
		};
	}
	async #storeRecovery(envelope) {
		this.#assertActive();
		const recovery = this.#adapter.recovery;
		if (recovery === void 0) throw adapterContractFailure("studio.host/adapter-port-unavailable", "The negotiated recovery adapter is unavailable.");
		const value = cloneContractValue(envelope);
		const fingerprint = mutationFingerprint(value, this.#configuration);
		const idempotencyKey = this.#mutationKey(RECOVERY_STORE, fingerprint);
		const context = createContext(this.#configuration, this.#identifiers.requestId(RECOVERY_STORE), {
			idempotencyKey,
			operationId: RECOVERY_STORE
		});
		const result = await this.#invoke(() => recovery.store(value, context));
		this.#clearMutationKey(RECOVERY_STORE, fingerprint);
		return result;
	}
	#assertActive() {
		if (this.#invalidationFailure !== void 0) throw this.#invalidationFailure;
		if (this.#disposed) throw new StudioHostSessionError("disposed", "The Studio host session is disposed.");
	}
	#clearMutationKey(operationId, fingerprint) {
		if (this.#retryIntents.get(operationId)?.fingerprint === fingerprint) this.#retryIntents.delete(operationId);
	}
	async #invoke(operation) {
		this.#assertActive();
		try {
			return await operation();
		} catch (error) {
			const failure = normalizeHostRejection(error);
			if (isStaleGenerationFailure(failure)) this.#invalidationFailure = failure;
			throw failure;
		}
	}
	#mutationKey(operationId, fingerprint) {
		const prior = this.#retryIntents.get(operationId);
		if (prior?.fingerprint === fingerprint) return prior.idempotencyKey;
		const idempotencyKey = this.#identifiers.idempotencyKey(operationId, fingerprint);
		this.#retryIntents.set(operationId, {
			fingerprint,
			idempotencyKey
		});
		return idempotencyKey;
	}
};
var SessionIdentifierAllocator = class {
	#factories;
	#idempotencyIntents = /* @__PURE__ */ new Map();
	#requestIds = /* @__PURE__ */ new Set();
	constructor(factories) {
		this.#factories = factories;
	}
	dispose() {
		this.#idempotencyIntents.clear();
		this.#requestIds.clear();
	}
	idempotencyKey(operationId, fingerprint) {
		const value = allocateIdentifier((candidateOperationId) => this.#factories.idempotencyKey(candidateOperationId), operationId, "idempotency");
		const scope = `${operationId}\u0000${value}`;
		const prior = this.#idempotencyIntents.get(scope);
		if (prior !== void 0 && prior !== fingerprint) throw new StudioHostSessionError("invalid-identifier", "The idempotency-key factory reused a key for another mutation intent.");
		this.#idempotencyIntents.set(scope, fingerprint);
		return value;
	}
	requestId(operationId) {
		const value = allocateIdentifier((candidateOperationId) => this.#factories.requestId(candidateOperationId), operationId, "request");
		if (this.#requestIds.has(value)) throw new StudioHostSessionError("invalid-identifier", "The request-ID factory returned an identifier already used by this session.");
		this.#requestIds.add(value);
		return value;
	}
};
function allocateIdentifier(factory, operationId, purpose) {
	let value;
	try {
		value = factory(operationId);
	} catch {
		throw new StudioHostSessionError("invalid-identifier", `The ${purpose}-ID factory failed to allocate an identifier.`);
	}
	if (!isStableId$1(value)) throw new StudioHostSessionError("invalid-identifier", `The ${purpose}-ID factory returned a non-canonical stable identifier.`);
	return value;
}
function appendOperationDiagnostics(adapter, configuration, negotiation) {
	const artifact = configuration.hostCapabilities.ports.find((entry) => entry.id === ARTIFACT_PORT);
	if (artifact !== void 0) {
		const requiredOperations = [ARTIFACT_LOAD];
		if (configuration.sessionState === "editable") requiredOperations.push(ARTIFACT_SAVE);
		for (const operationId of requiredOperations) if (!artifact.operations.includes(operationId)) negotiation.diagnostics.push(createDiagnostic("studio.host/missing-required-operation", `The host does not advertise the required ${operationId} operation.`, "blocking", { operationId }));
	}
	if (!configuration.features.offlineRecovery) return false;
	const recovery = configuration.hostCapabilities.ports.find((entry) => entry.id === RECOVERY_PORT);
	if (recovery === void 0) return false;
	let available = true;
	for (const operationId of [
		RECOVERY_STORE,
		RECOVERY_LOAD,
		RECOVERY_DISCARD
	]) if (!recovery.operations.includes(operationId)) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/missing-optional-operation", `The optional recovery port omits ${operationId}; recovery is disabled.`, "information", { operationId }));
	}
	if (adapter.recovery === void 0) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/adapter-port-unavailable", "The capability document advertises recovery but the adapter does not implement it.", "information", { port: RECOVERY_PORT }));
	}
	return available;
}
function appendModelOperationDiagnostics(adapter, configuration, negotiation) {
	const model = configuration.hostCapabilities.ports.find((entry) => entry.id === MODEL_PORT);
	if (model === void 0) return false;
	let available = true;
	for (const operationId of [MODEL_LIST, MODEL_GET]) if (!model.operations.includes(operationId)) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/missing-optional-operation", `The model port omits ${operationId}; model binding is disabled.`, "information", { operationId }));
	}
	if (adapter.model === void 0) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/adapter-port-unavailable", "The capability document advertises model reads but the adapter does not implement them.", "information", { port: MODEL_PORT }));
	}
	return available;
}
function appendResourceOperationDiagnostics(adapter, configuration, negotiation) {
	const resource = configuration.hostCapabilities.ports.find((entry) => entry.id === RESOURCE_PORT);
	if (resource === void 0) return false;
	let available = true;
	if (!resource.operations.includes(RESOURCE_SEARCH)) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/missing-optional-operation", `The resource port omits ${RESOURCE_SEARCH}; resource discovery is disabled.`, "information", { operationId: RESOURCE_SEARCH }));
	}
	if (adapter.resource === void 0) {
		available = false;
		negotiation.diagnostics.push(createDiagnostic("studio.host/adapter-port-unavailable", "The capability document advertises resource discovery but the adapter does not implement it.", "information", { port: RESOURCE_PORT }));
	}
	return available;
}
function appendProfileDiagnostics(configuration, negotiation) {
	if (configuration.artifacts.blueprint === void 0) negotiation.diagnostics.push(createDiagnostic("studio.host/missing-blueprint-artifact", "A Blueprint session requires a locked Blueprint artifact reference.", "blocking"));
	if (configuration.mode !== "blueprint" || configuration.composite !== "single") negotiation.diagnostics.push(createDiagnostic("studio.host/unsupported-session-profile", "This host-session profile opens only single Blueprint configurations.", "blocking", {
		composite: configuration.composite,
		mode: configuration.mode
	}));
}
function requestedOptionalPorts(configuration, optionalPorts) {
	const ports = new Set(optionalPorts ?? []);
	ports.delete(ARTIFACT_PORT);
	if (configuration.features.offlineRecovery) ports.add(RECOVERY_PORT);
	if (configuration.hostCapabilities.ports.some((entry) => entry.id === MODEL_PORT)) ports.add(MODEL_PORT);
	if (configuration.hostCapabilities.ports.some((entry) => entry.id === RESOURCE_PORT)) ports.add(RESOURCE_PORT);
	return [...ports];
}
function createContext(configuration, requestId, options) {
	return {
		...options.expectedRevision === void 0 ? {} : { expectedRevision: options.expectedRevision },
		...options.idempotencyKey === void 0 ? {} : { idempotencyKey: options.idempotencyKey },
		locale: configuration.locale.resolved,
		operationId: options.operationId,
		protocolVersion: configuration.protocolVersion,
		requestId,
		resourceContextKey: configuration.resourceContext.key,
		sessionGeneration: configuration.sessionGeneration
	};
}
function createDiagnostic(code, defaultMessage, severity, parameters) {
	return {
		code,
		message: {
			defaultMessage,
			key: code
		},
		...parameters === void 0 ? {} : { parameters },
		severity
	};
}
function normalizeLoadedBlueprint(document, resultRevision) {
	const revision = resultRevision ?? document.revision;
	if (!isRevision(revision)) throw new StudioHostSessionError("unexpected-artifact", "The loaded Blueprint does not carry a valid accepted revision.", [createDiagnostic("studio.host/missing-accepted-revision", "The loaded Blueprint does not carry a valid accepted revision.", "blocking")]);
	return cloneContractValue({
		...document,
		revision
	});
}
function isBlueprintLoadResult(value, artifactId) {
	if (typeof value !== "object" || value === null || Array.isArray(value) || !("value" in value)) return false;
	const document = value.value;
	return typeof document === "object" && document !== null && !Array.isArray(document) && "kind" in document && document.kind === "blueprint" && "id" in document && document.id === artifactId;
}
function isModelGetResult(value, reference) {
	if (!isHostResultRecord(value) || !isContentModelDocument(value.value)) return false;
	const expectedRevision = lockedReferenceRevision(reference);
	return value.value.id === reference.id && value.value.version === reference.version && (expectedRevision === void 0 || value.value.revision === expectedRevision) && (value.revision === void 0 || value.revision === value.value.revision);
}
function isModelListResult(value) {
	if (!isHostResultRecord(value) || !Array.isArray(value.value)) return false;
	const coordinates = /* @__PURE__ */ new Set();
	for (const model of value.value) {
		if (!isContentModelDocument(model)) return false;
		const coordinate = `${model.id}\u0000${model.version}\u0000${model.revision}`;
		if (coordinates.has(coordinate)) return false;
		coordinates.add(coordinate);
	}
	return true;
}
function isResourceSearchQuery(value) {
	if (!isPlainRecord(value) || !hasExactKeys(value, ["limit", "resourceType"], ["cursor", "search"])) return false;
	return typeof value.limit === "number" && Number.isSafeInteger(value.limit) && value.limit >= STUDIO_RESOURCE_SEARCH_LIMITS.minimumLimit && value.limit <= STUDIO_RESOURCE_SEARCH_LIMITS.maximumLimit && isQualifiedName$1(value.resourceType) && isBoundedOptionalString(value.cursor, STUDIO_RESOURCE_SEARCH_LIMITS.maximumCursorLength, false) && isBoundedOptionalString(value.search, STUDIO_RESOURCE_SEARCH_LIMITS.maximumSearchLength, true);
}
function isResourceSearchResult(value, query) {
	if (!isHostResultRecord(value) || !isResourceSearchPage(value.value, query)) return false;
	return true;
}
function isResourceSearchPage(value, query) {
	if (!isPlainRecord(value) || !hasExactKeys(value, ["items"], ["nextCursor"])) return false;
	if (!Array.isArray(value.items) || value.items.length > query.limit || !isBoundedOptionalString(value.nextCursor, STUDIO_RESOURCE_SEARCH_LIMITS.maximumCursorLength, false)) return false;
	const identifiers = /* @__PURE__ */ new Set();
	for (const item of value.items) {
		if (!isResourceSearchHit(item, query.resourceType) || identifiers.has(item.id)) return false;
		identifiers.add(item.id);
	}
	return true;
}
function isResourceSearchHit(value, resourceType) {
	return isPlainRecord(value) && hasExactKeys(value, [
		"id",
		"label",
		"resourceType"
	]) && isStableId$1(value.id) && value.resourceType === resourceType && isMessageReference$1(value.label);
}
function isMessageReference$1(value) {
	return isPlainRecord(value) && hasExactKeys(value, ["key"], ["defaultMessage"]) && isQualifiedName$1(value.key) && (value.defaultMessage === void 0 || typeof value.defaultMessage === "string" && value.defaultMessage.length >= 1 && value.defaultMessage.length <= 500);
}
function isQualifiedName$1(value) {
	return typeof value === "string" && value.length <= 160 && QUALIFIED_NAME.test(value);
}
function isBoundedOptionalString(value, maximumLength, allowEmpty) {
	return value === void 0 || typeof value === "string" && value.length <= maximumLength && (allowEmpty || value.length >= 1);
}
function isPlainRecord(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}
function hasExactKeys(value, required, optional = []) {
	const allowed = /* @__PURE__ */ new Set([...required, ...optional]);
	return required.every((key) => Object.hasOwn(value, key)) && Object.keys(value).every((key) => allowed.has(key));
}
function isHostResultRecord(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value) || !("value" in value)) return false;
	return !("revision" in value) || isRevision(value.revision);
}
function isContentModelDocument(value) {
	return validateContentModelSchema.validate(value);
}
function isArtifactReference(value) {
	return validateArtifactReferenceSchema.validate(value);
}
function lockedReferenceRevision(reference) {
	const candidate = reference;
	return isRevision(candidate.revision) ? candidate.revision : void 0;
}
function compareModelCoordinates(left, right) {
	return compareCodeUnits$1(left.id, right.id) || compareCodeUnits$1(left.version, right.version) || compareCodeUnits$1(left.revision, right.revision);
}
function compareCodeUnits$1(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
function mutationFingerprint(argument, configuration, expectedRevision) {
	return canonicalStringify({
		argument,
		context: {
			...expectedRevision === void 0 ? {} : { expectedRevision },
			locale: configuration.locale.resolved,
			protocolVersion: configuration.protocolVersion
		}
	});
}
async function invokeOpeningHostCall(operation) {
	try {
		return await operation();
	} catch (error) {
		throw normalizeHostRejection(error);
	}
}
function normalizeHostRejection(error) {
	if (isHostPortFailure(error)) return error;
	return adapterContractFailure("studio.host/invalid-failure-wrapper", "The host adapter rejected without a canonical HostPortFailure.");
}
function adapterContractFailure(code, defaultMessage) {
	return new HostPortFailure({
		category: "internal",
		contractVersion: STUDIO_CONTRACT_VERSION,
		diagnostics: [createDiagnostic(code, defaultMessage, "error")],
		kind: "host-error",
		message: {
			defaultMessage,
			key: code
		},
		retryable: false
	});
}
function isStaleGenerationFailure(failure) {
	return failure.error.category === "invalid-request" && (failure.error.diagnostics?.some((entry) => entry.code === "studio.host/stale-session-generation") ?? false);
}
function cloneNegotiation(value) {
	return {
		availablePorts: [...value.availablePorts],
		diagnostics: cloneContractValue(value.diagnostics),
		missingOptionalPorts: [...value.missingOptionalPorts],
		missingRequiredPorts: [...value.missingRequiredPorts],
		...value.protocolVersion === void 0 ? {} : { protocolVersion: value.protocolVersion },
		sessionState: value.sessionState
	};
}
function isStableId$1(value) {
	return typeof value === "string" && value.length >= 1 && value.length <= 240 && !FORBIDDEN_IDENTIFIERS.has(value) && STABLE_ID.test(value);
}
function isRevision(value) {
	return typeof value === "string" && value.length >= 1 && value.length <= 200;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/recipes.js
/**
* The reserved node property that records which theme recipe an author
* selected. Recipe selection is canonically an atomic batch of set-property
* operations, so it inherits batch atomicity and verified inverses from the
* command contract instead of introducing a new command type.
*/
var RECIPE_MARKER_PROPERTY = "studio.recipe";
/**
* Expand a theme recipe selection into the deterministic batch operations
* that apply it to one node: every design value of the recipe, in sorted
* member order, followed by the reserved recipe marker property.
*/
function recipeSelectionOperations(node, theme, recipeId) {
	const recipe = theme.recipes.find((candidate) => candidate.id === recipeId);
	if (recipe === void 0) throw new Error(`Theme ${theme.id} does not declare a recipe ${recipeId}.`);
	if (recipe.blockType !== node.type) throw new Error(`Recipe ${recipeId} targets ${recipe.blockType} blocks, not ${node.type} node ${node.id}.`);
	const operations = Object.entries(recipe.designValues).sort(([left], [right]) => left < right ? -1 : 1).map(([property, value]) => ({
		payload: {
			nodeId: node.id,
			property,
			value: cloneContractValue(value)
		},
		type: "studio.command/set-property"
	}));
	operations.push({
		payload: {
			nodeId: node.id,
			property: RECIPE_MARKER_PROPERTY,
			value: recipeId
		},
		type: "studio.command/set-property"
	});
	return operations;
}
//#endregion
//#region node_modules/@kumwe/studio-core/dist/validation.js
var validateBlueprintSchema = compileProfileSchema(blueprintSchema, { schemas: [commonSchema] });
var propertyValidators = /* @__PURE__ */ new WeakMap();
var MAX_BLUEPRINT_JSON_BYTES = 16777216;
var MAX_BLUEPRINT_JSON_DEPTH = 64;
var MAX_BLUEPRINT_JSON_VALUES = 1e6;
var MAX_JSON_CONTAINER_ENTRIES = 1e4;
function validateBlueprint(document, registry, options = {}) {
	const diagnostics = [];
	const maximumDepth = validationLimit(options.maximumDepth, 32, "maximumDepth");
	const maximumNodes = validationLimit(options.maximumNodes, 5e3, "maximumNodes");
	const nodePreflightDiagnostics = preflightBlueprintNodes(document, maximumDepth, maximumNodes);
	if (nodePreflightDiagnostics.length > 0) return {
		diagnostics: nodePreflightDiagnostics,
		valid: false
	};
	const valuePreflightDiagnostic = preflightBlueprintJson(document);
	if (valuePreflightDiagnostic !== void 0) return {
		diagnostics: [valuePreflightDiagnostic],
		valid: false
	};
	if (!validateBlueprintSchema.validate(document)) {
		diagnostics.push(...schemaDiagnostics(validateBlueprintSchema.errors));
		return {
			diagnostics,
			valid: false
		};
	}
	const blueprint = document;
	const identifiers = /* @__PURE__ */ new Set();
	const blockLocks = indexBlockLocks(blueprint.dependencyLock.blocks, diagnostics);
	let nodeCount = 0;
	const stack = blueprint.roots.map((node) => ({
		depth: 1,
		node
	})).reverse();
	while (stack.length > 0) {
		const frame = stack.pop();
		if (frame === void 0) break;
		const { depth, node } = frame;
		nodeCount += 1;
		if (nodeCount > maximumNodes) break;
		if (depth > maximumDepth) {
			diagnostics.push(diagnostic("maximum-depth", `Node depth exceeds the configured limit of ${maximumDepth}.`, node.id));
			continue;
		}
		if (identifiers.has(node.id)) diagnostics.push(diagnostic("duplicate-node-id", `Node identifier ${node.id} is not unique.`, node.id));
		identifiers.add(node.id);
		const registration = registry.resolveRegistration(node.type, node.version);
		if (registration === void 0) diagnostics.push(diagnostic("block-unavailable", `Block ${node.type}@${node.version} is not registered.`, node.id));
		else {
			validateBlockLock(node, registration.definition, registration.verifiedIntegrity, blockLocks.get(blockKey(node.type, node.version)), diagnostics);
			validateNodeProperties(node, registration.definition, registry, diagnostics);
			validateSlots(node, registration.definition, diagnostics);
		}
		validateBindings(node, options.fieldPaths, diagnostics);
		const childCollections = Object.values(node.slots);
		for (let collectionIndex = childCollections.length - 1; collectionIndex >= 0; collectionIndex -= 1) {
			const children = childCollections[collectionIndex];
			if (children === void 0) continue;
			for (let childIndex = children.length - 1; childIndex >= 0; childIndex -= 1) {
				const child = children[childIndex];
				if (child !== void 0) stack.push({
					depth: depth + 1,
					node: child
				});
			}
		}
	}
	if (nodeCount > maximumNodes) diagnostics.push(diagnostic("maximum-nodes", `Blueprint contains more than the configured limit of ${maximumNodes} nodes.`));
	return {
		diagnostics,
		valid: diagnostics.every((entry) => entry.severity !== "blocking" && entry.severity !== "error")
	};
}
function preflightBlueprintNodes(document, maximumDepth, maximumNodes) {
	if (!isRecord$4(document) || !Array.isArray(document.roots)) return [];
	const roots = document.roots;
	if (roots.length > maximumNodes) return [diagnostic("maximum-nodes", `Blueprint contains more than the configured limit of ${maximumNodes} nodes.`)];
	const seen = /* @__PURE__ */ new WeakSet();
	let scheduled = roots.length;
	const stack = roots.map((value) => ({
		depth: 1,
		value
	})).reverse();
	while (stack.length > 0) {
		const frame = stack.pop();
		if (frame === void 0 || !isRecord$4(frame.value)) continue;
		if (seen.has(frame.value)) return [diagnostic("cyclic-blueprint", "Blueprint nodes must form an acyclic JSON tree.")];
		seen.add(frame.value);
		if (frame.depth > maximumDepth) return [diagnostic("maximum-depth", `Node depth exceeds the configured limit of ${maximumDepth}.`, typeof frame.value.id === "string" ? frame.value.id : void 0)];
		if (!isRecord$4(frame.value.slots)) continue;
		for (const children of Object.values(frame.value.slots)) {
			if (!Array.isArray(children)) continue;
			scheduled += children.length;
			if (scheduled > maximumNodes) return [diagnostic("maximum-nodes", `Blueprint contains more than the configured limit of ${maximumNodes} nodes.`)];
			for (let index = children.length - 1; index >= 0; index -= 1) stack.push({
				depth: frame.depth + 1,
				value: children[index]
			});
		}
	}
	return [];
}
function preflightBlueprintJson(document) {
	const seen = /* @__PURE__ */ new WeakSet();
	const stack = [{
		depth: 0,
		value: document
	}];
	let approximateBytes = 0;
	let valueCount = 0;
	while (stack.length > 0) {
		const frame = stack.pop();
		if (frame === void 0) break;
		valueCount += 1;
		if (valueCount > MAX_BLUEPRINT_JSON_VALUES) return diagnostic("maximum-json-values", `Blueprint exceeds the fixed alpha limit of ${MAX_BLUEPRINT_JSON_VALUES} JSON values.`);
		if (frame.depth > MAX_BLUEPRINT_JSON_DEPTH) return diagnostic("maximum-value-depth", `Blueprint JSON value depth exceeds the fixed alpha limit of ${MAX_BLUEPRINT_JSON_DEPTH}.`);
		const { value } = frame;
		if (value === null) approximateBytes += 4;
		else if (typeof value === "boolean") approximateBytes += value ? 4 : 5;
		else if (typeof value === "number" && Number.isFinite(value)) approximateBytes += String(value).length;
		else if (typeof value === "string") approximateBytes += jsonStringByteLength(value);
		else if (Array.isArray(value)) {
			if (!isDenseJsonArray(value)) return diagnostic("non-json-value", "Blueprint arrays must be dense JSON arrays.");
			if (value.length > MAX_JSON_CONTAINER_ENTRIES) return diagnostic("maximum-array-items", `Blueprint arrays cannot exceed ${MAX_JSON_CONTAINER_ENTRIES} items.`);
			if (seen.has(value)) return diagnostic("cyclic-blueprint", "Blueprint must be an acyclic JSON document.");
			seen.add(value);
			approximateBytes += value.length + 2;
			for (let index = value.length - 1; index >= 0; index -= 1) stack.push({
				depth: frame.depth + 1,
				value: value[index]
			});
		} else if (isJsonRecord(value)) {
			if (seen.has(value)) return diagnostic("cyclic-blueprint", "Blueprint must be an acyclic JSON document.");
			seen.add(value);
			const entries = Object.entries(value);
			if (entries.length > MAX_JSON_CONTAINER_ENTRIES) return diagnostic("maximum-object-properties", `Blueprint objects cannot exceed ${MAX_JSON_CONTAINER_ENTRIES} properties.`);
			approximateBytes += entries.length + 2;
			for (let index = entries.length - 1; index >= 0; index -= 1) {
				const entry = entries[index];
				if (entry === void 0) continue;
				const [key, child] = entry;
				if (!isSafeJsonMemberName(key)) return diagnostic("unsafe-json-member", "Blueprint contains an unsafe JSON object member name.");
				approximateBytes += jsonStringByteLength(key) + 1;
				stack.push({
					depth: frame.depth + 1,
					value: child
				});
			}
		} else return diagnostic("non-json-value", "Blueprint must contain only JSON-compatible values.");
		if (approximateBytes > MAX_BLUEPRINT_JSON_BYTES) return diagnostic("maximum-json-bytes", `Blueprint exceeds the fixed alpha limit of ${MAX_BLUEPRINT_JSON_BYTES} encoded bytes.`);
	}
}
function isDenseJsonArray(value) {
	if (Object.getPrototypeOf(value) !== Array.prototype || Object.getOwnPropertySymbols(value).length) return false;
	const ownNames = Object.getOwnPropertyNames(value);
	return ownNames.length === value.length + 1 && ownNames[value.length] === "length" && ownNames.slice(0, -1).every((name, index) => name === String(index));
}
function isJsonRecord(value) {
	if (!isRecord$4(value) || Object.getOwnPropertySymbols(value).length > 0) return false;
	const prototype = Object.getPrototypeOf(value);
	if (prototype !== Object.prototype && prototype !== null) return false;
	return Object.getOwnPropertyNames(value).length === Object.keys(value).length;
}
function isSafeJsonMemberName(value) {
	if (value.length === 0 || value.length > 200 || value === "__proto__" || value === "prototype" || value === "constructor") return false;
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code <= 31 || code === 127) return false;
	}
	return true;
}
function jsonStringByteLength(value) {
	let bytes = 2;
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code <= 31) bytes += 6;
		else if (code === 34 || code === 92) bytes += 2;
		else if (code <= 127) bytes += 1;
		else if (code <= 2047) bytes += 2;
		else if (code >= 55296 && code <= 56319) {
			const next = value.charCodeAt(index + 1);
			if (next >= 56320 && next <= 57343) {
				bytes += 4;
				index += 1;
			} else bytes += 3;
		} else bytes += 3;
	}
	return bytes;
}
function validationLimit(value, fallback, name) {
	const result = value ?? fallback;
	if (!Number.isInteger(result) || result < 1) throw new RangeError(`${name} must be a positive integer.`);
	return result;
}
function isRecord$4(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
function schemaDiagnostics(errors) {
	return (errors ?? []).map((error) => diagnostic(`schema-${error.keyword}`, error.message, void 0, error.instancePath));
}
function validateNodeProperties(node, definition, registry, diagnostics) {
	const key = `${definition.type}@${definition.version}`;
	let cache = propertyValidators.get(registry);
	if (cache === void 0) {
		cache = /* @__PURE__ */ new Map();
		propertyValidators.set(registry, cache);
	}
	let validator = cache.get(key);
	if (validator === void 0) {
		const compiled = compileProfileSchema(definition.propertySchema);
		cache.set(key, compiled);
		validator = compiled;
	}
	validateEffectiveProperties(node, validator, node.properties, void 0, diagnostics);
	const effective = /* @__PURE__ */ new Map();
	for (const property of Object.keys(node.responsive ?? {}).sort(compareCodeUnits)) {
		const overrides = node.responsive?.[property];
		if (overrides === void 0) continue;
		for (const viewport of Object.keys(overrides).sort(compareCodeUnits)) {
			const override = overrides[viewport];
			if (override === void 0) continue;
			const properties = effective.get(viewport) ?? { ...node.properties };
			properties[property] = override;
			effective.set(viewport, properties);
		}
	}
	for (const viewport of [...effective.keys()].sort(compareCodeUnits)) {
		const properties = effective.get(viewport);
		if (properties !== void 0) validateEffectiveProperties(node, validator, properties, viewport, diagnostics);
	}
}
function validateEffectiveProperties(node, validator, properties, viewport, diagnostics) {
	if (validator.validate(properties)) return;
	diagnostics.push(...schemaDiagnostics(validator.errors).map((entry) => ({
		...entry,
		code: `studio.validation/block-properties-${entry.code.split("/").at(-1) ?? "invalid"}`,
		location: {
			...entry.location,
			nodeId: node.id,
			...viewport === void 0 ? {} : { jsonPointer: responsivePropertyPointer(entry.location?.jsonPointer, viewport) }
		}
	})));
}
function responsivePropertyPointer(jsonPointer, viewport) {
	const segments = jsonPointer?.split("/").slice(1) ?? [];
	const property = segments.shift();
	if (property === void 0) return `/responsive/${escapePointerToken(viewport)}`;
	return [
		"",
		"responsive",
		property,
		escapePointerToken(viewport),
		...segments
	].join("/");
}
function escapePointerToken(value) {
	return value.replaceAll("~", "~0").replaceAll("/", "~1");
}
function indexBlockLocks(locks, diagnostics) {
	const indexed = /* @__PURE__ */ new Map();
	for (const lock of locks) {
		const key = blockKey(lock.type, lock.version);
		if (indexed.has(key)) diagnostics.push(diagnostic("block-lock-duplicate", `Blueprint dependency lock repeats block ${lock.type}@${lock.version}.`));
		else indexed.set(key, lock);
	}
	return indexed;
}
function validateBlockLock(node, definition, verifiedIntegrity, lock, diagnostics) {
	if (lock === void 0) {
		diagnostics.push(diagnostic("block-lock-missing", `Block ${node.type}@${node.version} is absent from the Blueprint dependency lock.`, node.id));
		return;
	}
	if (lock.revision !== definition.revision) diagnostics.push(diagnostic("block-lock-revision-mismatch", `Block ${node.type}@${node.version} resolves to revision ${definition.revision}, not locked revision ${lock.revision}.`, node.id));
	if (lock.integrity !== void 0 && verifiedIntegrity === void 0) diagnostics.push(diagnostic("block-lock-integrity-unverified", `Block ${node.type}@${node.version} has a locked integrity value that the registry cannot verify.`, node.id));
	else if (lock.integrity !== void 0 && lock.integrity !== verifiedIntegrity) diagnostics.push(diagnostic("block-lock-integrity-mismatch", `Block ${node.type}@${node.version} does not match its locked integrity value.`, node.id));
}
function blockKey(type, version) {
	return `${type}@${version}`;
}
function validateSlots(node, definition, diagnostics) {
	const slots = new Map(definition.slots.map((slot) => [slot.id, slot]));
	for (const [slotName, children] of Object.entries(node.slots)) {
		const slot = slots.get(slotName);
		if (slot === void 0) {
			diagnostics.push(diagnostic("slot-unknown", `Slot ${slotName} is not declared by ${node.type}.`, node.id));
			continue;
		}
		if (children.length > slot.maximum) diagnostics.push(diagnostic("slot-maximum", `Slot ${slotName} accepts at most ${slot.maximum} children.`, node.id));
		for (const child of children) if (slot.accepts.types !== void 0 && !slot.accepts.types.includes(child.type)) diagnostics.push(diagnostic("slot-rejects-type", `Slot ${slotName} does not accept ${child.type}.`, child.id));
	}
	for (const slot of slots.values()) if ((Object.hasOwn(node.slots, slot.id) ? node.slots[slot.id]?.length ?? 0 : 0) < slot.minimum) diagnostics.push(diagnostic("slot-minimum", `Slot ${slot.id} requires at least ${slot.minimum} children.`, node.id));
}
function validateBindings(node, fieldPaths, diagnostics) {
	if (fieldPaths === void 0) return;
	for (const binding of Object.values(node.bindings)) {
		if (binding.source.kind !== "entry-field") continue;
		const path = binding.source.fieldPath.join(".");
		if (!fieldPaths.has(path)) diagnostics.push(diagnostic("field-unavailable", `Field ${path} is not available to this Studio configuration.`, node.id));
	}
}
function diagnostic(name, message, nodeId, jsonPointer) {
	const result = {
		code: `studio.validation/${name}`,
		message: {
			defaultMessage: message,
			key: `studio.validation/${name}`
		},
		severity: "error"
	};
	if (nodeId !== void 0 || jsonPointer !== void 0) {
		result.location = {};
		if (nodeId !== void 0) result.location.nodeId = nodeId;
		if (jsonPointer !== void 0) result.location.jsonPointer = jsonPointer;
	}
	return result;
}
function compareCodeUnits(left, right) {
	return left < right ? -1 : left > right ? 1 : 0;
}
var en_default = {
	$schema: "https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json",
	kind: "authoring-message-catalog",
	contractVersion: "0.1-draft",
	catalogVersion: "1.4.0",
	locale: "en",
	messages: /* @__PURE__ */ JSON.parse("{\"studio.shell/announce-binding-removed\":{\"defaultMessage\":\"Removed the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/announce-binding-set\":{\"defaultMessage\":\"Set the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/announce-canvas-mode\":{\"defaultMessage\":\"Canvas mode: {state}\",\"parameters\":[\"state\"]},\"studio.shell/announce-command-failed\":{\"defaultMessage\":\"Command failed: {message}\",\"parameters\":[\"message\"]},\"studio.shell/announce-conflict\":{\"defaultMessage\":\"The change was rejected: {message} The document is unchanged; refresh the session or undo before retrying.\",\"parameters\":[\"message\"]},\"studio.shell/announce-deleted\":{\"defaultMessage\":\"Deleted {label} block\",\"parameters\":[\"label\"]},\"studio.shell/announce-drag-cancelled\":{\"defaultMessage\":\"Reorder cancelled. {label} kept its position.\",\"parameters\":[\"label\"]},\"studio.shell/announce-dropped\":{\"defaultMessage\":\"Moved {label} to position {position} of {count}\",\"parameters\":[\"count\",\"label\",\"position\"]},\"studio.shell/announce-duplicated\":{\"defaultMessage\":\"Duplicated {label}\",\"parameters\":[\"label\"]},\"studio.shell/announce-edit-cancelled\":{\"defaultMessage\":\"Edit cancelled. {property} kept its value.\",\"parameters\":[\"property\"]},\"studio.shell/announce-field-bound\":{\"defaultMessage\":\"Bound {port} to the {field} model field\",\"parameters\":[\"field\",\"port\"]},\"studio.shell/announce-inheritance-reset\":{\"defaultMessage\":\"Reset every responsive override for {property}; all viewports now inherit the base value\",\"parameters\":[\"property\"]},\"studio.shell/announce-inserted\":{\"defaultMessage\":\"Inserted {label}\",\"parameters\":[\"label\"]},\"studio.shell/announce-invalid-value\":{\"defaultMessage\":\"The {label} value is not valid JSON. Nothing was changed.\",\"parameters\":[\"label\"]},\"studio.shell/announce-moved-down\":{\"defaultMessage\":\"Moved {label} down\",\"parameters\":[\"label\"]},\"studio.shell/announce-moved-to\":{\"defaultMessage\":\"Moved {label} to {destination}\",\"parameters\":[\"destination\",\"label\"]},\"studio.shell/announce-moved-up\":{\"defaultMessage\":\"Moved {label} up\",\"parameters\":[\"label\"]},\"studio.shell/announce-name-required\":{\"defaultMessage\":\"Enter a name before applying the change.\",\"parameters\":[]},\"studio.shell/announce-override-removed\":{\"defaultMessage\":\"Removed the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/announce-override-set\":{\"defaultMessage\":\"Set the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/announce-pattern-applied\":{\"defaultMessage\":\"Applied the {pattern} pattern\",\"parameters\":[\"pattern\"]},\"studio.shell/announce-preview-reloaded\":{\"defaultMessage\":\"The preview reloaded ({reason}). The document is unchanged.\",\"parameters\":[\"reason\"]},\"studio.shell/announce-preview-torn-down\":{\"defaultMessage\":\"The preview closed ({reason}). The document is unchanged.\",\"parameters\":[\"reason\"]},\"studio.shell/announce-property-set\":{\"defaultMessage\":\"Set {property}\",\"parameters\":[\"property\"]},\"studio.shell/announce-property-unset\":{\"defaultMessage\":\"Unset {property}\",\"parameters\":[\"property\"]},\"studio.shell/announce-recipe-applied\":{\"defaultMessage\":\"Applied the {recipe} recipe\",\"parameters\":[\"recipe\"]},\"studio.shell/announce-redid\":{\"defaultMessage\":\"Redid change\",\"parameters\":[]},\"studio.shell/announce-restored\":{\"defaultMessage\":\"Restored {label} block\",\"parameters\":[\"label\"]},\"studio.shell/announce-selection-cleared\":{\"defaultMessage\":\"Selection cleared\",\"parameters\":[]},\"studio.shell/announce-size-role-invalid\":{\"defaultMessage\":\"The {axis} role must be a lower-case identifier such as half or full-width. Nothing was changed.\",\"parameters\":[\"axis\"]},\"studio.shell/announce-size-role-removed\":{\"defaultMessage\":\"Removed the {axis} role\",\"parameters\":[\"axis\"]},\"studio.shell/announce-size-role-removed-viewport\":{\"defaultMessage\":\"Removed the {axis} role for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/announce-size-role-set\":{\"defaultMessage\":\"Set the {axis} role to {role}\",\"parameters\":[\"axis\",\"role\"]},\"studio.shell/announce-size-role-set-viewport\":{\"defaultMessage\":\"Set the {axis} role to {role} for the {viewport} viewport\",\"parameters\":[\"axis\",\"role\",\"viewport\"]},\"studio.shell/announce-undid\":{\"defaultMessage\":\"Undid change\",\"parameters\":[]},\"studio.shell/announce-viewport-changed\":{\"defaultMessage\":\"Previewing the {label} viewport\",\"parameters\":[\"label\"]},\"studio.shell/block-actions\":{\"defaultMessage\":\"Block actions\",\"parameters\":[]},\"studio.shell/breadcrumb-label\":{\"defaultMessage\":\"Selection path\",\"parameters\":[]},\"studio.shell/canvas-edit-toggle\":{\"defaultMessage\":\"Select and move rendered blocks\",\"parameters\":[]},\"studio.shell/canvas-empty\":{\"defaultMessage\":\"Choose a block to begin composing.\",\"parameters\":[]},\"studio.shell/canvas-label\":{\"defaultMessage\":\"Blueprint structure\",\"parameters\":[]},\"studio.shell/canvas-mode-editing\":{\"defaultMessage\":\"selecting and moving blocks\",\"parameters\":[]},\"studio.shell/canvas-mode-interacting\":{\"defaultMessage\":\"interacting with the rendered preview\",\"parameters\":[]},\"studio.shell/command-apply-pattern\":{\"defaultMessage\":\"Apply pattern {pattern}\",\"parameters\":[\"pattern\"]},\"studio.shell/command-clear-selection\":{\"defaultMessage\":\"Clear selection\",\"parameters\":[]},\"studio.shell/command-insert\":{\"defaultMessage\":\"Insert {label}\",\"parameters\":[\"label\"]},\"studio.shell/command-move-to\":{\"defaultMessage\":\"Move to {destination}\",\"parameters\":[\"destination\"]},\"studio.shell/command-palette-empty\":{\"defaultMessage\":\"No commands match the filter.\",\"parameters\":[]},\"studio.shell/command-palette-hint\":{\"defaultMessage\":\"Type to filter commands. Arrow Down moves into the results, Arrow Up returns to the filter, Enter runs a command, Escape closes.\",\"parameters\":[]},\"studio.shell/command-palette-input-label\":{\"defaultMessage\":\"Filter commands\",\"parameters\":[]},\"studio.shell/command-palette-label\":{\"defaultMessage\":\"Command palette\",\"parameters\":[]},\"studio.shell/command-palette-results-label\":{\"defaultMessage\":\"Matching commands\",\"parameters\":[]},\"studio.shell/command-palette-toggle\":{\"defaultMessage\":\"Commands\",\"parameters\":[]},\"studio.shell/delete\":{\"defaultMessage\":\"Delete\",\"parameters\":[]},\"studio.shell/diagnostics-empty\":{\"defaultMessage\":\"No issues\",\"parameters\":[]},\"studio.shell/diagnostics-heading\":{\"defaultMessage\":\"Diagnostics\",\"parameters\":[]},\"studio.shell/document-roots\":{\"defaultMessage\":\"document roots\",\"parameters\":[]},\"studio.shell/drag-drop-position\":{\"defaultMessage\":\"Moving {label} to position {position} of {count}\",\"parameters\":[\"count\",\"label\",\"position\"]},\"studio.shell/duplicate\":{\"defaultMessage\":\"Duplicate\",\"parameters\":[]},\"studio.shell/history-label\":{\"defaultMessage\":\"History\",\"parameters\":[]},\"studio.shell/inspector-add-override\":{\"defaultMessage\":\"Add override\",\"parameters\":[]},\"studio.shell/inspector-add-override-name-label\":{\"defaultMessage\":\"Override property name\",\"parameters\":[]},\"studio.shell/inspector-add-override-value-label\":{\"defaultMessage\":\"Override value as JSON\",\"parameters\":[]},\"studio.shell/inspector-add-property\":{\"defaultMessage\":\"Add property\",\"parameters\":[]},\"studio.shell/inspector-add-property-name-label\":{\"defaultMessage\":\"New property name\",\"parameters\":[]},\"studio.shell/inspector-add-property-value-label\":{\"defaultMessage\":\"New property value as JSON\",\"parameters\":[]},\"studio.shell/inspector-binding-accepts\":{\"defaultMessage\":\"Accepts {cardinality} {value-type} value\",\"parameters\":[\"cardinality\",\"value-type\"]},\"studio.shell/inspector-binding-control-label\":{\"defaultMessage\":\"Declared {control} control for {field}\",\"parameters\":[\"control\",\"field\"]},\"studio.shell/inspector-binding-control-preview\":{\"defaultMessage\":\"Control preview\",\"parameters\":[]},\"studio.shell/inspector-binding-control-unavailable\":{\"defaultMessage\":\"The declared {control} control requires a host field-adapter contribution.\",\"parameters\":[\"control\"]},\"studio.shell/inspector-binding-control-undeclared\":{\"defaultMessage\":\"This field declares no authoring control.\",\"parameters\":[]},\"studio.shell/inspector-binding-field-placeholder\":{\"defaultMessage\":\"Choose a model field\",\"parameters\":[]},\"studio.shell/inspector-binding-invalid\":{\"defaultMessage\":\"This binding no longer resolves and requires migration.\",\"parameters\":[]},\"studio.shell/inspector-binding-model\":{\"defaultMessage\":\"Fields from locked model {model}\",\"parameters\":[\"model\"]},\"studio.shell/inspector-binding-model-mismatch\":{\"defaultMessage\":\"The projected model does not match the Blueprint lock. Binding choices are disabled; see diagnostics.\",\"parameters\":[]},\"studio.shell/inspector-binding-model-unavailable\":{\"defaultMessage\":\"The session advertises model reads, but no active model projection is loaded. Binding choices are disabled.\",\"parameters\":[]},\"studio.shell/inspector-binding-no-compatible-fields\":{\"defaultMessage\":\"No compatible model fields\",\"parameters\":[]},\"studio.shell/inspector-binding-non-field-source\":{\"defaultMessage\":\"This port uses a non-field source. Choosing a model field replaces that source explicitly.\",\"parameters\":[]},\"studio.shell/inspector-binding-port-label\":{\"defaultMessage\":\"Binding port name\",\"parameters\":[]},\"studio.shell/inspector-binding-required\":{\"defaultMessage\":\" (required)\",\"parameters\":[]},\"studio.shell/inspector-binding-value-label\":{\"defaultMessage\":\"Binding value as JSON\",\"parameters\":[]},\"studio.shell/inspector-bindings-empty\":{\"defaultMessage\":\"No bindings\",\"parameters\":[]},\"studio.shell/inspector-bindings-heading\":{\"defaultMessage\":\"Bindings\",\"parameters\":[]},\"studio.shell/inspector-design-heading\":{\"defaultMessage\":\"Design\",\"parameters\":[]},\"studio.shell/inspector-design-placeholder\":{\"defaultMessage\":\"Choose a token\",\"parameters\":[]},\"studio.shell/inspector-design-unset\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-empty\":{\"defaultMessage\":\"Select a block to inspect its contract.\",\"parameters\":[]},\"studio.shell/inspector-heading\":{\"defaultMessage\":\"Inspector\",\"parameters\":[]},\"studio.shell/inspector-hint\":{\"defaultMessage\":\"Inputs hold JSON values. Enter applies the edit, Escape reverts it.\",\"parameters\":[]},\"studio.shell/inspector-identifier\":{\"defaultMessage\":\"Identifier\",\"parameters\":[]},\"studio.shell/inspector-layout-axis-block\":{\"defaultMessage\":\"Block size\",\"parameters\":[]},\"studio.shell/inspector-layout-axis-inline\":{\"defaultMessage\":\"Inline size\",\"parameters\":[]},\"studio.shell/inspector-layout-base-none\":{\"defaultMessage\":\"Base: none\",\"parameters\":[]},\"studio.shell/inspector-layout-base-role\":{\"defaultMessage\":\"Base: {role}\",\"parameters\":[\"role\"]},\"studio.shell/inspector-layout-fallback-hint\":{\"defaultMessage\":\"No theme size-role vocabulary is available. Enter a lower-case role identifier; Enter applies it, Escape cancels.\",\"parameters\":[]},\"studio.shell/inspector-layout-heading\":{\"defaultMessage\":\"Layout\",\"parameters\":[]},\"studio.shell/inspector-layout-no-roles\":{\"defaultMessage\":\"The active theme declares no size roles, so none can be assigned.\",\"parameters\":[]},\"studio.shell/inspector-layout-role-label-base\":{\"defaultMessage\":\"{axis} role (base)\",\"parameters\":[\"axis\"]},\"studio.shell/inspector-layout-role-label-viewport\":{\"defaultMessage\":\"{axis} role override for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/inspector-layout-role-placeholder\":{\"defaultMessage\":\"Choose a role\",\"parameters\":[]},\"studio.shell/inspector-layout-unset\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-layout-unset-label-base\":{\"defaultMessage\":\"Remove the {axis} base role\",\"parameters\":[\"axis\"]},\"studio.shell/inspector-layout-unset-label-viewport\":{\"defaultMessage\":\"Remove the {axis} role override for the {viewport} viewport\",\"parameters\":[\"axis\",\"viewport\"]},\"studio.shell/inspector-override-value-label\":{\"defaultMessage\":\"Override of {property} for the {viewport} viewport as JSON\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/inspector-overrides-empty\":{\"defaultMessage\":\"No overrides for the {viewport} viewport\",\"parameters\":[\"viewport\"]},\"studio.shell/inspector-overrides-heading\":{\"defaultMessage\":\"Overrides for the {viewport} viewport\",\"parameters\":[\"viewport\"]},\"studio.shell/inspector-properties\":{\"defaultMessage\":\"Properties\",\"parameters\":[]},\"studio.shell/inspector-properties-empty\":{\"defaultMessage\":\"No properties\",\"parameters\":[]},\"studio.shell/inspector-property-value-label\":{\"defaultMessage\":\"Value of {property} as JSON\",\"parameters\":[\"property\"]},\"studio.shell/inspector-provenance-base\":{\"defaultMessage\":\"Base value\",\"parameters\":[]},\"studio.shell/inspector-provenance-inherited\":{\"defaultMessage\":\"Inherited from base: {value}\",\"parameters\":[\"value\"]},\"studio.shell/inspector-provenance-inherited-none\":{\"defaultMessage\":\"Inherited from base: none\",\"parameters\":[]},\"studio.shell/inspector-provenance-overridden\":{\"defaultMessage\":\"Overridden for the {viewport} viewport: {value}\",\"parameters\":[\"value\",\"viewport\"]},\"studio.shell/inspector-read-only\":{\"defaultMessage\":\"Editing is disabled because this session is read-only.\",\"parameters\":[]},\"studio.shell/inspector-recipe-label\":{\"defaultMessage\":\"Recipe\",\"parameters\":[]},\"studio.shell/inspector-recipe-placeholder\":{\"defaultMessage\":\"Choose a recipe\",\"parameters\":[]},\"studio.shell/inspector-recipes-heading\":{\"defaultMessage\":\"Recipes\",\"parameters\":[]},\"studio.shell/inspector-remove-binding\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-remove-binding-label\":{\"defaultMessage\":\"Remove the {port} binding\",\"parameters\":[\"port\"]},\"studio.shell/inspector-remove-override\":{\"defaultMessage\":\"Remove\",\"parameters\":[]},\"studio.shell/inspector-remove-override-label\":{\"defaultMessage\":\"Remove the {property} override for the {viewport} viewport\",\"parameters\":[\"property\",\"viewport\"]},\"studio.shell/inspector-reset-inheritance\":{\"defaultMessage\":\"Reset all viewport overrides\",\"parameters\":[]},\"studio.shell/inspector-set-binding\":{\"defaultMessage\":\"Set binding\",\"parameters\":[]},\"studio.shell/inspector-type\":{\"defaultMessage\":\"Type\",\"parameters\":[]},\"studio.shell/inspector-unset\":{\"defaultMessage\":\"Unset\",\"parameters\":[]},\"studio.shell/inspector-unset-label\":{\"defaultMessage\":\"Unset {property}\",\"parameters\":[\"property\"]},\"studio.shell/move-destination-label\":{\"defaultMessage\":\"Move block to another position or slot\",\"parameters\":[]},\"studio.shell/move-destination-option\":{\"defaultMessage\":\"{collection}, position {position} of {count}\",\"parameters\":[\"collection\",\"count\",\"position\"]},\"studio.shell/move-destination-placeholder\":{\"defaultMessage\":\"Choose a destination\",\"parameters\":[]},\"studio.shell/move-down\":{\"defaultMessage\":\"Move down\",\"parameters\":[]},\"studio.shell/move-slot-collection\":{\"defaultMessage\":\"{parent}: {slot} slot\",\"parameters\":[\"parent\",\"slot\"]},\"studio.shell/move-up\":{\"defaultMessage\":\"Move up\",\"parameters\":[]},\"studio.shell/outline-empty\":{\"defaultMessage\":\"The outline lists blocks once the document has content.\",\"parameters\":[]},\"studio.shell/outline-heading\":{\"defaultMessage\":\"Outline\",\"parameters\":[]},\"studio.shell/outline-hint\":{\"defaultMessage\":\"Arrow keys move focus. Alt+Arrow moves the block. Delete removes it. Ctrl+D or Cmd+D duplicates it.\",\"parameters\":[]},\"studio.shell/outline-slot\":{\"defaultMessage\":\"Slot: {slot}\",\"parameters\":[\"slot\"]},\"studio.shell/palette-heading\":{\"defaultMessage\":\"Blocks\",\"parameters\":[]},\"studio.shell/palette-label\":{\"defaultMessage\":\"Block palette\",\"parameters\":[]},\"studio.shell/patterns-heading\":{\"defaultMessage\":\"Patterns\",\"parameters\":[]},\"studio.shell/preview-closed\":{\"defaultMessage\":\"Preview is disconnected. Editing remains available.\",\"parameters\":[]},\"studio.shell/preview-connecting\":{\"defaultMessage\":\"Preview is connecting.\",\"parameters\":[]},\"studio.shell/preview-current\":{\"defaultMessage\":\"Preview is current.\",\"parameters\":[]},\"studio.shell/preview-heading\":{\"defaultMessage\":\"Preview\",\"parameters\":[]},\"studio.shell/preview-label\":{\"defaultMessage\":\"Rendered preview\",\"parameters\":[]},\"studio.shell/preview-rendering\":{\"defaultMessage\":\"Preview is updating.\",\"parameters\":[]},\"studio.shell/preview-stale\":{\"defaultMessage\":\"Preview is stale. Editing remains available.\",\"parameters\":[]},\"studio.shell/preview-unavailable\":{\"defaultMessage\":\"Preview is unavailable for this session. Editing remains available.\",\"parameters\":[]},\"studio.shell/redo\":{\"defaultMessage\":\"Redo\",\"parameters\":[]},\"studio.shell/restore-last-deleted\":{\"defaultMessage\":\"Restore last deleted block\",\"parameters\":[]},\"studio.shell/save-state-saved\":{\"defaultMessage\":\"Saved\",\"parameters\":[]},\"studio.shell/save-state-unsaved\":{\"defaultMessage\":\"Unsaved changes\",\"parameters\":[]},\"studio.shell/severity-blocking\":{\"defaultMessage\":\"Blocking\",\"parameters\":[]},\"studio.shell/severity-error\":{\"defaultMessage\":\"Error\",\"parameters\":[]},\"studio.shell/severity-information\":{\"defaultMessage\":\"Information\",\"parameters\":[]},\"studio.shell/severity-warning\":{\"defaultMessage\":\"Warning\",\"parameters\":[]},\"studio.shell/status-label\":{\"defaultMessage\":\"Status\",\"parameters\":[]},\"studio.shell/undo\":{\"defaultMessage\":\"Undo\",\"parameters\":[]},\"studio.shell/unresolved-block\":{\"defaultMessage\":\"(unresolved)\",\"parameters\":[]},\"studio.shell/viewport-label\":{\"defaultMessage\":\"Preview width\",\"parameters\":[]},\"studio.shell/visual-drop-target\":{\"defaultMessage\":\"Moving {label} to {destination}\",\"parameters\":[\"destination\",\"label\"]}}")
};
//#endregion
//#region node_modules/@kumwe/studio/dist/messages.js
/** Backwards-compatible message map used by existing shell integrations. */
var studioMessages = en_default.messages;
/**
* Resolves a shell string using a host override first and the catalog default
* otherwise. Studio shell messages deliberately use the named-interpolation
* subset of ICU MessageFormat. Unknown parameters are ignored and missing
* parameters remain visible so malformed translations never fail silently.
*/
function messageText$1(key, overrides, parameters) {
	let text = (overrides?.[key] ?? studioMessages[key]).defaultMessage;
	if (parameters === void 0) return text;
	for (const name of en_default.messages[key].parameters) {
		const value = parameters[name];
		if (value !== void 0) text = text.replaceAll(`{${name}}`, value);
	}
	return text;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/outline.js
function findOutlineLocation(roots, nodeId) {
	return findWithin(roots, nodeId, void 0, void 0);
}
/**
* Returns the chain of nodes from a document root down to `nodeId`
* (root first, the node itself last), or an empty array when the
* identifier does not occur in the tree.
*/
function findAncestry(roots, nodeId) {
	for (const node of roots) {
		if (node.id === nodeId) return [node];
		for (const children of Object.values(node.slots)) {
			const nested = findAncestry(children, nodeId);
			if (nested.length > 0) return [node, ...nested];
		}
	}
	return [];
}
function collectDocumentIds(roots) {
	const identifiers = /* @__PURE__ */ new Set();
	const stack = [...roots];
	while (stack.length > 0) {
		const current = stack.pop();
		if (current === void 0) break;
		identifiers.add(current.id);
		for (const children of Object.values(current.slots)) stack.push(...children);
	}
	return identifiers;
}
/**
* Allocates a deterministic, collision-free identifier map covering the whole
* subtree of `source`. Every identifier becomes `${id}-copy-${n}` where `n`
* is the lowest positive integer not already taken by the document or by an
* earlier allocation in the same map.
*/
function allocateDuplicateIdMap(roots, source) {
	const taken = collectDocumentIds(roots);
	const idMap = {};
	const queue = [source];
	while (queue.length > 0) {
		const current = queue.shift();
		if (current === void 0) break;
		let counter = 1;
		let candidate = `${current.id}-copy-${counter}`;
		while (taken.has(candidate)) {
			counter += 1;
			candidate = `${current.id}-copy-${counter}`;
		}
		taken.add(candidate);
		Object.defineProperty(idMap, current.id, {
			configurable: true,
			enumerable: true,
			value: candidate,
			writable: true
		});
		for (const children of Object.values(current.slots)) queue.push(...children);
	}
	return idMap;
}
function findWithin(collection, nodeId, parentNodeId, slot) {
	for (const [index, node] of collection.entries()) {
		if (node.id === nodeId) {
			const location = {
				collection,
				index,
				node
			};
			if (parentNodeId !== void 0 && slot !== void 0) {
				location.parentNodeId = parentNodeId;
				location.slot = slot;
			}
			return location;
		}
		for (const [slotName, children] of Object.entries(node.slots)) {
			const nested = findWithin(children, nodeId, node.id, slotName);
			if (nested !== void 0) return nested;
		}
	}
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-client.js
/** Stable client-side and wire failure surfaced by the preview channel. */
var PreviewChannelError = class extends Error {
	code;
	retryable;
	constructor(code, message, retryable = false) {
		super(message);
		this.name = "PreviewChannelError";
		this.code = code;
		this.retryable = retryable;
	}
};
function snapshotOutboundPayload(payload) {
	try {
		return structuredClone(payload);
	} catch {
		throw new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message.");
	}
}
var PreviewClient = class {
	#activationListeners = /* @__PURE__ */ new Set();
	#channelId;
	#listener;
	#listeners = /* @__PURE__ */ new Set();
	#markerInventory = /* @__PURE__ */ new Set();
	#pending = /* @__PURE__ */ new Map();
	#pendingMeasures = /* @__PURE__ */ new Map();
	#pendingReady = /* @__PURE__ */ new Set();
	#sessionGeneration;
	#source;
	#target;
	#targetOrigin;
	#timeoutMilliseconds;
	#usedRequestIds = /* @__PURE__ */ new Set();
	#disposed = false;
	#lastInboundSequence = -1;
	#latestRenderRequestId;
	#latestRenderedDigest;
	#readyPayload;
	#sequence = 0;
	constructor(options) {
		this.#targetOrigin = normalizeOrigin(options.targetOrigin);
		this.#channelId = options.channelId;
		this.#sessionGeneration = options.sessionGeneration;
		this.#source = options.source;
		this.#target = options.target;
		this.#timeoutMilliseconds = options.timeoutMilliseconds ?? 1e4;
		this.#listener = (event) => {
			this.#receive(event);
		};
		this.#source.addEventListener("message", this.#listener);
	}
	dispose() {
		if (this.#disposed) return;
		this.#disposed = true;
		this.#source.removeEventListener("message", this.#listener);
		for (const pending of this.#pending.values()) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		}
		this.#pending.clear();
		for (const pending of this.#pendingMeasures.values()) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		}
		this.#pendingMeasures.clear();
		for (const pending of this.#pendingReady) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		}
		this.#pendingReady.clear();
		this.#activationListeners.clear();
		this.#listeners.clear();
		this.#latestRenderRequestId = void 0;
		this.#latestRenderedDigest = void 0;
		this.#markerInventory.clear();
	}
	onMessage(listener) {
		this.#listeners.add(listener);
		return () => {
			this.#listeners.delete(listener);
		};
	}
	/**
	* Resolves once the host announces `studio.preview/ready` on this channel. If the
	* announcement was already received, the cached payload resolves immediately.
	*
	* `isPreviewMessage` accepts only ready payloads carrying the exact draft wire protocol
	* version, so an announcement from an incompatible host is filtered out and never resolves
	* this promise — the wait times out instead. The promise also rejects on abort or when the
	* client is disposed.
	*/
	ready(options = {}) {
		if (this.#disposed) return Promise.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		if (options.signal?.aborted === true) return Promise.reject(new Error("Preview ready wait was aborted.", { cause: options.signal.reason }));
		if (this.#readyPayload !== void 0) return Promise.resolve(this.#readyPayload);
		return new Promise((resolve, reject) => {
			const abort = () => {
				if (this.#pendingReady.delete(pending)) {
					clearTimeout(pending.timeout);
					pending.cleanup();
					pending.reject(/* @__PURE__ */ new Error("Preview ready wait was aborted."));
				}
			};
			const cleanup = () => {
				options.signal?.removeEventListener("abort", abort);
			};
			const pending = {
				cleanup,
				reject,
				resolve,
				timeout: setTimeout(() => {
					if (this.#pendingReady.delete(pending)) {
						pending.cleanup();
						pending.reject(/* @__PURE__ */ new Error("Preview ready wait timed out."));
					}
				}, this.#timeoutMilliseconds)
			};
			this.#pendingReady.add(pending);
			options.signal?.addEventListener("abort", abort, { once: true });
		});
	}
	render(payload, options = {}) {
		if (this.#disposed) return Promise.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		if (options.signal?.aborted === true) return Promise.reject(new Error("Preview render was aborted.", { cause: options.signal.reason }));
		let request;
		try {
			request = snapshotOutboundPayload(payload);
		} catch (error) {
			return Promise.reject(error instanceof Error ? error : new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message."));
		}
		const message = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: request,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/render"
		};
		try {
			this.#assertOutbound(message);
		} catch (error) {
			return Promise.reject(error instanceof Error ? error : new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message."));
		}
		if (this.#usedRequestIds.has(request.requestId)) return Promise.reject(new PreviewChannelError("studio.preview/request-id-reused", `Preview request ${request.requestId} was already used in this session.`));
		for (const [requestId, pending] of this.#pending) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(/* @__PURE__ */ new Error(`Preview render ${requestId} was superseded by ${request.requestId}.`));
		}
		this.#pending.clear();
		this.#rejectPendingMeasures(/* @__PURE__ */ new Error(`Preview measurements were superseded by render ${request.requestId}.`));
		this.#usedRequestIds.add(request.requestId);
		this.#latestRenderRequestId = request.requestId;
		this.#latestRenderedDigest = void 0;
		this.#markerInventory.clear();
		return new Promise((resolve, reject) => {
			const abort = () => {
				const pending = this.#pending.get(request.requestId);
				if (pending !== void 0) {
					clearTimeout(pending.timeout);
					this.#pending.delete(request.requestId);
					pending.cleanup();
					if (this.#latestRenderRequestId === request.requestId) this.#latestRenderRequestId = void 0;
					pending.reject(/* @__PURE__ */ new Error("Preview render was aborted."));
					this.#revokeRemoteRender(request.draftDigest, "studio.preview/client-aborted");
				}
			};
			const cleanup = () => {
				options.signal?.removeEventListener("abort", abort);
			};
			const timeout = setTimeout(() => {
				const pending = this.#pending.get(request.requestId);
				if (pending !== void 0) {
					this.#pending.delete(request.requestId);
					pending.cleanup();
					if (this.#latestRenderRequestId === request.requestId) this.#latestRenderRequestId = void 0;
					pending.reject(/* @__PURE__ */ new Error(`Preview render ${request.requestId} timed out.`));
					this.#revokeRemoteRender(request.draftDigest, "studio.preview/client-timeout");
				}
			}, this.#timeoutMilliseconds);
			this.#pending.set(request.requestId, {
				cleanup,
				payload: request,
				reject,
				resolve,
				timeout
			});
			options.signal?.addEventListener("abort", abort, { once: true });
			try {
				this.#post(message);
			} catch (error) {
				clearTimeout(timeout);
				cleanup();
				this.#pending.delete(request.requestId);
				if (this.#latestRenderRequestId === request.requestId) this.#latestRenderRequestId = void 0;
				reject(error instanceof Error ? error : /* @__PURE__ */ new Error("Preview transport failed."));
			}
		});
	}
	/**
	* Requests the on-screen geometry of render markers from the responder. Resolves with a
	* `measured` outcome carrying viewport-relative CSS-pixel rectangles, or a `stale`
	* outcome when the response was measured against a render this client no longer
	* considers latest. Rejects on teardown, reload, supersession, abort, timeout, and
	* disposal exactly like `render()` does. Requires a completed render: geometry is a
	* volatile measurement of a specific render digest, never document state.
	*/
	measure(payload, options = {}) {
		if (this.#disposed) return Promise.reject(/* @__PURE__ */ new Error("Preview client was disposed."));
		if (options.signal?.aborted === true) return Promise.reject(new Error("Preview measure was aborted.", { cause: options.signal.reason }));
		let request;
		try {
			request = snapshotOutboundPayload(payload);
		} catch (error) {
			return Promise.reject(error instanceof Error ? error : new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message."));
		}
		if (this.#latestRenderedDigest === void 0) return Promise.reject(/* @__PURE__ */ new Error("Preview measure requires a completed render."));
		const message = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: request,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/measure"
		};
		try {
			this.#assertOutbound(message);
		} catch (error) {
			return Promise.reject(error instanceof Error ? error : new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message."));
		}
		if (this.#usedRequestIds.has(request.requestId)) return Promise.reject(new PreviewChannelError("studio.preview/request-id-reused", `Preview request ${request.requestId} was already used in this session.`));
		if (request.markers.some((marker) => !this.#markerInventory.has(marker) || !isPreviewMarker(marker, this.#latestRenderedDigest))) return Promise.reject(new PreviewChannelError("studio.preview/measure-stale-marker", "Preview measurement markers must belong to the current render inventory.", true));
		for (const [requestId, pending] of this.#pendingMeasures) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(/* @__PURE__ */ new Error(`Preview measure ${requestId} was superseded by ${request.requestId}.`));
		}
		this.#pendingMeasures.clear();
		this.#usedRequestIds.add(request.requestId);
		return new Promise((resolve, reject) => {
			const abort = () => {
				const pending = this.#pendingMeasures.get(request.requestId);
				if (pending !== void 0) {
					clearTimeout(pending.timeout);
					this.#pendingMeasures.delete(request.requestId);
					pending.cleanup();
					pending.reject(/* @__PURE__ */ new Error("Preview measure was aborted."));
				}
			};
			const cleanup = () => {
				options.signal?.removeEventListener("abort", abort);
			};
			const timeout = setTimeout(() => {
				const pending = this.#pendingMeasures.get(request.requestId);
				if (pending !== void 0) {
					this.#pendingMeasures.delete(request.requestId);
					pending.cleanup();
					pending.reject(/* @__PURE__ */ new Error(`Preview measure ${request.requestId} timed out.`));
				}
			}, this.#timeoutMilliseconds);
			this.#pendingMeasures.set(request.requestId, {
				cleanup,
				payload: request,
				reject,
				resolve,
				timeout
			});
			options.signal?.addEventListener("abort", abort, { once: true });
			try {
				this.#post(message);
			} catch (error) {
				clearTimeout(timeout);
				cleanup();
				this.#pendingMeasures.delete(request.requestId);
				reject(error instanceof Error ? error : /* @__PURE__ */ new Error("Preview transport failed."));
			}
		});
	}
	/**
	* Drive the preview surface to a semantic viewport role or to bounded
	* explicit dimensions. The two are alternatives, so a payload carrying both
	* is refused before it reaches the channel.
	*/
	setViewport(payload) {
		this.#assertActive();
		if (payload.viewport !== void 0 === (payload.width !== void 0 || payload.height !== void 0)) throw new RangeError("A viewport message carries either a semantic role or explicit dimensions, never both.");
		const message = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/viewport"
		};
		this.#assertOutbound(message);
		this.#rejectPendingMeasures(new PreviewChannelError("studio.preview/measure-viewport-changed", "Preview measurement was invalidated by a viewport change.", true));
		this.#post(message);
	}
	/**
	* Instruct the renderer to revoke the resources it holds for a superseded
	* draft while the channel stays open. This is not teardown: teardown ends
	* the session, dispose frees a render's resources within it.
	*/
	disposeDraft(payload) {
		this.#assertActive();
		const message = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/dispose"
		};
		this.#assertOutbound(message);
		for (const [requestId, pending] of this.#pending) if (payload.draftDigest === void 0 || pending.payload.draftDigest === payload.draftDigest) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(new PreviewChannelError("studio.preview/render-disposed", `Preview render ${requestId} was disposed before completion.`));
			this.#pending.delete(requestId);
			if (this.#latestRenderRequestId === requestId) this.#latestRenderRequestId = void 0;
		}
		if (payload.draftDigest === void 0 || payload.draftDigest === this.#latestRenderedDigest) {
			this.#latestRenderedDigest = void 0;
			this.#markerInventory.clear();
			this.#rejectPendingMeasures(new PreviewChannelError("studio.preview/measure-disposed", "Preview measurement was disposed with its render."));
		}
		this.#post(message);
	}
	/** Observe trusted marker interactions the renderer reports. */
	onActivated(listener) {
		this.#activationListeners.add(listener);
		return () => {
			this.#activationListeners.delete(listener);
		};
	}
	select(payload) {
		this.#assertActive();
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/select"
		});
	}
	#assertActive() {
		if (this.#disposed) throw new Error("Preview client was disposed.");
	}
	#assertOutbound(message) {
		if (!isPreviewMessage(message)) throw new PreviewChannelError("studio.preview/invalid-outbound-message", "Refused an invalid outbound preview message.");
	}
	#post(message) {
		this.#assertOutbound(message);
		this.#sequence += 1;
		this.#target.postMessage(message, this.#targetOrigin);
	}
	#rejectPendingMeasures(reason) {
		for (const pending of this.#pendingMeasures.values()) {
			clearTimeout(pending.timeout);
			pending.cleanup();
			pending.reject(reason);
		}
		this.#pendingMeasures.clear();
	}
	#revokeRemoteRender(draftDigest, reason) {
		if (this.#disposed) return;
		try {
			this.#post({
				channelId: this.#channelId,
				contractVersion: STUDIO_CONTRACT_VERSION,
				kind: "preview-message",
				payload: {
					draftDigest,
					reason
				},
				sequence: this.#sequence,
				sessionGeneration: this.#sessionGeneration,
				type: "studio.preview/dispose"
			});
		} catch {}
	}
	#receive(event) {
		if (event.origin !== this.#targetOrigin || event.source !== this.#target || !isPreviewMessage(event.data) || event.data.channelId !== this.#channelId || event.data.sessionGeneration !== this.#sessionGeneration || event.data.sequence <= this.#lastInboundSequence) return;
		this.#lastInboundSequence = event.data.sequence;
		if (event.data.type === "studio.preview/activated") {
			if (!this.#markerInventory.has(event.data.payload.marker) || !isPreviewMarker(event.data.payload.marker, this.#latestRenderedDigest)) return;
			for (const listener of this.#activationListeners) listener(event.data.payload);
			return;
		}
		if (event.data.type === "studio.preview/rendered") {
			if (event.data.payload.requestId !== this.#latestRenderRequestId) return;
			const pending = this.#pending.get(event.data.payload.requestId);
			if (pending !== void 0 && event.data.payload.draftDigest !== pending.payload.draftDigest) {
				clearTimeout(pending.timeout);
				pending.cleanup();
				this.#pending.delete(event.data.payload.requestId);
				this.#latestRenderRequestId = void 0;
				pending.reject(new PreviewChannelError("studio.preview/render-correlation-mismatch", "Preview response digest did not match its render request."));
				return;
			}
			if (pending !== void 0) {
				clearTimeout(pending.timeout);
				pending.cleanup();
				this.#pending.delete(event.data.payload.requestId);
				this.#latestRenderRequestId = void 0;
				this.#latestRenderedDigest = event.data.payload.draftDigest;
				this.#markerInventory.clear();
				for (const marker of event.data.payload.markers) this.#markerInventory.add(marker);
				pending.resolve(event.data.payload);
			}
		} else if (event.data.type === "studio.preview/measurements") {
			const pending = this.#pendingMeasures.get(event.data.payload.requestId);
			if (pending !== void 0) {
				const responseMarkers = [...Object.keys(event.data.payload.measurements), ...event.data.payload.unknown];
				if (responseMarkers.length !== pending.payload.markers.length || pending.payload.markers.some((marker) => !responseMarkers.includes(marker))) {
					clearTimeout(pending.timeout);
					pending.cleanup();
					this.#pendingMeasures.delete(event.data.payload.requestId);
					pending.reject(new PreviewChannelError("studio.preview/invalid-measurements", "Preview measurements did not exactly partition the requested marker inventory."));
					return;
				}
				clearTimeout(pending.timeout);
				pending.cleanup();
				this.#pendingMeasures.delete(event.data.payload.requestId);
				if (event.data.payload.draftDigest === this.#latestRenderedDigest) pending.resolve({
					geometry: event.data.payload,
					status: "measured"
				});
				else pending.resolve({
					measuredDigest: event.data.payload.draftDigest,
					status: "stale"
				});
			}
		} else if (event.data.type === "studio.preview/error") {
			const message = event.data.payload.message.defaultMessage ?? event.data.payload.message.key;
			const correlationId = event.data.payload.correlationId;
			if (correlationId !== void 0) {
				const pendingRender = this.#pending.get(correlationId);
				const pendingMeasure = this.#pendingMeasures.get(correlationId);
				if (pendingRender === void 0 && pendingMeasure === void 0) return;
				if (pendingRender !== void 0) {
					clearTimeout(pendingRender.timeout);
					pendingRender.cleanup();
					pendingRender.reject(new PreviewChannelError(event.data.payload.code, message, event.data.payload.retryable));
					this.#pending.delete(correlationId);
					if (this.#latestRenderRequestId === correlationId) {
						this.#latestRenderRequestId = void 0;
						this.#markerInventory.clear();
					}
				}
				if (pendingMeasure !== void 0) {
					clearTimeout(pendingMeasure.timeout);
					pendingMeasure.cleanup();
					pendingMeasure.reject(new PreviewChannelError(event.data.payload.code, message, event.data.payload.retryable));
					this.#pendingMeasures.delete(correlationId);
				}
			} else {
				for (const pending of this.#pending.values()) {
					clearTimeout(pending.timeout);
					pending.cleanup();
					pending.reject(new Error(message));
				}
				this.#pending.clear();
				for (const pending of this.#pendingMeasures.values()) {
					clearTimeout(pending.timeout);
					pending.cleanup();
					pending.reject(new Error(message));
				}
				this.#pendingMeasures.clear();
				this.#latestRenderRequestId = void 0;
				this.#latestRenderedDigest = void 0;
				this.#markerInventory.clear();
			}
		} else if (event.data.type === "studio.preview/ready") {
			this.#readyPayload = event.data.payload;
			const waiters = [...this.#pendingReady];
			this.#pendingReady.clear();
			for (const pending of waiters) {
				clearTimeout(pending.timeout);
				pending.cleanup();
				pending.resolve(event.data.payload);
			}
		} else if (event.data.type === "studio.preview/reload" || event.data.type === "studio.preview/teardown") {
			const reason = event.data.type === "studio.preview/reload" ? "Preview renderer reloaded before responding." : "Preview channel was torn down.";
			for (const pending of this.#pending.values()) {
				clearTimeout(pending.timeout);
				pending.cleanup();
				pending.reject(new Error(reason));
			}
			this.#pending.clear();
			for (const pending of this.#pendingMeasures.values()) {
				clearTimeout(pending.timeout);
				pending.cleanup();
				pending.reject(new Error(reason));
			}
			this.#pendingMeasures.clear();
			this.#latestRenderRequestId = void 0;
			this.#latestRenderedDigest = void 0;
			this.#markerInventory.clear();
			this.#readyPayload = void 0;
		}
		const teardown = event.data.type === "studio.preview/teardown";
		for (const listener of this.#listeners) listener(event.data);
		if (teardown) this.dispose();
	}
	/** Announce channel closure to the host, then dispose this client. */
	teardown(reason) {
		this.#assertActive();
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: { reason },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/teardown"
		});
		this.dispose();
	}
};
function normalizeOrigin(input) {
	if (input === "*") throw new TypeError("Preview target origin must be exact; wildcard origins are forbidden.");
	const url = new URL(input);
	if (url.origin === "null") throw new TypeError("Preview target origin must use a network origin.");
	return url.origin;
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-host.js
/**
* The preview-surface half of the channel: answers `studio.preview/render` and
* `studio.preview/measure` requests from a `PreviewClient` and forwards
* `studio.preview/select` to registered listeners. Inbound messages are filtered exactly
* like the client filters its own: pinned origin, expected source window, canonical
* schema, channel ID, session generation, and a strictly increasing sequence.
*/
var PreviewHost = class {
	#channelId;
	#listener;
	#measureCallback;
	#renderCallback;
	#renderer;
	#viewportListeners = /* @__PURE__ */ new Set();
	#disposeListeners = /* @__PURE__ */ new Set();
	#selectListeners = /* @__PURE__ */ new Set();
	#markerInventory = /* @__PURE__ */ new Set();
	#sessionGeneration;
	#source;
	#target;
	#targetOrigin;
	#usedRequestIds = /* @__PURE__ */ new Set();
	#viewports;
	#activeMeasure;
	#activeRender;
	#disposed = false;
	#lastInboundSequence = -1;
	#measureGeneration = 0;
	#measuredRenderDigest;
	#renderGeneration = 0;
	#sequence = 0;
	constructor(options) {
		this.#targetOrigin = normalizeOrigin(options.targetOrigin);
		this.#channelId = options.channelId;
		this.#sessionGeneration = options.sessionGeneration;
		this.#source = options.source;
		this.#target = options.target;
		this.#measureCallback = options.measure;
		this.#renderCallback = options.render;
		this.#renderer = options.renderer;
		this.#viewports = [...options.viewports];
		this.#listener = (event) => {
			this.#receive(event);
		};
		this.#source.addEventListener("message", this.#listener);
	}
	/**
	* Posts the `studio.preview/ready` announcement carrying the wire protocol version,
	* renderer id, and viewport inventory. Announcement is an explicit step so the host can
	* finish its own setup before opening the channel.
	*/
	announce() {
		this.#assertActive();
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: {
				protocolVersion: STUDIO_WIRE_PROTOCOL_VERSION,
				renderer: this.#renderer,
				viewports: [...this.#viewports]
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/ready"
		});
	}
	dispose() {
		if (this.#disposed) return;
		this.#disposed = true;
		this.#source.removeEventListener("message", this.#listener);
		this.#selectListeners.clear();
		this.#viewportListeners.clear();
		this.#disposeListeners.clear();
		this.#invalidateMeasure("Preview host was disposed.");
		this.#invalidateRender("Preview host was disposed.");
		this.#measuredRenderDigest = void 0;
		this.#markerInventory.clear();
	}
	/**
	* Report a trusted interaction with a marked region. The renderer reports
	* intent, never raw input events, and the marker carries nothing beyond the
	* node identity the render already published.
	*/
	announceActivation(payload) {
		this.#assertActive();
		if (!this.#markerInventory.has(payload.marker) || !isPreviewMarker(payload.marker, this.#measuredRenderDigest)) throw new RangeError("Preview activation marker is not in the current render inventory.");
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload,
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/activated"
		});
	}
	/** Observe viewport instructions the client drives the surface with. */
	onViewport(listener) {
		this.#viewportListeners.add(listener);
		return () => {
			this.#viewportListeners.delete(listener);
		};
	}
	/** Observe requests to revoke the resources held for a superseded draft. */
	onDispose(listener) {
		this.#disposeListeners.add(listener);
		return () => {
			this.#disposeListeners.delete(listener);
		};
	}
	onSelect(listener) {
		this.#selectListeners.add(listener);
		return () => {
			this.#selectListeners.delete(listener);
		};
	}
	/**
	* Announce that the renderer reloaded: any in-flight render or measurement is
	* void and the client must resend. The host re-announces readiness afterwards.
	*/
	reload(reason) {
		this.#assertActive();
		this.#invalidateMeasure("Preview renderer reloaded.");
		this.#invalidateRender("Preview renderer reloaded.");
		this.#measuredRenderDigest = void 0;
		this.#markerInventory.clear();
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: { reason },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/reload"
		});
		this.announce();
	}
	/** Announce channel closure to the client, then dispose this host. */
	teardown(reason) {
		this.#assertActive();
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: { reason },
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/teardown"
		});
		this.dispose();
	}
	#assertActive() {
		if (this.#disposed) throw new Error("Preview host was disposed.");
	}
	#handleMeasure(payload) {
		const requestId = payload.requestId;
		if (this.#usedRequestIds.has(requestId)) {
			this.#postError(requestId, {
				code: "studio.preview/request-id-reused",
				defaultMessage: "The preview request identifier was already used in this session.",
				retryable: false
			});
			return;
		}
		this.#usedRequestIds.add(requestId);
		if (this.#measuredRenderDigest === void 0) {
			this.#postError(requestId, {
				code: "studio.preview/measure-unavailable",
				defaultMessage: "Preview measurement is unavailable.",
				retryable: true
			});
			return;
		}
		const draftDigest = this.#measuredRenderDigest;
		if (payload.markers.some((marker) => !this.#markerInventory.has(marker) || !isPreviewMarker(marker, draftDigest))) {
			this.#postError(requestId, {
				code: "studio.preview/measure-stale-marker",
				defaultMessage: "Preview measurement markers are not in the current render inventory.",
				retryable: true
			});
			return;
		}
		if (this.#measureCallback === void 0) {
			this.#postError(requestId, {
				code: "studio.preview/measure-unavailable",
				defaultMessage: "Preview measurement is unavailable.",
				retryable: false
			});
			return;
		}
		this.#invalidateMeasure("Preview measurement was superseded.");
		const active = {
			controller: new AbortController(),
			draftDigest,
			generation: this.#measureGeneration,
			requestId
		};
		this.#activeMeasure = active;
		const markers = [...payload.markers];
		let result;
		try {
			result = this.#measureCallback(markers, active.controller.signal);
		} catch {
			this.#settleMeasureFailure(active, measureFailed());
			return;
		}
		Promise.resolve(result).then((measurement) => {
			try {
				this.#settleMeasured(active, markers, measurement);
			} catch {
				this.#settleMeasureFailure(active, measureFailed());
			}
		}, () => {
			this.#settleMeasureFailure(active, measureFailed());
		});
	}
	#handleRender(payload) {
		if (this.#usedRequestIds.has(payload.requestId)) {
			this.#postError(payload.requestId, {
				code: "studio.preview/request-id-reused",
				defaultMessage: "The preview request identifier was already used in this session.",
				retryable: false
			});
			return;
		}
		this.#usedRequestIds.add(payload.requestId);
		this.#invalidateMeasure("Preview measurement was superseded by a render.");
		this.#invalidateRender("Preview render was superseded.");
		this.#measuredRenderDigest = void 0;
		this.#markerInventory.clear();
		const active = {
			controller: new AbortController(),
			draftDigest: payload.draftDigest,
			generation: this.#renderGeneration,
			requestId: payload.requestId
		};
		this.#activeRender = active;
		let result;
		try {
			result = this.#renderCallback(payload, active.controller.signal);
		} catch {
			this.#settleFailure(active);
			return;
		}
		Promise.resolve(result).then((rendered) => {
			try {
				this.#settleRendered(active, rendered);
			} catch {
				this.#settleFailure(active);
			}
		}, () => {
			this.#settleFailure(active);
		});
	}
	#post(message) {
		if (!isPreviewMessage(message)) throw new TypeError("Refused an invalid outbound preview message.");
		this.#sequence += 1;
		this.#target.postMessage(message, this.#targetOrigin);
	}
	#receive(event) {
		if (event.origin !== this.#targetOrigin || event.source !== this.#target || !isPreviewMessage(event.data) || event.data.channelId !== this.#channelId || event.data.sessionGeneration !== this.#sessionGeneration || event.data.sequence <= this.#lastInboundSequence) return;
		this.#lastInboundSequence = event.data.sequence;
		if (event.data.type === "studio.preview/render") this.#handleRender(event.data.payload);
		else if (event.data.type === "studio.preview/measure") this.#handleMeasure(event.data.payload);
		else if (event.data.type === "studio.preview/select") for (const listener of this.#selectListeners) listener(event.data.payload);
		else if (event.data.type === "studio.preview/viewport") {
			this.#invalidateMeasure("Preview viewport changed.");
			for (const listener of this.#viewportListeners) listener(event.data.payload);
		} else if (event.data.type === "studio.preview/dispose") {
			if (event.data.payload.draftDigest === void 0 || event.data.payload.draftDigest === this.#activeRender?.draftDigest) this.#invalidateRender("Preview render was disposed.");
			if (event.data.payload.draftDigest === void 0 || event.data.payload.draftDigest === this.#measuredRenderDigest) {
				this.#invalidateMeasure("Preview measurement was disposed.");
				this.#measuredRenderDigest = void 0;
				this.#markerInventory.clear();
			}
			for (const listener of this.#disposeListeners) listener(event.data.payload);
		} else if (event.data.type === "studio.preview/teardown") this.dispose();
	}
	#settleMeasureFailure(active, failure) {
		if (!this.#isActiveMeasure(active)) return;
		this.#activeMeasure = void 0;
		this.#postError(active.requestId, failure);
	}
	#postError(correlationId, failure) {
		if (this.#disposed) return;
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: {
				code: failure.code,
				correlationId,
				message: {
					defaultMessage: failure.defaultMessage,
					key: failure.code
				},
				retryable: failure.retryable
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/error"
		});
	}
	#settleMeasured(active, markers, measurement) {
		if (!this.#isActiveMeasure(active)) return;
		const measurements = {};
		const unknown = [];
		for (const marker of markers) {
			const rects = Object.hasOwn(measurement.rects, marker) ? measurement.rects[marker] : void 0;
			if (rects === void 0 || rects.length === 0) unknown.push(marker);
			else measurements[marker] = rects.map((rect) => ({
				height: rect.height,
				width: rect.width,
				x: rect.x,
				y: rect.y
			}));
		}
		const message = {
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: {
				draftDigest: active.draftDigest,
				measurements,
				requestId: active.requestId,
				unknown,
				viewport: {
					devicePixelRatio: measurement.viewport.devicePixelRatio,
					height: measurement.viewport.height,
					scrollX: measurement.viewport.scrollX,
					scrollY: measurement.viewport.scrollY,
					width: measurement.viewport.width
				}
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/measurements"
		};
		if (!isPreviewMessage(message)) {
			this.#settleMeasureFailure(active, measureFailed());
			return;
		}
		this.#activeMeasure = void 0;
		this.#post(message);
	}
	#settleFailure(active) {
		if (!this.#isActiveRender(active)) return;
		this.#activeRender = void 0;
		this.#postError(active.requestId, {
			code: "studio.preview/render-failed",
			defaultMessage: "Preview rendering failed.",
			retryable: true
		});
	}
	#settleRendered(active, rendered) {
		if (!this.#isActiveRender(active)) return;
		if (!isPreviewRenderedPayload(rendered) || rendered.draftDigest !== active.draftDigest || rendered.requestId !== active.requestId) {
			this.#settleFailure(active);
			return;
		}
		this.#activeRender = void 0;
		this.#measuredRenderDigest = active.draftDigest;
		this.#markerInventory.clear();
		for (const marker of rendered.markers) this.#markerInventory.add(marker);
		this.#post({
			channelId: this.#channelId,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "preview-message",
			payload: {
				diagnostics: rendered.diagnostics,
				draftDigest: active.draftDigest,
				markers: rendered.markers,
				markerMap: rendered.markerMap,
				requestId: active.requestId
			},
			sequence: this.#sequence,
			sessionGeneration: this.#sessionGeneration,
			type: "studio.preview/rendered"
		});
	}
	#invalidateMeasure(reason) {
		this.#measureGeneration += 1;
		this.#activeMeasure?.controller.abort(reason);
		this.#activeMeasure = void 0;
	}
	#invalidateRender(reason) {
		this.#renderGeneration += 1;
		this.#activeRender?.controller.abort(reason);
		this.#activeRender = void 0;
	}
	#isActiveMeasure(active) {
		return !this.#disposed && this.#activeMeasure === active && active.generation === this.#measureGeneration && active.draftDigest === this.#measuredRenderDigest;
	}
	#isActiveRender(active) {
		return !this.#disposed && this.#activeRender === active && active.generation === this.#renderGeneration;
	}
};
function measureFailed() {
	return {
		code: "studio.preview/measure-failed",
		defaultMessage: "Preview measurement failed.",
		retryable: true
	};
}
//#endregion
//#region node_modules/@kumwe/studio-preview/dist/preview-identity.js
/**
* Return the exact digest preimage for a complete, schema-valid Studio draft.
* No transport envelope, prefix, BOM, viewport, revision override, or trailing
* newline is added: the preimage is only the canonical UTF-8 artifact bytes.
* The authoritative host MUST schema- and semantics-validate the artifact
* before treating this helper's result as a valid draft identity.
*/
function canonicalPreviewDraftBytes(draft, options = {}) {
	return canonicalUtf8Bytes(draft, options);
}
/** Compute the lowercase SHA-256 hex identity of a complete Studio draft. */
async function computePreviewDraftDigest(draft, options = {}) {
	const subtle = options.subtle ?? globalThis.crypto.subtle;
	const serializationOptions = options.maximumDepth === void 0 ? {} : { maximumDepth: options.maximumDepth };
	const preimage = Uint8Array.from(canonicalPreviewDraftBytes(draft, serializationOptions));
	const digest = await subtle.digest("SHA-256", preimage);
	return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}
//#endregion
//#region node_modules/@kumwe/studio/dist/preview-surface.js
/**
* Deterministic shell-side orchestration around the canonical PreviewClient.
* A microtask is the only coalescing boundary: all synchronous changes reduce
* to the final immutable snapshot, with no clock or debounce interval in the
* observable behavior. Every newer intent aborts prior work and generation
* checks prevent a staging or renderer callback that ignores cancellation
* from publishing stale marker authority.
*/
var StudioPreviewSurface = class {
	#binding;
	#callbacks;
	#controllers = /* @__PURE__ */ new Set();
	#unsubscribeActivated;
	#unsubscribeMessages;
	#accepted = false;
	#acceptedDigest;
	#closed = false;
	#generation = 0;
	#lastViewport;
	#latestIntent;
	#markerMap = {};
	#measurementController;
	#measurementGeneration = 0;
	#pendingIntent;
	#rendered;
	#measureSerial = 0;
	#renderSerial = 0;
	#scheduled = false;
	#selectedNodeId;
	#state = "connecting";
	constructor(binding, callbacks) {
		this.#binding = binding;
		this.#callbacks = callbacks;
		this.#unsubscribeMessages = binding.client.onMessage((message) => {
			this.#receive(message);
		});
		this.#unsubscribeActivated = binding.client.onActivated((payload) => {
			const nodeId = this.#markerMap[payload.marker];
			if (nodeId === void 0) return;
			this.#selectedNodeId = nodeId;
			this.#callbacks.onActivated(nodeId);
		});
		callbacks.onState(this.#state);
	}
	get state() {
		return this.#state;
	}
	/** Queue the latest complete draft and semantic viewport for rendering. */
	update(draft, viewport) {
		if (this.#closed) return;
		let snapshot;
		try {
			snapshot = structuredClone(draft);
		} catch {
			this.#setState("stale");
			return;
		}
		this.#generation += 1;
		const intent = {
			draft: snapshot,
			generation: this.#generation,
			viewport
		};
		this.#latestIntent = intent;
		this.#pendingIntent = intent;
		if (this.#acceptedDigest !== void 0) try {
			this.#binding.client.disposeDraft({
				draftDigest: this.#acceptedDigest,
				reason: "studio.preview/draft-superseded"
			});
		} catch {}
		this.#accepted = false;
		this.#acceptedDigest = void 0;
		this.#markerMap = {};
		this.#rendered = void 0;
		this.#clearGeometry();
		for (const controller of this.#controllers) controller.abort("Preview render was superseded by newer authoring state.");
		this.#schedule();
	}
	/**
	* Mirror shell selection only after the latest marker map proves that the
	* node has a live rendered region. Selection remains fully usable without
	* that proof; it is simply not sent into a stale surface.
	*/
	selectNode(nodeId) {
		this.#selectedNodeId = nodeId;
		if (nodeId === void 0 || !Object.values(this.#markerMap).includes(nodeId)) return;
		try {
			this.#binding.client.select({
				nodeId,
				reveal: true
			});
		} catch {
			this.#setState("stale");
		}
	}
	/**
	* Re-measure the current render after host-observed scroll, resize, zoom or
	* late asset settlement. The call is inert until a render is accepted.
	*/
	refreshGeometry() {
		const rendered = this.#rendered;
		if (rendered !== void 0 && this.#accepted) this.#measure(rendered);
	}
	/** End the preview channel and release every shell-side listener. */
	teardown(reason) {
		if (this.#closed) return;
		try {
			this.#binding.client.teardown(reason);
		} catch {}
		this.#close();
	}
	#close() {
		if (this.#closed) return;
		this.#closed = true;
		this.#generation += 1;
		this.#pendingIntent = void 0;
		this.#accepted = false;
		this.#acceptedDigest = void 0;
		this.#markerMap = {};
		this.#rendered = void 0;
		this.#clearGeometry();
		for (const controller of this.#controllers) controller.abort("Preview channel closed.");
		this.#controllers.clear();
		this.#unsubscribeActivated();
		this.#unsubscribeMessages();
		this.#setState("closed");
	}
	async #perform(intent) {
		const controller = new AbortController();
		this.#controllers.add(controller);
		try {
			this.#setState("connecting");
			const ready = await this.#binding.client.ready({ signal: controller.signal });
			if (!this.#isCurrent(intent, controller)) return;
			const viewport = this.#resolveViewport(intent, ready);
			if (viewport === void 0) {
				this.#setState("stale");
				return;
			}
			const expectedDigest = await computePreviewDraftDigest(intent.draft);
			if (!this.#isCurrent(intent, controller)) return;
			const identity = await this.#binding.stage(intent.draft, { signal: controller.signal });
			if (!this.#isCurrent(intent, controller)) return;
			if (identity.artifactId !== intent.draft.id || identity.draftRevision !== intent.draft.revision || identity.draftDigest !== expectedDigest) throw new TypeError("The host staged a different preview draft identity.");
			if (this.#lastViewport !== viewport) {
				this.#binding.client.setViewport({ viewport });
				this.#lastViewport = viewport;
			}
			this.#renderSerial += 1;
			this.#setState("rendering");
			const rendered = await this.#binding.client.render({
				artifactId: identity.artifactId,
				draftDigest: identity.draftDigest,
				draftRevision: identity.draftRevision,
				requestId: `renders/studio-shell-${this.#renderSerial}`,
				viewport
			}, { signal: controller.signal });
			if (!this.#isCurrent(intent, controller)) return;
			this.#accept(rendered);
		} catch {
			if (this.#isCurrent(intent, controller)) {
				this.#accepted = false;
				this.#acceptedDigest = void 0;
				this.#markerMap = {};
				this.#rendered = void 0;
				this.#clearGeometry();
				this.#setState("stale");
			}
		} finally {
			this.#controllers.delete(controller);
		}
	}
	#accept(rendered) {
		this.#markerMap = structuredClone(rendered.markerMap);
		this.#accepted = true;
		this.#acceptedDigest = rendered.draftDigest;
		this.#rendered = structuredClone(rendered);
		this.#setState("current");
		this.selectNode(this.#selectedNodeId);
		this.#measure(rendered);
	}
	async #measure(rendered) {
		const entries = Object.entries(rendered.markerMap);
		if (entries.length === 0) {
			this.#clearGeometry();
			return;
		}
		this.#measurementController?.abort("Preview geometry was superseded by a newer measurement.");
		this.#measurementGeneration += 1;
		const generation = this.#measurementGeneration;
		const controller = new AbortController();
		this.#measurementController = controller;
		this.#controllers.add(controller);
		const measurements = {};
		const unknownNodeIds = [];
		let viewport;
		try {
			for (let offset = 0; offset < entries.length; offset += 1e3) {
				const chunk = entries.slice(offset, offset + 1e3);
				this.#measureSerial += 1;
				const outcome = await this.#binding.client.measure({
					markers: chunk.map(([marker]) => marker),
					requestId: `measurements/studio-shell-${this.#measureSerial}`
				}, { signal: controller.signal });
				if (!this.#isAcceptedGeometry(rendered, controller, generation) || outcome.status !== "measured") return;
				viewport ??= structuredClone(outcome.geometry.viewport);
				for (const [marker, rects] of Object.entries(outcome.geometry.measurements)) {
					const nodeId = rendered.markerMap[marker];
					if (nodeId !== void 0) measurements[nodeId] = structuredClone(rects);
				}
				for (const marker of outcome.geometry.unknown) {
					const nodeId = rendered.markerMap[marker];
					if (nodeId !== void 0) unknownNodeIds.push(nodeId);
				}
			}
			if (viewport !== void 0 && this.#isAcceptedGeometry(rendered, controller, generation)) this.#callbacks.onGeometry?.({
				draftDigest: rendered.draftDigest,
				measurements,
				unknownNodeIds,
				viewport
			});
		} catch {
			if (this.#isAcceptedGeometry(rendered, controller, generation)) this.#callbacks.onGeometry?.(void 0);
		} finally {
			this.#controllers.delete(controller);
			if (this.#measurementController === controller) this.#measurementController = void 0;
		}
	}
	#isAcceptedGeometry(rendered, controller, generation) {
		return !this.#closed && !controller.signal.aborted && generation === this.#measurementGeneration && this.#accepted && this.#acceptedDigest === rendered.draftDigest && this.#rendered?.requestId === rendered.requestId;
	}
	#clearGeometry() {
		this.#measurementGeneration += 1;
		this.#measurementController?.abort("Preview geometry authority was revoked.");
		this.#measurementController = void 0;
		this.#callbacks.onGeometry?.(void 0);
	}
	#isCurrent(intent, controller) {
		return !this.#closed && !controller.signal.aborted && intent.generation === this.#generation && this.#latestIntent?.generation === intent.generation;
	}
	#receive(message) {
		this.#callbacks.onMessage(message);
		if (message.type === "studio.preview/reload") {
			this.#accepted = false;
			this.#acceptedDigest = void 0;
			this.#lastViewport = void 0;
			this.#markerMap = {};
			this.#rendered = void 0;
			this.#clearGeometry();
			this.#setState("stale");
			const latest = this.#latestIntent;
			if (latest !== void 0) this.update(latest.draft, latest.viewport);
		} else if (message.type === "studio.preview/ready") {
			if (!this.#accepted && this.#controllers.size === 0 && this.#pendingIntent === void 0) {
				const latest = this.#latestIntent;
				if (latest !== void 0) this.update(latest.draft, latest.viewport);
			}
		} else if (message.type === "studio.preview/teardown") this.#close();
		else if (message.type === "studio.preview/error" && message.payload.correlationId === void 0) {
			this.#accepted = false;
			this.#acceptedDigest = void 0;
			this.#markerMap = {};
			this.#rendered = void 0;
			this.#clearGeometry();
			this.#setState("stale");
		}
	}
	#resolveViewport(intent, ready) {
		const viewport = intent.viewport ?? ready.viewports[0];
		return viewport !== void 0 && ready.viewports.includes(viewport) ? viewport : void 0;
	}
	#schedule() {
		if (this.#scheduled) return;
		this.#scheduled = true;
		queueMicrotask(() => {
			this.#scheduled = false;
			const intent = this.#pendingIntent;
			this.#pendingIntent = void 0;
			if (intent !== void 0 && !this.#closed) this.#perform(intent);
		});
	}
	#setState(state) {
		if (state === this.#state) return;
		this.#state = state;
		this.#callbacks.onState(state);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/profiles.js
var PORTABLE_NODES$1 = Object.freeze([
	"blockquote",
	"bulletList",
	"callout",
	"checklist",
	"checklistItem",
	"codeBlock",
	"doc",
	"hardBreak",
	"heading",
	"horizontalRule",
	"listItem",
	"orderedList",
	"paragraph",
	"table",
	"tableCell",
	"tableRow",
	"text"
]);
var PORTABLE_MARKS$1 = Object.freeze([
	"bold",
	"code",
	"highlight",
	"italic",
	"strike"
]);
var ATTRIBUTE_LIMITS = Object.freeze({
	maximumDepth: 8,
	maximumItemsPerArray: 256,
	maximumPropertiesPerObject: 64,
	maximumStringLength: 4096,
	maximumTotalBytes: 65536
});
function profile(maximumTextLength, maximumNodes) {
	return Object.freeze({
		allowedAttributes: Object.freeze({
			callout: Object.freeze(["tone"]),
			checklistItem: Object.freeze(["checked", "level"]),
			codeBlock: Object.freeze(["language"]),
			heading: Object.freeze(["level"]),
			"mark:highlight": Object.freeze(["tone"]),
			orderedList: Object.freeze(["start"]),
			table: Object.freeze(["header"])
		}),
		allowedMarks: PORTABLE_MARKS$1,
		allowedNodes: PORTABLE_NODES$1,
		attributeLimits: ATTRIBUTE_LIMITS,
		headingLevels: Object.freeze([
			2,
			3,
			4
		]),
		maximumDepth: 32,
		maximumDocumentBytes: 1048576,
		maximumMarks: 2e4,
		maximumMarksPerNode: PORTABLE_MARKS$1.length,
		maximumNodes,
		maximumTextLength
	});
}
/** Smallest interoperable profile and the fail-closed default. */
var PORTABLE_RICH_TEXT_PROFILE = profile(25e4, 5e3);
/** Content-page prose profile. It intentionally has the same closed grammar with a smaller bound. */
var MARKETING_RICH_TEXT_PROFILE = profile(1e5, 2e3);
/** Documentation profile. Code is an inert inline mark; executable code is never accepted. */
var DOCUMENTATION_RICH_TEXT_PROFILE = profile(5e5, 1e4);
var PROFILES = Object.freeze({
	"studio.rich-text/documentation": DOCUMENTATION_RICH_TEXT_PROFILE,
	"studio.rich-text/marketing": MARKETING_RICH_TEXT_PROFILE,
	"studio.rich-text/portable": PORTABLE_RICH_TEXT_PROFILE
});
/** Resolve only a Studio-owned, versioned profile. Unknown host input never widens the grammar. */
function resolveRichTextProfile(id = "studio.rich-text/portable") {
	const resolved = PROFILES[id];
	if (resolved === void 0) throw new TypeError(`Unknown Studio rich-text profile "${id}".`);
	return resolved;
}
/**
* Select the closed profile used for prose nested in first-party interactive
* page controls. Unknown runtime input fails closed instead of silently
* widening the editor grammar.
*/
function resolveContainerRichTextProfile(containerType) {
	switch (containerType) {
		case "studio.core/accordion-item":
		case "studio.core/dialog":
		case "studio.core/notice":
		case "studio.core/popover":
		case "studio.core/tab": return "studio.rich-text/marketing";
		default: throw new TypeError(`Unknown Studio rich-text container "${String(containerType)}".`);
	}
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/first-party-tools.js
var STUDIO_EDITOR_JS_TOOL_NAMES = Object.freeze([
	"callout",
	"checklist",
	"code",
	"delimiter",
	"header",
	"list",
	"paragraph",
	"quote",
	"table"
]);
function studioEditorJsTools() {
	return Object.freeze({
		callout: StudioCalloutTool,
		checklist: StudioChecklistTool,
		code: StudioCodeTool,
		delimiter: StudioDelimiterTool,
		header: StudioHeaderTool,
		list: StudioListTool,
		paragraph: StudioParagraphTool,
		quote: StudioQuoteTool,
		table: StudioTableTool
	});
}
function toStudioEditorJsBlocks(document) {
	return document.content.map((node) => ({
		data: { node: structuredClone(node) },
		type: toolName(node)
	}));
}
function fromStudioEditorJsBlocks(value) {
	if (!isRecord$3(value) || !Array.isArray(value.blocks)) throw new TypeError("Editor surface returned an invalid block collection.");
	const content = value.blocks.map((block, index) => {
		if (!isRecord$3(block) || !STUDIO_EDITOR_JS_TOOL_NAMES.includes(block.type) || !isRecord$3(block.data) || !isRecord$3(block.data.node)) throw new TypeError(`Editor block ${index} is not a Studio first-party block.`);
		const node = structuredClone(block.data.node);
		if (toolName(node) !== block.type) throw new TypeError(`Editor block ${index} has a mismatched Studio node type.`);
		return node;
	});
	return {
		content: content.length > 0 ? content : [{ type: "paragraph" }],
		type: "doc"
	};
}
function toolName(node) {
	switch (node.type) {
		case "heading": return "header";
		case "blockquote": return "quote";
		case "horizontalRule": return "delimiter";
		case "bulletList":
		case "orderedList": return "list";
		case "checklist": return "checklist";
		case "table": return "table";
		case "callout": return "callout";
		case "codeBlock": return "code";
		case "paragraph": return "paragraph";
		default: throw new TypeError(`Node type "${node.type}" has no first-party Editor.js tool.`);
	}
}
var InlineToolBase = class {
	static isReadOnlySupported = true;
	node;
	readOnly;
	field;
	constructor(options, fallback) {
		this.node = structuredClone(options.data?.node ?? fallback);
		this.readOnly = options.readOnly === true;
	}
	renderInline(label, content) {
		const field = document.createElement("div");
		field.className = "studio-rich-text-field";
		field.contentEditable = this.readOnly ? "false" : "true";
		field.setAttribute("aria-label", label);
		field.setAttribute("role", "textbox");
		field.setAttribute("aria-multiline", "true");
		field.spellcheck = true;
		for (const inline of content) appendInline(field, inline);
		field.addEventListener("paste", pastePlainText);
		this.field = field;
		return field;
	}
	saveInline(original) {
		return this.field === void 0 ? structuredClone([...original]) : preserveInlineRepresentation(original, readInline(this.field));
	}
};
var StudioParagraphTool = class extends InlineToolBase {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "¶",
		title: "Paragraph"
	};
	constructor(options) {
		super(options, { type: "paragraph" });
	}
	render() {
		return this.renderInline("Paragraph", this.node.content ?? []);
	}
	save() {
		const node = structuredClone(this.node);
		const content = this.saveInline(node.content ?? []);
		if (!sameCanonical(node.content ?? [], content)) node.content = content;
		return { node };
	}
};
var StudioHeaderTool = class extends InlineToolBase {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "H",
		title: "Heading"
	};
	#level;
	constructor(options) {
		super(options, {
			attrs: { level: 2 },
			type: "heading"
		});
	}
	render() {
		const group = editorGroup("Heading");
		const level = document.createElement("select");
		const selectedLevel = this.node.attrs?.level === 3 || this.node.attrs?.level === 4 ? this.node.attrs.level : 2;
		level.setAttribute("aria-label", "Heading level");
		level.disabled = this.readOnly;
		for (const value of [
			2,
			3,
			4
		]) {
			const option = document.createElement("option");
			option.value = String(value);
			option.textContent = `Heading ${value}`;
			option.selected = selectedLevel === value;
			level.append(option);
		}
		level.value = String(selectedLevel);
		this.#level = level;
		group.append(level, this.renderInline("Heading text", this.node.content ?? []));
		return group;
	}
	save() {
		const node = structuredClone(this.node);
		const level = Number(this.#level?.value ?? this.node.attrs?.level ?? 2);
		if (level !== Number(this.node.attrs?.level ?? 2)) node.attrs = { level };
		const content = this.saveInline(node.content ?? []);
		if (!sameCanonical(node.content ?? [], content)) node.content = content;
		return { node };
	}
};
var StudioQuoteTool = class extends InlineToolBase {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "“",
		title: "Quote"
	};
	constructor(options) {
		super(options, {
			content: [{ type: "paragraph" }],
			type: "blockquote"
		});
	}
	render() {
		return this.renderInline("Quotation", editableBlockContent(this.node.content ?? []));
	}
	save() {
		const node = structuredClone(this.node);
		const content = editableBlockContent(node.content ?? []);
		node.content = mergeEditableBlockContent(node.content ?? [], this.saveInline(content));
		return { node };
	}
};
var StudioDelimiterTool = class {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "—",
		title: "Separator"
	};
	render() {
		const separator = document.createElement("hr");
		separator.setAttribute("aria-label", "Separator");
		return separator;
	}
	save() {
		return { node: { type: "horizontalRule" } };
	}
};
var StudioCalloutTool = class extends InlineToolBase {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "!",
		title: "Callout"
	};
	#tone;
	constructor(options) {
		super(options, {
			attrs: { tone: "info" },
			content: [{ type: "paragraph" }],
			type: "callout"
		});
	}
	render() {
		const group = editorGroup("Callout");
		this.#tone = selectControl("Callout tone", [
			"info",
			"success",
			"warning",
			"danger"
		], stringAttribute(this.node.attrs?.tone, "info"), this.readOnly);
		group.append(this.#tone, this.renderInline("Callout text", editableBlockContent(this.node.content ?? [])));
		return group;
	}
	save() {
		const node = structuredClone(this.node);
		node.attrs = { tone: this.#tone?.value ?? "info" };
		const content = editableBlockContent(node.content ?? []);
		node.content = mergeEditableBlockContent(node.content ?? [], this.saveInline(content));
		return { node };
	}
};
var StudioCodeTool = class {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "</>",
		title: "Code"
	};
	#node;
	#readOnly;
	#language;
	#source;
	constructor(options) {
		this.#node = structuredClone(options.data?.node ?? {
			attrs: { language: "text" },
			text: "",
			type: "codeBlock"
		});
		this.#readOnly = options.readOnly === true;
	}
	render() {
		const group = editorGroup("Code sample");
		this.#language = textInput$1("Code language", stringAttribute(this.#node.attrs?.language, "text"), this.#readOnly);
		this.#language.pattern = "[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}";
		this.#language.maxLength = 64;
		this.#source = document.createElement("textarea");
		this.#source.setAttribute("aria-label", "Inert code source");
		this.#source.disabled = this.#readOnly;
		this.#source.rows = 8;
		this.#source.value = this.#node.text ?? "";
		group.append(this.#language, this.#source);
		return group;
	}
	save() {
		const language = this.#language?.value.trim() ?? "text";
		return { node: {
			attrs: { language: /^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$/u.test(language) ? language : "text" },
			text: this.#source?.value ?? "",
			type: "codeBlock"
		} };
	}
};
var StudioListTool = class {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "•",
		title: "List"
	};
	#readOnly;
	#node;
	#rows;
	#root;
	constructor(options) {
		const node = structuredClone(options.data?.node ?? {
			content: [{
				content: [{ type: "paragraph" }],
				type: "listItem"
			}],
			type: "bulletList"
		});
		this.#node = node;
		this.#readOnly = options.readOnly === true;
		this.#rows = flattenList(node);
	}
	render() {
		this.#root = editorGroup("List");
		this.#renderRows();
		return this.#root;
	}
	save() {
		this.#syncRows();
		return { node: structuredClone(this.#node) };
	}
	#renderRows() {
		const root = this.#root;
		if (root === void 0) return;
		root.replaceChildren();
		const style = selectControl("List style", ["bullet", "ordered"], this.#node.type === "orderedList" ? "ordered" : "bullet", this.#readOnly);
		style.addEventListener("change", () => {
			this.#syncRows();
			const ordered = style.value === "ordered";
			const start = orderedListStart(this.#node);
			this.#node.type = ordered ? "orderedList" : "bulletList";
			if (ordered && start !== 1) this.#node.attrs = { start };
			else delete this.#node.attrs;
			this.#renderRows();
		});
		root.append(style);
		if (this.#node.type === "orderedList") {
			const start = textInput$1("Ordered list start", String(orderedListStart(this.#node)), this.#readOnly);
			start.type = "number";
			start.min = "1";
			start.max = "1000000";
			start.addEventListener("change", () => {
				const value = Math.max(1, Math.min(1e6, Number(start.value) || 1));
				if (value === orderedListStart(this.#node)) return;
				if (value === 1) delete this.#node.attrs;
				else this.#node.attrs = { start: value };
			});
			root.append(start);
		}
		this.#rows = flattenList(this.#node);
		const list = document.createElement("ol");
		list.setAttribute("aria-label", "List items");
		for (const [index, row] of this.#rows.entries()) {
			const item = document.createElement("li");
			item.dataset.index = String(index);
			item.dataset.studioDepth = String(row.depth);
			item.setAttribute("aria-level", String(row.depth + 1));
			const field = inlineField(`List item ${index + 1}`, row.editableBlock.content ?? [], this.#readOnly);
			field.dataset.listText = String(index);
			item.append(field);
			if (!this.#readOnly) item.append(rowButton("Move item up", () => this.#move(index, -1), !canMoveListRow(row, -1)), rowButton("Move item down", () => this.#move(index, 1), !canMoveListRow(row, 1)), rowButton("Indent item", () => this.#indent(index), !canIndentListRow(row)), rowButton("Outdent item", () => this.#outdent(index), row.ownerItem === void 0), rowButton("Remove item", () => this.#remove(index), !canRemoveListRow(row, this.#node)));
			list.append(item);
		}
		root.append(list);
		if (!this.#readOnly) root.append(rowButton("Add list item", () => this.#add()));
	}
	#syncRows() {
		for (const field of this.#root?.querySelectorAll("[data-list-text]") ?? []) {
			const index = Number(field.dataset.listText);
			const row = this.#rows[index];
			if (row === void 0) continue;
			const content = preserveInlineRepresentation(row.editableBlock.content ?? [], readInline(field));
			if (row.syntheticEditable) {
				if (content.length > 0) {
					row.editableBlock.content = content;
					row.item.content = [row.editableBlock, ...row.item.content ?? []];
					row.syntheticEditable = false;
				}
			} else if (!sameCanonical(row.editableBlock.content ?? [], content)) row.editableBlock.content = content;
		}
	}
	#add() {
		this.#syncRows();
		if (this.#rows.length < 500) this.#node.content = [...this.#node.content ?? [], {
			content: [{ type: "paragraph" }],
			type: "listItem"
		}];
		this.#renderRows();
	}
	#indent(index) {
		this.#syncRows();
		const row = this.#rows[index];
		if (row === void 0 || !canIndentListRow(row)) return;
		const siblings = row.parentList.content ?? [];
		const itemIndex = siblings.indexOf(row.item);
		const previous = siblings[itemIndex - 1];
		if (previous === void 0) return;
		siblings.splice(itemIndex, 1);
		const existing = previous.content?.at(-1);
		const nested = existing?.type === row.parentList.type ? existing : {
			...row.parentList.type === "orderedList" && row.parentList.attrs !== void 0 ? { attrs: structuredClone(row.parentList.attrs) } : {},
			content: [],
			type: row.parentList.type
		};
		if (nested !== existing) previous.content = [...previous.content ?? [], nested];
		nested.content = [...nested.content ?? [], row.item];
		this.#renderRows();
	}
	#outdent(index) {
		this.#syncRows();
		const row = this.#rows[index];
		if (row?.ownerItem === void 0 || row.parentListParent === void 0) return;
		const siblings = row.parentList.content ?? [];
		const itemIndex = siblings.indexOf(row.item);
		if (itemIndex < 0) return;
		const trailing = siblings.splice(itemIndex + 1);
		siblings.splice(itemIndex, 1);
		if (trailing.length > 0) row.item.content = [...row.item.content ?? [], {
			...row.parentList.type === "orderedList" && row.parentList.attrs !== void 0 ? { attrs: structuredClone(row.parentList.attrs) } : {},
			content: trailing,
			type: row.parentList.type
		}];
		if (siblings.length === 0) removeListFromItem(row.ownerItem, row.parentList);
		const parentSiblings = row.parentListParent.content ?? [];
		const ownerIndex = parentSiblings.indexOf(row.ownerItem);
		if (ownerIndex < 0) return;
		parentSiblings.splice(ownerIndex + 1, 0, row.item);
		this.#renderRows();
	}
	#move(index, delta) {
		this.#syncRows();
		const row = this.#rows[index];
		if (row === void 0 || !canMoveListRow(row, delta)) return;
		const siblings = row.parentList.content ?? [];
		const itemIndex = siblings.indexOf(row.item);
		const [item] = siblings.splice(itemIndex, 1);
		if (item !== void 0) siblings.splice(itemIndex + delta, 0, item);
		this.#renderRows();
	}
	#remove(index) {
		this.#syncRows();
		const row = this.#rows[index];
		if (row === void 0 || !canRemoveListRow(row, this.#node)) return;
		const siblings = row.parentList.content ?? [];
		const itemIndex = siblings.indexOf(row.item);
		if (itemIndex < 0) return;
		siblings.splice(itemIndex, 1);
		if (siblings.length === 0 && row.ownerItem !== void 0) removeListFromItem(row.ownerItem, row.parentList);
		this.#renderRows();
	}
};
var StudioChecklistTool = class {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "☑",
		title: "Checklist"
	};
	#readOnly;
	#initialRows;
	#node;
	#root;
	#rows;
	constructor(options) {
		this.#readOnly = options.readOnly === true;
		this.#node = structuredClone(options.data?.node ?? {
			content: [{
				attrs: {
					checked: false,
					level: 0
				},
				type: "checklistItem"
			}],
			type: "checklist"
		});
		const content = this.#node.content ?? [];
		this.#rows = content.length > 0 ? content.map((item) => ({
			checked: item.attrs?.checked === true,
			content: structuredClone(item.content ?? []),
			contentPresent: item.content !== void 0,
			depth: Number(item.attrs?.level ?? 0)
		})) : [{
			checked: false,
			content: [],
			contentPresent: false,
			depth: 0
		}];
		this.#initialRows = structuredClone(this.#rows);
	}
	render() {
		this.#root = editorGroup("Checklist");
		this.#renderRows();
		return this.#root;
	}
	save() {
		this.#syncRows();
		if (sameCanonical(this.#rows, this.#initialRows)) return { node: structuredClone(this.#node) };
		return { node: {
			content: this.#rows.map((row) => ({
				attrs: {
					checked: row.checked,
					level: row.depth
				},
				...row.contentPresent || row.content.length > 0 ? { content: structuredClone(row.content) } : {},
				type: "checklistItem"
			})),
			type: "checklist"
		} };
	}
	#renderRows() {
		const root = this.#root;
		if (root === void 0) return;
		root.replaceChildren();
		for (const [index, row] of this.#rows.entries()) {
			const group = editorGroup(`Checklist item ${index + 1}`);
			group.dataset.studioDepth = String(row.depth);
			group.setAttribute("aria-level", String(row.depth + 1));
			const checked = document.createElement("input");
			checked.type = "checkbox";
			checked.checked = row.checked;
			checked.disabled = this.#readOnly;
			checked.dataset.checkState = String(index);
			checked.setAttribute("aria-label", `Checklist item ${index + 1} complete`);
			const field = inlineField(`Checklist item ${index + 1}`, row.content, this.#readOnly);
			field.dataset.checkText = String(index);
			field.addEventListener("input", () => {
				row.contentPresent = true;
			});
			group.append(checked, field);
			if (!this.#readOnly) group.append(rowButton("Move item up", () => this.#move(index, -1), index === 0), rowButton("Move item down", () => this.#move(index, 1), index === this.#rows.length - 1), rowButton("Indent item", () => this.#indent(index, 1), row.depth >= 4 || index === 0), rowButton("Outdent item", () => this.#indent(index, -1), row.depth === 0), rowButton("Remove item", () => this.#remove(index), this.#rows.length === 1));
			root.append(group);
		}
		if (!this.#readOnly) root.append(rowButton("Add checklist item", () => this.#add()));
	}
	#syncRows() {
		for (const field of this.#root?.querySelectorAll("[data-check-text]") ?? []) {
			const row = this.#rows[Number(field.dataset.checkText)];
			if (row !== void 0) row.content = preserveInlineRepresentation(row.content, readInline(field));
		}
		for (const input of this.#root?.querySelectorAll("[data-check-state]") ?? []) {
			const row = this.#rows[Number(input.dataset.checkState)];
			if (row !== void 0) row.checked = input.checked;
		}
	}
	#add() {
		this.#syncRows();
		if (this.#rows.length < 500) this.#rows.push({
			checked: false,
			content: [],
			contentPresent: false,
			depth: 0
		});
		this.#renderRows();
	}
	#indent(index, delta) {
		this.#syncRows();
		const row = this.#rows[index];
		if (row !== void 0) row.depth = Math.max(0, Math.min(4, row.depth + delta));
		this.#renderRows();
	}
	#move(index, delta) {
		this.#syncRows();
		const target = index + delta;
		if (target >= 0 && target < this.#rows.length) {
			const [row] = this.#rows.splice(index, 1);
			if (row !== void 0) this.#rows.splice(target, 0, row);
		}
		this.#renderRows();
	}
	#remove(index) {
		this.#syncRows();
		if (this.#rows.length > 1) this.#rows.splice(index, 1);
		this.#renderRows();
	}
};
var StudioTableTool = class {
	static isReadOnlySupported = true;
	static toolbox = {
		icon: "▦",
		title: "Table"
	};
	#readOnly;
	#initialCells;
	#initialHeader;
	#node;
	#cells;
	#header;
	#root;
	constructor(options) {
		this.#readOnly = options.readOnly === true;
		this.#node = structuredClone(options.data?.node ?? {
			attrs: { header: false },
			content: [{
				content: [{ type: "tableCell" }, { type: "tableCell" }],
				type: "tableRow"
			}, {
				content: [{ type: "tableCell" }, { type: "tableCell" }],
				type: "tableRow"
			}],
			type: "table"
		});
		this.#header = this.#node.attrs?.header === true;
		this.#cells = (this.#node.content ?? []).map((row) => (row.content ?? []).map((cell) => ({
			content: structuredClone(cell.content ?? []),
			contentPresent: cell.content !== void 0
		})));
		this.#initialHeader = this.#header;
		this.#initialCells = structuredClone(this.#cells);
	}
	render() {
		this.#root = editorGroup("Table");
		this.#renderTable();
		return this.#root;
	}
	save() {
		this.#syncCells();
		if (this.#header === this.#initialHeader && sameCanonical(this.#cells, this.#initialCells)) return { node: structuredClone(this.#node) };
		return { node: {
			attrs: { header: this.#header },
			content: this.#cells.map((row) => ({
				content: row.map((cell) => ({
					...cell.contentPresent || cell.content.length > 0 ? { content: structuredClone(cell.content) } : {},
					type: "tableCell"
				})),
				type: "tableRow"
			})),
			type: "table"
		} };
	}
	#renderTable() {
		const root = this.#root;
		if (root === void 0) return;
		root.replaceChildren();
		const header = document.createElement("input");
		header.type = "checkbox";
		header.checked = this.#header;
		header.disabled = this.#readOnly;
		header.setAttribute("aria-label", "Use first row as table header");
		header.addEventListener("change", () => {
			this.#header = header.checked;
		});
		root.append(header);
		const table = document.createElement("table");
		table.setAttribute("aria-label", "Table data");
		for (const [rowIndex, row] of this.#cells.entries()) {
			const tr = document.createElement("tr");
			for (const [columnIndex, value] of row.entries()) {
				const cell = document.createElement(rowIndex === 0 && this.#header ? "th" : "td");
				const field = inlineField(`Row ${rowIndex + 1}, column ${columnIndex + 1}`, value.content, this.#readOnly);
				field.dataset.tableCell = `${rowIndex}:${columnIndex}`;
				field.addEventListener("input", () => {
					value.contentPresent = true;
				});
				cell.append(field);
				tr.append(cell);
			}
			table.append(tr);
		}
		root.append(table);
		if (!this.#readOnly) root.append(rowButton("Add table row", () => this.#resize(1, 0), this.#cells.length >= 200), rowButton("Remove table row", () => this.#resize(-1, 0), this.#cells.length <= 1), rowButton("Add table column", () => this.#resize(0, 1), (this.#cells[0]?.length ?? 0) >= 50), rowButton("Remove table column", () => this.#resize(0, -1), (this.#cells[0]?.length ?? 0) <= 1));
	}
	#resize(rows, columns) {
		this.#syncCells();
		if (rows > 0 && this.#cells.length < 200) this.#cells.push(Array.from({ length: this.#cells[0]?.length ?? 1 }, () => ({
			content: [],
			contentPresent: false
		})));
		if (rows < 0 && this.#cells.length > 1) this.#cells.pop();
		if (columns > 0 && (this.#cells[0]?.length ?? 0) < 50) for (const row of this.#cells) row.push({
			content: [],
			contentPresent: false
		});
		if (columns < 0 && (this.#cells[0]?.length ?? 0) > 1) for (const row of this.#cells) row.pop();
		this.#renderTable();
	}
	#syncCells() {
		for (const field of this.#root?.querySelectorAll("[data-table-cell]") ?? []) {
			const [row, column] = (field.dataset.tableCell ?? "").split(":").map(Number);
			const targetRow = row === void 0 ? void 0 : this.#cells[row];
			const targetCell = column === void 0 ? void 0 : targetRow?.[column];
			if (targetCell !== void 0) targetCell.content = preserveInlineRepresentation(targetCell.content, readInline(field));
		}
	}
};
/** Editor.js inline tool for a bounded semantic highlight tone. */
var StudioMarkerTool = class {
	static isInline = true;
	static sanitize = { mark: { "data-studio-tone": true } };
	#button;
	#tone = "accent";
	checkState(selection) {
		const active = closestMark(selection.anchorNode) !== void 0;
		this.#button?.setAttribute("aria-pressed", String(active));
		return active;
	}
	render() {
		const button = document.createElement("button");
		button.type = "button";
		button.textContent = "Highlight";
		button.setAttribute("aria-label", "Toggle semantic highlight");
		button.setAttribute("aria-pressed", "false");
		this.#button = button;
		return button;
	}
	renderActions() {
		const select = selectControl("Highlight tone", [
			"accent",
			"info",
			"success",
			"warning",
			"danger"
		], this.#tone, false);
		select.addEventListener("change", () => {
			this.#tone = select.value;
		});
		return select;
	}
	surround(range) {
		const active = closestMark(range.commonAncestorContainer);
		if (active !== void 0) {
			const parent = active.parentNode;
			while (active.firstChild !== null) parent?.insertBefore(active.firstChild, active);
			active.remove();
			return;
		}
		if (range.collapsed) return;
		const mark = document.createElement("mark");
		mark.dataset.studioTone = this.#tone;
		mark.append(range.extractContents());
		range.insertNode(mark);
	}
};
function appendInline(parent, node) {
	if (node.type === "hardBreak") {
		parent.appendChild(document.createElement("br"));
		return;
	}
	if (node.type !== "text" || (node.text ?? "").length === 0) return;
	let child = document.createTextNode(node.text ?? "");
	for (const mark of [...node.marks ?? []].reverse()) {
		const element = document.createElement(markElement(mark));
		if (mark.type === "highlight") element.dataset.studioTone = stringAttribute(mark.attrs?.tone, "accent");
		element.append(child);
		child = element;
	}
	parent.appendChild(child);
}
function markElement(mark) {
	if (mark.type === "bold") return "strong";
	if (mark.type === "italic") return "em";
	if (mark.type === "strike") return "s";
	if (mark.type === "code") return "code";
	return "mark";
}
function readInline(parent) {
	const result = [];
	const visit = (node, marks) => {
		if (node.nodeType === Node.TEXT_NODE) {
			const text = node.nodeValue ?? "";
			if (text.length > 0) result.push({
				...marks.length > 0 ? { marks } : {},
				text,
				type: "text"
			});
			return;
		}
		if (!(node instanceof Element)) return;
		if (node.localName === "br") {
			result.push({ type: "hardBreak" });
			return;
		}
		const next = [...marks];
		const mark = canonicalMark(node);
		if (mark !== void 0 && !next.some((item) => item.type === mark.type)) {
			if (mark.type === "code") next.splice(0, next.length, mark);
			else if (!next.some((item) => item.type === "code")) next.push(mark);
		}
		for (const child of node.childNodes) visit(child, next);
	};
	for (const child of parent.childNodes) visit(child, []);
	return result;
}
function preserveInlineRepresentation(original, rendered) {
	return sameCanonical(projectInline(original), projectInline(rendered)) ? structuredClone([...original]) : rendered;
}
function projectInline(content) {
	const projection = [];
	for (const node of content) {
		if (node.type === "hardBreak") {
			projection.push({ kind: "hard-break" });
			continue;
		}
		if (node.type !== "text") continue;
		const marks = (node.marks ?? []).map((mark) => {
			if (mark.type !== "highlight") return mark.type;
			const tone = mark.attrs?.tone;
			return `${mark.type}:${typeof tone === "string" ? tone : ""}`;
		}).sort();
		const previous = projection.at(-1);
		if (previous?.kind === "text" && sameCanonical(previous.marks, marks)) previous.text += node.text ?? "";
		else projection.push({
			kind: "text",
			marks,
			text: node.text ?? ""
		});
	}
	return projection;
}
function canonicalMark(element) {
	if (element.localName === "strong" || element.localName === "b") return { type: "bold" };
	if (element.localName === "em" || element.localName === "i") return { type: "italic" };
	if (element.localName === "s" || element.localName === "del") return { type: "strike" };
	if (element.localName === "code") return { type: "code" };
	if (element.localName === "mark") {
		const tone = element.getAttribute("data-studio-tone");
		return {
			attrs: { tone: [
				"accent",
				"danger",
				"info",
				"success",
				"warning"
			].includes(tone ?? "") ? tone ?? "accent" : "accent" },
			type: "highlight"
		};
	}
}
function pastePlainText(event) {
	event.preventDefault();
	const text = event.clipboardData?.getData("text/plain") ?? "";
	const selection = globalThis.getSelection();
	if (selection === null || selection.rangeCount === 0) return;
	const range = selection.getRangeAt(0);
	range.deleteContents();
	range.insertNode(document.createTextNode(text.slice(0, 25e4)));
	range.collapse(false);
}
function flattenList(node, depth = 0, ownerItem, parentListParent) {
	const rows = [];
	for (const item of node.content ?? []) {
		const existingEditable = (item.content ?? []).find((block) => block.type === "paragraph" || block.type === "heading");
		const editableBlock = existingEditable ?? { type: "paragraph" };
		rows.push({
			depth,
			editableBlock,
			item,
			...ownerItem === void 0 ? {} : { ownerItem },
			parentList: node,
			...parentListParent === void 0 ? {} : { parentListParent },
			syntheticEditable: existingEditable === void 0
		});
		for (const nested of item.content ?? []) if (nested.type === "bulletList" || nested.type === "orderedList") rows.push(...flattenList(nested, depth + 1, item, node));
	}
	return rows;
}
function orderedListStart(node) {
	const value = Number(node.attrs?.start ?? 1);
	return Number.isSafeInteger(value) && value >= 1 && value <= 1e6 ? value : 1;
}
function canMoveListRow(row, delta) {
	const siblings = row.parentList.content ?? [];
	const index = siblings.indexOf(row.item);
	return index >= 0 && index + delta >= 0 && index + delta < siblings.length;
}
function canIndentListRow(row) {
	if (row.depth >= 4) return false;
	return (row.parentList.content ?? []).indexOf(row.item) > 0;
}
function canRemoveListRow(row, root) {
	return row.parentList !== root || (root.content?.length ?? 0) > 1;
}
function removeListFromItem(item, list) {
	item.content = (item.content ?? []).filter((block) => block !== list);
}
function editableBlockContent(blocks) {
	return blocks.find((block) => block.type === "paragraph" || block.type === "heading")?.content ?? [];
}
function mergeEditableBlockContent(blocks, content) {
	const result = structuredClone([...blocks]);
	const index = result.findIndex((block) => block.type === "paragraph" || block.type === "heading");
	if (index < 0) {
		if (content.length > 0) result.unshift({
			content: structuredClone([...content]),
			type: "paragraph"
		});
		return result;
	}
	const block = result[index];
	if (block !== void 0 && !sameCanonical(block.content ?? [], content)) block.content = structuredClone([...content]);
	return result;
}
function inlineField(label, content, readOnly) {
	const field = document.createElement("div");
	field.className = "studio-rich-text-field";
	field.contentEditable = readOnly ? "false" : "true";
	field.setAttribute("aria-label", label);
	field.setAttribute("aria-multiline", "true");
	field.setAttribute("role", "textbox");
	field.spellcheck = true;
	for (const inline of content) appendInline(field, inline);
	field.addEventListener("paste", pastePlainText);
	return field;
}
function sameCanonical(left, right) {
	if (Object.is(left, right)) return true;
	if (Array.isArray(left) || Array.isArray(right)) return Array.isArray(left) && Array.isArray(right) && left.length === right.length && left.every((value, index) => sameCanonical(value, right[index]));
	if (!isRecord$3(left) || !isRecord$3(right)) return false;
	const leftKeys = Object.keys(left).sort();
	const rightKeys = Object.keys(right).sort();
	return leftKeys.length === rightKeys.length && leftKeys.every((key, index) => key === rightKeys[index] && sameCanonical(left[key], right[key]));
}
function editorGroup(label) {
	const group = document.createElement("div");
	group.setAttribute("aria-label", label);
	group.setAttribute("role", "group");
	return group;
}
function textInput$1(label, value, readOnly) {
	const input = document.createElement("input");
	input.type = "text";
	input.setAttribute("aria-label", label);
	input.disabled = readOnly;
	input.value = value;
	return input;
}
function selectControl(label, values, selected, readOnly) {
	const select = document.createElement("select");
	select.setAttribute("aria-label", label);
	select.disabled = readOnly;
	for (const value of values) {
		const option = document.createElement("option");
		option.value = value;
		option.textContent = value;
		option.selected = value === selected;
		select.append(option);
	}
	select.value = selected;
	return select;
}
function rowButton(label, action, disabled = false) {
	const button = document.createElement("button");
	button.type = "button";
	button.textContent = label;
	button.setAttribute("aria-label", label);
	button.disabled = disabled;
	button.addEventListener("click", action);
	return button;
}
function closestMark(node) {
	let candidate = node instanceof HTMLElement ? node : node?.parentElement;
	while (candidate !== null && candidate !== void 0) {
		if (candidate.localName === "mark") return candidate;
		candidate = candidate.parentElement ?? void 0;
	}
}
function isRecord$3(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
function stringAttribute(value, fallback) {
	return typeof value === "string" ? value : fallback;
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/studio-rich-text-editor.js
/**
* The only public construction path for block prose. Hosts configure Studio
* profiles and canonical values; the selected editor remains an implementation
* detail and can never leak its document shape into an artifact.
*/
var StudioRichTextEditorFactory = class {
	#surfaceAdapter;
	constructor(surfaceAdapter = new EditorJsSurfaceAdapter()) {
		this.#surfaceAdapter = surfaceAdapter;
	}
	async create(options) {
		const profile = resolveRichTextProfile(options.profile ?? (options.containerType === void 0 ? "studio.rich-text/portable" : resolveContainerRichTextProfile(options.containerType)));
		let lastValid = parseRichTextDocument(options.value, profile);
		const readOnly = options.readOnly === true || options.binding !== void 0 && options.binding.source.kind !== "static-value";
		const mounted = {};
		let changeQueue = Promise.resolve();
		const readCanonical = async () => {
			if (mounted.surface === void 0) return lastValid;
			lastValid = parseRichTextDocument(await mounted.surface.read(), profile);
			return lastValid;
		};
		mounted.surface = await this.#surfaceAdapter.mount({
			holder: options.holder,
			initialValue: lastValid,
			onChange: () => {
				changeQueue = changeQueue.then(async () => {
					try {
						const value = await readCanonical();
						options.onChange?.({
							diagnostics: [],
							valid: true,
							value
						});
					} catch {
						options.onChange?.({
							diagnostics: [invalidEditorDiagnostic()],
							valid: false,
							value: lastValid
						});
					}
				});
			},
			...options.placeholder === void 0 ? {} : { placeholder: options.placeholder },
			readOnly
		});
		return {
			destroy: () => mounted.surface?.destroy(),
			focus: () => mounted.surface?.focus(),
			readOnly,
			replace: async (value) => {
				const canonical = parseRichTextDocument(value, profile);
				await mounted.surface?.replace(canonical);
				lastValid = canonical;
			},
			save: async () => {
				await changeQueue;
				try {
					return await readCanonical();
				} catch {
					return lastValid;
				}
			}
		};
	}
};
function invalidEditorDiagnostic() {
	return {
		code: "studio.rich-text/invalid-editor-state",
		message: {
			defaultMessage: "The latest edit is not valid for this rich-text profile.",
			key: "studio.rich-text/invalid-editor-state"
		},
		severity: "error"
	};
}
var EditorJsSurfaceAdapter = class {
	async mount(options) {
		const Runtime = (await __vitePreload(() => import("./editorjs-BQPU4-8b.js"), [])).default;
		const runtime = new Runtime({
			data: toEditorJs(options.initialValue),
			holder: options.holder,
			inlineToolbar: [
				"bold",
				"italic",
				"marker"
			],
			minHeight: 0,
			onChange: options.onChange,
			placeholder: options.placeholder ?? "",
			readOnly: options.readOnly,
			tools: {
				...studioEditorJsTools(),
				marker: StudioMarkerTool
			}
		});
		await runtime.isReady;
		return {
			destroy: () => runtime.destroy(),
			focus: () => {
				runtime.caret?.focus(true);
			},
			read: async () => fromEditorJs(await runtime.save()),
			replace: async (value) => runtime.render(toEditorJs(value))
		};
	}
};
function toEditorJs(document) {
	return {
		blocks: toStudioEditorJsBlocks(document),
		version: "2.31.6"
	};
}
function fromEditorJs(value) {
	return fromStudioEditorJsBlocks(value);
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/strict-csp-surface.js
/**
* Sink-free authoring surface for hosts enforcing strict style CSP and
* Trusted Types. It shares Studio's canonical first-party tools but never
* creates style elements, style attributes, or HTML-string sinks.
*/
var StudioStrictCspRichTextSurfaceAdapter = class {
	mount(options) {
		return Promise.resolve(new StrictCspRichTextSurface(options));
	}
};
var StrictCspRichTextSurface = class {
	#blocks = document.createElement("div");
	#options;
	#root = document.createElement("section");
	#mounted = [];
	constructor(options) {
		this.#options = options;
		this.#root.className = "studio-rich-text-strict-surface";
		this.#root.dataset.studioRichTextSurface = "strict-csp";
		this.#root.setAttribute("aria-label", options.readOnly ? "Rich text preview" : "Rich text editor");
		this.#root.setAttribute("role", "region");
		this.#blocks.className = "studio-rich-text-strict-blocks";
		this.#blocks.addEventListener("change", this.#notifyChange);
		this.#blocks.addEventListener("input", this.#notifyChange);
		this.#render(options.initialValue);
		options.holder.replaceChildren(this.#root);
	}
	destroy() {
		this.#blocks.removeEventListener("change", this.#notifyChange);
		this.#blocks.removeEventListener("input", this.#notifyChange);
		this.#mounted = [];
		this.#root.remove();
	}
	focus() {
		const target = this.#root.querySelector("[contenteditable=\"true\"], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)");
		if (target !== null) {
			target.focus();
			return;
		}
		this.#root.tabIndex = -1;
		this.#root.focus();
	}
	read() {
		return Promise.resolve(structuredClone(this.#snapshot()));
	}
	replace(value) {
		this.#render(value);
		return Promise.resolve();
	}
	#notifyChange = () => {
		this.#options.onChange();
	};
	#add(type) {
		const document = this.#snapshot();
		document.content.push(defaultNode(type));
		this.#render(document);
		this.#options.onChange();
	}
	#move(index, delta) {
		const document = this.#snapshot();
		const target = index + delta;
		if (target < 0 || target >= document.content.length) return;
		const [node] = document.content.splice(index, 1);
		if (node === void 0) return;
		document.content.splice(target, 0, node);
		this.#render(document);
		this.#options.onChange();
		this.#focusBlock(target);
	}
	#remove(index) {
		const document = this.#snapshot();
		document.content.splice(index, 1);
		if (document.content.length === 0) document.content.push(defaultNode("paragraph"));
		this.#render(document);
		this.#options.onChange();
		this.#focusBlock(Math.min(index, document.content.length - 1));
	}
	#focusBlock(index) {
		this.#blocks.querySelector(`[data-studio-rich-text-index="${String(index)}"]`)?.querySelector("[contenteditable=\"true\"], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)")?.focus();
	}
	#render(value) {
		const tools = studioEditorJsTools();
		const blocks = toStudioEditorJsBlocks(value);
		this.#mounted = blocks.map((block) => {
			const Tool = tools[block.type];
			return {
				tool: new Tool({
					data: block.data,
					readOnly: this.#options.readOnly
				}),
				type: block.type
			};
		});
		this.#root.replaceChildren();
		if (!this.#options.readOnly) this.#root.append(this.#createToolbar());
		this.#blocks.replaceChildren(...this.#mounted.map((block, index) => this.#renderBlock(block, index)));
		this.#root.append(this.#blocks);
	}
	#renderBlock(block, index) {
		const group = document.createElement("section");
		const title = studioEditorJsTools()[block.type].toolbox.title;
		group.className = "studio-rich-text-strict-block";
		group.dataset.studioRichTextIndex = String(index);
		group.setAttribute("aria-label", `${title} block ${String(index + 1)}`);
		group.setAttribute("role", "group");
		if (!this.#options.readOnly) {
			const controls = document.createElement("div");
			controls.className = "studio-rich-text-strict-block-controls";
			controls.setAttribute("aria-label", `${title} block actions`);
			controls.setAttribute("role", "toolbar");
			controls.append(actionButton$2("Move block up", () => this.#move(index, -1), index === 0), actionButton$2("Move block down", () => this.#move(index, 1), index === this.#mounted.length - 1), actionButton$2("Remove block", () => this.#remove(index)));
			group.append(controls);
		}
		group.append(block.tool.render());
		return group;
	}
	#createToolbar() {
		const tools = studioEditorJsTools();
		const toolbar = document.createElement("div");
		const select = document.createElement("select");
		select.setAttribute("aria-label", "Rich text block type");
		for (const type of STUDIO_EDITOR_JS_TOOL_NAMES) {
			const option = document.createElement("option");
			option.textContent = tools[type].toolbox.title;
			option.value = type;
			select.append(option);
		}
		toolbar.className = "studio-rich-text-strict-toolbar";
		toolbar.setAttribute("aria-label", "Rich text tools");
		toolbar.setAttribute("role", "toolbar");
		toolbar.append(select, actionButton$2("Add rich text block", () => {
			if (isToolName(select.value)) this.#add(select.value);
		}));
		const tone = highlightToneControl();
		toolbar.append(inlineActionButton("Bold selected text", () => this.#formatInline("bold")), inlineActionButton("Italicize selected text", () => this.#formatInline("italic")), inlineActionButton("Strike selected text", () => this.#formatInline("strike")), inlineActionButton("Format selected text as code", () => this.#formatInline("code")), tone, inlineActionButton("Highlight selected text", () => this.#formatInline("highlight", highlightTone(tone.value))), inlineActionButton("Insert line break", () => this.#formatInline("hard-break")));
		return toolbar;
	}
	#formatInline(action, tone = "accent") {
		const selection = globalThis.getSelection();
		if (selection === null || selection.rangeCount === 0) return;
		const range = selection.getRangeAt(0);
		const start = closestEditable(range.startContainer, this.#root);
		const end = closestEditable(range.endContainer, this.#root);
		if (start === void 0 || start !== end) return;
		if (action === "hard-break") {
			range.deleteContents();
			const lineBreak = document.createElement("br");
			range.insertNode(lineBreak);
			range.setStartAfter(lineBreak);
			range.collapse(true);
			selection.removeAllRanges();
			selection.addRange(range);
			this.#options.onChange();
			return;
		}
		if (range.collapsed) return;
		const localName = inlineElementName(action);
		const existing = closestInlineElement(range.commonAncestorContainer, start, localName);
		if (existing !== void 0) {
			const parent = existing.parentNode;
			while (existing.firstChild !== null) parent?.insertBefore(existing.firstChild, existing);
			existing.remove();
			this.#options.onChange();
			return;
		}
		const wrapper = document.createElement(localName);
		if (action === "highlight") wrapper.dataset.studioTone = tone;
		wrapper.append(range.extractContents());
		range.insertNode(wrapper);
		range.selectNodeContents(wrapper);
		selection.removeAllRanges();
		selection.addRange(range);
		this.#options.onChange();
	}
	#snapshot() {
		return {
			content: this.#mounted.map((block) => block.tool.save().node),
			type: "doc"
		};
	}
};
function actionButton$2(label, action, disabled = false) {
	const button = document.createElement("button");
	button.disabled = disabled;
	button.textContent = label;
	button.type = "button";
	button.setAttribute("aria-label", label);
	button.addEventListener("click", action);
	return button;
}
function inlineActionButton(label, action) {
	const button = actionButton$2(label, action);
	button.addEventListener("mousedown", (event) => event.preventDefault());
	return button;
}
function highlightToneControl() {
	const select = document.createElement("select");
	select.setAttribute("aria-label", "Highlight tone");
	for (const tone of [
		"accent",
		"info",
		"success",
		"warning",
		"danger"
	]) {
		const option = document.createElement("option");
		option.textContent = tone;
		option.value = tone;
		select.append(option);
	}
	return select;
}
function closestEditable(node, root) {
	let candidate = node instanceof HTMLElement ? node : node.parentElement;
	while (candidate !== null) {
		if (candidate.getAttribute("contenteditable") === "true") return candidate;
		if (candidate === root) return void 0;
		candidate = candidate.parentElement;
	}
}
function closestInlineElement(node, boundary, localName) {
	let candidate = node instanceof HTMLElement ? node : node.parentElement;
	while (candidate !== null && candidate !== boundary) {
		if (candidate.localName === localName) return candidate;
		candidate = candidate.parentElement;
	}
}
function inlineElementName(action) {
	if (action === "bold") return "strong";
	if (action === "italic") return "em";
	if (action === "strike") return "s";
	if (action === "code") return "code";
	return "mark";
}
function isToolName(value) {
	return STUDIO_EDITOR_JS_TOOL_NAMES.some((name) => name === value);
}
function highlightTone(value) {
	return [
		"accent",
		"danger",
		"info",
		"success",
		"warning"
	].includes(value) ? value : "accent";
}
function defaultNode(type) {
	switch (type) {
		case "callout": return {
			attrs: { tone: "info" },
			content: [{ type: "paragraph" }],
			type: "callout"
		};
		case "checklist": return {
			content: [{
				attrs: {
					checked: false,
					level: 0
				},
				type: "checklistItem"
			}],
			type: "checklist"
		};
		case "code": return {
			attrs: { language: "text" },
			type: "codeBlock"
		};
		case "delimiter": return { type: "horizontalRule" };
		case "header": return {
			attrs: { level: 2 },
			type: "heading"
		};
		case "list": return {
			content: [{
				content: [{ type: "paragraph" }],
				type: "listItem"
			}],
			type: "bulletList"
		};
		case "paragraph": return { type: "paragraph" };
		case "quote": return {
			content: [{ type: "paragraph" }],
			type: "blockquote"
		};
		case "table": return {
			attrs: { header: false },
			content: [{
				content: [{ type: "tableCell" }, { type: "tableCell" }],
				type: "tableRow"
			}, {
				content: [{ type: "tableCell" }, { type: "tableCell" }],
				type: "tableRow"
			}],
			type: "table"
		};
	}
}
//#endregion
//#region node_modules/@kumwe/studio-rich-text/dist/index.js
var PORTABLE_MARKS = Object.freeze([
	"bold",
	"code",
	"highlight",
	"italic",
	"strike"
]);
var PORTABLE_NODES = Object.freeze([
	"blockquote",
	"bulletList",
	"callout",
	"checklist",
	"checklistItem",
	"codeBlock",
	"doc",
	"hardBreak",
	"heading",
	"horizontalRule",
	"listItem",
	"orderedList",
	"paragraph",
	"table",
	"tableCell",
	"tableRow",
	"text"
]);
var DEFAULT_HEADING_LEVELS = Object.freeze([
	2,
	3,
	4
]);
var DEFAULT_MAXIMUM_DOCUMENT_BYTES = 1048576;
var DEFAULT_MAXIMUM_MARKS = 2e4;
var DEFAULT_MAXIMUM_MARKS_PER_NODE = PORTABLE_MARKS.length;
var RICH_TEXT_HARD_LIMITS = Object.freeze({
	maximumDepth: 128,
	maximumDocumentBytes: 10485760,
	maximumMarks: 4e5,
	maximumMarksPerNode: PORTABLE_MARKS.length,
	maximumNodes: 1e5,
	maximumTextLength: 10485760
});
var ATTRIBUTE_HARD_LIMITS = Object.freeze({
	maximumDepth: 32,
	maximumItemsPerArray: 1e4,
	maximumPropertiesPerObject: 1e3,
	maximumStringLength: 1048576,
	maximumTotalBytes: RICH_TEXT_HARD_LIMITS.maximumDocumentBytes
});
var DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS = Object.freeze({
	maximumDepth: 8,
	maximumItemsPerArray: 256,
	maximumPropertiesPerObject: 64,
	maximumStringLength: 4096,
	maximumTotalBytes: 65536
});
var DEFAULT_RICH_TEXT_PROFILE = Object.freeze({
	allowedAttributes: Object.freeze({
		callout: Object.freeze(["tone"]),
		checklistItem: Object.freeze(["checked", "level"]),
		codeBlock: Object.freeze(["language"]),
		heading: Object.freeze(["level"]),
		"mark:highlight": Object.freeze(["tone"]),
		orderedList: Object.freeze(["start"]),
		table: Object.freeze(["header"])
	}),
	allowedMarks: PORTABLE_MARKS,
	allowedNodes: PORTABLE_NODES,
	attributeLimits: DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS,
	headingLevels: DEFAULT_HEADING_LEVELS,
	maximumDepth: 32,
	maximumDocumentBytes: DEFAULT_MAXIMUM_DOCUMENT_BYTES,
	maximumMarks: DEFAULT_MAXIMUM_MARKS,
	maximumMarksPerNode: DEFAULT_MAXIMUM_MARKS_PER_NODE,
	maximumNodes: 5e3,
	maximumTextLength: 25e4
});
function parseRichTextDocument(value, profile = DEFAULT_RICH_TEXT_PROFILE) {
	validateProfile(profile);
	const node = parseNode(value, "$", 1, profile, resolveAttributeLimits(profile), {
		attributeBytes: 0,
		markCount: 0,
		nodeCount: 0,
		textLength: 0
	});
	if (node.type !== "doc") throw new TypeError("Rich-text document root must have type \"doc\".");
	const document = {
		...node,
		content: node.content ?? [],
		type: "doc"
	};
	if (utf8ByteLength(JSON.stringify(document)) > maximumDocumentBytes(profile)) throw new RangeError("Rich-text document exceeds its total-byte limit.");
	return document;
}
function parseNode(value, path, depth, profile, attributeLimits, state) {
	if (!isRecord$2(value)) throw new TypeError(`${path} must be a rich-text node with a non-empty type.`);
	assertKnownKeys(value, path, [
		"attrs",
		"content",
		"marks",
		"text",
		"type"
	]);
	if (typeof value.type !== "string" || value.type.length === 0) throw new TypeError(`${path} must be a rich-text node with a non-empty type.`);
	if (!profile.allowedNodes.includes(value.type)) throw new TypeError(`${path} uses disallowed node type "${value.type}".`);
	if (depth > profile.maximumDepth) throw new RangeError(`${path} exceeds the rich-text depth limit.`);
	state.nodeCount += 1;
	if (state.nodeCount > profile.maximumNodes) throw new RangeError("Rich-text document exceeds its node limit.");
	const node = { type: value.type };
	if (value.text !== void 0) {
		if (typeof value.text !== "string") throw new TypeError(`${path}.text must be a string.`);
		node.text = value.text;
		state.textLength += value.text.length;
		if (state.textLength > profile.maximumTextLength) throw new RangeError("Rich-text document exceeds its text-length limit.");
	}
	if (value.attrs !== void 0) node.attrs = parseAttributes(value.attrs, `${path}.attrs`, value.type, profile, attributeLimits, state);
	if (value.content !== void 0) {
		if (!Array.isArray(value.content)) throw new TypeError(`${path}.content must be an array.`);
		assertStructuralArray(value.content, `${path}.content`);
		node.content = value.content.map((child, index) => parseNode(child, `${path}.content[${index}]`, depth + 1, profile, attributeLimits, state));
	}
	if (value.marks !== void 0) {
		if (!Array.isArray(value.marks)) throw new TypeError(`${path}.marks must be an array.`);
		assertStructuralArray(value.marks, `${path}.marks`);
		const maximumPerNode = profile.maximumMarksPerNode ?? DEFAULT_MAXIMUM_MARKS_PER_NODE;
		const maximumMarks = profile.maximumMarks ?? DEFAULT_MAXIMUM_MARKS;
		if (value.marks.length > maximumPerNode) throw new RangeError(`${path}.marks exceeds the per-node mark limit.`);
		if (state.markCount + value.marks.length > maximumMarks) throw new RangeError("Rich-text document exceeds its aggregate mark limit.");
		state.markCount += value.marks.length;
		node.marks = value.marks.map((mark, index) => parseMark(mark, `${path}.marks[${index}]`, profile, attributeLimits, state));
		assertPortableMarkSet(node.marks, `${path}.marks`);
	}
	assertNodeGrammar(node, path, profile);
	return node;
}
function parseMark(value, path, profile, attributeLimits, state) {
	if (!isRecord$2(value)) throw new TypeError(`${path} must be a mark with a non-empty type.`);
	assertKnownKeys(value, path, ["attrs", "type"]);
	if (typeof value.type !== "string" || value.type.length === 0) throw new TypeError(`${path} must be a mark with a non-empty type.`);
	if (!profile.allowedMarks.includes(value.type)) throw new TypeError(`${path} uses disallowed mark type "${value.type}".`);
	const mark = { type: value.type };
	if (value.attrs !== void 0) mark.attrs = parseAttributes(value.attrs, `${path}.attrs`, `mark:${value.type}`, profile, attributeLimits, state);
	if (mark.type === "highlight") {
		const tone = mark.attrs?.tone;
		if (typeof tone !== "string" || ![
			"accent",
			"danger",
			"info",
			"success",
			"warning"
		].includes(tone)) throw new TypeError(`${path}.attrs.tone must be a configured highlight tone.`);
	} else if (mark.attrs !== void 0) throw new TypeError(`${path} cannot carry attributes in the portable rich-text grammar.`);
	return mark;
}
function assertNodeGrammar(node, path, profile) {
	switch (node.type) {
		case "doc":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			if (node.content === void 0 || node.content.length === 0) throw new TypeError(`${path}.content must contain at least one block node.`);
			assertChildTypes(node.content, path, blockNodeTypes);
			break;
		case "text":
			assertNoNodeFields(node, path, ["attrs", "content"]);
			if (node.text === void 0) throw new TypeError(`${path}.text is required for a text node.`);
			if (node.text.length === 0) throw new TypeError(`${path}.text cannot be empty.`);
			break;
		case "paragraph":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertChildTypes(node.content ?? [], path, inlineNodeTypes);
			break;
		case "heading": {
			assertNoNodeFields(node, path, ["marks", "text"]);
			assertChildTypes(node.content ?? [], path, inlineNodeTypes);
			const level = node.attrs?.level;
			const levels = profile.headingLevels ?? DEFAULT_HEADING_LEVELS;
			if (typeof level !== "number" || !Number.isInteger(level) || !levels.includes(level)) throw new TypeError(`${path}.attrs.level must be a configured heading level.`);
			break;
		}
		case "orderedList": {
			assertNoNodeFields(node, path, ["marks", "text"]);
			assertNonEmptyChildTypes(node.content, path, listItemNodeTypes);
			const start = node.attrs?.start;
			if (start !== void 0 && (!Number.isSafeInteger(start) || Number(start) < 1)) throw new TypeError(`${path}.attrs.start must be a positive integer.`);
			break;
		}
		case "bulletList":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertNonEmptyChildTypes(node.content, path, listItemNodeTypes);
			break;
		case "listItem":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertNonEmptyChildTypes(node.content, path, blockNodeTypes);
			if (node.content?.[0]?.type !== "paragraph") throw new TypeError(`${path}.content must begin with a paragraph node.`);
			break;
		case "blockquote":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertNonEmptyChildTypes(node.content, path, blockNodeTypes);
			break;
		case "callout":
			assertNoNodeFields(node, path, ["marks", "text"]);
			assertNonEmptyChildTypes(node.content, path, blockNodeTypes);
			if (typeof node.attrs?.tone !== "string" || ![
				"danger",
				"info",
				"success",
				"warning"
			].includes(node.attrs.tone)) throw new TypeError(`${path}.attrs.tone must be a configured callout tone.`);
			break;
		case "checklist":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertNonEmptyChildTypes(node.content, path, checklistItemNodeTypes);
			break;
		case "checklistItem":
			assertNoNodeFields(node, path, ["marks", "text"]);
			assertChildTypes(node.content ?? [], path, inlineNodeTypes);
			if (typeof node.attrs?.checked !== "boolean") throw new TypeError(`${path}.attrs.checked must be a boolean.`);
			if (!Number.isSafeInteger(node.attrs.level) || Number(node.attrs.level) < 0 || Number(node.attrs.level) > 4) throw new TypeError(`${path}.attrs.level must be an integer from zero through four.`);
			break;
		case "table":
			assertNoNodeFields(node, path, ["marks", "text"]);
			assertNonEmptyChildTypes(node.content, path, tableRowNodeTypes);
			if (typeof node.attrs?.header !== "boolean") throw new TypeError(`${path}.attrs.header must be a boolean.`);
			assertRectangularTable(node.content, path);
			break;
		case "tableRow":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertNonEmptyChildTypes(node.content, path, tableCellNodeTypes);
			break;
		case "tableCell":
			assertNoNodeFields(node, path, [
				"attrs",
				"marks",
				"text"
			]);
			assertChildTypes(node.content ?? [], path, inlineNodeTypes);
			break;
		case "codeBlock":
			assertNoNodeFields(node, path, ["content", "marks"]);
			if (node.text === void 0) throw new TypeError(`${path}.text is required for a code block.`);
			if (typeof node.attrs?.language !== "string" || !/^[A-Za-z0-9][A-Za-z0-9+_.#-]{0,63}$/u.test(node.attrs.language)) throw new TypeError(`${path}.attrs.language must be a bounded language identifier.`);
			break;
		case "hardBreak":
		case "horizontalRule":
			assertNoNodeFields(node, path, [
				"attrs",
				"content",
				"marks",
				"text"
			]);
			break;
		default: throw new TypeError(`${path} uses a node without a portable grammar.`);
	}
}
function assertPortableMarkSet(marks, path) {
	const types = /* @__PURE__ */ new Set();
	for (const mark of marks) {
		if (types.has(mark.type)) throw new TypeError(`${path} cannot contain duplicate ${mark.type} marks.`);
		types.add(mark.type);
	}
	if (types.has("code") && types.size > 1) throw new TypeError(`${path} cannot combine code with another mark.`);
}
var blockNodeTypes = /* @__PURE__ */ new Set([
	"blockquote",
	"bulletList",
	"callout",
	"checklist",
	"codeBlock",
	"heading",
	"horizontalRule",
	"orderedList",
	"paragraph",
	"table"
]);
var inlineNodeTypes = /* @__PURE__ */ new Set(["hardBreak", "text"]);
var listItemNodeTypes = /* @__PURE__ */ new Set(["listItem"]);
var checklistItemNodeTypes = /* @__PURE__ */ new Set(["checklistItem"]);
var tableRowNodeTypes = /* @__PURE__ */ new Set(["tableRow"]);
var tableCellNodeTypes = /* @__PURE__ */ new Set(["tableCell"]);
function assertRectangularTable(rows, path) {
	const width = rows?.[0]?.content?.length ?? 0;
	const invalid = rows?.findIndex((row) => row.content?.length !== width) ?? -1;
	if (width < 1 || invalid >= 0) throw new TypeError(`${path}.content must be a non-empty rectangular table.`);
}
function assertNoNodeFields(node, path, fields) {
	const present = fields.find((field) => node[field] !== void 0);
	if (present !== void 0) throw new TypeError(`${path}.${present} is not valid on a ${node.type} node.`);
}
function assertChildTypes(content, path, allowed) {
	const invalidIndex = content.findIndex((child) => !allowed.has(child.type));
	if (invalidIndex >= 0) throw new TypeError(`${path}.content[${invalidIndex}] is not valid inside this node.`);
}
function assertNonEmptyChildTypes(content, path, allowed) {
	if (content === void 0 || content.length === 0) throw new TypeError(`${path}.content must contain at least one child node.`);
	assertChildTypes(content, path, allowed);
}
function parseAttributes(value, path, ownerType, profile, limits, state) {
	const attributes = parseJsonObject(value, path, 1, limits, state);
	const allowed = profile.allowedAttributes[ownerType] ?? [];
	for (const key of Object.keys(attributes)) if (!allowed.includes(key)) throw new TypeError(`${path}.${key} is not allowed for ${ownerType}.`);
	return attributes;
}
function parseJsonObject(value, path, depth, limits, state) {
	if (!isRecord$2(value)) throw new TypeError(`${path} must be an object.`);
	assertAttributeDepth(depth, path, limits);
	const entries = Object.entries(value);
	if (entries.length > limits.maximumPropertiesPerObject) throw new RangeError(`${path} exceeds the attribute property limit.`);
	addAttributeBytes(state, limits, 2);
	const parsed = {};
	for (const [index, [key, entry]] of entries.entries()) {
		assertAttributeKey(key, path, limits);
		addAttributeBytes(state, limits, (index === 0 ? 0 : 1) + jsonByteLength(key) + 1);
		parsed[key] = parseJsonValue(entry, `${path}.${key}`, depth + 1, limits, state);
	}
	return parsed;
}
function parseJsonValue(value, path, depth, limits, state) {
	if (typeof value === "string") {
		if (value.length > limits.maximumStringLength) throw new RangeError(`${path} exceeds the attribute string limit.`);
		addAttributeBytes(state, limits, jsonByteLength(value));
		return value;
	}
	if (value === null || typeof value === "boolean") {
		addAttributeBytes(state, limits, jsonByteLength(value));
		return value;
	}
	if (typeof value === "number" && Number.isFinite(value)) {
		addAttributeBytes(state, limits, jsonByteLength(value));
		return value;
	}
	if (Array.isArray(value)) {
		assertAttributeDepth(depth, path, limits);
		assertJsonArray(value, path, limits);
		addAttributeBytes(state, limits, 2);
		return value.map((entry, index) => {
			if (index > 0) addAttributeBytes(state, limits, 1);
			return parseJsonValue(entry, `${path}[${index}]`, depth + 1, limits, state);
		});
	}
	if (isRecord$2(value)) return parseJsonObject(value, path, depth, limits, state);
	throw new TypeError(`${path} is not JSON-compatible.`);
}
function addAttributeBytes(state, limits, count) {
	state.attributeBytes += count;
	if (state.attributeBytes > limits.maximumTotalBytes) throw new RangeError("Rich-text attributes exceed the total-byte limit.");
}
function assertAttributeDepth(depth, path, limits) {
	if (depth > limits.maximumDepth) throw new RangeError(`${path} exceeds the attribute depth limit.`);
}
function assertAttributeKey(key, path, limits) {
	if (key === "__proto__" || key === "constructor" || key === "prototype") throw new TypeError(`${path}.${key} is a forbidden object key.`);
	if (key.length > limits.maximumStringLength) throw new RangeError(`${path} contains an attribute key that exceeds the string limit.`);
}
function assertJsonArray(value, path, limits) {
	if (value.length > limits.maximumItemsPerArray) throw new RangeError(`${path} exceeds the attribute item limit.`);
	const keys = Object.keys(value);
	for (const key of keys) if (key === "__proto__" || key === "constructor" || key === "prototype") throw new TypeError(`${path}.${key} is a forbidden object key.`);
	if (keys.length !== value.length || keys.some((key, index) => key !== String(index))) throw new TypeError(`${path} must be a dense JSON array without extra properties.`);
}
function assertStructuralArray(value, path) {
	if (Object.getPrototypeOf(value) !== Array.prototype || Object.getOwnPropertySymbols(value).length) throw new TypeError(`${path} must be a dense JSON array without extra properties.`);
	const names = Object.getOwnPropertyNames(value);
	if (names.length !== value.length + 1 || names[value.length] !== "length" || names.slice(0, -1).some((name, index) => name !== String(index))) throw new TypeError(`${path} must be a dense JSON array without extra properties.`);
}
function assertKnownKeys(value, path, allowedKeys) {
	const allowed = new Set(allowedKeys);
	const unknown = Object.keys(value).find((key) => !allowed.has(key));
	if (unknown !== void 0) throw new TypeError(`${path}.${unknown} is not a recognized rich-text key.`);
}
function jsonByteLength(value) {
	const serialized = JSON.stringify(value);
	if (serialized === void 0) throw new TypeError("Attribute value is not JSON-compatible.");
	return utf8ByteLength(serialized);
}
function utf8ByteLength(value) {
	let bytes = 0;
	for (let index = 0; index < value.length; index += 1) {
		const code = value.charCodeAt(index);
		if (code <= 127) bytes += 1;
		else if (code <= 2047) bytes += 2;
		else if (code >= 55296 && code <= 56319) {
			const next = value.charCodeAt(index + 1);
			if (next >= 56320 && next <= 57343) {
				bytes += 4;
				index += 1;
			} else bytes += 3;
		} else bytes += 3;
	}
	return bytes;
}
function isRecord$2(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}
function validateProfile(profile) {
	for (const [name, value] of [
		["maximumDepth", profile.maximumDepth],
		["maximumNodes", profile.maximumNodes],
		["maximumTextLength", profile.maximumTextLength]
	]) assertBoundedProfileLimit(name, value, RICH_TEXT_HARD_LIMITS[name]);
	for (const [name, value] of [
		["maximumDocumentBytes", maximumDocumentBytes(profile)],
		["maximumMarks", profile.maximumMarks ?? DEFAULT_MAXIMUM_MARKS],
		["maximumMarksPerNode", profile.maximumMarksPerNode ?? DEFAULT_MAXIMUM_MARKS_PER_NODE]
	]) assertBoundedProfileLimit(name, value, RICH_TEXT_HARD_LIMITS[name]);
	if ((profile.maximumMarksPerNode ?? DEFAULT_MAXIMUM_MARKS_PER_NODE) > (profile.maximumMarks ?? DEFAULT_MAXIMUM_MARKS)) throw new RangeError("maximumMarksPerNode cannot exceed maximumMarks.");
	if (!profile.allowedNodes.includes("doc") || !profile.allowedNodes.includes("text")) throw new TypeError("Rich-text profile must allow doc and text nodes.");
	if (new Set(profile.allowedNodes).size !== profile.allowedNodes.length) throw new TypeError("Rich-text profile node names must be unique.");
	if (new Set(profile.allowedMarks).size !== profile.allowedMarks.length) throw new TypeError("Rich-text profile mark names must be unique.");
	const unsupportedNode = profile.allowedNodes.find((name) => !PORTABLE_NODES.includes(name));
	if (unsupportedNode !== void 0) throw new TypeError(`Rich-text profile node "${unsupportedNode}" has no portable grammar.`);
	const unsupportedMark = profile.allowedMarks.find((name) => !PORTABLE_MARKS.includes(name));
	if (unsupportedMark !== void 0) throw new TypeError(`Rich-text profile mark "${unsupportedMark}" has no portable grammar.`);
	validateHeadingLevels(profile.headingLevels ?? DEFAULT_HEADING_LEVELS);
	resolveAttributeLimits(profile);
}
function assertBoundedProfileLimit(name, value, hardMaximum) {
	if (!Number.isInteger(value) || value < 1) throw new RangeError(`${name} must be a positive integer.`);
	if (value > hardMaximum) throw new RangeError(`${name} exceeds the immutable safety ceiling of ${hardMaximum}.`);
}
function validateHeadingLevels(levels) {
	if (levels.length === 0 || new Set(levels).size !== levels.length || levels.some((level) => !Number.isInteger(level) || level < 1 || level > 6)) throw new RangeError("headingLevels must contain unique integer levels from 1 through 6.");
}
function maximumDocumentBytes(profile) {
	return profile.maximumDocumentBytes ?? DEFAULT_MAXIMUM_DOCUMENT_BYTES;
}
function resolveAttributeLimits(profile) {
	const limits = {
		...DEFAULT_RICH_TEXT_ATTRIBUTE_LIMITS,
		...profile.attributeLimits
	};
	for (const [name, value] of Object.entries(limits)) assertBoundedProfileLimit(name, value, ATTRIBUTE_HARD_LIMITS[name]);
	return limits;
}
//#endregion
//#region node_modules/@kumwe/studio-renderer-web/dist/scoped-css.js
var TARGETS = Object.freeze({
	action: "[data-studio-part=\"action\"]",
	content: "[data-studio-part=\"content\"]",
	heading: "[data-studio-part=\"heading\"]",
	media: "[data-studio-part=\"media\"]",
	self: ""
});
var ALLOWED_PROPERTIES = /* @__PURE__ */ new Set([
	"background-color",
	"border-color",
	"border-radius",
	"border-style",
	"border-width",
	"color",
	"font-family",
	"font-size",
	"font-style",
	"font-weight",
	"gap",
	"letter-spacing",
	"line-height",
	"margin-block",
	"margin-inline",
	"max-inline-size",
	"min-block-size",
	"opacity",
	"padding-block",
	"padding-inline",
	"text-align",
	"text-decoration",
	"text-transform"
]);
var VALUE = /^(?:#[0-9A-Fa-f]{3,8}|-?[0-9]+(?:\.[0-9]+)?(?:ch|em|rem|%|px)?|[a-z][a-z0-9 -]{0,126}|var\(--studio-[a-z0-9-]{1,100}\))$/u;
/** Compile structured host style intent into one node-bounded stylesheet. */
function compileStudioScopedStyleSheet(scope, sheet) {
	if (!/^[A-Za-z][A-Za-z0-9_-]{0,511}$/u.test(scope)) throw new TypeError("Scoped CSS scope must be a bounded CSS-safe identifier.");
	if (sheet.rules.length > 100) throw new RangeError("Scoped stylesheet exceeds 100 rules.");
	const base = `[data-studio-scope="${scope}"]`;
	return sheet.rules.map((rule) => {
		if (!Object.hasOwn(TARGETS, rule.target)) throw new TypeError(`Scoped CSS target ${rule.target} is not allowed.`);
		const entries = Object.entries(rule.declarations);
		if (entries.length > 50) throw new RangeError("Scoped style rule exceeds 50 declarations.");
		const declarations = entries.sort(([left], [right]) => left.localeCompare(right)).map(([property, value]) => {
			if (!ALLOWED_PROPERTIES.has(property)) throw new TypeError(`Scoped CSS property ${property} is not allowed.`);
			if (value.length > 256 || !VALUE.test(value) || /(?:url|expression|javascript|@|[;{}])/iu.test(value)) throw new TypeError(`Scoped CSS value for ${property} is not allowed.`);
			return `${property}:${value}`;
		}).join(";");
		return `${base}${TARGETS[rule.target]}{${declarations}}`;
	}).join("");
}
//#endregion
//#region node_modules/@kumwe/studio-media/dist/media-library.js
var MEDIA_PROVIDER_FAILURE = Object.freeze({
	defaultMessage: "The media library could not be loaded.",
	key: "studio.media/provider-failed"
});
var MediaLibrary = class {
	#listeners = /* @__PURE__ */ new Set();
	#provider;
	#abortController;
	#state = {
		assets: [],
		status: "idle"
	};
	constructor(provider) {
		this.#provider = provider;
	}
	get state() {
		return structuredClone(this.#state);
	}
	dispose() {
		this.#abortController?.abort();
		this.#listeners.clear();
	}
	async loadNext() {
		if (this.#state.query === void 0 || this.#state.nextCursor === void 0) return this.state;
		return this.#load({
			...this.#state.query,
			cursor: this.#state.nextCursor
		}, [...this.#state.assets]);
	}
	async search(query) {
		const normalized = { limit: Math.max(1, Math.min(100, Math.trunc(query.limit))) };
		if (query.mediaTypes !== void 0) normalized.mediaTypes = [...query.mediaTypes];
		if (query.search !== void 0) normalized.search = query.search;
		return this.#load(normalized, []);
	}
	subscribe(listener) {
		this.#listeners.add(listener);
		listener(this.state);
		return () => {
			this.#listeners.delete(listener);
		};
	}
	async #load(query, existing) {
		this.#abortController?.abort();
		const controller = new AbortController();
		this.#abortController = controller;
		this.#setState({
			assets: existing,
			query,
			status: "loading"
		});
		try {
			const page = await this.#provider.list(query, controller.signal);
			if (controller.signal.aborted) return this.state;
			const next = {
				assets: [...existing, ...page.assets],
				query,
				status: "ready"
			};
			if (page.nextCursor !== void 0) next.nextCursor = page.nextCursor;
			this.#setState(next);
		} catch {
			if (!controller.signal.aborted) this.#setState({
				assets: existing,
				error: { ...MEDIA_PROVIDER_FAILURE },
				query,
				status: "error"
			});
		}
		return this.state;
	}
	#setState(state) {
		this.#state = state;
		for (const listener of this.#listeners) listener(this.state);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-media/dist/upload-controller.js
var MEDIA_UPLOAD_FAILURE = Object.freeze({
	defaultMessage: "The upload could not be completed.",
	key: "studio.media/upload-failed"
});
var MEDIA_UPLOAD_TOO_LARGE = Object.freeze({
	defaultMessage: "The file is larger than the host allows for this upload.",
	key: "studio.media/upload-too-large"
});
var ACTIVE_STATES = /* @__PURE__ */ new Set([
	"authorized",
	"requested",
	"transferring",
	"verifying"
]);
var MediaUploadController = class {
	#listeners = /* @__PURE__ */ new Set();
	#sessionId;
	#transport;
	#abortController;
	#file;
	#session;
	constructor(transport, options) {
		this.#transport = transport;
		this.#sessionId = options?.sessionId ?? (() => crypto.randomUUID());
	}
	get session() {
		if (this.#session === void 0) throw new Error("No upload session has been started.");
		return structuredClone(this.#session);
	}
	cancel() {
		const current = this.#session;
		if (current === void 0 || !ACTIVE_STATES.has(current.state)) return;
		this.#abortController?.abort();
		this.#setSession({
			...structuredClone(current),
			state: "cancelled"
		});
		this.#transport.abort(current.id).catch(() => void 0);
	}
	async retry() {
		const current = this.#session;
		const file = this.#file;
		if (current?.state !== "failed" || file === void 0) throw new Error("Only a failed upload session can be retried.");
		return this.#run(file, structuredClone(current.request));
	}
	subscribe(listener) {
		this.#listeners.add(listener);
		if (this.#session !== void 0) listener(this.session);
		return () => {
			this.#listeners.delete(listener);
		};
	}
	async upload(file, request) {
		if (this.#session !== void 0 && ACTIVE_STATES.has(this.#session.state)) throw new Error("An upload session is already in progress.");
		if (file.size < 1) throw new Error("Cannot upload an empty file.");
		const descriptor = {
			byteSize: file.size,
			filename: request.filename,
			mediaType: request.mediaType,
			purpose: request.purpose
		};
		if (request.checksum !== void 0) descriptor.checksum = request.checksum;
		this.#file = file;
		return this.#run(file, descriptor);
	}
	#fail() {
		const current = this.#session;
		if (current === void 0) return;
		const failed = {
			contractVersion: current.contractVersion,
			failure: {
				code: "studio.media/upload-failed",
				message: { ...MEDIA_UPLOAD_FAILURE },
				severity: "error"
			},
			id: current.id,
			kind: "media-upload-session",
			progress: { ...current.progress },
			request: { ...current.request },
			state: "failed"
		};
		if (current.plan !== void 0) failed.plan = { ...current.plan };
		this.#setSession(failed);
	}
	async #run(file, request) {
		const controller = new AbortController();
		this.#abortController = controller;
		const totalBytes = request.byteSize;
		const base = {
			contractVersion: STUDIO_CONTRACT_VERSION,
			id: this.#sessionId(request),
			kind: "media-upload-session",
			request
		};
		this.#setSession({
			...base,
			progress: {
				totalBytes,
				transferredBytes: 0
			},
			state: "requested"
		});
		try {
			const plan = await this.#transport.authorize(request, controller.signal);
			if (controller.signal.aborted) return this.session;
			if (totalBytes > plan.maximumBytes) {
				this.#setSession({
					...base,
					failure: {
						code: "studio.media/upload-too-large",
						message: { ...MEDIA_UPLOAD_TOO_LARGE },
						parameters: {
							byteSize: totalBytes,
							maximumBytes: plan.maximumBytes
						},
						severity: "error"
					},
					plan,
					progress: {
						totalBytes,
						transferredBytes: 0
					},
					state: "failed"
				});
				return this.session;
			}
			this.#setSession({
				...base,
				plan,
				progress: {
					totalBytes,
					transferredBytes: 0
				},
				state: "authorized"
			});
			this.#setSession({
				...base,
				plan,
				progress: {
					totalBytes,
					transferredBytes: 0
				},
				state: "transferring"
			});
			const chunkBytes = Math.max(1, plan.chunkBytes ?? totalBytes);
			let transferredBytes = 0;
			while (transferredBytes < totalBytes) {
				const data = file.slice(transferredBytes, Math.min(transferredBytes + chunkBytes, totalBytes));
				await this.#transport.transfer({
					data,
					offset: transferredBytes,
					sessionId: base.id
				}, controller.signal);
				if (controller.signal.aborted) return this.session;
				transferredBytes = Math.min(transferredBytes + data.size, totalBytes);
				this.#setSession({
					...base,
					plan,
					progress: {
						totalBytes,
						transferredBytes
					},
					state: "transferring"
				});
			}
			this.#setSession({
				...base,
				plan,
				progress: {
					totalBytes,
					transferredBytes: totalBytes
				},
				state: "verifying"
			});
			const asset = await this.#transport.finalize(base.id, controller.signal);
			if (controller.signal.aborted) return this.session;
			this.#setSession({
				...base,
				asset,
				plan,
				progress: {
					totalBytes,
					transferredBytes: totalBytes
				},
				state: "complete"
			});
		} catch {
			if (!controller.signal.aborted) this.#fail();
		}
		return this.session;
	}
	#setSession(session) {
		this.#session = session;
		for (const listener of this.#listeners) listener(this.session);
	}
};
//#endregion
//#region node_modules/@kumwe/studio-media/dist/validate-media-reference.js
/**
* Semantic validation the media-reference schema cannot express: a
* rectangle crop uses normalized coordinates and MUST remain inside the
* source bounds. Schema validation is assumed to have passed already.
*/
function validateMediaReference(reference) {
	const diagnostics = [];
	const crop = reference.cropIntent;
	if (crop?.mode === "rectangle") {
		if (crop.x + crop.width > 1 || crop.y + crop.height > 1) diagnostics.push({
			code: "studio.media/crop-out-of-bounds",
			location: { artifactId: reference.assetId },
			message: {
				defaultMessage: "The crop rectangle extends beyond the source media bounds.",
				key: "studio.media/crop-out-of-bounds"
			},
			severity: "error"
		});
	}
	return diagnostics;
}
//#endregion
//#region node_modules/@kumwe/studio-media/dist/media-field.js
/**
* Host-neutral media-field state machine. It coordinates the complete author
* journey while persisting only a canonical MediaReference — never bytes,
* delivery URLs, upload grants, Editor state, or catalogue projections.
*/
var StudioMediaFieldController = class {
	#library;
	#listeners = /* @__PURE__ */ new Set();
	#mediaTypes;
	#onChange;
	#provider;
	#readOnly;
	#upload;
	#usage;
	#asset;
	#libraryState = {
		assets: [],
		status: "idle"
	};
	#status;
	#uploadState;
	#value;
	constructor(options) {
		this.#provider = options.provider;
		this.#usage = options.usage;
		this.#mediaTypes = options.mediaTypes === void 0 ? void 0 : [...options.mediaTypes];
		this.#onChange = options.onChange;
		this.#readOnly = options.readOnly === true || options.binding !== void 0 && options.binding.source.kind !== "static-value";
		this.#value = options.value === void 0 ? void 0 : structuredClone(options.value);
		this.#upload = options.uploadTransport === void 0 ? void 0 : new MediaUploadController(options.uploadTransport);
		this.#status = this.#value === void 0 ? "empty" : "browsing";
		this.#library = new MediaLibrary(options.provider);
		this.#library.subscribe((library) => {
			this.#libraryState = library;
			if (library.status === "error") this.#status = "error";
			this.#emit(false);
		});
		if (this.#upload !== void 0) this.#upload.subscribe((upload) => {
			this.#uploadState = upload;
			this.#status = upload.state === "failed" ? "error" : upload.state === "complete" ? "browsing" : "uploading";
			this.#emit(false);
		});
	}
	get state() {
		const state = {
			diagnostics: this.#value === void 0 ? [] : validateMediaReference(this.#value),
			library: structuredClone(this.#libraryState),
			readOnly: this.#readOnly,
			status: this.#status
		};
		if (this.#asset !== void 0) state.asset = structuredClone(this.#asset);
		if (this.#uploadState !== void 0) state.upload = structuredClone(this.#uploadState);
		if (this.#value !== void 0) state.value = structuredClone(this.#value);
		return state;
	}
	cancelUpload() {
		this.#assertMutable();
		this.#upload?.cancel();
	}
	clear() {
		this.#assertMutable();
		this.#asset = void 0;
		this.#value = void 0;
		this.#status = "empty";
		this.#emit(true);
	}
	dispose() {
		this.#library.dispose();
		this.#listeners.clear();
	}
	async drop(files) {
		return this.#uploadFiles([...files]);
	}
	async loadNext() {
		this.#status = "browsing";
		await this.#library.loadNext();
		return this.state;
	}
	async open() {
		return this.search("");
	}
	async paste(items) {
		const files = [...items].filter((item) => item.kind === "file").map((item) => item.getAsFile()).filter((file) => file !== null);
		return this.#uploadFiles(files);
	}
	async resolve() {
		if (this.#value === void 0) return this.state;
		this.#status = "browsing";
		this.#emit(false);
		try {
			const asset = await this.#provider.get(this.#value.assetId);
			if (asset === null) {
				this.#asset = void 0;
				this.#status = "orphaned";
			} else {
				this.#asset = asset;
				this.#status = asset.state === "rejected" || asset.state === "quarantined" ? "error" : "ready";
			}
		} catch {
			this.#status = "error";
		}
		this.#emit(false);
		return this.state;
	}
	async retryUpload() {
		this.#assertMutable();
		if (this.#upload === void 0) throw new Error("This media field has no upload transport.");
		const result = await this.#upload.retry();
		await this.#acceptCompletedUpload(result);
		return this.state;
	}
	async search(search) {
		this.#status = "browsing";
		await this.#library.search({
			limit: 40,
			...this.#mediaTypes === void 0 ? {} : { mediaTypes: this.#mediaTypes },
			...search.trim().length === 0 ? {} : { search: search.trim().slice(0, 500) }
		});
		return this.state;
	}
	select(asset) {
		this.#assertMutable();
		this.#asset = structuredClone(asset);
		const alt = asset.metadata.altText?.trim();
		this.#value = {
			accessibility: {
				altText: alt === void 0 || alt.length === 0 ? asset.filename : alt,
				...asset.metadata.caption === void 0 ? {} : { caption: asset.metadata.caption },
				mode: "informative"
			},
			assetId: asset.id,
			assetRevision: asset.revision,
			contractVersion: STUDIO_CONTRACT_VERSION,
			kind: "media-reference",
			usage: this.#usage
		};
		this.#status = asset.state === "ready" ? "ready" : asset.state === "processing" ? "uploading" : "error";
		this.#emit(true);
	}
	setAltText(altText) {
		this.#assertMutable();
		const value = this.#requireValue();
		const caption = value.accessibility.mode === "informative" ? value.accessibility.caption : void 0;
		value.accessibility = {
			altText: altText.trim().slice(0, 5e3),
			...caption === void 0 ? {} : { caption },
			mode: "informative"
		};
		this.#emit(true);
	}
	setCaption(caption) {
		this.#assertMutable();
		const value = this.#requireValue();
		if (value.accessibility.mode !== "informative") return;
		value.accessibility = {
			...value.accessibility,
			...caption === void 0 || caption.length === 0 ? {} : { caption: caption.slice(0, 2e4) }
		};
		this.#emit(true);
	}
	setDecorative(decorative) {
		this.#assertMutable();
		const value = this.#requireValue();
		if (decorative) value.accessibility = { mode: "decorative" };
		else {
			const metadataAlt = this.#asset?.metadata.altText?.trim() ?? "";
			const filename = this.#asset?.filename ?? "";
			value.accessibility = {
				altText: metadataAlt.length > 0 ? metadataAlt : filename.length > 0 ? filename : "Media",
				mode: "informative"
			};
		}
		this.#emit(true);
	}
	setFocalPoint(point) {
		this.#assertMutable();
		const value = this.#requireValue();
		if (point === void 0) delete value.focalPoint;
		else value.focalPoint = {
			x: clampUnit(point.x),
			y: clampUnit(point.y)
		};
		this.#emit(true);
	}
	setRenditionIntent(intent) {
		this.#assertMutable();
		const value = this.#requireValue();
		if (intent === void 0) delete value.renditionIntent;
		else value.renditionIntent = structuredClone(intent);
		this.#emit(true);
	}
	subscribe(listener) {
		this.#listeners.add(listener);
		listener(this.state);
		return () => {
			this.#listeners.delete(listener);
		};
	}
	async upload(file) {
		return this.#uploadFiles([file]);
	}
	#assertMutable() {
		if (this.#readOnly) throw new Error("Dynamic and read-only media fields cannot be mutated.");
	}
	async #acceptCompletedUpload(session) {
		if (session.state !== "complete" || session.asset === void 0) return;
		const asset = await this.#provider.get(session.asset.id);
		if (asset === null) {
			this.#value = {
				accessibility: {
					altText: session.request.filename,
					mode: "informative"
				},
				assetId: session.asset.id,
				assetRevision: session.asset.revision,
				contractVersion: STUDIO_CONTRACT_VERSION,
				kind: "media-reference",
				usage: this.#usage
			};
			this.#asset = void 0;
			this.#status = "orphaned";
			this.#emit(true);
			return;
		}
		this.select(asset);
	}
	#emit(changed) {
		const state = this.state;
		if (changed) this.#onChange?.(state);
		for (const listener of this.#listeners) listener(state);
	}
	#requireValue() {
		if (this.#value === void 0) throw new Error("Select media before editing its usage.");
		return this.#value;
	}
	async #uploadFiles(files) {
		this.#assertMutable();
		const file = files[0];
		if (file === void 0) return this.state;
		if (this.#mediaTypes !== void 0 && !this.#mediaTypes.includes(file.type)) throw new TypeError(`Media type "${file.type}" is not accepted by this field.`);
		this.#status = "uploading";
		this.#emit(false);
		if (this.#upload === void 0) {
			const asset = await this.#provider.upload({
				alt: file.name,
				file
			});
			this.select(asset);
			return this.state;
		}
		const result = await this.#upload.upload(file, {
			filename: file.name,
			mediaType: file.type || "application/octet-stream",
			purpose: this.#usage
		});
		await this.#acceptCompletedUpload(result);
		return this.state;
	}
};
function clampUnit(value) {
	if (!Number.isFinite(value)) throw new TypeError("Focal coordinates must be finite numbers.");
	return Math.max(0, Math.min(1, value));
}
//#endregion
//#region node_modules/@kumwe/studio/dist/media-authoring-control.js
function mountStudioMediaReferenceControl(options, services) {
	return new StudioMediaReferenceControl(options, services);
}
function mountStudioMediaCollectionControl(options, services) {
	return new StudioMediaCollectionControl(options, services);
}
var StudioMediaReferenceControl = class {
	#controller;
	#holder;
	#onChange;
	#unsubscribe;
	readOnly;
	#error;
	#lastValid;
	constructor(options, services) {
		this.#holder = group(options.holder, "Media picker");
		this.#onChange = options.onChange;
		this.#lastValid = optionalReference(options.value);
		this.#controller = new StudioMediaFieldController({
			...options.binding === void 0 ? {} : { binding: options.binding },
			...options.mediaTypes === void 0 ? {} : { mediaTypes: [...options.mediaTypes] },
			onChange: (state) => this.#acceptChange(state),
			provider: services.provider,
			...options.readOnly === void 0 ? {} : { readOnly: options.readOnly },
			...services.uploadTransport === void 0 ? {} : { uploadTransport: services.uploadTransport },
			usage: options.usage ?? this.#lastValid?.usage ?? "studio.media/content",
			...this.#lastValid === void 0 ? {} : { value: this.#lastValid }
		});
		this.readOnly = this.#controller.state.readOnly;
		this.#holder.addEventListener("dragover", (event) => {
			if (!this.readOnly) event.preventDefault();
		});
		this.#holder.addEventListener("drop", (event) => {
			if (this.readOnly || event.dataTransfer === null) return;
			event.preventDefault();
			this.#run(() => this.#controller.drop(event.dataTransfer?.files ?? []));
		});
		this.#holder.addEventListener("paste", (event) => {
			if (this.readOnly || event.clipboardData === null) return;
			this.#run(() => this.#controller.paste(event.clipboardData?.items ?? []));
		});
		this.#unsubscribe = this.#controller.subscribe(() => this.#render());
		if (this.#lastValid === void 0) this.#run(() => this.#controller.open());
		else this.#run(() => this.#controller.resolve());
	}
	destroy() {
		this.#unsubscribe();
		this.#controller.dispose();
		this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector("[aria-label=\"Search media\"]")?.focus();
	}
	value() {
		return this.#lastValid === void 0 ? void 0 : structuredClone(this.#lastValid);
	}
	#acceptChange(state) {
		let valid = !state.diagnostics.some((diagnostic) => diagnostic.severity === "error");
		if (valid && state.value !== void 0) try {
			this.#lastValid = canonicalReference(state.value);
		} catch {
			valid = false;
		}
		else if (valid) this.#lastValid = void 0;
		this.#onChange?.({
			valid,
			value: this.value()
		});
	}
	#render() {
		const state = this.#controller.state;
		const browser = document.createElement("section");
		browser.setAttribute("aria-label", "Media library");
		const search = input("Search media", "", this.readOnly);
		const searchButton = button("Search media library", () => {
			this.#run(() => this.#controller.search(search.value));
		});
		searchButton.disabled = this.readOnly;
		browser.append(search, searchButton);
		for (const asset of state.library.assets) browser.append(button(`Select ${mediaAssetLabel(asset)}`, () => this.#controller.select(asset), this.readOnly));
		if (state.library.nextCursor !== void 0) browser.append(button("Load more media", () => {
			this.#run(() => this.#controller.loadNext());
		}, this.readOnly));
		const upload = document.createElement("input");
		upload.type = "file";
		upload.setAttribute("aria-label", "Upload media");
		upload.disabled = this.readOnly;
		if (state.library.query?.mediaTypes !== void 0) upload.accept = state.library.query.mediaTypes.join(",");
		upload.addEventListener("change", () => {
			const file = upload.files?.[0];
			if (file !== void 0) this.#run(() => this.#controller.upload(file));
		});
		browser.append(upload);
		const status = document.createElement("p");
		status.setAttribute("role", state.status === "error" ? "alert" : "status");
		status.setAttribute("aria-live", "polite");
		status.textContent = this.#statusText(state);
		browser.append(status);
		if (state.upload?.state === "failed") browser.append(button("Retry media upload", () => {
			this.#run(() => this.#controller.retryUpload());
		}, this.readOnly));
		if (state.upload !== void 0 && ![
			"cancelled",
			"complete",
			"failed"
		].includes(state.upload.state)) browser.append(button("Cancel media upload", () => this.#controller.cancelUpload()));
		const usage = document.createElement("section");
		usage.setAttribute("aria-label", "Selected media usage");
		if (state.value === void 0) usage.append(document.createTextNode("No media selected."));
		else this.#renderUsage(usage, state);
		this.#holder.replaceChildren(browser, usage);
	}
	#renderUsage(holder, state) {
		const value = state.value;
		if (value === void 0) return;
		const selected = document.createElement("p");
		selected.textContent = state.status === "orphaned" ? `Missing media ${value.assetId}. Select a replacement.` : `Selected media ${state.asset?.filename ?? value.assetId}.`;
		holder.append(selected);
		const decorative = document.createElement("input");
		decorative.type = "checkbox";
		decorative.checked = value.accessibility.mode === "decorative";
		decorative.disabled = this.readOnly;
		decorative.setAttribute("aria-label", "Media is decorative");
		decorative.addEventListener("change", () => this.#controller.setDecorative(decorative.checked));
		holder.append(decorative);
		const accessibility = value.accessibility;
		const informative = accessibility.mode === "informative";
		const alt = input("Media alternative text", accessibility.mode === "informative" ? accessibility.altText : "", this.readOnly || !informative);
		alt.maxLength = 5e3;
		alt.addEventListener("input", () => this.#controller.setAltText(alt.value));
		const caption = input("Media caption", accessibility.mode === "informative" ? accessibility.caption ?? "" : "", this.readOnly || !informative);
		caption.maxLength = 2e4;
		caption.addEventListener("input", () => this.#controller.setCaption(caption.value));
		holder.append(alt, caption);
		const focalX = numberInput$1("Media focal point x", value.focalPoint?.x ?? .5, this.readOnly);
		const focalY = numberInput$1("Media focal point y", value.focalPoint?.y ?? .5, this.readOnly);
		const setFocal = () => {
			this.#controller.setFocalPoint({
				x: focalX.valueAsNumber,
				y: focalY.valueAsNumber
			});
		};
		focalX.addEventListener("change", setFocal);
		focalY.addEventListener("change", setFocal);
		holder.append(focalX, focalY);
		const role = input("Media rendition role", value.renditionIntent?.role ?? "content", this.readOnly);
		role.maxLength = 64;
		const fit = select("Media rendition fit", [
			"contain",
			"cover",
			"fill",
			"scale-down"
		], value.renditionIntent?.fit ?? "cover", this.readOnly);
		const setRendition = () => {
			this.#controller.setRenditionIntent({
				fit: fit.value,
				role: role.value.trim() || "content"
			});
		};
		role.addEventListener("change", setRendition);
		fit.addEventListener("change", setRendition);
		holder.append(role, fit);
		holder.append(button("Replace media", () => this.focus(), this.readOnly), button("Clear media", () => this.#controller.clear(), this.readOnly));
	}
	async #run(action) {
		try {
			this.#error = void 0;
			await action();
		} catch (error) {
			this.#error = error instanceof Error ? error.message : "Media operation failed.";
			this.#render();
		}
	}
	#statusText(state) {
		if (this.#error !== void 0) return this.#error;
		if (state.upload !== void 0 && state.status === "uploading") {
			const { totalBytes, transferredBytes } = state.upload.progress;
			return `Uploading media: ${transferredBytes} of ${totalBytes} bytes.`;
		}
		switch (state.status) {
			case "browsing": return "Browse, search, paste, drop, or upload media.";
			case "empty": return "No media selected.";
			case "error": return "The media operation needs attention.";
			case "orphaned": return "The stored media reference is missing. Select a replacement.";
			case "ready": return "Media is ready.";
			case "uploading": return "Media is processing.";
		}
	}
};
var StudioMediaCollectionControl = class {
	#holder;
	#list;
	#onChange;
	#picker;
	readOnly;
	#lastValid;
	constructor(options, services) {
		this.#holder = group(options.holder, "Media collection editor");
		this.#list = document.createElement("ol");
		this.#list.setAttribute("aria-label", "Selected media order");
		this.#onChange = options.onChange;
		this.#lastValid = referenceCollection(options.value);
		this.readOnly = readOnly(options);
		const pickerHolder = document.createElement("div");
		this.#picker = new StudioMediaReferenceControl({
			...options.binding === void 0 ? {} : { binding: options.binding },
			holder: pickerHolder,
			...options.mediaTypes === void 0 ? {} : { mediaTypes: options.mediaTypes },
			onChange: (change) => {
				if (change.valid && change.value !== void 0) this.#append(canonicalReference(change.value));
			},
			readOnly: this.readOnly,
			usage: options.usage ?? "studio.media/collection",
			value: void 0
		}, services);
		this.#holder.append(pickerHolder, this.#list);
		this.#render();
	}
	destroy() {
		this.#picker.destroy();
		this.#holder.remove();
	}
	focus() {
		this.#picker.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#append(value) {
		if (this.readOnly || this.#lastValid.length >= 100) return;
		if (this.#lastValid.some((item) => item.assetId === value.assetId)) return;
		this.#commit([...this.#lastValid, canonicalReference(value)]);
	}
	#commit(value) {
		try {
			this.#lastValid = referenceCollection(value);
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
			this.#render();
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
		}
	}
	#move(index, delta) {
		const target = index + delta;
		if (this.readOnly || target < 0 || target >= this.#lastValid.length) return;
		const next = [...this.#lastValid];
		const current = next[index];
		const other = next[target];
		if (current === void 0 || other === void 0) return;
		next[index] = other;
		next[target] = current;
		this.#commit(next);
	}
	#render() {
		this.#list.replaceChildren();
		for (const [index, reference] of this.#lastValid.entries()) {
			const item = document.createElement("li");
			const label = document.createElement("p");
			label.textContent = `${index + 1}. ${reference.assetId}`;
			item.append(label);
			const decorative = document.createElement("input");
			decorative.type = "checkbox";
			decorative.checked = reference.accessibility.mode === "decorative";
			decorative.disabled = this.readOnly;
			decorative.setAttribute("aria-label", `Media ${index + 1} is decorative`);
			decorative.addEventListener("change", () => {
				const next = structuredClone(this.#lastValid);
				const selected = next[index];
				if (selected === void 0) return;
				selected.accessibility = decorative.checked ? { mode: "decorative" } : {
					altText: selected.assetId,
					mode: "informative"
				};
				this.#commit(next);
			});
			item.append(decorative);
			const alt = input(`Media ${index + 1} alternative text`, reference.accessibility.mode === "informative" ? reference.accessibility.altText : "", this.readOnly || reference.accessibility.mode !== "informative");
			alt.addEventListener("change", () => {
				const next = structuredClone(this.#lastValid);
				const selected = next[index];
				if (selected?.accessibility.mode !== "informative") return;
				selected.accessibility.altText = alt.value;
				this.#commit(next);
			});
			item.append(alt);
			item.append(button(`Move media ${index + 1} up`, () => this.#move(index, -1), this.readOnly || index === 0), button(`Move media ${index + 1} down`, () => this.#move(index, 1), this.readOnly || index === this.#lastValid.length - 1), button(`Remove media ${index + 1}`, () => this.#commit(this.#lastValid.filter((_, itemIndex) => itemIndex !== index)), this.readOnly));
			this.#list.append(item);
		}
	}
};
function optionalReference(value) {
	if (value === void 0 || value === null) return void 0;
	return canonicalReference(value);
}
function referenceCollection(value) {
	if (!Array.isArray(value) || value.length > 100) throw new RangeError("Media collection must contain at most 100 references.");
	return value.map(canonicalReference);
}
function canonicalReference(value) {
	const record = exactObject(value, [
		"accessibility",
		"assetId",
		"assetRevision",
		"contractVersion",
		"cropIntent",
		"focalPoint",
		"kind",
		"renditionIntent",
		"usage"
	], "Media reference");
	if (record.contractVersion !== "0.1-draft" || record.kind !== "media-reference") throw new TypeError("Media reference has an unsupported contract or kind.");
	const assetId = boundedName(record.assetId, /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u, 240, "asset id");
	const usage = boundedName(record.usage, /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u, 160, "usage");
	const candidate = {
		accessibility: parseAccessibility(record.accessibility),
		assetId,
		contractVersion: "0.1-draft",
		kind: "media-reference",
		usage
	};
	if (record.assetRevision !== void 0) candidate.assetRevision = boundedName(record.assetRevision, /^.{1,200}$/u, 200, "revision");
	if (record.focalPoint !== void 0) candidate.focalPoint = parseFocalPoint(record.focalPoint);
	if (record.cropIntent !== void 0) candidate.cropIntent = parseCropIntent(record.cropIntent);
	if (record.renditionIntent !== void 0) candidate.renditionIntent = parseRenditionIntent(record.renditionIntent);
	if (validateMediaReference(candidate).some((diagnostic) => diagnostic.severity === "error")) throw new TypeError("Media value must be a canonical Studio media reference.");
	return candidate;
}
function parseAccessibility(value) {
	const record = exactObject(value, [
		"altFieldPath",
		"altText",
		"caption",
		"captionFieldPath",
		"mode"
	], "Media accessibility");
	switch (record.mode) {
		case "decorative":
			exactKeys(record, ["mode"], "Decorative media accessibility");
			return { mode: "decorative" };
		case "informative": {
			exactKeys(record, [
				"altText",
				"caption",
				"mode"
			], "Informative media accessibility");
			const altText = boundedText(record.altText, 1, 5e3, "Media alternative text");
			const caption = record.caption === void 0 ? void 0 : boundedText(record.caption, 0, 2e4, "Media caption");
			return {
				altText,
				...caption === void 0 ? {} : { caption },
				mode: "informative"
			};
		}
		case "bound": {
			exactKeys(record, [
				"altFieldPath",
				"captionFieldPath",
				"mode"
			], "Bound media accessibility");
			const altFieldPath = localPath(record.altFieldPath, "Alternative-text field path");
			const captionFieldPath = record.captionFieldPath === void 0 ? void 0 : localPath(record.captionFieldPath, "Caption field path");
			return {
				altFieldPath,
				...captionFieldPath === void 0 ? {} : { captionFieldPath },
				mode: "bound"
			};
		}
		default: throw new TypeError("Media accessibility mode is invalid.");
	}
}
function parseFocalPoint(value) {
	const record = exactObject(value, ["x", "y"], "Media focal point");
	return {
		x: unit(record.x, "Focal x"),
		y: unit(record.y, "Focal y")
	};
}
function parseCropIntent(value) {
	const record = exactObject(value, [
		"height",
		"mode",
		"width",
		"x",
		"y"
	], "Media crop intent");
	if (record.mode === "aspect-ratio") {
		exactKeys(record, [
			"height",
			"mode",
			"width"
		], "Aspect-ratio crop");
		return {
			height: boundedInteger(record.height, 1, 1e4, "Crop height"),
			mode: "aspect-ratio",
			width: boundedInteger(record.width, 1, 1e4, "Crop width")
		};
	}
	if (record.mode === "rectangle") {
		exactKeys(record, [
			"height",
			"mode",
			"width",
			"x",
			"y"
		], "Rectangle crop");
		const width = unit(record.width, "Crop width");
		const height = unit(record.height, "Crop height");
		if (width === 0 || height === 0) throw new RangeError("Crop dimensions must be positive.");
		return {
			height,
			mode: "rectangle",
			width,
			x: unit(record.x, "Crop x"),
			y: unit(record.y, "Crop y")
		};
	}
	throw new TypeError("Media crop mode is invalid.");
}
function parseRenditionIntent(value) {
	const record = exactObject(value, [
		"fit",
		"preferredMediaTypes",
		"role"
	], "Media rendition intent");
	const role = boundedName(record.role, /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u, 100, "rendition role");
	const fit = record.fit;
	if (fit !== void 0 && fit !== "contain" && fit !== "cover" && fit !== "fill" && fit !== "scale-down") throw new TypeError("Media rendition fit is invalid.");
	let preferredMediaTypes;
	if (record.preferredMediaTypes !== void 0) {
		if (!Array.isArray(record.preferredMediaTypes) || record.preferredMediaTypes.length > 10) throw new RangeError("Preferred media types exceed their item limit.");
		preferredMediaTypes = record.preferredMediaTypes.map((item) => boundedName(item, /^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/u, 200, "preferred media type"));
		if (new Set(preferredMediaTypes).size !== preferredMediaTypes.length) throw new TypeError("Preferred media types must be unique.");
	}
	return {
		...fit === void 0 ? {} : { fit },
		...preferredMediaTypes === void 0 ? {} : { preferredMediaTypes },
		role
	};
}
function localPath(value, name) {
	if (!Array.isArray(value) || value.length < 1 || value.length > 32) throw new RangeError(`${name} must have 1 through 32 segments.`);
	return value.map((segment) => boundedName(segment, /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u, 100, `${name} segment`));
}
function exactObject(value, keys, name) {
	if (typeof value !== "object" || value === null || Array.isArray(value) || Object.getPrototypeOf(value) !== Object.prototype) throw new TypeError(`${name} must be a plain JSON object.`);
	const record = value;
	exactKeys(record, keys, name);
	return record;
}
function exactKeys(record, keys, name) {
	const allowed = new Set(keys);
	const unknown = Object.keys(record).find((key) => !allowed.has(key));
	if (unknown !== void 0) throw new TypeError(`${name} contains unknown member ${unknown}.`);
}
function boundedName(value, pattern, maximum, name) {
	if (typeof value !== "string" || value.length > maximum || [
		"__proto__",
		"constructor",
		"prototype"
	].includes(value) || !pattern.test(value)) throw new TypeError(`Media ${name} is invalid.`);
	return value;
}
function boundedText(value, minimum, maximum, name) {
	if (typeof value !== "string" || value.length < minimum || value.length > maximum) throw new RangeError(`${name} must contain ${minimum} through ${maximum} characters.`);
	return value;
}
function boundedInteger(value, minimum, maximum, name) {
	if (typeof value !== "number" || !Number.isInteger(value) || value < minimum || value > maximum) throw new RangeError(`${name} must be an integer from ${minimum} through ${maximum}.`);
	return value;
}
function unit(value, name) {
	if (typeof value !== "number" || !Number.isFinite(value) || value < 0 || value > 1) throw new RangeError(`${name} must be a finite number from 0 through 1.`);
	return value;
}
function readOnly(options) {
	return options.readOnly === true || options.binding !== void 0 && options.binding.source.kind !== "static-value";
}
function group(holder, label) {
	const element = document.createElement("section");
	element.className = "studio-authoring-control studio-media-control";
	element.setAttribute("aria-label", label);
	holder.append(element);
	return element;
}
function input(label, value, disabled) {
	const element = document.createElement("input");
	element.type = "text";
	element.value = value;
	element.disabled = disabled;
	element.setAttribute("aria-label", label);
	return element;
}
function numberInput$1(label, value, disabled) {
	const element = document.createElement("input");
	element.type = "number";
	element.min = "0";
	element.max = "1";
	element.step = "0.01";
	element.value = String(value);
	element.disabled = disabled;
	element.setAttribute("aria-label", label);
	return element;
}
function select(label, values, selected, disabled) {
	const element = document.createElement("select");
	element.disabled = disabled;
	element.setAttribute("aria-label", label);
	for (const value of values) {
		const option = document.createElement("option");
		option.value = value;
		option.textContent = value;
		option.selected = value === selected;
		element.append(option);
	}
	return element;
}
function button(label, action, disabled = false) {
	const element = document.createElement("button");
	element.type = "button";
	element.textContent = label;
	element.disabled = disabled;
	element.setAttribute("aria-label", label);
	element.addEventListener("click", action);
	return element;
}
function mediaAssetLabel(asset) {
	return `${asset.filename} (${asset.mediaKind})`;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/authoring-controls.js
var STUDIO_AUTHORING_CONTROL_IDS = Object.freeze({
	chart: "studio.control/chart",
	drawing: "studio.control/drawing",
	mediaCollection: "studio.control/media-collection",
	mediaReference: "studio.control/media-reference",
	money: "studio.control/money",
	richText: "studio.control/rich-text",
	scopedCss: "studio.control/scoped-css",
	source: "studio.control/source",
	table: "studio.control/table"
});
/**
* Studio-owned registry for first-party page controls. Hosts see stable
* control identifiers and canonical values, never Editor.js/CodeMirror/chart
* library configuration.
*/
var StudioAuthoringControlRegistry = class {
	#codeField;
	#media;
	#richTextFactory;
	#sourcePreview;
	constructor(services = {}) {
		this.#codeField = services.codeField ?? new TextareaCodeFieldAdapter();
		this.#media = services.media;
		this.#richTextFactory = services.richTextFactory ?? new StudioRichTextEditorFactory(services.strictContentSecurityPolicy === true ? new StudioStrictCspRichTextSurfaceAdapter() : void 0);
		this.#sourcePreview = services.sourcePreview;
	}
	async mount(control, options) {
		switch (control) {
			case "studio.control/rich-text": return this.#mountRichText(options);
			case "studio.control/source": return new StudioSourceControl(options, this.#codeField, this.#sourcePreview);
			case "studio.control/chart": return new StudioChartControl(options);
			case "studio.control/drawing": return new StudioDrawingControl(options);
			case "studio.control/media-reference": return mountStudioMediaReferenceControl(options, this.#requireMedia());
			case "studio.control/media-collection": return mountStudioMediaCollectionControl(options, this.#requireMedia());
			case "studio.control/money": return new StudioMoneyControl(options);
			case "studio.control/scoped-css": return new StudioScopedCssControl(options);
			case "studio.control/table": return new StudioTableControl(options);
			default: throw new Error(`Unknown Studio authoring control ${String(control)}.`);
		}
	}
	async #mountRichText(options) {
		if (!isRecord$1(options.value) || options.value.type !== "doc") throw new TypeError("Rich-text control requires a canonical Studio document.");
		const profile = parseRichTextProfile(options.profile);
		let value = structuredClone(options.value);
		const editor = await this.#richTextFactory.create({
			...options.binding === void 0 ? {} : { binding: options.binding },
			holder: options.holder,
			onChange: (change) => {
				value = change.value;
				options.onChange?.({
					valid: change.valid,
					value: change.value
				});
			},
			...profile === void 0 ? {} : { profile },
			readOnly: isReadOnly(options),
			value: options.value
		});
		value = await editor.save();
		return {
			destroy: () => editor.destroy(),
			focus: () => editor.focus(),
			readOnly: editor.readOnly,
			value: () => structuredClone(value)
		};
	}
	#requireMedia() {
		if (this.#media === void 0) throw new Error("Studio media controls require host-injected media services.");
		return this.#media;
	}
};
var TextareaCodeFieldAdapter = class {
	mount(options) {
		const textarea = document.createElement("textarea");
		textarea.className = "studio-source-editor";
		textarea.setAttribute("aria-label", `${options.language} source`);
		textarea.disabled = options.readOnly;
		textarea.rows = 12;
		textarea.spellcheck = false;
		textarea.value = options.source;
		textarea.addEventListener("input", () => options.onChange(textarea.value));
		options.holder.append(textarea);
		return {
			destroy: () => textarea.remove(),
			focus: () => textarea.focus(),
			source: () => textarea.value
		};
	}
};
var StudioSourceControl = class {
	#code;
	#language;
	#onChange;
	#preview;
	#previewRegion;
	readOnly;
	#abort;
	#lastValid;
	constructor(options, codeAdapter, preview) {
		this.readOnly = isReadOnly(options);
		this.#onChange = options.onChange;
		this.#preview = preview;
		this.#lastValid = parseSourceText(options.value);
		this.#language = parseSourceProfile(options.profile);
		const group = controlGroup(options.holder, "Source editor");
		const codeHolder = document.createElement("div");
		const previewButton = actionButton$1("Preview source", () => void this.#renderPreview());
		previewButton.disabled = preview === void 0;
		this.#previewRegion = document.createElement("div");
		this.#previewRegion.setAttribute("aria-live", "polite");
		this.#previewRegion.setAttribute("aria-label", "Trusted source preview");
		group.append(codeHolder, previewButton, this.#previewRegion);
		this.#code = codeAdapter.mount({
			holder: codeHolder,
			language: this.#language,
			onChange: (source) => this.#change(source),
			readOnly: this.readOnly,
			source: this.#lastValid
		});
	}
	destroy() {
		this.#abort?.abort();
		this.#code.destroy();
	}
	focus() {
		this.#code.focus();
	}
	value() {
		return this.#lastValid;
	}
	#change(source) {
		if (this.readOnly) return;
		try {
			this.#lastValid = parseSourceText(source);
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
		}
	}
	async #renderPreview() {
		if (this.#preview === void 0) return;
		this.#abort?.abort();
		const controller = new AbortController();
		this.#abort = controller;
		this.#previewRegion.replaceChildren(document.createTextNode("Rendering preview…"));
		try {
			const node = await this.#preview.render({
				language: this.#language,
				source: this.value()
			}, controller.signal);
			if (!controller.signal.aborted) this.#previewRegion.replaceChildren(node);
		} catch {
			if (!controller.signal.aborted) this.#previewRegion.replaceChildren(document.createTextNode("Preview is unavailable."));
		}
	}
};
var StudioChartControl = class {
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	#working;
	constructor(options) {
		this.readOnly = isReadOnly(options);
		this.#holder = controlGroup(options.holder, "Chart editor");
		this.#onChange = options.onChange;
		this.#lastValid = parseStudioChartSpec(options.value);
		this.#working = structuredClone(this.#lastValid);
		this.#render();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector("input,select,button")?.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (this.readOnly) return;
		try {
			this.#lastValid = parseStudioChartSpec(this.#working);
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
		}
	}
	#render() {
		this.#holder.replaceChildren();
		const type = selectInput("Chart type", [
			"bar",
			"line",
			"pie",
			"doughnut"
		], this.#working.type, this.readOnly);
		type.addEventListener("change", () => {
			this.#working.type = type.value;
			this.#commit();
		});
		const title = textInput("Chart title", this.#working.title ?? "", this.readOnly);
		title.maxLength = 500;
		title.addEventListener("input", () => {
			const value = title.value;
			if (value.length === 0) delete this.#working.title;
			else this.#working.title = value;
			this.#commit();
		});
		this.#holder.append(type, title);
		const table = document.createElement("table");
		table.setAttribute("aria-label", "Chart data");
		const header = document.createElement("tr");
		header.append(tableHeader("Label"));
		for (const [datasetIndex, dataset] of this.#working.datasets.entries()) {
			const cell = tableHeader(`Dataset ${datasetIndex + 1}`);
			const label = textInput(`Dataset ${datasetIndex + 1} label`, dataset.label, this.readOnly);
			label.addEventListener("input", () => {
				dataset.label = label.value.slice(0, 500);
				this.#commit();
			});
			cell.replaceChildren(label);
			header.append(cell);
		}
		table.append(header);
		for (const [rowIndex, labelValue] of this.#working.labels.entries()) {
			const row = document.createElement("tr");
			const labelCell = document.createElement("td");
			const label = textInput(`Chart label ${rowIndex + 1}`, labelValue, this.readOnly);
			label.addEventListener("input", () => {
				this.#working.labels[rowIndex] = label.value.slice(0, 500);
				this.#commit();
			});
			labelCell.append(label);
			row.append(labelCell);
			for (const [datasetIndex, dataset] of this.#working.datasets.entries()) {
				const cell = document.createElement("td");
				const input = textInput(`Value for label ${rowIndex + 1}, dataset ${datasetIndex + 1}`, String(dataset.values[rowIndex] ?? 0), this.readOnly);
				input.inputMode = "decimal";
				input.addEventListener("input", () => {
					const value = Number(input.value);
					if (!Number.isFinite(value)) {
						this.#onChange?.({
							valid: false,
							value: this.value()
						});
						return;
					}
					dataset.values[rowIndex] = value;
					this.#commit();
				});
				cell.append(input);
				row.append(cell);
			}
			table.append(row);
		}
		this.#holder.append(table);
		if (!this.readOnly) this.#holder.append(actionButton$1("Add chart row", () => this.#addRow(), this.#working.labels.length >= 200), actionButton$1("Remove chart row", () => this.#removeRow(), this.#working.labels.length <= 1), actionButton$1("Add chart dataset", () => this.#addDataset(), this.#working.datasets.length >= 20), actionButton$1("Remove chart dataset", () => this.#removeDataset(), this.#working.datasets.length <= 1));
	}
	#addRow() {
		if (this.#working.labels.length >= 200) return;
		this.#working.labels.push(`Label ${this.#working.labels.length + 1}`);
		for (const dataset of this.#working.datasets) dataset.values.push(0);
		this.#commit();
		this.#render();
	}
	#removeRow() {
		if (this.#working.labels.length <= 1) return;
		this.#working.labels.pop();
		for (const dataset of this.#working.datasets) dataset.values.pop();
		this.#commit();
		this.#render();
	}
	#addDataset() {
		if (this.#working.datasets.length >= 20) return;
		this.#working.datasets.push({
			label: `Dataset ${this.#working.datasets.length + 1}`,
			values: this.#working.labels.map(() => 0)
		});
		this.#commit();
		this.#render();
	}
	#removeDataset() {
		if (this.#working.datasets.length <= 1) return;
		this.#working.datasets.pop();
		this.#commit();
		this.#render();
	}
};
var SVG_NAMESPACE = "http://www.w3.org/2000/svg";
/**
* Native, dependency-free vector authoring over Studio's bounded drawing value.
* The SVG is a view only: only detached points, color tokens, and widths cross
* the control boundary.
*/
var StudioDrawingControl = class {
	#alt;
	#color;
	#commitStroke;
	#height;
	#holder;
	#onChange;
	#pointX;
	#pointY;
	#status;
	#strokeWidth;
	#svg;
	#width;
	readOnly;
	#activePointerId;
	#lastValid;
	#pendingPoints = [];
	#working;
	constructor(options) {
		parseCanonicalControlProfile(options.profile, "studio.drawing/canonical", "drawing");
		this.readOnly = isReadOnly(options);
		this.#lastValid = parseStudioDrawingDocument(options.value);
		this.#working = structuredClone(this.#lastValid);
		this.#onChange = options.onChange;
		this.#holder = controlGroup(options.holder, "Drawing editor");
		const help = document.createElement("p");
		help.textContent = this.readOnly ? "Drawing is read-only." : "Draw with a pointer, or enter a point and use Add point. Arrow keys move the point; Space adds it and Enter commits the stroke.";
		this.#alt = document.createElement("textarea");
		this.#alt.setAttribute("aria-label", "Drawing alternative text");
		this.#alt.disabled = this.readOnly;
		this.#alt.maxLength = 5e3;
		this.#alt.rows = 3;
		this.#alt.value = this.#lastValid.alt;
		this.#alt.addEventListener("input", () => {
			this.#working.alt = this.#alt.value;
			this.#commitWorking();
		});
		this.#width = numberInput("Drawing width", this.#lastValid.width, this.readOnly, 1, 4096, 1);
		this.#height = numberInput("Drawing height", this.#lastValid.height, this.readOnly, 1, 4096, 1);
		this.#width.addEventListener("input", () => this.#changeDimensions());
		this.#height.addEventListener("input", () => this.#changeDimensions());
		this.#color = textInput("Drawing color token", "#000000", this.readOnly);
		this.#color.maxLength = 127;
		this.#color.spellcheck = false;
		this.#color.addEventListener("input", () => this.#validateStrokeSettings());
		this.#strokeWidth = numberInput("Drawing stroke width", 2, this.readOnly, .25, 64, .25);
		this.#strokeWidth.addEventListener("input", () => this.#validateStrokeSettings());
		this.#svg = document.createElementNS(SVG_NAMESPACE, "svg");
		this.#svg.classList.add("studio-drawing-canvas");
		this.#svg.setAttribute("role", "img");
		this.#svg.setAttribute("aria-label", this.#lastValid.alt);
		this.#svg.setAttribute("aria-description", "Arrow keys move the drawing point. Space adds a point. Enter commits and Escape discards the current stroke.");
		this.#svg.setAttribute("aria-keyshortcuts", "ArrowUp ArrowDown ArrowLeft ArrowRight Space Enter Escape");
		this.#svg.setAttribute("preserveAspectRatio", "xMidYMid meet");
		this.#svg.tabIndex = this.readOnly ? -1 : 0;
		this.#svg.addEventListener("pointerdown", (event) => this.#beginPointerStroke(event));
		this.#svg.addEventListener("pointermove", (event) => this.#continuePointerStroke(event));
		this.#svg.addEventListener("pointerup", (event) => this.#finishPointerStroke(event));
		this.#svg.addEventListener("pointercancel", (event) => this.#cancelPointerStroke(event));
		this.#svg.addEventListener("keydown", (event) => this.#handleCanvasKey(event));
		this.#pointX = numberInput("Drawing point x", 0, this.readOnly, 0, this.#lastValid.width, 1);
		this.#pointY = numberInput("Drawing point y", 0, this.readOnly, 0, this.#lastValid.height, 1);
		const addPoint = actionButton$1("Add drawing point", () => this.#addKeyboardPoint());
		this.#commitStroke = actionButton$1("Commit drawing stroke", () => this.#completeStroke(), true);
		const discardStroke = actionButton$1("Discard current drawing stroke", () => {
			this.#pendingPoints = [];
			this.#renderDrawing();
		});
		const removeStroke = actionButton$1("Remove last drawing stroke", () => this.#removeLastStroke(), this.#lastValid.strokes.length === 0);
		for (const button of [
			addPoint,
			this.#commitStroke,
			discardStroke,
			removeStroke
		]) button.hidden = this.readOnly;
		this.#status = document.createElement("p");
		this.#status.setAttribute("aria-live", "polite");
		this.#status.className = "studio-authoring-status";
		this.#holder.append(help, this.#alt, this.#width, this.#height, this.#color, this.#strokeWidth, this.#svg, this.#pointX, this.#pointY, addPoint, this.#commitStroke, discardStroke, removeStroke, this.#status);
		this.#renderDrawing();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#svg.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#addKeyboardPoint() {
		if (this.readOnly) return;
		const point = {
			x: Number(this.#pointX.value),
			y: Number(this.#pointY.value)
		};
		if (!this.#validPoint(point)) {
			this.#invalid();
			return;
		}
		this.#appendPoint(point);
		this.#renderDrawing();
	}
	#appendPoint(point) {
		if (this.#pendingPoints.length >= 1e4) {
			this.#status.textContent = "A drawing stroke can contain at most 10000 points.";
			return;
		}
		const previous = this.#pendingPoints.at(-1);
		if (previous?.x === point.x && previous.y === point.y) return;
		this.#pendingPoints.push(point);
	}
	#beginPointerStroke(event) {
		if (this.readOnly || event.button !== 0) return;
		event.preventDefault();
		this.#activePointerId = event.pointerId;
		this.#pendingPoints = [this.#pointFromPointer(event)];
		try {
			this.#svg.setPointerCapture(event.pointerId);
		} catch {}
		this.#renderDrawing();
	}
	#cancelPointerStroke(event) {
		if (event.pointerId !== this.#activePointerId) return;
		this.#activePointerId = void 0;
		this.#pendingPoints = [];
		this.#renderDrawing();
	}
	#changeDimensions() {
		if (this.readOnly) return;
		this.#working.width = Number(this.#width.value);
		this.#working.height = Number(this.#height.value);
		if (this.#commitWorking()) {
			this.#pointX.max = String(this.#lastValid.width);
			this.#pointY.max = String(this.#lastValid.height);
			this.#pointX.value = String(clamp(Number(this.#pointX.value), 0, this.#lastValid.width));
			this.#pointY.value = String(clamp(Number(this.#pointY.value), 0, this.#lastValid.height));
			this.#renderDrawing();
		}
	}
	#commitWorking() {
		if (this.readOnly) return false;
		try {
			this.#lastValid = parseStudioDrawingDocument(this.#working);
			this.#working = structuredClone(this.#lastValid);
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
			this.#svg.setAttribute("aria-label", this.#lastValid.alt);
			return true;
		} catch {
			this.#invalid();
			return false;
		}
	}
	#completeStroke() {
		if (this.readOnly || this.#pendingPoints.length === 0) return;
		try {
			const stroke = this.#parseStroke(this.#pendingPoints);
			this.#working.strokes = [...this.#lastValid.strokes, stroke];
			if (!this.#commitWorking()) return;
			this.#pendingPoints = [];
			this.#renderDrawing();
		} catch {
			this.#invalid();
		}
	}
	#continuePointerStroke(event) {
		if (event.pointerId !== this.#activePointerId) return;
		event.preventDefault();
		this.#appendPoint(this.#pointFromPointer(event));
		this.#renderDrawing();
	}
	#finishPointerStroke(event) {
		if (event.pointerId !== this.#activePointerId) return;
		event.preventDefault();
		this.#appendPoint(this.#pointFromPointer(event));
		this.#activePointerId = void 0;
		this.#completeStroke();
	}
	#handleCanvasKey(event) {
		if (this.readOnly) return;
		const step = event.shiftKey ? 10 : 1;
		let x = Number(this.#pointX.value);
		let y = Number(this.#pointY.value);
		switch (event.key) {
			case "ArrowLeft":
				x -= step;
				break;
			case "ArrowRight":
				x += step;
				break;
			case "ArrowUp":
				y -= step;
				break;
			case "ArrowDown":
				y += step;
				break;
			case " ":
				event.preventDefault();
				this.#addKeyboardPoint();
				return;
			case "Enter":
				event.preventDefault();
				this.#completeStroke();
				return;
			case "Escape":
				event.preventDefault();
				this.#pendingPoints = [];
				this.#renderDrawing();
				return;
			default: return;
		}
		event.preventDefault();
		this.#pointX.value = String(clamp(x, 0, this.#lastValid.width));
		this.#pointY.value = String(clamp(y, 0, this.#lastValid.height));
	}
	#invalid() {
		this.#onChange?.({
			valid: false,
			value: this.value()
		});
	}
	#parseStroke(points) {
		const candidate = parseStudioDrawingDocument({
			alt: this.#lastValid.alt,
			height: this.#lastValid.height,
			strokes: [{
				color: this.#color.value,
				points: structuredClone(points),
				width: Number(this.#strokeWidth.value)
			}],
			width: this.#lastValid.width
		}).strokes[0];
		if (candidate === void 0) throw new TypeError("Drawing stroke is unavailable.");
		return candidate;
	}
	#pointFromPointer(event) {
		const bounds = this.#svg.getBoundingClientRect();
		const x = bounds.width > 0 ? (event.clientX - bounds.left) / bounds.width * this.#lastValid.width : event.offsetX;
		const y = bounds.height > 0 ? (event.clientY - bounds.top) / bounds.height * this.#lastValid.height : event.offsetY;
		return {
			x: clamp(Number.isFinite(x) ? x : 0, 0, this.#lastValid.width),
			y: clamp(Number.isFinite(y) ? y : 0, 0, this.#lastValid.height)
		};
	}
	#removeLastStroke() {
		if (this.readOnly || this.#lastValid.strokes.length === 0) return;
		this.#working = structuredClone(this.#lastValid);
		this.#working.strokes.pop();
		if (this.#commitWorking()) this.#renderDrawing();
	}
	#renderDrawing() {
		this.#svg.setAttribute("viewBox", `0 0 ${String(this.#lastValid.width)} ${String(this.#lastValid.height)}`);
		this.#svg.replaceChildren();
		for (const stroke of this.#lastValid.strokes) this.#svg.append(this.#strokeElement(stroke));
		if (this.#pendingPoints.length > 0) try {
			this.#svg.append(this.#strokeElement(this.#parseStroke(this.#pendingPoints)));
		} catch {}
		this.#commitStroke.disabled = this.#pendingPoints.length === 0;
		this.#status.textContent = `${String(this.#lastValid.strokes.length)} committed strokes; ${String(this.#pendingPoints.length)} points in the current stroke.`;
		const remove = this.#holder.querySelector("[aria-label=\"Remove last drawing stroke\"]");
		if (remove !== null) remove.disabled = this.#lastValid.strokes.length === 0;
	}
	#strokeElement(stroke) {
		const polyline = document.createElementNS(SVG_NAMESPACE, "polyline");
		polyline.setAttribute("fill", "none");
		polyline.setAttribute("points", stroke.points.map((point) => `${point.x},${point.y}`).join(" "));
		polyline.setAttribute("stroke", stroke.color.startsWith("#") ? stroke.color : "currentColor");
		polyline.setAttribute("stroke-linecap", "round");
		polyline.setAttribute("stroke-linejoin", "round");
		polyline.setAttribute("stroke-width", String(stroke.width));
		return polyline;
	}
	#validPoint(point) {
		try {
			this.#parseStroke([point]);
			return true;
		} catch {
			return false;
		}
	}
	#validateStrokeSettings() {
		if (this.readOnly) return;
		try {
			this.#parseStroke(this.#pendingPoints.length === 0 ? [{
				x: 0,
				y: 0
			}] : this.#pendingPoints);
		} catch {
			this.#invalid();
		}
		this.#renderDrawing();
	}
};
/** Text-only canonical table editor; DOM table markup is never the value. */
var StudioTableControl = class {
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	#working;
	constructor(options) {
		parseCanonicalControlProfile(options.profile, "studio.table/canonical", "table");
		this.readOnly = isReadOnly(options);
		this.#lastValid = parseStudioTableDocument(options.value);
		this.#working = structuredClone(this.#lastValid);
		this.#onChange = options.onChange;
		this.#holder = controlGroup(options.holder, "Table editor");
		this.#render();
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#holder.querySelector("input,textarea,button")?.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#addColumn() {
		if (this.readOnly || this.#lastValid.columns.length >= 50) return;
		this.#working = structuredClone(this.#lastValid);
		this.#working.columns.push(`Column ${String(this.#working.columns.length + 1)}`);
		for (const row of this.#working.rows) row.push("");
		if (this.#commit()) this.#render();
	}
	#addRow() {
		if (this.readOnly || this.#lastValid.rows.length >= 1e3) return;
		this.#working = structuredClone(this.#lastValid);
		this.#working.rows.push(this.#working.columns.map(() => ""));
		if (this.#commit()) this.#render();
	}
	#commit() {
		if (this.readOnly) return false;
		try {
			this.#lastValid = parseStudioTableDocument(this.#working);
			this.#working = structuredClone(this.#lastValid);
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
			return true;
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
			return false;
		}
	}
	#removeColumn() {
		if (this.readOnly || this.#lastValid.columns.length <= 1) return;
		this.#working = structuredClone(this.#lastValid);
		this.#working.columns.pop();
		for (const row of this.#working.rows) row.pop();
		if (this.#commit()) this.#render();
	}
	#removeRow() {
		if (this.readOnly || this.#lastValid.rows.length === 0) return;
		this.#working = structuredClone(this.#lastValid);
		this.#working.rows.pop();
		if (this.#commit()) this.#render();
	}
	#render() {
		this.#holder.replaceChildren();
		const help = document.createElement("p");
		help.textContent = "Table cells are text. HTML and executable content are not interpreted.";
		const caption = textInput("Table caption", this.#working.caption ?? "", this.readOnly);
		caption.maxLength = 500;
		caption.addEventListener("input", () => {
			if (caption.value.length === 0) delete this.#working.caption;
			else this.#working.caption = caption.value;
			this.#commit();
		});
		this.#holder.append(help, caption);
		const table = document.createElement("table");
		table.setAttribute("aria-label", "Table data");
		const head = document.createElement("thead");
		const headerRow = document.createElement("tr");
		headerRow.append(tableHeader("Row"));
		for (const [columnIndex, columnValue] of this.#working.columns.entries()) {
			const header = tableHeader(`Column ${String(columnIndex + 1)}`);
			const input = textInput(`Table column ${String(columnIndex + 1)} heading`, columnValue, this.readOnly);
			input.maxLength = 500;
			input.addEventListener("input", () => {
				this.#working.columns[columnIndex] = input.value;
				this.#commit();
			});
			header.replaceChildren(input);
			headerRow.append(header);
		}
		head.append(headerRow);
		table.append(head);
		const body = document.createElement("tbody");
		for (const [rowIndex, rowValue] of this.#working.rows.entries()) {
			const row = document.createElement("tr");
			const rowHeader = document.createElement("th");
			rowHeader.scope = "row";
			rowHeader.textContent = String(rowIndex + 1);
			row.append(rowHeader);
			for (const [columnIndex, cellValue] of rowValue.entries()) {
				const cell = document.createElement("td");
				const input = document.createElement("textarea");
				input.setAttribute("aria-label", `Table row ${String(rowIndex + 1)}, column ${String(columnIndex + 1)}`);
				input.disabled = this.readOnly;
				input.maxLength = 5e3;
				input.rows = 2;
				input.value = cellValue;
				input.addEventListener("input", () => {
					const targetRow = this.#working.rows[rowIndex];
					if (targetRow === void 0) return;
					targetRow[columnIndex] = input.value;
					this.#commit();
				});
				cell.append(input);
				row.append(cell);
			}
			body.append(row);
		}
		table.append(body);
		this.#holder.append(table);
		if (!this.readOnly) {
			const actions = document.createElement("div");
			actions.className = "studio-authoring-actions";
			actions.append(actionButton$1("Add table row", () => this.#addRow(), this.#working.rows.length >= 1e3), actionButton$1("Remove last table row", () => this.#removeRow(), this.#working.rows.length === 0), actionButton$1("Add table column", () => this.#addColumn(), this.#working.columns.length >= 50), actionButton$1("Remove last table column", () => this.#removeColumn(), this.#working.columns.length <= 1));
			this.#holder.append(actions);
		}
	}
};
var StudioMoneyControl = class {
	#amount;
	#currency;
	#holder;
	#onChange;
	readOnly;
	#lastValid;
	constructor(options) {
		this.readOnly = isReadOnly(options);
		this.#lastValid = parseStudioMoneyValue(options.value);
		this.#onChange = options.onChange;
		this.#holder = controlGroup(options.holder, "Money editor");
		this.#amount = textInput("Exact decimal amount", this.#lastValid.amount, this.readOnly);
		this.#amount.inputMode = "decimal";
		this.#currency = textInput("Three-letter currency", this.#lastValid.currency, this.readOnly);
		this.#currency.maxLength = 3;
		this.#currency.autocapitalize = "characters";
		this.#amount.addEventListener("input", () => this.#commit());
		this.#currency.addEventListener("input", () => this.#commit());
		this.#holder.append(this.#amount, this.#currency);
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#amount.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (this.readOnly) return;
		try {
			this.#lastValid = parseStudioMoneyValue({
				amount: this.#amount.value,
				currency: this.#currency.value.toUpperCase()
			});
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
		}
	}
};
var StudioScopedCssControl = class {
	#holder;
	#onChange;
	#source;
	readOnly;
	#lastValid;
	constructor(options) {
		this.readOnly = isReadOnly(options);
		this.#lastValid = parseScopedStyleSheet(options.value);
		this.#onChange = options.onChange;
		this.#holder = controlGroup(options.holder, "Scoped style editor");
		const help = document.createElement("p");
		help.textContent = "Use only self, heading, content, media, or action targets and approved properties.";
		this.#source = document.createElement("textarea");
		this.#source.setAttribute("aria-label", "Scoped CSS source");
		this.#source.disabled = this.readOnly;
		this.#source.rows = 10;
		this.#source.value = serializeScopedCss(this.#lastValid);
		this.#source.addEventListener("input", () => this.#commit());
		this.#holder.append(help, this.#source);
	}
	destroy() {
		this.#holder.remove();
	}
	focus() {
		this.#source.focus();
	}
	value() {
		return structuredClone(this.#lastValid);
	}
	#commit() {
		if (this.readOnly) return;
		try {
			const parsed = parseScopedCss(this.#source.value);
			compileStudioScopedStyleSheet("authoring-preview", parsed);
			this.#lastValid = parsed;
			this.#onChange?.({
				valid: true,
				value: this.value()
			});
		} catch {
			this.#onChange?.({
				valid: false,
				value: this.value()
			});
		}
	}
};
function parseScopedCss(source) {
	if (source.length > 1e5) throw new RangeError("Scoped CSS source exceeds 100000 characters.");
	const rules = [];
	const pattern = /\s*(self|heading|content|media|action)\s*\{([^{}]*)\}\s*/guy;
	let cursor = 0;
	while (cursor < source.length) {
		pattern.lastIndex = cursor;
		const match = pattern.exec(source);
		if (match?.index !== cursor) throw new TypeError(`Scoped CSS is invalid near character ${cursor + 1}.`);
		const declarations = Object.create(null);
		for (const declaration of (match[2] ?? "").split(";")) {
			if (declaration.trim().length === 0) continue;
			const colon = declaration.indexOf(":");
			if (colon < 1) throw new TypeError("Scoped CSS declaration requires property: value.");
			const property = declaration.slice(0, colon).trim().toLowerCase();
			const value = declaration.slice(colon + 1).trim();
			if (Object.hasOwn(declarations, property)) throw new TypeError(`Scoped CSS property ${property} is declared twice.`);
			declarations[property] = value;
		}
		rules.push({
			declarations,
			target: match[1]
		});
		cursor = pattern.lastIndex;
	}
	const sheet = { rules };
	compileStudioScopedStyleSheet("authoring-preview", sheet);
	return sheet;
}
function serializeScopedCss(sheet) {
	return sheet.rules.map((rule) => {
		const declarations = Object.entries(rule.declarations).sort(([left], [right]) => left.localeCompare(right)).map(([property, value]) => `  ${property}: ${value};`).join("\n");
		return `${rule.target} {\n${declarations}\n}`;
	}).join("\n\n");
}
function parseScopedStyleSheet(value) {
	if (!isRecord$1(value) || !Array.isArray(value.rules)) throw new TypeError("Scoped styles require a structured rule collection.");
	const sheet = structuredClone(value);
	compileStudioScopedStyleSheet("authoring-preview", sheet);
	return sheet;
}
function parseSourceText(value) {
	if (typeof value !== "string" || value.length > 1e6) throw new RangeError("Source text exceeds its 1000000-character limit.");
	return value;
}
function parseSourceProfile(profile) {
	switch (profile) {
		case "studio.source/code": return "code";
		case "studio.source/latex": return "latex";
		case "studio.source/mermaid": return "mermaid";
		default: throw new TypeError(`Unknown Studio source profile "${String(profile)}".`);
	}
}
function parseCanonicalControlProfile(value, expected, name) {
	if (value !== void 0 && value !== expected) throw new TypeError(`Unknown Studio ${name} profile "${value}".`);
}
function isReadOnly(options) {
	return options.readOnly === true || options.binding !== void 0 && options.binding.source.kind !== "static-value";
}
function parseRichTextProfile(value) {
	if (value === void 0) return void 0;
	if (value === "studio.rich-text/documentation" || value === "studio.rich-text/marketing" || value === "studio.rich-text/portable") return value;
	throw new TypeError(`Unknown Studio rich-text profile "${value}".`);
}
function controlGroup(holder, label) {
	const group = document.createElement("section");
	group.className = "studio-authoring-control";
	group.setAttribute("aria-label", label);
	holder.append(group);
	return group;
}
function textInput(label, value, readOnly) {
	const input = document.createElement("input");
	input.type = "text";
	input.setAttribute("aria-label", label);
	input.disabled = readOnly;
	input.value = value;
	return input;
}
function numberInput(label, value, readOnly, minimum, maximum, step) {
	const input = document.createElement("input");
	input.type = "number";
	input.setAttribute("aria-label", label);
	input.disabled = readOnly;
	input.max = String(maximum);
	input.min = String(minimum);
	input.step = String(step);
	input.value = String(value);
	return input;
}
function selectInput(label, values, selected, readOnly) {
	const select = document.createElement("select");
	select.setAttribute("aria-label", label);
	select.disabled = readOnly;
	for (const value of values) {
		const option = document.createElement("option");
		option.value = value;
		option.textContent = value;
		option.selected = value === selected;
		select.append(option);
	}
	return select;
}
function tableHeader(text) {
	const header = document.createElement("th");
	header.scope = "col";
	header.textContent = text;
	return header;
}
function actionButton$1(label, action, disabled = false) {
	const button = document.createElement("button");
	button.type = "button";
	button.textContent = label;
	button.setAttribute("aria-label", label);
	button.disabled = disabled;
	button.addEventListener("click", action);
	return button;
}
function clamp(value, minimum, maximum) {
	return Math.min(maximum, Math.max(minimum, value));
}
function isRecord$1(value) {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
//#endregion
//#region node_modules/@kumwe/studio/dist/resource-authoring-control.js
var SEARCH_DELAY_MILLISECONDS = 300;
var SEARCH_LIMIT = 20;
/** Mount Studio's accessible resource browser and optional canonical picker. */
function mountStudioResourceBindingControl(options) {
	return new StudioResourceBindingControl(options);
}
/** Runtime guard for the only binding source this picker may persist. */
function isStudioResourceReference(value) {
	if (!isRecord(value) || !hasOnly(value, [
		"id",
		"kind",
		"resourceType"
	])) return false;
	return value.kind === "resource-reference" && isStableId(value.id) && isQualifiedName(value.resourceType);
}
var StudioResourceBindingControl = class {
	#cancel;
	#clear;
	#currentRegion;
	#holder;
	#loadMore;
	#onChange;
	#results;
	#retry;
	#search;
	#searchButton;
	#service;
	#status;
	#type;
	#types;
	readOnly;
	#abort;
	#current;
	#debounce;
	#destroyed = false;
	#items = [];
	#nextCursor;
	#requestSequence = 0;
	#retryQuery;
	constructor(options) {
		this.readOnly = options.readOnly || nonResourceBinding(options.binding);
		this.#current = resourceSource(options.binding);
		this.#holder = document.createElement("section");
		this.#holder.className = "studio-resource-binding-control";
		this.#holder.setAttribute("aria-label", `Resource browser for ${options.label}`);
		this.#onChange = options.onChange;
		this.#service = options.service;
		this.#types = parseResourceTypes(options.service.resourceTypes);
		this.#currentRegion = document.createElement("p");
		this.#currentRegion.className = "studio-resource-current";
		this.#currentRegion.setAttribute("aria-live", "polite");
		this.#type = document.createElement("select");
		this.#type.setAttribute("aria-label", "Resource type");
		for (const type of this.#types) {
			const choice = document.createElement("option");
			choice.value = type.id;
			choice.textContent = messageText(type.label);
			choice.selected = type.id === this.#current?.resourceType;
			this.#type.append(choice);
		}
		this.#type.disabled = this.#types.length === 0;
		this.#type.addEventListener("change", () => this.#resetSearch());
		this.#search = document.createElement("input");
		this.#search.type = "search";
		this.#search.maxLength = 160;
		this.#search.setAttribute("aria-label", "Search authorized resources");
		this.#search.disabled = this.#types.length === 0;
		this.#search.addEventListener("input", () => this.#scheduleSearch());
		this.#search.addEventListener("keydown", (event) => {
			if (event.key === "Enter") {
				event.preventDefault();
				this.#runSearch(void 0, false);
			}
		});
		this.#searchButton = actionButton("Search resources", () => {
			this.#runSearch(void 0, false);
		});
		this.#searchButton.disabled = this.#types.length === 0;
		this.#cancel = actionButton("Cancel resource search", () => this.#cancelSearch(true));
		this.#cancel.hidden = true;
		this.#retry = actionButton("Retry resource search", () => {
			const query = this.#retryQuery;
			if (query !== void 0) this.#runSearch(query.cursor, query.cursor !== void 0);
		});
		this.#retry.hidden = true;
		this.#clear = actionButton("Clear selected resource", () => this.#clearSelection());
		this.#clear.disabled = this.readOnly || this.#current === void 0;
		this.#status = document.createElement("p");
		this.#status.className = "studio-resource-status";
		this.#status.setAttribute("aria-live", "polite");
		this.#status.textContent = this.#types.length === 0 ? "No authorized resource types are available." : "Enter a search term or browse all authorized resources.";
		this.#results = document.createElement("ul");
		this.#results.className = "studio-resource-results";
		this.#results.setAttribute("aria-label", "Authorized resource results");
		this.#loadMore = actionButton("Load more resources", () => {
			if (this.#nextCursor !== void 0) this.#runSearch(this.#nextCursor, true);
		});
		this.#loadMore.hidden = true;
		const searchGroup = document.createElement("div");
		searchGroup.className = "studio-resource-search";
		searchGroup.append(this.#type, this.#search, this.#searchButton, this.#cancel, this.#retry);
		this.#holder.append(this.#currentRegion, searchGroup, this.#status, this.#results, this.#loadMore, this.#clear);
		options.holder.append(this.#holder);
		this.#renderCurrent(options.binding, options.multiple);
	}
	current() {
		return this.#current === void 0 ? void 0 : structuredClone(this.#current);
	}
	destroy() {
		this.#destroyed = true;
		this.#cancelSearch(false);
		this.#holder.remove();
	}
	focus() {
		this.#search.focus();
	}
	#cancelSearch(announce) {
		if (this.#debounce !== void 0) {
			clearTimeout(this.#debounce);
			this.#debounce = void 0;
		}
		this.#abort?.abort();
		this.#abort = void 0;
		this.#requestSequence += 1;
		this.#setBusy(false);
		if (announce && !this.#destroyed) this.#status.textContent = "Resource search cancelled.";
	}
	#clearSelection() {
		if (this.readOnly || this.#current === void 0) return;
		this.#current = void 0;
		this.#clear.disabled = true;
		this.#currentRegion.textContent = "No resource selected.";
		this.#onChange?.({});
	}
	#renderCurrent(binding, multiple) {
		if (this.#current !== void 0) this.#currentRegion.textContent = `Selected ${this.#current.resourceType}: ${this.#current.id}.`;
		else if (binding !== void 0) this.#currentRegion.textContent = `This ${binding.source.kind} binding is host-managed.`;
		else this.#currentRegion.textContent = "No resource selected.";
		if (this.readOnly) this.#currentRegion.append(document.createTextNode(` Selection is read-only${multiple ? " for this collection port" : ""}.`));
	}
	#renderResults() {
		const rows = this.#items.map((hit) => {
			const row = document.createElement("li");
			const label = messageText(hit.label);
			const summary = document.createElement("span");
			summary.textContent = `${label} (${hit.id})`;
			row.append(summary);
			if (!this.readOnly) row.append(actionButton(this.#current?.id === hit.id && this.#current.resourceType === hit.resourceType ? `Selected ${label}` : this.#current === void 0 ? `Select ${label}` : `Replace with ${label}`, () => this.#select(hit), this.#current?.id === hit.id && this.#current.resourceType === hit.resourceType));
			return row;
		});
		this.#results.replaceChildren(...rows);
		this.#loadMore.hidden = this.#nextCursor === void 0;
	}
	#resetSearch() {
		this.#cancelSearch(false);
		this.#items = [];
		this.#nextCursor = void 0;
		this.#retryQuery = void 0;
		this.#renderResults();
		this.#status.textContent = "Enter a search term or browse all authorized resources.";
	}
	async #runSearch(cursor, append) {
		if (this.#destroyed || this.#type.value === "") return;
		this.#cancelSearch(false);
		const controller = new AbortController();
		this.#abort = controller;
		const sequence = ++this.#requestSequence;
		const query = {
			...cursor === void 0 ? {} : { cursor },
			limit: SEARCH_LIMIT,
			resourceType: this.#type.value,
			...this.#search.value === "" ? {} : { search: this.#search.value }
		};
		Object.freeze(query);
		this.#retryQuery = query;
		this.#retry.hidden = true;
		this.#status.textContent = append ? "Loading more authorized resources…" : "Searching…";
		this.#setBusy(true);
		try {
			const page = parseResourcePage(await this.#service.search(query, controller.signal), query);
			if (controller.signal.aborted || this.#destroyed || sequence !== this.#requestSequence) return;
			this.#items = append ? appendPage(this.#items, page.items) : page.items;
			this.#nextCursor = page.nextCursor;
			this.#retryQuery = void 0;
			this.#renderResults();
			this.#status.textContent = this.#items.length === 0 ? "No authorized resources match this search." : `${this.#items.length} authorized resource${this.#items.length === 1 ? "" : "s"} shown.`;
		} catch {
			if (controller.signal.aborted || this.#destroyed || sequence !== this.#requestSequence) return;
			this.#retry.hidden = false;
			this.#status.textContent = "Resource search is unavailable. No selection was changed.";
		} finally {
			if (sequence === this.#requestSequence) {
				this.#abort = void 0;
				this.#setBusy(false);
			}
		}
	}
	#scheduleSearch() {
		if (this.#debounce !== void 0) clearTimeout(this.#debounce);
		this.#debounce = setTimeout(() => {
			this.#debounce = void 0;
			this.#runSearch(void 0, false);
		}, SEARCH_DELAY_MILLISECONDS);
	}
	#select(hit) {
		if (this.readOnly) return;
		this.#current = {
			id: hit.id,
			kind: "resource-reference",
			resourceType: hit.resourceType
		};
		this.#clear.disabled = false;
		this.#currentRegion.textContent = `Selected ${hit.resourceType}: ${hit.id}.`;
		this.#renderResults();
		this.#onChange?.({ source: structuredClone(this.#current) });
	}
	#setBusy(busy) {
		this.#cancel.hidden = !busy;
		this.#searchButton.disabled = this.#types.length === 0;
		this.#type.disabled = this.#types.length === 0;
		this.#search.disabled = this.#types.length === 0;
		this.#loadMore.disabled = busy;
		this.#results.setAttribute("aria-busy", String(busy));
	}
};
function actionButton(label, action, disabled = false) {
	const button = document.createElement("button");
	button.type = "button";
	button.textContent = label;
	button.setAttribute("aria-label", label);
	button.disabled = disabled;
	button.addEventListener("click", action);
	return button;
}
function appendPage(current, next) {
	const seen = new Set(current.map((hit) => `${hit.resourceType}\u0000${hit.id}`));
	for (const hit of next) {
		const key = `${hit.resourceType}\u0000${hit.id}`;
		if (seen.has(key)) throw new TypeError("Resource search repeated an existing item.");
		seen.add(key);
	}
	return [...current, ...next];
}
function messageText(message) {
	return message.defaultMessage ?? message.key;
}
function nonResourceBinding(binding) {
	return binding !== void 0 && binding.source.kind !== "resource-reference";
}
function parseResourcePage(page, query) {
	if (!isRecord(page) || !hasOnly(page, ["items", "nextCursor"]) || !Array.isArray(page.items)) throw new TypeError("Resource search returned an invalid page.");
	if (page.items.length > query.limit) throw new RangeError("Resource search returned too many items.");
	const items = page.items.map((hit) => parseResourceHit(hit, query.resourceType));
	if (new Set(items.map((hit) => `${hit.resourceType}\u0000${hit.id}`)).size !== items.length) throw new TypeError("Resource search returned duplicate items.");
	const nextCursor = page.nextCursor;
	if (nextCursor !== void 0 && (typeof nextCursor !== "string" || nextCursor.length === 0 || nextCursor.length > 500)) throw new TypeError("Resource search returned an invalid cursor.");
	return {
		items,
		...nextCursor === void 0 ? {} : { nextCursor }
	};
}
function parseResourceHit(hit, resourceType) {
	if (!isRecord(hit) || !hasOnly(hit, [
		"id",
		"label",
		"resourceType"
	]) || !isStableId(hit.id) || hit.resourceType !== resourceType || !isMessageReference(hit.label)) throw new TypeError("Resource search returned an invalid item.");
	return {
		id: hit.id,
		label: structuredClone(hit.label),
		resourceType
	};
}
function parseResourceTypes(options) {
	if (options.length > 100) throw new RangeError("Resource type inventory exceeds 100 entries.");
	const seen = /* @__PURE__ */ new Set();
	return options.map((option) => {
		if (!isRecord(option) || !hasOnly(option, ["id", "label"]) || !isQualifiedName(option.id) || !isMessageReference(option.label) || seen.has(option.id)) throw new TypeError("Resource type inventory is invalid or duplicated.");
		seen.add(option.id);
		return structuredClone(option);
	});
}
function resourceSource(binding) {
	if (binding?.source.kind !== "resource-reference") return void 0;
	if (!isStudioResourceReference(binding.source)) throw new TypeError("Resource binding is not canonical.");
	return structuredClone(binding.source);
}
function hasOnly(value, allowed) {
	const keys = Object.keys(value);
	return keys.length <= allowed.length && keys.every((key) => allowed.includes(key));
}
function isMessageReference(value) {
	if (!isRecord(value) || !hasOnly(value, ["key", "defaultMessage"]) || !isQualifiedName(value.key)) return false;
	return value.defaultMessage === void 0 || typeof value.defaultMessage === "string" && value.defaultMessage.length >= 1 && value.defaultMessage.length <= 500;
}
function isQualifiedName(value) {
	return typeof value === "string" && value.length <= 160 && /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u.test(value);
}
function isRecord(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value)) return false;
	const prototype = Object.getPrototypeOf(value);
	return prototype === Object.prototype || prototype === null;
}
function isStableId(value) {
	return typeof value === "string" && value.length <= 240 && ![
		"__proto__",
		"prototype",
		"constructor"
	].includes(value) && /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u.test(value);
}
//#endregion
//#region node_modules/@kumwe/studio/dist/kumwe-studio.js
var KumweStudioElement = class extends i {
	static properties = {
		announcement: {
			attribute: false,
			state: true
		},
		authoringControlRegistry: { attribute: false },
		canvasDirectManipulation: {
			attribute: false,
			state: true
		},
		canvasGeometry: {
			attribute: false,
			state: true
		},
		configuration: { attribute: false },
		contentModel: { attribute: false },
		designControls: { attribute: false },
		document: { attribute: false },
		messages: { attribute: false },
		patterns: { attribute: false },
		paletteFilter: {
			attribute: false,
			state: true
		},
		paletteOpen: {
			attribute: false,
			state: true
		},
		previewBinding: { attribute: false },
		previewState: {
			attribute: false,
			state: true
		},
		resourceSearchService: { attribute: false },
		selectedNodeId: {
			attribute: false,
			state: true
		},
		theme: { attribute: false },
		viewports: { attribute: false }
	};
	static styles = i$1`
    :host {
      --studio-border: #d7dce2;
      --studio-panel: #f7f8fa;
      --studio-primary: #3157d5;
      color: #18202a;
      display: block;
      font:
        400 0.9375rem/1.45 system-ui,
        sans-serif;
      min-height: 30rem;
    }

    .workspace {
      display: grid;
      grid-template-columns:
        minmax(10rem, 13rem) minmax(18rem, 1fr) minmax(11rem, 15rem)
        minmax(12rem, 16rem);
      min-height: inherit;
    }

    .panel,
    .canvas {
      border: 1px solid var(--studio-border);
      min-inline-size: 0;
      padding: 1rem;
    }

    .panel {
      background: var(--studio-panel);
    }

    h2 {
      font-size: 0.8125rem;
      letter-spacing: 0.05em;
      margin: 0 0 0.75rem;
      text-transform: uppercase;
    }

    button {
      background: white;
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      color: inherit;
      cursor: pointer;
      font: inherit;
      padding: 0.5rem 0.625rem;
      text-align: left;
    }

    button:disabled {
      cursor: not-allowed;
      opacity: 0.55;
    }

    button:focus-visible {
      outline: 0.1875rem solid color-mix(in srgb, var(--studio-primary), transparent 55%);
      outline-offset: 0.125rem;
    }

    button[aria-pressed='true'] {
      border-color: var(--studio-primary);
      box-shadow: inset 0.1875rem 0 0 var(--studio-primary);
    }

    .palette,
    .tree {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .toolbar {
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
      margin-bottom: 1rem;
    }

    .command-palette-toggle {
      font-size: 0.8125rem;
      margin-bottom: 0.75rem;
      padding: 0.375rem 0.5rem;
    }

    .command-palette {
      background: var(--studio-panel);
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      display: grid;
      gap: 0.5rem;
      margin-bottom: 1rem;
      padding: 0.75rem;
    }

    .command-palette input {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      box-sizing: border-box;
      font: inherit;
      inline-size: 100%;
      min-inline-size: 0;
      padding: 0.5rem 0.625rem;
    }

    .command-palette .hint {
      margin: 0;
    }

    .command-results {
      display: grid;
      gap: 0.375rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .command-empty {
      color: #5d6671;
      margin: 0;
    }

    .canvas-chip {
      touch-action: none;
    }

    .drop-indicator {
      border: 1px dashed var(--studio-primary);
      border-radius: 0.375rem;
      font-weight: 600;
      margin: 0 0 0.75rem;
      padding: 0.375rem 0.5rem;
    }

    .node-children {
      border-inline-start: 1px solid var(--studio-border);
      margin: 0.5rem 0 0 0.75rem;
      padding-inline-start: 0.75rem;
    }

    .empty {
      color: #5d6671;
      padding: 3rem 1rem;
      text-align: center;
    }

    .hint {
      color: #5d6671;
      font-size: 0.75rem;
      margin: 0 0 0.75rem;
      overflow-wrap: anywhere;
    }

    .unresolved {
      background: #fbe9e9;
      border: 1px solid #e5b6b6;
      border-radius: 0.25rem;
      color: #7c1d1d;
      font-size: 0.75rem;
      padding: 0 0.25rem;
    }

    .outline-controls {
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      margin-top: 0.375rem;
    }

    .outline-controls button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .outline-move-destination-label {
      display: grid;
      flex-basis: 100%;
      font-size: 0.75rem;
      gap: 0.25rem;
      min-inline-size: 0;
    }

    .outline-move-destination {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      box-sizing: border-box;
      font: inherit;
      inline-size: 100%;
      max-inline-size: 100%;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .viewport-switcher {
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      margin-bottom: 0.75rem;
    }

    .viewport-switcher button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .preview-region {
      background: white;
      border: 1px solid var(--studio-border);
      border-radius: 0.5rem;
      margin-bottom: 1rem;
      padding: 0.75rem;
    }

    .preview-region h2 {
      margin-bottom: 0.25rem;
    }

    .preview-status {
      color: #5d6671;
      font-size: 0.8125rem;
      margin: 0 0 0.625rem;
    }

    .preview-surface-slot {
      display: block;
      max-inline-size: 100%;
      overflow: visible;
    }

    .preview-stage {
      isolation: isolate;
      overflow: auto;
      position: relative;
    }

    .preview-stage:focus-visible {
      outline: 0.1875rem solid color-mix(in srgb, var(--studio-primary), transparent 55%);
      outline-offset: 0.125rem;
    }

    .preview-surface-slot::slotted(iframe) {
      border: 0;
      display: block;
      inline-size: 100%;
      min-block-size: 20rem;
    }

    .preview-canvas-overlay {
      inset-block-start: 0;
      inset-inline-start: 0;
      max-inline-size: none;
      overflow: hidden;
      pointer-events: none;
      position: absolute;
      z-index: 1;
    }

    .preview-canvas-region {
      fill: transparent;
      pointer-events: all;
      stroke: transparent;
      stroke-width: 2;
      vector-effect: non-scaling-stroke;
    }

    .preview-canvas-overlay[data-interactive='false'] .preview-canvas-region {
      pointer-events: none;
    }

    .preview-canvas-overlay[data-interactive='true'] {
      pointer-events: auto;
    }

    .canvas-edit-toggle {
      margin-bottom: 0.625rem;
    }

    .preview-canvas-region[data-hovered='true'] {
      fill: color-mix(in srgb, var(--studio-primary), transparent 92%);
      stroke: color-mix(in srgb, var(--studio-primary), transparent 35%);
    }

    .preview-canvas-region[data-selected='true'] {
      fill: color-mix(in srgb, var(--studio-primary), transparent 88%);
      stroke: var(--studio-primary);
      stroke-width: 3;
    }

    .preview-canvas-drop-indicator {
      fill: color-mix(in srgb, var(--studio-primary), transparent 72%);
      pointer-events: none;
      stroke: var(--studio-primary);
      stroke-width: 2;
      vector-effect: non-scaling-stroke;
    }

    .preview-canvas-status {
      background: white;
      border: 1px dashed var(--studio-primary);
      border-radius: 0.375rem;
      font-size: 0.8125rem;
      font-weight: 600;
      margin: 0.5rem 0 0;
      padding: 0.375rem 0.5rem;
    }

    .breadcrumb ol {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      list-style: none;
      margin: 0 0 0.75rem;
      padding: 0;
    }

    .breadcrumb li + li::before {
      content: '\\203A';
      margin-inline-end: 0.375rem;
    }

    .breadcrumb button {
      font-size: 0.8125rem;
      padding: 0.25rem 0.375rem;
    }

    .breadcrumb-current {
      font-weight: 600;
    }

    .diagnostics {
      grid-column: 1 / -1;
    }

    .diagnostics-list {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0;
      padding: 0;
    }

    .diagnostics-empty {
      color: #5d6671;
      margin: 0;
    }

    .diagnostic-severity {
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .diagnostic-severity::after {
      content: ':';
    }

    .statusbar {
      align-items: center;
      border: 1px solid var(--studio-border);
      display: flex;
      gap: 0.5rem;
      grid-column: 1 / -1;
      padding: 0.5rem 1rem;
    }

    .save-state[data-dirty='true'] {
      color: #7c4a03;
    }

    .assistive {
      block-size: 1px;
      clip-path: inset(50%);
      inline-size: 1px;
      margin: -1px;
      overflow: hidden;
      padding: 0;
      position: absolute;
      white-space: nowrap;
    }

    dl {
      display: grid;
      gap: 0.75rem;
      margin: 0;
    }

    dt {
      color: #5d6671;
      font-size: 0.75rem;
      text-transform: uppercase;
    }

    dd {
      margin: 0.125rem 0 0;
      overflow-wrap: anywhere;
    }

    .inspector-section {
      margin-top: 1rem;
    }

    .inspector-section h3 {
      font-size: 0.75rem;
      letter-spacing: 0.05em;
      margin: 0 0 0.5rem;
      text-transform: uppercase;
    }

    .inspector-rows {
      display: grid;
      gap: 0.5rem;
      list-style: none;
      margin: 0 0 0.5rem;
      padding: 0;
    }

    .inspector-row {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
    }

    .inspector-authoring-row {
      display: grid;
      gap: 0.375rem;
    }

    .inspector-authoring-control,
    .inspector-resource-control {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      min-inline-size: 0;
      overflow: auto;
      padding: 0.5rem;
    }

    .inspector-authoring-control :is(input, select, textarea, button),
    .inspector-resource-control :is(input, select, textarea, button) {
      font: inherit;
      max-inline-size: 100%;
    }

    .inspector-authoring-control textarea {
      box-sizing: border-box;
      inline-size: 100%;
    }

    .studio-resource-search {
      display: grid;
      gap: 0.375rem;
      grid-template-columns: minmax(0, 1fr);
    }

    .studio-resource-current,
    .studio-resource-status {
      color: #5d6671;
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .studio-resource-results {
      display: grid;
      gap: 0.375rem;
      list-style: none;
      padding: 0;
    }

    .studio-resource-results li {
      align-items: center;
      display: flex;
      flex-wrap: wrap;
      gap: 0.375rem;
      justify-content: space-between;
      overflow-wrap: anywhere;
    }

    .inspector-name {
      font-size: 0.8125rem;
      font-weight: 600;
      overflow-wrap: anywhere;
    }

    .inspector input {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .inspector input:disabled {
      background: var(--studio-panel);
      cursor: not-allowed;
      opacity: 0.55;
    }

    .inspector select {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
    }

    .inspector select:disabled {
      background: var(--studio-panel);
      cursor: not-allowed;
      opacity: 0.55;
    }

    .inspector textarea {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      flex: 1 1 6rem;
      font: inherit;
      min-block-size: 3rem;
      min-inline-size: 0;
      padding: 0.375rem 0.5rem;
      resize: vertical;
    }

    .inspector-binding-model {
      border: 1px solid var(--studio-border);
      border-radius: 0.375rem;
      display: grid;
      gap: 0.375rem;
      padding: 0.5rem;
    }

    .inspector-binding-control {
      align-items: center;
      background: white;
      border-inline-start: 0.1875rem solid var(--studio-border);
      display: grid;
      flex-basis: 100%;
      gap: 0.25rem;
      padding: 0.375rem 0.5rem;
    }

    .inspector-binding-control > :is(input, select, textarea) {
      inline-size: 100%;
    }

    .inspector-binding-path,
    .inspector-binding-status {
      color: #5d6671;
      flex-basis: 100%;
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .inspector-provenance {
      color: #5d6671;
      flex-basis: 100%;
      font-size: 0.75rem;
    }

    .outline-slot-label {
      color: #5d6671;
      display: block;
      font-size: 0.75rem;
      margin: 0.25rem 0;
    }

    .inspector button {
      font-size: 0.8125rem;
      padding: 0.375rem 0.5rem;
    }

    .inspector-binding-value {
      font-size: 0.75rem;
      overflow-wrap: anywhere;
    }

    .inspector-empty {
      color: #5d6671;
      margin: 0 0 0.5rem;
    }

    @media (max-width: 60rem) {
      .workspace {
        grid-template-columns: minmax(16rem, 1fr);
      }
    }

    /* SR-019: no chrome motion is essential, so a reduced-motion preference
       zeroes every animation and transition the shell declares now or later. */
    @media (prefers-reduced-motion: reduce) {
      :host,
      *,
      *::before,
      *::after {
        animation-delay: 0s !important;
        animation-duration: 0s !important;
        animation-iteration-count: 1 !important;
        scroll-behavior: auto !important;
        transition-delay: 0s !important;
        transition-duration: 0s !important;
      }
    }
  `;
	#activeViewportId;
	#authoringControls = /* @__PURE__ */ new Map();
	#authoringControlsReady = Promise.resolve();
	#authoringDiagnostics = /* @__PURE__ */ new Map();
	#defaultAuthoringControlRegistry = new StudioAuthoringControlRegistry();
	#announcementPending = false;
	#commandSequence = 0;
	#bindingProjection;
	#defaultDefinitions = createCoreProductionBlockDefinitions();
	#defaultPatterns = createCoreProductionPatterns();
	#diagnostics = [];
	#drag;
	#hoveredPreviewNodeId;
	#internalDocumentUpdate = false;
	#lastDirty = false;
	#paletteInvoker;
	#pendingFocusNodeId;
	#pendingPaletteFocus = false;
	#onDocumentKeydown = (event) => {
		if (event.key === "Escape" && (this.#drag !== void 0 || this.#previewDrag !== void 0) && this.#cancelDrag()) {
			event.preventDefault();
			event.stopPropagation();
		}
	};
	#pendingPreviewAnnouncements = [];
	#activePreviewBinding;
	#previewBindingGeneration;
	#previewSurface;
	#previewDrag;
	#removedNodes = [];
	#registry;
	#resourceBindingControls = /* @__PURE__ */ new Map();
	#session;
	#sessionGeneration = "";
	get activeViewport() {
		const ordered = this.#orderedViewports();
		if (ordered.length === 0) return;
		const chosen = ordered.find((viewport) => viewport.id === this.#activeViewportId);
		const initial = ordered.find((viewport) => viewport.id === this.configuration?.session.preview.initialViewport);
		return chosen ?? initial ?? ordered.find((viewport) => viewport.base) ?? ordered[0];
	}
	get stateVersion() {
		return this.#session?.stateVersion ?? 0;
	}
	/** Resolves after the latest imperative custom-field lifecycle pass settles. */
	get authoringReady() {
		return this.#authoringControlsReady;
	}
	/** The single mode resolved from the wire configuration for this session. */
	get sessionMode() {
		return this.#session?.mode;
	}
	execute(command) {
		if (this.configuration?.session.sessionState === "read-only") {
			const message = "The current Studio session is read-only.";
			this.#announce("studio.shell/announce-conflict", { message });
			throw new Error(message);
		}
		const session = this.#session;
		if (session === void 0) throw new Error("Load a blueprint document before executing a command.");
		let next;
		try {
			next = session.execute(command);
		} catch (error) {
			if (error instanceof StudioCommandError && CONFLICT_ERROR_CODES.has(error.code)) this.#announce("studio.shell/announce-conflict", { message: error.message });
			else this.#announce("studio.shell/announce-command-failed", { message: error instanceof Error ? error.message : String(error) });
			throw error;
		}
		this.#assignInternalDocument(next);
		this.selectedNodeId = session.selection[0];
		this.#emitDocumentChange({
			command,
			document: next,
			source: "command"
		});
		this.#syncDirty();
		return next;
	}
	/**
	* Select one host-known document node, or clear selection after a host-owned interaction.
	*
	* Hosts that allocate node identifiers execute the accepted command through `execute()` and then
	* use this seam to give palette insertion the same Inspector, outline and preview-selection parity
	* as commands Studio can construct locally. Invalid identifiers are refused by the core session.
	*/
	selectNode(nodeId) {
		const session = this.#session;
		if (session === void 0) throw new Error("Load a blueprint document before selecting a node.");
		if (nodeId === void 0) {
			session.clearSelection();
			this.selectedNodeId = void 0;
			this.#previewSurface?.selectNode(void 0);
			return;
		}
		session.select([nodeId]);
		this.selectedNodeId = nodeId;
		this.#previewSurface?.selectNode(nodeId);
	}
	/**
	* Accept a host save acknowledgement without replacing the local session.
	* Pass the state version captured with the saved snapshot when the host can
	* settle after newer edits; those edits remain dirty on the accepted base.
	*/
	markSaved(revision, stateVersion) {
		const session = this.#session;
		if (session === void 0) return;
		session.markSaved(revision ?? session.savedRevision, stateVersion);
		this.#assignInternalDocument(session.document);
		this.#syncDirty();
	}
	/**
	* Ask the bound preview channel to refresh volatile marker geometry after
	* a host-observed resize, scroll, zoom or late-loading asset settlement.
	*/
	refreshPreviewGeometry() {
		this.#previewSurface?.refreshGeometry();
	}
	/**
	* Closes the bound preview channel without changing authoring state or
	* focus. A later preview session requires a fresh binding.
	*/
	teardownPreview(reason) {
		if (this.#previewSurface === void 0) return;
		this.#queuePreviewAnnouncement("studio.shell/announce-preview-torn-down", { reason });
		this.#previewSurface.teardown(reason);
	}
	disconnectedCallback() {
		this.#destroyAuthoringControls();
		this.#destroyResourceBindingControls();
		this.ownerDocument.removeEventListener("keydown", this.#onDocumentKeydown, true);
		this.teardownPreview("studio.preview/surface-disconnected");
		super.disconnectedCallback();
	}
	connectedCallback() {
		super.connectedCallback();
		this.ownerDocument.addEventListener("keydown", this.#onDocumentKeydown, true);
	}
	/**
	* Consumes preview channel messages a host forwards (for example from
	* `PreviewClient.onMessage`). Renderer reload and channel teardown are
	* announced through the single polite live region with their qualified
	* reason, via the message catalog. The handler never moves focus and never
	* touches the document or session: a preview restart is presentation-only.
	* Read-only sessions announce identically. All other message types are
	* ignored here.
	*/
	notifyPreviewMessage(message) {
		if (message.type === "studio.preview/reload") this.#queuePreviewAnnouncement("studio.shell/announce-preview-reloaded", { reason: message.payload.reason });
		else if (message.type === "studio.preview/teardown") this.#queuePreviewAnnouncement("studio.shell/announce-preview-torn-down", { reason: message.payload.reason });
	}
	redo() {
		const session = this.#session;
		if (this.#isReadOnly() || session?.canRedo !== true) return this.document;
		this.#captureOutlineFocus();
		const next = session.redo();
		this.#assignInternalDocument(next);
		this.selectedNodeId = session.selection[0];
		this.#emitDocumentChange({
			command: null,
			document: next,
			source: "redo"
		});
		this.#syncDirty();
		this.#announce("studio.shell/announce-redid");
		return next;
	}
	undo() {
		const session = this.#session;
		if (this.#isReadOnly() || session?.canUndo !== true) return this.document;
		this.#captureOutlineFocus();
		const next = session.undo();
		this.#assignInternalDocument(next);
		this.selectedNodeId = session.selection[0];
		this.#emitDocumentChange({
			command: null,
			document: next,
			source: "undo"
		});
		this.#syncDirty();
		this.#announce("studio.shell/announce-undid");
		return next;
	}
	willUpdate(changed) {
		if (changed.has("viewports") || changed.has("theme")) this.#activeViewportId = void 0;
		if (changed.has("configuration")) this.#rebuildRegistry();
		if (changed.has("configuration") || changed.has("previewBinding")) this.#synchronizePreviewSurface();
		if (changed.has("document") || changed.has("configuration")) {
			if (this.#internalDocumentUpdate) this.#internalDocumentUpdate = false;
			else this.#rebuildSession();
		}
		if (changed.has("document") || changed.has("configuration") || changed.has("contentModel")) this.#revalidate();
	}
	updated(changed) {
		if (changed.has("authoringControlRegistry")) this.#destroyAuthoringControls();
		if (changed.has("resourceSearchService")) this.#destroyResourceBindingControls();
		for (const select of this.shadowRoot?.querySelectorAll("select[data-current-value]") ?? []) {
			const current = select.dataset.currentValue;
			if (current !== void 0 && select.value !== current) select.value = current;
		}
		this.#announcementPending = false;
		const deferred = this.#pendingPreviewAnnouncements.shift();
		if (deferred !== void 0 && deferred !== this.announcement) {
			this.announcement = deferred;
			this.#announcementPending = true;
		}
		if (this.#pendingPaletteFocus) {
			this.#pendingPaletteFocus = false;
			this.shadowRoot?.querySelector(".command-palette input")?.focus();
		}
		for (const select of this.shadowRoot?.querySelectorAll("select.layout-role-select") ?? []) select.value = select.dataset.role ?? "";
		if (changed.has("document") || changed.has("configuration") || changed.has("previewBinding") || changed.has("theme") || changed.has("viewports")) this.#schedulePreview();
		this.#authoringControlsReady = this.#authoringControlsReady.catch(() => void 0).then(async () => {
			await this.#synchronizeAuthoringControls();
			this.#synchronizeResourceBindingControls();
		});
		const nodeId = this.#pendingFocusNodeId;
		if (nodeId === void 0) return;
		this.#pendingFocusNodeId = void 0;
		this.#focusOutlineEntry(nodeId);
	}
	render() {
		const session = this.#session;
		const readOnly = this.#isReadOnly();
		const roots = this.document?.roots ?? [];
		const selected = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(this.document.roots, this.selectedNodeId)?.node;
		const diagnostics = [...this.#diagnostics, ...this.#authoringDiagnostics.values()].sort((left, right) => SEVERITY_RANK[left.severity] - SEVERITY_RANK[right.severity]);
		return b`
      <div
        class="workspace"
        @keydown=${(event) => {
			this.#onWorkspaceKeydown(event);
		}}
      >
        <aside class="panel" aria-label=${this.#text("studio.shell/palette-label")}>
          <h2>${this.#text("studio.shell/palette-heading")}</h2>
          <ul class="palette">
            ${this.#activeDefinitions().map((definition) => b`
                <li>
                  <button
                    type="button"
                    ?disabled=${!this.#canInsertDefinition(definition)}
                    @click=${() => this.#requestInsert(definition)}
                  >
                    ${referenceText(definition.label)}
                  </button>
                </li>
              `)}
          </ul>
          ${this.#activePatterns().length === 0 ? A : b`
                  <h2 class="pattern-heading">${this.#text("studio.shell/patterns-heading")}</h2>
                  <ul class="palette pattern-palette">
                    ${this.#activePatterns().map((pattern) => b`
                        <li>
                          <button
                            type="button"
                            class="pattern-apply"
                            data-pattern-id=${pattern.id}
                            ?disabled=${this.#patternDestination(pattern) === void 0}
                            @click=${() => {
			this.#applyPattern(pattern);
		}}
                          >
                            ${referenceText(pattern.label)}
                          </button>
                        </li>
                      `)}
                  </ul>
                `}
        </aside>

        <main
          class="canvas"
          aria-label=${this.#text("studio.shell/canvas-label")}
          data-viewport=${this.activeViewport?.id ?? A}
          @pointermove=${(event) => {
			this.#onCanvasPointerMove(event);
		}}
          @pointerup=${(event) => {
			this.#onCanvasPointerUp(event);
		}}
          @pointercancel=${(event) => {
			this.#onCanvasPointerCancel(event);
		}}
        >
          ${this.#renderViewportSwitcher()} ${this.#renderBreadcrumb()} ${this.#renderPreview()}
          <button
            type="button"
            class="command-palette-toggle"
            aria-expanded=${this.paletteOpen === true ? "true" : "false"}
            @click=${(event) => {
			this.#togglePalette(event);
		}}
          >
            ${this.#text("studio.shell/command-palette-toggle")}
          </button>
          ${this.#renderCommandPalette()}
          <div class="toolbar" role="group" aria-label=${this.#text("studio.shell/history-label")}>
            <button
              type="button"
              ?disabled=${session?.canUndo !== true || readOnly}
              @click=${() => {
			this.undo();
		}}
            >
              ${this.#text("studio.shell/undo")}
            </button>
            <button
              type="button"
              ?disabled=${session?.canRedo !== true || readOnly}
              @click=${() => {
			this.redo();
		}}
            >
              ${this.#text("studio.shell/redo")}
            </button>
          </div>
          ${this.#renderDropIndicator()}
          ${roots.length === 0 ? b`<p class="empty">${this.#text("studio.shell/canvas-empty")}</p>` : this.#previewCapabilityAvailable() && this.previewBinding !== void 0 ? A : b`<ul class="tree structural-canvas-fallback">
                    ${roots.map((node) => this.#renderCanvasNode(node))}
                  </ul>`}
        </main>

        <aside class="panel outline" aria-label=${this.#text("studio.shell/outline-heading")}>
          <h2>${this.#text("studio.shell/outline-heading")}</h2>
          <p class="hint">${this.#text("studio.shell/outline-hint")}</p>
          ${roots.length === 0 ? b`<p class="empty">${this.#text("studio.shell/outline-empty")}</p>` : b`<ul class="tree">
                  ${roots.map((node) => this.#renderOutlineNode(node))}
                </ul>`}
        </aside>

        <aside class="panel inspector" aria-label=${this.#text("studio.shell/inspector-heading")}>
          <h2>${this.#text("studio.shell/inspector-heading")}</h2>
          ${selected === void 0 ? b`<p>${this.#text("studio.shell/inspector-empty")}</p>` : this.#renderInspector(selected)}
        </aside>

        <section
          class="panel diagnostics"
          aria-label=${this.#text("studio.shell/diagnostics-heading")}
        >
          <h2>${this.#text("studio.shell/diagnostics-heading")}</h2>
          ${diagnostics.length === 0 ? b`<p class="diagnostics-empty">
                  ${this.#text("studio.shell/diagnostics-empty")}
                </p>` : b`<ul class="diagnostics-list">
                  ${diagnostics.map((entry) => this.#renderDiagnostic(entry))}
                </ul>`}
        </section>

        <footer class="statusbar" aria-label=${this.#text("studio.shell/status-label")}>
          ${session === void 0 ? A : b`<span class="save-state" data-dirty=${session.dirty ? "true" : "false"}>
                  ${this.#text(session.dirty ? "studio.shell/save-state-unsaved" : "studio.shell/save-state-saved")}
                </span>`}
          <p class="assistive" aria-live="polite">${this.announcement ?? ""}</p>
        </footer>
      </div>
    `;
	}
	#addOverride(node, viewport) {
		const nameInput = this.shadowRoot?.querySelector("input.inspector-add-override-name") ?? null;
		const valueInput = this.shadowRoot?.querySelector("input.inspector-add-override-value") ?? null;
		if (nameInput === null || valueInput === null) return;
		const property = nameInput.value.trim();
		if (property.length === 0) {
			this.#announce("studio.shell/announce-name-required");
			return;
		}
		const parsed = this.#parseJsonInput(valueInput.value, property);
		if (parsed === void 0) return;
		if (this.#setNodeProperty(node, property, parsed.value, viewport)) {
			nameInput.value = "";
			valueInput.value = "";
		}
	}
	#addProperty(node) {
		const nameInput = this.shadowRoot?.querySelector("input.inspector-add-property-name") ?? null;
		const valueInput = this.shadowRoot?.querySelector("input.inspector-add-property-value") ?? null;
		if (nameInput === null || valueInput === null) return;
		const property = nameInput.value.trim();
		if (property.length === 0) {
			this.#announce("studio.shell/announce-name-required");
			return;
		}
		const parsed = this.#parseJsonInput(valueInput.value, property);
		if (parsed === void 0) return;
		if (this.#setNodeProperty(node, property, parsed.value, void 0)) {
			nameInput.value = "";
			valueInput.value = "";
		}
	}
	#announce(key, parameters) {
		this.announcement = messageText$1(key, this.messages, parameters);
		this.#announcementPending = true;
	}
	#assignInternalDocument(document) {
		this.#internalDocumentUpdate = true;
		this.document = document;
	}
	/**
	* The size role assigned in the targeted responsive context: the base
	* assignment without a viewport, the viewport override otherwise.
	*/
	#assignedSizeRole(node, axis, viewport) {
		return viewport === void 0 ? node.sizeRoles?.[axis] : node.responsiveSizeRoles?.[axis]?.[viewport.id];
	}
	#axisText(axis) {
		return this.#text(AXIS_MESSAGE_KEYS[axis]);
	}
	/**
	* Abandons an in-progress canvas drag without touching the document.
	* Returns whether a drag was actually pending, so keyboard handling can
	* consume the Escape key only when it cancelled something.
	*/
	#cancelDrag() {
		const previewDrag = this.#previewDrag;
		if (previewDrag !== void 0) {
			this.#previewDrag = void 0;
			this.#releasePreviewDragCapture(previewDrag);
			if (previewDrag.active) this.#announce("studio.shell/announce-drag-cancelled", { label: previewDrag.label });
			this.requestUpdate();
			return true;
		}
		const drag = this.#drag;
		if (drag === void 0) return false;
		this.#drag = void 0;
		this.#releaseDragCapture(drag);
		if (drag.active) this.#announce("studio.shell/announce-drag-cancelled", { label: drag.label });
		this.requestUpdate();
		return true;
	}
	#captureOutlineFocus() {
		const active = this.shadowRoot?.activeElement;
		if (active instanceof HTMLElement && active.classList.contains("outline-entry") && active.dataset.nodeId !== void 0) this.#pendingFocusNodeId = active.dataset.nodeId;
	}
	#closePalette(restoreFocus) {
		this.paletteOpen = false;
		this.paletteFilter = "";
		const invoker = this.#paletteInvoker;
		this.#paletteInvoker = void 0;
		if (restoreFocus && invoker?.isConnected === true) invoker.focus();
	}
	#commandEnvelope(document, session) {
		this.#commandSequence += 1;
		return {
			artifactId: document.id,
			baseStateVersion: session.stateVersion,
			contractVersion: document.contractVersion,
			id: `studio-shell-command-${this.#commandSequence}`,
			kind: "command",
			sessionGeneration: this.#sessionGeneration
		};
	}
	#currentInspectorNode(nodeId) {
		return this.document === void 0 ? void 0 : findOutlineLocation(this.document.roots, nodeId)?.node;
	}
	#deleteNode(node) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#canMutateNode(node, "studio.command/remove-node")) return;
		const location = findOutlineLocation(document.roots, node.id);
		if (location === void 0) return;
		const previousSiblingId = location.index > 0 ? location.collection[location.index - 1]?.id : void 0;
		const parentId = location.parentNodeId;
		const label = this.#nodeLabel(node);
		const destination = { position: location.index };
		if (location.parentNodeId !== void 0 && location.slot !== void 0) {
			destination.parentNodeId = location.parentNodeId;
			destination.slot = location.slot;
		}
		const removedNode = structuredClone(location.node);
		const command = {
			...this.#commandEnvelope(document, session),
			payload: { nodeId: node.id },
			type: "studio.command/remove-node"
		};
		if (this.#runShellCommand(command)) {
			this.#removedNodes.push({
				destination,
				label,
				node: removedNode
			});
			const maximumHistoryEntries = this.configuration?.session.limits.maxHistoryEntries ?? 100;
			if (this.#removedNodes.length > maximumHistoryEntries) this.#removedNodes.splice(0, this.#removedNodes.length - maximumHistoryEntries);
			const focusTarget = previousSiblingId ?? parentId ?? this.document?.roots[0]?.id;
			if (focusTarget !== void 0) {
				this.#selectNode(focusTarget);
				this.#pendingFocusNodeId = focusTarget;
			}
			this.#announce("studio.shell/announce-deleted", { label });
		}
	}
	#restoreLastNode() {
		const session = this.#session;
		const document = this.document;
		const record = this.#lastRestorableNode();
		const destination = record === void 0 ? void 0 : this.#restoreDestination(record);
		if (session === void 0 || document === void 0 || record === void 0 || destination === void 0 || !this.#permits("studio.command/restore-node")) return;
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				destination,
				node: structuredClone(record.node)
			},
			type: "studio.command/restore-node"
		};
		if (this.#runShellCommand(command)) {
			this.#removedNodes.splice(this.#removedNodes.lastIndexOf(record), 1);
			this.#selectNode(record.node.id);
			this.#pendingFocusNodeId = record.node.id;
			this.#announce("studio.shell/announce-restored", { label: record.label });
		}
	}
	#lastRestorableNode() {
		const document = this.document;
		if (document === void 0) return;
		const ids = collectDocumentIds(document.roots);
		for (let index = this.#removedNodes.length - 1; index >= 0; index -= 1) {
			const record = this.#removedNodes[index];
			if (record === void 0 || [...collectDocumentIds([record.node])].some((id) => ids.has(id))) continue;
			if (this.#restoreDestination(record) === void 0) continue;
			return record;
		}
	}
	#restoreDestination(record) {
		const document = this.document;
		if (document === void 0) return;
		const parentId = record.destination.parentNodeId;
		if (parentId === void 0) {
			if (this.#session?.mode === "hybrid") return;
			return { position: Math.min(record.destination.position, document.roots.length) };
		}
		const slotName = record.destination.slot;
		const parent = findOutlineLocation(document.roots, parentId)?.node;
		const declared = this.#findDefinition(parent ?? record.node)?.slots.find((slot) => slot.id === slotName);
		if (parent === void 0 || slotName === void 0 || declared?.accepts.types.includes(record.node.type) !== true) return;
		const children = parent.slots[slotName] ?? [];
		if (children.length >= declared.maximum) return;
		if (this.#session?.mode === "hybrid") {
			const allowed = parent.authoring.slots?.[slotName]?.allowedBlocks ?? parent.authoring.allowedBlocks;
			if (!this.#isComposableSlot(parent, slotName) || allowed?.includes(record.node.type) === false) return;
		}
		return {
			parentNodeId: parentId,
			position: Math.min(record.destination.position, children.length),
			slot: slotName
		};
	}
	#duplicateNode(node) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#canMutateNode(node, "studio.command/duplicate-node")) return;
		const idMap = allocateDuplicateIdMap(document.roots, node);
		const copyId = idMap[node.id];
		if (copyId === void 0) return;
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				idMap,
				nodeId: node.id
			},
			type: "studio.command/duplicate-node"
		};
		if (this.#runShellCommand(command)) {
			this.#selectNode(copyId);
			this.#pendingFocusNodeId = copyId;
			this.#announce("studio.shell/announce-duplicated", { label: this.#nodeLabel(node) });
		}
	}
	#emitDocumentChange(detail) {
		this.dispatchEvent(new CustomEvent("studio-document-change", {
			bubbles: true,
			composed: true,
			detail
		}));
	}
	#filteredPaletteEntries() {
		const filter = (this.paletteFilter ?? "").trim().toLowerCase();
		const entries = this.#paletteEntries();
		if (filter.length === 0) return entries;
		return entries.filter((entry) => entry.label.toLowerCase().includes(filter));
	}
	#activeDefinitions() {
		return this.configuration?.blockDefinitions ?? this.#defaultDefinitions;
	}
	#activePatterns() {
		return this.patterns ?? (this.configuration?.blockDefinitions === void 0 ? this.#defaultPatterns : []);
	}
	#findDefinition(node) {
		return this.#activeDefinitions().find((candidate) => candidate.type === node.type && candidate.version === node.version);
	}
	#focusOutlineEntry(nodeId) {
		const entries = this.shadowRoot?.querySelectorAll("button.outline-entry");
		if (entries === void 0) return;
		for (const entry of entries) if (entry.dataset.nodeId === nodeId) {
			entry.focus();
			return;
		}
	}
	/**
	* Inserts a fresh node for a palette block definition: into the selected
	* node's first declared slot when its definition declares slots, otherwise
	* at the end of the document roots — the same placement an outline insert
	* resolves to.
	*/
	#insertDefinition(definition) {
		const session = this.#session;
		const document = this.document;
		const destination = this.#insertionDestination(definition);
		if (session === void 0 || document === void 0 || destination === void 0) return;
		const taken = collectDocumentIds(document.roots);
		const base = definition.type.slice(definition.type.indexOf("/") + 1);
		let counter = 1;
		let nodeId = `${base}-${counter}`;
		while (taken.has(nodeId)) {
			counter += 1;
			nodeId = `${base}-${counter}`;
		}
		const node = {
			authoring: { mode: isCoreProductionBlockType(definition.type) && definition.slots.length > 0 ? "structural" : "content" },
			bindings: {},
			id: nodeId,
			properties: isCoreProductionBlockType(definition.type) ? coreProductionInitialProperties(definition.type) : {},
			slots: Object.fromEntries(definition.slots.map((slot) => [slot.id, []])),
			type: definition.type,
			version: definition.version
		};
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				destination,
				node
			},
			type: "studio.command/insert-node"
		};
		if (this.#runShellCommand(command)) {
			this.#selectNode(nodeId);
			this.#pendingFocusNodeId = nodeId;
			this.#announce("studio.shell/announce-inserted", { label: referenceText(definition.label) });
		}
	}
	#isReadOnly() {
		return this.configuration?.session.sessionState === "read-only" || this.#session?.sessionState === "read-only";
	}
	#canInsertDefinition(definition) {
		return this.#insertionDestination(definition) !== void 0;
	}
	#canMutateNode(node, type) {
		if (!this.#permits(type)) return false;
		if (this.#session?.mode !== "hybrid") return true;
		const document = this.document;
		if (document === void 0) return false;
		const location = findOutlineLocation(document.roots, node.id);
		if (location?.parentNodeId === void 0 || location.slot === void 0 || this.#subtreeContainsLockedNode(node)) return false;
		const parent = findOutlineLocation(document.roots, location.parentNodeId)?.node;
		if (parent === void 0 || !this.#isComposableSlot(parent, location.slot)) return false;
		const allowed = parent.authoring.slots?.[location.slot]?.allowedBlocks ?? parent.authoring.allowedBlocks;
		return type !== "studio.command/duplicate-node" || allowed?.includes(node.type) !== false;
	}
	#insertionDestination(definition) {
		if (!this.#permits("studio.command/insert-node")) return;
		const document = this.document;
		if (document === void 0) return;
		const selected = this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(document.roots, this.selectedNodeId)?.node;
		const selectedDefinition = selected === void 0 ? void 0 : this.#findDefinition(selected);
		if (selected === void 0 || selectedDefinition === void 0) return this.#session?.mode === "hybrid" ? void 0 : { position: document.roots.length };
		for (const slot of selectedDefinition.slots) {
			if (!slot.accepts.types.includes(definition.type)) continue;
			if (this.#session?.mode === "hybrid") {
				if (!this.#isComposableSlot(selected, slot.id)) continue;
				if ((selected.authoring.slots?.[slot.id]?.allowedBlocks ?? selected.authoring.allowedBlocks)?.includes(definition.type) === false) continue;
			}
			return {
				parentNodeId: selected.id,
				position: selected.slots[slot.id]?.length ?? 0,
				slot: slot.id
			};
		}
		return this.#session?.mode === "hybrid" ? void 0 : { position: document.roots.length };
	}
	#isComposableSlot(parent, slot) {
		return parent.authoring.mode === "structural" || parent.authoring.slots?.[slot]?.composable === true;
	}
	#subtreeContainsLockedNode(node) {
		const stack = [node];
		while (stack.length > 0) {
			const current = stack.pop();
			if (current === void 0) break;
			if (current.authoring.mode === "locked") return true;
			for (const children of Object.values(current.slots)) stack.push(...children);
		}
		return false;
	}
	#moveNode(node, direction) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#canMutateNode(node, "studio.command/reorder-children")) return;
		const location = findOutlineLocation(document.roots, node.id);
		if (location === void 0) return;
		const targetIndex = location.index + direction;
		if (targetIndex < 0 || targetIndex >= location.collection.length) return;
		const order = location.collection.map((sibling) => sibling.id);
		const [movedId] = order.splice(location.index, 1);
		if (movedId === void 0) return;
		order.splice(targetIndex, 0, movedId);
		const payload = { order };
		if (location.parentNodeId !== void 0 && location.slot !== void 0) {
			payload.parentNodeId = location.parentNodeId;
			payload.slot = location.slot;
		}
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/reorder-children"
		};
		if (this.#runShellCommand(command)) {
			this.#pendingFocusNodeId = node.id;
			this.#announce(direction === -1 ? "studio.shell/announce-moved-up" : "studio.shell/announce-moved-down", { label: this.#nodeLabel(node) });
		}
	}
	#moveDestinations(node) {
		const document = this.document;
		const source = document === void 0 ? void 0 : findOutlineLocation(document.roots, node.id);
		if (document === void 0 || source === void 0 || !this.#permits("studio.command/move-node")) return [];
		const destinations = [];
		for (const target of this.#moveCollections(node)) {
			const sameCollection = source.parentNodeId === target.parentNodeId && source.slot === target.slot;
			const collection = target.collection.filter((candidate) => candidate.id !== node.id);
			for (let position = 0; position <= collection.length; position += 1) {
				if (sameCollection && position === source.index) continue;
				const destination = { position };
				if (target.parentNodeId !== void 0 && target.slot !== void 0) {
					destination.parentNodeId = target.parentNodeId;
					destination.slot = target.slot;
				}
				if (!this.#canMoveNodeTo(node, destination)) continue;
				const option = {
					destination,
					id: `${target.parentNodeId ?? "document"}--${target.slot ?? "roots"}--${position}`,
					label: this.#text("studio.shell/move-destination-option", {
						collection: target.label,
						count: String(collection.length + 1),
						position: String(position + 1)
					})
				};
				if (sameCollection) {
					const order = collection.map((candidate) => candidate.id);
					order.splice(position, 0, node.id);
					option.order = order;
				}
				destinations.push(option);
			}
		}
		return destinations;
	}
	#moveCollections(node) {
		const document = this.document;
		if (document === void 0) return [];
		const collections = [{
			collection: document.roots,
			label: this.#text("studio.shell/document-roots"),
			specificity: 0
		}];
		const stack = document.roots.map((node) => ({
			node,
			specificity: 1
		}));
		while (stack.length > 0) {
			const current = stack.shift();
			if (current === void 0) break;
			const { node: parent, specificity } = current;
			const definition = this.#findDefinition(parent);
			for (const children of Object.values(parent.slots)) stack.push(...children.map((node) => ({
				node,
				specificity: specificity + 1
			})));
			for (const slot of definition?.slots ?? []) {
				if (!slot.accepts.types.includes(node.type)) continue;
				collections.push({
					collection: parent.slots[slot.id] ?? [],
					label: this.#text("studio.shell/move-slot-collection", {
						parent: `${this.#nodeLabel(parent)} (${parent.id})`,
						slot: referenceText(slot.label)
					}),
					parentNodeId: parent.id,
					slot: slot.id,
					specificity
				});
			}
		}
		return collections;
	}
	#canMoveNodeTo(node, destination) {
		const document = this.document;
		const source = document === void 0 ? void 0 : findOutlineLocation(document.roots, node.id);
		if (document === void 0 || source === void 0 || !this.#permits("studio.command/move-node")) return false;
		const parentId = destination.parentNodeId;
		if (parentId === node.id || parentId !== void 0 && findAncestry([node], parentId).length > 0) return false;
		const sameCollection = source.parentNodeId === destination.parentNodeId && source.slot === destination.slot;
		if (!sameCollection && source.parentNodeId !== void 0 && source.slot !== void 0) {
			const sourceParent = findOutlineLocation(document.roots, source.parentNodeId)?.node;
			const sourceSlot = this.#findDefinition(sourceParent ?? node)?.slots.find((candidate) => candidate.id === source.slot);
			if (sourceParent === void 0 || sourceSlot === void 0 || source.collection.length - 1 < sourceSlot.minimum) return false;
		}
		if (parentId === void 0) {
			const postMoveLength = document.roots.length - (source.parentNodeId === void 0 ? 1 : 0);
			return this.#session?.mode !== "hybrid" && destination.position <= postMoveLength;
		}
		if (destination.slot === void 0) return false;
		const parent = findOutlineLocation(document.roots, parentId)?.node;
		const slot = (parent === void 0 ? void 0 : this.#findDefinition(parent))?.slots.find((candidate) => candidate.id === destination.slot);
		if (parent === void 0 || slot?.accepts.types.includes(node.type) !== true) return false;
		const postMoveLength = (parent.slots[destination.slot] ?? []).length - (sameCollection ? 1 : 0);
		if (destination.position > postMoveLength || postMoveLength + 1 > slot.maximum) return false;
		if (this.#session?.mode !== "hybrid") return true;
		if (source.parentNodeId === void 0 || source.slot === void 0 || this.#subtreeContainsLockedNode(node) || !this.#isComposableSlot(parent, destination.slot)) return false;
		const sourceParent = findOutlineLocation(document.roots, source.parentNodeId)?.node;
		if (sourceParent === void 0 || !this.#isComposableSlot(sourceParent, source.slot)) return false;
		return (parent.authoring.slots?.[destination.slot]?.allowedBlocks ?? parent.authoring.allowedBlocks)?.includes(node.type) !== false;
	}
	#moveNodeToOption(node, option) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#canMoveNodeTo(node, option.destination)) return;
		let command;
		if (option.order === void 0) command = {
			...this.#commandEnvelope(document, session),
			payload: {
				destination: structuredClone(option.destination),
				nodeId: node.id
			},
			type: "studio.command/move-node"
		};
		else {
			const payload = { order: [...option.order] };
			if (option.destination.parentNodeId !== void 0 && option.destination.slot !== void 0) {
				payload.parentNodeId = option.destination.parentNodeId;
				payload.slot = option.destination.slot;
			}
			command = {
				...this.#commandEnvelope(document, session),
				payload,
				type: "studio.command/reorder-children"
			};
		}
		if (this.#runShellCommand(command)) {
			this.#selectNode(node.id);
			this.#pendingFocusNodeId = node.id;
			this.#announce("studio.shell/announce-moved-to", {
				destination: option.label,
				label: this.#nodeLabel(node)
			});
		}
	}
	#moveOutlineFocus(origin, direction) {
		const entries = [...this.shadowRoot?.querySelectorAll("button.outline-entry") ?? []];
		const index = entries.findIndex((entry) => entry === origin);
		if (index === -1) return;
		entries[index + direction]?.focus();
	}
	#nodeLabel(node) {
		const definition = this.#findDefinition(node);
		return definition === void 0 ? node.type : referenceText(definition.label);
	}
	#onCanvasPointerCancel(event) {
		if (this.#drag?.pointerId === event.pointerId) this.#cancelDrag();
	}
	#onCanvasPointerMove(event) {
		const drag = this.#drag;
		if (drag === void 0) return;
		if (event.pointerId !== drag.pointerId) return;
		drag.active = true;
		const index = this.#resolveDragIndex(event, drag);
		if (index !== void 0) drag.targetIndex = index;
		this.requestUpdate();
	}
	#onCanvasPointerUp(event) {
		const drag = this.#drag;
		if (drag === void 0) return;
		if (event.pointerId !== drag.pointerId) return;
		this.#drag = void 0;
		this.#releaseDragCapture(drag);
		if (!drag.active) return;
		this.requestUpdate();
		if (drag.targetIndex === drag.sourceIndex) {
			this.#announce("studio.shell/announce-drag-cancelled", { label: drag.label });
			return;
		}
		const session = this.#session;
		const document = this.document;
		const dragged = document === void 0 ? void 0 : findOutlineLocation(document.roots, drag.nodeId)?.node;
		if (session === void 0 || document === void 0 || dragged === void 0 || !this.#canMutateNode(dragged, "studio.command/reorder-children")) return;
		const order = [...drag.order];
		const [movedId] = order.splice(drag.sourceIndex, 1);
		if (movedId === void 0) return;
		order.splice(drag.targetIndex, 0, movedId);
		const payload = { order };
		if (drag.parentNodeId !== void 0 && drag.slot !== void 0) {
			payload.parentNodeId = drag.parentNodeId;
			payload.slot = drag.slot;
		}
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/reorder-children"
		};
		if (this.#runShellCommand(command)) {
			this.#selectNode(drag.nodeId);
			this.#announce("studio.shell/announce-dropped", {
				count: String(drag.order.length),
				label: drag.label,
				position: String(drag.targetIndex + 1)
			});
		}
	}
	/**
	* Starts tracking a possible chip drag. The drag only becomes active on the
	* first pointer move, so a plain press-and-release stays an ordinary click.
	* Read-only sessions and single-child collections never start tracking.
	*/
	#onChipPointerDown(event, node) {
		const document = this.document;
		if (document === void 0 || this.#session === void 0 || !this.#canMutateNode(node, "studio.command/reorder-children") || event.button !== 0 || this.#drag !== void 0) return;
		const location = findOutlineLocation(document.roots, node.id);
		if (location === void 0 || location.collection.length < 2) return;
		const drag = {
			active: false,
			label: this.#nodeLabel(node),
			nodeId: node.id,
			order: location.collection.map((sibling) => sibling.id),
			pointerId: event.pointerId,
			sourceIndex: location.index,
			targetIndex: location.index
		};
		if (location.parentNodeId !== void 0 && location.slot !== void 0) {
			drag.parentNodeId = location.parentNodeId;
			drag.slot = location.slot;
		}
		const chip = event.currentTarget;
		if (chip instanceof Element) {
			drag.capture = chip;
			try {
				chip.setPointerCapture(event.pointerId);
			} catch {}
		}
		this.#drag = drag;
	}
	/**
	* The shared keyboard contract of every inspector value input: Enter parses
	* the text as JSON and commits it through set-property (with the viewport
	* when the input edits an override); Escape reverts to the committed value
	* and announces the cancellation. Both success and failure re-align the
	* input with the document, so a rejected command never leaves optimistic
	* text behind while focus stays on the input.
	*/
	#onInspectorValueKeydown(event, node, property, viewport) {
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement)) return;
		if (event.key === "Enter") {
			event.preventDefault();
			const parsed = this.#parseJsonInput(input.value, property);
			if (parsed === void 0) return;
			this.#setNodeProperty(node, property, parsed.value, viewport);
			const current = this.#currentInspectorNode(node.id) ?? node;
			input.value = this.#serializedInspectorValue(current, property, viewport);
			return;
		}
		if (event.key === "Escape") {
			event.preventDefault();
			event.stopPropagation();
			const current = this.#currentInspectorNode(node.id) ?? node;
			input.value = this.#serializedInspectorValue(current, property, viewport);
			this.#announce("studio.shell/announce-edit-cancelled", { property });
		}
	}
	/**
	* A size-role select commits on change: the chosen role dispatches
	* set-size-role for the targeted context immediately. The placeholder is a
	* disabled option, so closing the picker without choosing — or choosing the
	* already-assigned role — dispatches nothing, and a rejected command snaps
	* the select back to the committed assignment.
	*/
	#onLayoutRoleChange(event, node, axis) {
		const select = event.currentTarget;
		if (!(select instanceof HTMLSelectElement)) return;
		const role = select.value;
		const viewport = this.#sizeRoleTargetViewport();
		if (role.length === 0 || role === this.#assignedSizeRole(node, axis, viewport)) return;
		if (!this.#setSizeRole(node, axis, role)) {
			const current = this.#currentInspectorNode(node.id) ?? node;
			select.value = this.#assignedSizeRole(current, axis, viewport) ?? "";
		}
	}
	/**
	* The fallback identifier input used when no theme size-role vocabulary is
	* available. Enter validates the text as a bounded lower-case identifier
	* and dispatches set-size-role; an invalid identifier announces the
	* rejection and dispatches nothing. Escape reverts to the committed
	* assignment and announces the cancellation.
	*/
	#onLayoutRoleInputKeydown(event, node, axis) {
		const input = event.currentTarget;
		if (!(input instanceof HTMLInputElement)) return;
		const viewport = this.#sizeRoleTargetViewport();
		if (event.key === "Enter") {
			event.preventDefault();
			const role = input.value.trim();
			if (!isSizeRoleIdentifier(role)) {
				this.#announce("studio.shell/announce-size-role-invalid", { axis: this.#axisText(axis) });
				return;
			}
			this.#setSizeRole(node, axis, role);
			const current = this.#currentInspectorNode(node.id) ?? node;
			input.value = this.#assignedSizeRole(current, axis, viewport) ?? "";
			return;
		}
		if (event.key === "Escape") {
			event.preventDefault();
			event.stopPropagation();
			const current = this.#currentInspectorNode(node.id) ?? node;
			input.value = this.#assignedSizeRole(current, axis, viewport) ?? "";
			this.#announce("studio.shell/announce-edit-cancelled", { property: this.#axisText(axis) });
		}
	}
	#onOutlineKeydown(event, node) {
		if (event.key === "ArrowUp" || event.key === "ArrowDown") {
			event.preventDefault();
			const direction = event.key === "ArrowUp" ? -1 : 1;
			if (event.altKey) this.#moveNode(node, direction);
			else this.#moveOutlineFocus(event.currentTarget, direction);
			return;
		}
		if (event.key === "Delete") {
			event.preventDefault();
			this.#deleteNode(node);
			return;
		}
		if ((event.key === "d" || event.key === "D") && (event.ctrlKey || event.metaKey)) {
			event.preventDefault();
			this.#duplicateNode(node);
		}
	}
	#onPaletteEntryKeydown(event) {
		if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;
		event.preventDefault();
		const buttons = this.#paletteResultButtons();
		const index = buttons.findIndex((button) => button === event.currentTarget);
		if (index === -1) return;
		if (event.key === "ArrowDown") {
			buttons[index + 1]?.focus();
			return;
		}
		if (index === 0) {
			this.shadowRoot?.querySelector(".command-palette input")?.focus();
			return;
		}
		buttons[index - 1]?.focus();
	}
	#onPaletteInputKeydown(event) {
		if (event.key === "ArrowDown") {
			event.preventDefault();
			this.#paletteResultButtons()[0]?.focus();
			return;
		}
		if (event.key === "Enter") {
			event.preventDefault();
			const first = this.#filteredPaletteEntries().find((entry) => !entry.disabled);
			if (first !== void 0) this.#runPaletteEntry(first);
		}
	}
	#onWorkspaceKeydown(event) {
		if ((event.key === "k" || event.key === "K") && (event.ctrlKey || event.metaKey)) {
			event.preventDefault();
			this.#togglePalette(event);
			return;
		}
		if (event.key === "Escape") {
			if (this.#cancelDrag()) {
				event.preventDefault();
				return;
			}
			if (this.paletteOpen === true) {
				event.preventDefault();
				this.#closePalette(true);
			}
		}
	}
	#orderedViewports() {
		return [...this.viewports ?? this.theme?.viewports ?? []].sort((left, right) => left.order - right.order);
	}
	#activeDesignControls() {
		return this.designControls ?? this.theme?.designControls;
	}
	#propertyTargetViewport() {
		const viewport = this.activeViewport;
		return viewport === void 0 || viewport.base ? void 0 : viewport;
	}
	#designControlProperty(definition, control) {
		return definition.propertyControls?.find((entry) => entry.control.endsWith(`/${control.id}`))?.property ?? control.id;
	}
	#applyRecipe(node, recipeId) {
		const document = this.document;
		const session = this.#session;
		const theme = this.theme;
		if (document === void 0 || session === void 0 || theme === void 0 || !this.#permits("studio.command/batch")) return;
		let operations;
		try {
			operations = recipeSelectionOperations(node, theme, recipeId);
		} catch (error) {
			this.#announce("studio.shell/announce-command-failed", { message: error instanceof Error ? error.message : String(error) });
			return;
		}
		const command = {
			...this.#commandEnvelope(document, session),
			payload: { operations },
			type: "studio.command/batch"
		};
		if (!this.#runShellCommand(command)) return;
		const recipe = theme.recipes.find((candidate) => candidate.id === recipeId);
		this.#announce("studio.shell/announce-recipe-applied", { recipe: recipe === void 0 ? recipeId : referenceText(recipe.label) });
	}
	#applyPattern(pattern) {
		const session = this.#session;
		const document = this.document;
		const destination = this.#patternDestination(pattern);
		if (session === void 0 || document === void 0 || destination === void 0) return;
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				destination,
				idMap: this.#allocatePatternIdMap(pattern),
				nodes: structuredClone(pattern.roots),
				pattern: {
					id: pattern.id,
					revision: pattern.revision,
					version: pattern.version
				}
			},
			type: "studio.command/apply-pattern"
		};
		if (this.#runShellCommand(command)) {
			const first = command.payload.idMap[pattern.roots[0]?.id ?? ""];
			if (first !== void 0) {
				this.#selectNode(first);
				this.#pendingFocusNodeId = first;
			}
			this.#announce("studio.shell/announce-pattern-applied", { pattern: referenceText(pattern.label) });
		}
	}
	#allocatePatternIdMap(pattern) {
		const taken = collectDocumentIds(this.document?.roots ?? []);
		const idMap = {};
		const queue = [...pattern.roots];
		while (queue.length > 0) {
			const current = queue.shift();
			if (current === void 0) break;
			let counter = 1;
			let candidate = `${current.id}-pattern-${counter}`;
			while (taken.has(candidate)) {
				counter += 1;
				candidate = `${current.id}-pattern-${counter}`;
			}
			taken.add(candidate);
			Object.defineProperty(idMap, current.id, {
				configurable: true,
				enumerable: true,
				value: candidate,
				writable: true
			});
			for (const children of Object.values(current.slots)) queue.push(...children);
		}
		return idMap;
	}
	#patternDestination(pattern) {
		if (!this.#permits("studio.command/apply-pattern") || pattern.roots.length === 0) return;
		const definitions = this.#activeDefinitions();
		const pending = [...pattern.roots];
		while (pending.length > 0) {
			const node = pending.pop();
			if (node === void 0 || !definitions.some((definition) => definition.type === node.type && definition.version === node.version)) return;
			for (const children of Object.values(node.slots)) pending.push(...children);
		}
		const document = this.document;
		if (document === void 0) return;
		const selected = this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(document.roots, this.selectedNodeId)?.node;
		const selectedDefinition = selected === void 0 ? void 0 : this.#findDefinition(selected);
		if (selected !== void 0 && selectedDefinition !== void 0) for (const slot of selectedDefinition.slots) {
			const children = selected.slots[slot.id] ?? [];
			if (pattern.roots.every((root) => slot.accepts.types.includes(root.type)) && children.length + pattern.roots.length <= slot.maximum && (this.#session?.mode !== "hybrid" || this.#isComposableSlot(selected, slot.id) && pattern.roots.every((root) => {
				return (selected.authoring.slots?.[slot.id]?.allowedBlocks ?? selected.authoring.allowedBlocks)?.includes(root.type) !== false;
			}))) return {
				parentNodeId: selected.id,
				position: children.length,
				slot: slot.id
			};
		}
		return this.#session?.mode === "hybrid" ? void 0 : { position: document.roots.length };
	}
	/**
	* The executable command list for the palette. Selection-scoped structural
	* entries reuse the outline's dispatch paths and its exact disabled rules;
	* insert entries mirror the block palette. Labels are catalog strings so the
	* case-insensitive filter operates on localized text.
	*/
	#paletteEntries() {
		const session = this.#session;
		const document = this.document;
		const readOnly = this.#isReadOnly();
		const entries = [];
		const location = document === void 0 || this.selectedNodeId === void 0 ? void 0 : findOutlineLocation(document.roots, this.selectedNodeId);
		if (location !== void 0) {
			const node = location.node;
			const first = location.index === 0;
			const last = location.index === location.collection.length - 1;
			entries.push({
				disabled: !this.#canMutateNode(node, "studio.command/reorder-children") || first,
				id: "move-up",
				label: this.#text("studio.shell/move-up"),
				run: () => {
					this.#moveNode(node, -1);
				}
			}, {
				disabled: !this.#canMutateNode(node, "studio.command/reorder-children") || last,
				id: "move-down",
				label: this.#text("studio.shell/move-down"),
				run: () => {
					this.#moveNode(node, 1);
				}
			}, {
				disabled: !this.#canMutateNode(node, "studio.command/duplicate-node"),
				id: "duplicate",
				label: this.#text("studio.shell/duplicate"),
				run: () => {
					this.#duplicateNode(node);
				}
			}, {
				disabled: !this.#canMutateNode(node, "studio.command/remove-node"),
				id: "delete",
				label: this.#text("studio.shell/delete"),
				run: () => {
					this.#deleteNode(node);
				}
			});
			for (const destination of this.#moveDestinations(node)) entries.push({
				disabled: false,
				id: `move-to-${destination.id}`,
				label: this.#text("studio.shell/command-move-to", { destination: destination.label }),
				run: () => {
					this.#moveNodeToOption(node, destination);
				}
			});
		}
		entries.push({
			disabled: readOnly || !this.#permits("studio.command/restore-node") || this.#lastRestorableNode() === void 0,
			id: "restore-last-deleted",
			label: this.#text("studio.shell/restore-last-deleted"),
			run: () => {
				this.#restoreLastNode();
			}
		}, {
			disabled: readOnly || session?.canUndo !== true,
			id: "undo",
			label: this.#text("studio.shell/undo"),
			run: () => {
				this.undo();
			}
		}, {
			disabled: readOnly || session?.canRedo !== true,
			id: "redo",
			label: this.#text("studio.shell/redo"),
			run: () => {
				this.redo();
			}
		}, {
			disabled: location === void 0,
			id: "clear-selection",
			label: this.#text("studio.shell/command-clear-selection"),
			run: () => {
				this.#session?.clearSelection();
				this.selectedNodeId = void 0;
				this.#previewSurface?.selectNode(void 0);
				this.#announce("studio.shell/announce-selection-cleared");
			}
		});
		for (const definition of this.#activeDefinitions()) entries.push({
			disabled: !this.#canInsertDefinition(definition),
			id: `insert-${definition.type}@${definition.version}`,
			label: this.#text("studio.shell/command-insert", { label: referenceText(definition.label) }),
			run: () => {
				this.#insertDefinition(definition);
			}
		});
		for (const pattern of this.#activePatterns()) entries.push({
			disabled: this.#patternDestination(pattern) === void 0,
			id: `apply-pattern-${pattern.id}`,
			label: this.#text("studio.shell/command-apply-pattern", { pattern: referenceText(pattern.label) }),
			run: () => {
				this.#applyPattern(pattern);
			}
		});
		return entries;
	}
	#paletteResultButtons() {
		return [...this.shadowRoot?.querySelectorAll("button.command-entry") ?? []].filter((button) => !button.disabled);
	}
	/**
	* Parses one inspector input as JSON. A parse failure announces the
	* invalid-value message naming the edited field and returns undefined so
	* the caller dispatches nothing; the wrapper object distinguishes a parsed
	* null from a failed parse.
	*/
	#parseJsonInput(text, label) {
		try {
			return { value: JSON.parse(text) };
		} catch {
			this.#announce("studio.shell/announce-invalid-value", { label });
			return;
		}
	}
	/**
	* Announces a preview lifecycle message through the single polite live
	* region. The region is a single slot, so deterministic ordering matters:
	* when an operation-outcome announcement from the same tick is still
	* waiting to render, the lifecycle text is queued and takes the slot on
	* the update after the outcome has rendered — queued after, never instead.
	* With no announcement in flight it is announced immediately. A queued
	* text identical to the one already showing is dropped, since re-rendering
	* identical text would not be re-announced anyway.
	*/
	#queuePreviewAnnouncement(key, parameters) {
		if (this.#announcementPending && this.isUpdatePending) {
			this.#pendingPreviewAnnouncements.push(messageText$1(key, this.messages, parameters));
			return;
		}
		this.#announce(key, parameters);
	}
	#rebuildRegistry() {
		const registry = new BlockRegistry();
		for (const definition of this.#activeDefinitions()) try {
			registry.register(definition);
		} catch {}
		this.#registry = registry;
	}
	#rebuildSession() {
		if (this.document === void 0) {
			this.#session = void 0;
			this.#sessionGeneration = "";
		} else {
			const generation = this.configuration?.session.sessionGeneration ?? this.document.revision;
			const options = {
				document: this.document,
				sessionGeneration: generation,
				sessionState: this.configuration?.session.sessionState ?? "editable"
			};
			if (this.configuration !== void 0) options.mode = resolveSessionMode(this.configuration.session);
			const maximumHistoryEntries = this.configuration?.session.limits.maxHistoryEntries;
			if (maximumHistoryEntries !== void 0) options.maximumHistoryEntries = maximumHistoryEntries;
			this.#session = new StudioSession(options);
			this.#sessionGeneration = generation;
		}
		this.#drag = void 0;
		this.selectedNodeId = void 0;
		this.#previewSurface?.selectNode(void 0);
		this.#syncDirty();
	}
	#permits(type) {
		const session = this.#session;
		return session !== void 0 && permittedCommandTypes(session.mode).has(type);
	}
	#releaseDragCapture(drag) {
		try {
			drag.capture?.releasePointerCapture(drag.pointerId);
		} catch {}
	}
	#removeBinding(node, port) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/remove-binding")) return;
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				nodeId: node.id,
				port
			},
			type: "studio.command/remove-binding"
		};
		if (this.#runShellCommand(command)) this.#announce("studio.shell/announce-binding-removed", { port });
	}
	#resetInheritedProperty(node, property) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/reset-inherited-property") || Object.keys(node.responsive?.[property] ?? {}).length === 0) return;
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				nodeId: node.id,
				property
			},
			type: "studio.command/reset-inherited-property"
		};
		if (this.#runShellCommand(command)) this.#announce("studio.shell/announce-inheritance-reset", { property });
	}
	#renderBreadcrumb() {
		const roots = this.document?.roots;
		if (roots === void 0 || this.selectedNodeId === void 0) return A;
		const ancestry = findAncestry(roots, this.selectedNodeId);
		if (ancestry.length === 0) return A;
		return b`
      <nav class="breadcrumb" aria-label=${this.#text("studio.shell/breadcrumb-label")}>
        <ol>
          ${ancestry.map((node, index) => index === ancestry.length - 1 ? b`<li>
                  <span class="breadcrumb-current" aria-current="true">
                    ${this.#nodeLabel(node)}
                  </span>
                </li>` : b`<li>
                  <button
                    type="button"
                    class="breadcrumb-entry"
                    data-node-id=${node.id}
                    @click=${() => {
			this.#selectNode(node.id);
		}}
                  >
                    ${this.#nodeLabel(node)}
                  </button>
                </li>`)}
        </ol>
      </nav>
    `;
	}
	#renderCanvasNode(node) {
		const definition = this.#findDefinition(node);
		const nested = Object.entries(node.slots);
		return b`
      <li>
        <button
          type="button"
          class="canvas-chip"
          data-node-id=${node.id}
          aria-pressed=${this.selectedNodeId === node.id ? "true" : "false"}
          @click=${() => {
			this.#selectNode(node.id);
		}}
          @pointerdown=${(event) => {
			this.#onChipPointerDown(event, node);
		}}
        >
          ${definition === void 0 ? node.type : referenceText(definition.label)}
        </button>
        ${nested.map(([slot, children]) => b`
            <section class="node-children" aria-label=${slot}>
              <ul class="tree">
                ${children.map((child) => this.#renderCanvasNode(child))}
              </ul>
            </section>
          `)}
      </li>
    `;
	}
	/**
	* The palette is deliberately not an ARIA combobox: it is a labelled region
	* holding a labelled filter input and a list of real, natively focusable
	* buttons. Arrow keys move between the input and the enabled results, Enter
	* activates, Tab leaves in document order — behavior documented in
	* docs/experience/keyboard.md.
	*/
	#renderCommandPalette() {
		if (this.paletteOpen !== true) return A;
		const entries = this.#filteredPaletteEntries();
		return b`
      <section
        class="command-palette"
        aria-label=${this.#text("studio.shell/command-palette-label")}
      >
        <input
          type="text"
          aria-label=${this.#text("studio.shell/command-palette-input-label")}
          .value=${this.paletteFilter ?? ""}
          @input=${(event) => {
			const target = event.currentTarget;
			if (target instanceof HTMLInputElement) this.paletteFilter = target.value;
		}}
          @keydown=${(event) => {
			this.#onPaletteInputKeydown(event);
		}}
        />
        <p class="hint">${this.#text("studio.shell/command-palette-hint")}</p>
        ${entries.length === 0 ? b`<p class="command-empty">${this.#text("studio.shell/command-palette-empty")}</p>` : b`
                <ul
                  class="command-results"
                  aria-label=${this.#text("studio.shell/command-palette-results-label")}
                >
                  ${entries.map((entry) => b`
                      <li>
                        <button
                          type="button"
                          class="command-entry"
                          data-command-id=${entry.id}
                          ?disabled=${entry.disabled}
                          @click=${() => {
			this.#runPaletteEntry(entry);
		}}
                          @keydown=${(event) => {
			this.#onPaletteEntryKeydown(event);
		}}
                        >
                          ${entry.label}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
      </section>
    `;
	}
	#renderDiagnostic(entry) {
		const severity = b`<span class="diagnostic-severity">
      ${this.#text(SEVERITY_MESSAGE_KEYS[entry.severity])}
    </span>`;
		const message = diagnosticText(entry);
		const nodeId = entry.location?.nodeId;
		return b`
      <li data-diagnostic-code=${entry.code}>
        ${nodeId === void 0 ? b`<span class="diagnostic-text">${severity} ${message}</span>` : b`
                <button
                  type="button"
                  class="diagnostic-entry"
                  data-node-id=${nodeId}
                  @click=${() => {
			this.#revealDiagnosticNode(nodeId);
		}}
                >
                  ${severity} ${message}
                </button>
              `}
      </li>
    `;
	}
	/** Textual drop-position readout shown while a canvas chip drag is active. */
	#renderDropIndicator() {
		const drag = this.#drag;
		if (drag?.active !== true) return A;
		return b`
      <p class="drop-indicator">
        ${this.#text("studio.shell/drag-drop-position", {
			count: String(drag.order.length),
			label: drag.label,
			position: String(drag.targetIndex + 1)
		})}
      </p>
    `;
	}
	/**
	* The editable inspector: contract facts, then keyboard-complete editors
	* for base properties, bindings, and active-viewport overrides. Tab order
	* follows the DOM order documented in docs/experience/keyboard.md. Each
	* mutating section derives its disabled state from the canonical session
	* mode; read-only sessions additionally state the reason textually.
	*/
	#renderInspector(node) {
		const readOnly = this.#isReadOnly();
		return b`
      <dl>
        <div>
          <dt>${this.#text("studio.shell/inspector-identifier")}</dt>
          <dd>${node.id}</dd>
        </div>
        <div>
          <dt>${this.#text("studio.shell/inspector-type")}</dt>
          <dd>${node.type}@${node.version}</dd>
        </div>
      </dl>
      ${readOnly ? b`<p class="hint inspector-read-only">
              ${this.#text("studio.shell/inspector-read-only")}
            </p>` : b`<p class="hint">${this.#text("studio.shell/inspector-hint")}</p>`}
      ${this.#renderInspectorRecipes(node, !this.#permits("studio.command/batch"))}
      ${this.#renderInspectorDesign(node, !this.#permits("studio.command/set-property"))}
      ${this.#renderInspectorProperties(node, !this.#permits("studio.command/set-property"))}
      ${this.#renderInspectorAuthoringControls(node, readOnly)}
      ${this.#renderInspectorResourceBindings(node, !this.#permits("studio.command/set-binding"))}
      ${this.#renderInspectorBindings(node, !this.#permits("studio.command/set-binding"))}
      ${this.#renderInspectorOverrides(node, !this.#permits("studio.command/set-property"))}
      ${this.#renderInspectorLayout(node, !this.#permits("studio.command/set-size-role"))}
    `;
	}
	/**
	* Studio-owned custom fields are rendered as stable holders and mounted in
	* `updated()`. The imperative editor/library lifecycle therefore remains
	* behind the authoring registry instead of becoming part of Lit templates or
	* the public shell contract.
	*/
	#renderInspectorAuthoringControls(node, readOnly) {
		const targets = this.#inspectorAuthoringTargets(node, readOnly);
		if (targets.length === 0) return A;
		return b`
      <section class="inspector-section inspector-authoring" aria-label="Studio authoring controls">
        <h3>Authoring</h3>
        <ul class="inspector-rows">
          ${targets.map((target) => b`
              <li
                class="inspector-authoring-row"
                data-authoring-kind=${target.kind}
                data-authoring-name=${target.name}
              >
                <span class="inspector-name">${target.label}</span>
                <div
                  class="inspector-authoring-control"
                  data-authoring-key=${target.key}
                  data-authoring-control=${target.control}
                ></div>
              </li>
            `)}
        </ul>
      </section>
    `;
	}
	#inspectorAuthoringTargets(node, readOnly) {
		const definition = this.#findDefinition(node);
		if (definition === void 0) return [];
		const targets = [];
		for (const propertyControl of definition.propertyControls ?? []) {
			if (!isStudioAuthoringControlId(propertyControl.control)) continue;
			const value = propertyControl.control === STUDIO_AUTHORING_CONTROL_IDS.scopedCss ? defaultAuthoringControlValue(propertyControl.control) : node.properties[propertyControl.property] ?? defaultAuthoringControlValue(propertyControl.control);
			targets.push({
				control: propertyControl.control,
				key: `${node.id}:property:${propertyControl.property}`,
				kind: "property",
				label: propertyControl.label === void 0 ? propertyControl.help === void 0 ? propertyControl.property : referenceText(propertyControl.help) : referenceText(propertyControl.label),
				name: propertyControl.property,
				nodeId: node.id,
				readOnly,
				value
			});
		}
		for (const port of definition.ports) {
			const metadata = port.authoring;
			if (metadata?.control === void 0 || !isStudioAuthoringControlId(metadata.control)) continue;
			const binding = node.bindings[port.id];
			const value = binding?.source.kind === "static-value" ? binding.source.value : defaultAuthoringControlValue(metadata.control);
			targets.push({
				...binding === void 0 ? {} : { binding },
				control: metadata.control,
				key: `${node.id}:port:${port.id}`,
				kind: "port",
				label: referenceText(port.label) || port.id,
				name: port.id,
				nodeId: node.id,
				...metadata.profile === void 0 ? {} : { profile: metadata.profile },
				readOnly: readOnly || metadata.readOnly === true || binding !== void 0 && binding.source.kind !== "static-value",
				value
			});
		}
		return targets;
	}
	async #synchronizeAuthoringControls() {
		if (!this.isConnected || this.shadowRoot === null) {
			this.#destroyAuthoringControls();
			return;
		}
		const node = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : this.#currentInspectorNode(this.selectedNodeId);
		const targets = node === void 0 ? [] : this.#inspectorAuthoringTargets(node, this.#isReadOnly());
		const holders = /* @__PURE__ */ new Map();
		for (const holder of this.shadowRoot.querySelectorAll("[data-authoring-key]")) {
			const key = holder.dataset.authoringKey;
			if (key !== void 0) holders.set(key, holder);
		}
		const expected = new Set(targets.map((target) => target.key));
		for (const [key, mounted] of this.#authoringControls) if (!expected.has(key) || holders.get(key) !== mounted.holder) this.#destroyAuthoringControl(key, mounted);
		for (const key of [...this.#authoringDiagnostics.keys()]) if (!expected.has(key)) this.#authoringDiagnostics.delete(key);
		const registry = this.authoringControlRegistry ?? this.#defaultAuthoringControlRegistry;
		for (const target of targets) {
			const holder = holders.get(target.key);
			if (holder === void 0) continue;
			const signature = authoringTargetSignature(target);
			const mounted = this.#authoringControls.get(target.key);
			if (mounted?.holder === holder && mounted.signature === signature) continue;
			const restoreFocus = mounted !== void 0 && this.shadowRoot.activeElement !== null && mounted.holder.contains(this.shadowRoot.activeElement);
			if (mounted !== void 0) this.#destroyAuthoringControl(target.key, mounted);
			holder.replaceChildren();
			try {
				const options = {
					...target.binding === void 0 ? {} : { binding: target.binding },
					holder,
					onChange: (change) => {
						this.#acceptAuthoringControlChange(target, change);
					},
					...target.profile === void 0 ? {} : { profile: target.profile },
					readOnly: target.readOnly,
					usage: "studio.media/content",
					value: structuredClone(target.value)
				};
				const handle = await registry.mount(target.control, options);
				const currentNode = this.#currentInspectorNode(target.nodeId);
				const currentTarget = currentNode ? this.#inspectorAuthoringTargets(currentNode, this.#isReadOnly()).find((candidate) => candidate.key === target.key) : void 0;
				if (!holder.isConnected || currentTarget === void 0 || authoringTargetSignature(currentTarget) !== signature) {
					handle.destroy();
					continue;
				}
				this.#authoringControls.set(target.key, {
					handle,
					holder,
					signature
				});
				this.#setAuthoringDiagnostic(target.key, void 0);
				if (restoreFocus) handle.focus();
			} catch (error) {
				holder.replaceChildren(document.createTextNode(error instanceof Error ? `Control unavailable: ${error.message}` : "Control unavailable."));
				this.#setAuthoringDiagnostic(target.key, {
					code: "studio.authoring/control-unavailable",
					location: { nodeId: target.nodeId },
					message: {
						defaultMessage: `The ${target.label} authoring control is unavailable.`,
						key: "studio.authoring/control-unavailable"
					},
					parameters: {
						control: target.control,
						name: target.name
					},
					severity: "error"
				});
			}
		}
	}
	#acceptAuthoringControlChange(target, change) {
		const node = this.#currentInspectorNode(target.nodeId);
		if (node === void 0 || target.readOnly) return;
		if (!change.valid) {
			this.#setAuthoringDiagnostic(target.key, {
				code: "studio.authoring/invalid-control-value",
				location: { nodeId: node.id },
				message: {
					defaultMessage: `${target.label} contains an invalid value.`,
					key: "studio.authoring/invalid-control-value"
				},
				parameters: {
					control: target.control,
					name: target.name
				},
				severity: "error"
			});
			return;
		}
		let applied;
		if (target.kind === "property") {
			const value = toJsonValue(change.value);
			if (value === void 0) {
				this.#setAuthoringValueDiagnostic(target, node.id);
				return;
			}
			if (target.control === STUDIO_AUTHORING_CONTROL_IDS.scopedCss) {
				this.dispatchEvent(new CustomEvent("studio-scoped-style-change", {
					bubbles: true,
					composed: true,
					detail: {
						nodeId: node.id,
						value
					}
				}));
				applied = true;
			} else applied = this.#setNodeProperty(node, target.name, value, void 0);
		} else applied = this.#setAuthoringPortValue(node, target.name, change.value);
		if (!applied) return;
		this.#setAuthoringDiagnostic(target.key, void 0);
		const current = this.#currentInspectorNode(node.id);
		const updatedTarget = current ? this.#inspectorAuthoringTargets(current, this.#isReadOnly()).find((candidate) => candidate.key === target.key) : void 0;
		const mounted = this.#authoringControls.get(target.key);
		if (mounted !== void 0 && updatedTarget !== void 0) mounted.signature = authoringTargetSignature(updatedTarget);
	}
	#setAuthoringPortValue(node, port, input) {
		const current = node.bindings[port];
		if (current !== void 0 && current.source.kind !== "static-value") return false;
		if (input === void 0) {
			if (current === void 0) return true;
			this.#removeBinding(node, port);
			return this.#currentInspectorNode(node.id)?.bindings[port] === void 0;
		}
		const value = toJsonValue(input);
		if (value === void 0) return false;
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-binding")) return false;
		const binding = current === void 0 ? {
			onError: "error",
			onNull: "empty",
			source: {
				kind: "static-value",
				value
			},
			transforms: []
		} : {
			...current,
			source: {
				kind: "static-value",
				value
			}
		};
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				binding,
				nodeId: node.id,
				port
			},
			type: "studio.command/set-binding"
		};
		if (!this.#runShellCommand(command)) return false;
		this.#announce("studio.shell/announce-binding-set", { port });
		return true;
	}
	#setAuthoringValueDiagnostic(target, nodeId) {
		this.#setAuthoringDiagnostic(target.key, {
			code: "studio.authoring/non-canonical-control-value",
			location: { nodeId },
			message: {
				defaultMessage: `${target.label} did not produce bounded canonical JSON.`,
				key: "studio.authoring/non-canonical-control-value"
			},
			parameters: {
				control: target.control,
				name: target.name
			},
			severity: "error"
		});
	}
	#setAuthoringDiagnostic(key, diagnostic) {
		const previous = this.#authoringDiagnostics.get(key);
		if (diagnostic === void 0) {
			if (previous === void 0) return;
			this.#authoringDiagnostics.delete(key);
			this.requestUpdate();
			return;
		}
		if (previous?.code === diagnostic.code && previous.message.defaultMessage === diagnostic.message.defaultMessage) return;
		this.#authoringDiagnostics.set(key, diagnostic);
		this.requestUpdate();
	}
	#destroyAuthoringControl(key, mounted) {
		try {
			mounted.handle.destroy();
		} catch {}
		this.#authoringControls.delete(key);
	}
	#destroyAuthoringControls() {
		for (const [key, mounted] of this.#authoringControls) this.#destroyAuthoringControl(key, mounted);
		this.#authoringDiagnostics.clear();
	}
	/**
	* Resource-valued ports use a dedicated search/browser surface rather than
	* the legacy raw binding field. Discovery stays useful in read-only mode;
	* only a port that explicitly permits authoring can select a canonical
	* resource reference.
	*/
	#renderInspectorResourceBindings(node, readOnly) {
		const targets = this.#inspectorResourceBindingTargets(node, readOnly);
		if (targets.length === 0) return A;
		const browserAvailable = this.resourceSearchService !== void 0 && this.#resourcePortAdvertised();
		return b`
      <section class="inspector-section inspector-resource-bindings" aria-label="Resource bindings">
        <h3>Resources</h3>
        <ul class="inspector-rows">
          ${targets.map((target) => b`
              <li class="inspector-row" data-resource-port=${target.port}>
                <span class="inspector-name">${target.label}</span>
                ${browserAvailable ? b`<div
                        class="inspector-resource-control"
                        data-resource-authoring-key=${target.key}
                      ></div>` : b`<p class="inspector-binding-status resource-browser-unavailable">
                        Resource browsing is unavailable in this
                        session.${target.binding === void 0 ? "" : ` The stored ${target.binding.source.kind} binding remains unchanged.`}
                      </p>`}
              </li>
            `)}
        </ul>
      </section>
    `;
	}
	#inspectorResourceBindingTargets(node, readOnly) {
		const definition = this.#findDefinition(node);
		if (definition === void 0) return [];
		return definition.ports.filter((port) => port.valueType === "resource").map((port) => {
			const binding = node.bindings[port.id];
			return {
				...binding === void 0 ? {} : { binding },
				key: `resource:${node.id}:${port.id}`,
				label: referenceText(port.label) || port.id,
				multiple: port.multiple,
				nodeId: node.id,
				port: port.id,
				readOnly: readOnly || port.authoring?.readOnly === true || binding !== void 0 && binding.source.kind !== "resource-reference"
			};
		});
	}
	#synchronizeResourceBindingControls() {
		const service = this.resourceSearchService;
		if (!this.isConnected || this.shadowRoot === null || service === void 0 || !this.#resourcePortAdvertised()) {
			this.#destroyResourceBindingControls();
			return;
		}
		const node = this.document === void 0 || this.selectedNodeId === void 0 ? void 0 : this.#currentInspectorNode(this.selectedNodeId);
		const targets = node === void 0 ? [] : this.#inspectorResourceBindingTargets(node, this.#isReadOnly());
		const holders = /* @__PURE__ */ new Map();
		for (const holder of this.shadowRoot.querySelectorAll("[data-resource-authoring-key]")) {
			const key = holder.dataset.resourceAuthoringKey;
			if (key !== void 0) holders.set(key, holder);
		}
		const expected = new Set(targets.map((target) => target.key));
		for (const [key, mounted] of this.#resourceBindingControls) if (!expected.has(key) || holders.get(key) !== mounted.holder) this.#destroyResourceBindingControl(key, mounted);
		let removedDiagnostic = false;
		for (const key of [...this.#authoringDiagnostics.keys()]) if (key.startsWith("resource:") && !expected.has(key)) {
			this.#authoringDiagnostics.delete(key);
			removedDiagnostic = true;
		}
		if (removedDiagnostic) this.requestUpdate();
		for (const target of targets) {
			const holder = holders.get(target.key);
			if (holder === void 0) continue;
			const signature = resourceBindingTargetSignature(target);
			const mounted = this.#resourceBindingControls.get(target.key);
			if (mounted?.holder === holder && mounted.signature === signature) continue;
			const restoreFocus = mounted !== void 0 && this.shadowRoot.activeElement !== null && mounted.holder.contains(this.shadowRoot.activeElement);
			if (mounted !== void 0) this.#destroyResourceBindingControl(target.key, mounted);
			holder.replaceChildren();
			try {
				const handle = mountStudioResourceBindingControl({
					...target.binding === void 0 ? {} : { binding: target.binding },
					holder,
					label: target.label,
					multiple: target.multiple,
					onChange: (change) => this.#acceptResourceBindingChange(target, change),
					readOnly: target.readOnly,
					service
				});
				const currentNode = this.#currentInspectorNode(target.nodeId);
				const currentTarget = currentNode ? this.#inspectorResourceBindingTargets(currentNode, this.#isReadOnly()).find((candidate) => candidate.key === target.key) : void 0;
				if (!holder.isConnected || currentTarget === void 0 || resourceBindingTargetSignature(currentTarget) !== signature) {
					handle.destroy();
					continue;
				}
				this.#resourceBindingControls.set(target.key, {
					handle,
					holder,
					signature
				});
				this.#setAuthoringDiagnostic(target.key, void 0);
				if (restoreFocus) handle.focus();
			} catch {
				holder.replaceChildren(document.createTextNode("Resource browser is unavailable."));
				this.#setAuthoringDiagnostic(target.key, {
					code: "studio.authoring/resource-control-unavailable",
					location: { nodeId: target.nodeId },
					message: {
						defaultMessage: `The ${target.label} resource browser is unavailable.`,
						key: "studio.authoring/resource-control-unavailable"
					},
					parameters: { port: target.port },
					severity: "error"
				});
			}
		}
	}
	#acceptResourceBindingChange(target, change) {
		const node = this.#currentInspectorNode(target.nodeId);
		if (node === void 0 || target.readOnly) return;
		const currentTarget = this.#inspectorResourceBindingTargets(node, this.#isReadOnly()).find((candidate) => candidate.key === target.key);
		if (currentTarget === void 0 || currentTarget.readOnly) return;
		let applied;
		if (change.source === void 0) {
			if (node.bindings[target.port] === void 0) return;
			this.#removeBinding(node, target.port);
			applied = this.#currentInspectorNode(node.id)?.bindings[target.port] === void 0;
		} else applied = this.#setResourceReferenceBinding(node, target.port, change.source);
		if (!applied) return;
		this.#setAuthoringDiagnostic(target.key, void 0);
		const updatedNode = this.#currentInspectorNode(node.id);
		const updatedTarget = updatedNode ? this.#inspectorResourceBindingTargets(updatedNode, this.#isReadOnly()).find((candidate) => candidate.key === target.key) : void 0;
		const mounted = this.#resourceBindingControls.get(target.key);
		if (mounted !== void 0 && updatedTarget !== void 0) mounted.signature = resourceBindingTargetSignature(updatedTarget);
	}
	#setResourceReferenceBinding(node, port, source) {
		if (!isStudioResourceReference(source)) return false;
		const current = node.bindings[port];
		if (current !== void 0 && current.source.kind !== "resource-reference") return false;
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-binding")) return false;
		const binding = {
			onError: "error",
			onNull: "empty",
			source: structuredClone(source),
			transforms: []
		};
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				binding,
				nodeId: node.id,
				port
			},
			type: "studio.command/set-binding"
		};
		if (!this.#runShellCommand(command)) return false;
		this.#announce("studio.shell/announce-binding-set", { port });
		return true;
	}
	#destroyResourceBindingControl(key, mounted) {
		try {
			mounted.handle.destroy();
		} catch {}
		this.#resourceBindingControls.delete(key);
	}
	#destroyResourceBindingControls() {
		for (const [key, mounted] of this.#resourceBindingControls) this.#destroyResourceBindingControl(key, mounted);
		let removedDiagnostic = false;
		for (const key of [...this.#authoringDiagnostics.keys()]) if (key.startsWith("resource:")) {
			this.#authoringDiagnostics.delete(key);
			removedDiagnostic = true;
		}
		if (removedDiagnostic && this.isConnected) this.requestUpdate();
	}
	/** Theme recipes are atomic command batches, never an untracked style mutation. */
	#renderInspectorRecipes(node, disabled) {
		const theme = this.theme;
		if (theme === void 0) return A;
		const recipes = theme.recipes.filter((recipe) => recipe.blockType === node.type);
		if (recipes.length === 0) return A;
		const selected = node.properties[RECIPE_MARKER_PROPERTY];
		return b`
      <section
        class="inspector-section inspector-recipes"
        aria-label=${this.#text("studio.shell/inspector-recipes-heading")}
      >
        <h3>${this.#text("studio.shell/inspector-recipes-heading")}</h3>
        <label class="inspector-row">
          <span class="inspector-name">${this.#text("studio.shell/inspector-recipe-label")}</span>
          <select
            class="inspector-recipe-select"
            data-current-value=${typeof selected === "string" ? selected : ""}
            ?disabled=${disabled}
            @change=${(event) => {
			const target = event.currentTarget;
			if (target instanceof HTMLSelectElement && target.value !== "") this.#applyRecipe(node, target.value);
		}}
          >
            <option value="" disabled .selected=${typeof selected !== "string"}>
              ${this.#text("studio.shell/inspector-recipe-placeholder")}
            </option>
            ${recipes.map((recipe) => b`
                <option value=${recipe.id} .selected=${selected === recipe.id}>
                  ${referenceText(recipe.label)}
                </option>
              `)}
          </select>
        </label>
      </section>
    `;
	}
	/** Typed theme controls bound to this block definition's declared vocabulary. */
	#renderInspectorDesign(node, disabled) {
		const definition = this.#findDefinition(node);
		const controls = this.#activeDesignControls();
		if (definition === void 0 || controls === void 0) return A;
		const declared = definition.themeControls.map((id) => controls.find((control) => control.id === id)).filter((control) => control !== void 0);
		if (declared.length === 0) return A;
		const viewport = this.#propertyTargetViewport();
		return b`
      <section
        class="inspector-section inspector-design"
        aria-label=${this.#text("studio.shell/inspector-design-heading")}
      >
        <h3>${this.#text("studio.shell/inspector-design-heading")}</h3>
        <ul class="inspector-rows">
          ${declared.map((control) => {
			const property = this.#designControlProperty(definition, control);
			const base = node.properties[property];
			const override = viewport === void 0 ? void 0 : node.responsive?.[property]?.[viewport.id];
			const effective = override ?? base;
			return b`
              <li class="inspector-row" data-control=${control.id}>
                <label class="inspector-name" for=${`design-${node.id}-${control.id}`}>
                  ${referenceText(control.label)}
                </label>
                <span class="inspector-provenance">
                  ${viewport === void 0 ? this.#text("studio.shell/inspector-provenance-base") : override === void 0 ? this.#text("studio.shell/inspector-provenance-inherited", { value: JSON.stringify(base) }) : this.#text("studio.shell/inspector-provenance-overridden", {
				value: JSON.stringify(override),
				viewport: referenceText(viewport.label)
			})}
                </span>
                <select
                  id=${`design-${node.id}-${control.id}`}
                  class="inspector-design-select"
                  data-current-value=${typeof effective === "string" ? effective : ""}
                  data-property=${property}
                  ?disabled=${disabled}
                  @change=${(event) => {
				const target = event.currentTarget;
				if (target instanceof HTMLSelectElement && target.value !== "") this.#setNodeProperty(node, property, target.value, viewport);
			}}
                >
                  <option value="" disabled .selected=${typeof effective !== "string"}>
                    ${this.#text("studio.shell/inspector-design-placeholder")}
                  </option>
                  ${control.choices.map((choice) => b`
                      <option value=${choice.id} .selected=${effective === choice.id}>
                        ${referenceText(choice.label)}
                      </option>
                    `)}
                </select>
                <button
                  type="button"
                  class="inspector-design-unset"
                  data-property=${property}
                  ?disabled=${disabled || (viewport === void 0 ? base : override) === void 0}
                  @click=${() => {
				this.#unsetNodeProperty(node, property, viewport);
			}}
                >
                  ${this.#text("studio.shell/inspector-design-unset")}
                </button>
              </li>
            `;
		})}
        </ul>
      </section>
    `;
	}
	#renderInspectorBindings(node, readOnly) {
		const projection = this.#bindingProjection?.nodes.find((entry) => entry.nodeId === node.id);
		if (projection === void 0) {
			if (this.#modelPortAdvertised()) return b`
          <section
            class="inspector-section inspector-bindings"
            aria-label=${this.#text("studio.shell/inspector-bindings-heading")}
          >
            <h3>${this.#text("studio.shell/inspector-bindings-heading")}</h3>
            <p class="inspector-empty inspector-binding-model-unavailable">
              ${this.#text("studio.shell/inspector-binding-model-unavailable")}
            </p>
          </section>
        `;
			return this.#renderLegacyInspectorBindings(node, readOnly);
		}
		const modelCompatible = !this.#bindingProjection?.diagnostics.some((entry) => entry.code.startsWith("studio.binding/model-"));
		const resourcePorts = new Set((this.#findDefinition(node)?.ports ?? []).filter((port) => port.valueType === "resource").map((port) => port.id));
		const projectedPorts = projection.ports.filter((port) => !resourcePorts.has(port.port));
		return b`
      <section
        class="inspector-section inspector-bindings"
        aria-label=${this.#text("studio.shell/inspector-bindings-heading")}
      >
        <h3>${this.#text("studio.shell/inspector-bindings-heading")}</h3>
        <p class="hint inspector-binding-model">
          ${this.#text("studio.shell/inspector-binding-model", { model: `${this.contentModel?.id ?? ""}@${this.contentModel?.version ?? ""}#${this.contentModel?.revision ?? ""}` })}
        </p>
        ${!modelCompatible ? b`<p class="inspector-empty inspector-binding-model-mismatch">
                ${this.#text("studio.shell/inspector-binding-model-mismatch")}
              </p>` : projectedPorts.length === 0 ? b`<p class="inspector-empty">
                  ${this.#text("studio.shell/inspector-bindings-empty")}
                </p>` : b`<ul class="inspector-rows">
                  ${projectedPorts.map((port) => this.#renderProjectedBindingPort(node, port, readOnly))}
                </ul>`}
      </section>
    `;
	}
	#renderProjectedBindingPort(node, projection, readOnly) {
		const declared = this.#findDefinition(node)?.ports.find((candidate) => candidate.id === projection.port);
		const boundPath = projection.boundFieldPath;
		const selectedValue = boundPath === void 0 ? "" : JSON.stringify(boundPath);
		const selected = projection.candidates.find((candidate) => JSON.stringify(candidate.fieldPath) === selectedValue);
		const label = declared === void 0 ? projection.port : referenceText(declared.label);
		return b`
      <li class="inspector-row inspector-binding-model" data-port=${projection.port}>
        <label class="inspector-name" for=${`binding-${node.id}-${projection.port}`}>
          ${label}
          ${projection.required === true ? this.#text("studio.shell/inspector-binding-required") : A}
        </label>
        ${projection.valueType === void 0 ? A : b`<span class="inspector-binding-status">
                ${this.#text("studio.shell/inspector-binding-accepts", {
			cardinality: projection.multiple === true ? "many" : "one",
			"value-type": projection.valueType
		})}
              </span>`}
        ${projection.status === "non-field-source" ? b`<span class="inspector-binding-status">
                ${this.#text("studio.shell/inspector-binding-non-field-source")}
              </span>` : projection.status === "invalid" ? b`<span class="inspector-binding-status">
                  ${this.#text("studio.shell/inspector-binding-invalid")}
                </span>` : A}
        <select
          id=${`binding-${node.id}-${projection.port}`}
          class="inspector-binding-field"
          data-port=${projection.port}
          data-current-value=${selectedValue}
          data-authoring-control=${selected?.control ?? A}
          ?disabled=${readOnly || projection.valueType === void 0 || projection.candidates.length === 0}
          @change=${(event) => {
			const target = event.currentTarget;
			if (!(target instanceof HTMLSelectElement) || target.value === "") return;
			const candidate = projection.candidates.find((entry) => JSON.stringify(entry.fieldPath) === target.value);
			if (candidate !== void 0) this.#setFieldBinding(node, projection.port, candidate);
		}}
        >
          <option value="" .selected=${selected === void 0}>
            ${projection.candidates.length === 0 ? this.#text("studio.shell/inspector-binding-no-compatible-fields") : this.#text("studio.shell/inspector-binding-field-placeholder")}
          </option>
          ${projection.candidates.map((candidate) => b`
              <option
                value=${JSON.stringify(candidate.fieldPath)}
                data-authoring-control=${candidate.control ?? A}
                .selected=${JSON.stringify(candidate.fieldPath) === selectedValue}
              >
                ${referenceText(candidate.label)} (${candidate.fieldPath.join(".")})
              </option>
            `)}
        </select>
        ${boundPath === void 0 ? A : b`<code class="inspector-binding-path">${boundPath.join(".")}</code>`}
        ${selected === void 0 ? A : this.#renderDeclaredFieldControl(selected)}
        ${projection.binding === void 0 ? A : b`<button
                type="button"
                class="inspector-binding-remove"
                data-port=${projection.port}
                aria-label=${this.#text("studio.shell/inspector-remove-binding-label", { port: projection.port })}
                ?disabled=${readOnly}
                @click=${() => {
			this.#removeBinding(node, projection.port);
		}}
              >
                ${this.#text("studio.shell/inspector-remove-binding")}
              </button>`}
      </li>
    `;
	}
	#renderDeclaredFieldControl(candidate) {
		const field = this.#fieldAtPath(candidate.fieldPath);
		const control = candidate.control;
		if (field === void 0 || control === void 0) return b`<div class="inspector-binding-control">
        <span class="inspector-binding-status">
          ${this.#text("studio.shell/inspector-binding-control-undeclared")}
        </span>
      </div>`;
		const label = referenceText(field.label);
		const controlLabel = this.#text("studio.shell/inspector-binding-control-label", {
			control,
			field: label
		});
		let rendered;
		switch (control) {
			case "studio.control/date":
				rendered = b`<input type="date" aria-label=${controlLabel} disabled />`;
				break;
			case "studio.control/date-time":
				rendered = b`<input type="datetime-local" aria-label=${controlLabel} disabled />`;
				break;
			case "studio.control/number":
				rendered = b`<input type="number" aria-label=${controlLabel} disabled />`;
				break;
			case "studio.control/select":
				rendered = b`<select aria-label=${controlLabel} disabled>
          <option>${this.#text("studio.shell/inspector-binding-control-preview")}</option>
          ${(field.enumValues ?? []).map((value) => b`<option value=${value.value}>${referenceText(value.label)}</option>`)}
        </select>`;
				break;
			case "studio.control/switch":
				rendered = b`<input type="checkbox" aria-label=${controlLabel} disabled />`;
				break;
			case "studio.control/multi-line-text":
				rendered = b`<textarea aria-label=${controlLabel} disabled></textarea>`;
				break;
			case "studio.control/single-line-text":
				rendered = b`<input
          type="text"
          aria-label=${controlLabel}
          placeholder=${field.authoring?.placeholder === void 0 ? A : referenceText(field.authoring.placeholder)}
          disabled
        />`;
				break;
			default: rendered = b`<span class="inspector-binding-status">
          ${this.#text("studio.shell/inspector-binding-control-unavailable", { control })}
        </span>`;
		}
		return b`<div
      class="inspector-binding-control"
      data-authoring-control=${control}
      aria-label=${controlLabel}
    >
      <span class="inspector-binding-status">${control}</span>
      ${rendered}
    </div>`;
	}
	#renderLegacyInspectorBindings(node, readOnly) {
		const declaredPorts = this.#findDefinition(node)?.ports ?? [];
		const resourcePorts = new Set(declaredPorts.filter((port) => port.valueType === "resource").map((port) => port.id));
		const entries = Object.entries(node.bindings).filter(([port]) => !resourcePorts.has(port));
		const showRawBindingForm = declaredPorts.length === 0 || declaredPorts.some((port) => port.valueType !== "resource");
		return b`
      <section
        class="inspector-section inspector-bindings"
        aria-label=${this.#text("studio.shell/inspector-bindings-heading")}
      >
        <h3>${this.#text("studio.shell/inspector-bindings-heading")}</h3>
        ${entries.length === 0 ? b`<p class="inspector-empty">
                ${this.#text("studio.shell/inspector-bindings-empty")}
              </p>` : b`
                <ul class="inspector-rows">
                  ${entries.map(([port, binding]) => b`
                      <li class="inspector-row">
                        <span class="inspector-name">${port}</span>
                        <code class="inspector-binding-value">${JSON.stringify(binding)}</code>
                        <button
                          type="button"
                          class="inspector-binding-remove"
                          data-port=${port}
                          aria-label=${this.#text("studio.shell/inspector-remove-binding-label", { port })}
                          ?disabled=${readOnly}
                          @click=${() => {
			this.#removeBinding(node, port);
		}}
                        >
                          ${this.#text("studio.shell/inspector-remove-binding")}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
        ${showRawBindingForm ? b`<div class="inspector-row inspector-set-binding-form">
                <input
                  type="text"
                  class="inspector-binding-port"
                  aria-label=${this.#text("studio.shell/inspector-binding-port-label")}
                  ?disabled=${readOnly}
                />
                <input
                  type="text"
                  class="inspector-binding-value-input"
                  aria-label=${this.#text("studio.shell/inspector-binding-value-label")}
                  ?disabled=${readOnly}
                />
                <button
                  type="button"
                  class="inspector-binding-set"
                  ?disabled=${readOnly}
                  @click=${() => {
			this.#setBinding(node);
		}}
                >
                  ${this.#text("studio.shell/inspector-set-binding")}
                </button>
              </div>` : A}
      </section>
    `;
	}
	/**
	* The layout section: one row per layout axis showing the base size-role
	* assignment, the active-viewport inheritance provenance, and the role
	* control. The role vocabulary comes from the theme document's `size-role`
	* design controls, fed to the shell over the same host path as the
	* viewport switcher. A theme that declares no size roles is stated
	* textually with no controls; with no theme vocabulary available at all
	* the editor falls back to a validated identifier input.
	*/
	#renderInspectorLayout(node, readOnly) {
		const vocabulary = this.#sizeRoleVocabulary();
		return b`
      <section
        class="inspector-section inspector-layout"
        aria-label=${this.#text("studio.shell/inspector-layout-heading")}
      >
        <h3>${this.#text("studio.shell/inspector-layout-heading")}</h3>
        ${vocabulary?.length === 0 ? b`<p class="inspector-empty layout-no-roles">
                ${this.#text("studio.shell/inspector-layout-no-roles")}
              </p>` : b`
                ${vocabulary === void 0 ? b`<p class="hint layout-fallback-hint">
                        ${this.#text("studio.shell/inspector-layout-fallback-hint")}
                      </p>` : A}
                <ul class="inspector-rows">
                  ${SIZE_ROLE_AXES.map((axis) => this.#renderLayoutAxis(node, axis, vocabulary, readOnly))}
                </ul>
              `}
      </section>
    `;
	}
	/**
	* The per-viewport override editor for the active viewport of the switcher.
	* Overrides dispatch the same set-property and unset-property commands as
	* base properties, carrying the viewport, and every announcement names the
	* viewport — the keyboard path that stands in for visual resize work.
	* Every listed value carries textual provenance: an overridden row names
	* the supplying viewport, and a base property without an override for the
	* active viewport is listed as inheriting from base.
	*/
	#renderInspectorOverrides(node, readOnly) {
		const viewport = this.activeViewport;
		if (viewport === void 0) return A;
		const viewportLabel = referenceText(viewport.label);
		const rows = [];
		for (const [property, base] of Object.entries(node.properties)) rows.push({
			base,
			override: node.responsive?.[property]?.[viewport.id],
			property
		});
		for (const [property, values] of Object.entries(node.responsive ?? {})) {
			if (Object.hasOwn(node.properties, property)) continue;
			const override = values[viewport.id];
			if (override !== void 0) rows.push({
				base: void 0,
				override,
				property
			});
		}
		return b`
      <section
        class="inspector-section inspector-overrides"
        aria-label=${this.#text("studio.shell/inspector-overrides-heading", { viewport: viewportLabel })}
      >
        <h3>
          ${this.#text("studio.shell/inspector-overrides-heading", { viewport: viewportLabel })}
        </h3>
        ${rows.length === 0 ? b`<p class="inspector-empty">
                ${this.#text("studio.shell/inspector-overrides-empty", { viewport: viewportLabel })}
              </p>` : b`
                <ul class="inspector-rows">
                  ${rows.map(({ base, override, property }) => override === void 0 ? b`
                          <li class="inspector-row inspector-inherited" data-property=${property}>
                            <span class="inspector-name">${property}</span>
                            <span class="inspector-provenance">
                              ${this.#text("studio.shell/inspector-provenance-inherited", { value: JSON.stringify(base) })}
                            </span>
                            <button
                              type="button"
                              class="inspector-inheritance-reset"
                              data-property=${property}
                              ?disabled=${readOnly || !this.#permits("studio.command/reset-inherited-property") || Object.keys(node.responsive?.[property] ?? {}).length === 0}
                              @click=${() => {
			this.#resetInheritedProperty(node, property);
		}}
                            >
                              ${this.#text("studio.shell/inspector-reset-inheritance")}
                            </button>
                          </li>
                        ` : b`
                          <li class="inspector-row">
                            <span class="inspector-name">${property}</span>
                            <span class="inspector-provenance">
                              ${this.#text("studio.shell/inspector-provenance-overridden", {
			value: JSON.stringify(override),
			viewport: viewportLabel
		})}
                            </span>
                            <input
                              type="text"
                              class="inspector-override-input"
                              data-property=${property}
                              aria-label=${this.#text("studio.shell/inspector-override-value-label", {
			property,
			viewport: viewportLabel
		})}
                              .value=${JSON.stringify(override)}
                              ?disabled=${readOnly}
                              @keydown=${(event) => {
			this.#onInspectorValueKeydown(event, node, property, viewport);
		}}
                            />
                            <button
                              type="button"
                              class="inspector-override-remove"
                              data-property=${property}
                              aria-label=${this.#text("studio.shell/inspector-remove-override-label", {
			property,
			viewport: viewportLabel
		})}
                              ?disabled=${readOnly}
                              @click=${() => {
			this.#unsetNodeProperty(node, property, viewport);
		}}
                            >
                              ${this.#text("studio.shell/inspector-remove-override")}
                            </button>
                            <button
                              type="button"
                              class="inspector-inheritance-reset"
                              data-property=${property}
                              ?disabled=${readOnly || !this.#permits("studio.command/reset-inherited-property")}
                              @click=${() => {
			this.#resetInheritedProperty(node, property);
		}}
                            >
                              ${this.#text("studio.shell/inspector-reset-inheritance")}
                            </button>
                          </li>
                        `)}
                </ul>
              `}
        <div class="inspector-row inspector-add-override-form">
          <input
            type="text"
            class="inspector-add-override-name"
            aria-label=${this.#text("studio.shell/inspector-add-override-name-label")}
            ?disabled=${readOnly}
          />
          <input
            type="text"
            class="inspector-add-override-value"
            aria-label=${this.#text("studio.shell/inspector-add-override-value-label")}
            ?disabled=${readOnly}
          />
          <button
            type="button"
            class="inspector-add-override-submit"
            ?disabled=${readOnly}
            @click=${() => {
			this.#addOverride(node, viewport);
		}}
          >
            ${this.#text("studio.shell/inspector-add-override")}
          </button>
        </div>
      </section>
    `;
	}
	#renderInspectorProperties(node, readOnly) {
		const customProperties = new Set((this.#findDefinition(node)?.propertyControls ?? []).filter((entry) => isStudioAuthoringControlId(entry.control)).map((entry) => entry.property));
		const entries = Object.entries(node.properties).filter(([property]) => !customProperties.has(property));
		return b`
      <section
        class="inspector-section inspector-properties"
        aria-label=${this.#text("studio.shell/inspector-properties")}
      >
        <h3>${this.#text("studio.shell/inspector-properties")}</h3>
        ${entries.length === 0 ? b`<p class="inspector-empty">
                ${this.#text("studio.shell/inspector-properties-empty")}
              </p>` : b`
                <ul class="inspector-rows">
                  ${entries.map(([property, value]) => b`
                      <li class="inspector-row">
                        <span class="inspector-name">${property}</span>
                        <span class="inspector-provenance">
                          ${this.#text("studio.shell/inspector-provenance-base")}
                        </span>
                        <input
                          type="text"
                          class="inspector-property-input"
                          data-property=${property}
                          aria-label=${this.#text("studio.shell/inspector-property-value-label", { property })}
                          .value=${JSON.stringify(value)}
                          ?disabled=${readOnly}
                          @keydown=${(event) => {
			this.#onInspectorValueKeydown(event, node, property, void 0);
		}}
                        />
                        <button
                          type="button"
                          class="inspector-property-unset"
                          data-property=${property}
                          aria-label=${this.#text("studio.shell/inspector-unset-label", { property })}
                          ?disabled=${readOnly}
                          @click=${() => {
			this.#unsetNodeProperty(node, property, void 0);
		}}
                        >
                          ${this.#text("studio.shell/inspector-unset")}
                        </button>
                      </li>
                    `)}
                </ul>
              `}
        <div class="inspector-row inspector-add-property-form">
          <input
            type="text"
            class="inspector-add-property-name"
            aria-label=${this.#text("studio.shell/inspector-add-property-name-label")}
            ?disabled=${readOnly}
          />
          <input
            type="text"
            class="inspector-add-property-value"
            aria-label=${this.#text("studio.shell/inspector-add-property-value-label")}
            ?disabled=${readOnly}
          />
          <button
            type="button"
            class="inspector-add-property-submit"
            ?disabled=${readOnly}
            @click=${() => {
			this.#addProperty(node);
		}}
          >
            ${this.#text("studio.shell/inspector-add-property")}
          </button>
        </div>
      </section>
    `;
	}
	/**
	* One layout row: the axis name, the base assignment as text, the
	* active-viewport provenance (overridden for that viewport, or inherited
	* from base) when the switcher is on a non-base viewport, then the role
	* control and its remove button. The control targets the base assignment
	* while the switcher is on the base viewport (or no viewports exist) and
	* the active viewport's override otherwise — the same base-versus-viewport
	* split the responsive property editor dispatches with.
	*/
	#renderLayoutAxis(node, axis, vocabulary, readOnly) {
		const axisLabel = this.#axisText(axis);
		const baseRole = node.sizeRoles?.[axis];
		const viewport = this.#sizeRoleTargetViewport();
		const assigned = this.#assignedSizeRole(node, axis, viewport);
		const viewportLabel = viewport === void 0 ? void 0 : referenceText(viewport.label);
		const controlLabel = viewportLabel === void 0 ? this.#text("studio.shell/inspector-layout-role-label-base", { axis: axisLabel }) : this.#text("studio.shell/inspector-layout-role-label-viewport", {
			axis: axisLabel,
			viewport: viewportLabel
		});
		const unsetLabel = viewportLabel === void 0 ? this.#text("studio.shell/inspector-layout-unset-label-base", { axis: axisLabel }) : this.#text("studio.shell/inspector-layout-unset-label-viewport", {
			axis: axisLabel,
			viewport: viewportLabel
		});
		return b`
      <li class="inspector-row layout-axis" data-axis=${axis}>
        <span class="inspector-name">${axisLabel}</span>
        <span class="inspector-provenance layout-base-state" data-axis=${axis}>
          ${baseRole === void 0 ? this.#text("studio.shell/inspector-layout-base-none") : this.#text("studio.shell/inspector-layout-base-role", { role: baseRole })}
        </span>
        ${viewportLabel === void 0 ? A : b`
                <span class="inspector-provenance layout-viewport-state" data-axis=${axis}>
                  ${assigned !== void 0 ? this.#text("studio.shell/inspector-provenance-overridden", {
			value: assigned,
			viewport: viewportLabel
		}) : baseRole !== void 0 ? this.#text("studio.shell/inspector-provenance-inherited", { value: baseRole }) : this.#text("studio.shell/inspector-provenance-inherited-none")}
                </span>
              `}
        ${vocabulary === void 0 ? b`
                <input
                  type="text"
                  class="layout-role-input"
                  data-axis=${axis}
                  aria-label=${controlLabel}
                  .value=${assigned ?? ""}
                  ?disabled=${readOnly}
                  @keydown=${(event) => {
			this.#onLayoutRoleInputKeydown(event, node, axis);
		}}
                />
              ` : b`
                <select
                  class="layout-role-select"
                  data-axis=${axis}
                  data-role=${assigned ?? ""}
                  aria-label=${controlLabel}
                  ?disabled=${readOnly}
                  @change=${(event) => {
			this.#onLayoutRoleChange(event, node, axis);
		}}
                >
                  <option value="" disabled ?selected=${assigned === void 0}>
                    ${this.#text("studio.shell/inspector-layout-role-placeholder")}
                  </option>
                  ${vocabulary.map((choice) => b`
                      <option value=${choice.id} ?selected=${assigned === choice.id}>
                        ${referenceText(choice.label)}
                      </option>
                    `)}
                </select>
              `}
        <button
          type="button"
          class="layout-role-unset"
          data-axis=${axis}
          aria-label=${unsetLabel}
          ?disabled=${readOnly || assigned === void 0}
          @click=${() => {
			this.#unsetSizeRole(node, axis);
		}}
        >
          ${this.#text("studio.shell/inspector-layout-unset")}
        </button>
      </li>
    `;
	}
	#renderOutlineControls(node) {
		const location = this.document === void 0 ? void 0 : findOutlineLocation(this.document.roots, node.id);
		const reorderDisabled = !this.#canMutateNode(node, "studio.command/reorder-children");
		const first = location === void 0 || location.index === 0;
		const last = location === void 0 || location.index === location.collection.length - 1;
		const destinations = this.#moveDestinations(node);
		return b`
      <div
        class="outline-controls"
        role="group"
        aria-label=${this.#text("studio.shell/block-actions")}
      >
        <button
          type="button"
          class="outline-move-up"
          ?disabled=${reorderDisabled || first}
          @click=${() => {
			this.#moveNode(node, -1);
		}}
        >
          ${this.#text("studio.shell/move-up")}
        </button>
        <button
          type="button"
          class="outline-move-down"
          ?disabled=${reorderDisabled || last}
          @click=${() => {
			this.#moveNode(node, 1);
		}}
        >
          ${this.#text("studio.shell/move-down")}
        </button>
        <button
          type="button"
          class="outline-duplicate"
          ?disabled=${!this.#canMutateNode(node, "studio.command/duplicate-node")}
          @click=${() => {
			this.#duplicateNode(node);
		}}
        >
          ${this.#text("studio.shell/duplicate")}
        </button>
        <button
          type="button"
          class="outline-delete"
          ?disabled=${!this.#canMutateNode(node, "studio.command/remove-node")}
          @click=${() => {
			this.#deleteNode(node);
		}}
        >
          ${this.#text("studio.shell/delete")}
        </button>
        <label class="outline-move-destination-label">
          <span>${this.#text("studio.shell/move-destination-label")}</span>
          <select
            class="outline-move-destination"
            ?disabled=${destinations.length === 0}
            @change=${(event) => {
			const target = event.currentTarget;
			if (!(target instanceof HTMLSelectElement)) return;
			const option = destinations.find((candidate) => candidate.id === target.value);
			if (option !== void 0) this.#moveNodeToOption(node, option);
			target.value = "";
		}}
          >
            <option value="" selected disabled>
              ${this.#text("studio.shell/move-destination-placeholder")}
            </option>
            ${destinations.map((destination) => b`
                <option value=${destination.id}>${destination.label}</option>
              `)}
          </select>
        </label>
      </div>
    `;
	}
	#renderOutlineNode(node) {
		const definition = this.#findDefinition(node);
		const selected = this.selectedNodeId === node.id;
		const nested = Object.entries(node.slots);
		return b`
      <li>
        <button
          type="button"
          class="outline-entry"
          data-node-id=${node.id}
          aria-pressed=${selected ? "true" : "false"}
          @click=${() => {
			this.#selectNode(node.id);
		}}
          @keydown=${(event) => {
			this.#onOutlineKeydown(event, node);
		}}
        >
          ${definition === void 0 ? b`${node.type}
                  <span class="unresolved">${this.#text("studio.shell/unresolved-block")}</span>` : referenceText(definition.label)}
        </button>
        ${selected ? this.#renderOutlineControls(node) : A}
        ${nested.map(([slot, children]) => {
			if (children.length === 0) return A;
			const slotText = this.#slotLabel(node, slot);
			return b`
            <section class="node-children" aria-label=${slotText}>
              <span class="outline-slot-label">${slotText}</span>
              <ul class="tree">
                ${children.map((child) => this.#renderOutlineNode(child))}
              </ul>
            </section>
          `;
		})}
      </li>
    `;
	}
	#renderPreview() {
		const available = this.#previewCapabilityAvailable() && this.previewBinding !== void 0;
		const state = available ? this.previewState ?? "connecting" : "unavailable";
		const statusKey = state === "closed" ? "studio.shell/preview-closed" : state === "connecting" ? "studio.shell/preview-connecting" : state === "current" ? "studio.shell/preview-current" : state === "rendering" ? "studio.shell/preview-rendering" : state === "stale" ? "studio.shell/preview-stale" : "studio.shell/preview-unavailable";
		return b`
      <section
        class="preview-region"
        data-preview-state=${state}
        aria-label=${this.#text("studio.shell/preview-label")}
      >
        <h2>${this.#text("studio.shell/preview-heading")}</h2>
        <p class="preview-status">${this.#text(statusKey)}</p>
        ${available && state === "current" && this.canvasGeometry !== void 0 ? b`
                <button
                  type="button"
                  class="canvas-edit-toggle"
                  aria-pressed=${this.canvasDirectManipulation === true ? "true" : "false"}
                  @click=${() => {
			this.canvasDirectManipulation = this.canvasDirectManipulation !== true;
			if (!this.canvasDirectManipulation && this.#previewDrag !== void 0) this.#cancelDrag();
			this.#announce("studio.shell/announce-canvas-mode", { state: this.#text(this.canvasDirectManipulation ? "studio.shell/canvas-mode-editing" : "studio.shell/canvas-mode-interacting") });
		}}
                >
                  ${this.#text("studio.shell/canvas-edit-toggle")}
                </button>
              ` : A}
        ${available && state !== "closed" ? b`
                <div class="preview-stage" tabindex="0">
                  <slot
                    class="preview-surface-slot"
                    name="preview"
                    @slotchange=${() => {
			queueMicrotask(() => {
				this.refreshPreviewGeometry();
			});
		}}
                  ></slot>
                  ${this.#renderPreviewCanvasOverlay()}
                </div>
                ${this.#renderPreviewCanvasStatus()}
              ` : A}
      </section>
    `;
	}
	#renderPreviewCanvasOverlay() {
		const geometry = this.canvasGeometry;
		if (geometry === void 0 || geometry.viewport.width <= 0 || geometry.viewport.height <= 0) return A;
		const indicator = this.#previewDrag?.active === true ? this.#previewDrag.target?.indicator : void 0;
		const measurements = Object.entries(geometry.measurements).sort(([left], [right]) => {
			return (left === this.selectedNodeId ? 1 : 0) - (right === this.selectedNodeId ? 1 : 0);
		});
		return b`
      <svg
        class="preview-canvas-overlay"
        data-interactive=${this.canvasDirectManipulation === true ? "true" : "false"}
        width=${String(geometry.viewport.width)}
        height=${String(geometry.viewport.height)}
        viewBox=${`0 0 ${geometry.viewport.width} ${geometry.viewport.height}`}
        preserveAspectRatio="xMinYMin meet"
        aria-hidden="true"
        @pointermove=${(event) => {
			this.#onPreviewCanvasPointerMove(event);
		}}
        @pointerup=${(event) => {
			this.#onPreviewCanvasPointerUp(event);
		}}
        @pointercancel=${(event) => {
			this.#onPreviewCanvasPointerCancel(event);
		}}
      >
        ${measurements.flatMap(([nodeId, rects]) => rects.map((rect, index) => w`
              <rect
                class="preview-canvas-region"
                data-node-id=${nodeId}
                data-rect-index=${String(index)}
                data-hovered=${this.#hoveredPreviewNodeId === nodeId ? "true" : "false"}
                data-selected=${this.selectedNodeId === nodeId ? "true" : "false"}
                x=${String(rect.x)}
                y=${String(rect.y)}
                width=${String(rect.width)}
                height=${String(rect.height)}
                @pointerenter=${() => {
			this.#hoveredPreviewNodeId = nodeId;
			this.requestUpdate();
		}}
                @pointerleave=${() => {
			if (this.#hoveredPreviewNodeId === nodeId) {
				this.#hoveredPreviewNodeId = void 0;
				this.requestUpdate();
			}
		}}
                @pointerdown=${(event) => {
			this.#onPreviewCanvasPointerDown(event, nodeId);
		}}
              ></rect>
            `))}
        ${indicator === void 0 ? A : w`
                <rect
                  class="preview-canvas-drop-indicator"
                  x=${String(indicator.x)}
                  y=${String(indicator.y)}
                  width=${String(indicator.width)}
                  height=${String(indicator.height)}
                ></rect>
              `}
      </svg>
    `;
	}
	#renderPreviewCanvasStatus() {
		const drag = this.#previewDrag;
		if (drag?.active !== true || drag.target === void 0) return A;
		return b`
      <p class="preview-canvas-status">
        ${this.#text("studio.shell/visual-drop-target", {
			destination: drag.target.label,
			label: drag.label
		})}
      </p>
    `;
	}
	#onPreviewCanvasPointerDown(event, nodeId) {
		if (this.canvasDirectManipulation !== true || event.button !== 0 || this.#previewDrag !== void 0) return;
		const document = this.document;
		const node = document === void 0 ? void 0 : findOutlineLocation(document.roots, nodeId)?.node;
		if (node === void 0) return;
		this.#selectNode(nodeId);
		if (this.#moveDestinations(node).length === 0) return;
		const drag = {
			active: false,
			label: this.#nodeLabel(node),
			nodeId,
			originX: event.clientX,
			originY: event.clientY,
			pointerId: event.pointerId
		};
		const target = event.currentTarget;
		if (target instanceof Element) {
			drag.capture = target;
			try {
				target.setPointerCapture(event.pointerId);
			} catch {}
		}
		this.#previewDrag = drag;
	}
	#onPreviewCanvasPointerMove(event) {
		const drag = this.#previewDrag;
		if (drag?.pointerId !== event.pointerId) return;
		if (!drag.active && Math.hypot(event.clientX - drag.originX, event.clientY - drag.originY) < 4) return;
		drag.active = true;
		const point = this.#previewCanvasPoint(event);
		const document = this.document;
		const node = document === void 0 ? void 0 : findOutlineLocation(document.roots, drag.nodeId)?.node;
		if (point !== void 0 && node !== void 0) {
			const target = this.#resolvePreviewDropTarget(node, point.x, point.y);
			if (target === void 0) delete drag.target;
			else drag.target = target;
		}
		this.requestUpdate();
	}
	#onPreviewCanvasPointerUp(event) {
		const drag = this.#previewDrag;
		if (drag?.pointerId !== event.pointerId) return;
		this.#previewDrag = void 0;
		this.#releasePreviewDragCapture(drag);
		this.requestUpdate();
		if (!drag.active) {
			this.#selectNode(drag.nodeId);
			return;
		}
		const document = this.document;
		const node = document === void 0 ? void 0 : findOutlineLocation(document.roots, drag.nodeId)?.node;
		if (node === void 0 || drag.target === void 0) {
			this.#announce("studio.shell/announce-drag-cancelled", { label: drag.label });
			return;
		}
		this.#moveNodeToOption(node, drag.target);
	}
	#onPreviewCanvasPointerCancel(event) {
		if (this.#previewDrag?.pointerId === event.pointerId) this.#cancelDrag();
	}
	#releasePreviewDragCapture(drag) {
		try {
			if (drag.capture?.hasPointerCapture(drag.pointerId) === true) drag.capture.releasePointerCapture(drag.pointerId);
		} catch {}
	}
	#previewCanvasPoint(event) {
		const geometry = this.canvasGeometry;
		const target = event.currentTarget;
		if (geometry === void 0 || !(target instanceof SVGElement)) return;
		const svg = target instanceof SVGSVGElement ? target : target.ownerSVGElement;
		if (svg === null) return;
		const bounds = svg.getBoundingClientRect();
		if (bounds.width <= 0 || bounds.height <= 0) return {
			x: event.clientX,
			y: event.clientY
		};
		return {
			x: (event.clientX - bounds.left) / bounds.width * geometry.viewport.width,
			y: (event.clientY - bounds.top) / bounds.height * geometry.viewport.height
		};
	}
	#resolvePreviewDropTarget(node, x, y) {
		const targets = this.#previewDropTargets(node);
		let chosen;
		let distance = Number.POSITIVE_INFINITY;
		for (const target of targets) {
			const current = Math.hypot(target.distanceX - x, target.distanceY - y);
			if (current < distance || current === distance && (chosen === void 0 || target.specificity > chosen.specificity)) {
				chosen = target;
				distance = current;
			}
		}
		return chosen;
	}
	#previewDropTargets(node) {
		const geometry = this.canvasGeometry;
		const document = this.document;
		if (geometry === void 0 || document === void 0) return [];
		const options = this.#moveDestinations(node);
		const collections = this.#moveCollections(node);
		const targets = [];
		for (const option of options) {
			const collection = collections.find((candidate) => candidate.parentNodeId === option.destination.parentNodeId && candidate.slot === option.destination.slot);
			if (collection === void 0) continue;
			const children = collection.collection.filter((candidate) => candidate.id !== node.id);
			const childRects = children.map((child) => boundingPreviewRect(geometry.measurements[child.id] ?? []));
			if (childRects.every((rect) => rect !== void 0) && childRects.length > 0) {
				const target = collectionPositionTarget(childRects, option.destination.position);
				targets.push({
					...option,
					distanceX: target.x + target.width / 2,
					distanceY: target.y + target.height / 2,
					indicator: target,
					specificity: collection.specificity
				});
				continue;
			}
			if (children.length !== 0 || collection.parentNodeId === void 0) continue;
			const parentRect = boundingPreviewRect(geometry.measurements[collection.parentNodeId] ?? []);
			if (parentRect === void 0) continue;
			const parent = findOutlineLocation(document.roots, collection.parentNodeId)?.node;
			const slots = this.#findDefinition(parent ?? node)?.slots.filter((slot) => {
				return (parent?.slots[slot.id] ?? []).length === 0 && slot.accepts.types.includes(node.type);
			}) ?? [];
			const slotIndex = Math.max(0, slots.findIndex((slot) => slot.id === collection.slot));
			const bandHeight = parentRect.height / Math.max(1, slots.length);
			const indicator = {
				height: Math.max(4, bandHeight - 8),
				width: Math.max(4, parentRect.width - 8),
				x: parentRect.x + 4,
				y: parentRect.y + slotIndex * bandHeight + 4
			};
			targets.push({
				...option,
				distanceX: indicator.x + indicator.width / 2,
				distanceY: indicator.y + indicator.height / 2,
				indicator,
				specificity: collection.specificity
			});
		}
		return targets;
	}
	#renderViewportSwitcher() {
		const ordered = this.#orderedViewports();
		if (ordered.length === 0) return A;
		const active = this.activeViewport;
		return b`
      <section class="viewport-switcher" aria-label=${this.#text("studio.shell/viewport-label")}>
        ${ordered.map((viewport) => b`
            <button
              type="button"
              class="viewport-option"
              data-viewport-id=${viewport.id}
              aria-pressed=${active?.id === viewport.id ? "true" : "false"}
              @click=${() => {
			this.#selectViewport(viewport);
		}}
            >
              ${referenceText(viewport.label)}
            </button>
          `)}
      </section>
    `;
	}
	#requestInsert(definition) {
		const destination = this.#insertionDestination(definition);
		if (destination === void 0) return;
		const detail = {
			definition,
			parentId: destination.parentNodeId ?? null
		};
		if (destination.slot !== void 0) detail.slot = destination.slot;
		this.dispatchEvent(new CustomEvent("studio-insert-request", {
			bubbles: true,
			composed: true,
			detail
		}));
	}
	/**
	* Maps a pointer position onto a target position within the dragged node's
	* own collection. Chips with measurable geometry are hit-tested by their
	* vertical extent; environments without layout (such as the test DOM) fall
	* back to the event's composed path. Only the source collection's chips are
	* considered — cross-slot reparenting is out of scope for pointer drag.
	*/
	#resolveDragIndex(event, drag) {
		const chips = [...this.shadowRoot?.querySelectorAll("button.canvas-chip") ?? []].filter((chip) => {
			const nodeId = chip.dataset.nodeId;
			return nodeId !== void 0 && drag.order.includes(nodeId);
		});
		let nearestIndex;
		let nearestDistance = Number.POSITIVE_INFINITY;
		for (const chip of chips) {
			const rect = chip.getBoundingClientRect();
			if (rect.height <= 0) continue;
			const nodeId = chip.dataset.nodeId;
			if (nodeId === void 0) continue;
			if (event.clientY >= rect.top && event.clientY <= rect.bottom) return drag.order.indexOf(nodeId);
			const distance = Math.abs(event.clientY - (rect.top + rect.height / 2));
			if (distance < nearestDistance) {
				nearestDistance = distance;
				nearestIndex = drag.order.indexOf(nodeId);
			}
		}
		if (nearestIndex !== void 0) return nearestIndex;
		for (const hop of event.composedPath()) if (hop instanceof HTMLElement) {
			const nodeId = hop.dataset.nodeId;
			if (nodeId !== void 0 && drag.order.includes(nodeId)) return drag.order.indexOf(nodeId);
		}
	}
	#revalidate() {
		if (this.document === void 0) {
			this.#diagnostics = [];
			this.#bindingProjection = void 0;
			return;
		}
		const registry = this.#registry ?? new BlockRegistry();
		const result = validateBlueprint(this.document, registry);
		this.#bindingProjection = this.contentModel === void 0 ? void 0 : projectBlueprintFieldBindings(this.document, this.contentModel, this.#activeDefinitions());
		this.#diagnostics = [...result.diagnostics, ...this.#bindingProjection?.diagnostics ?? []].sort((left, right) => SEVERITY_RANK[left.severity] - SEVERITY_RANK[right.severity]);
	}
	#revealDiagnosticNode(nodeId) {
		this.#selectNode(nodeId);
		this.#pendingFocusNodeId = nodeId;
		this.requestUpdate();
	}
	#runPaletteEntry(entry) {
		if (entry.disabled) return;
		const invoker = this.#paletteInvoker;
		this.#closePalette(false);
		entry.run();
		if (this.#pendingFocusNodeId === void 0 && invoker?.isConnected === true) invoker.focus();
	}
	#runShellCommand(command) {
		try {
			this.execute(command);
			return true;
		} catch {
			return false;
		}
	}
	#selectNode(nodeId, notifyPreview = true) {
		const session = this.#session;
		if (session === void 0) return;
		try {
			session.select([nodeId]);
		} catch {
			return;
		}
		this.selectedNodeId = nodeId;
		if (notifyPreview) this.#previewSurface?.selectNode(nodeId);
	}
	#selectViewport(viewport) {
		if (this.activeViewport?.id === viewport.id) return;
		this.#activeViewportId = viewport.id;
		this.dispatchEvent(new CustomEvent("studio-viewport-change", {
			bubbles: true,
			composed: true,
			detail: { viewport }
		}));
		this.#announce("studio.shell/announce-viewport-changed", { label: referenceText(viewport.label) });
		this.#schedulePreview();
		this.requestUpdate();
	}
	/**
	* Preview is deny-by-default: feature policy, the canonical preview port
	* with render and cancellation operations, and a concrete browser binding
	* must all agree before the shell opens a channel.
	*/
	#previewCapabilityAvailable() {
		const session = this.configuration?.session;
		if (session?.preview.enabled !== true) return false;
		return session.hostCapabilities.ports.some((port) => port.id === "studio.port/preview" && port.operations.includes("studio.operation/preview.render") && port.operations.includes("studio.operation/preview.cancel"));
	}
	#schedulePreview() {
		const surface = this.#previewSurface;
		const document = this.document;
		if (surface === void 0 || document === void 0) return;
		surface.update(document, this.activeViewport?.id ?? this.configuration?.session.preview.initialViewport);
	}
	#synchronizePreviewSurface() {
		const binding = this.previewBinding;
		const generation = this.configuration?.session.sessionGeneration;
		if (!this.#previewCapabilityAvailable() || binding === void 0 || generation === void 0) {
			if (this.#previewSurface !== void 0) this.#previewSurface.teardown("studio.preview/capability-revoked");
			this.#previewSurface = void 0;
			this.#activePreviewBinding = void 0;
			this.#previewBindingGeneration = void 0;
			this.previewState = "unavailable";
			this.canvasGeometry = void 0;
			return;
		}
		if (this.#previewSurface !== void 0 && this.#activePreviewBinding === binding && this.#previewBindingGeneration === generation) return;
		this.#previewSurface?.teardown("studio.preview/session-replaced");
		this.#activePreviewBinding = binding;
		this.#previewBindingGeneration = generation;
		this.#previewSurface = new StudioPreviewSurface(binding, {
			onActivated: (nodeId) => {
				this.#selectNode(nodeId, false);
				this.requestUpdate();
			},
			onGeometry: (geometry) => {
				this.canvasGeometry = geometry;
				if (geometry === void 0) {
					this.#hoveredPreviewNodeId = void 0;
					if (this.#previewDrag !== void 0) this.#cancelDrag();
				}
			},
			onMessage: (message) => {
				this.notifyPreviewMessage(message);
			},
			onState: (state) => {
				this.previewState = state;
			}
		});
	}
	#serializedInspectorValue(node, property, viewport) {
		const value = viewport === void 0 ? node.properties[property] : node.responsive?.[property]?.[viewport.id];
		return value === void 0 ? "" : JSON.stringify(value);
	}
	#fieldAtPath(fieldPath) {
		let fields = this.contentModel?.fields;
		let resolved;
		for (const member of fieldPath) {
			resolved = fields?.find((field) => field.id === member);
			if (resolved === void 0) return;
			fields = resolved.fields;
		}
		return resolved;
	}
	#modelPortAdvertised() {
		return this.configuration?.session.hostCapabilities.ports.some((port) => port.id === "studio.port/model") ?? false;
	}
	#resourcePortAdvertised() {
		return this.configuration?.session.hostCapabilities.ports.some((port) => port.id === "studio.port/resource" && port.operations.includes("studio.operation/resource.search")) ?? false;
	}
	#setFieldBinding(node, port, candidate) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-binding")) return;
		const current = node.bindings[port];
		const binding = current?.source.kind === "entry-field" ? {
			...current,
			source: {
				fieldPath: [...candidate.fieldPath],
				kind: "entry-field"
			}
		} : {
			onError: "error",
			onNull: "empty",
			source: {
				fieldPath: [...candidate.fieldPath],
				kind: "entry-field"
			},
			transforms: []
		};
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				binding,
				nodeId: node.id,
				port
			},
			type: "studio.command/set-binding"
		};
		if (this.#runShellCommand(command)) this.#announce("studio.shell/announce-field-bound", {
			field: candidate.fieldPath.join("."),
			port
		});
	}
	#setBinding(node) {
		const session = this.#session;
		const document = this.document;
		const portInput = this.shadowRoot?.querySelector("input.inspector-binding-port") ?? null;
		const valueInput = this.shadowRoot?.querySelector("input.inspector-binding-value-input") ?? null;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-binding") || portInput === null || valueInput === null) return;
		const port = portInput.value.trim();
		if (port.length === 0) {
			this.#announce("studio.shell/announce-name-required");
			return;
		}
		if (this.#findDefinition(node)?.ports.some((entry) => entry.id === port && entry.valueType === "resource")) {
			this.#announce("studio.shell/announce-invalid-value", { label: port });
			return;
		}
		const parsed = this.#parseJsonInput(valueInput.value, port);
		if (parsed === void 0) return;
		const value = parsed.value;
		if (value === null || typeof value !== "object" || Array.isArray(value)) {
			this.#announce("studio.shell/announce-invalid-value", { label: port });
			return;
		}
		const command = {
			...this.#commandEnvelope(document, session),
			payload: {
				binding: value,
				nodeId: node.id,
				port
			},
			type: "studio.command/set-binding"
		};
		if (this.#runShellCommand(command)) {
			portInput.value = "";
			valueInput.value = "";
			this.#announce("studio.shell/announce-binding-set", { port });
		}
	}
	/**
	* Dispatches set-property for a base value or, with a viewport, for a
	* responsive override, and announces the outcome — naming the viewport for
	* overrides. Returns whether the command applied.
	*/
	#setNodeProperty(node, property, value, viewport) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-property")) return false;
		const payload = {
			nodeId: node.id,
			property,
			value
		};
		if (viewport !== void 0) payload.viewport = viewport.id;
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/set-property"
		};
		if (!this.#runShellCommand(command)) return false;
		if (viewport === void 0) this.#announce("studio.shell/announce-property-set", { property });
		else this.#announce("studio.shell/announce-override-set", {
			property,
			viewport: referenceText(viewport.label)
		});
		return true;
	}
	/**
	* Dispatches set-size-role for one axis, targeting the base assignment or
	* the active viewport's override per `#sizeRoleTargetViewport`, and
	* announces the outcome naming the axis, the role, and — for overrides —
	* the viewport. Returns whether the command applied.
	*/
	#setSizeRole(node, axis, role) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/set-size-role")) return false;
		const viewport = this.#sizeRoleTargetViewport();
		const payload = {
			axis,
			nodeId: node.id,
			role
		};
		if (viewport !== void 0) payload.viewport = viewport.id;
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/set-size-role"
		};
		if (!this.#runShellCommand(command)) return false;
		if (viewport === void 0) this.#announce("studio.shell/announce-size-role-set", {
			axis: this.#axisText(axis),
			role
		});
		else this.#announce("studio.shell/announce-size-role-set-viewport", {
			axis: this.#axisText(axis),
			role,
			viewport: referenceText(viewport.label)
		});
		return true;
	}
	/**
	* The responsive context size-role edits target: the active viewport when
	* the switcher is on a non-base viewport, otherwise the base assignment —
	* the same base-versus-viewport split responsive property dispatch uses,
	* resolved from the viewport switcher.
	*/
	#sizeRoleTargetViewport() {
		const viewport = this.activeViewport;
		return viewport === void 0 || viewport.base ? void 0 : viewport;
	}
	/**
	* The declared size-role vocabulary of the active theme: the choices of
	* every `size-role` design control the host feeds through
	* `designControls`, deduplicated by identifier in declaration order.
	* Undefined when the host supplies no theme design controls at all —
	* the layout editor then falls back to a validated identifier input.
	*/
	#sizeRoleVocabulary() {
		const controls = this.#activeDesignControls();
		if (controls === void 0) return;
		const choices = [];
		const seen = /* @__PURE__ */ new Set();
		for (const control of controls) {
			if (control.kind !== "size-role") continue;
			for (const choice of control.choices) if (!seen.has(choice.id)) {
				seen.add(choice.id);
				choices.push(choice);
			}
		}
		return choices;
	}
	/**
	* The visible outline label for a slot: the declared slot label from the
	* parent's block definition when resolvable, otherwise the raw slot name,
	* rendered through the catalog's slot template.
	*/
	#slotLabel(node, slot) {
		const declared = this.#findDefinition(node)?.slots.find((candidate) => candidate.id === slot);
		return this.#text("studio.shell/outline-slot", { slot: declared === void 0 ? slot : referenceText(declared.label) });
	}
	#syncDirty() {
		const dirty = this.#session?.dirty ?? false;
		if (dirty === this.#lastDirty) return;
		this.#lastDirty = dirty;
		this.dispatchEvent(new CustomEvent("studio-dirty-changed", {
			bubbles: true,
			composed: true,
			detail: { dirty }
		}));
	}
	#text(key, parameters) {
		return messageText$1(key, this.messages, parameters);
	}
	#togglePalette(event) {
		if (this.paletteOpen === true) {
			this.#closePalette(true);
			return;
		}
		const origin = event?.composedPath()[0];
		this.#paletteInvoker = origin instanceof HTMLElement ? origin : void 0;
		this.paletteOpen = true;
		this.paletteFilter = "";
		this.#pendingPaletteFocus = true;
	}
	#unsetNodeProperty(node, property, viewport) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/unset-property")) return;
		const payload = {
			nodeId: node.id,
			property
		};
		if (viewport !== void 0) payload.viewport = viewport.id;
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/unset-property"
		};
		if (!this.#runShellCommand(command)) return;
		if (viewport === void 0) this.#announce("studio.shell/announce-property-unset", { property });
		else this.#announce("studio.shell/announce-override-removed", {
			property,
			viewport: referenceText(viewport.label)
		});
	}
	/**
	* Dispatches unset-size-role for one axis against the context
	* `#sizeRoleTargetViewport` resolves, announcing the removal with the
	* axis and — for overrides — the viewport.
	*/
	#unsetSizeRole(node, axis) {
		const session = this.#session;
		const document = this.document;
		if (session === void 0 || document === void 0 || !this.#permits("studio.command/unset-size-role")) return;
		const viewport = this.#sizeRoleTargetViewport();
		const payload = {
			axis,
			nodeId: node.id
		};
		if (viewport !== void 0) payload.viewport = viewport.id;
		const command = {
			...this.#commandEnvelope(document, session),
			payload,
			type: "studio.command/unset-size-role"
		};
		if (!this.#runShellCommand(command)) return;
		if (viewport === void 0) this.#announce("studio.shell/announce-size-role-removed", { axis: this.#axisText(axis) });
		else this.#announce("studio.shell/announce-size-role-removed-viewport", {
			axis: this.#axisText(axis),
			viewport: referenceText(viewport.label)
		});
	}
};
/**
* Session-level rejections meaning the document/session pairing is stale or
* non-writable rather than the command being malformed. They surface through
* the conflict announcement, which carries recovery guidance.
*/
var CONFLICT_ERROR_CODES = /* @__PURE__ */ new Set([
	"read-only-session",
	"stale-generation",
	"stale-state"
]);
var AXIS_MESSAGE_KEYS = {
	block: "studio.shell/inspector-layout-axis-block",
	inline: "studio.shell/inspector-layout-axis-inline"
};
/** Both layout axes in the order the layout section renders them. */
var SIZE_ROLE_AXES = ["inline", "block"];
/**
* The canonical bounded lower-case identifier shape (the shared local-name
* pattern of the command schema) the fallback role input validates against
* before dispatching, with the prototype-polluting names the schema also
* excludes.
*/
var SIZE_ROLE_IDENTIFIER = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/;
var FORBIDDEN_ROLE_IDENTIFIERS = /* @__PURE__ */ new Set([
	"__proto__",
	"constructor",
	"prototype"
]);
var SEVERITY_MESSAGE_KEYS = {
	blocking: "studio.shell/severity-blocking",
	error: "studio.shell/severity-error",
	information: "studio.shell/severity-information",
	warning: "studio.shell/severity-warning"
};
var SEVERITY_RANK = {
	blocking: 0,
	error: 1,
	information: 3,
	warning: 2
};
var STUDIO_AUTHORING_CONTROLS = new Set(Object.values(STUDIO_AUTHORING_CONTROL_IDS));
function isStudioAuthoringControlId(value) {
	return STUDIO_AUTHORING_CONTROLS.has(value);
}
function defaultAuthoringControlValue(control) {
	switch (control) {
		case "studio.control/rich-text": return {
			content: [],
			type: "doc"
		};
		case "studio.control/source": return "";
		case "studio.control/chart": return {
			datasets: [{
				label: "Series 1",
				values: [0]
			}],
			labels: ["Label 1"],
			type: "bar"
		};
		case "studio.control/drawing": return {
			alt: "Drawing",
			height: 600,
			strokes: [],
			width: 800
		};
		case "studio.control/money": return {
			amount: "0",
			currency: "USD"
		};
		case "studio.control/media-collection": return [];
		case "studio.control/media-reference": return;
		case "studio.control/scoped-css": return { rules: [] };
		case "studio.control/table": return {
			columns: ["Column 1"],
			rows: [[""]]
		};
	}
}
function authoringTargetSignature(target) {
	return JSON.stringify({
		bindingKind: target.binding?.source.kind,
		control: target.control,
		kind: target.kind,
		name: target.name,
		profile: target.profile,
		readOnly: target.readOnly,
		value: target.value
	});
}
function resourceBindingTargetSignature(target) {
	return JSON.stringify({
		binding: target.binding,
		multiple: target.multiple,
		readOnly: target.readOnly
	});
}
/**
* Copy an editor-produced value into the bounded language-neutral JSON
* domain. Unsupported objects, non-finite numbers and excessive structures
* fail closed instead of being coerced into persisted protocol data.
*/
function toJsonValue(value, depth = 0) {
	if (depth > 32) return void 0;
	if (value === null || typeof value === "boolean") return value;
	if (typeof value === "number") return Number.isFinite(value) ? value : void 0;
	if (typeof value === "string") return value.length <= 1e6 ? value : void 0;
	if (Array.isArray(value)) {
		if (value.length > 1e4) return void 0;
		const result = [];
		for (const item of value) {
			const parsed = toJsonValue(item, depth + 1);
			if (parsed === void 0) return void 0;
			result.push(parsed);
		}
		return result;
	}
	if (typeof value !== "object" || value === void 0) return void 0;
	const prototype = Object.getPrototypeOf(value);
	if (prototype !== Object.prototype && prototype !== null) return void 0;
	const entries = Object.entries(value);
	if (entries.length > 1e3) return void 0;
	const result = {};
	for (const [key, item] of entries) {
		if (key === "__proto__" || key === "constructor" || key === "prototype") return void 0;
		const parsed = toJsonValue(item, depth + 1);
		if (parsed === void 0) return void 0;
		result[key] = parsed;
	}
	return result;
}
function diagnosticText(entry) {
	const template = referenceText(entry.message);
	if (entry.parameters === void 0) return template;
	let text = template;
	for (const [name, value] of Object.entries(entry.parameters)) text = text.replaceAll(`{${name}}`, String(value));
	return text;
}
function boundingPreviewRect(rects) {
	const visible = rects.filter((rect) => rect.width > 0 && rect.height > 0);
	const first = visible[0];
	if (first === void 0) return;
	let left = first.x;
	let top = first.y;
	let right = first.x + first.width;
	let bottom = first.y + first.height;
	for (const rect of visible.slice(1)) {
		left = Math.min(left, rect.x);
		top = Math.min(top, rect.y);
		right = Math.max(right, rect.x + rect.width);
		bottom = Math.max(bottom, rect.y + rect.height);
	}
	return {
		height: bottom - top,
		width: right - left,
		x: left,
		y: top
	};
}
/** A CSP-safe SVG indicator for one ordered insertion boundary. */
function collectionPositionTarget(rects, position) {
	const first = rects[0];
	const last = rects.at(-1);
	if (first === void 0 || last === void 0) return {
		height: 4,
		width: 4,
		x: 0,
		y: 0
	};
	const bounds = boundingPreviewRect(rects) ?? first;
	const xSpread = Math.abs(last.x + last.width / 2 - (first.x + first.width / 2));
	const ySpread = Math.abs(last.y + last.height / 2 - (first.y + first.height / 2));
	const before = rects[Math.max(0, position - 1)] ?? first;
	const after = rects[Math.min(rects.length - 1, position)] ?? last;
	if (xSpread > ySpread) {
		const boundary = position === 0 ? first.x : position >= rects.length ? last.x + last.width : (before.x + before.width + after.x) / 2;
		return {
			height: Math.max(4, bounds.height),
			width: 4,
			x: boundary - 2,
			y: bounds.y
		};
	}
	const boundary = position === 0 ? first.y : position >= rects.length ? last.y + last.height : (before.y + before.height + after.y) / 2;
	return {
		height: 4,
		width: Math.max(4, bounds.width),
		x: bounds.x,
		y: boundary - 2
	};
}
function isSizeRoleIdentifier(text) {
	return text.length > 0 && text.length <= 100 && SIZE_ROLE_IDENTIFIER.test(text) && !FORBIDDEN_ROLE_IDENTIFIERS.has(text);
}
function referenceText(reference) {
	return reference.defaultMessage ?? reference.key;
}
//#endregion
//#region node_modules/@kumwe/studio/dist/index.js
function defineKumweStudio(tagName = "kumwe-studio") {
	if (customElements.get(tagName) === void 0) customElements.define(tagName, KumweStudioElement);
}
//#endregion
//#region assets/administrator/components/studio-contributions.ts
function activateStudioContributions() {
	return { runtime: new ContributionRuntime({ generation: "composition-runtime-r0" }) };
}
function activateHostContributions(runtime, documents, trustedOwners) {
	const owners = /* @__PURE__ */ new Map();
	for (const document of documents) {
		const identity = document.kind === "block-definition" ? document.type : document.id;
		const trustedOwner = trustedOwners[`${document.kind} ${identity}`];
		if (trustedOwner === void 0) continue;
		const key = `${trustedOwner}\u0000${document.owner.id}@${document.owner.version}`;
		const entry = owners.get(key) ?? {
			owner: document.owner,
			contributions: { blocks: [] }
		};
		if (document.kind === "block-definition") entry.contributions.blocks.push(document);
		else if (document.kind === "pattern") (entry.contributions.patterns ??= []).push(document);
		else if (document.kind === "field-adapter") (entry.contributions.fieldAdapters ??= []).push(document);
		else if (document.kind === "inspector") (entry.contributions.inspectors ??= []).push(document);
		else if (document.kind === "design-vocabulary") (entry.contributions.designVocabularies ??= []).push(document);
		else if (document.kind === "migration") (entry.contributions.migrations ??= []).push(document);
		owners.set(key, entry);
	}
	let generation = 0;
	for (const { owner: contributionOwner, contributions: contributionSet } of owners.values()) {
		generation += 1;
		runtime.activate(contributionOwner, contributionSet, { generation: `composition-runtime-r${generation}` });
	}
}
function coreStudioTheme(blocks, trustedRenderers, reference) {
	const renderers = [...new Set(blocks.map(({ type }) => trustedRenderers[type]))].filter((renderer) => renderer !== void 0).sort();
	return {
		blockSupport: blocks.map(({ type }) => ({
			renderer: trustedRenderers[type],
			type,
			versions: "^1.0.0"
		})),
		contractVersion: "0.1-draft",
		designControls: layoutDesignControls(),
		id: reference.id,
		kind: "theme",
		label: {
			key: "kumwe.app/administrator-theme",
			defaultMessage: "Administrator"
		},
		owner: {
			id: reference.id,
			version: reference.version
		},
		recipes: layoutRecipes(),
		renderers: renderers.map((id) => ({
			exactPreview: true,
			id,
			surfaces: ["web", "preview"],
			version: "1.0.0"
		})),
		revision: reference.revision,
		version: reference.version,
		viewports: [
			{
				base: true,
				id: "compact",
				label: {
					key: "studio.viewport/compact",
					defaultMessage: "Compact"
				},
				order: 0,
				previewWidth: 360
			},
			{
				base: false,
				id: "medium",
				label: {
					key: "studio.viewport/medium",
					defaultMessage: "Medium"
				},
				order: 1,
				previewWidth: 768
			},
			{
				base: false,
				id: "expanded",
				label: {
					key: "studio.viewport/expanded",
					defaultMessage: "Expanded"
				},
				order: 2,
				previewWidth: 1440
			}
		]
	};
}
function layoutDesignControls() {
	return [
		designControl(CORE_LAYOUT_THEME_CONTROLS.alignment, "enum", "Alignment", [
			["center", "Centre"],
			["end", "End"],
			["start", "Start"],
			["stretch", "Stretch"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.collapse, "enum", "Responsive collapse", [
			["preserve", "Preserve"],
			["stack", "Stack"],
			["wrap", "Wrap"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.direction, "enum", "Direction", [["block", "Vertical"], ["inline", "Horizontal"]]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.spacing, "spacing-role", "Spacing", [
			["comfortable", "Comfortable"],
			["compact", "Compact"],
			["none", "None"],
			["spacious", "Spacious"]
		]),
		designControl(CORE_LAYOUT_THEME_CONTROLS.visibility, "enum", "Visibility", [["hidden", "Hidden"], ["visible", "Visible"]])
	];
}
function designControl(id, kind, label, choices) {
	return {
		choices: choices.map(([choice, choiceLabel]) => ({
			id: choice,
			label: {
				key: `core.composition/${id}-${choice}`,
				defaultMessage: choiceLabel
			}
		})),
		id,
		kind,
		label: {
			key: `core.composition/${id}`,
			defaultMessage: label
		}
	};
}
function layoutRecipes() {
	return [{
		blockType: "studio.core/grid",
		designValues: {
			[CORE_LAYOUT_THEME_CONTROLS.alignment]: "stretch",
			[CORE_LAYOUT_THEME_CONTROLS.collapse]: "stack",
			[CORE_LAYOUT_THEME_CONTROLS.spacing]: "comfortable",
			[CORE_LAYOUT_THEME_CONTROLS.visibility]: "visible"
		},
		id: "responsive-content-grid",
		label: {
			key: "core.composition/responsive-content-grid",
			defaultMessage: "Responsive content grid"
		}
	}];
}
//#endregion
//#region assets/administrator/components/studio-host-adapter.ts
function createStudioHttpHostAdapter(baseUrl, options) {
	const base = baseUrl.endsWith("/") ? baseUrl.slice(0, -1) : baseUrl;
	let serial = 0;
	let previewSequence = 0;
	let previewRenderTail;
	let previewUnavailable;
	let unloading = false;
	const markUnloading = () => {
		unloading = true;
		window.setTimeout(() => {
			unloading = false;
		}, 0);
	};
	window.addEventListener("beforeunload", markUnloading);
	window.addEventListener("pagehide", markUnloading);
	window.addEventListener("pageshow", () => {
		unloading = false;
	});
	const failure = (category, code, retryable = false) => {
		serial += 1;
		return new HostPortFailure({
			category,
			contractVersion: STUDIO_CONTRACT_VERSION,
			correlationId: `browser-transport-${serial}`,
			kind: "host-error",
			message: {
				key: code,
				defaultMessage: "The Studio host request could not be completed."
			},
			retryable
		});
	};
	const call = async (port, operation, args, context) => {
		const perform = async () => {
			if (port === "preview" && previewUnavailable !== void 0) throw previewUnavailable;
			const controller = new AbortController();
			const timer = window.setTimeout(() => controller.abort(), 1e4);
			const headers = {
				"content-type": "application/json",
				"X-CSRF-Token": options.csrf
			};
			if (port === "preview" && options.preview !== void 0) {
				headers["X-Kumwe-Studio-Preview-Channel"] = options.preview.channelId;
				headers["X-Kumwe-Studio-Preview-Source"] = options.preview.sourceId;
				headers["X-Kumwe-Studio-Preview-Sequence"] = String(previewSequence);
				previewSequence += 1;
			}
			let response;
			try {
				response = await fetch(`${base}/${port}/${operation}`, {
					body: JSON.stringify({
						arguments: args,
						context
					}),
					credentials: "same-origin",
					headers,
					keepalive: port === "preview" && operation === "cancel" && unloading,
					method: "POST",
					signal: controller.signal
				});
			} catch (reason) {
				const aborted = reason instanceof DOMException && reason.name === "AbortError";
				const unavailable = failure("unavailable", aborted ? "studio.host/transport-timeout" : "studio.host/transport-unavailable", true);
				if (port === "preview") previewUnavailable = unavailable;
				throw unavailable;
			} finally {
				window.clearTimeout(timer);
			}
			let body;
			try {
				body = await response.json();
			} catch {
				throw failure("internal", "studio.host/malformed-response");
			}
			if (!response.ok) {
				if (isHostPortError(body)) throw new HostPortFailure(body);
				throw failure(categoryForStatus(response.status), "studio.host/http-refused", response.status === 429 || response.status >= 502);
			}
			if (!isResult(body)) throw failure("internal", "studio.host/malformed-response");
			return body;
		};
		if (port !== "preview" || operation === "cancel") return perform();
		const pending = previewRenderTail === void 0 ? perform() : previewRenderTail.then(perform);
		const completed = pending.then(() => void 0, () => void 0);
		previewRenderTail = completed;
		completed.finally(() => {
			if (previewRenderTail === completed) previewRenderTail = void 0;
		});
		return pending;
	};
	const adapter = { artifact: {
		dependencies: (reference, context) => call("artifact", "dependencies", { reference: copy(reference) }, context),
		load: (reference, context) => call("artifact", "load", { reference: copy(reference) }, context),
		publish: (reference, context) => call("artifact", "publish", { reference: copy(reference) }, context),
		save: (document, context) => call("artifact", "save", { document: copy(document) }, context),
		unpublish: (reference, context) => call("artifact", "unpublish", { reference: copy(reference) }, context)
	} };
	if (hasPort(options.advertised, "localization")) adapter.localization = { messages: (locale, namespaces, context) => call("localization", "messages", {
		locale,
		namespaces
	}, context) };
	if (hasPort(options.advertised, "model")) adapter.model = {
		get: (reference, context) => call("model", "get", { reference: copy(reference) }, context),
		list: (context) => call("model", "list", {}, context)
	};
	if (hasPort(options.advertised, "permission")) adapter.permission = {
		explain: (operation, context) => call("permission", "explain", { operation }, context),
		refresh: (context) => call("permission", "refresh", {}, context)
	};
	if (hasPort(options.advertised, "recovery")) adapter.recovery = {
		discard: (context) => call("recovery", "discard", {}, context),
		load: (context) => call("recovery", "load", {}, context),
		store: (envelope, context) => call("recovery", "store", { envelope }, context)
	};
	if (hasPort(options.advertised, "resource")) adapter.resource = { search: (query, context) => call("resource", "search", { query: copy(query) }, context) };
	if (hasPort(options.advertised, "telemetry")) adapter.telemetry = { emit: (event, context) => call("telemetry", "emit", { event: copy(event) }, context) };
	if (hasPort(options.advertised, "preview")) adapter.preview = {
		cancel: (draftDigest, context) => call("preview", "cancel", { draftDigest }, context),
		render: (payload, context) => call("preview", "render", { payload: copy(payload) }, context)
	};
	if (hasPort(options.advertised, "media")) adapter.media = {
		abortUpload: (uploadId, context) => call("media", "abort-upload", { uploadId }, context),
		authorizeUpload: (request, context) => call("media", "authorize-upload", { request: copy(request) }, context),
		completeUpload: (uploadId, context) => call("media", "complete-upload", { uploadId }, context),
		get: (assetId, context) => call("media", "get", { assetId }, context),
		importExternal: (url, context) => call("media", "import-external", { url }, context),
		list: (query, context) => call("media", "list", { query: copy(query) }, context),
		uploadStatus: (assetId, context) => call("media", "upload-status", { assetId }, context)
	};
	return adapter;
}
function hasPort(advertised, port) {
	return advertised.has(`studio.port/${port}`);
}
function copy(value) {
	return JSON.parse(JSON.stringify(value));
}
function isResult(value) {
	if (typeof value !== "object" || value === null || Array.isArray(value) || !Object.hasOwn(value, "value")) return false;
	const revision = value.revision;
	return (revision === void 0 || typeof revision === "string" && revision.length > 0) && Object.keys(value).every((key) => key === "value" || key === "revision");
}
function categoryForStatus(status) {
	if (status === 401) return "unauthenticated";
	if (status === 403) return "forbidden";
	if (status === 404) return "not-found";
	if (status === 409) return "conflict";
	if (status === 413) return "limit-exceeded";
	if (status === 422) return "validation-failed";
	if (status === 429) return "rate-limited";
	if (status === 408 || status === 502 || status === 503 || status === 504) return "unavailable";
	if (status >= 500) return "internal";
	return "invalid-request";
}
//#endregion
//#region assets/administrator/components/studio-composition.ts
async function setupStudioComposition() {
	const root = document.querySelector("[data-studio-composition]");
	const encoded = document.querySelector("#studio-composition-boot");
	const status = document.querySelector("[data-studio-composition-status]");
	if (root === null || encoded === null || status === null) return;
	const surfaceMessages = compositionSurfaceMessages(root);
	defineKumweStudio();
	const shell = root.querySelector("kumwe-studio");
	if (shell === null) return;
	try {
		const boot = JSON.parse(encoded.textContent ?? "");
		if (boot.release !== "0.1.0-rc.1") throw new Error("Studio release binding mismatch.");
		const opened = await openHostSession(boot);
		const advertised = new Set(opened.hostCapabilities);
		const adapter = createStudioHttpHostAdapter(boot.endpoints.ports, {
			advertised,
			csrf: boot.csrf,
			...opened.preview === void 0 ? {} : { preview: opened.preview }
		});
		const ids = identifierFactories();
		const configuration = studioConfiguration(boot, opened);
		const handle = await openStudioSession(adapter, {
			configuration,
			identifiers: ids
		});
		const model = (await handle.models?.get(boot.document.model))?.value ?? boot.model;
		const { runtime } = activateStudioContributions();
		activateHostContributions(runtime, boot.contributions, boot.contributionOwners);
		const generation = runtime.current;
		const admittedBlocks = new Set(boot.contributions.filter((contribution) => contribution.kind === "block-definition").map((contribution) => `${contribution.type}@${contribution.version}#${contribution.revision}`));
		const blocks = generation.blocks().filter((block) => {
			const trustedRenderer = boot.blockRenderers[block.type];
			return admittedBlocks.has(`${block.type}@${block.version}#${block.revision}`) && trustedRenderer !== void 0 && block.rendererRequirements.some(({ capability }) => capability === trustedRenderer);
		});
		const admittedPatterns = new Set(boot.contributions.filter((contribution) => contribution.kind === "pattern").map((contribution) => `${contribution.id}@${contribution.version}#${contribution.revision}`));
		const theme = coreStudioTheme(blocks, boot.blockRenderers, boot.document.dependencyLock.theme);
		const messages = await loadMessages(adapter.localization, boot, opened, ids);
		shell.configuration = {
			session: configuration,
			blockDefinitions: blocks
		};
		shell.contentModel = model;
		shell.document = handle.session.document;
		shell.messages = messages;
		shell.patterns = generation.contributions("pattern").filter((pattern) => admittedPatterns.has(`${pattern.id}@${pattern.version}#${pattern.revision}`));
		shell.theme = theme;
		shell.designControls = theme.designControls;
		shell.viewports = theme.viewports;
		let disposePreview = () => void 0;
		let saveTail = Promise.resolve();
		let conflicted = false;
		let lifecycleChanging = false;
		const quarantineConflict = () => {
			if (conflicted) return;
			conflicted = true;
			disposePreview();
			handle.dispose();
			status.textContent = surfaceMessages.conflict;
			const refusal = document.createElement("div");
			refusal.className = "notice error";
			refusal.dataset.studioCompositionConflict = "";
			refusal.setAttribute("role", "alert");
			refusal.textContent = surfaceMessages.conflict;
			shell.replaceWith(refusal);
		};
		const save = () => {
			saveTail = saveTail.then(async () => {
				if (conflicted || !handle.session.dirty) return;
				try {
					const acceptedStateVersion = handle.session.stateVersion;
					const accepted = await handle.save();
					shell.markSaved(accepted.revision, acceptedStateVersion);
					await shell.updateComplete;
					status.textContent = surfaceMessages.saved;
				} catch (error) {
					if (isHostPortFailure(error) && error.error.category === "conflict") {
						quarantineConflict();
						return;
					}
					status.textContent = surfaceMessages.saveFailed;
					throw error;
				}
			}).catch(() => void 0);
			return saveTail;
		};
		shell.addEventListener("studio-document-change", (event) => {
			if (conflicted || lifecycleChanging || handle.session.sessionState === "read-only") return;
			const detail = event.detail;
			try {
				if (detail.source === "command" && detail.command !== null) handle.session.execute(detail.command);
				else if (detail.source === "undo") handle.session.undo();
				else if (detail.source === "redo") handle.session.redo();
				shell.document = handle.session.document;
				save();
			} catch {
				status.textContent = surfaceMessages.changeRefused;
			}
		});
		shell.addEventListener("studio-insert-request", (event) => {
			if (conflicted || lifecycleChanging || handle.session.sessionState === "read-only") return;
			const detail = event.detail;
			const command = insertCommand(shell, handle, detail, opened.sessionGeneration);
			shell.execute(command);
			shell.selectNode(command.payload.node.id);
		});
		if (opened.preview !== void 0 && adapter.preview !== void 0) {
			const frame = root.querySelector("[data-studio-preview]");
			if (frame !== null) {
				const preview = previewBinding(frame, opened.preview, opened, adapter.preview, handle, shell, save);
				shell.previewBinding = preview.binding;
				disposePreview = preview.dispose;
			}
		}
		let disposed = false;
		const dispose = () => {
			if (disposed) return;
			disposed = true;
			disposePreview();
			handle.dispose();
		};
		const lifecycleButtons = [...root.querySelectorAll("[data-studio-publish], [data-studio-unpublish]")];
		const mayChangeLifecycle = (target) => target === "published" ? opened.lifecycle.canPublish : opened.lifecycle.canUnpublish;
		for (const button of lifecycleButtons) button.hidden = !mayChangeLifecycle(button.hasAttribute("data-studio-publish") ? "published" : "draft");
		let lifecycleTail = Promise.resolve();
		const changeLifecycle = (target) => {
			lifecycleTail = lifecycleTail.then(async () => {
				if (conflicted || !mayChangeLifecycle(target) || boot.status === target) return;
				lifecycleChanging = true;
				shell.inert = true;
				shell.setAttribute("aria-busy", "true");
				for (const button of lifecycleButtons) button.disabled = true;
				status.textContent = target === "published" ? surfaceMessages.publishing : surfaceMessages.unpublishing;
				if (target === "published") {
					await save();
					await shell.updateComplete;
					if (handle.session.dirty) throw new Error("The latest draft was not accepted before publication.");
				}
				const operationId = `studio.operation/artifact.${target === "published" ? "publish" : "unpublish"}`;
				const context = {
					expectedRevision: handle.revision,
					idempotencyKey: ids.idempotencyKey(operationId),
					operationId,
					protocolVersion: opened.protocolVersion,
					requestId: ids.requestId(operationId),
					resourceContextKey: opened.resourceContextKey,
					sessionGeneration: opened.sessionGeneration
				};
				const reference = {
					id: boot.artifact.id,
					revision: handle.revision,
					version: boot.artifact.version
				};
				if ((target === "published" ? await adapter.artifact.publish(reference, context) : await adapter.artifact.unpublish(reference, context)).revision === void 0) throw new Error("The lifecycle mutation omitted its accepted revision.");
				dispose();
				window.location.reload();
			}).catch((error) => {
				if (isHostPortFailure(error) && error.error.category === "conflict") {
					quarantineConflict();
					return;
				}
				status.textContent = surfaceMessages.lifecycleFailed;
				lifecycleChanging = false;
				shell.inert = false;
				shell.removeAttribute("aria-busy");
				for (const button of lifecycleButtons) button.disabled = false;
			});
		};
		root.querySelector("[data-studio-publish]")?.addEventListener("click", () => changeLifecycle("published"));
		root.querySelector("[data-studio-unpublish]")?.addEventListener("click", () => changeLifecycle("draft"));
		status.textContent = surfaceMessages.ready;
		window.addEventListener("beforeunload", dispose, { once: true });
		window.addEventListener("pagehide", dispose, { once: true });
	} catch {
		status.textContent = surfaceMessages.openFailed;
	}
}
function compositionSurfaceMessages(root) {
	const messages = {
		changeRefused: root.dataset.messageChangeRefused,
		conflict: root.dataset.messageConflict,
		lifecycleFailed: root.dataset.messageLifecycleFailed,
		openFailed: root.dataset.messageOpenFailed,
		publishing: root.dataset.messagePublishing,
		ready: root.dataset.messageReady,
		saveFailed: root.dataset.messageSaveFailed,
		saved: root.dataset.messageSaved,
		unpublishing: root.dataset.messageUnpublishing
	};
	if (Object.values(messages).some((message) => message === void 0 || message === "")) throw new Error("The Studio composition surface message catalogue is incomplete.");
	return messages;
}
async function openHostSession(boot) {
	const response = await fetch(boot.endpoints.session, {
		body: JSON.stringify({
			mode: "blueprint",
			resourceId: boot.artifact.id,
			resourceKind: "blueprint"
		}),
		credentials: "same-origin",
		headers: {
			"content-type": "application/json",
			"X-CSRF-Token": boot.csrf
		},
		method: "POST"
	});
	if (!response.ok) throw new Error("Studio session opening was refused.");
	const value = await response.json();
	if (value.protocolVersion !== "0.1.0-draft.2") throw new Error("Studio protocol version mismatch.");
	return value;
}
function studioConfiguration(boot, opened) {
	const operations = new Set(opened.hostCapabilities.filter((capability) => capability.startsWith("studio.operation/")));
	return {
		actor: boot.actor,
		artifacts: {
			blueprint: boot.artifact,
			model: boot.document.model,
			theme: boot.document.dependencyLock.theme
		},
		blocks: boot.document.dependencyLock.blocks,
		composite: "single",
		contractVersion: "0.1-draft",
		displayPreferences: {
			calendar: "gregory",
			hourCycle: "h23",
			numberingSystem: "latn"
		},
		features: {
			clipboardMediaUpload: operations.has("studio.operation/media.authorize-upload"),
			collaboration: false,
			customInspectors: false,
			executablePlugins: false,
			externalMediaImport: operations.has("studio.operation/media.import-external"),
			offlineRecovery: [
				"store",
				"load",
				"discard"
			].every((operation) => operations.has(`studio.operation/recovery.${operation}`))
		},
		hostCapabilities: fullCapabilities(opened),
		limits: {
			maxChildrenPerSlot: 100,
			maxCommandBatch: 100,
			maxContributionsPerPlugin: 1e3,
			maxDepth: 32,
			maxExtensionBytes: 262144,
			maxHistoryEntries: 100,
			maxLocaleBytes: 262144,
			maxMediaBatch: 100,
			maxMediaUploadBytes: 52428800,
			maxNodes: 5e3,
			maxPluginCount: 100,
			maxPreviewBytes: 1048576,
			maxPreviewRequestsPerMinute: 60,
			maxPropertyBytes: 65536,
			maxRichTextBytes: 1048576,
			maxRichTextDepth: 32,
			maxSlotsPerNode: 32
		},
		locale: {
			direction: boot.locale.direction,
			fallbacks: boot.locale.fallbacks,
			requested: boot.locale.requested,
			resolved: boot.locale.resolved,
			timeZone: boot.locale.timezone
		},
		mode: "blueprint",
		permissions: opened.permissions,
		plugins: [],
		protocolVersion: opened.protocolVersion,
		preview: {
			allowApproximateRenderer: false,
			enabled: opened.preview !== void 0 && operations.has("studio.operation/preview.render"),
			initialViewport: "expanded",
			sameOriginRequired: true
		},
		resourceContext: {
			key: opened.resourceContextKey,
			resource: {
				id: boot.artifact.id,
				type: "kumwe.app/content-blueprint"
			},
			revision: boot.artifact.revision,
			scopes: [{
				id: boot.site,
				kind: "kumwe.app/site"
			}],
			surface: "kumwe.app/administrator"
		},
		sessionGeneration: opened.sessionGeneration,
		sessionId: `studio-session-${crypto.randomUUID()}`,
		sessionState: boot.status === "draft" ? "editable" : "read-only"
	};
}
function fullCapabilities(opened) {
	const grouped = /* @__PURE__ */ new Map();
	for (const capability of opened.hostCapabilities) {
		const match = /^studio\.operation\/([a-z0-9-]+)\./.exec(capability);
		if (match?.[1] === void 0) continue;
		const id = `studio.port/${match[1]}`;
		grouped.set(id, [...grouped.get(id) ?? [], capability]);
	}
	return {
		capabilities: [],
		contractVersion: "0.1-draft",
		host: {
			generation: opened.sessionGeneration,
			id: "kumwe.app/studio-host",
			version: "2.0.0"
		},
		kind: "host-capabilities",
		ports: [...grouped.entries()].sort(([left], [right]) => left.localeCompare(right)).map(([id, operations]) => ({
			id,
			operations: operations.sort(),
			version: "1.0.0"
		})),
		protocolVersions: [opened.protocolVersion]
	};
}
function identifierFactories() {
	let request = 0;
	let mutation = 0;
	return {
		requestId: () => `requests/browser-${++request}-${crypto.randomUUID()}`,
		idempotencyKey: () => `operations/browser-${++mutation}-${crypto.randomUUID()}`
	};
}
async function loadMessages(localization, boot, opened, ids) {
	if (localization === void 0) return {};
	const context = {
		locale: boot.locale.resolved,
		operationId: "studio.operation/localization.messages",
		protocolVersion: opened.protocolVersion,
		requestId: ids.requestId("studio.operation/localization.messages"),
		resourceContextKey: opened.resourceContextKey,
		sessionGeneration: opened.sessionGeneration
	};
	const result = await localization.messages(boot.locale.resolved, ["studio.shell"], context);
	return Object.fromEntries(Object.entries(result.value).map(([key, defaultMessage]) => [key, { defaultMessage }]));
}
function insertCommand(shell, handle, detail, sessionGeneration) {
	const properties = isCoreLayoutBlockType(detail.definition.type) ? coreLayoutInitialProperties(detail.definition.type) : {};
	const slots = Object.fromEntries(detail.definition.slots.map(({ id }) => [id, []]));
	let position = shell.document?.roots.length ?? 0;
	if (detail.parentId !== null && detail.slot !== void 0) position = findNode(shell.document?.roots ?? [], detail.parentId)?.slots[detail.slot]?.length ?? 0;
	return {
		artifactId: handle.session.document.id,
		baseStateVersion: shell.stateVersion,
		contractVersion: "0.1-draft",
		expectedRevision: handle.revision,
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
				id: `nodes/${crypto.randomUUID()}`,
				properties,
				slots,
				type: detail.definition.type,
				version: detail.definition.version
			}
		},
		sessionGeneration,
		type: "studio.command/insert-node"
	};
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
function previewBinding(frame, metadata, opened, previewPort, handle, shell, save) {
	if (new URL(metadata.origin).origin !== window.location.origin) throw new Error("The Studio preview origin is not the current application origin.");
	const bridge = localPreviewBridge(metadata.origin);
	let activeFrame = frame;
	let documentSequence = 0;
	let documentTail = Promise.resolve();
	let navigationGeneration = 0;
	let loaded;
	let stagingFrame;
	let geometry = observePreviewGeometry(activeFrame, shell);
	let disposed = false;
	const attempts = /* @__PURE__ */ new Set();
	let loadedAttempt;
	const cancelAttempt = (attempt) => {
		if (attempt.cancellation !== void 0) return attempt.cancellation;
		const cancellation = previewPort.cancel(attempt.digest, previewContext(opened, "cancel")).then(() => void 0);
		attempt.cancellation = cancellation;
		return cancellation;
	};
	const navigate = (rendered, signal, generation) => {
		const pending = documentTail.then(async () => {
			throwIfAborted(signal);
			if (disposed || generation !== navigationGeneration) throw abortError();
			const previewUrl = new URL(metadata.documentPath, metadata.origin);
			if (previewUrl.origin !== window.location.origin) throw new Error("The Studio preview document URL is not same-origin.");
			const sequence = documentSequence;
			documentSequence += 1;
			previewUrl.search = new URLSearchParams({
				channel: metadata.channelId,
				context: opened.resourceContextKey,
				generation: opened.sessionGeneration,
				render: rendered.requestId,
				sequence: String(sequence),
				source: metadata.sourceId
			}).toString();
			const candidate = createStagingPreviewFrame(activeFrame);
			stagingFrame = candidate;
			if (activeFrame.parentElement === null) throw new Error("The Studio preview frame is no longer attached.");
			activeFrame.after(candidate);
			try {
				await loadPreviewFrame(candidate, previewUrl, rendered, signal);
				throwIfAborted(signal);
				if (disposed || generation !== navigationGeneration) throw abortError();
				geometry.dispose();
				activeFrame.remove();
				candidate.slot = "preview";
				candidate.dataset.studioPreview = "";
				activeFrame = candidate;
				stagingFrame = void 0;
				geometry = observePreviewGeometry(activeFrame, shell);
				candidate.hidden = false;
				geometry.refreshAfterLayout();
			} catch (error) {
				candidate.remove();
				if (stagingFrame === candidate) stagingFrame = void 0;
				throw error;
			}
		});
		documentTail = pending.then(() => void 0, () => void 0);
		return pending;
	};
	const client = new PreviewClient({
		channelId: metadata.channelId,
		sessionGeneration: opened.sessionGeneration,
		source: bridge.clientSource,
		target: bridge.hostTarget,
		targetOrigin: metadata.origin
	});
	const host = new PreviewHost({
		channelId: metadata.channelId,
		renderer: "core.renderer/layout",
		sessionGeneration: opened.sessionGeneration,
		source: bridge.hostSource,
		target: bridge.clientTarget,
		targetOrigin: metadata.origin,
		viewports: [
			"compact",
			"medium",
			"expanded"
		],
		measure: async (markers, signal) => {
			const current = loaded;
			if (current === void 0 || activeFrame.hidden || activeFrame.contentWindow === null || new URLSearchParams(activeFrame.contentWindow.location.search).get("render") !== current.requestId || markers.some((marker) => !current.markers.includes(marker))) throw new Error("The Studio preview measurement does not belong to the current rendered draft.");
			return measurePreviewFrame(activeFrame, markers, signal);
		},
		render: async (payload, signal) => {
			const sameDigestPredecessors = [...attempts].filter(({ digest }) => digest === payload.draftDigest);
			if (sameDigestPredecessors.length > 0) {
				await Promise.all(sameDigestPredecessors.map(cancelAttempt));
				for (const predecessor of sameDigestPredecessors) {
					attempts.delete(predecessor);
					if (loadedAttempt === predecessor) loadedAttempt = void 0;
				}
				throwIfAborted(signal);
			}
			const generation = ++navigationGeneration;
			const attempt = {
				accepted: false,
				digest: payload.draftDigest
			};
			attempts.add(attempt);
			loaded = void 0;
			activeFrame.hidden = true;
			let aborted = signal.aborted;
			const cancel = () => cancelAttempt(attempt);
			const onAbort = () => {
				aborted = true;
				cancel().catch(() => void 0);
			};
			signal.addEventListener("abort", onAbort, { once: true });
			try {
				const result = await previewPort.render(payload, previewContext(opened, "render"));
				if (aborted || signal.aborted) throw abortError();
				await navigate(result.value, signal, generation);
				throwIfAborted(signal);
				loaded = result.value;
				attempt.accepted = true;
				loadedAttempt = attempt;
				return result.value;
			} catch (error) {
				await cancel();
				attempts.delete(attempt);
				throw error;
			} finally {
				signal.removeEventListener("abort", onAbort);
			}
		}
	});
	host.onDispose(({ draftDigest }) => {
		const digest = draftDigest ?? loaded?.draftDigest;
		const attempt = loadedAttempt !== void 0 && (digest === void 0 || loadedAttempt.digest === digest) ? loadedAttempt : [...attempts].find((candidate) => candidate.accepted && candidate.digest === digest);
		loaded = void 0;
		if (attempt !== void 0) {
			if (loadedAttempt === attempt) loadedAttempt = void 0;
			cancelAttempt(attempt).catch(() => void 0).finally(() => attempts.delete(attempt));
		}
	});
	host.onSelect(({ nodeId, reveal }) => {
		if (loaded === void 0 || reveal !== true) return;
		const marker = Object.entries(loaded.markerMap).find(([, id]) => id === nodeId)?.[0];
		if (marker === void 0) return;
		previewMarkerElement(activeFrame, marker)?.scrollIntoView({
			block: "nearest",
			inline: "nearest"
		});
		geometry.refreshAfterLayout();
	});
	host.onViewport(({ height, viewport, width: requestedWidth }) => {
		const width = requestedWidth ?? (viewport === void 0 ? void 0 : {
			compact: 360,
			medium: 768,
			expanded: 1440
		}[viewport]);
		if (width !== void 0) {
			activeFrame.style.inlineSize = `${String(width)}px`;
			if (stagingFrame !== void 0) stagingFrame.style.inlineSize = `${String(width)}px`;
		}
		if (height !== void 0) {
			activeFrame.style.blockSize = `${String(height)}px`;
			if (stagingFrame !== void 0) stagingFrame.style.blockSize = `${String(height)}px`;
		}
		geometry.refreshAfterLayout();
	});
	host.announce();
	return {
		binding: {
			client,
			stage: async (draft, { signal }) => {
				await save();
				await shell.updateComplete;
				throwIfAborted(signal);
				if (handle.session.dirty) throw new Error("The preview draft is not the current accepted artifact revision.");
				if (draft.revision !== handle.revision) throw new Error("The preview intent was superseded by the accepted artifact revision.");
				return {
					artifactId: draft.id,
					draftDigest: await computePreviewDraftDigest(draft, { subtle: crypto.subtle }),
					draftRevision: draft.revision
				};
			}
		},
		dispose: () => {
			if (disposed) return;
			disposed = true;
			navigationGeneration += 1;
			geometry.dispose();
			activeFrame.hidden = true;
			stagingFrame?.remove();
			stagingFrame = void 0;
			loaded = void 0;
			loadedAttempt = void 0;
			for (const attempt of attempts) cancelAttempt(attempt).catch(() => void 0);
			attempts.clear();
			host.teardown("studio.preview/session-ended");
		}
	};
}
function createStagingPreviewFrame(active) {
	const candidate = document.createElement("iframe");
	const omitted = /* @__PURE__ */ new Set([
		"data-studio-preview",
		"hidden",
		"slot",
		"src"
	]);
	for (const attribute of active.attributes) if (!omitted.has(attribute.name)) candidate.setAttribute(attribute.name, attribute.value);
	candidate.hidden = true;
	return candidate;
}
/**
* Coalesce volatile same-origin layout signals into the Studio shell's explicit remeasurement seam.
*/
function observePreviewGeometry(frame, shell) {
	let disposed = false;
	let animationFrame;
	let settlementFrame;
	let innerDispose = () => void 0;
	const refresh = () => {
		if (disposed || animationFrame !== void 0) return;
		animationFrame = window.requestAnimationFrame(() => {
			animationFrame = void 0;
			if (!disposed) shell.refreshPreviewGeometry();
		});
	};
	const refreshAfterLayout = () => {
		refresh();
		if (disposed || settlementFrame !== void 0) return;
		settlementFrame = window.requestAnimationFrame(() => {
			settlementFrame = void 0;
			refresh();
		});
	};
	const attachInner = () => {
		innerDispose();
		try {
			const previewWindow = frame.contentWindow;
			const previewDocument = frame.contentDocument;
			if (previewWindow === null || previewDocument === null || previewWindow.location.origin !== window.location.origin) return;
			const observer = new ResizeObserver(refreshAfterLayout);
			if (previewDocument.documentElement !== null) observer.observe(previewDocument.documentElement);
			if (previewDocument.body !== null) observer.observe(previewDocument.body);
			const viewport = previewWindow.visualViewport;
			previewWindow.addEventListener("resize", refreshAfterLayout, { passive: true });
			previewWindow.addEventListener("scroll", refreshAfterLayout, { passive: true });
			previewDocument.addEventListener("load", refreshAfterLayout, true);
			viewport?.addEventListener("resize", refreshAfterLayout, { passive: true });
			viewport?.addEventListener("scroll", refreshAfterLayout, { passive: true });
			previewDocument.fonts?.ready.then(refreshAfterLayout, () => void 0);
			innerDispose = () => {
				observer.disconnect();
				previewWindow.removeEventListener("resize", refreshAfterLayout);
				previewWindow.removeEventListener("scroll", refreshAfterLayout);
				previewDocument.removeEventListener("load", refreshAfterLayout, true);
				viewport?.removeEventListener("resize", refreshAfterLayout);
				viewport?.removeEventListener("scroll", refreshAfterLayout);
			};
			refreshAfterLayout();
		} catch {}
	};
	const frameObserver = new ResizeObserver(refreshAfterLayout);
	frameObserver.observe(frame);
	frame.addEventListener("load", attachInner);
	window.addEventListener("resize", refreshAfterLayout, { passive: true });
	window.addEventListener("scroll", refreshAfterLayout, { passive: true });
	window.visualViewport?.addEventListener("resize", refreshAfterLayout, { passive: true });
	window.visualViewport?.addEventListener("scroll", refreshAfterLayout, { passive: true });
	attachInner();
	return {
		dispose: () => {
			if (disposed) return;
			disposed = true;
			innerDispose();
			frameObserver.disconnect();
			frame.removeEventListener("load", attachInner);
			window.removeEventListener("resize", refreshAfterLayout);
			window.removeEventListener("scroll", refreshAfterLayout);
			window.visualViewport?.removeEventListener("resize", refreshAfterLayout);
			window.visualViewport?.removeEventListener("scroll", refreshAfterLayout);
			if (animationFrame !== void 0) window.cancelAnimationFrame(animationFrame);
			if (settlementFrame !== void 0) window.cancelAnimationFrame(settlementFrame);
		},
		refreshAfterLayout
	};
}
function previewContext(opened, operation) {
	return {
		operationId: `studio.operation/preview.${operation}`,
		protocolVersion: opened.protocolVersion,
		requestId: `requests/preview-${operation}-${crypto.randomUUID()}`,
		resourceContextKey: opened.resourceContextKey,
		sessionGeneration: opened.sessionGeneration
	};
}
async function loadPreviewFrame(frame, url, rendered, signal) {
	await new Promise((resolve, reject) => {
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
				throwIfAborted(signal);
				const document = frame.contentDocument;
				const location = frame.contentWindow?.location;
				if (document === null || location === void 0 || location.origin !== window.location.origin || location.pathname !== url.pathname || new URLSearchParams(location.search).get("render") !== rendered.requestId || document.contentType !== "text/html" || document.querySelector("[data-kis-surface=\"core.administrator.content-editor\"]") === null) throw new Error("The Studio preview document did not load its same-origin HTML contract.");
				const actual = Array.from(document.querySelectorAll("[data-studio-preview-marker]")).map((element) => element.getAttribute("data-studio-preview-marker"));
				if (actual.some((marker) => marker === null) || actual.length !== rendered.markers.length || rendered.markers.some((marker) => !actual.includes(marker))) throw new Error("The Studio preview marker inventory does not match the rendered response.");
				finish();
			} catch (error) {
				finish(error instanceof Error ? error : /* @__PURE__ */ new Error("The Studio preview document was invalid."));
			}
		};
		const timeout = window.setTimeout(() => finish(/* @__PURE__ */ new Error("The Studio preview document did not finish loading.")), 1e4);
		frame.addEventListener("load", onLoad);
		frame.src = url.toString();
	});
}
async function measurePreviewFrame(frame, markers, signal) {
	throwIfAborted(signal);
	const document = frame.contentDocument;
	const previewWindow = frame.contentWindow;
	if (document === null || previewWindow === null || previewWindow.location.origin !== window.location.origin) throw new Error("The Studio preview frame is not available for same-origin measurement.");
	const requested = new Set(markers);
	const rects = Object.create(null);
	const elements = document.querySelectorAll("[data-studio-preview-marker]");
	if (elements.length > 1e5) throw new Error("The Studio preview marker inventory is too large.");
	for (const element of elements) {
		const marker = element.getAttribute("data-studio-preview-marker");
		if (marker === null || !requested.has(marker)) continue;
		const fragments = element.getClientRects();
		if (fragments.length > 1e3) throw new Error("A Studio preview marker has too many rectangles.");
		for (const rect of fragments) {
			const member = {
				height: cssExtent(rect.height),
				width: cssExtent(rect.width),
				x: cssCoordinate(rect.x),
				y: cssCoordinate(rect.y)
			};
			(rects[marker] ??= []).push(member);
		}
	}
	throwIfAborted(signal);
	return {
		rects,
		viewport: {
			devicePixelRatio: positiveRatio(previewWindow.devicePixelRatio),
			height: cssExtent(previewWindow.innerHeight),
			scrollX: cssCoordinate(previewWindow.scrollX),
			scrollY: cssCoordinate(previewWindow.scrollY),
			width: cssExtent(previewWindow.innerWidth)
		}
	};
}
function previewMarkerElement(frame, marker) {
	for (const element of frame.contentDocument?.querySelectorAll("[data-studio-preview-marker]") ?? []) if (element.getAttribute("data-studio-preview-marker") === marker) return element;
}
function cssCoordinate(value) {
	if (!Number.isFinite(value) || Math.abs(value) > 1e8) throw new Error("A Studio preview coordinate is outside the protocol bounds.");
	return value;
}
function cssExtent(value) {
	if (!Number.isFinite(value) || value < 0 || value > 1e8) throw new Error("A Studio preview extent is outside the protocol bounds.");
	return value;
}
function positiveRatio(value) {
	if (!Number.isFinite(value) || value <= 0 || value > 100) throw new Error("The Studio preview pixel ratio is outside the protocol bounds.");
	return value;
}
function throwIfAborted(signal) {
	if (signal.aborted) throw abortError();
}
function abortError() {
	return new DOMException("The Studio preview operation was aborted.", "AbortError");
}
function localPreviewBridge(origin) {
	const clientSource = new LocalMessageSource();
	const hostSource = new LocalMessageSource();
	const clientTarget = { postMessage: (data) => clientSource.emit({
		data,
		origin,
		source: hostTarget
	}) };
	const hostTarget = { postMessage: (data) => hostSource.emit({
		data,
		origin,
		source: clientTarget
	}) };
	return {
		clientSource,
		clientTarget,
		hostSource,
		hostTarget
	};
}
var LocalMessageSource = class {
	listeners = /* @__PURE__ */ new Set();
	addEventListener(_type, listener) {
		this.listeners.add(listener);
	}
	removeEventListener(_type, listener) {
		this.listeners.delete(listener);
	}
	emit(event) {
		queueMicrotask(() => this.listeners.forEach((listener) => listener(event)));
	}
};
//#endregion
export { setupStudioComposition };
