import { STUDIO_CONTRACT_VERSION, STUDIO_WIRE_PROTOCOL_VERSION, } from './types.js';
const qualifiedName = /^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*\/[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/u;
const localName = /^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/u;
const previewDigest = /^[a-f0-9]{64}$/u;
const previewMarker = /^studio\.preview\/node\/([a-f0-9]{64})\/(0|[1-9][0-9]{0,4})$/u;
const hostErrorCategories = new Set([
    'cancelled',
    'conflict',
    'forbidden',
    'incompatible',
    'internal',
    'invalid-request',
    'limit-exceeded',
    'not-found',
    'rate-limited',
    'unauthenticated',
    'unavailable',
    'validation-failed',
]);
export function isHostPortError(value) {
    return (isRecord(value) &&
        hasExactKeys(value, ['contractVersion', 'kind', 'category', 'message', 'retryable'], ['correlationId', 'diagnostics', 'retryAfterMilliseconds', 'revision']) &&
        value.contractVersion === STUDIO_CONTRACT_VERSION &&
        value.kind === 'host-error' &&
        typeof value.category === 'string' &&
        hostErrorCategories.has(value.category) &&
        isMessageReference(value.message) &&
        typeof value.retryable === 'boolean' &&
        (value.correlationId === undefined || isStableId(value.correlationId)) &&
        (value.revision === undefined || isRevision(value.revision)) &&
        (value.retryAfterMilliseconds === undefined ||
            (isNonNegativeInteger(value.retryAfterMilliseconds) &&
                value.retryAfterMilliseconds <= 86_400_000)) &&
        (value.diagnostics === undefined || isArrayOf(value.diagnostics, isDiagnostic, 1_000)));
}
export function isPreviewMessage(value) {
    if (!isRecord(value) ||
        !hasExactKeys(value, [
            'contractVersion',
            'kind',
            'channelId',
            'sessionGeneration',
            'sequence',
            'type',
            'payload',
        ]) ||
        value.contractVersion !== STUDIO_CONTRACT_VERSION ||
        value.kind !== 'preview-message' ||
        !isStableId(value.channelId) ||
        !isRevision(value.sessionGeneration) ||
        !isNonNegativeInteger(value.sequence) ||
        typeof value.type !== 'string' ||
        !isRecord(value.payload)) {
        return false;
    }
    switch (value.type) {
        case 'studio.preview/ready':
            return isReadyPayload(value.payload);
        case 'studio.preview/render':
            return isRenderPayload(value.payload);
        case 'studio.preview/rendered':
            return isPreviewRenderedPayload(value.payload);
        case 'studio.preview/select':
            return isSelectPayload(value.payload);
        case 'studio.preview/measure':
            return isMeasurePayload(value.payload);
        case 'studio.preview/measurements':
            return isMeasurementsPayload(value.payload);
        case 'studio.preview/error':
            return isErrorPayload(value.payload);
        case 'studio.preview/reload':
        case 'studio.preview/teardown':
            return isReasonPayload(value.payload);
        case 'studio.preview/activated':
            return isActivatedPayload(value.payload);
        case 'studio.preview/viewport':
            return isViewportPayload(value.payload);
        case 'studio.preview/dispose':
            return isDisposePayload(value.payload);
        default:
            return false;
    }
}
function isActivatedPayload(value) {
    return (hasExactKeys(value, ['interaction', 'marker']) &&
        isPreviewMarker(value.marker) &&
        (value.interaction === 'activate' ||
            value.interaction === 'context-menu' ||
            value.interaction === 'focus'));
}
/** A semantic role and explicit dimensions are alternatives, never a merge. */
function isViewportPayload(value) {
    const keys = Object.keys(value);
    if (keys.length === 0 || keys.some((key) => !['height', 'viewport', 'width'].includes(key))) {
        return false;
    }
    const hasRole = Object.hasOwn(value, 'viewport');
    const hasWidth = Object.hasOwn(value, 'width');
    const hasHeight = Object.hasOwn(value, 'height');
    const hasDimensions = hasWidth || hasHeight;
    if (hasRole === hasDimensions) {
        return false;
    }
    if (hasRole) {
        return isLocalName(value.viewport);
    }
    return ((!hasWidth || isBoundedDimension(value.width)) &&
        (!hasHeight || isBoundedDimension(value.height)));
}
function isBoundedDimension(value) {
    return (typeof value === 'number' && Number.isSafeInteger(value) && value >= 240 && value <= 10_000);
}
function isDisposePayload(value) {
    if (!isQualifiedName(value.reason)) {
        return false;
    }
    const keys = Object.keys(value);
    if (keys.some((key) => key !== 'draftDigest' && key !== 'reason')) {
        return false;
    }
    return (value.draftDigest === undefined ||
        (typeof value.draftDigest === 'string' && previewDigest.test(value.draftDigest)));
}
function isReasonPayload(value) {
    return hasExactKeys(value, ['reason']) && isQualifiedName(value.reason);
}
function isReadyPayload(value) {
    return (hasExactKeys(value, ['protocolVersion', 'renderer', 'viewports']) &&
        value.protocolVersion === STUDIO_WIRE_PROTOCOL_VERSION &&
        isQualifiedName(value.renderer) &&
        isStringArray(value.viewports, isLocalName, 20));
}
function isRenderPayload(value) {
    return (hasExactKeys(value, ['artifactId', 'draftDigest', 'draftRevision', 'requestId', 'viewport']) &&
        isStableId(value.artifactId) &&
        typeof value.draftDigest === 'string' &&
        previewDigest.test(value.draftDigest) &&
        isRevision(value.draftRevision) &&
        isStableId(value.requestId) &&
        isLocalName(value.viewport));
}
export function isPreviewRenderedPayload(value) {
    if (!isRecord(value) ||
        !hasExactKeys(value, ['requestId', 'draftDigest', 'markers', 'markerMap', 'diagnostics']) ||
        !isStableId(value.requestId) ||
        typeof value.draftDigest !== 'string' ||
        !previewDigest.test(value.draftDigest) ||
        !isStringArray(value.markers, isPreviewMarker, 100_000) ||
        new Set(value.markers).size !== value.markers.length ||
        !isArrayOf(value.diagnostics, isDiagnostic, 10_000) ||
        !isMarkerMap(value.markerMap)) {
        return false;
    }
    const markerMap = value.markerMap;
    const markerKeys = Object.keys(markerMap);
    if (markerKeys.length !== value.markers.length) {
        return false;
    }
    const nodeIds = Object.values(markerMap);
    if (new Set(nodeIds).size !== nodeIds.length) {
        return false;
    }
    return value.markers.every((marker, ordinal) => {
        const match = previewMarker.exec(marker);
        return (match !== null &&
            match[1] === value.draftDigest &&
            Number(match[2]) === ordinal &&
            Object.hasOwn(markerMap, marker));
    });
}
function isMarkerMap(value) {
    if (!isRecord(value)) {
        return false;
    }
    const entries = Object.entries(value);
    return (entries.length <= 100_000 &&
        entries.every(([marker, nodeId]) => isPreviewMarker(marker) && isStableId(nodeId)));
}
function isMeasurePayload(value) {
    return (hasExactKeys(value, ['requestId', 'markers']) &&
        isStableId(value.requestId) &&
        isStringArray(value.markers, isPreviewMarker, 1_000) &&
        value.markers.length >= 1 &&
        new Set(value.markers).size === value.markers.length);
}
function isMeasurementsPayload(value) {
    if (!hasExactKeys(value, ['requestId', 'draftDigest', 'measurements', 'unknown', 'viewport']) ||
        !isStableId(value.requestId) ||
        typeof value.draftDigest !== 'string' ||
        !previewDigest.test(value.draftDigest) ||
        !isMeasurementMap(value.measurements) ||
        !isStringArray(value.unknown, isPreviewMarker, 1_000) ||
        new Set(value.unknown).size !== value.unknown.length ||
        !isViewportMetrics(value.viewport)) {
        return false;
    }
    const markers = [...Object.keys(value.measurements), ...value.unknown];
    return (new Set(markers).size === markers.length &&
        markers.every((marker) => previewMarker.exec(marker)?.[1] === value.draftDigest));
}
function isMeasurementMap(value) {
    if (!isRecord(value)) {
        return false;
    }
    const entries = Object.entries(value);
    return (entries.length <= 1_000 &&
        entries.every(([marker, rects]) => isPreviewMarker(marker) && isArrayOf(rects, isMarkerRect, 1_000) && rects.length >= 1));
}
function isMarkerRect(value) {
    return (isRecord(value) &&
        hasExactKeys(value, ['x', 'y', 'width', 'height']) &&
        isCssCoordinate(value.x) &&
        isCssCoordinate(value.y) &&
        isCssExtent(value.width) &&
        isCssExtent(value.height));
}
function isViewportMetrics(value) {
    return (isRecord(value) &&
        hasExactKeys(value, ['width', 'height', 'scrollX', 'scrollY', 'devicePixelRatio']) &&
        isCssExtent(value.width) &&
        isCssExtent(value.height) &&
        isCssCoordinate(value.scrollX) &&
        isCssCoordinate(value.scrollY) &&
        typeof value.devicePixelRatio === 'number' &&
        Number.isFinite(value.devicePixelRatio) &&
        value.devicePixelRatio > 0 &&
        value.devicePixelRatio <= 100);
}
function isSelectPayload(value) {
    return (hasExactKeys(value, ['nodeId'], ['reveal']) &&
        isStableId(value.nodeId) &&
        (value.reveal === undefined || typeof value.reveal === 'boolean'));
}
function isErrorPayload(value) {
    return (hasExactKeys(value, ['code', 'message', 'retryable'], ['correlationId']) &&
        isQualifiedName(value.code) &&
        isMessageReference(value.message) &&
        typeof value.retryable === 'boolean' &&
        (value.correlationId === undefined || isStableId(value.correlationId)));
}
function isDiagnostic(value) {
    if (!isRecord(value) ||
        !hasExactKeys(value, ['code', 'severity', 'message'], ['location', 'parameters', 'remediations']) ||
        !isQualifiedName(value.code) ||
        typeof value.severity !== 'string' ||
        !['information', 'warning', 'error', 'blocking'].includes(value.severity) ||
        !isMessageReference(value.message)) {
        return false;
    }
    if (value.location !== undefined && !isDiagnosticLocation(value.location)) {
        return false;
    }
    if (value.parameters !== undefined &&
        (!isRecord(value.parameters) ||
            Object.keys(value.parameters).length > 20 ||
            !Object.keys(value.parameters).every((key) => isSafeJsonMemberName(key)) ||
            !Object.values(value.parameters).every((entry) => entry === null ||
                typeof entry === 'boolean' ||
                typeof entry === 'string' ||
                (typeof entry === 'number' && Number.isFinite(entry))))) {
        return false;
    }
    return value.remediations === undefined || isStringArray(value.remediations, isQualifiedName, 10);
}
function isDiagnosticLocation(value) {
    if (!isRecord(value) ||
        !hasExactKeys(value, [], ['artifactId', 'nodeId', 'fieldPath', 'jsonPointer'])) {
        return false;
    }
    return ((value.artifactId === undefined || isStableId(value.artifactId)) &&
        (value.nodeId === undefined || isStableId(value.nodeId)) &&
        (value.fieldPath === undefined || isStringArray(value.fieldPath, isLocalName, 32)) &&
        (value.jsonPointer === undefined ||
            (typeof value.jsonPointer === 'string' && value.jsonPointer.length <= 1_000)));
}
function isMessageReference(value) {
    return (isRecord(value) &&
        hasExactKeys(value, ['key'], ['defaultMessage']) &&
        isQualifiedName(value.key) &&
        (value.defaultMessage === undefined ||
            (typeof value.defaultMessage === 'string' &&
                value.defaultMessage.length > 0 &&
                value.defaultMessage.length <= 500)));
}
function hasExactKeys(value, required, optional = []) {
    const allowed = new Set([...required, ...optional]);
    return (required.every((key) => Object.hasOwn(value, key)) &&
        Object.keys(value).every((key) => allowed.has(key)));
}
function isRecord(value) {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }
    const prototype = Object.getPrototypeOf(value);
    return prototype === Object.prototype || prototype === null;
}
function isNonNegativeInteger(value) {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}
// CSS-pixel geometry stays finite and bounded so a hostile responder cannot smuggle
// NaN, infinities, or absurd magnitudes into overlay arithmetic.
const cssPixelLimit = 100_000_000;
function isCssCoordinate(value) {
    return typeof value === 'number' && Number.isFinite(value) && Math.abs(value) <= cssPixelLimit;
}
function isCssExtent(value) {
    return (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= cssPixelLimit);
}
function isQualifiedName(value) {
    return typeof value === 'string' && value.length <= 160 && qualifiedName.test(value);
}
function isLocalName(value) {
    return (typeof value === 'string' &&
        value.length <= 100 &&
        !isForbiddenObjectMemberName(value) &&
        localName.test(value));
}
function isStableId(value) {
    return (typeof value === 'string' &&
        value.length <= 240 &&
        !isForbiddenObjectMemberName(value) &&
        /^[A-Za-z0-9][A-Za-z0-9._:/-]*$/u.test(value));
}
/** Whether a value has the canonical preview marker grammar, optionally for one draft. */
export function isPreviewMarker(value, draftDigest) {
    if (typeof value !== 'string') {
        return false;
    }
    const match = previewMarker.exec(value);
    return match !== null && (draftDigest === undefined || match[1] === draftDigest);
}
function isRevision(value) {
    return typeof value === 'string' && value.length >= 1 && value.length <= 200;
}
function isStringArray(value, predicate, maximumItems) {
    return isArrayOf(value, predicate, maximumItems);
}
function isArrayOf(value, predicate, maximumItems) {
    if (!Array.isArray(value) || value.length > maximumItems || !isDenseArray(value)) {
        return false;
    }
    for (const entry of value) {
        if (!predicate(entry)) {
            return false;
        }
    }
    return true;
}
function isDenseArray(value) {
    if (Object.getPrototypeOf(value) !== Array.prototype ||
        Object.getOwnPropertySymbols(value).length) {
        return false;
    }
    const names = Object.getOwnPropertyNames(value);
    return (names.length === value.length + 1 &&
        names[value.length] === 'length' &&
        names.slice(0, -1).every((name, index) => name === String(index)));
}
function isSafeJsonMemberName(value) {
    if (value.length === 0 || value.length > 200 || isForbiddenObjectMemberName(value)) {
        return false;
    }
    for (let index = 0; index < value.length; index += 1) {
        const code = value.charCodeAt(index);
        if (code <= 0x1f || code === 0x7f) {
            return false;
        }
    }
    return true;
}
function isForbiddenObjectMemberName(value) {
    return value === '__proto__' || value === 'prototype' || value === 'constructor';
}
//# sourceMappingURL=guards.js.map