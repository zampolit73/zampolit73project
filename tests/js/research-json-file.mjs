import assert from 'node:assert/strict';
import { isResearchJsonFile, MAX_RESEARCH_JSON_BYTES } from '../../resources/js/lib/researchJsonFile.js';

// Regression: the repair path once accidentally used /\\.json$/ which rejected
// every normal .json file, even though the preview accepted it.
assert.equal(isResearchJsonFile({ name: 'inn-7715641372.json', size: 6201 }), true);
assert.equal(isResearchJsonFile({ name: 'inn-9727130961.JSON', size: 9120 }), true);
assert.equal(isResearchJsonFile({ name: 'inn-7715641372.json', size: MAX_RESEARCH_JSON_BYTES }), true);
assert.equal(isResearchJsonFile({ name: 'inn-7715641372.json', size: MAX_RESEARCH_JSON_BYTES + 1 }), false);
assert.equal(isResearchJsonFile({ name: 'inn-7715641372.json', size: 0 }), false);
assert.equal(isResearchJsonFile({ name: 'inn-7715641372.json.txt', size: 109 }), false);
assert.equal(isResearchJsonFile({ name: 'inn-7715641372\\xjson', size: 109 }), false);
assert.equal(isResearchJsonFile(null), false);
console.log('Research JSON filename and file-size validation: OK');
