export type InterfaceShell = 'administrator' | 'portal';

export interface InterfaceLandingSurface {
  id: string;
  shell: InterfaceShell;
  path: string;
  heading: string;
  purpose: string;
}

/**
 * Stable core landing routes that form the minimum interface-regression inventory.
 *
 * Extension-owned routes are exercised by their own conformance fixtures. A new core navigation
 * destination belongs here in the same change that registers it, keeping visual evidence and route
 * coverage reviewable as one manifest rather than as repeated test code.
 */
export const interfaceLandingSurfaces = [
  {
    id: 'administrator.dashboard',
    shell: 'administrator',
    path: '/administrator',
    heading: 'Good work starts with a clear view.',
    purpose: 'Orient administrators and expose the primary publishing actions.',
  },
  {
    id: 'administrator.content',
    shell: 'administrator',
    path: '/administrator/content',
    heading: 'Content',
    purpose: 'Find, review and publish content.',
  },
  {
    id: 'administrator.content-create',
    shell: 'administrator',
    path: '/administrator/content/new',
    heading: 'Create content',
    purpose: 'Create one content item through its graphical model.',
  },
  {
    id: 'administrator.media',
    shell: 'administrator',
    path: '/administrator/media',
    heading: 'Media library',
    purpose: 'Find and upload reusable site media.',
  },
  {
    id: 'administrator.business-workspaces',
    shell: 'administrator',
    path: '/administrator/business',
    heading: 'Business records',
    purpose: 'Discover generated operational record workspaces.',
  },
  {
    id: 'administrator.business-reports',
    shell: 'administrator',
    path: '/administrator/reports',
    heading: 'Business report',
    purpose: 'Run policy-filtered reports and manage verified exports.',
  },
  {
    id: 'administrator.content-models',
    shell: 'administrator',
    path: '/administrator/content-models',
    heading: 'Content models',
    purpose: 'Model content fields and publishing workflows.',
  },
  {
    id: 'administrator.business-definitions',
    shell: 'administrator',
    path: '/administrator/business-definitions',
    heading: 'Business definitions',
    purpose: 'Model operational entities and their delivery contract.',
  },
  {
    id: 'administrator.schema-plans',
    shell: 'administrator',
    path: '/administrator/business-schema-plans',
    heading: 'Business schema plans',
    purpose: 'Inspect and control generated relational storage changes.',
  },
  {
    id: 'administrator.navigation',
    shell: 'administrator',
    path: '/administrator/navigation',
    heading: 'Menus and navigation',
    purpose: 'Manage the public navigation tree.',
  },
  {
    id: 'administrator.access',
    shell: 'administrator',
    path: '/administrator/access',
    heading: 'Users, groups and permissions',
    purpose: 'Manage people, roles, grants and delivery credentials.',
  },
  {
    id: 'administrator.business-security',
    shell: 'administrator',
    path: '/administrator/business-security',
    heading: 'Business Security',
    purpose: 'Govern business authority, approvals and step-up assurance.',
  },
  {
    id: 'administrator.extensions',
    shell: 'administrator',
    path: '/administrator/extensions',
    heading: 'Extensions',
    purpose: 'Operate the verified extension and theme lifecycle.',
  },
  {
    id: 'administrator.automation',
    shell: 'administrator',
    path: '/administrator/automation',
    heading: 'Automation',
    purpose: 'Schedule work and inspect background execution.',
  },
  {
    id: 'administrator.settings',
    shell: 'administrator',
    path: '/administrator/settings',
    heading: 'Site settings',
    purpose: 'Manage public identity, presentation and site defaults.',
  },
  {
    id: 'portal.overview',
    shell: 'portal',
    path: '/portal',
    heading: 'Welcome to Kumwe Portal',
    purpose: 'Orient portal members within their authorized workspace.',
  },
  {
    id: 'portal.business-records',
    shell: 'portal',
    path: '/portal/business',
    heading: 'Business records',
    purpose: 'Discover the business record workspaces available to the member.',
  },
  {
    id: 'portal.business-reports',
    shell: 'portal',
    path: '/portal/reports',
    heading: 'Business report',
    purpose: 'Run reports disclosed to the current portal scope.',
  },
  {
    id: 'portal.approvals',
    shell: 'portal',
    path: '/portal/approvals',
    heading: 'Approval inbox',
    purpose: 'Review maker-checker requests in the member scope.',
  },
  {
    id: 'portal.security',
    shell: 'portal',
    path: '/portal/security',
    heading: 'Two-step verification',
    purpose: 'Manage authenticator and recovery verification.',
  },
] as const satisfies readonly InterfaceLandingSurface[];
