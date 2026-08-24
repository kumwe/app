import { HostPortFailure, type HostAdapter, type HostErrorCategory, type HostPortError, type HostPortResult, type HostRequestContext, type JsonObject, type MediaAsset, type MediaUploadAcceptedAsset, type PreviewRenderPayload, type PreviewRenderedPayload, type QualifiedName, type ResourceSearchHit, type Revision, type StableId, type StudioArtifact, type StudioWireProtocolVersion, type TelemetryEvent } from '@kumwe/studio-protocol';
export type TestbedPortName = 'artifact' | 'localization' | 'media' | 'model' | 'permission' | 'preview' | 'recovery' | 'resource' | 'telemetry';
export interface TestbedHostOptions {
    /**
     * Permits the fixture-only `studio.test/operation` wildcard used by broad
     * unit tests. Defaults to false and MUST remain false for conformance replay.
     */
    allowTestOperationId?: boolean;
    documents?: StudioArtifact[];
    initialClockMilliseconds?: number;
    mediaAssets?: MediaAsset[];
    messages?: Record<string, Record<QualifiedName, string>>;
    permissions?: QualifiedName[];
    rateLimits?: TestbedRateLimitPolicy[];
    render?: (payload: PreviewRenderPayload) => PreviewRenderedPayload | Promise<PreviewRenderedPayload>;
    resources?: ResourceSearchHit[];
    /** Exact live generation to seed before the first portable request. */
    sessionGeneration?: Revision;
}
/** A deterministic fixed-window policy used by portable sequence replays. */
export interface TestbedRateLimitPolicy {
    maximumRequests: number;
    operationId: QualifiedName;
    windowMilliseconds: number;
}
export interface TestbedControls {
    advanceClock(milliseconds: number): void;
    artifactStatus(id: StableId): StudioArtifact['status'] | undefined;
    disconnect(): void;
    failNext(portName: TestbedPortName, operation: string, category: HostErrorCategory): void;
    /**
     * Standalone external-import drill. The wire protocol's media port does not
     * yet carry the media contract's explicit external-import operation, so the
     * testbed exercises the canonical external-URL policy here, under the same
     * request guards as the wire ports (`failNext` targets port `media`,
     * operation `import-external`). A candidate that fails the default policy
     * rejects with a `validation-failed` host error that names the stable
     * rejection reason but never echoes the candidate URL. An accepted
     * candidate mints a deterministic asset identity in `processing` state that
     * the media port can then serve.
     */
    importExternalMedia(candidate: string, context: HostRequestContext): Promise<HostPortResult<MediaUploadAcceptedAsset>>;
    reconnect(): void;
    recoveryEnvelope(resourceContextKey: StableId): JsonObject | undefined;
    revisionOf(id: StableId): Revision | undefined;
    readonly pendingPreviewRenders: number;
    readonly previewDeliveries: readonly string[];
    readonly sessionGeneration: Revision;
    setPermissions(permissions: QualifiedName[]): void;
    readonly telemetryEvents: readonly TelemetryEvent[];
}
export interface TestbedHost {
    controls: TestbedControls;
    host: HostAdapter;
}
export interface HostRequestContextFixtureOptions {
    expectedRevision?: Revision;
    idempotencyKey?: StableId;
    locale?: string;
    operationId?: QualifiedName;
    protocolVersion?: StudioWireProtocolVersion;
    requestId?: StableId;
    resourceContextKey?: StableId;
    sessionGeneration?: Revision;
}
export declare class TestbedHostError extends HostPortFailure {
    constructor(error: HostPortError);
}
export declare function createHostRequestContextFixture(options?: HostRequestContextFixtureOptions): HostRequestContext;
export declare function createTestbedHost(options?: TestbedHostOptions): TestbedHost;
//# sourceMappingURL=host-testbed.d.ts.map