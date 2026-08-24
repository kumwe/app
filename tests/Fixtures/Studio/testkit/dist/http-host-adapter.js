import { isHostPortError, STUDIO_CONTRACT_VERSION, } from '@kumwe/studio-protocol';
import { TestbedHostError } from './host-testbed.js';
/**
 * A `HostAdapter` that speaks JSON over an injected fetch-like transport to a
 * host server: every port operation becomes
 * `POST {baseUrl}/ports/{port}/{operation}` with a
 * `{ arguments, context }` JSON body.
 *
 * Success responses (2xx) must carry a `HostPortResult` JSON body. Failure
 * responses that carry a guard-conforming `HostPortError` body are re-thrown
 * as that canonical error, so a host-authored category (with its safe
 * revision on conflicts) crosses the transport intact. Everything else is
 * mapped onto the canonical host error categories without disclosing
 * transport details: network refusal and deadline expiry become
 * `unavailable`, HTTP statuses map by class (401 `unauthenticated`, 403
 * `forbidden`, 404 `not-found`, 409 `conflict`, 413 `limit-exceeded`, 422
 * `validation-failed`, 429 `rate-limited`, 5xx `internal`/`unavailable`), and
 * a malformed body — unparseable JSON, a result without `value`, or an error
 * document the guard rejects — becomes `internal`. Every rejection is a
 * `TestbedHostError` whose `error` satisfies `isHostPortError`, and no
 * message ever echoes response bodies, addresses, or underlying reasons.
 */
