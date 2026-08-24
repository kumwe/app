import { type HostAdapter } from '@kumwe/studio-protocol';
/**
 * Minimal structural view of an HTTP response the adapter needs: the status
 * code and the raw body text. Deliberately narrower than any platform
 * response class so implementations from any runtime satisfy it.
 */
export interface HttpResponseLike {
    status: number;
    text(): Promise<string>;
}
/** The request shape the adapter hands to the injected transport. */
export interface HttpRequestInit {
    body: string;
    headers: Record<string, string>;
    method: 'POST';
    /** Opaque abort signal produced by the injected timeout factory, if any. */
    signal?: unknown;
}
/**
 * The injected fetch-like transport — the adapter's portability seam.
 *
 * Testkit source is deliberately free of host globals (no fetch, no DOM, no
 * Node), so the embedder supplies the platform transport: a Node test injects
 * a `node:http`-backed implementation, a browser host would inject the
 * platform fetch, and either stays outside this package. The function must
 * resolve with the terminal HTTP response (any status) and reject only for
 * transport-level failures: network refusal, connection loss, or an abort
 * raised by the injected timeout signal.
 */
export type HttpFetchLike = (url: string, init: HttpRequestInit) => Promise<HttpResponseLike>;
/**
 * An abort signal handle minted per request by the injected timeout factory.
 * `signal` is passed through to the transport untouched; `release` (when
 * present) is invoked once the request settles so timer-backed factories can
 * clean up.
 */
export interface HttpTimeoutHandle {
    release?(): void;
    signal: unknown;
}
export interface HttpHostAdapterOptions {
    /**
     * Second half of the portability seam: mints the per-request timeout
     * signal. When omitted, requests carry no deadline. A rejection whose
     * reason is named `AbortError` or `TimeoutError` is mapped to the canonical
     * deadline failure.
     */
    createTimeoutSignal?: (timeoutMilliseconds: number) => HttpTimeoutHandle;
    fetchImplementation: HttpFetchLike;
    /** Transport deadline handed to `createTimeoutSignal`. Defaults to 10 000. */
    timeoutMilliseconds?: number;
}
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
export declare function createHttpHostAdapter(baseUrl: string, options: HttpHostAdapterOptions): HostAdapter;
//# sourceMappingURL=http-host-adapter.d.ts.map