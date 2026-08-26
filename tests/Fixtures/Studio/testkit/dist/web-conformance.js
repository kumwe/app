/**
 * Replay every interaction lane from an independent copy of the same initial
 * state. The adapter owns browser automation; this runner owns fixture
 * isolation and deterministic observation comparison.
 */
export async function runAuthoringWebVector(vector, adapter) {
    const pristine = stableJson(vector);
    const lanes = [];
    for (const lane of vector.lanes) {
        const session = await adapter.open(cloneJson(vector.given), cloneJson(lane));
        let observation;
        try {
            for (const action of lane.steps) {
                await session.perform(cloneJson(action));
            }
            observation = cloneJson(await session.observe());
        }
        finally {
            await session.dispose?.();
        }
        const mismatches = compareAuthoringObservation(observation, vector.expect);
        if (stableJson(vector) !== pristine) {
            mismatches.push('adapter mutated the conformance vector');
        }
        lanes.push({
            mismatches,
            name: lane.name,
            observation,
            passed: mismatches.length === 0,
            surface: lane.surface,
        });
    }
    return {
        lanes,
        passed: lanes.every((lane) => lane.passed),
        profile: 'studio.profile/authoring-web',
        vectorId: vector.id,
    };
}
function compareAuthoringObservation(actual, expected) {
    const mismatches = [];
    compareExact(mismatches, 'announcements', actual.announcements, expected.announcements);
    compareExact(mismatches, 'commands', actual.commands, expected.commands);
    compareExact(mismatches, 'dirty', actual.dirty, expected.dirty);
    compareExact(mismatches, 'document', actual.document, expected.document);
    compareExact(mismatches, 'focus', actual.focus, expected.focus);
    compareExact(mismatches, 'selection', actual.selection, expected.selection);
    return mismatches;
}
function compareExact(mismatches, field, actual, expected) {
    if (stableJson(actual) !== stableJson(expected)) {
        mismatches.push(`${field} differs from the vector expectation`);
    }
}
function cloneJson(value) {
    return JSON.parse(JSON.stringify(value));
}
function stableJson(value) {
    return JSON.stringify(sortJson(value)) ?? 'undefined';
}
function sortJson(value) {
    if (Array.isArray(value)) {
        return value.map((member) => sortJson(member));
    }
    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value)
            .sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
            .map(([key, member]) => [key, sortJson(member)]));
    }
    return value;
}
//# sourceMappingURL=web-conformance.js.map