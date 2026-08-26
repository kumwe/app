import type { BlueprintDocument, JsonObject, JsonValue, QualifiedName, StableId } from '@kumwe/studio-protocol';
export type AuthoringWebRegion = 'canvas' | 'command-palette' | 'inspector' | 'outline' | 'palette' | 'preview' | 'viewport';
export type AuthoringWebAction = {
    kind: 'focus-node';
    nodeId: StableId;
    region: AuthoringWebRegion;
} | {
    key: 'ArrowDown' | 'ArrowLeft' | 'ArrowRight' | 'ArrowUp' | 'Delete' | 'End' | 'Enter' | 'Escape' | 'Home' | 'Tab' | 'd' | 'y' | 'z';
    kind: 'key';
    modifiers: ('Alt' | 'Control' | 'Meta' | 'Shift')[];
    region: AuthoringWebRegion;
} | {
    destination: {
        parentNodeId?: StableId;
        position: number;
        slot?: string;
    };
    kind: 'drag-node';
    nodeId: StableId;
} | {
    kind: 'activate-command';
    payload: JsonObject;
    region: AuthoringWebRegion;
    type: QualifiedName;
} | {
    kind: 'edit-property';
    nodeId: StableId;
    property: string;
    value: JsonValue;
    viewport?: string;
};
export interface AuthoringWebGiven {
    direction: 'ltr' | 'rtl';
    document: BlueprintDocument;
    locale: string;
    readOnly: boolean;
    reducedMotion: boolean;
    selection: StableId | null;
    viewport: {
        height: number;
        width: number;
        zoomPercent: number;
    };
}
export interface AuthoringWebCommandObservation {
    payload: JsonObject;
    type: QualifiedName;
}
export interface AuthoringWebObservation {
    announcements: {
        key: QualifiedName;
        politeness: 'assertive' | 'polite';
    }[];
    commands: AuthoringWebCommandObservation[];
    dirty: boolean;
    document: BlueprintDocument;
    focus: {
        nodeId?: StableId;
        region: AuthoringWebRegion;
    };
    selection: StableId | null;
}
export interface AuthoringWebLane {
    name: string;
    steps: AuthoringWebAction[];
    surface: 'keyboard' | 'pointer' | 'structural-control';
}
export interface AuthoringWebVector {
    contractVersion: string;
    description: string;
    expect: AuthoringWebObservation;
    given: AuthoringWebGiven;
    id: QualifiedName;
    kind: 'authoring-web-vector';
    lanes: AuthoringWebLane[];
    requirements: string[];
}
export interface AuthoringWebConformanceSession {
    dispose?(): Promise<void> | void;
    observe(): Promise<AuthoringWebObservation> | AuthoringWebObservation;
    perform(action: Readonly<AuthoringWebAction>): Promise<void> | void;
}
export interface AuthoringWebConformanceAdapter {
    open(given: Readonly<AuthoringWebGiven>, lane: Readonly<AuthoringWebLane>): Promise<AuthoringWebConformanceSession> | AuthoringWebConformanceSession;
}
export interface AuthoringWebLaneResult {
    mismatches: string[];
    name: string;
    observation: AuthoringWebObservation;
    passed: boolean;
    surface: AuthoringWebLane['surface'];
}
export interface AuthoringWebVectorResult {
    lanes: AuthoringWebLaneResult[];
    passed: boolean;
    profile: 'studio.profile/authoring-web';
    vectorId: QualifiedName;
}
/**
 * Replay every interaction lane from an independent copy of the same initial
 * state. The adapter owns browser automation; this runner owns fixture
 * isolation and deterministic observation comparison.
 */
export declare function runAuthoringWebVector(vector: Readonly<AuthoringWebVector>, adapter: Readonly<AuthoringWebConformanceAdapter>): Promise<AuthoringWebVectorResult>;
//# sourceMappingURL=web-conformance.d.ts.map