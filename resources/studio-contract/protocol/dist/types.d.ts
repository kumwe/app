export declare const STUDIO_CONTRACT_VERSION: "0.1-draft";
export type StudioContractVersion = typeof STUDIO_CONTRACT_VERSION;
export declare const STUDIO_WIRE_PROTOCOL_VERSION: "0.1.0-draft.2";
export type StudioWireProtocolVersion = typeof STUDIO_WIRE_PROTOCOL_VERSION;
/**
 * Stable diagnostic carried with the canonical `invalid-request` category
 * when a host refuses an operation from an obsolete session generation.
 */
export declare const STUDIO_STALE_SESSION_GENERATION_DIAGNOSTIC_CODE: "studio.host/stale-session-generation";
export type JsonPrimitive = boolean | null | number | string;
export type JsonValue = JsonPrimitive | JsonObject | JsonValue[];
export interface JsonObject {
    [key: string]: JsonValue;
}
export interface JsonSchema extends JsonObject {
    $id?: string;
    $schema?: string;
}
export type StableId = string;
export type Revision = string;
export type SemanticVersion = string;
export type QualifiedName = `${string}/${string}`;
export type LocalName = string;
export type PackageRelativePath = string;
export type NodeId = StableId;
export type BlockType = QualifiedName;
export interface MessageReference {
    defaultMessage?: string;
    key: QualifiedName;
}
export interface OwnerReference {
    id: QualifiedName;
    version: SemanticVersion;
}
export interface ArtifactReference {
    id: StableId;
    integrity?: string;
    revision?: Revision;
    version: SemanticVersion;
}
export interface LockedArtifactReference extends ArtifactReference {
    revision: Revision;
}
export interface ResolvedEntryReference {
    id: StableId;
    integrity?: string;
    revision: Revision;
}
export interface DiagnosticLocation {
    artifactId?: StableId;
    fieldPath?: LocalName[];
    jsonPointer?: string;
    nodeId?: NodeId;
}
export interface StudioDiagnostic {
    code: QualifiedName;
    location?: DiagnosticLocation;
    message: MessageReference;
    parameters?: Record<string, JsonPrimitive>;
    remediations?: QualifiedName[];
    severity: 'blocking' | 'error' | 'information' | 'warning';
}
export interface EntryFieldBindingSource {
    fieldPath: LocalName[];
    kind: 'entry-field';
}
export interface ContextValueBindingSource {
    key: QualifiedName;
    kind: 'context-value';
}
export interface StaticValueBindingSource {
    kind: 'static-value';
    value: JsonValue;
}
export interface ResourceReferenceBindingSource {
    id: StableId;
    kind: 'resource-reference';
    resourceType: QualifiedName;
}
export interface QueryReferenceBindingSource {
    kind: 'query-reference';
    parameters: JsonObject;
    query: QualifiedName;
    version: SemanticVersion;
}
export type BindingSource = ContextValueBindingSource | EntryFieldBindingSource | QueryReferenceBindingSource | ResourceReferenceBindingSource | StaticValueBindingSource;
export interface BindingTransform {
    arguments: JsonObject;
    operator: QualifiedName;
    version: SemanticVersion;
}
export interface FieldBinding {
    fallback?: JsonValue;
    onError: 'error' | 'fallback' | 'hide';
    onNull: 'empty' | 'error' | 'fallback' | 'hide';
    source: BindingSource;
    transforms: BindingTransform[];
}
/**
 * The per-slot composition marker: declares one named slot of a node as a
 * hybrid-composable region on its own, without making the whole node
 * structural. The marker only ever grants composability — it never revokes
 * what the node-level policy already permits — and its `allowedBlocks`, when
 * declared, bounds that slot ahead of the node-level list.
 */
