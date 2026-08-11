import { readFileSync } from 'node:fs';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const read = (path) => JSON.parse(readFileSync(new URL(`../${path}`, import.meta.url), 'utf8'));
const clone = (value) => structuredClone(value);
const ajv = new Ajv2020({ allErrors: true, strict: true, strictTypes: false });
addFormats(ajv);
ajv.addKeyword({
  keyword: 'x-kumwe-uniqueBy',
  type: 'array',
  schemaType: 'string',
  errors: false,
  validate(property, values) {
    const seen = new Set();
    for (const value of values) {
      if (typeof value !== 'object' || value === null || Array.isArray(value) || !(property in value)) continue;
      const semanticIdentity = value[property];
      if (seen.has(semanticIdentity)) return false;
      seen.add(semanticIdentity);
    }

    return true;
  },
});
ajv.addKeyword({
  keyword: 'x-kumwe-ownedSurface',
  type: 'object',
  schemaType: 'object',
  errors: false,
  validate(fields, document) {
    const owner = document[fields.owner];
    const surface = document[fields.surface];
    if (typeof owner !== 'string' || typeof surface !== 'string') return true;
    const namespace = owner === 'core' ? 'core' : owner.replace('/', '.');
    const prefix = `${namespace}.`;
    if (!surface.startsWith(prefix)) return false;
    const suffix = surface.slice(prefix.length);

    return /^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/.test(suffix);
  },
});

const surfaceSchema = read('docs/interface-standard/schemas/surface-declaration.schema.json');
const preferenceSchema = read('docs/interface-standard/schemas/presentation-preference.schema.json');
const surfaceExample = read('docs/interface-standard/examples/extension-surface.json');
const preferenceExample = read('docs/interface-standard/examples/presentation-preference.json');
const surface = ajv.compile(surfaceSchema);
const preference = ajv.compile(preferenceSchema);
let validDocuments = 0;
let adversarialDocuments = 0;

const requireValid = (name, validate, document) => {
  if (!validate(document)) {
    throw new Error(`${name} must be valid: ${ajv.errorsText(validate.errors, { separator: '\n' })}`);
  }
  validDocuments += 1;
};
const requireInvalid = (name, validate, document) => {
  if (validate(document)) throw new Error(`${name} must be rejected.`);
  adversarialDocuments += 1;
};

requireValid('surface example', surface, surfaceExample);
requireValid('presentation preference example', preference, preferenceExample);

const tooManyCapabilities = clone(surfaceExample);
tooManyCapabilities.capabilities = Array.from({ length: 65 }, (_, index) => `acme.capability-${index + 1}`);
requireInvalid('65 surface capabilities', surface, tooManyCapabilities);

const noResponsiveContract = clone(surfaceExample);
noResponsiveContract.responsive = [];
requireInvalid('empty responsive contract', surface, noResponsiveContract);

const collapsingEssential = clone(surfaceExample);
collapsingEssential.responsive[0].may_collapse = true;
requireInvalid('collapsing essential element', surface, collapsingEssential);

const executablePurpose = clone(surfaceExample);
executablePurpose.purpose = '<script>alert(1)</script>';
requireInvalid('executable-shaped purpose', surface, executablePurpose);

const paddedPurpose = clone(surfaceExample);
paddedPurpose.purpose = ' Find and inspect a policy-filtered facility inspection. ';
requireInvalid('whitespace-padded purpose', surface, paddedPurpose);

const protocolPurpose = clone(surfaceExample);
protocolPurpose.purpose = 'javascript:openUnsafePresentation()';
requireInvalid('script-protocol purpose', surface, protocolPurpose);

const duplicateCustomization = clone(surfaceExample);
duplicateCustomization.customization.push(clone(duplicateCustomization.customization[0]));
duplicateCustomization.customization.at(-1).scope = 'administrator';
requireInvalid('duplicate customization declaration', surface, duplicateCustomization);

const duplicateResponsive = clone(surfaceExample);
duplicateResponsive.responsive.push(clone(duplicateResponsive.responsive[0]));
duplicateResponsive.responsive.at(-1).priority = 'optional';
duplicateResponsive.responsive.at(-1).may_collapse = true;
requireInvalid('duplicate responsive declaration', surface, duplicateResponsive);

const incompatibleAreaActors = [
  ['administrator area portal actor', 'administrator', 'portal'],
  ['portal area administrator actor', 'portal', 'administrator'],
  ['public area administrator actor', 'public', 'administrator'],
];
for (const [name, area, actor] of incompatibleAreaActors) {
  const document = clone(surfaceExample);
  document.area = area;
  document.actor = actor;
  requireInvalid(name, surface, document);
}

const publicPortalSurface = clone(surfaceExample);
publicPortalSurface.area = 'portal';
publicPortalSurface.actor = 'public';
publicPortalSurface.capabilities = [];
requireValid('public actor in portal area', surface, publicPortalSurface);

