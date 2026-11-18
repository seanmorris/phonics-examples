import smNoSaccadeStyle from 'sm-no-saccade-style';

/**
 * Paths held to the house style. Everything else in the repository is ignored for now: a game joins the gate by
 * being listed here once `npx eslint --no-ignore <its paths>` is clean.
 */
const linted = [
	'eslint.config.mjs'
	, 'games/terrain-sandbox/**'
	, 'scripts/sync-terrain.mjs'
	, 'tests/terrain-*.test.mjs'
	, 'tests/browser/terrain-sandbox.spec.mjs'
	, 'games/rpsc/**'
	, 'scripts/generate-rpsc-assets.mjs'
	, 'tests/rpsc-*.test.mjs'
	, 'tests/browser/rpsc.spec.mjs'
];

const ignores = [
	'**/*.{js,mjs,cjs}'
	, ...linted.map(path => `!${path}`)
	// Generated: the private-deck bundle is minified third-party code plus one source file of Poker's.
	, 'games/*/browser/mental-poker.mjs'
	, 'games/*/dist/**'
	, 'site/dist/**'
	, '.worktrees/**'
];

const rules = {
	'sm-no-saccade-style/max-line-length': ['error', {max: 120, tabWidth: 4}]
	, 'sm-no-saccade-style/allman-tabs': ['error', {maxLineLength: 120}]
	, 'sm-no-saccade-style/parameter-layout': ['error', {max: 120}]
	, 'sm-no-saccade-style/object-layout': ['error', {max: 120}]
};

export default [{ignores}, ...smNoSaccadeStyle.configs.recommended, {rules}];