export interface SlotCompositionPolicy {
    allowedBlocks?: BlockType[];
    composable: true;
}
export interface NodeAuthoringPolicy {
    allowedBlocks?: BlockType[];
    mode: 'content' | 'designer' | 'locked' | 'structural' | 'variant';
    requiredPermission?: QualifiedName;
    slots?: Record<LocalName, SlotCompositionPolicy>;
}
/** Layout axes addressable by size-role commands; the schema admits no other member names. */
export type SizeRoleAxis = 'block' | 'inline';
export interface BlueprintNode {
    authoring: NodeAuthoringPolicy;
    bindings: Record<LocalName, FieldBinding>;
    extensions?: Record<QualifiedName, JsonValue>;
    id: NodeId;
    properties: JsonObject;
    responsive?: Record<LocalName, Record<LocalName, JsonValue>>;
    /** Responsive size-role overrides per axis and viewport role, mirroring `responsive`. */
    responsiveSizeRoles?: Record<LocalName, Record<LocalName, LocalName>>;
    /** Base named size role per layout axis (`inline` or `block`). */
    sizeRoles?: Record<LocalName, LocalName>;
    slots: Record<LocalName, BlueprintNode[]>;
    type: BlockType;
    version: SemanticVersion;
}
export interface BlueprintBlockLock {
    integrity?: string;
    revision: Revision;
    type: BlockType;
    version: SemanticVersion;
}
export interface BlueprintDependencyLock {
    blocks: BlueprintBlockLock[];
    plugins?: LockedArtifactReference[];
    theme: LockedArtifactReference;
}
export interface BlueprintDocument {
    contractVersion: StudioContractVersion;
    dependencyLock: BlueprintDependencyLock;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    id: StableId;
    kind: 'blueprint';
    label: MessageReference;
    model: LockedArtifactReference;
    owner: OwnerReference;
    revision: Revision;
    roots: BlueprintNode[];
    status: 'draft' | 'published' | 'retired';
    version: SemanticVersion;
}
export interface BlockSlotAcceptance {
    types: BlockType[];
}
export interface BlockSlotDefinition {
    accepts: BlockSlotAcceptance;
    id: LocalName;
    label: MessageReference;
    maximum: number;
    minimum: number;
    ordered: boolean;
}
export interface BlockPortDefinition {
    /**
     * Portable authoring hints. They select a Studio control/profile without
     * exposing an editor implementation. A read-only port may still be bound by
     * the host, but Studio never offers a value mutation for it.
     */
    authoring?: BlockPortAuthoringMetadata;
    id: LocalName;
    label: MessageReference;
    multiple: boolean;
    required: boolean;
    valueType: string;
}
export interface BlockPortAuthoringMetadata {
    control?: QualifiedName;
    profile?: QualifiedName;
    readOnly?: boolean;
}
export interface RendererRequirement {
    capability: QualifiedName;
    surface: 'document' | 'email' | 'native' | 'preview' | 'web';
    versions: string;
}
export interface BlockAccessibilityContract {
    accessibleName: 'derived' | 'not-applicable' | 'required-binding' | 'required-property';
    category: 'composite' | 'data-display' | 'decorative' | 'interactive' | 'landmark' | 'media' | 'structural' | 'text';
    keyboard: MessageReference;
    outputChecks: QualifiedName[];
    reducedMotion: 'disable-motion' | 'not-applicable' | 'required';
}
export type BlockIcon = {
    kind: 'asset';
    integrity: string;
    path: PackageRelativePath;
} | {
    kind: 'symbol';
    value: LocalName | QualifiedName;
};
export interface BlockPropertyControl {
    control: QualifiedName;
    help?: MessageReference;
    label?: MessageReference;
    property: LocalName;
}
export interface BlockFallback {
    lossless: true;
    type: BlockType;
    versions: string;
}
export interface BlockDefinition {
    accessibility: BlockAccessibilityContract;
    category: QualifiedName;
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    fallback?: BlockFallback;
    icon?: BlockIcon;
    kind: 'block-definition';
    label: MessageReference;
    editingModes: ('blueprint' | 'content')[];
    owner: OwnerReference;
    ports: BlockPortDefinition[];
    propertyControls?: BlockPropertyControl[];
    propertySchema: JsonSchema;
    rendererRequirements: RendererRequirement[];
    revision: Revision;
    slots: BlockSlotDefinition[];
    themeControls: LocalName[];
    type: BlockType;
    version: SemanticVersion;
}
/** Canonical, renderer-neutral chart data. It carries no Chart.js configuration. */
export interface StudioChartDataset {
    label: string;
    values: number[];
}
export interface StudioChartSpec {
    datasets: StudioChartDataset[];
    labels: string[];
    title?: string;
    type: 'bar' | 'doughnut' | 'line' | 'pie';
}
export interface StudioDrawingPoint {
    x: number;
    y: number;
}
export interface StudioDrawingStroke {
    color: string;
    points: StudioDrawingPoint[];
    width: number;
}
/** Bounded vector drawing data; never an SVG or canvas command stream. */
export interface StudioDrawingDocument {
    alt: string;
    height: number;
    strokes: StudioDrawingStroke[];
    width: number;
}
/** Decimal money uses a string so persistence never rounds through a binary float. */
export interface StudioMoneyValue {
    amount: string;
    currency: string;
}
/** Portable visual intent resolved by Studio renderers; it contains no CSS values or selectors. */
export interface StudioPresentationIntent {
    align?: 'center' | 'end' | 'start' | 'stretch';
    animation?: 'fade' | 'none' | 'parallax' | 'scale' | 'slide';
    height?: 'auto' | 'content' | 'full' | 'viewport';
    inverse?: boolean;
    margin?: 'comfortable' | 'compact' | 'none' | 'spacious';
    marker?: 'check' | 'decimal' | 'disc' | 'none';
    padding?: 'comfortable' | 'compact' | 'none' | 'spacious';
    position?: 'flow' | 'relative' | 'sticky';
    print?: 'hide' | 'only' | 'show';
    scrolling?: 'auto' | 'clip' | 'snap' | 'visible';
    visibility?: {
        compact?: 'hidden' | 'visible';
        expanded?: 'hidden' | 'visible';
        medium?: 'hidden' | 'visible';
    };
    width?: 'auto' | 'content' | 'full';
}
/** Canonical bounded table data used by static authoring and host query projections. */
export interface StudioTableDocument {
    caption?: string;
    columns: string[];
    rows: string[][];
}
export interface HostPortCapability {
    id: QualifiedName;
    operations: QualifiedName[];
    version: SemanticVersion;
}
export interface HostFeatureCapability {
    configuration?: JsonValue;
    id: QualifiedName;
    version: SemanticVersion;
}
export interface HostCapabilities {
    capabilities: HostFeatureCapability[];
    contractVersion: StudioContractVersion;
    extensions?: Record<QualifiedName, JsonValue>;
    host: {
        generation: Revision;
        id: QualifiedName;
        version: SemanticVersion;
    };
    kind: 'host-capabilities';
    ports: HostPortCapability[];
    protocolVersions: SemanticVersion[];
}
export type ContentFieldKind = 'boolean' | 'collection' | 'date' | 'date-time' | 'decimal' | 'enum' | 'integer' | 'media' | 'money' | 'object' | 'resource' | 'rich-text' | 'string' | QualifiedName;
export interface FieldAuthoringMetadata {
    control?: QualifiedName;
    group?: LocalName;
    hidden?: boolean;
    order?: number;
    placeholder?: MessageReference;
    readOnly?: boolean;
    width?: 'full' | 'half' | 'third' | 'two-thirds';
}
export interface FieldConstraints {
    allowedMediaKinds?: MediaAsset['mediaKind'][];
    maxItems?: number;
    maxLength?: number;
    maximum?: string;
    minItems?: number;
    minLength?: number;
    minimum?: string;
    scale?: number;
    validator?: QualifiedName;
    validatorArguments?: Record<string, JsonValue>;
}
export interface ContentModelEnumValue {
    label: MessageReference;
    value: LocalName;
}
export interface FieldDefinition {
    authoring?: FieldAuthoringMetadata;
    cardinality: 'many' | 'one';
    constraints?: FieldConstraints;
    defaultValue?: JsonValue;
    description?: MessageReference;
    enumValues?: ContentModelEnumValue[];
    extensions?: Record<QualifiedName, JsonValue>;
    fields?: FieldDefinition[];
    id: LocalName;
    itemKind?: Exclude<ContentFieldKind, 'collection'>;
    kind: ContentFieldKind;
    label: MessageReference;
    localized: boolean;
    required: boolean;
    semanticRole?: QualifiedName;
}
export interface RelationshipAuthoringMetadata {
    allowCreate: boolean;
    control: QualifiedName;
    displayField?: LocalName;
    searchProvider?: QualifiedName;
}
export interface RelationshipDefinition {
    authoring?: RelationshipAuthoringMetadata;
    extensions?: Record<QualifiedName, JsonValue>;
    id: LocalName;
    kind: 'many-to-many' | 'many-to-one' | 'one-to-many' | 'one-to-one';
    label: MessageReference;
    onDelete: 'detach' | 'nullify' | 'restrict';
    required: boolean;
    sourceField: LocalName;
    targetField?: LocalName;
    targetModel: ArtifactReference;
}
export interface ContentModelDocument {
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    fields: FieldDefinition[];
    id: StableId;
    kind: 'content-model';
    label: MessageReference;
    owner: OwnerReference;
    relationships: RelationshipDefinition[];
    revision: Revision;
    status: 'draft' | 'published' | 'retired';
    version: SemanticVersion;
}
export type PluginActivation = 'declarative' | 'executable';
export type PluginEntryRealm = 'application' | 'sandboxed-frame' | 'worker';
export interface PluginEntryModule {
    integrity: string;
    path: PackageRelativePath;
    realm: PluginEntryRealm;
}
export type PluginContributionKind = 'block' | 'command' | 'design-vocabulary' | 'field-adapter' | 'inspector' | 'locale' | 'migration' | 'panel' | 'pattern' | 'renderer-capability' | 'test-fixture' | 'transform';
export interface PluginContributionDeclaration {
    executable?: boolean;
    id: QualifiedName;
    integrity: string;
    kind: PluginContributionKind;
    resource: PackageRelativePath;
    version: SemanticVersion;
}
export interface PluginCapabilityRequirement {
    id: QualifiedName;
    versions: string;
}
export interface PluginDependency {
    id: QualifiedName;
    optional: boolean;
    versions: string;
}
export interface PluginManifest {
    activation: PluginActivation;
    contractVersion: StudioContractVersion;
    contributions: PluginContributionDeclaration[];
    dependencies: PluginDependency[];
    description?: MessageReference;
    entryModules: PluginEntryModule[];
    extensions?: Record<QualifiedName, JsonValue>;
    id: QualifiedName;
    kind: 'plugin-manifest';
    label: MessageReference;
    locales?: string[];
    optionalCapabilities: PluginCapabilityRequirement[];
    owner: OwnerReference;
    permissions: QualifiedName[];
    requiredCapabilities: PluginCapabilityRequirement[];
    version: SemanticVersion;
}
export interface ThemeViewport {
    base: boolean;
    id: LocalName;
    label: MessageReference;
    order: number;
    previewWidth: number;
}
export type ThemeDesignControlKind = 'boolean' | 'color-role' | 'enum' | 'integer' | 'layer-role' | 'motion-role' | 'radius-role' | 'shadow-role' | 'size-role' | 'spacing-role' | 'typography-role';
export interface ThemeDesignChoice {
    deprecated?: boolean;
    id: LocalName;
    label: MessageReference;
}
export interface ThemeDesignControl {
    choices: ThemeDesignChoice[];
    description?: MessageReference;
    id: LocalName;
    kind: ThemeDesignControlKind;
    label: MessageReference;
}
export interface ThemeRecipe {
    blockType: QualifiedName;
    designValues: Record<LocalName, JsonValue>;
    id: LocalName;
    label: MessageReference;
}
export interface ThemeRendererDeclaration {
    exactPreview: boolean;
    id: QualifiedName;
    surfaces: RendererRequirement['surface'][];
    version: SemanticVersion;
}
export interface ThemeBlockSupport {
    renderer: QualifiedName;
    type: QualifiedName;
    versions: string;
}
export interface ThemeAlias {
    equivalentMeaning: true;
    from: LocalName;
    kind: 'choice' | 'design-control' | 'recipe' | 'viewport';
    to: LocalName;
}
export interface ThemeDocument {
    aliases?: ThemeAlias[];
    blockSupport: ThemeBlockSupport[];
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    designControls: ThemeDesignControl[];
    extensions?: Record<QualifiedName, JsonValue>;
    id: StableId;
    kind: 'theme';
    label: MessageReference;
    owner: OwnerReference;
    recipes: ThemeRecipe[];
    renderers: ThemeRendererDeclaration[];
    revision: Revision;
    version: SemanticVersion;
    viewports: ThemeViewport[];
}
/**
 * The declarative payload behind a `design-vocabulary` plugin contribution:
 * design controls and recipes an extension offers, which a theme may adopt or
 * remap through its own declared controls and aliases. The vocabulary never
 * carries CSS or executable values; a theme that ignores it loses nothing.
 */
