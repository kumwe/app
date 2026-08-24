import type { HostPortError } from './types.js';
/**
 * The only rejection wrapper a typed host port exposes to Studio callers.
 *
 * Keeping the serializable `HostPortError` under one public `error` member
 * prevents transports and adapters from leaking implementation exceptions,
 * stack traces, response objects, or other host-private state across the
 * authority boundary.
 */
export declare class HostPortFailure extends Error {
    readonly error: HostPortError;
    constructor(error: HostPortError);
}
/** Whether an unknown rejection is the public typed host-port wrapper. */
export declare function isHostPortFailure(value: unknown): value is HostPortFailure;
//# sourceMappingURL=host-failure.d.ts.map