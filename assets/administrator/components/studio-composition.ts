import { defineKumweStudio, type KumweStudioElement, type StudioDocumentChangeDetail, type StudioInsertRequestDetail, type StudioMessageOverrides, type StudioPreviewBinding } from '@kumwe/studio';
import { coreLayoutInitialProperties, isCoreLayoutBlockType, openStudioSession, type StudioHostSessionHandle } from '@kumwe/studio-core';
import { PreviewClient, PreviewHost, computePreviewDraftDigest, type PreviewMeasurement, type PreviewMessageEvent, type PreviewMessageListener, type PreviewMessageSource, type PreviewMessageTarget } from '@kumwe/studio-preview';
import { STUDIO_WIRE_PROTOCOL_VERSION, isHostPortFailure, type BlueprintDocument, type ContentModelDocument, type HostCapabilities, type HostRequestContext, type InsertNodeCommand, type PreviewMarkerRect, type PreviewRenderedPayload, type QualifiedName, type StableId, type StudioConfiguration, type StudioWireProtocolVersion } from '@kumwe/studio-protocol';
import { activateHostContributions, activateStudioContributions, coreStudioTheme } from './studio-contributions';
import { createStudioHttpHostAdapter, type StudioPreviewSessionMetadata } from './studio-host-adapter';

interface BootDocument {
  actor: { displayName: string; id: string };
  artifact: { id: string; revision: string; version: string };
  blockRenderers: Record<string, string>;
  csrf: string;
  contributionOwners: Record<string, string>;
  contributions: import('@kumwe/studio-core').StudioCompositionContribution[];
  document: BlueprintDocument;
  endpoints: { ports: string; session: string };
  locale: { direction: 'ltr' | 'rtl'; fallbacks: string[]; requested: string; resolved: string; timezone: string };
  model: ContentModelDocument;
  release: string;
  site: string;
  status: 'draft' | 'published' | 'retired';
}

interface OpenSessionDocument {
  hostCapabilities: QualifiedName[];
  lifecycle: { canPublish: boolean; canUnpublish: boolean };
  mode: 'blueprint';
  permissions: QualifiedName[];
  preview?: StudioPreviewSessionMetadata;
  protocolVersion: StudioWireProtocolVersion;
  resourceContextKey: string;
  resourceKind: 'blueprint';
  sessionGeneration: string;
}

