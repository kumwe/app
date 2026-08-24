import { type StudioSchemaProfileErrorCode } from '@kumwe/studio-core';
import { type BlockDefinition, type BlockSlotDefinition, type BlockType, type BlueprintDocument, type BlueprintBlockLock, type BlueprintNode, type ContentFieldKind, type ContentModelDocument, type DiagnosticLocation, type FieldDefinition, type JsonSchema, type StudioConfiguration, type StudioDiagnostic } from '@kumwe/studio-protocol';
export { createHostRequestContextFixture, createTestbedHost, TestbedHostError, type HostRequestContextFixtureOptions, type TestbedControls, type TestbedHost, type TestbedHostOptions, type TestbedPortName, type TestbedRateLimitPolicy, } from './host-testbed.js';
export { createHttpHostAdapter, type HttpFetchLike, type HttpHostAdapterOptions, type HttpRequestInit, type HttpResponseLike, type HttpTimeoutHandle, } from './http-host-adapter.js';
export interface BlueprintFixtureOptions {
    blockLocks?: BlueprintBlockLock[];
    id?: string;
    revision?: string;
    roots?: BlueprintNode[];
}
export interface TestBlockOptions {
    label?: string;
    propertySchema?: JsonSchema;
    slots?: BlockSlotDefinition[];
    type: BlockType;
    version?: string;
}
export interface StudioConfigurationFixtureOptions {
    composite?: 'hybrid' | 'single';
    mode?: 'blueprint' | 'content' | 'model';
    sessionState?: 'editable' | 'read-only';
}
export interface SchemaProfileVectorInstance {
    value: unknown;
    valid: boolean;
}
/** Portable input consumed by `studio.profile/binding-projection-v1`. */
export interface BindingProjectionVectorInput {
    blockDefinitions: readonly BlockDefinition[];
    blueprint: BlueprintDocument;
    model: ContentModelDocument;
}
export interface BindingProjectionVectorCandidateResult {
    cardinality: FieldDefinition['cardinality'];
    control?: string;
    fieldPath: string[];
    itemKind?: Exclude<ContentFieldKind, 'collection'>;
    kind: ContentFieldKind;
}
export interface BindingProjectionVectorPortResult {
    bindingSourceKind?: string;
    boundFieldPath?: string[];
    candidates: BindingProjectionVectorCandidateResult[];
    multiple?: boolean;
    port: string;
    required?: boolean;
    status: 'invalid' | 'non-field-source' | 'resolved' | 'unbound';
    valueType?: string;
}
export interface BindingProjectionVectorRunResult {
    diagnostics: {
        code: string;
        location?: DiagnosticLocation;
        severity: StudioDiagnostic['severity'];
    }[];
    model: BlueprintDocument['model'];
    nodes: {
        nodeId: string;
        ports: BindingProjectionVectorPortResult[];
    }[];
}
/**
 * Replays a binding vector without consulting its expected projection. The
 * normalized result intentionally excludes localized labels and prose so a
 * non-TypeScript host compares stable coordinates, controls, outcomes, and
 * diagnostic locations rather than an English rendering.
 */
export declare function runBindingProjectionVector(vector: BindingProjectionVectorInput): BindingProjectionVectorRunResult;
export interface SchemaProfileVectorInput {
    expect: {
        outcome: 'accepted' | 'rejected';
    };
    schema: unknown;
}
export interface SchemaProfileVectorInstanceResult {
    diagnostic?: {
        instancePath: string;
        keyword: string;
    };
    value: unknown;
    valid: boolean;
}
export type SchemaProfileVectorRunResult = {
    instances: SchemaProfileVectorInstanceResult[];
    outcome: 'accepted';
} | {
    code: StudioSchemaProfileErrorCode;
    outcome: 'rejected';
    schemaPath: string;
};
/**
 * Replay a portable `studio.profile/schema-property` vector against the
 * reference implementation. Only each case's input `value` is read from the
 * accepted expectation; expected verdicts and diagnostics are not consulted.
 * Callers compare this independently produced outcome with the published
 * `expect` member.
 */
export declare function runSchemaProfileVector(vector: SchemaProfileVectorInput & {
    expect: {
        instances?: SchemaProfileVectorInstance[];
    };
}): SchemaProfileVectorRunResult;
export declare function createBlueprintFixture(options?: BlueprintFixtureOptions): BlueprintDocument;
export declare function defineTestBlock(options: TestBlockOptions): BlockDefinition;
export declare function createStudioConfigurationFixture(options?: StudioConfigurationFixtureOptions): StudioConfiguration;
export declare function assertBlueprintConforms(blueprint: BlueprintDocument, definitions: readonly BlockDefinition[]): void;
export declare class StudioConformanceError extends Error {
    readonly diagnostics: StudioDiagnostic[];
    constructor(diagnostics: StudioDiagnostic[]);
}
//# sourceMappingURL=index.d.ts.map