/** Editor-visible component markers and the reusable complete-board preset. */
export const componentNames = ['Board', 'Active', 'Queue', 'Input', 'Run'];
export const classes = [
	...componentNames.map(name => ({name: `PhpTris.${name}`, members: []}))
	, {
		name: 'PhpTris.Match'
		, members: componentNames.map(name => ({
			name
			, type: 'class'
			, propertyType: `PhpTris.${name}`
			, value: {}
		}))
	}
];

export const boardProperties = () => [{
	name: 'Components'
	, type: 'class'
	, propertytype: 'PhpTris.Match'
	, value: {}
}];