export async function setupStudioComposition(): Promise<void> {
  const root = document.querySelector<HTMLElement>('[data-studio-composition]');
  const encoded = document.querySelector<HTMLScriptElement>('#studio-composition-boot');
  const status = document.querySelector<HTMLElement>('[data-studio-composition-status]');
  if (root === null || encoded === null || status === null) return;
  const surfaceMessages = compositionSurfaceMessages(root);
  defineKumweStudio();
  const shell = root.querySelector<KumweStudioElement>('kumwe-studio');
  if (shell === null) return;
  try {
    const boot = JSON.parse(encoded.textContent ?? '') as BootDocument;
    if (boot.release !== '0.1.0-alpha.11') throw new Error('Studio release binding mismatch.');
    const opened = await openHostSession(boot);
    const advertised = new Set(opened.hostCapabilities);
    const adapter = createStudioHttpHostAdapter(boot.endpoints.ports, {
      advertised,
      csrf: boot.csrf,
      ...(opened.preview === undefined ? {} : { preview: opened.preview }),
    });
    const ids = identifierFactories();
    const configuration = studioConfiguration(boot, opened);
    const handle = await openStudioSession(adapter, { configuration, identifiers: ids });
    const modelResult = await handle.models?.get(boot.document.model);
    const model = modelResult?.value ?? boot.model;
    const { runtime } = activateStudioContributions();
    activateHostContributions(runtime, boot.contributions, boot.contributionOwners);
    const generation = runtime.current;
    const admittedBlocks = new Set(boot.contributions
      .filter((contribution) => contribution.kind === 'block-definition')
      .map((contribution) => `${contribution.type}@${contribution.version}#${contribution.revision}`));
    const blocks = generation.blocks().filter((block) => {
      const trustedRenderer = boot.blockRenderers[block.type];
      return admittedBlocks.has(`${block.type}@${block.version}#${block.revision}`)
        && trustedRenderer !== undefined
        && block.rendererRequirements.some(({ capability }) => capability === trustedRenderer);
    });
    const admittedPatterns = new Set(boot.contributions
      .filter((contribution) => contribution.kind === 'pattern')
      .map((contribution) => `${contribution.id}@${contribution.version}#${contribution.revision}`));
    const theme = coreStudioTheme(blocks, boot.blockRenderers, boot.document.dependencyLock.theme);
    const messages = await loadMessages(adapter.localization, boot, opened, ids);
    shell.configuration = { session: configuration, blockDefinitions: blocks };
    shell.contentModel = model;
    shell.document = handle.session.document;
    shell.messages = messages;
    shell.patterns = (generation.contributions('pattern') as import('@kumwe/studio-protocol').PatternDocument[])
      .filter((pattern) => admittedPatterns.has(`${pattern.id}@${pattern.version}#${pattern.revision}`));
    shell.theme = theme;
    shell.designControls = theme.designControls;
    shell.viewports = theme.viewports;

    let disposePreview = (): void => undefined;
    let saveTail: Promise<void> = Promise.resolve();
    let conflicted = false;
    let lifecycleChanging = false;
    const quarantineConflict = (): void => {
      if (conflicted) return;
      conflicted = true;
      disposePreview();
      handle.dispose();
      status.textContent = surfaceMessages.conflict;
      const refusal = document.createElement('div');
      refusal.className = 'notice error';
      refusal.dataset.studioCompositionConflict = '';
      refusal.setAttribute('role', 'alert');
      refusal.textContent = surfaceMessages.conflict;
      shell.replaceWith(refusal);
    };
    const save = (): Promise<void> => {
      saveTail = saveTail.then(async () => {
        if (conflicted || !handle.session.dirty) return;
        try {
          const acceptedStateVersion = handle.session.stateVersion;
          const accepted = await handle.save();
          shell.markSaved(accepted.revision, acceptedStateVersion);
          await shell.updateComplete;
          status.textContent = surfaceMessages.saved;
        } catch (error) {
          if (isHostPortFailure(error) && error.error.category === 'conflict') {
            quarantineConflict();
            return;
          }
          status.textContent = surfaceMessages.saveFailed;
          throw error;
        }
      }).catch(() => undefined);
      return saveTail;
    };
    shell.addEventListener('studio-document-change', (event) => {
      if (conflicted || lifecycleChanging || handle.session.sessionState === 'read-only') return;
      const detail = (event as CustomEvent<StudioDocumentChangeDetail>).detail;
      try {
        if (detail.source === 'command' && detail.command !== null) handle.session.execute(detail.command);
        else if (detail.source === 'undo') handle.session.undo();
        else if (detail.source === 'redo') handle.session.redo();
        shell.document = handle.session.document;
        void save();
      } catch {
        status.textContent = surfaceMessages.changeRefused;
      }
    });
    shell.addEventListener('studio-insert-request', (event) => {
      if (conflicted || lifecycleChanging || handle.session.sessionState === 'read-only') return;
      const detail = (event as CustomEvent<StudioInsertRequestDetail>).detail;
      const command = insertCommand(shell, handle, detail, opened.sessionGeneration);
      shell.execute(command);
      shell.selectNode(command.payload.node.id);
    });
    if (opened.preview !== undefined && adapter.preview !== undefined) {
      const frame = root.querySelector<HTMLIFrameElement>('[data-studio-preview]');
      if (frame !== null) {
        const preview = previewBinding(frame, opened.preview, opened, adapter.preview, handle, shell, save);
        shell.previewBinding = preview.binding;
        disposePreview = preview.dispose;
      }
    }
    let disposed = false;
    const dispose = (): void => {
      if (disposed) return;
      disposed = true;
      disposePreview();
      handle.dispose();
    };
    const lifecycleButtons = [...root.querySelectorAll<HTMLButtonElement>('[data-studio-publish], [data-studio-unpublish]')];
    const mayChangeLifecycle = (target: 'draft' | 'published'): boolean => target === 'published'
      ? opened.lifecycle.canPublish
      : opened.lifecycle.canUnpublish;
    for (const button of lifecycleButtons) {
      button.hidden = !mayChangeLifecycle(button.hasAttribute('data-studio-publish') ? 'published' : 'draft');
    }
    let lifecycleTail: Promise<void> = Promise.resolve();
    const changeLifecycle = (target: 'draft' | 'published'): void => {
      lifecycleTail = lifecycleTail.then(async () => {
        if (conflicted || !mayChangeLifecycle(target) || boot.status === target) return;
        lifecycleChanging = true;
        shell.inert = true;
        shell.setAttribute('aria-busy', 'true');
        for (const button of lifecycleButtons) button.disabled = true;
        status.textContent = target === 'published' ? surfaceMessages.publishing : surfaceMessages.unpublishing;
        if (target === 'published') {
          await save();
          await shell.updateComplete;
          if (handle.session.dirty) throw new Error('The latest draft was not accepted before publication.');
        }
        const operationId = `studio.operation/artifact.${target === 'published' ? 'publish' : 'unpublish'}` as QualifiedName;
        const context: HostRequestContext = {
          expectedRevision: handle.revision,
          idempotencyKey: ids.idempotencyKey(operationId),
          operationId,
          protocolVersion: opened.protocolVersion,
          requestId: ids.requestId(operationId),
          resourceContextKey: opened.resourceContextKey,
          sessionGeneration: opened.sessionGeneration,
        };
        const reference = { id: boot.artifact.id, revision: handle.revision, version: boot.artifact.version };
        const result = target === 'published'
          ? await adapter.artifact.publish(reference, context)
          : await adapter.artifact.unpublish(reference, context);
        if (result.revision === undefined) throw new Error('The lifecycle mutation omitted its accepted revision.');
        dispose();
        window.location.reload();
      }).catch((error: unknown) => {
        if (isHostPortFailure(error) && error.error.category === 'conflict') {
          quarantineConflict();
          return;
        }
        status.textContent = surfaceMessages.lifecycleFailed;
        lifecycleChanging = false;
        shell.inert = false;
        shell.removeAttribute('aria-busy');
        for (const button of lifecycleButtons) button.disabled = false;
      });
    };
    root.querySelector<HTMLButtonElement>('[data-studio-publish]')
      ?.addEventListener('click', () => changeLifecycle('published'));
    root.querySelector<HTMLButtonElement>('[data-studio-unpublish]')
      ?.addEventListener('click', () => changeLifecycle('draft'));
    status.textContent = surfaceMessages.ready;
    window.addEventListener('beforeunload', dispose, { once: true });
    window.addEventListener('pagehide', dispose, { once: true });
  } catch {
    status.textContent = surfaceMessages.openFailed;
  }
}