export interface DesignVocabulary {
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    designControls: ThemeDesignControl[];
    extensions?: Record<QualifiedName, JsonValue>;
    id: QualifiedName;
    kind: 'design-vocabulary';
    label: MessageReference;
    owner: OwnerReference;
    recipes: ThemeRecipe[];
    version: SemanticVersion;
}
/**
 * The declarative payload behind a `field-adapter` contribution. Executable
 * control code remains host-bound; this document is safe to validate and
 * activate into an immutable contribution generation.
 */
export interface FieldAdapterContribution {
    contractVersion: StudioContractVersion;
    control: QualifiedName;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    fieldKinds: QualifiedName[];
    id: QualifiedName;
    kind: 'field-adapter';
    label: MessageReference;
    optionSchema?: JsonSchema;
    owner: OwnerReference;
    requiredCapability?: QualifiedName;
    version: SemanticVersion;
}
/**
 * The declarative payload behind an `inspector` contribution. Its executable
 * surface is resolved separately through host capability policy.
 */
export interface InspectorContribution {
    blockTypes: BlockType[];
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    id: QualifiedName;
    kind: 'inspector';
    label: MessageReference;
    owner: OwnerReference;
    placement: 'augment' | 'replace';
    requiredCapability?: QualifiedName;
    version: SemanticVersion;
}
export type MigrationArtifactKind = 'block-definition' | 'blueprint' | 'content-model' | 'entry' | 'theme';
/**
 * The declarative payload behind a `migration` plugin contribution: the
 * portable descriptor of a document migration, safe to validate at admission
 * and install without executing plugin code. The transformation itself is
 * trusted package code the host binds separately; artifacts never carry
 * executable migration source.
 */
