# Announcements example

The compact schema-3 component: a shell contribution, two site-owned entity definitions with a workflow,
a package-owned bounded field type with its safe presenter, an injected application service, and one
portable migration. It is the smallest complete component the demonstration installs, and the fixture the
field-presentation and contribution-summary proofs read.

Install it with the other shipped examples through `bin/kumwe demo:install-examples`, or build, sign and
install it by hand as described in `docs/extensions.md`. The declarations live only in `kumwe.json`; the
provider registers the service and binds the presenter and the administrator route to the declarations
the signed manifest carries.