const allowedCustomizationScopes = {
  columns: ['administrator', 'role-workspace', 'user'],
  density: ['site', 'administrator', 'role-workspace', 'user'],
  'saved-views': ['administrator', 'role-workspace', 'user'],
  layout: ['site', 'administrator'],
  'theme-mode': ['site', 'user'],
  'dashboard-cards': ['administrator', 'role-workspace', 'user'],
  'landing-workspace': ['administrator', 'role-workspace', 'user'],
  'navigation-shortcuts': ['role-workspace', 'user'],
  'labels-help': ['administrator'],
};
const customizationScopes = ['site', 'administrator', 'role-workspace', 'user'];
for (const [slot, allowedScopes] of Object.entries(allowedCustomizationScopes)) {
  for (const scope of customizationScopes) {
    const document = clone(surfaceExample);
    document.customization = [{ slot, scope }];
    if (allowedScopes.includes(scope)) {
      requireValid(`${slot} at ${scope} scope`, surface, document);
    } else {
      requireInvalid(`${slot} at ${scope} scope`, surface, document);
    }
  }
}

const extensionNamespaceSurface = clone(surfaceExample);
extensionNamespaceSurface.surface = '9ac.me_.2-orders_v1.administrator.catalog';
requireValid('extension-grammar surface namespace', surface, extensionNamespaceSurface);

const legacyOwnerDotSurface = clone(surfaceExample);
legacyOwnerDotSurface.surface = 'a...b.administrator.catalog';
requireValid('legacy owner dots in surface namespace', surface, legacyOwnerDotSurface);

const trailingSurfaceSeparator = clone(surfaceExample);
trailingSurfaceSeparator.surface = 'acme.inspections.catalog.';
requireInvalid('trailing surface identifier separator', surface, trailingSurfaceSeparator);

const invalidPreferenceValue = clone(preferenceExample);
invalidPreferenceValue.value = ['reference', '<script>'];
requireInvalid('unsafe preference value', preference, invalidPreferenceValue);

const invalidPreferenceScope = clone(preferenceExample);
invalidPreferenceScope.scope = 'site';
requireInvalid('slot-incompatible preference scope', preference, invalidPreferenceScope);

const identifiedAdministrator = clone(preferenceExample);
identifiedAdministrator.scope = 'administrator';
requireInvalid('identified global administrator preference', preference, identifiedAdministrator);

const anonymousUser = clone(preferenceExample);
anonymousUser.scope_id = null;
requireInvalid('anonymous user preference', preference, anonymousUser);

const validLandingWorkspace = clone(preferenceExample);
validLandingWorkspace.slot = 'landing-workspace';
validLandingWorkspace.value = 'core.administrator.dashboard';
requireValid('letter-led dotted landing workspace', preference, validLandingWorkspace);

const extensionLandingWorkspace = clone(validLandingWorkspace);
extensionLandingWorkspace.value = '9ac.workspace_name';
requireValid('extension-compatible landing workspace', preference, extensionLandingWorkspace);

const legacyOwnerDotWorkspace = clone(validLandingWorkspace);
legacyOwnerDotWorkspace.value = 'a...b.workspace';
requireValid('legacy owner dots in landing workspace', preference, legacyOwnerDotWorkspace);

const trailingLandingWorkspaceSeparator = clone(validLandingWorkspace);
trailingLandingWorkspaceSeparator.value = 'acme.workspace.';
requireInvalid('trailing landing workspace separator', preference, trailingLandingWorkspaceSeparator);

const extensionOwnerPreference = clone(preferenceExample);
extensionOwnerPreference.owner = '9ac.me_/2-orders_v1';
extensionOwnerPreference.surface = '9ac.me_.2-orders_v1.administrator.catalog';
requireValid('canonical extension owner punctuation', preference, extensionOwnerPreference);

const maximumVendor = `9${'a'.repeat(62)}`;
const maximumPackage = `2${'b'.repeat(62)}`;
const maximumOwnerPreference = clone(preferenceExample);
maximumOwnerPreference.owner = `${maximumVendor}/${maximumPackage}`;
maximumOwnerPreference.surface = `${maximumVendor}.${maximumPackage}.workspace`;
requireValid('63-character extension owner segments', preference, maximumOwnerPreference);

const overlongOwnerPreference = clone(maximumOwnerPreference);
overlongOwnerPreference.owner = `${maximumVendor}a/${maximumPackage}`;
requireInvalid('64-character extension owner segment', preference, overlongOwnerPreference);

const nonCanonicalOwnerPreference = clone(preferenceExample);
nonCanonicalOwnerPreference.owner = 'Acme/Inspections';
requireInvalid('non-canonical extension owner spelling', preference, nonCanonicalOwnerPreference);

const legacyDottedOwners = [
  ['a../b', 'a...b.workspace'],
  ['a./b', 'a..b.workspace'],
  ['a/b.', 'a.b..workspace'],
];
for (const [owner, ownedSurface] of legacyDottedOwners) {
  const document = clone(preferenceExample);
  document.owner = owner;
  document.surface = ownedSurface;
  requireValid(`legacy dotted owner ${owner}`, preference, document);
}

const trailingPreferenceSurfaceSeparator = clone(preferenceExample);
trailingPreferenceSurfaceSeparator.surface = 'acme.inspections.catalog.';
requireInvalid('trailing preference surface separator', preference, trailingPreferenceSurfaceSeparator);

const ambiguousUnownedPreferenceSurface = clone(preferenceExample);
ambiguousUnownedPreferenceSurface.surface = 'acme..inspections.catalog';
requireInvalid('repeated dots outside preference owner namespace', preference, ambiguousUnownedPreferenceSurface);

process.stdout.write(
  `KIS JSON Schemas verified: ${validDocuments} valid and ${adversarialDocuments} adversarial documents.\n`,
);