export interface MigrationDeclaration {
    artifactKinds: MigrationArtifactKind[];
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    id: QualifiedName;
    kind: 'migration';
    label: MessageReference;
    lossClassification: 'lossless' | 'lossy';
    owner: OwnerReference;
    sourceVersions: string;
    targetVersion: SemanticVersion;
    version: SemanticVersion;
}
export type HostErrorCategory = 'cancelled' | 'conflict' | 'forbidden' | 'incompatible' | 'internal' | 'invalid-request' | 'limit-exceeded' | 'not-found' | 'rate-limited' | 'unauthenticated' | 'unavailable' | 'validation-failed';
export interface HostPortError {
    category: HostErrorCategory;
    contractVersion: StudioContractVersion;
    correlationId?: StableId;
    diagnostics?: StudioDiagnostic[];
    kind: 'host-error';
    message: MessageReference;
    retryAfterMilliseconds?: number;
    retryable: boolean;
    revision?: Revision;
}
export interface HostRequestContext {
    expectedRevision?: Revision;
    idempotencyKey?: StableId;
    locale?: string;
    operationId: QualifiedName;
    protocolVersion: StudioWireProtocolVersion;
    requestId: StableId;
    resourceContextKey: StableId;
    sessionGeneration: Revision;
    traceContext?: Record<LocalName, string>;
}
export interface HostPortResult<TValue> {
    revision?: Revision;
    value: TValue;
}
export type StudioArtifact = BlueprintDocument | ContentModelDocument | EntryDocument;
export interface ArtifactPort {
    dependencies(reference: ArtifactReference, context: HostRequestContext): Promise<HostPortResult<ArtifactReference[]>>;
    load(reference: ArtifactReference, context: HostRequestContext): Promise<HostPortResult<StudioArtifact>>;
    publish(reference: ArtifactReference, context: HostRequestContext): Promise<HostPortResult<null>>;
    save(artifact: StudioArtifact, context: HostRequestContext): Promise<HostPortResult<null>>;
    unpublish(reference: ArtifactReference, context: HostRequestContext): Promise<HostPortResult<null>>;
}
export interface ModelPort {
    get(reference: ArtifactReference, context: HostRequestContext): Promise<HostPortResult<ContentModelDocument>>;
    list(context: HostRequestContext): Promise<HostPortResult<ContentModelDocument[]>>;
}
export interface ResourceSearchQuery {
    cursor?: string;
    limit: number;
    resourceType: QualifiedName;
    search?: string;
}
export interface ResourceSearchHit {
    id: StableId;
    label: MessageReference;
    resourceType: QualifiedName;
}
export interface ResourceSearchPage {
    items: ResourceSearchHit[];
    nextCursor?: string;
}
export interface ResourcePort {
    search(query: ResourceSearchQuery, context: HostRequestContext): Promise<HostPortResult<ResourceSearchPage>>;
}
export interface PreviewPort {
    cancel(draftDigest: string, context: HostRequestContext): Promise<HostPortResult<null>>;
    render(payload: PreviewRenderPayload, context: HostRequestContext): Promise<HostPortResult<PreviewRenderedPayload>>;
}
export interface MediaHostPort {
    /** Releases a grant the client will not use. Never deletes an accepted asset. */
    abortUpload(uploadId: StableId, context: HostRequestContext): Promise<HostPortResult<null>>;
    /**
     * Authorizes one declared upload against host policy and returns the grant
     * to transfer against. Policy rejection happens here, before any byte moves.
     */
    authorizeUpload(request: MediaUploadRequestDescriptor, context: HostRequestContext): Promise<HostPortResult<MediaUploadGrant>>;
    /**
     * Closes a transferred upload. The host verifies the bytes it received - it
     * never trusts the client's declared media type or checksum - and mints the
     * stable asset identity, which may still be processing or quarantined.
     */
    completeUpload(uploadId: StableId, context: HostRequestContext): Promise<HostPortResult<MediaUploadAcceptedAsset>>;
    get(assetId: StableId, context: HostRequestContext): Promise<HostPortResult<MediaAsset | null>>;
    /**
     * Fetches an external candidate under host runtime hardening. The lexical
     * URL policy is necessary but not sufficient: redirect re-validation, DNS
     * rebinding defence, response verification and size bounds are the host's.
     */
    importExternal(url: string, context: HostRequestContext): Promise<HostPortResult<MediaUploadAcceptedAsset>>;
    list(query: MediaQuery, context: HostRequestContext): Promise<HostPortResult<MediaPage>>;
    /** Polls an accepted asset whose processing has not settled. */
    uploadStatus(assetId: StableId, context: HostRequestContext): Promise<HostPortResult<MediaUploadAcceptedAsset>>;
}
export interface LocalizationPort {
    messages(locale: string, namespaces: QualifiedName[], context: HostRequestContext): Promise<HostPortResult<Record<QualifiedName, string>>>;
}
export interface PermissionExplanation {
    allowed: boolean;
    reason?: MessageReference;
}
export interface PermissionSnapshot {
    permissions: QualifiedName[];
    sessionGeneration: Revision;
}
export interface PermissionPort {
    explain(operation: QualifiedName, context: HostRequestContext): Promise<HostPortResult<PermissionExplanation>>;
    refresh(context: HostRequestContext): Promise<HostPortResult<PermissionSnapshot>>;
}
export interface RecoveryPort {
    discard(context: HostRequestContext): Promise<HostPortResult<null>>;
    load(context: HostRequestContext): Promise<HostPortResult<JsonObject | null>>;
    store(envelope: JsonObject, context: HostRequestContext): Promise<HostPortResult<null>>;
}
export interface TelemetryEvent {
    attributes?: Record<string, JsonPrimitive>;
    name: QualifiedName;
}
export interface TelemetryPort {
    emit(event: TelemetryEvent, context: HostRequestContext): Promise<HostPortResult<null>>;
}
export interface HostAdapter {
    artifact: ArtifactPort;
    localization?: LocalizationPort;
    media?: MediaHostPort;
    model?: ModelPort;
    permission?: PermissionPort;
    preview?: PreviewPort;
    recovery?: RecoveryPort;
    resource?: ResourcePort;
    telemetry?: TelemetryPort;
}
export type ExtensionLifecycleState = 'activating' | 'active' | 'disabled' | 'discovered' | 'installed-disabled' | 'purged' | 'rejected' | 'trust-revoked' | 'uninstalled-data-preserved' | 'verified';
export interface EntryDocument {
    compositionOverrides?: Record<StableId, JsonValue>;
    contractVersion: StudioContractVersion;
    extensions?: Record<QualifiedName, JsonValue>;
    id: StableId;
    kind: 'entry';
    locale?: string;
    model: LockedArtifactReference;
    revision: Revision;
    status: 'archived' | 'draft' | 'in-review' | 'published';
    translationOf?: StableId;
    values: Record<LocalName, JsonValue>;
    workflowState?: QualifiedName;
}
export type StudioAuthoringMode = 'blueprint' | 'content' | 'model';
/**
 * The single permission determinant of a headless editing session, fixed at
 * session creation. It flattens the configuration triple of editing `mode`,
 * `composite`, and `sessionState`: a read-only session state flattens to
 * `read-only`, the bounded hybrid composite flattens to `hybrid`, and every
 * other session flattens to its authoring mode. `hybrid` remains the bounded
 * Blueprint-plus-Content composite — not a fourth editing mode — and
 * `read-only` is the canonical session-mode spelling of the read-only
 * session state.
 */