export function createHttpHostAdapter(baseUrl, options) {
    const base = baseUrl.endsWith('/') ? baseUrl.slice(0, -1) : baseUrl;
    const timeoutMilliseconds = options.timeoutMilliseconds ?? 10_000;
    let correlationSerial = 0;
    function createError(failure) {
        correlationSerial += 1;
        const error = {
            category: failure.category,
            contractVersion: STUDIO_CONTRACT_VERSION,
            correlationId: `http-transport-${correlationSerial}`,
            kind: 'host-error',
            message: { defaultMessage: failure.defaultMessage, key: failure.key },
            retryable: failure.retryable,
        };
        return new TestbedHostError(error);
    }
    async function call(portName, operation, callArguments, context) {
        const handle = options.createTimeoutSignal?.(timeoutMilliseconds);
        let response;
        try {
            response = await options.fetchImplementation(`${base}/ports/${portName}/${operation}`, {
                body: JSON.stringify({ arguments: callArguments, context }),
                headers: { 'content-type': 'application/json' },
                method: 'POST',
                ...(handle === undefined ? {} : { signal: handle.signal }),
            });
        }
        catch (reason) {
            throw createError(classifyTransportFailure(reason));
        }
        finally {
            handle?.release?.();
        }
        let bodyText;
        try {
            bodyText = await response.text();
        }
        catch {
            throw createError(malformedResponse());
        }
        if (response.status >= 200 && response.status < 300) {
            const parsed = parseJson(bodyText);
            if (!isHostPortResult(parsed)) {
                throw createError(malformedResponse());
            }
            const result = {
                ...(parsed.revision === undefined ? {} : { revision: parsed.revision }),
                value: parsed.value,
            };
            return result;
        }
        const parsed = parseJson(bodyText);
        if (isHostPortError(parsed)) {
            // The host authored a canonical error; transport it verbatim.
            throw new TestbedHostError(parsed);
        }
        throw createError(statusFailure(response.status));
    }
    return {
        artifact: {
            dependencies(reference, context) {
                return call('artifact', 'dependencies', { reference: asJson(reference) }, context);
            },
            load(reference, context) {
                return call('artifact', 'load', { reference: asJson(reference) }, context);
            },
            publish(reference, context) {
                return call('artifact', 'publish', { reference: asJson(reference) }, context);
            },
            save(document, context) {
                return call('artifact', 'save', { document: asJson(document) }, context);
            },
            unpublish(reference, context) {
                return call('artifact', 'unpublish', { reference: asJson(reference) }, context);
            },
        },
        localization: {
            messages(locale, namespaces, context) {
                return call('localization', 'messages', { locale, namespaces }, context);
            },
        },
        media: {
            abortUpload(uploadId, context) {
                return call('media', 'abort-upload', { uploadId }, context);
            },
            authorizeUpload(request, context) {
                return call('media', 'authorize-upload', { request: asJson(request) }, context);
            },
            completeUpload(uploadId, context) {
                return call('media', 'complete-upload', { uploadId }, context);
            },
            get(assetId, context) {
                return call('media', 'get', { assetId }, context);
            },
            importExternal(url, context) {
                return call('media', 'import-external', { url }, context);
            },
            list(query, context) {
                return call('media', 'list', { query: asJson(query) }, context);
            },
            uploadStatus(assetId, context) {
                return call('media', 'upload-status', { assetId }, context);
            },
        },
        model: {
            get(reference, context) {
                return call('model', 'get', { reference: asJson(reference) }, context);
            },
            list(context) {
                return call('model', 'list', {}, context);
            },
        },
        permission: {
            explain(operation, context) {
                return call('permission', 'explain', { operation }, context);
            },
            refresh(context) {
                return call('permission', 'refresh', {}, context);
            },
        },
        preview: {
            cancel(draftDigest, context) {
                return call('preview', 'cancel', { draftDigest }, context);
            },
            render(payload, context) {
                return call('preview', 'render', { payload: asJson(payload) }, context);
            },
        },
        recovery: {
            discard(context) {
                return call('recovery', 'discard', {}, context);
            },
            load(context) {
                return call('recovery', 'load', {}, context);
            },
            store(envelope, context) {
                return call('recovery', 'store', { envelope }, context);
            },
        },
        resource: {
            search(query, context) {
                return call('resource', 'search', { query: asJson(query) }, context);
            },
        },
        telemetry: {
            emit(event, context) {
                return call('telemetry', 'emit', { event: asJson(event) }, context);
            },
        },
    };
}
/** Serializable typed values cross the wire as their JSON projections. */
function asJson(value) {
    return JSON.parse(JSON.stringify(value));
}
function parseJson(text) {
    try {
        return JSON.parse(text);
    }
    catch {
        return undefined;
    }
}
function isHostPortResult(value) {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }
    const record = value;
    if (!Object.hasOwn(record, 'value')) {
        return false;
    }
    const revision = record.revision;
    if (revision !== undefined && (typeof revision !== 'string' || revision.length === 0)) {
        return false;
    }
    return Object.keys(record).every((key) => key === 'value' || key === 'revision');
}
function classifyTransportFailure(reason) {
    const name = typeof reason === 'object' && reason !== null && 'name' in reason ? reason.name : undefined;
    if (name === 'AbortError' || name === 'TimeoutError') {
        // The injected deadline signal fired before the host answered.
        return {
            category: 'unavailable',
            defaultMessage: 'The host did not respond within the transport deadline.',
            key: 'studio.testkit/http-timeout',
            retryable: true,
        };
    }
    return {
        category: 'unavailable',
        defaultMessage: 'The host could not be reached.',
        key: 'studio.testkit/http-unreachable',
        retryable: true,
    };
}
function malformedResponse() {
    return {
        category: 'internal',
        defaultMessage: 'The host response could not be interpreted.',
        key: 'studio.testkit/http-malformed-response',
        retryable: false,
    };
}
function statusFailure(status) {
    const category = categoryForStatus(status);
    return {
        category,
        defaultMessage: 'The host rejected the request.',
        key: `studio.testkit/http-status-${category}`,
        retryable: category === 'rate-limited' || category === 'unavailable',
    };
}
function categoryForStatus(status) {
    switch (status) {
        case 401:
            return 'unauthenticated';
        case 403:
            return 'forbidden';
        case 404:
            return 'not-found';
        case 408:
            return 'unavailable';
        case 409:
            return 'conflict';
        case 413:
            return 'limit-exceeded';
        case 422:
            return 'validation-failed';
        case 429:
            return 'rate-limited';
        case 502:
        case 503:
        case 504:
            return 'unavailable';
        default:
            if (status >= 400 && status < 500) {
                return 'invalid-request';
            }
            return 'internal';
    }
}
//# sourceMappingURL=http-host-adapter.js.map