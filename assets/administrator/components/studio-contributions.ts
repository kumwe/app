import {
  ContributionRuntime,
  CORE_LAYOUT_THEME_CONTROLS,
  type ExtensionContributions,
  type StudioCompositionContribution,
} from '@kumwe/studio-core';
import type {
  BlockDefinition,
  LockedArtifactReference,
  QualifiedName,
  ThemeDocument,
  ThemeDesignControl,
  ThemeRecipe,
} from '@kumwe/studio-protocol';

export function activateStudioContributions(): { runtime: ContributionRuntime } {
  return { runtime: new ContributionRuntime({ generation: 'composition-runtime-r0' }) };
}

export function activateHostContributions(
  runtime: ContributionRuntime,
  documents: readonly StudioCompositionContribution[],
  trustedOwners: Readonly<Record<string, string>>,
): void {
  const owners = new Map<string, { owner: StudioCompositionContribution['owner']; contributions: ExtensionContributions }>();
  for (const document of documents) {
    const identity = document.kind === 'block-definition' ? document.type : document.id;
    const trustedOwner = trustedOwners[`${document.kind} ${identity}`];
    if (trustedOwner === undefined) continue;
    const key = `${trustedOwner}\u0000${document.owner.id}@${document.owner.version}`;
    const entry = owners.get(key) ?? { owner: document.owner, contributions: { blocks: [] } };
    if (document.kind === 'block-definition') entry.contributions.blocks.push(document);
    else if (document.kind === 'pattern') (entry.contributions.patterns ??= []).push(document);
    else if (document.kind === 'field-adapter') (entry.contributions.fieldAdapters ??= []).push(document);
    else if (document.kind === 'inspector') (entry.contributions.inspectors ??= []).push(document);
    else if (document.kind === 'design-vocabulary') (entry.contributions.designVocabularies ??= []).push(document);
    else if (document.kind === 'migration') (entry.contributions.migrations ??= []).push(document);
    owners.set(key, entry);
  }
  let generation = 0;
  for (const { owner: contributionOwner, contributions: contributionSet } of owners.values()) {
    generation += 1;
    runtime.activate(contributionOwner, contributionSet, { generation: `composition-runtime-r${generation}` });
  }
}

export function coreStudioTheme(
  blocks: readonly BlockDefinition[],
  trustedRenderers: Readonly<Record<string, string>>,
  reference: LockedArtifactReference,
): ThemeDocument {
  const renderers = [...new Set(blocks.map(({ type }) => trustedRenderers[type]))]
    .filter((renderer): renderer is string => renderer !== undefined)
    .sort();
  return {
    blockSupport: blocks.map(({ type }) => ({
      renderer: trustedRenderers[type] as QualifiedName,
      type,
      versions: '^1.0.0',
    })),
    contractVersion: '0.1-draft',
    designControls: layoutDesignControls(),
    id: reference.id as QualifiedName,
    kind: 'theme',
    label: { key: 'kumwe.app/administrator-theme', defaultMessage: 'Administrator' },
    owner: { id: reference.id as QualifiedName, version: reference.version },
    recipes: layoutRecipes(),
    renderers: renderers.map((id) => ({
      exactPreview: true,
      id: id as QualifiedName,
      surfaces: ['web', 'preview'],
      version: '1.0.0',
    })),
    revision: reference.revision,
    version: reference.version,
    viewports: [
      { base: true, id: 'compact', label: { key: 'studio.viewport/compact', defaultMessage: 'Compact' }, order: 0, previewWidth: 360 },
      { base: false, id: 'medium', label: { key: 'studio.viewport/medium', defaultMessage: 'Medium' }, order: 1, previewWidth: 768 },
      { base: false, id: 'expanded', label: { key: 'studio.viewport/expanded', defaultMessage: 'Expanded' }, order: 2, previewWidth: 1440 },
    ],
  };
}

function layoutDesignControls(): ThemeDesignControl[] {
  return [
    designControl(CORE_LAYOUT_THEME_CONTROLS.alignment, 'enum', 'Alignment', [
      ['center', 'Centre'], ['end', 'End'], ['start', 'Start'], ['stretch', 'Stretch'],
    ]),
    designControl(CORE_LAYOUT_THEME_CONTROLS.collapse, 'enum', 'Responsive collapse', [
      ['preserve', 'Preserve'], ['stack', 'Stack'], ['wrap', 'Wrap'],
    ]),
    designControl(CORE_LAYOUT_THEME_CONTROLS.direction, 'enum', 'Direction', [
      ['block', 'Vertical'], ['inline', 'Horizontal'],
    ]),
    designControl(CORE_LAYOUT_THEME_CONTROLS.spacing, 'spacing-role', 'Spacing', [
      ['comfortable', 'Comfortable'], ['compact', 'Compact'], ['none', 'None'], ['spacious', 'Spacious'],
    ]),
    designControl(CORE_LAYOUT_THEME_CONTROLS.visibility, 'enum', 'Visibility', [
      ['hidden', 'Hidden'], ['visible', 'Visible'],
    ]),
  ];
}

function designControl(
  id: string,
  kind: ThemeDesignControl['kind'],
  label: string,
  choices: readonly (readonly [string, string])[],
): ThemeDesignControl {
  return {
    choices: choices.map(([choice, choiceLabel]) => ({
      id: choice,
      label: { key: `core.composition/${id}-${choice}`, defaultMessage: choiceLabel },
    })),
    id,
    kind,
    label: { key: `core.composition/${id}`, defaultMessage: label },
  };
}

function layoutRecipes(): ThemeRecipe[] {
  return [{
    blockType: 'studio.core/grid',
    designValues: {
      [CORE_LAYOUT_THEME_CONTROLS.alignment]: 'stretch',
      [CORE_LAYOUT_THEME_CONTROLS.collapse]: 'stack',
      [CORE_LAYOUT_THEME_CONTROLS.spacing]: 'comfortable',
      [CORE_LAYOUT_THEME_CONTROLS.visibility]: 'visible',
    },
    id: 'responsive-content-grid',
    label: { key: 'core.composition/responsive-content-grid', defaultMessage: 'Responsive content grid' },
  }];
}