export type StudioSessionMode = StudioAuthoringMode | 'hybrid' | 'read-only';
export interface StudioDisplayPreferences {
    calendar: LocalName;
    hourCycle: 'h11' | 'h12' | 'h23' | 'h24';
    measurementSystem?: 'metric' | 'uk' | 'us';
    numberingSystem: LocalName;
}
export interface StudioResourceScope {
    id: StableId;
    kind: QualifiedName;
}
export interface StudioResourceContext {
    key: StableId;
    resource?: {
        id: StableId;
        type: QualifiedName;
    };
    revision?: Revision;
    scopes: StudioResourceScope[];
    surface: QualifiedName;
}
export interface StudioLimits {
    maxChildrenPerSlot: number;
    maxCommandBatch: number;
    maxContributionsPerPlugin: number;
    maxDepth: number;
    maxExtensionBytes: number;
    maxHistoryEntries: number;
    maxLocaleBytes: number;
    maxMediaBatch: number;
    maxMediaUploadBytes: number;
    maxNodes: number;
    maxPluginCount: number;
    maxPreviewBytes: number;
    maxPreviewRequestsPerMinute: number;
    maxPropertyBytes: number;
    maxRichTextBytes: number;
    maxRichTextDepth: number;
    maxSlotsPerNode: number;
}
export interface StudioConfiguration {
    actor: {
        displayName: string;
        id: StableId;
    };
    artifacts: {
        blueprint?: LockedArtifactReference;
        entry?: ResolvedEntryReference;
        model?: LockedArtifactReference;
        theme?: LockedArtifactReference;
    };
    blocks: BlueprintBlockLock[];
    composite: 'hybrid' | 'single';
    contractVersion: StudioContractVersion;
    displayPreferences: StudioDisplayPreferences;
    extensions?: Record<QualifiedName, JsonValue>;
    features: {
        clipboardMediaUpload: boolean;
        collaboration: boolean;
        customInspectors: boolean;
        executablePlugins: boolean;
        externalMediaImport: boolean;
        offlineRecovery: boolean;
    };
    hostCapabilities: HostCapabilities;
    limits: StudioLimits;
    locale: {
        direction: 'ltr' | 'rtl';
        fallbacks: string[];
        requested: string;
        resolved: string;
        timeZone: string;
    };
    mode: StudioAuthoringMode;
    permissions: QualifiedName[];
    plugins: LockedArtifactReference[];
    protocolVersion: StudioWireProtocolVersion;
    preview: {
        allowApproximateRenderer: boolean;
        enabled: boolean;
        initialViewport?: LocalName;
        sameOriginRequired: boolean;
    };
    sessionGeneration: Revision;
    sessionId: StableId;
    sessionState: 'editable' | 'read-only';
    resourceContext: StudioResourceContext;
}
export interface ExperimentalShellConfiguration {
    /** Omit to use Studio's complete first-party catalog; an explicit array overrides it. */
    blockDefinitions?: BlockDefinition[];
    session: StudioConfiguration;
}
export interface CommandDestination {
    parentNodeId?: NodeId;
    position: number;
    slot?: LocalName;
}
export interface CommandBase<TType extends QualifiedName, TPayload extends object> {
    artifactId: StableId;
    baseStateVersion: number;
    contractVersion: StudioContractVersion;
    expectedRevision?: Revision;
    groupId?: StableId;
    id: StableId;
    kind: 'command';
    payload: TPayload;
    sessionGeneration: Revision;
    type: TType;
}
export interface InsertNodePayload {
    destination: CommandDestination;
    node: BlueprintNode;
}
export type InsertNodeCommand = CommandBase<'studio.command/insert-node', InsertNodePayload>;
export interface RemoveNodePayload {
    nodeId: NodeId;
}
export type RemoveNodeCommand = CommandBase<'studio.command/remove-node', RemoveNodePayload>;
export interface RestoreNodePayload {
    destination: CommandDestination;
    node: BlueprintNode;
}
export type RestoreNodeCommand = CommandBase<'studio.command/restore-node', RestoreNodePayload>;
export interface MoveNodePayload {
    destination: CommandDestination;
    nodeId: NodeId;
}
export type MoveNodeCommand = CommandBase<'studio.command/move-node', MoveNodePayload>;
export interface SetPropertyPayload {
    nodeId: NodeId;
    property: LocalName;
    value: JsonValue;
    viewport?: LocalName;
}
export type SetPropertyCommand = CommandBase<'studio.command/set-property', SetPropertyPayload>;
export interface DuplicateNodePayload {
    destination?: CommandDestination;
    idMap: Record<NodeId, NodeId>;
    nodeId: NodeId;
}
export type DuplicateNodeCommand = CommandBase<'studio.command/duplicate-node', DuplicateNodePayload>;
export interface ReorderChildrenPayload {
    order: NodeId[];
    parentNodeId?: NodeId;
    slot?: LocalName;
}
export type ReorderChildrenCommand = CommandBase<'studio.command/reorder-children', ReorderChildrenPayload>;
export interface UnsetPropertyPayload {
    nodeId: NodeId;
    property: LocalName;
    viewport?: LocalName;
}
export type UnsetPropertyCommand = CommandBase<'studio.command/unset-property', UnsetPropertyPayload>;
export interface SetSizeRolePayload {
    axis: SizeRoleAxis;
    nodeId: NodeId;
    role: LocalName;
    viewport?: LocalName;
}
export type SetSizeRoleCommand = CommandBase<'studio.command/set-size-role', SetSizeRolePayload>;
export interface UnsetSizeRolePayload {
    axis: SizeRoleAxis;
    nodeId: NodeId;
    viewport?: LocalName;
}
export type UnsetSizeRoleCommand = CommandBase<'studio.command/unset-size-role', UnsetSizeRolePayload>;
export interface ResetInheritedPropertyPayload {
    nodeId: NodeId;
    property: LocalName;
}
export type ResetInheritedPropertyCommand = CommandBase<'studio.command/reset-inherited-property', ResetInheritedPropertyPayload>;
export interface SetBindingPayload {
    binding: FieldBinding;
    nodeId: NodeId;
    port: LocalName;
}
export type SetBindingCommand = CommandBase<'studio.command/set-binding', SetBindingPayload>;
export interface RemoveBindingPayload {
    nodeId: NodeId;
    port: LocalName;
}
export type RemoveBindingCommand = CommandBase<'studio.command/remove-binding', RemoveBindingPayload>;
export interface SetFieldValuePayload {
    fieldPath: LocalName[];
    locale?: string;
    value: JsonValue;
}
export type SetFieldValueCommand = CommandBase<'studio.command/set-field-value', SetFieldValuePayload>;
export type ProvenanceOrigin = 'authoring' | 'import' | 'migration' | 'pattern' | 'plugin' | 'system';
export interface ProvenanceActor {
    displayName?: string;
    id: StableId;
}
export interface ProvenanceEntry {
    actor: ProvenanceActor;
    commandCount?: number;
    fromRevision?: Revision;
    migrationId?: QualifiedName;
    origin: ProvenanceOrigin;
    recordedAt: string;
    sessionId?: StableId;
    source?: ArtifactReference;
    toRevision: Revision;
}
export interface ProvenanceRecord {
    artifact: {
        id: StableId;
        revision: Revision;
    };
    chain: ProvenanceEntry[];
    contractVersion: StudioContractVersion;
    extensions?: Record<QualifiedName, JsonValue>;
    kind: 'provenance';
}
export type UnresolvedContributionReason = 'incompatible' | 'not-installed' | 'owner-disabled' | 'owner-revoked';
export interface UnresolvedContributionReference {
    contribution: Exclude<PluginContributionKind, 'test-fixture'>;
    id: QualifiedName;
    version: SemanticVersion;
}
export interface UnresolvedContribution {
    affectedNodes?: StableId[];
    contractVersion: StudioContractVersion;
    diagnostics?: StudioDiagnostic[];
    kind: 'unresolved-contribution';
    owner?: OwnerReference;
    reason: UnresolvedContributionReason;
    reference: UnresolvedContributionReference;
}
export interface PatternDocument {
    blockDependencies: BlueprintBlockLock[];
    contractVersion: StudioContractVersion;
    description?: MessageReference;
    extensions?: Record<QualifiedName, JsonValue>;
    id: StableId;
    kind: 'pattern';
    label: MessageReference;
    owner: OwnerReference;
    revision: Revision;
    roots: BlueprintNode[];
    version: SemanticVersion;
}
export interface PatternReference {
    id: StableId;
    revision: Revision;
    version: SemanticVersion;
}
export interface ApplyPatternPayload {
    destination: CommandDestination;
    idMap: Record<NodeId, NodeId>;
    nodes: BlueprintNode[];
    pattern: PatternReference;
}
export type ApplyPatternCommand = CommandBase<'studio.command/apply-pattern', ApplyPatternPayload>;
export interface BatchOperation<TType extends QualifiedName, TPayload extends object> {
    payload: TPayload;
    type: TType;
}
export type BlueprintBatchOperation = BatchOperation<'studio.command/duplicate-node', DuplicateNodePayload> | BatchOperation<'studio.command/insert-node', InsertNodePayload> | BatchOperation<'studio.command/move-node', MoveNodePayload> | BatchOperation<'studio.command/remove-binding', RemoveBindingPayload> | BatchOperation<'studio.command/remove-node', RemoveNodePayload> | BatchOperation<'studio.command/reorder-children', ReorderChildrenPayload> | BatchOperation<'studio.command/restore-node', RestoreNodePayload> | BatchOperation<'studio.command/set-binding', SetBindingPayload> | BatchOperation<'studio.command/set-property', SetPropertyPayload> | BatchOperation<'studio.command/set-size-role', SetSizeRolePayload> | BatchOperation<'studio.command/unset-property', UnsetPropertyPayload> | BatchOperation<'studio.command/unset-size-role', UnsetSizeRolePayload>;
export interface BatchPayload {
    operations: BlueprintBatchOperation[];
}
export type BatchCommand = CommandBase<'studio.command/batch', BatchPayload>;
export interface AddModelFieldPayload {
    field: FieldDefinition;
    position?: number;
}
export type AddModelFieldCommand = CommandBase<'studio.command/add-model-field', AddModelFieldPayload>;
export type BlueprintCommand = ApplyPatternCommand | BatchCommand | DuplicateNodeCommand | InsertNodeCommand | MoveNodeCommand | RemoveBindingCommand | RemoveNodeCommand | ReorderChildrenCommand | ResetInheritedPropertyCommand | RestoreNodeCommand | SetBindingCommand | SetPropertyCommand | SetSizeRoleCommand | UnsetPropertyCommand | UnsetSizeRoleCommand;
export type StudioCommand = AddModelFieldCommand | BlueprintCommand | SetFieldValueCommand;
export interface PreviewMessageBase<TType extends QualifiedName, TPayload extends object> {
    channelId: StableId;
    contractVersion: StudioContractVersion;
    kind: 'preview-message';
    payload: TPayload;
    sequence: number;
    sessionGeneration: Revision;
    type: TType;
}
export interface PreviewReadyPayload {
    protocolVersion: StudioWireProtocolVersion;
    renderer: QualifiedName;
    viewports: LocalName[];
}
export interface PreviewRenderPayload {
    artifactId: StableId;
    draftDigest: string;
    draftRevision: Revision;
    /** Session-unique identity for this render attempt, including retries. */
    requestId: StableId;
    viewport: LocalName;
}
export interface PreviewRenderedPayload {
    diagnostics: StudioDiagnostic[];
    draftDigest: string;
    /** Exact render request this response settles. */
    requestId: StableId;
    /** Canonical render markers in deterministic Blueprint preorder. */
    markers: StableId[];
    /** Exact one-to-one mapping from every marker to the node it renders. */
    markerMap: Record<StableId, NodeId>;
}
export interface PreviewSelectPayload {
    nodeId: NodeId;
    reveal?: boolean;
}
/** Axis-aligned rectangle in CSS pixels, relative to the preview viewport origin. */
export interface PreviewMarkerRect {
    height: number;
    width: number;
    x: number;
    y: number;
}
/** Preview viewport metrics captured at measurement time. */
export interface PreviewViewportMetrics {
    devicePixelRatio: number;
    height: number;
    scrollX: number;
    scrollY: number;
    width: number;
}
export interface PreviewMeasurePayload {
    /** Opaque render markers to measure, as a bounded explicit list. */
    markers: StableId[];
    /** Session-unique request identity; never reused for a render or measurement. */
    requestId: StableId;
}
/**
 * Volatile on-screen geometry for render markers. Measurements are never document
 * state: they are bound to the render digest they were measured against and become
 * meaningless as soon as a newer render or reload supersedes that digest.
 */
