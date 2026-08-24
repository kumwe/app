import { type HostPortError, type PreviewMessage, type PreviewRenderedPayload } from './types.js';
export declare function isHostPortError(value: unknown): value is HostPortError;
export declare function isPreviewMessage(value: unknown): value is PreviewMessage;
export declare function isPreviewRenderedPayload(value: unknown): value is PreviewRenderedPayload;
/** Whether a value has the canonical preview marker grammar, optionally for one draft. */
export declare function isPreviewMarker(value: unknown, draftDigest?: string): value is string;
//# sourceMappingURL=guards.d.ts.map