interface CompositionSurfaceMessages {
  changeRefused: string;
  conflict: string;
  lifecycleFailed: string;
  openFailed: string;
  publishing: string;
  ready: string;
  saveFailed: string;
  saved: string;
  unpublishing: string;
}

function compositionSurfaceMessages(root: HTMLElement): CompositionSurfaceMessages {
  const messages = {
    changeRefused: root.dataset.messageChangeRefused,
    conflict: root.dataset.messageConflict,
    lifecycleFailed: root.dataset.messageLifecycleFailed,
    openFailed: root.dataset.messageOpenFailed,
    publishing: root.dataset.messagePublishing,
    ready: root.dataset.messageReady,
    saveFailed: root.dataset.messageSaveFailed,
    saved: root.dataset.messageSaved,
    unpublishing: root.dataset.messageUnpublishing,
  };
  if (Object.values(messages).some((message) => message === undefined || message === '')) {
    throw new Error('The Studio composition surface message catalogue is incomplete.');
  }
  return messages as CompositionSurfaceMessages;
}

async function openHostSession(boot: BootDocument): Promise<OpenSessionDocument> {
  const response = await fetch(boot.endpoints.session, {
    body: JSON.stringify({ mode: 'blueprint', resourceId: boot.artifact.id, resourceKind: 'blueprint' }),
    credentials: 'same-origin',
    headers: { 'content-type': 'application/json', 'X-CSRF-Token': boot.csrf },
    method: 'POST',
  });
  if (!response.ok) throw new Error('Studio session opening was refused.');
  const value = await response.json() as OpenSessionDocument;
  if (value.protocolVersion !== STUDIO_WIRE_PROTOCOL_VERSION) throw new Error('Studio protocol version mismatch.');
  return value;
}

function studioConfiguration(boot: BootDocument, opened: OpenSessionDocument): StudioConfiguration {
  const operations = new Set(opened.hostCapabilities.filter((capability) => capability.startsWith('studio.operation/')));
  return {
    actor: boot.actor,
    artifacts: {
      blueprint: boot.artifact,
      model: boot.document.model,
      theme: boot.document.dependencyLock.theme,
    },
    blocks: boot.document.dependencyLock.blocks,
    composite: 'single',
    contractVersion: '0.1-draft',
    displayPreferences: { calendar: 'gregory', hourCycle: 'h23', numberingSystem: 'latn' },
    features: {
      clipboardMediaUpload: operations.has('studio.operation/media.authorize-upload'),
      collaboration: false,
      customInspectors: false,
      executablePlugins: false,
      externalMediaImport: operations.has('studio.operation/media.import-external'),
      offlineRecovery: ['store', 'load', 'discard'].every((operation) => operations.has(`studio.operation/recovery.${operation}`)),
    },
    hostCapabilities: fullCapabilities(opened),
    limits: {
      maxChildrenPerSlot: 100,
      maxCommandBatch: 100,
      maxContributionsPerPlugin: 1000,
      maxDepth: 32,
      maxExtensionBytes: 262144,
      maxHistoryEntries: 100,
      maxLocaleBytes: 262144,
      maxMediaBatch: 100,
      maxMediaUploadBytes: 52428800,
      maxNodes: 5000,
      maxPluginCount: 100,
      maxPreviewBytes: 1048576,
      maxPreviewRequestsPerMinute: 60,
      maxPropertyBytes: 65536,
      maxRichTextBytes: 1048576,
      maxRichTextDepth: 32,
      maxSlotsPerNode: 32,
    },
    locale: {
      direction: boot.locale.direction,
      fallbacks: boot.locale.fallbacks,
      requested: boot.locale.requested,
      resolved: boot.locale.resolved,
      timeZone: boot.locale.timezone,
    },
    mode: 'blueprint',
    permissions: opened.permissions,
    plugins: [],
    protocolVersion: opened.protocolVersion,
    preview: {
      allowApproximateRenderer: false,
      enabled: opened.preview !== undefined && operations.has('studio.operation/preview.render'),
      initialViewport: 'expanded',
      sameOriginRequired: true,
    },
    resourceContext: {
      key: opened.resourceContextKey,
      resource: { id: boot.artifact.id, type: 'kumwe.app/content-blueprint' },
      revision: boot.artifact.revision,
      scopes: [{ id: boot.site, kind: 'kumwe.app/site' }],
      surface: 'kumwe.app/administrator',
    },
    sessionGeneration: opened.sessionGeneration,
    sessionId: `studio-session-${crypto.randomUUID()}`,
    sessionState: boot.status === 'draft' ? 'editable' : 'read-only',
  };
}