export interface PreviewMeasurementsPayload {
    /** Digest of the render the geometry was measured against. */
    draftDigest: string;
    /** One or more rectangles per measured marker; inline content fragments across lines. */
    measurements: Record<StableId, PreviewMarkerRect[]>;
    requestId: StableId;
    /** Requested markers the renderer could not associate with any on-screen geometry. */
    unknown: StableId[];
    viewport: PreviewViewportMetrics;
}
export interface PreviewErrorPayload {
    code: QualifiedName;
    correlationId?: StableId;
    message: MessageReference;
    retryable: boolean;
}
export type PreviewReadyMessage = PreviewMessageBase<'studio.preview/ready', PreviewReadyPayload>;
export type PreviewRenderMessage = PreviewMessageBase<'studio.preview/render', PreviewRenderPayload>;
export type PreviewRenderedMessage = PreviewMessageBase<'studio.preview/rendered', PreviewRenderedPayload>;
export type PreviewSelectMessage = PreviewMessageBase<'studio.preview/select', PreviewSelectPayload>;
export type PreviewMeasureMessage = PreviewMessageBase<'studio.preview/measure', PreviewMeasurePayload>;
export type PreviewMeasurementsMessage = PreviewMessageBase<'studio.preview/measurements', PreviewMeasurementsPayload>;
export type PreviewErrorMessage = PreviewMessageBase<'studio.preview/error', PreviewErrorPayload>;
export interface PreviewReloadPayload {
    reason: QualifiedName;
}
export type PreviewReloadMessage = PreviewMessageBase<'studio.preview/reload', PreviewReloadPayload>;
/**
 * The renderer reports a trusted interaction with a marked region. It reports
 * intent, never raw input events, and the marker carries nothing beyond the
 * node identity the render already published.
 */
