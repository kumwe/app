import { isHostPortError } from './guards.js';
/**
 * The only rejection wrapper a typed host port exposes to Studio callers.
 *
 * Keeping the serializable `HostPortError` under one public `error` member
 * prevents transports and adapters from leaking implementation exceptions,
 * stack traces, response objects, or other host-private state across the
 * authority boundary.
 */
export class HostPortFailure extends Error {
    error;
    constructor(error) {
        if (!isHostPortError(error)) {
            throw new TypeError('HostPortFailure requires a canonical HostPortError.');
        }
        super(error.message.defaultMessage ?? error.message.key);
        this.name = 'HostPortFailure';
        this.error = error;
    }
}
/** Whether an unknown rejection is the public typed host-port wrapper. */
export function isHostPortFailure(value) {
    return (value instanceof Error &&
        'error' in value &&
        isHostPortError(value.error));
}
//# sourceMappingURL=host-failure.js.map