function fullCapabilities(opened: OpenSessionDocument): HostCapabilities {
  const grouped = new Map<QualifiedName, QualifiedName[]>();
  for (const capability of opened.hostCapabilities) {
    const match = /^studio\.operation\/([a-z0-9-]+)\./.exec(capability);
    if (match?.[1] === undefined) continue;
    const id = `studio.port/${match[1]}` as QualifiedName;
    grouped.set(id, [...(grouped.get(id) ?? []), capability]);
  }
  return {
    capabilities: [],
    contractVersion: '0.1-draft',
    host: { generation: opened.sessionGeneration, id: 'kumwe.app/studio-host', version: '2.0.0' },
    kind: 'host-capabilities',
    ports: [...grouped.entries()].sort(([left], [right]) => left.localeCompare(right)).map(([id, operations]) => ({
      id,
      operations: operations.sort(),
      version: '1.0.0',
    })),
    protocolVersions: [opened.protocolVersion],
  };
}

function identifierFactories(): { requestId(operationId: string): string; idempotencyKey(operationId: string): string } {
  let request = 0;
  let mutation = 0;
  return {
    requestId: () => `requests/browser-${++request}-${crypto.randomUUID()}`,
    idempotencyKey: () => `operations/browser-${++mutation}-${crypto.randomUUID()}`,
  };
}

async function loadMessages(
  localization: ReturnType<typeof createStudioHttpHostAdapter>['localization'],
  boot: BootDocument,
  opened: OpenSessionDocument,
  ids: ReturnType<typeof identifierFactories>,
): Promise<StudioMessageOverrides> {
  if (localization === undefined) return {};
  const context: HostRequestContext = {
    locale: boot.locale.resolved,
    operationId: 'studio.operation/localization.messages',
    protocolVersion: opened.protocolVersion,
    requestId: ids.requestId('studio.operation/localization.messages'),
    resourceContextKey: opened.resourceContextKey,
    sessionGeneration: opened.sessionGeneration,
  };
  const result = await localization.messages(boot.locale.resolved, ['studio.shell' as QualifiedName], context);
  return Object.fromEntries(Object.entries(result.value).map(([key, defaultMessage]) => [key, { defaultMessage }])) as StudioMessageOverrides;
}

function insertCommand(
  shell: KumweStudioElement,
  handle: StudioHostSessionHandle,
  detail: StudioInsertRequestDetail,
  sessionGeneration: string,
): InsertNodeCommand {
  const properties = isCoreLayoutBlockType(detail.definition.type)
    ? coreLayoutInitialProperties(detail.definition.type)
    : {};
  const slots = Object.fromEntries(detail.definition.slots.map(({ id }) => [id, []]));
  let position = shell.document?.roots.length ?? 0;
  if (detail.parentId !== null && detail.slot !== undefined) {
    position = findNode(shell.document?.roots ?? [], detail.parentId)?.slots[detail.slot]?.length ?? 0;
  }
  return {
    artifactId: handle.session.document.id,
    baseStateVersion: shell.stateVersion,
    contractVersion: '0.1-draft',
    expectedRevision: handle.revision,
    id: `commands/insert-${crypto.randomUUID()}`,
    kind: 'command',
    payload: {
      destination: {
        ...(detail.parentId === null ? {} : { parentNodeId: detail.parentId }),
        position,
        ...(detail.slot === undefined ? {} : { slot: detail.slot }),
      },
      node: {
        authoring: { mode: isCoreLayoutBlockType(detail.definition.type) ? 'structural' : 'content' },
        bindings: {},
        id: `nodes/${crypto.randomUUID()}`,
        properties,
        slots,
        type: detail.definition.type,
        version: detail.definition.version,
      },
    },
    sessionGeneration,
    type: 'studio.command/insert-node',
  };
}

