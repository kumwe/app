export type GeneratedJsonPrimitive = boolean | null | number | string;
export type GeneratedJsonValue = GeneratedJsonPrimitive | GeneratedJsonValue[] | {
    [key: string]: GeneratedJsonValue;
};
export interface GeneratedTypeScriptModelMetadata {
    readonly documentContractRevision: string;
    readonly generator: {
        readonly name: string;
        readonly version: string;
    };
    readonly schemaCount: number;
    readonly schemaEpoch: string;
    readonly schemaManifestDigest: string;
    readonly supportedWireProtocolRange: string;
}
export declare const GENERATED_TYPESCRIPT_MODEL_METADATA: GeneratedTypeScriptModelMetadata;
/** Studio authoring message catalog */
export type GeneratedAuthoringMessageCatalog = {
    $schema: 'https://schemas.kumwe.org/studio/v1/authoring-message-catalog.schema.json';
    catalogVersion: GeneratedCommonSemanticVersion;
    contractVersion: GeneratedCommonContractVersion;
    kind: 'authoring-message-catalog';
    locale: GeneratedCommonLocale;
    messages: {
        [key: string]: GeneratedAuthoringMessageCatalogMessage;
    };
};
export type GeneratedAuthoringMessageCatalogMessage = {
    defaultMessage: string;
    parameters: GeneratedCommonLocalName[];
};
/** Studio portable web-authoring conformance vector */
export type GeneratedAuthoringWebVector = {
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: GeneratedAuthoringWebVectorObservation;
    given: GeneratedAuthoringWebVectorGiven;
    id: GeneratedCommonQualifiedName;
    kind: 'authoring-web-vector';
    lanes: [GeneratedAuthoringWebVectorLane, ...GeneratedAuthoringWebVectorLane[]];
    requirements: [string, ...string[]];
};
export type GeneratedAuthoringWebVectorAction = GeneratedAuthoringWebVectorFocusNode | GeneratedAuthoringWebVectorKeyAction | GeneratedAuthoringWebVectorDragNode | GeneratedAuthoringWebVectorActivateCommand | GeneratedAuthoringWebVectorEditProperty;
export type GeneratedAuthoringWebVectorActivateCommand = {
    kind: 'activate-command';
    payload: GeneratedAuthoringWebVectorJsonObject;
    region: GeneratedAuthoringWebVectorRegion;
    type: GeneratedCommonQualifiedName;
};
export type GeneratedAuthoringWebVectorDragNode = {
    destination: GeneratedCommandDestination;
    kind: 'drag-node';
    nodeId: GeneratedCommonStableId;
};
export type GeneratedAuthoringWebVectorEditProperty = {
    kind: 'edit-property';
    nodeId: GeneratedCommonStableId;
    property: GeneratedCommonLocalName;
    value: GeneratedCommonJsonValue;
    viewport?: GeneratedCommonLocalName;
};
export type GeneratedAuthoringWebVectorFocusNode = {
    kind: 'focus-node';
    nodeId: GeneratedCommonStableId;
    region: GeneratedAuthoringWebVectorRegion;
};
export type GeneratedAuthoringWebVectorGiven = {
    direction: 'ltr' | 'rtl';
    document: GeneratedBlueprint;
    locale: GeneratedCommonLocale;
    readOnly: boolean;
    reducedMotion: boolean;
    selection: GeneratedCommonStableId | null;
    viewport: {
        height: number;
        width: number;
        zoomPercent: number;
    };
};
export type GeneratedAuthoringWebVectorJsonObject = {
    [key: string]: GeneratedCommonJsonValue;
};
export type GeneratedAuthoringWebVectorKeyAction = {
    key: 'ArrowDown' | 'ArrowLeft' | 'ArrowRight' | 'ArrowUp' | 'Delete' | 'End' | 'Enter' | 'Escape' | 'Home' | 'Tab' | 'd' | 'y' | 'z';
    kind: 'key';
    modifiers: ('Alt' | 'Control' | 'Meta' | 'Shift')[];
    region: GeneratedAuthoringWebVectorRegion;
};
export type GeneratedAuthoringWebVectorLane = {
    name: GeneratedCommonLocalName;
    steps: [GeneratedAuthoringWebVectorAction, ...GeneratedAuthoringWebVectorAction[]];
    surface: 'keyboard' | 'pointer' | 'structural-control';
};
export type GeneratedAuthoringWebVectorObservation = {
    announcements: {
        key: GeneratedCommonQualifiedName;
        politeness: 'assertive' | 'polite';
    }[];
    commands: {
        payload: GeneratedAuthoringWebVectorJsonObject;
        type: GeneratedCommonQualifiedName;
    }[];
    dirty: boolean;
    document: GeneratedBlueprint;
    focus: {
        nodeId?: GeneratedCommonStableId;
        region: GeneratedAuthoringWebVectorRegion;
    };
    selection: GeneratedCommonStableId | null;
};
export type GeneratedAuthoringWebVectorRegion = 'canvas' | 'command-palette' | 'inspector' | 'outline' | 'palette' | 'preview' | 'viewport';
/** Studio field-binding projection conformance vector */
export type GeneratedBindingProjectionVector = {
    blockDefinitions: [GeneratedBlockDefinition, ...GeneratedBlockDefinition[]];
    blueprint: GeneratedBlueprint;
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: GeneratedBindingProjectionVectorProjection;
    id: GeneratedCommonStableId;
    kind: 'binding-projection-vector';
    model: GeneratedContentModel;
    profile: 'studio.profile/binding-projection-v1';
};
export type GeneratedBindingProjectionVectorCandidate = {
    cardinality: 'one' | 'many';
    control?: GeneratedCommonQualifiedName;
    fieldPath: GeneratedBindingProjectionVectorFieldPath;
    itemKind?: ('string' | 'rich-text' | 'boolean' | 'integer' | 'decimal' | 'money' | 'date' | 'date-time' | 'enum' | 'media' | 'resource' | 'object') | GeneratedCommonQualifiedName;
    kind: ('string' | 'rich-text' | 'boolean' | 'integer' | 'decimal' | 'money' | 'date' | 'date-time' | 'enum' | 'media' | 'resource' | 'object' | 'collection') | GeneratedCommonQualifiedName;
};
export type GeneratedBindingProjectionVectorDiagnostic = {
    code: GeneratedCommonQualifiedName;
    location?: GeneratedCommonDiagnosticLocation;
    severity: 'information' | 'warning' | 'error' | 'blocking';
};
export type GeneratedBindingProjectionVectorFieldPath = [
    GeneratedCommonLocalName,
    ...GeneratedCommonLocalName[]
];
export type GeneratedBindingProjectionVectorNode = {
    nodeId: GeneratedCommonStableId;
    ports: GeneratedBindingProjectionVectorPort[];
};
export type GeneratedBindingProjectionVectorPort = {
    bindingSourceKind?: 'context-value' | 'entry-field' | 'query-reference' | 'resource-reference' | 'static-value';
    boundFieldPath?: GeneratedBindingProjectionVectorFieldPath;
    candidates: GeneratedBindingProjectionVectorCandidate[];
    multiple?: boolean;
    port: GeneratedCommonLocalName;
    required?: boolean;
    status: 'invalid' | 'non-field-source' | 'resolved' | 'unbound';
    valueType?: string;
};
export type GeneratedBindingProjectionVectorProjection = {
    diagnostics: GeneratedBindingProjectionVectorDiagnostic[];
    model: GeneratedCommonLockedArtifactReference;
    nodes: GeneratedBindingProjectionVectorNode[];
};
/** Studio block definition */
export type GeneratedBlockDefinition = {
    accessibility: {
        accessibleName: 'not-applicable' | 'required-property' | 'required-binding' | 'derived';
        category: 'structural' | 'landmark' | 'interactive' | 'media' | 'text' | 'decorative' | 'data-display' | 'composite';
        keyboard: GeneratedCommonMessageReference;
        outputChecks: [GeneratedCommonQualifiedName, ...GeneratedCommonQualifiedName[]];
        reducedMotion: 'not-applicable' | 'required' | 'disable-motion';
    };
    category: GeneratedCommonQualifiedName;
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    editingModes: ['blueprint' | 'content', ...('blueprint' | 'content')[]];
    extensions?: GeneratedCommonExtensions;
    fallback?: {
        lossless: true & boolean;
        type: GeneratedCommonQualifiedName;
        versions: GeneratedCommonVersionRange;
    };
    icon?: {
        kind: 'symbol';
        value: GeneratedCommonLocalName | GeneratedCommonQualifiedName;
    } | {
        integrity: GeneratedCommonIntegrity;
        kind: 'asset';
        path: GeneratedCommonPackageRelativePath;
    };
    kind: 'block-definition';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    ports: {
        authoring?: {
            control?: GeneratedCommonQualifiedName;
            profile?: GeneratedCommonQualifiedName;
            readOnly?: boolean;
        };
        id: GeneratedCommonLocalName;
        label: GeneratedCommonMessageReference;
        multiple: boolean;
        required: boolean;
        valueType: ('text' | 'rich-text' | 'boolean' | 'integer' | 'decimal' | 'money' | 'date' | 'date-time' | 'media' | 'resource') | GeneratedCommonQualifiedName;
    }[];
    propertyControls?: {
        control: GeneratedCommonQualifiedName;
        help?: GeneratedCommonMessageReference;
        label?: GeneratedCommonMessageReference;
        property: GeneratedCommonLocalName;
    }[];
    propertySchema: {
        [key: string]: GeneratedJsonValue;
    };
    rendererRequirements: [
        {
            capability: GeneratedCommonQualifiedName;
            surface: 'web' | 'email' | 'document' | 'native' | 'preview';
            versions: GeneratedCommonVersionRange;
        },
        ...{
            capability: GeneratedCommonQualifiedName;
            surface: 'web' | 'email' | 'document' | 'native' | 'preview';
            versions: GeneratedCommonVersionRange;
        }[]
    ];
    revision: GeneratedCommonRevision;
    slots: {
        accepts: {
            types: [GeneratedCommonQualifiedName, ...GeneratedCommonQualifiedName[]];
        };
        id: GeneratedCommonLocalName;
        label: GeneratedCommonMessageReference;
        maximum: number;
        minimum: number;
        ordered: boolean;
    }[];
    themeControls: GeneratedCommonLocalName[];
    type: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
/** Studio Blueprint */
export type GeneratedBlueprint = {
    contractVersion: GeneratedCommonContractVersion;
    dependencyLock: {
        blocks: {
            integrity?: GeneratedCommonIntegrity;
            revision: GeneratedCommonRevision;
            type: GeneratedCommonQualifiedName;
            version: GeneratedCommonSemanticVersion;
        }[];
        plugins?: GeneratedCommonLockedArtifactReference[];
        theme: GeneratedCommonLockedArtifactReference;
    };
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonStableId;
    kind: 'blueprint';
    label: GeneratedCommonMessageReference;
    model: GeneratedCommonLockedArtifactReference;
    owner: GeneratedCommonOwnerReference;
    revision: GeneratedCommonRevision;
    roots: GeneratedBlueprintNode[];
    status: 'draft' | 'published' | 'retired';
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedBlueprintBinding = {
    fallback?: GeneratedCommonJsonValue;
    onError: 'hide' | 'fallback' | 'error';
    onNull: 'empty' | 'hide' | 'fallback' | 'error';
    source: GeneratedBlueprintBindingSource;
    transforms: GeneratedBlueprintTransform[];
};
export type GeneratedBlueprintBindingSource = {
    fieldPath: [GeneratedCommonLocalName, ...GeneratedCommonLocalName[]];
    kind: 'entry-field';
} | {
    key: GeneratedCommonQualifiedName;
    kind: 'context-value';
} | {
    kind: 'static-value';
    value: GeneratedCommonJsonValue;
} | {
    id: GeneratedCommonStableId;
    kind: 'resource-reference';
    resourceType: GeneratedCommonQualifiedName;
} | {
    kind: 'query-reference';
    parameters: {
        [key: string]: GeneratedCommonJsonValue;
    };
    query: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedBlueprintNode = {
    authoring: {
        allowedBlocks?: GeneratedCommonQualifiedName[];
        mode: 'locked' | 'content' | 'variant' | 'structural' | 'designer';
        requiredPermission?: GeneratedCommonQualifiedName;
        slots?: {
            [key: string]: {
                allowedBlocks?: GeneratedCommonQualifiedName[];
                composable: true;
            };
        };
    };
    bindings: {
        [key: string]: GeneratedBlueprintBinding;
    };
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonStableId;
    properties: {
        [key: string]: GeneratedCommonJsonValue;
    };
    responsive?: {
        [key: string]: {
            [key: string]: GeneratedCommonJsonValue;
        };
    };
    responsiveSizeRoles?: {
        [key: string]: {
            [key: string]: GeneratedCommonLocalName;
        };
    };
    sizeRoles?: {
        [key: string]: GeneratedCommonLocalName;
    };
    slots: {
        [key: string]: GeneratedBlueprintNode[];
    };
    type: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedBlueprintSizeRoleAxis = 'inline' | 'block';
export type GeneratedBlueprintTransform = {
    arguments: {
        [key: string]: GeneratedCommonJsonValue;
    };
    operator: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
/** Studio canonical serialization vector */
export type GeneratedCanonicalVector = {
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: {
        canonical: string;
        digest: GeneratedCommonIntegrity;
    } | {
        rejected: 'depth-exceeded' | 'forbidden-member';
    };
    id: GeneratedCommonStableId;
    kind: 'canonical-vector';
    maximumDepth?: number;
    value: GeneratedJsonValue;
};
/** Studio canonical command vector */
export type GeneratedCommandVector = {
    command: GeneratedCommand;
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: {
        document: GeneratedBlueprint;
    } | {
        errorCode: GeneratedCommandVectorCommandErrorCode;
    };
    id: GeneratedCommonStableId;
    initial: GeneratedBlueprint;
    inverse?: GeneratedCommand;
    kind: 'command-vector';
    mode?: 'blueprint' | 'content' | 'hybrid' | 'model' | 'read-only';
};
export type GeneratedCommandVectorCommandErrorCode = 'artifact-not-draft' | 'binding-not-found' | 'duplicate-field' | 'duplicate-node' | 'illegal-move' | 'invalid-batch' | 'invalid-id-map' | 'invalid-index' | 'invalid-order' | 'locale-mismatch' | 'mode-forbidden' | 'node-not-found' | 'parent-not-found' | 'property-not-found' | 'read-only-session' | 'stale-generation' | 'stale-state' | 'unsupported-command';
/** Studio command */
export type GeneratedCommand = {
    artifactId: GeneratedCommonStableId;
    baseStateVersion: number;
    contractVersion: GeneratedCommonContractVersion;
    expectedRevision?: GeneratedCommonRevision;
    groupId?: GeneratedCommonStableId;
    id: GeneratedCommonStableId;
    kind: 'command';
    payload: {
        [key: string]: GeneratedCommonJsonValue;
    };
    sessionGeneration: GeneratedCommonRevision;
    type: GeneratedCommonQualifiedName;
};
export type GeneratedCommandAddModelField = {
    field: GeneratedContentModelField;
    position?: number;
};
export type GeneratedCommandApplyPattern = {
    destination: GeneratedCommandDestination;
    idMap: {
        [key: string]: GeneratedCommonStableId;
    };
    nodes: [GeneratedBlueprintNode, ...GeneratedBlueprintNode[]];
    pattern: {
        id: GeneratedCommonStableId;
        revision: GeneratedCommonRevision;
        version: GeneratedCommonSemanticVersion;
    };
};
export type GeneratedCommandBatch = {
    operations: [GeneratedCommandBatchOperation, ...GeneratedCommandBatchOperation[]];
};
export type GeneratedCommandBatchOperation = {
    payload: {
        [key: string]: GeneratedCommonJsonValue;
    };
    type: GeneratedCommonQualifiedName;
};
export type GeneratedCommandDestination = {
    parentNodeId?: GeneratedCommonStableId;
    position: number;
    slot?: GeneratedCommonLocalName;
};
export type GeneratedCommandDuplicateNode = {
    destination?: GeneratedCommandDestination;
    idMap: {
        [key: string]: GeneratedCommonStableId;
    };
    nodeId: GeneratedCommonStableId;
};
export type GeneratedCommandInsertNode = {
    destination: GeneratedCommandDestination;
    node: GeneratedBlueprintNode;
};
export type GeneratedCommandMoveNode = {
    destination: GeneratedCommandDestination;
    nodeId: GeneratedCommonStableId;
};
export type GeneratedCommandRemoveBinding = {
    nodeId: GeneratedCommonStableId;
    port: GeneratedCommonLocalName;
};
export type GeneratedCommandRemoveNode = {
    nodeId: GeneratedCommonStableId;
};
export type GeneratedCommandReorderChildren = {
    order: [GeneratedCommonStableId, ...GeneratedCommonStableId[]];
    parentNodeId?: GeneratedCommonStableId;
    slot?: GeneratedCommonLocalName;
};
export type GeneratedCommandResetInheritedProperty = {
    nodeId: GeneratedCommonStableId;
    property: GeneratedCommonLocalName;
};
export type GeneratedCommandRestoreNode = {
    destination: GeneratedCommandDestination;
    node: GeneratedBlueprintNode;
};
export type GeneratedCommandSetBinding = {
    binding: GeneratedBlueprintBinding;
    nodeId: GeneratedCommonStableId;
    port: GeneratedCommonLocalName;
};
export type GeneratedCommandSetFieldValue = {
    fieldPath: [GeneratedCommonLocalName, ...GeneratedCommonLocalName[]];
    locale?: GeneratedCommonLocale;
    value: GeneratedCommonJsonValue;
};
export type GeneratedCommandSetProperty = {
    nodeId: GeneratedCommonStableId;
    property: GeneratedCommonLocalName;
    value: GeneratedCommonJsonValue;
    viewport?: GeneratedCommonLocalName;
};
export type GeneratedCommandSetSizeRole = {
    axis: GeneratedBlueprintSizeRoleAxis;
    nodeId: GeneratedCommonStableId;
    role: GeneratedCommonLocalName;
    viewport?: GeneratedCommonLocalName;
};
export type GeneratedCommandUnsetProperty = {
    nodeId: GeneratedCommonStableId;
    property: GeneratedCommonLocalName;
    viewport?: GeneratedCommonLocalName;
};
export type GeneratedCommandUnsetSizeRole = {
    axis: GeneratedBlueprintSizeRoleAxis;
    nodeId: GeneratedCommonStableId;
    viewport?: GeneratedCommonLocalName;
};
/** Studio common contract types */
export type GeneratedCommon = Record<string, never>;
export type GeneratedCommonArtifactReference = {
    id: GeneratedCommonStableId;
    integrity?: GeneratedCommonIntegrity;
    revision?: GeneratedCommonRevision;
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedCommonCanonicalDecimal = string;
export type GeneratedCommonContractVersion = '0.1-draft' & string;
export type GeneratedCommonCurrencyCode = string;
export type GeneratedCommonDiagnostic = {
    code: GeneratedCommonQualifiedName;
    location?: GeneratedCommonDiagnosticLocation;
    message: GeneratedCommonMessageReference;
    parameters?: {
        [key: string]: string | number | boolean | null;
    };
    remediations?: GeneratedCommonQualifiedName[];
    severity: 'information' | 'warning' | 'error' | 'blocking';
};
export type GeneratedCommonDiagnosticLocation = {
    artifactId?: GeneratedCommonStableId;
    fieldPath?: GeneratedCommonLocalName[];
    jsonPointer?: string;
    nodeId?: GeneratedCommonStableId;
};
export type GeneratedCommonExtensions = {
    [key: string]: GeneratedCommonJsonValue;
};
export type GeneratedCommonIntegrity = string;
export type GeneratedCommonJsonValue = null | boolean | number | string | GeneratedCommonJsonValue[] | {
    [key: string]: GeneratedCommonJsonValue;
};
export type GeneratedCommonLocalName = string;
export type GeneratedCommonLocale = string;
export type GeneratedCommonLockedArtifactReference = {
    id: GeneratedCommonStableId;
    integrity?: GeneratedCommonIntegrity;
    revision: GeneratedCommonRevision;
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedCommonMessageReference = {
    defaultMessage?: string;
    key: GeneratedCommonQualifiedName;
};
export type GeneratedCommonMoneyValue = {
    amount: GeneratedCommonCanonicalDecimal;
    currency: GeneratedCommonCurrencyCode;
};
export type GeneratedCommonOwnerReference = {
    id: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedCommonPackageRelativePath = string;
export type GeneratedCommonQualifiedName = string;
export type GeneratedCommonResolvedEntryReference = {
    id: GeneratedCommonStableId;
    integrity?: GeneratedCommonIntegrity;
    revision: GeneratedCommonRevision;
};
export type GeneratedCommonRevision = string;
export type GeneratedCommonRfc3339Date = string;
export type GeneratedCommonRfc3339Instant = string;
export type GeneratedCommonSafeJsonMemberName = string;
export type GeneratedCommonSemanticVersion = string;
export type GeneratedCommonStableId = string;
export type GeneratedCommonVersionRange = string;
/** Studio content model */
export type GeneratedContentModel = {
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    fields: GeneratedContentModelField[];
    id: GeneratedCommonStableId;
    kind: 'content-model';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    relationships: GeneratedContentModelRelationship[];
    revision: GeneratedCommonRevision;
    status: 'draft' | 'published' | 'retired';
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedContentModelAuthoringMetadata = {
    control?: GeneratedCommonQualifiedName;
    group?: GeneratedCommonLocalName;
    hidden?: boolean;
    order?: number;
    placeholder?: GeneratedCommonMessageReference;
    readOnly?: boolean;
    width?: 'full' | 'half' | 'third' | 'two-thirds';
};
export type GeneratedContentModelField = {
    authoring?: GeneratedContentModelAuthoringMetadata;
    cardinality: 'one' | 'many';
    constraints?: {
        allowedMediaKinds?: ('image' | 'video' | 'audio' | 'document' | 'archive' | 'other')[];
        maxItems?: number;
        maxLength?: number;
        maximum?: string;
        minItems?: number;
        minLength?: number;
        minimum?: string;
        scale?: number;
        validator?: GeneratedCommonQualifiedName;
        validatorArguments?: {
            [key: string]: GeneratedCommonJsonValue;
        };
    };
    defaultValue?: GeneratedCommonJsonValue;
    description?: GeneratedCommonMessageReference;
    enumValues?: [
        {
            label: GeneratedCommonMessageReference;
            value: GeneratedCommonLocalName;
        },
        ...{
            label: GeneratedCommonMessageReference;
            value: GeneratedCommonLocalName;
        }[]
    ];
    extensions?: GeneratedCommonExtensions;
    fields?: GeneratedContentModelField[];
    id: GeneratedCommonLocalName;
    itemKind?: ('string' | 'rich-text' | 'boolean' | 'integer' | 'decimal' | 'money' | 'date' | 'date-time' | 'enum' | 'media' | 'resource' | 'object') | GeneratedCommonQualifiedName;
    kind: ('string' | 'rich-text' | 'boolean' | 'integer' | 'decimal' | 'money' | 'date' | 'date-time' | 'enum' | 'media' | 'resource' | 'object' | 'collection') | GeneratedCommonQualifiedName;
    label: GeneratedCommonMessageReference;
    localized: boolean;
    required: boolean;
    semanticRole?: GeneratedCommonQualifiedName;
};
export type GeneratedContentModelRelationship = {
    authoring?: {
        allowCreate: boolean;
        control: GeneratedCommonQualifiedName;
        displayField?: GeneratedCommonLocalName;
        searchProvider?: GeneratedCommonQualifiedName;
    };
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonLocalName;
    kind: 'one-to-one' | 'many-to-one' | 'one-to-many' | 'many-to-many';
    label: GeneratedCommonMessageReference;
    onDelete: 'restrict' | 'nullify' | 'detach';
    required: boolean;
    sourceField: GeneratedCommonLocalName;
    targetField?: GeneratedCommonLocalName;
    targetModel: GeneratedCommonArtifactReference;
};
/** Studio corpus integrity manifest */
export type GeneratedCorpusManifest = {
    contractVersion: GeneratedCommonContractVersion;
    groups: [
        {
            files: {
                digest: GeneratedCommonIntegrity;
                file: string;
            }[];
            group: GeneratedCommonLocalName;
            path: string;
        },
        ...{
            files: {
                digest: GeneratedCommonIntegrity;
                file: string;
            }[];
            group: GeneratedCommonLocalName;
            path: string;
        }[]
    ];
    kind: 'corpus-manifest';
};
/** Studio design vocabulary contribution */
export type GeneratedDesignVocabulary = {
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    designControls: {
        choices: [
            {
                deprecated?: boolean;
                id: GeneratedCommonLocalName;
                label: GeneratedCommonMessageReference;
            },
            ...{
                deprecated?: boolean;
                id: GeneratedCommonLocalName;
                label: GeneratedCommonMessageReference;
            }[]
        ];
        description?: GeneratedCommonMessageReference;
        id: GeneratedCommonLocalName;
        kind: 'color-role' | 'typography-role' | 'spacing-role' | 'size-role' | 'radius-role' | 'shadow-role' | 'motion-role' | 'layer-role' | 'enum' | 'boolean' | 'integer';
        label: GeneratedCommonMessageReference;
    }[];
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonQualifiedName;
    kind: 'design-vocabulary';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    recipes: {
        blockType: GeneratedCommonQualifiedName;
        designValues: {
            [key: string]: GeneratedCommonJsonValue;
        };
        id: GeneratedCommonLocalName;
        label: GeneratedCommonMessageReference;
    }[];
    version: GeneratedCommonSemanticVersion;
};
/** Studio content entry */
export type GeneratedEntry = {
    compositionOverrides?: {
        [key: string]: GeneratedCommonJsonValue;
    };
    contractVersion: GeneratedCommonContractVersion;
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonStableId;
    kind: 'entry';
    locale?: GeneratedCommonLocale;
    model: GeneratedCommonLockedArtifactReference;
    revision: GeneratedCommonRevision;
    status: 'draft' | 'in-review' | 'published' | 'archived';
    translationOf?: GeneratedCommonStableId;
    values: {
        [key: string]: GeneratedCommonJsonValue;
    };
    workflowState?: GeneratedCommonQualifiedName;
};
/** Studio field adapter contribution */
export type GeneratedFieldAdapter = {
    contractVersion: GeneratedCommonContractVersion;
    control: GeneratedCommonQualifiedName;
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    fieldKinds: [GeneratedCommonQualifiedName, ...GeneratedCommonQualifiedName[]];
    id: GeneratedCommonQualifiedName;
    kind: 'field-adapter';
    label: GeneratedCommonMessageReference;
    optionSchema?: {
        [key: string]: GeneratedCommonJsonValue;
    };
    owner: GeneratedCommonOwnerReference;
    requiredCapability?: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
/** Studio host capabilities */
export type GeneratedHostCapabilities = {
    capabilities: {
        configuration?: GeneratedCommonJsonValue;
        id: GeneratedCommonQualifiedName;
        version: GeneratedCommonSemanticVersion;
    }[];
    contractVersion: GeneratedCommonContractVersion;
    extensions?: GeneratedCommonExtensions;
    host: {
        generation: GeneratedCommonRevision;
        id: GeneratedCommonQualifiedName;
        version: GeneratedCommonSemanticVersion;
    };
    kind: 'host-capabilities';
    ports: {
        id: GeneratedHostOperationsPortCapability;
        operations: [
            GeneratedHostOperationsOperationCapability,
            ...GeneratedHostOperationsOperationCapability[]
        ];
        version: GeneratedCommonSemanticVersion;
    }[];
    protocolVersions: [GeneratedCommonSemanticVersion, ...GeneratedCommonSemanticVersion[]];
};
/** Studio host port error */
export type GeneratedHostError = {
    category: 'invalid-request' | 'unauthenticated' | 'forbidden' | 'not-found' | 'conflict' | 'validation-failed' | 'incompatible' | 'limit-exceeded' | 'rate-limited' | 'unavailable' | 'cancelled' | 'internal';
    contractVersion: GeneratedCommonContractVersion;
    correlationId?: GeneratedCommonStableId;
    diagnostics?: GeneratedCommonDiagnostic[];
    kind: 'host-error';
    message: GeneratedCommonMessageReference;
    retryAfterMilliseconds?: number;
    retryable: boolean;
    revision?: GeneratedCommonRevision;
};
/** Studio host port operation registry */
export type GeneratedHostOperations = {
    contractVersion: GeneratedCommonContractVersion;
    extensions?: GeneratedCommonExtensions;
    kind: 'host-operations';
    operations: [
        {
            capability: GeneratedHostOperationsOperationCapability;
            expectsRevision: boolean;
            method: string;
            mutating: boolean;
            operation: GeneratedCommonLocalName;
            port: GeneratedHostOperationsPortName;
            portCapability: GeneratedHostOperationsPortCapability;
            required: boolean;
            route: GeneratedHostOperationsOperationRoute;
        },
        ...{
            capability: GeneratedHostOperationsOperationCapability;
            expectsRevision: boolean;
            method: string;
            mutating: boolean;
            operation: GeneratedCommonLocalName;
            port: GeneratedHostOperationsPortName;
            portCapability: GeneratedHostOperationsPortCapability;
            required: boolean;
            route: GeneratedHostOperationsOperationRoute;
        }[]
    ];
};
/** The capability identifier a host advertises for one port operation. */
export type GeneratedHostOperationsOperationCapability = 'studio.operation/artifact.dependencies' | 'studio.operation/artifact.load' | 'studio.operation/artifact.publish' | 'studio.operation/artifact.save' | 'studio.operation/artifact.unpublish' | 'studio.operation/localization.messages' | 'studio.operation/media.abort-upload' | 'studio.operation/media.authorize-upload' | 'studio.operation/media.complete-upload' | 'studio.operation/media.get' | 'studio.operation/media.import-external' | 'studio.operation/media.list' | 'studio.operation/media.upload-status' | 'studio.operation/model.get' | 'studio.operation/model.list' | 'studio.operation/permission.explain' | 'studio.operation/permission.refresh' | 'studio.operation/preview.cancel' | 'studio.operation/preview.render' | 'studio.operation/recovery.discard' | 'studio.operation/recovery.load' | 'studio.operation/recovery.store' | 'studio.operation/resource.search' | 'studio.operation/telemetry.emit';
/** The transport route segment addressing one port operation. */
export type GeneratedHostOperationsOperationRoute = 'artifact/dependencies' | 'artifact/load' | 'artifact/publish' | 'artifact/save' | 'artifact/unpublish' | 'localization/messages' | 'media/abort-upload' | 'media/authorize-upload' | 'media/complete-upload' | 'media/get' | 'media/import-external' | 'media/list' | 'media/upload-status' | 'model/get' | 'model/list' | 'permission/explain' | 'permission/refresh' | 'preview/cancel' | 'preview/render' | 'recovery/discard' | 'recovery/load' | 'recovery/store' | 'resource/search' | 'telemetry/emit';
/** The capability identifier a host advertises for a whole port. */
export type GeneratedHostOperationsPortCapability = 'studio.port/artifact' | 'studio.port/localization' | 'studio.port/media' | 'studio.port/model' | 'studio.port/permission' | 'studio.port/preview' | 'studio.port/recovery' | 'studio.port/resource' | 'studio.port/telemetry';
export type GeneratedHostOperationsPortName = 'artifact' | 'localization' | 'media' | 'model' | 'permission' | 'preview' | 'recovery' | 'resource' | 'telemetry';
/** Studio host port request */
export type GeneratedHostRequest = {
    arguments?: GeneratedCommonJsonValue;
    context: GeneratedHostRequestRequestContext;
};
/** The request envelope. Actor identity and authorization evidence are attached by the trusted transport and never appear here: a Studio-supplied actor value is display context, never authentication. */
export type GeneratedHostRequestRequestContext = {
    expectedRevision?: GeneratedCommonRevision;
    idempotencyKey?: GeneratedCommonStableId;
    locale?: GeneratedCommonLocale;
    operationId: GeneratedHostOperationsOperationCapability;
    protocolVersion: GeneratedCommonSemanticVersion;
    requestId: GeneratedCommonStableId;
    resourceContextKey: GeneratedCommonStableId;
    sessionGeneration: GeneratedCommonRevision;
    traceContext?: {
        [key: string]: string;
    };
};
/** Studio host port result */
export type GeneratedHostResult = {
    revision?: GeneratedCommonRevision;
    value: GeneratedCommonJsonValue;
};
/** Studio canonical host sequence conformance vector */
export type GeneratedHostSequenceVector = {
    assertions: [GeneratedHostSequenceVectorAssertion, ...GeneratedHostSequenceVectorAssertion[]];
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expectFinal: GeneratedHostSequenceVectorFinalState;
    given: GeneratedHostSequenceVectorGiven;
    id: GeneratedCommonStableId;
    idempotencyPolicy: GeneratedHostSequenceVectorIdempotencyPolicy;
    kind: 'host-sequence-vector';
    profile: 'studio.profile/host-baseline-v2';
    steps: [
        (GeneratedHostSequenceVectorInvokeStep | GeneratedHostSequenceVectorSettleStep | GeneratedHostSequenceVectorAdvanceClockStep | GeneratedHostSequenceVectorReleasePreviewRenderStep),
        (GeneratedHostSequenceVectorInvokeStep | GeneratedHostSequenceVectorSettleStep | GeneratedHostSequenceVectorAdvanceClockStep | GeneratedHostSequenceVectorReleasePreviewRenderStep),
        ...(GeneratedHostSequenceVectorInvokeStep | GeneratedHostSequenceVectorSettleStep | GeneratedHostSequenceVectorAdvanceClockStep | GeneratedHostSequenceVectorReleasePreviewRenderStep)[]
    ];
};
export type GeneratedHostSequenceVectorAdvanceClockStep = {
    action: 'advance-clock';
    id: GeneratedCommonStableId;
    milliseconds: number;
};
export type GeneratedHostSequenceVectorArtifactFinalState = {
    id: GeneratedCommonStableId;
    revisionFrom: GeneratedCommonStableId;
    status: 'archived' | 'draft' | 'in-review' | 'published' | 'retired';
};
export type GeneratedHostSequenceVectorArtifactSeed = {
    id: GeneratedCommonStableId;
    kind: 'blueprint' | 'content-model' | 'entry';
    revision: GeneratedCommonRevision;
    status: 'archived' | 'draft' | 'in-review' | 'published' | 'retired';
};
export type GeneratedHostSequenceVectorAssertion = 'operation-id-mismatch-refusal' | 'idempotent-in-flight-coalescing' | 'idempotent-completed-replay' | 'idempotency-changed-argument-refusal' | 'idempotency-changed-context-refusal' | 'idempotency-resource-scope-separation' | 'canonical-number-equivalence' | 'failed-attempt-retry' | 'fixed-window-rate-limit' | 'fixed-window-reset' | 'in-flight-preview-cancellation' | 'cross-context-cancellation-isolation' | 'late-preview-result-discard';
export type GeneratedHostSequenceVectorErrorExpectation = {
    category: 'cancelled' | 'conflict' | 'forbidden' | 'incompatible' | 'internal' | 'invalid-request' | 'limit-exceeded' | 'not-found' | 'rate-limited' | 'unauthenticated' | 'unavailable' | 'validation-failed';
    outcome: 'error';
    retryAfterMilliseconds?: number;
    retryable: boolean;
};
export type GeneratedHostSequenceVectorExpectation = GeneratedHostSequenceVectorResultExpectation | GeneratedHostSequenceVectorErrorExpectation;
export type GeneratedHostSequenceVectorFinalState = {
    artifacts?: GeneratedHostSequenceVectorArtifactFinalState[];
    pendingPreviewRenders: 0;
    previewDeliveries: string[];
    recovery?: GeneratedHostSequenceVectorRecoveryFinalState[];
};
export type GeneratedHostSequenceVectorGiven = {
    artifacts: GeneratedHostSequenceVectorArtifactSeed[];
    permissions: GeneratedCommonQualifiedName[];
    rateLimits?: GeneratedHostSequenceVectorRateLimit[];
    sessionGeneration: GeneratedCommonRevision;
};
/** The exact map key and canonical intent preimage used by this profile. Scope fields select an accepted record; the argument and listed semantic context fields form its canonical JSON fingerprint. Optional semantic fields that are absent are omitted, not serialized as null. Canonical JSON applies its number grammar, including normalization of negative zero to zero. Per-attempt request and trace correlation never affect intent. */
export type GeneratedHostSequenceVectorIdempotencyPolicy = {
    absentOptionalFields: 'omit';
    argumentCanonicalization: 'canonical-json';
    excludedContextFields: ['requestId', 'traceContext'];
    intentContextFields: ['expectedRevision', 'locale', 'protocolVersion'];
    numberNormalization: 'canonical-json';
    scopeFields: ['idempotencyKey', 'operationId', 'resourceContextKey', 'sessionGeneration'];
};
export type GeneratedHostSequenceVectorInvokeStep = {
    action: 'invoke';
    argument?: GeneratedCommonJsonValue;
    completion: 'pending' | 'settled';
    context: GeneratedHostSequenceVectorRequestContext;
    expect?: GeneratedHostSequenceVectorExpectation;
    id: GeneratedCommonStableId;
    operation: GeneratedCommonLocalName;
    port: GeneratedHostSequenceVectorPort;
};
export type GeneratedHostSequenceVectorPort = 'artifact' | 'localization' | 'media' | 'model' | 'permission' | 'preview' | 'recovery' | 'resource' | 'telemetry';
export type GeneratedHostSequenceVectorRateLimit = {
    maximumRequests: number;
    operationId: GeneratedHostOperationsOperationCapability;
    retryAfterMilliseconds: number;
    windowMilliseconds: number;
};
export type GeneratedHostSequenceVectorRecoveryFinalState = {
    resourceContextKey: GeneratedCommonStableId;
    value: GeneratedCommonJsonValue;
};
/** A deterministic renderer completion injected after a prior preview.render invocation. It is a harness precondition, not a host port or transport operation. */
export type GeneratedHostSequenceVectorReleasePreviewRenderStep = {
    action: 'release-preview-render';
    id: GeneratedCommonStableId;
    invocation: GeneratedCommonStableId;
    value: GeneratedPreviewMessageRendered;
};
/** The canonical host request envelope. Malformed single exchanges remain in the original host corpus; an intentional registered-capability mismatch is an executable invalid-request assertion here. */
export type GeneratedHostSequenceVectorRequestContext = GeneratedHostRequestRequestContext;
export type GeneratedHostSequenceVectorResultExpectation = {
    outcome: 'result';
    revisionAdvancesFrom?: GeneratedCommonRevision;
    sameAs?: GeneratedCommonStableId;
    value?: 'null' | 'rendered';
};
export type GeneratedHostSequenceVectorSettleStep = {
    action: 'settle';
    expect: GeneratedHostSequenceVectorExpectation;
    id: GeneratedCommonStableId;
    invocation: GeneratedCommonStableId;
};
/** Studio canonical host conformance vector */
export type GeneratedHostVector = {
    argument?: GeneratedCommonJsonValue;
    context: {
        expectedRevision?: GeneratedCommonRevision;
        idempotencyKey?: GeneratedCommonStableId;
        locale?: GeneratedCommonLocale;
        operationId: GeneratedCommonQualifiedName;
        protocolVersion: string;
        requestId: string;
        resourceContextKey: GeneratedCommonStableId;
        sessionGeneration: GeneratedCommonRevision;
    };
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: {
        outcome: 'result';
        revision?: GeneratedCommonRevision;
        revisionAdvances?: boolean;
        value?: 'artifact' | 'artifact-references' | 'content-model' | 'content-models' | 'media-asset' | 'media-page' | 'message-bundle' | 'null' | 'permission-explanation' | 'permission-snapshot' | 'recovery-envelope' | 'rendered' | 'search-page' | 'upload-accepted' | 'upload-grant';
    } | {
        category: 'cancelled' | 'conflict' | 'forbidden' | 'incompatible' | 'internal' | 'invalid-request' | 'limit-exceeded' | 'not-found' | 'rate-limited' | 'unauthenticated' | 'unavailable' | 'validation-failed';
        messageMustNotContain?: [string, ...string[]];
        outcome: 'error';
        retryable?: boolean;
        revision?: GeneratedCommonRevision;
    };
    given: {
        artifacts: {
            id: GeneratedCommonStableId;
            kind: 'blueprint' | 'content-model' | 'entry';
            revision: GeneratedCommonRevision;
        }[];
        permissions: GeneratedCommonQualifiedName[];
        sessionGeneration?: GeneratedCommonRevision;
    };
    id: GeneratedCommonStableId;
    kind: 'host-vector';
    operation: GeneratedCommonLocalName;
    port: 'artifact' | 'localization' | 'media' | 'model' | 'permission' | 'preview' | 'recovery' | 'resource' | 'telemetry';
    profile: GeneratedCommonQualifiedName;
};
/** Studio inspector contribution */
export type GeneratedInspector = {
    blockTypes: [GeneratedCommonQualifiedName, ...GeneratedCommonQualifiedName[]];
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonQualifiedName;
    kind: 'inspector';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    placement: 'augment' | 'replace';
    requiredCapability?: GeneratedCommonQualifiedName;
    version: GeneratedCommonSemanticVersion;
};
/** Studio host-owned media asset */
export type GeneratedMediaAsset = {
    byteSize: number;
    contractVersion: GeneratedCommonContractVersion;
    extensions?: GeneratedCommonExtensions;
    filename: string;
    id: GeneratedCommonStableId;
    kind: 'media-asset';
    mediaKind: 'image' | 'video' | 'audio' | 'document' | 'archive' | 'other';
    mediaType: string;
    metadata: {
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
    };
    renditions?: {
        height: number;
        id: GeneratedCommonLocalName;
        mediaType: string;
        width: number;
    }[];
    revision: GeneratedCommonRevision;
    state: 'processing' | 'ready' | 'rejected' | 'quarantined' | 'archived';
};
/** Studio persisted media reference */
export type GeneratedMediaReference = {
    accessibility: {
        altText: string;
        caption?: string;
        mode: 'informative';
    } | {
        mode: 'decorative';
    } | {
        altFieldPath: [GeneratedCommonLocalName, ...GeneratedCommonLocalName[]];
        captionFieldPath?: [GeneratedCommonLocalName, ...GeneratedCommonLocalName[]];
        mode: 'bound';
    };
    assetId: GeneratedCommonStableId;
    assetRevision?: GeneratedCommonRevision;
    contractVersion: GeneratedCommonContractVersion;
    cropIntent?: {
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
    focalPoint?: {
        x: number;
        y: number;
    };
    kind: 'media-reference';
    renditionIntent?: {
        fit?: 'contain' | 'cover' | 'fill' | 'scale-down';
        preferredMediaTypes?: string[];
        role: GeneratedCommonLocalName;
    };
    usage: GeneratedCommonQualifiedName;
};
/** Studio media upload grant */
export type GeneratedMediaUploadGrant = {
    expiresAt: GeneratedCommonRfc3339Instant;
    headers?: {
        [key: string]: string;
    };
    method: 'POST' | 'PUT';
    plan: {
        chunkBytes?: number;
        maximumBytes: number;
        resumable: boolean;
    };
    uploadId: GeneratedCommonStableId;
    url: string;
};
/** Studio media upload session */
export type GeneratedMediaUploadSession = {
    asset?: GeneratedMediaUploadSessionAcceptedAsset;
    contractVersion: GeneratedCommonContractVersion;
    failure?: GeneratedCommonDiagnostic;
    id: GeneratedCommonStableId;
    kind: 'media-upload-session';
    plan?: GeneratedMediaUploadSessionPlan;
    progress: {
        totalBytes: number;
        transferredBytes: number;
    };
    request: {
        byteSize: number;
        checksum?: GeneratedCommonIntegrity;
        filename: string;
        mediaType: string;
        purpose: GeneratedCommonQualifiedName;
    };
    state: 'requested' | 'authorized' | 'transferring' | 'verifying' | 'complete' | 'failed' | 'cancelled';
};
export type GeneratedMediaUploadSessionAcceptedAsset = {
    id: GeneratedCommonStableId;
    revision: GeneratedCommonRevision;
    state: 'processing' | 'ready' | 'rejected' | 'quarantined';
};
export type GeneratedMediaUploadSessionPlan = {
    chunkBytes?: number;
    maximumBytes: number;
    resumable: boolean;
};
/** Studio canonical media policy vector */
export type GeneratedMediaVector = {
    cancel?: {
        during: 'complete' | 'requested' | 'transferring' | 'verifying';
        finalState: 'cancelled' | 'complete';
    };
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: {
        outcome: 'accepted';
        plan: GeneratedMediaVectorPlan;
    } | {
        code: GeneratedMediaVectorFailureCode;
        messageMustNotContain?: [string, ...string[]];
        outcome: 'rejected';
    };
    id: GeneratedCommonStableId;
    kind: 'media-vector';
    policy: {
        acceptedMediaTypes: [GeneratedMediaVectorMediaType, ...GeneratedMediaVectorMediaType[]];
        chunkBytes?: number;
        maximumBytes: number;
        resumable: boolean;
    };
    request: {
        byteSize: number;
        checksum?: GeneratedCommonIntegrity;
        filename: string;
        mediaType: GeneratedMediaVectorMediaType;
        purpose: GeneratedCommonQualifiedName;
    };
    retry?: {
        freshSession: true;
    };
};
export type GeneratedMediaVectorFailureCode = 'studio.media/upload-failed' | 'studio.media/upload-too-large';
export type GeneratedMediaVectorMediaType = string;
export type GeneratedMediaVectorPlan = {
    chunkBytes?: number;
    maximumBytes: number;
    resumable: boolean;
};
/** Studio migration declaration */
export type GeneratedMigration = {
    artifactKinds: [
        'block-definition' | 'blueprint' | 'content-model' | 'entry' | 'theme',
        ...('block-definition' | 'blueprint' | 'content-model' | 'entry' | 'theme')[]
    ];
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonQualifiedName;
    kind: 'migration';
    label: GeneratedCommonMessageReference;
    lossClassification: 'lossless' | 'lossy';
    owner: GeneratedCommonOwnerReference;
    sourceVersions: GeneratedCommonVersionRange;
    targetVersion: GeneratedCommonSemanticVersion;
    version: GeneratedCommonSemanticVersion;
};
/** Studio composition pattern */
export type GeneratedPattern = {
    blockDependencies: {
        integrity?: GeneratedCommonIntegrity;
        revision: GeneratedCommonRevision;
        type: GeneratedCommonQualifiedName;
        version: GeneratedCommonSemanticVersion;
    }[];
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonStableId;
    kind: 'pattern';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    revision: GeneratedCommonRevision;
    roots: [GeneratedBlueprintNode, ...GeneratedBlueprintNode[]];
    version: GeneratedCommonSemanticVersion;
};
/** Studio plugin manifest */
export type GeneratedPluginManifest = {
    activation: 'declarative' | 'executable';
    contractVersion: GeneratedCommonContractVersion;
    contributions: {
        executable?: boolean;
        id: GeneratedCommonQualifiedName;
        integrity: GeneratedCommonIntegrity;
        kind: 'block' | 'command' | 'design-vocabulary' | 'field-adapter' | 'inspector' | 'locale' | 'migration' | 'panel' | 'pattern' | 'renderer-capability' | 'test-fixture' | 'transform';
        resource: GeneratedCommonPackageRelativePath;
        version: GeneratedCommonSemanticVersion;
    }[];
    dependencies: {
        id: GeneratedCommonQualifiedName;
        optional: boolean;
        versions: GeneratedCommonVersionRange;
    }[];
    description?: GeneratedCommonMessageReference;
    entryModules: {
        integrity: GeneratedCommonIntegrity;
        path: GeneratedCommonPackageRelativePath;
        realm: 'application' | 'worker' | 'sandboxed-frame';
    }[];
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonQualifiedName;
    kind: 'plugin-manifest';
    label: GeneratedCommonMessageReference;
    locales?: GeneratedCommonLocale[];
    optionalCapabilities: GeneratedPluginManifestCapabilityRequirement[];
    owner: GeneratedCommonOwnerReference;
    permissions: GeneratedCommonQualifiedName[];
    requiredCapabilities: GeneratedPluginManifestCapabilityRequirement[];
    version: GeneratedCommonSemanticVersion;
};
export type GeneratedPluginManifestCapabilityRequirement = {
    id: GeneratedCommonQualifiedName;
    versions: GeneratedCommonVersionRange;
};
/** Studio preview channel message */
export type GeneratedPreviewMessage = {
    channelId: GeneratedCommonStableId;
    contractVersion: GeneratedCommonContractVersion;
    kind: 'preview-message';
    payload: {
        [key: string]: GeneratedCommonJsonValue;
    };
    sequence: number;
    sessionGeneration: GeneratedCommonRevision;
    type: 'studio.preview/activated' | 'studio.preview/dispose' | 'studio.preview/error' | 'studio.preview/measure' | 'studio.preview/measurements' | 'studio.preview/ready' | 'studio.preview/reload' | 'studio.preview/render' | 'studio.preview/rendered' | 'studio.preview/select' | 'studio.preview/teardown' | 'studio.preview/viewport';
};
export type GeneratedPreviewMessageActivated = {
    interaction: 'activate' | 'context-menu' | 'focus';
    marker: GeneratedPreviewMessagePreviewMarker;
};
export type GeneratedPreviewMessageDispose = {
    draftDigest?: string;
    reason: GeneratedCommonQualifiedName;
};
export type GeneratedPreviewMessageError = {
    code: GeneratedCommonQualifiedName;
    correlationId?: GeneratedCommonStableId;
    message: GeneratedCommonMessageReference;
    retryable: boolean;
};
export type GeneratedPreviewMessageMarkerRect = {
    height: number;
    width: number;
    x: number;
    y: number;
};
export type GeneratedPreviewMessageMeasure = {
    markers: [GeneratedPreviewMessagePreviewMarker, ...GeneratedPreviewMessagePreviewMarker[]];
    requestId: GeneratedCommonStableId;
};
export type GeneratedPreviewMessageMeasurements = {
    draftDigest: string;
    measurements: {
        [key: string]: [GeneratedPreviewMessageMarkerRect, ...GeneratedPreviewMessageMarkerRect[]];
    };
    requestId: GeneratedCommonStableId;
    unknown: GeneratedPreviewMessagePreviewMarker[];
    viewport: GeneratedPreviewMessageViewportMetrics;
};
/** A deterministic marker for one node in a canonical draft: the draft digest followed by its zero-based Blueprint preorder ordinal. */
export type GeneratedPreviewMessagePreviewMarker = string;
export type GeneratedPreviewMessageReady = {
    protocolVersion: GeneratedCommonSemanticVersion;
    renderer: GeneratedCommonQualifiedName;
    viewports: GeneratedCommonLocalName[];
};
export type GeneratedPreviewMessageReload = {
    reason: GeneratedCommonQualifiedName;
};
export type GeneratedPreviewMessageRender = {
    artifactId: GeneratedCommonStableId;
    draftDigest: string;
    draftRevision: GeneratedCommonRevision;
    requestId: GeneratedCommonStableId;
    viewport: GeneratedCommonLocalName;
};
export type GeneratedPreviewMessageRendered = {
    diagnostics: GeneratedCommonDiagnostic[];
    draftDigest: string;
    markerMap: {
        [key: string]: GeneratedCommonStableId;
    };
    markers: GeneratedPreviewMessagePreviewMarker[];
    requestId: GeneratedCommonStableId;
};
export type GeneratedPreviewMessageSelect = {
    nodeId: GeneratedCommonStableId;
    reveal?: boolean;
};
export type GeneratedPreviewMessageTeardown = {
    reason: GeneratedCommonQualifiedName;
};
/** A semantic viewport role and explicit dimensions are alternatives, never a merge. Each branch is closed, so a payload naming both matches neither. */
export type GeneratedPreviewMessageViewport = {
    viewport: GeneratedCommonLocalName;
} | {
    height?: number;
    width?: number;
};
export type GeneratedPreviewMessageViewportMetrics = {
    devicePixelRatio: number;
    height: number;
    scrollX: number;
    scrollY: number;
    width: number;
};
/** Studio preview identity conformance vector */
export type GeneratedPreviewVector = {
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    draft: GeneratedBlueprint;
    expect: {
        draftDigest: string;
        markerMap: {
            [key: string]: GeneratedCommonStableId;
        };
        markers: GeneratedPreviewMessagePreviewMarker[];
    };
    id: GeneratedCommonStableId;
    kind: 'preview-vector';
    profile: 'studio.profile/preview-identity-v1';
    protocolVersion: '0.1.0-draft.2';
    render: GeneratedPreviewMessageRender;
};
/** Studio artifact provenance record */
export type GeneratedProvenance = {
    artifact: {
        id: GeneratedCommonStableId;
        revision: GeneratedCommonRevision;
    };
    chain: [
        {
            actor: {
                displayName?: string;
                id: GeneratedCommonStableId;
            };
            commandCount?: number;
            fromRevision?: GeneratedCommonRevision;
            migrationId?: GeneratedCommonQualifiedName;
            origin: 'authoring' | 'import' | 'migration' | 'pattern' | 'plugin' | 'system';
            recordedAt: GeneratedCommonRfc3339Instant;
            sessionId?: GeneratedCommonStableId;
            source?: GeneratedCommonArtifactReference;
            toRevision: GeneratedCommonRevision;
        },
        ...{
            actor: {
                displayName?: string;
                id: GeneratedCommonStableId;
            };
            commandCount?: number;
            fromRevision?: GeneratedCommonRevision;
            migrationId?: GeneratedCommonQualifiedName;
            origin: 'authoring' | 'import' | 'migration' | 'pattern' | 'plugin' | 'system';
            recordedAt: GeneratedCommonRfc3339Instant;
            sessionId?: GeneratedCommonStableId;
            source?: GeneratedCommonArtifactReference;
            toRevision: GeneratedCommonRevision;
        }[]
    ];
    contractVersion: GeneratedCommonContractVersion;
    extensions?: GeneratedCommonExtensions;
    kind: 'provenance';
};
/** Studio renderer-web conformance vector */
export type GeneratedRendererWebVector = {
    bindings: {
        nodeId: GeneratedCommonStableId;
        port: GeneratedCommonLocalName;
        value: GeneratedCommonJsonValue;
    }[];
    context?: {
        allowBlobMedia?: boolean;
    };
    coverage: {
        behaviors: ('accordion-native' | 'countdown' | 'dialog' | 'lightbox' | 'navigation-disclosure' | 'notice-dismiss' | 'popover' | 'slideshow' | 'tabs')[];
        blockTypes: GeneratedCommonQualifiedName[];
        presentation: ('alignment' | 'inverse' | 'markers' | 'motion' | 'position' | 'print' | 'responsive-visibility' | 'scrolling' | 'sizing' | 'spacing')[];
        security: ('active-media-deny' | 'blob-default-deny' | 'escaped-text' | 'safe-url-deny' | 'typed-data-fallback')[];
    };
    expect: {
        cssContains: string[];
        enhancements: ('chart' | 'countdown' | 'diagram' | 'dialog' | 'lightbox' | 'math' | 'motion' | 'navigation' | 'notice' | 'popover' | 'slideshow' | 'tabs')[];
        htmlContains: string[];
        htmlExcludes: string[];
    };
    id: GeneratedCommonStableId;
    media: {
        altText: string;
        assetId: GeneratedCommonStableId;
        caption?: string;
        height?: number;
        mediaType?: string;
        src: string;
        width?: number;
    }[];
    roots: [GeneratedBlueprintNode, ...GeneratedBlueprintNode[]];
};
/** Studio rich-text renderer-conformance fixture */
export type GeneratedRichTextProjection = {
    description: string;
    document: GeneratedRichText;
    projection: GeneratedRichTextProjectionBlockProjection[];
};
export type GeneratedRichTextProjectionBlockProjection = {
    embeds: GeneratedRichTextProjectionEmbed[];
    spans: GeneratedRichTextProjectionSpan[];
    text: string;
    type: 'checklistItem' | 'codeBlock' | 'heading' | 'horizontalRule' | 'paragraph' | 'tableCell';
};
export type GeneratedRichTextProjectionEmbed = {
    index: number;
    kind: 'hardBreak';
};
export type GeneratedRichTextProjectionSpan = {
    end: number;
    marks: [
        'bold' | 'code' | 'highlight' | 'italic' | 'strike',
        ...('bold' | 'code' | 'highlight' | 'italic' | 'strike')[]
    ];
    start: number;
};
/** Studio portable rich text */
export type GeneratedRichText = GeneratedRichTextDoc;
export type GeneratedRichTextBlock = GeneratedRichTextParagraph | GeneratedRichTextHeading | GeneratedRichTextBlockquote | GeneratedRichTextBulletList | GeneratedRichTextOrderedList | GeneratedRichTextHorizontalRule | GeneratedRichTextChecklist | GeneratedRichTextTable | GeneratedRichTextCallout | GeneratedRichTextCodeBlock;
export type GeneratedRichTextBlockquote = {
    content: [GeneratedRichTextBlock, ...GeneratedRichTextBlock[]];
    type: 'blockquote';
};
export type GeneratedRichTextBulletList = {
    content: [GeneratedRichTextListItem, ...GeneratedRichTextListItem[]];
    type: 'bulletList';
};
export type GeneratedRichTextCallout = {
    attrs: {
        tone: 'danger' | 'info' | 'success' | 'warning';
    };
    content: [GeneratedRichTextBlock, ...GeneratedRichTextBlock[]];
    type: 'callout';
};
export type GeneratedRichTextChecklist = {
    content: [GeneratedRichTextChecklistItem, ...GeneratedRichTextChecklistItem[]];
    type: 'checklist';
};
export type GeneratedRichTextChecklistItem = {
    attrs: {
        checked: boolean;
        level: number;
    };
    content?: GeneratedRichTextInline[];
    type: 'checklistItem';
};
export type GeneratedRichTextCodeBlock = {
    attrs: {
        language: string;
    };
    text: string;
    type: 'codeBlock';
};
export type GeneratedRichTextDoc = {
    content?: GeneratedRichTextBlock[];
    type: 'doc';
};
export type GeneratedRichTextHardBreak = {
    type: 'hardBreak';
};
export type GeneratedRichTextHeading = {
    attrs: {
        level: 2 | 3 | 4;
    };
    content?: GeneratedRichTextInline[];
    type: 'heading';
};
export type GeneratedRichTextHorizontalRule = {
    type: 'horizontalRule';
};
export type GeneratedRichTextInline = GeneratedRichTextText | GeneratedRichTextHardBreak;
export type GeneratedRichTextListItem = {
    content: [GeneratedRichTextBlock, ...GeneratedRichTextBlock[]];
    type: 'listItem';
};
export type GeneratedRichTextMark = {
    type: 'bold' | 'code' | 'italic' | 'strike';
} | {
    attrs: {
        tone: 'accent' | 'danger' | 'info' | 'success' | 'warning';
    };
    type: 'highlight';
};
export type GeneratedRichTextOrderedList = {
    attrs?: {
        start: number;
    };
    content: [GeneratedRichTextListItem, ...GeneratedRichTextListItem[]];
    type: 'orderedList';
};
export type GeneratedRichTextParagraph = {
    content?: GeneratedRichTextInline[];
    type: 'paragraph';
};
export type GeneratedRichTextTable = {
    attrs: {
        header: boolean;
    };
    content: [GeneratedRichTextTableRow, ...GeneratedRichTextTableRow[]];
    type: 'table';
};
export type GeneratedRichTextTableCell = {
    content?: GeneratedRichTextInline[];
    type: 'tableCell';
};
export type GeneratedRichTextTableRow = {
    content: [GeneratedRichTextTableCell, ...GeneratedRichTextTableCell[]];
    type: 'tableRow';
};
export type GeneratedRichTextText = {
    marks?: GeneratedRichTextMark[];
    text: string;
    type: 'text';
};
/** Studio property-schema profile conformance vector */
export type GeneratedSchemaProfileVector = {
    boundary?: GeneratedSchemaProfileVectorLimitBoundary;
    contractVersion: GeneratedCommonContractVersion;
    description: string;
    expect: {
        instances: [
            {
                diagnostic?: GeneratedSchemaProfileVectorInstanceDiagnostic;
                valid: boolean;
                value: GeneratedCommonJsonValue;
            },
            ...{
                diagnostic?: GeneratedSchemaProfileVectorInstanceDiagnostic;
                valid: boolean;
                value: GeneratedCommonJsonValue;
            }[]
        ];
        outcome: 'accepted';
    } | {
        code: 'invalid-root' | 'unsupported-keyword' | 'invalid-keyword-value' | 'unsafe-member' | 'limit-exceeded' | 'invalid-reference' | 'recursive-schema';
        outcome: 'rejected';
        schemaPath: GeneratedSchemaProfileVectorJsonPointer;
    };
    id: GeneratedCommonStableId;
    kind: 'schema-profile-vector';
    profile: 'studio.profile/schema-property';
    schema: GeneratedJsonValue;
};
export type GeneratedSchemaProfileVectorInstanceDiagnostic = {
    instancePath: GeneratedSchemaProfileVectorJsonPointer;
    keyword: string;
};
export type GeneratedSchemaProfileVectorJsonPointer = string;
export type GeneratedSchemaProfileVectorLimitBoundary = {
    limit: 'maxAlternatives' | 'maxDescriptionLength' | 'maxEnumMembers' | 'maxExamples' | 'maxJsonDepth' | 'maxJsonItems' | 'maxJsonProperties' | 'maxObjectKeyLength' | 'maxPropertyNames' | 'maxReferenceLength' | 'maxReferences' | 'maxSchemaBytes' | 'maxSchemaDepth' | 'maxSchemaMapProperties' | 'maxSchemaNodes' | 'maxTitleLength';
    position: 'at-limit' | 'over-limit';
    value: number;
};
/** Studio Schema Profile meta-schema */
export type GeneratedSchemaProfile = GeneratedSchemaProfileSchema & {
    additionalProperties: false;
    type: 'object';
    [key: string]: GeneratedJsonValue | false | 'object';
};
export type GeneratedSchemaProfileJsonValue = null | boolean | number | string | GeneratedSchemaProfileJsonValue[] | {
    [key: string]: GeneratedSchemaProfileJsonValue;
};
export type GeneratedSchemaProfileLimits = {
    maxAlternatives: 64;
    maxDescriptionLength: 10000;
    maxEnumMembers: 1024;
    maxExamples: 100;
    maxJsonDepth: 64;
    maxJsonItems: 10000;
    maxJsonProperties: 1000;
    maxObjectKeyLength: 200;
    maxPropertyNames: 512;
    maxReferenceLength: 500;
    maxReferences: 128;
    maxSchemaBytes: 262144;
    maxSchemaDepth: 32;
    maxSchemaMapProperties: 512;
    maxSchemaNodes: 1024;
    maxTitleLength: 1000;
};
export type GeneratedSchemaProfileSchema = {
    $defs?: GeneratedSchemaProfileSchemaMap;
    $ref?: string;
    $schema?: 'https://json-schema.org/draft/2020-12/schema';
    additionalProperties?: GeneratedSchemaProfileSubschema;
    allOf?: GeneratedSchemaProfileSchemaArray;
    anyOf?: GeneratedSchemaProfileSchemaArray;
    const?: GeneratedSchemaProfileJsonValue;
    default?: GeneratedSchemaProfileJsonValue;
    dependentRequired?: {
        [key: string]: GeneratedCommonSafeJsonMemberName[];
    };
    description?: string;
    else?: GeneratedSchemaProfileSubschema;
    enum?: [GeneratedSchemaProfileJsonValue, ...GeneratedSchemaProfileJsonValue[]];
    examples?: GeneratedSchemaProfileJsonValue[];
    exclusiveMaximum?: number;
    exclusiveMinimum?: number;
    if?: GeneratedSchemaProfileSubschema;
    items?: GeneratedSchemaProfileSubschema;
    maxItems?: number;
    maxLength?: number;
    maxProperties?: number;
    maximum?: number;
    minItems?: number;
    minLength?: number;
    minProperties?: number;
    minimum?: number;
    multipleOf?: number;
    not?: GeneratedSchemaProfileSubschema;
    oneOf?: GeneratedSchemaProfileSchemaArray;
    prefixItems?: GeneratedSchemaProfileSchemaArray;
    properties?: GeneratedSchemaProfileSchemaMap;
    propertyNames?: GeneratedSchemaProfileSubschema;
    readOnly?: boolean;
    required?: GeneratedCommonSafeJsonMemberName[];
    then?: GeneratedSchemaProfileSubschema;
    title?: string;
    type?: GeneratedSchemaProfileTypeName | [GeneratedSchemaProfileTypeName, ...GeneratedSchemaProfileTypeName[]];
    uniqueItems?: boolean;
    writeOnly?: boolean;
    [key: string]: GeneratedJsonValue | GeneratedSchemaProfileSchemaMap | string | 'https://json-schema.org/draft/2020-12/schema' | GeneratedSchemaProfileSubschema | GeneratedSchemaProfileSchemaArray | GeneratedSchemaProfileJsonValue | {
        [key: string]: GeneratedCommonSafeJsonMemberName[];
    } | [GeneratedSchemaProfileJsonValue, ...GeneratedSchemaProfileJsonValue[]] | GeneratedSchemaProfileJsonValue[] | number | boolean | GeneratedCommonSafeJsonMemberName[] | (GeneratedSchemaProfileTypeName | [GeneratedSchemaProfileTypeName, ...GeneratedSchemaProfileTypeName[]]);
};
export type GeneratedSchemaProfileSchemaArray = [
    GeneratedSchemaProfileSubschema,
    ...GeneratedSchemaProfileSubschema[]
];
export type GeneratedSchemaProfileSchemaMap = {
    [key: string]: GeneratedSchemaProfileSchema;
};
export type GeneratedSchemaProfileSubschema = boolean | GeneratedSchemaProfileSchema;
export type GeneratedSchemaProfileTypeName = 'array' | 'boolean' | 'integer' | 'null' | 'number' | 'object' | 'string';
/** Studio canonical chart */
export type GeneratedStudioChart = {
    datasets: [{
        label: string;
        values: number[];
    }, ...{
        label: string;
        values: number[];
    }[]];
    labels: string[];
    title?: string;
    type: 'bar' | 'doughnut' | 'line' | 'pie';
};
/** Studio resolved session configuration */
export type GeneratedStudioConfig = {
    actor: {
        displayName: string;
        id: GeneratedCommonStableId;
    };
    artifacts: {
        blueprint?: GeneratedCommonLockedArtifactReference;
        entry?: GeneratedCommonResolvedEntryReference;
        model?: GeneratedCommonLockedArtifactReference;
        theme?: GeneratedCommonLockedArtifactReference;
    };
    blocks: {
        integrity?: GeneratedCommonIntegrity;
        revision: GeneratedCommonRevision;
        type: GeneratedCommonQualifiedName;
        version: GeneratedCommonSemanticVersion;
    }[];
    composite: 'single' | 'hybrid';
    contractVersion: GeneratedCommonContractVersion;
    displayPreferences: {
        calendar: GeneratedCommonLocalName;
        hourCycle: 'h11' | 'h12' | 'h23' | 'h24';
        measurementSystem?: 'metric' | 'us' | 'uk';
        numberingSystem: GeneratedCommonLocalName;
    };
    extensions?: GeneratedCommonExtensions;
    features: {
        clipboardMediaUpload: boolean;
        collaboration: boolean;
        customInspectors: boolean;
        executablePlugins: boolean;
        externalMediaImport: boolean;
        offlineRecovery: boolean;
    };
    hostCapabilities: GeneratedHostCapabilities;
    limits: {
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
    };
    locale: {
        direction: 'ltr' | 'rtl';
        fallbacks: GeneratedCommonLocale[];
        requested: GeneratedCommonLocale;
        resolved: GeneratedCommonLocale;
        timeZone: string;
    };
    mode: 'model' | 'blueprint' | 'content';
    permissions: GeneratedCommonQualifiedName[];
    plugins: GeneratedCommonLockedArtifactReference[];
    preview: {
        allowApproximateRenderer: boolean;
        enabled: boolean;
        initialViewport?: GeneratedCommonLocalName;
        sameOriginRequired: boolean;
    };
    protocolVersion: GeneratedCommonSemanticVersion;
    resourceContext: {
        key: GeneratedCommonStableId;
        resource?: {
            id: GeneratedCommonStableId;
            type: GeneratedCommonQualifiedName;
        };
        revision?: GeneratedCommonRevision;
        scopes: {
            id: GeneratedCommonStableId;
            kind: GeneratedCommonQualifiedName;
        }[];
        surface: GeneratedCommonQualifiedName;
    };
    sessionGeneration: GeneratedCommonRevision;
    sessionId: GeneratedCommonStableId;
    sessionState: 'editable' | 'read-only';
};
/** Studio canonical drawing */
export type GeneratedStudioDrawing = {
    alt: string;
    height: number;
    strokes: {
        color: string;
        points: [{
            x: number;
            y: number;
        }, ...{
            x: number;
            y: number;
        }[]];
        width: number;
    }[];
    width: number;
};
/** Studio canonical money */
export type GeneratedStudioMoney = {
    amount: string;
    currency: string;
};
/** Studio presentation intent */
export type GeneratedStudioPresentation = {
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
};
/** Studio coordinated release record */
export type GeneratedStudioRelease = {
    claimedProfiles: GeneratedCommonQualifiedName[];
    contractVersion: GeneratedCommonContractVersion;
    corpusManifestDigest: GeneratedCommonIntegrity;
    kind: 'studio-release';
    packages: {
        '@kumwe/studio': GeneratedCommonSemanticVersion;
        '@kumwe/studio-core': GeneratedCommonSemanticVersion;
        '@kumwe/studio-media': GeneratedCommonSemanticVersion;
        '@kumwe/studio-preview': GeneratedCommonSemanticVersion;
        '@kumwe/studio-protocol': GeneratedCommonSemanticVersion;
        '@kumwe/studio-renderer-web': GeneratedCommonSemanticVersion;
        '@kumwe/studio-rich-text': GeneratedCommonSemanticVersion;
        '@kumwe/studio-testkit': GeneratedCommonSemanticVersion;
    };
    protocolVersion: GeneratedCommonSemanticVersion;
    release: GeneratedCommonSemanticVersion;
};
/** Studio table document */
export type GeneratedStudioTable = {
    caption?: string;
    columns: [string, ...string[]];
    rows: [string, ...string[]][];
};
/** Studio theme design profile */
export type GeneratedTheme = {
    aliases?: {
        equivalentMeaning: true & boolean;
        from: GeneratedCommonLocalName;
        kind: 'viewport' | 'design-control' | 'choice' | 'recipe';
        to: GeneratedCommonLocalName;
    }[];
    blockSupport: {
        renderer: GeneratedCommonQualifiedName;
        type: GeneratedCommonQualifiedName;
        versions: GeneratedCommonVersionRange;
    }[];
    contractVersion: GeneratedCommonContractVersion;
    description?: GeneratedCommonMessageReference;
    designControls: {
        choices: [
            {
                deprecated?: boolean;
                id: GeneratedCommonLocalName;
                label: GeneratedCommonMessageReference;
            },
            ...{
                deprecated?: boolean;
                id: GeneratedCommonLocalName;
                label: GeneratedCommonMessageReference;
            }[]
        ];
        description?: GeneratedCommonMessageReference;
        id: GeneratedCommonLocalName;
        kind: 'color-role' | 'typography-role' | 'spacing-role' | 'size-role' | 'radius-role' | 'shadow-role' | 'motion-role' | 'layer-role' | 'enum' | 'boolean' | 'integer';
        label: GeneratedCommonMessageReference;
    }[];
    extensions?: GeneratedCommonExtensions;
    id: GeneratedCommonStableId;
    kind: 'theme';
    label: GeneratedCommonMessageReference;
    owner: GeneratedCommonOwnerReference;
    recipes: {
        blockType: GeneratedCommonQualifiedName;
        designValues: {
            [key: string]: GeneratedCommonJsonValue;
        };
        id: GeneratedCommonLocalName;
        label: GeneratedCommonMessageReference;
    }[];
    renderers: [
        {
            exactPreview: boolean;
            id: GeneratedCommonQualifiedName;
            surfaces: [
                'web' | 'email' | 'document' | 'native' | 'preview',
                ...('web' | 'email' | 'document' | 'native' | 'preview')[]
            ];
            version: GeneratedCommonSemanticVersion;
        },
        ...{
            exactPreview: boolean;
            id: GeneratedCommonQualifiedName;
            surfaces: [
                'web' | 'email' | 'document' | 'native' | 'preview',
                ...('web' | 'email' | 'document' | 'native' | 'preview')[]
            ];
            version: GeneratedCommonSemanticVersion;
        }[]
    ];
    revision: GeneratedCommonRevision;
    version: GeneratedCommonSemanticVersion;
    viewports: [
        {
            base: boolean;
            id: GeneratedCommonLocalName;
            label: GeneratedCommonMessageReference;
            order: number;
            previewWidth: number;
        },
        ...{
            base: boolean;
            id: GeneratedCommonLocalName;
            label: GeneratedCommonMessageReference;
            order: number;
            previewWidth: number;
        }[]
    ];
};
/** Studio unresolved contribution */
export type GeneratedUnresolvedContribution = {
    affectedNodes?: GeneratedCommonStableId[];
    contractVersion: GeneratedCommonContractVersion;
    diagnostics?: GeneratedCommonDiagnostic[];
    kind: 'unresolved-contribution';
    owner?: GeneratedCommonOwnerReference;
    reason: 'incompatible' | 'not-installed' | 'owner-disabled' | 'owner-revoked';
    reference: {
        contribution: 'block' | 'command' | 'design-vocabulary' | 'field-adapter' | 'inspector' | 'locale' | 'migration' | 'panel' | 'pattern' | 'renderer-capability' | 'transform';
        id: GeneratedCommonQualifiedName;
        version: GeneratedCommonSemanticVersion;
    };
};
export interface GeneratedProtocolModelMap {
    readonly 'authoring-message-catalog.schema.json': GeneratedAuthoringMessageCatalog;
    readonly 'authoring-web-vector.schema.json': GeneratedAuthoringWebVector;
    readonly 'binding-projection-vector.schema.json': GeneratedBindingProjectionVector;
    readonly 'block-definition.schema.json': GeneratedBlockDefinition;
    readonly 'blueprint.schema.json': GeneratedBlueprint;
    readonly 'canonical-vector.schema.json': GeneratedCanonicalVector;
    readonly 'command-vector.schema.json': GeneratedCommandVector;
    readonly 'command.schema.json': GeneratedCommand;
    readonly 'common.schema.json': GeneratedCommon;
    readonly 'content-model.schema.json': GeneratedContentModel;
    readonly 'corpus-manifest.schema.json': GeneratedCorpusManifest;
    readonly 'design-vocabulary.schema.json': GeneratedDesignVocabulary;
    readonly 'entry.schema.json': GeneratedEntry;
    readonly 'field-adapter.schema.json': GeneratedFieldAdapter;
    readonly 'host-capabilities.schema.json': GeneratedHostCapabilities;
    readonly 'host-error.schema.json': GeneratedHostError;
    readonly 'host-operations.schema.json': GeneratedHostOperations;
    readonly 'host-request.schema.json': GeneratedHostRequest;
    readonly 'host-result.schema.json': GeneratedHostResult;
    readonly 'host-sequence-vector.schema.json': GeneratedHostSequenceVector;
    readonly 'host-vector.schema.json': GeneratedHostVector;
    readonly 'inspector.schema.json': GeneratedInspector;
    readonly 'media-asset.schema.json': GeneratedMediaAsset;
    readonly 'media-reference.schema.json': GeneratedMediaReference;
    readonly 'media-upload-grant.schema.json': GeneratedMediaUploadGrant;
    readonly 'media-upload-session.schema.json': GeneratedMediaUploadSession;
    readonly 'media-vector.schema.json': GeneratedMediaVector;
    readonly 'migration.schema.json': GeneratedMigration;
    readonly 'pattern.schema.json': GeneratedPattern;
    readonly 'plugin-manifest.schema.json': GeneratedPluginManifest;
    readonly 'preview-message.schema.json': GeneratedPreviewMessage;
    readonly 'preview-vector.schema.json': GeneratedPreviewVector;
    readonly 'provenance.schema.json': GeneratedProvenance;
    readonly 'renderer-web-vector.schema.json': GeneratedRendererWebVector;
    readonly 'rich-text-projection.schema.json': GeneratedRichTextProjection;
    readonly 'rich-text.schema.json': GeneratedRichText;
    readonly 'schema-profile-vector.schema.json': GeneratedSchemaProfileVector;
    readonly 'schema-profile.schema.json': GeneratedSchemaProfile;
    readonly 'studio-chart.schema.json': GeneratedStudioChart;
    readonly 'studio-config.schema.json': GeneratedStudioConfig;
    readonly 'studio-drawing.schema.json': GeneratedStudioDrawing;
    readonly 'studio-money.schema.json': GeneratedStudioMoney;
    readonly 'studio-presentation.schema.json': GeneratedStudioPresentation;
    readonly 'studio-release.schema.json': GeneratedStudioRelease;
    readonly 'studio-table.schema.json': GeneratedStudioTable;
    readonly 'theme.schema.json': GeneratedTheme;
    readonly 'unresolved-contribution.schema.json': GeneratedUnresolvedContribution;
}
export type GeneratedProtocolSchemaFile = keyof GeneratedProtocolModelMap;
export type GeneratedProtocolModel = GeneratedProtocolModelMap[GeneratedProtocolSchemaFile];
export declare const GENERATED_PROTOCOL_SCHEMA_FILES: readonly GeneratedProtocolSchemaFile[];
/**
 * Exercises the serialization boundary for data already accepted by the named JSON Schema.
 * This function deliberately performs no validation: schemas and semantic conformance remain authoritative.
 */
export declare function roundTripGeneratedProtocolModel<SchemaFile extends GeneratedProtocolSchemaFile>(schemaFile: SchemaFile, value: GeneratedProtocolModelMap[SchemaFile]): GeneratedProtocolModelMap[SchemaFile];
//# sourceMappingURL=schema-models.d.ts.map