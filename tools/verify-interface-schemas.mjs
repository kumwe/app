import { readFileSync } from 'node:fs';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const read = (path) => JSON.parse(readFileSync(new URL(`../${path}`, import.meta.url), 'utf8'));
const clone = (value) => structuredClone(value);
const ajv = new Ajv2020({ allErrors: true, strict: true, strictTypes: false });
addFormats(ajv);

const surfaceSchema = read('docs/interface-standard/schemas/surface-declaration.schema.json');
const preferenceSchema = read('docs/interface-standard/schemas/presentation-preference.schema.json');
const surfaceExample = read('docs/interface-standard/examples/extension-surface.json');
const preferenceExample = read('docs/interface-standard/examples/presentation-preference.json');
const surface = ajv.compile(surfaceSchema);
const preference = ajv.compile(preferenceSchema);

const requireValid = (name, validate, document) => {
  if (!validate(document)) {
    throw new Error(`${name} must be valid: ${ajv.errorsText(validate.errors, { separator: '\n' })}`);
  }
};
const requireInvalid = (name, validate, document) => {
  if (validate(document)) throw new Error(`${name} must be rejected.`);
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
requireInvalid('duplicate responsive declaration', surface, duplicateResponsive);

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

process.stdout.write('KIS JSON Schemas verified: 2 examples and 12 adversarial documents.\n');