function findNode(nodes: readonly BlueprintDocument['roots'][number][], id: string): BlueprintDocument['roots'][number] | undefined {
  for (const node of nodes) {
    if (node.id === id) return node;
    for (const children of Object.values(node.slots)) {
      const found = findNode(children, id);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}

function previewBinding(
  frame: HTMLIFrameElement,
  metadata: StudioPreviewSessionMetadata,
  opened: OpenSessionDocument,
  previewPort: NonNullable<ReturnType<typeof createStudioHttpHostAdapter>['preview']>,
  handle: StudioHostSessionHandle,
  shell: KumweStudioElement,
  save: () => Promise<void>,
): { binding: StudioPreviewBinding; dispose(): void } {
  if (new URL(metadata.origin).origin !== window.location.origin) {
    throw new Error('The Studio preview origin is not the current application origin.');
  }
  const bridge = localPreviewBridge(metadata.origin);
  let activeFrame = frame;
  let documentSequence = 0;
  let documentTail: Promise<void> = Promise.resolve();
  let navigationGeneration = 0;
  let loaded: PreviewRenderedPayload | undefined;
  let stagingFrame: HTMLIFrameElement | undefined;
  let geometry = observePreviewGeometry(activeFrame, shell);
  let disposed = false;
  const attempts = new Set<PreviewRenderAttempt>();
  let loadedAttempt: PreviewRenderAttempt | undefined;
  const cancelAttempt = (attempt: PreviewRenderAttempt): Promise<void> => {
    if (attempt.cancellation !== undefined) return attempt.cancellation;
    const cancellation = previewPort.cancel(attempt.digest, previewContext(opened, 'cancel'))
      .then(() => undefined);
    attempt.cancellation = cancellation;
    return cancellation;
  };
  const navigate = (
    rendered: PreviewRenderedPayload,
    signal: AbortSignal,
    generation: number,
  ): Promise<void> => {
    const pending = documentTail.then(async () => {
      throwIfAborted(signal);
      if (disposed || generation !== navigationGeneration) throw abortError();
      const previewUrl = new URL(metadata.documentPath, metadata.origin);
      if (previewUrl.origin !== window.location.origin) {
        throw new Error('The Studio preview document URL is not same-origin.');
      }
      const sequence = documentSequence;
      documentSequence += 1;
      previewUrl.search = new URLSearchParams({
        channel: metadata.channelId,
        context: opened.resourceContextKey,
        generation: opened.sessionGeneration,
        render: rendered.requestId,
        sequence: String(sequence),
        source: metadata.sourceId,
      }).toString();
      const candidate = createStagingPreviewFrame(activeFrame);
      stagingFrame = candidate;
      if (activeFrame.parentElement === null) {
        throw new Error('The Studio preview frame is no longer attached.');
      }
      // Put the candidate in its final DOM position before loading its single-use document URL.
      // Moving an already-loaded iframe makes browsers navigate it again, which would replay the
      // claimed sequence and replace the validated preview with the fail-closed refusal document.
      activeFrame.after(candidate);
      try {
        // Once a document sequence is sent, wait for that claim to settle even when superseded.
        // The candidate stays hidden, so stale/refused HTML can never become the visual preview.
        await loadPreviewFrame(candidate, previewUrl, rendered, signal);
        throwIfAborted(signal);
        if (disposed || generation !== navigationGeneration) throw abortError();
        geometry.dispose();
        activeFrame.remove();
        candidate.slot = 'preview';
        candidate.dataset.studioPreview = '';
        activeFrame = candidate;
        stagingFrame = undefined;
        geometry = observePreviewGeometry(activeFrame, shell);
        candidate.hidden = false;
        geometry.refreshAfterLayout();
      } catch (error) {
        candidate.remove();
        if (stagingFrame === candidate) stagingFrame = undefined;
        throw error;
      }
    });
    documentTail = pending.then(() => undefined, () => undefined);
    return pending;
  };
  const client = new PreviewClient({
    channelId: metadata.channelId,
    sessionGeneration: opened.sessionGeneration,
    source: bridge.clientSource,
    target: bridge.hostTarget,
    targetOrigin: metadata.origin,
  });
  const host = new PreviewHost({
    channelId: metadata.channelId,
    renderer: 'core.renderer/layout',
    sessionGeneration: opened.sessionGeneration,
    source: bridge.hostSource,
    target: bridge.clientTarget,
    targetOrigin: metadata.origin,
    viewports: ['compact', 'medium', 'expanded'],
    measure: async (markers, signal) => {
      const current = loaded;
      if (
        current === undefined
        || activeFrame.hidden
        || activeFrame.contentWindow === null
        || new URLSearchParams(activeFrame.contentWindow.location.search).get('render') !== current.requestId
        || markers.some((marker) => !current.markers.includes(marker))
      ) {
        throw new Error('The Studio preview measurement does not belong to the current rendered draft.');
      }
      return measurePreviewFrame(activeFrame, markers, signal);
    },
    render: async (payload, signal) => {
      const sameDigestPredecessors = [...attempts].filter(({ digest }) => digest === payload.draftDigest);
      if (sameDigestPredecessors.length > 0) {
        await Promise.all(sameDigestPredecessors.map(cancelAttempt));
        for (const predecessor of sameDigestPredecessors) {
          attempts.delete(predecessor);
          if (loadedAttempt === predecessor) loadedAttempt = undefined;
        }
        throwIfAborted(signal);
      }
      const generation = ++navigationGeneration;
      const attempt: PreviewRenderAttempt = { accepted: false, digest: payload.draftDigest };
      attempts.add(attempt);
      loaded = undefined;
      activeFrame.hidden = true;
      let aborted = signal.aborted;
      const cancel = (): Promise<void> => cancelAttempt(attempt);
      const onAbort = (): void => {
        aborted = true;
        void cancel().catch(() => undefined);
      };
      signal.addEventListener('abort', onAbort, { once: true });
      try {
        const result = await previewPort.render(payload, previewContext(opened, 'render'));
        if (aborted || signal.aborted) {
          throw abortError();
        }
        await navigate(result.value, signal, generation);
        throwIfAborted(signal);
        loaded = result.value;
        attempt.accepted = true;
        loadedAttempt = attempt;
        return result.value;
      } catch (error) {
        await cancel();
        attempts.delete(attempt);
        throw error;
      } finally {
        signal.removeEventListener('abort', onAbort);
      }
    },
  });
  host.onDispose(({ draftDigest }) => {
    const digest = draftDigest ?? loaded?.draftDigest;
    const attempt = loadedAttempt !== undefined && (digest === undefined || loadedAttempt.digest === digest)
      ? loadedAttempt
      : [...attempts].find((candidate) => candidate.accepted && candidate.digest === digest);
    loaded = undefined;
    if (attempt !== undefined) {
      if (loadedAttempt === attempt) loadedAttempt = undefined;
      void cancelAttempt(attempt).catch(() => undefined).finally(() => attempts.delete(attempt));
    }
  });
  host.onSelect(({ nodeId, reveal }) => {
    if (loaded === undefined || reveal !== true) return;
    const marker = Object.entries(loaded.markerMap).find(([, id]) => id === nodeId)?.[0];
    if (marker === undefined) return;
    previewMarkerElement(activeFrame, marker)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    geometry.refreshAfterLayout();
  });
  host.onViewport(({ height, viewport, width: requestedWidth }) => {
    const widths: Record<string, number> = { compact: 360, medium: 768, expanded: 1440 };
    const width = requestedWidth ?? (viewport === undefined ? undefined : widths[viewport]);
    if (width !== undefined) {
      activeFrame.style.inlineSize = `${String(width)}px`;
      if (stagingFrame !== undefined) stagingFrame.style.inlineSize = `${String(width)}px`;
    }
    if (height !== undefined) {
      activeFrame.style.blockSize = `${String(height)}px`;
      if (stagingFrame !== undefined) stagingFrame.style.blockSize = `${String(height)}px`;
    }
    geometry.refreshAfterLayout();
  });
  host.announce();
  return {
    binding: {
      client,
      stage: async (draft, { signal }) => {
        await save();
        await shell.updateComplete;
        throwIfAborted(signal);
        if (handle.session.dirty) {
          throw new Error('The preview draft is not the current accepted artifact revision.');
        }
        if (draft.revision !== handle.revision) {
          throw new Error('The preview intent was superseded by the accepted artifact revision.');
        }
        return {
          artifactId: draft.id,
          draftDigest: await computePreviewDraftDigest(draft, { subtle: crypto.subtle }),
          draftRevision: draft.revision,
        };
      },
    },
    dispose: () => {
      if (disposed) return;
      disposed = true;
      navigationGeneration += 1;
      geometry.dispose();
      activeFrame.hidden = true;
      stagingFrame?.remove();
      stagingFrame = undefined;
      loaded = undefined;
      loadedAttempt = undefined;
      for (const attempt of attempts) void cancelAttempt(attempt).catch(() => undefined);
      attempts.clear();
      host.teardown('studio.preview/session-ended');
    },
  };
}

interface PreviewRenderAttempt {
  accepted: boolean;
  cancellation?: Promise<void>;
  digest: string;
}

function createStagingPreviewFrame(active: HTMLIFrameElement): HTMLIFrameElement {
  const candidate = document.createElement('iframe');
  const omitted = new Set(['data-studio-preview', 'hidden', 'slot', 'src']);
  for (const attribute of active.attributes) {
    if (!omitted.has(attribute.name)) candidate.setAttribute(attribute.name, attribute.value);
  }
  candidate.hidden = true;
  return candidate;
}

/**
 * Coalesce volatile same-origin layout signals into the Studio shell's explicit remeasurement seam.
 */
function observePreviewGeometry(
  frame: HTMLIFrameElement,
  shell: KumweStudioElement,
): { dispose(): void; refreshAfterLayout(): void } {
  let disposed = false;
  let animationFrame: number | undefined;
  let settlementFrame: number | undefined;
  let innerDispose = (): void => undefined;
  const refresh = (): void => {
    if (disposed || animationFrame !== undefined) return;
    animationFrame = window.requestAnimationFrame(() => {
      animationFrame = undefined;
      if (!disposed) shell.refreshPreviewGeometry();
    });
  };
  const refreshAfterLayout = (): void => {
    refresh();
    if (disposed || settlementFrame !== undefined) return;
    settlementFrame = window.requestAnimationFrame(() => {
      settlementFrame = undefined;
      refresh();
    });
  };
  const attachInner = (): void => {
    innerDispose();
    try {
      const previewWindow = frame.contentWindow;
      const previewDocument = frame.contentDocument;
      if (
        previewWindow === null
        || previewDocument === null
        || previewWindow.location.origin !== window.location.origin
      ) return;
      const observer = new ResizeObserver(refreshAfterLayout);
      if (previewDocument.documentElement !== null) observer.observe(previewDocument.documentElement);
      if (previewDocument.body !== null) observer.observe(previewDocument.body);
      const viewport = previewWindow.visualViewport;
      previewWindow.addEventListener('resize', refreshAfterLayout, { passive: true });
      previewWindow.addEventListener('scroll', refreshAfterLayout, { passive: true });
      previewDocument.addEventListener('load', refreshAfterLayout, true);
      viewport?.addEventListener('resize', refreshAfterLayout, { passive: true });
      viewport?.addEventListener('scroll', refreshAfterLayout, { passive: true });
      void previewDocument.fonts?.ready.then(refreshAfterLayout, () => undefined);
      innerDispose = (): void => {
        observer.disconnect();
        previewWindow.removeEventListener('resize', refreshAfterLayout);
        previewWindow.removeEventListener('scroll', refreshAfterLayout);
        previewDocument.removeEventListener('load', refreshAfterLayout, true);
        viewport?.removeEventListener('resize', refreshAfterLayout);
        viewport?.removeEventListener('scroll', refreshAfterLayout);
      };
      refreshAfterLayout();
    } catch {
      // The load validator owns refusal. A redirected foreign document is never observed or measured.
    }
  };
  const frameObserver = new ResizeObserver(refreshAfterLayout);
  frameObserver.observe(frame);
  frame.addEventListener('load', attachInner);
  window.addEventListener('resize', refreshAfterLayout, { passive: true });
  window.addEventListener('scroll', refreshAfterLayout, { passive: true });
  window.visualViewport?.addEventListener('resize', refreshAfterLayout, { passive: true });
  window.visualViewport?.addEventListener('scroll', refreshAfterLayout, { passive: true });
  attachInner();

  return {
    dispose: () => {
      if (disposed) return;
      disposed = true;
      innerDispose();
      frameObserver.disconnect();
      frame.removeEventListener('load', attachInner);
      window.removeEventListener('resize', refreshAfterLayout);
      window.removeEventListener('scroll', refreshAfterLayout);
      window.visualViewport?.removeEventListener('resize', refreshAfterLayout);
      window.visualViewport?.removeEventListener('scroll', refreshAfterLayout);
      if (animationFrame !== undefined) window.cancelAnimationFrame(animationFrame);
      if (settlementFrame !== undefined) window.cancelAnimationFrame(settlementFrame);
    },
    refreshAfterLayout,
  };
}

function previewContext(opened: OpenSessionDocument, operation: 'cancel' | 'render'): HostRequestContext {
  return {
    operationId: `studio.operation/preview.${operation}`,
    protocolVersion: opened.protocolVersion,
    requestId: `requests/preview-${operation}-${crypto.randomUUID()}`,
    resourceContextKey: opened.resourceContextKey,
    sessionGeneration: opened.sessionGeneration,
  };
}

async function loadPreviewFrame(
  frame: HTMLIFrameElement,
  url: URL,
  rendered: PreviewRenderedPayload,
  signal: AbortSignal,
): Promise<void> {
  await new Promise<void>((resolve, reject) => {
    let settled = false;
    const finish = (error?: Error): void => {
      if (settled) return;
      settled = true;
      window.clearTimeout(timeout);
      frame.removeEventListener('load', onLoad);
      if (error === undefined) resolve();
      else reject(error);
    };
    const onLoad = (): void => {
      try {
        throwIfAborted(signal);
        const document = frame.contentDocument;
        const location = frame.contentWindow?.location;
        if (
          document === null
          || location === undefined
          || location.origin !== window.location.origin
          || location.pathname !== url.pathname
          || new URLSearchParams(location.search).get('render') !== rendered.requestId
          || document.contentType !== 'text/html'
          || document.querySelector('[data-kis-surface="core.administrator.content-editor"]') === null
        ) {
          throw new Error('The Studio preview document did not load its same-origin HTML contract.');
        }
        const actual = Array.from(document.querySelectorAll<HTMLElement>('[data-studio-preview-marker]'))
          .map((element) => element.getAttribute('data-studio-preview-marker'));
        if (
          actual.some((marker): marker is null => marker === null)
          || actual.length !== rendered.markers.length
          || rendered.markers.some((marker) => !actual.includes(marker))
        ) {
          throw new Error('The Studio preview marker inventory does not match the rendered response.');
        }
        finish();
      } catch (error) {
        finish(error instanceof Error ? error : new Error('The Studio preview document was invalid.'));
      }
    };
    const timeout = window.setTimeout(
      () => finish(new Error('The Studio preview document did not finish loading.')),
      10_000,
    );
    frame.addEventListener('load', onLoad);
    frame.src = url.toString();
  });
}

export async function measurePreviewFrame(
  frame: HTMLIFrameElement,
  markers: StableId[],
  signal: AbortSignal,
): Promise<PreviewMeasurement> {
  throwIfAborted(signal);
  const document = frame.contentDocument;
  const previewWindow = frame.contentWindow;
  if (document === null || previewWindow === null || previewWindow.location.origin !== window.location.origin) {
    throw new Error('The Studio preview frame is not available for same-origin measurement.');
  }
  const requested = new Set(markers);
  const rects = Object.create(null) as Record<StableId, PreviewMarkerRect[]>;
  const elements = document.querySelectorAll<HTMLElement>('[data-studio-preview-marker]');
  if (elements.length > 100_000) throw new Error('The Studio preview marker inventory is too large.');
  for (const element of elements) {
    const marker = element.getAttribute('data-studio-preview-marker');
    if (marker === null || !requested.has(marker)) continue;
    const fragments = element.getClientRects();
    if (fragments.length > 1_000) throw new Error('A Studio preview marker has too many rectangles.');
    for (const rect of fragments) {
      const member = {
        height: cssExtent(rect.height),
        width: cssExtent(rect.width),
        x: cssCoordinate(rect.x),
        y: cssCoordinate(rect.y),
      };
      (rects[marker] ??= []).push(member);
    }
  }
  throwIfAborted(signal);
  return {
    rects,
    viewport: {
      devicePixelRatio: positiveRatio(previewWindow.devicePixelRatio),
      height: cssExtent(previewWindow.innerHeight),
      scrollX: cssCoordinate(previewWindow.scrollX),
      scrollY: cssCoordinate(previewWindow.scrollY),
      width: cssExtent(previewWindow.innerWidth),
    },
  };
}

function previewMarkerElement(frame: HTMLIFrameElement, marker: string): HTMLElement | undefined {
  for (const element of frame.contentDocument?.querySelectorAll<HTMLElement>('[data-studio-preview-marker]') ?? []) {
    if (element.getAttribute('data-studio-preview-marker') === marker) return element;
  }
  return undefined;
}

function cssCoordinate(value: number): number {
  if (!Number.isFinite(value) || Math.abs(value) > 100_000_000) {
    throw new Error('A Studio preview coordinate is outside the protocol bounds.');
  }
  return value;
}

function cssExtent(value: number): number {
  if (!Number.isFinite(value) || value < 0 || value > 100_000_000) {
    throw new Error('A Studio preview extent is outside the protocol bounds.');
  }
  return value;
}

function positiveRatio(value: number): number {
  if (!Number.isFinite(value) || value <= 0 || value > 100) {
    throw new Error('The Studio preview pixel ratio is outside the protocol bounds.');
  }
  return value;
}

function throwIfAborted(signal: AbortSignal): void {
  if (signal.aborted) throw abortError();
}

function abortError(): DOMException {
  return new DOMException('The Studio preview operation was aborted.', 'AbortError');
}

function localPreviewBridge(origin: string): {
  clientSource: PreviewMessageSource;
  clientTarget: PreviewMessageTarget;
  hostSource: PreviewMessageSource;
  hostTarget: PreviewMessageTarget;
} {
  const clientSource = new LocalMessageSource();
  const hostSource = new LocalMessageSource();
  const clientTarget: PreviewMessageTarget = { postMessage: (data) => clientSource.emit({ data, origin, source: hostTarget }) };
  const hostTarget: PreviewMessageTarget = { postMessage: (data) => hostSource.emit({ data, origin, source: clientTarget }) };
  return { clientSource, clientTarget, hostSource, hostTarget };
}

class LocalMessageSource implements PreviewMessageSource {
  private readonly listeners = new Set<PreviewMessageListener>();
  addEventListener(_type: 'message', listener: PreviewMessageListener): void { this.listeners.add(listener); }
  removeEventListener(_type: 'message', listener: PreviewMessageListener): void { this.listeners.delete(listener); }
  emit(event: PreviewMessageEvent): void { queueMicrotask(() => this.listeners.forEach((listener) => listener(event))); }
}
