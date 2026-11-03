const upper = value => String(value).toUpperCase();

const list = value => Array.isArray(value) ? value.map(upper) : value === undefined ? [] : [upper(value)];

const readName = (bytes, offset) => {
	let result = '';

	for(let index = 0; index < 8; index++)
	{
		const byte = bytes[offset + index];

		if(byte === 0) break;

		if(byte < 32 || byte > 126) throw new Error(`Invalid WAD lump name at directory byte ${offset}`);

		result += String.fromCharCode(byte);
	}

	if(!result) throw new Error(`Empty WAD lump name at directory byte ${offset}`);

	return upper(result);
};

const matches = (criteria, facts) => {
	const archiveTypes = list(criteria.archiveType ?? criteria.archiveTypes);
	const namespaces = list(criteria.mapNamespace ?? criteria.mapNamespaces).map(value => value.toLowerCase());

	if(archiveTypes.length && !archiveTypes.includes(facts.archiveType)) return false;

	if(namespaces.length && !namespaces.includes(facts.mapNamespace)) return false;

	if(list(criteria.mustContain).some(name => !facts.lumps.has(name))) return false;

	if(list(criteria.mustNotContain).some(name => facts.lumps.has(name))) return false;

	if(list(criteria.requiredMaps).some(name => !facts.mapSet.has(name))) return false;

	for(const alternatives of criteria.mustContainAny ?? [])
	if(!list(alternatives).some(name => facts.lumps.has(name))) return false;

	const requiredCapabilities = Array.isArray(criteria.capabilities) ? criteria.capabilities.map(String) : [];

	return requiredCapabilities.every(value => facts.capabilities.has(value));
};

/** Parse enough of a WAD directory to identify its role and compatibility without activating gameplay. */
export function inspectWad(value, {requirement = {criteria: {}, candidates: []}} = {})
{
	const bytes = value instanceof Uint8Array ? value : new Uint8Array(value);

	if(bytes.byteLength < 12) throw new Error('WAD header is truncated');

	const archiveType = new TextDecoder('ascii').decode(bytes.subarray(0, 4));

	if(!['IWAD','PWAD'].includes(archiveType)) throw new Error(`Expected IWAD or PWAD header, got ${archiveType}`);

	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
	const count = view.getUint32(4, true);
	const directory = view.getUint32(8, true);

	if(count > 1_000_000) throw new Error(`Unreasonable WAD lump count ${count}`);

	if(directory > bytes.byteLength || count > Math.floor((bytes.byteLength - directory) / 16))
	throw new Error('WAD directory is outside the archive');

	const entries = [];
	const lumps = new Set;

	for(let index = 0; index < count; index++)
	{
		const entry = directory + index * 16;
		const offset = view.getUint32(entry, true);
		const size = view.getUint32(entry + 4, true);
		const name = readName(bytes, entry + 8);

		if(offset > bytes.byteLength || size > bytes.byteLength - offset)
		throw new Error(`WAD lump ${name} is outside the archive`);

		entries.push({name, size});
		lumps.add(name);
	}

	const maps = [];

	for(let index = 0; index + 3 < entries.length; index++)
	if(entries[index].size === 0
		&& entries[index + 1].name === 'THINGS'
		&& entries[index + 2].name === 'LINEDEFS'
		&& entries[index + 3].name === 'SIDEDEFS'
	)
	maps.push(entries[index].name);

	const episodeMaps = maps.filter(name => /^E\dM\d+$/.test(name));
	const commercialMaps = maps.filter(name => /^MAP\d\d$/.test(name));
	const mapNamespace = episodeMaps.length && !commercialMaps.length
		? 'episode'
		: commercialMaps.length && !episodeMaps.length ? 'commercial' : maps.length ? 'mixed' : 'none';

	const capabilities = new Set([
		'doom-wad'
		, archiveType.toLowerCase()
		, `maps:${mapNamespace}`
		, ...maps.map(name => `map:${name}`)
	]);

	const facts = {archiveType, lumps, maps, mapSet: new Set(maps), mapNamespace, capabilities};

	if(!matches(requirement.criteria ?? {}, facts))
	throw new Error(`WAD does not satisfy requirement ${requirement.id ?? 'unnamed'}`);

	let candidate = '';

	if(requirement.candidates?.length)
	{
		candidate = requirement.candidates.find(value => matches(value.criteria ?? {}, facts))?.id ?? '';

		if(!candidate)
		throw new Error(`WAD does not match an accepted ${requirement.label ?? requirement.id ?? 'archive'} profile`);
	}

	const markers = ['FREEDOOM','FREEDM','GAMECONF','DMENUPIC','DPHOOF','IWADINFO']
		.filter(name => lumps.has(name));

	return {
		candidate
		, profile: {
			format: 'doom-wad'
			, archiveType
			, mapNamespace
			, maps
			, capabilities: [...capabilities].sort()
			, markers
		}
	};
}

export class DoomWadInspector
{

	inspect(bytes, options) { return inspectWad(bytes, options); }
}

/** Register the inspector under the manifest export identity selected by the world. */
export function activate({services, exportId})
{
	services.register('resource-inspector', exportId, () => new DoomWadInspector);

	return () => services.unregister('resource-inspector', exportId);
}
