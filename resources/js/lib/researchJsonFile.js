// Browser-only file gate shared by preview and repair: one rule for both paths.
export const MAX_RESEARCH_JSON_BYTES = 256 * 1024;

export function isResearchJsonFile(file) {
    return Boolean(file
        && typeof file.name === 'string'
        && /\.json$/i.test(file.name)
        && Number.isFinite(file.size)
        && file.size > 0
        && file.size <= MAX_RESEARCH_JSON_BYTES);
}