export interface PreviewActivatedPayload {
    interaction: 'activate' | 'context-menu' | 'focus';
    /** A marker from the currently accepted render inventory. */
    marker: StableId;
}
export type PreviewActivatedMessage = PreviewMessageBase<'studio.preview/activated', PreviewActivatedPayload>;
/**
 * The client drives the preview surface to a semantic viewport role or to
 * bounded explicit dimensions. A role and explicit dimensions are
 * alternatives, not a merge.
 */
export interface PreviewViewportPayload {
    height?: number;
    viewport?: LocalName;
    width?: number;
}
export type PreviewViewportMessage = PreviewMessageBase<'studio.preview/viewport', PreviewViewportPayload>;
/**
 * The client instructs the renderer to revoke the resources it holds for a
 * draft while the channel stays open. This is not teardown: teardown ends the
 * session, dispose frees a superseded render's resources within it.
 */
export interface PreviewDisposePayload {
    draftDigest?: string;
    reason: QualifiedName;
}
export type PreviewDisposeMessage = PreviewMessageBase<'studio.preview/dispose', PreviewDisposePayload>;
export interface PreviewTeardownPayload {
    reason: QualifiedName;
}
export type PreviewTeardownMessage = PreviewMessageBase<'studio.preview/teardown', PreviewTeardownPayload>;
export type PreviewMessage = PreviewActivatedMessage | PreviewDisposeMessage | PreviewErrorMessage | PreviewMeasureMessage | PreviewMeasurementsMessage | PreviewReadyMessage | PreviewReloadMessage | PreviewRenderMessage | PreviewRenderedMessage | PreviewSelectMessage | PreviewTeardownMessage | PreviewViewportMessage;
export interface MediaRendition {
    height: number;
    id: LocalName;
    mediaType: string;
    width: number;
}
export interface MediaMetadata {
    altText?: string;
    caption?: string;
    credit?: string;
    decorative?: boolean;
    durationMilliseconds?: number;
    focalPoint?: {
        x: number;
        y: number;
    };
    height?: number;
    license?: string;
    width?: number;
}
export interface MediaRenditionIntent {
    fit?: 'contain' | 'cover' | 'fill' | 'scale-down';
    preferredMediaTypes?: string[];
    role: LocalName;
}
export type MediaCropIntent = {
    height: number;
    mode: 'aspect-ratio';
    width: number;
} | {
    height: number;
    mode: 'rectangle';
    width: number;
    x: number;
    y: number;
};
export type MediaAccessibility = {
    altFieldPath: LocalName[];
    captionFieldPath?: LocalName[];
    mode: 'bound';
} | {
    mode: 'decorative';
} | {
    altText: string;
    caption?: string;
    mode: 'informative';
};
export interface MediaReference {
    accessibility: MediaAccessibility;
    assetId: StableId;
    assetRevision?: Revision;
    contractVersion: StudioContractVersion;
    cropIntent?: MediaCropIntent;
    focalPoint?: {
        x: number;
        y: number;
    };
    kind: 'media-reference';
    renditionIntent?: MediaRenditionIntent;
    usage: QualifiedName;
}
export interface MediaAsset {
    byteSize: number;
    contractVersion: StudioContractVersion;
    extensions?: Record<QualifiedName, JsonValue>;
    filename: string;
    id: StableId;
    kind: 'media-asset';
    mediaKind: 'archive' | 'audio' | 'document' | 'image' | 'other' | 'video';
    mediaType: string;
    metadata: MediaMetadata;
    renditions?: MediaRendition[];
    revision: Revision;
    state: 'archived' | 'processing' | 'quarantined' | 'ready' | 'rejected';
}
export type MediaUploadSessionState = 'authorized' | 'cancelled' | 'complete' | 'failed' | 'requested' | 'transferring' | 'verifying';
export interface MediaUploadRequestDescriptor {
    byteSize: number;
    checksum?: string;
    filename: string;
    mediaType: string;
    purpose: QualifiedName;
}
export interface MediaUploadPlan {
    chunkBytes?: number;
    maximumBytes: number;
    resumable: boolean;
}
export interface MediaUploadProgress {
    totalBytes: number;
    transferredBytes: number;
}
export interface MediaUploadAcceptedAsset {
    id: StableId;
    revision: Revision;
    state: 'processing' | 'quarantined' | 'ready' | 'rejected';
}
/**
 * The host's authorization to transfer one upload. Bytes never cross the JSON
 * port: the host issues a short-lived, single-purpose destination it controls
 * and the client transfers directly to it, so custody, quotas and storage
 * placement stay host-owned and a large body never traverses the port
 * transport. The grant is a capability, not a credential store - it is scoped
 * to one declared upload and expires.
 */
export interface MediaUploadGrant {
    expiresAt: string;
    headers?: Record<string, string>;
    method: 'POST' | 'PUT';
    plan: MediaUploadPlan;
    uploadId: StableId;
    url: string;
}
export interface MediaUploadSession {
    asset?: MediaUploadAcceptedAsset;
    contractVersion: StudioContractVersion;
    failure?: StudioDiagnostic;
    id: StableId;
    kind: 'media-upload-session';
    plan?: MediaUploadPlan;
    progress: MediaUploadProgress;
    request: MediaUploadRequestDescriptor;
    state: MediaUploadSessionState;
}
export interface MediaQuery {
    cursor?: string;
    limit: number;
    mediaTypes?: string[];
    search?: string;
}
export interface MediaPage {
    assets: MediaAsset[];
    nextCursor?: string;
}
//# sourceMappingURL=types.d.ts.map