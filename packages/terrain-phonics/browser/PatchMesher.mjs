/** Browser batch evaluator for aligned, unedited render samples of the version-one terrain recipe. */
const cells = 32, side = cells + 1, haloSide = side + 2, count = side * side;
const diagonal = .7071067811865476;
const gx = [1,-1,0,0,diagonal,-diagonal,diagonal,-diagonal];
const gz = [0,0,1,-1,diagonal,diagonal,-diagonal,-diagonal];

const fade = t => t*t*t*(t*(t*6-15)+10);

const fadeSlope = t => 30*t*t*(t-1)*(t-1);

function heightSampler(r, permutation)
{
	const p = permutation.concat(permutation);
	const warpX = new Float64Array(3), warpZ = new Float64Array(3);
	const a = new Float64Array(3), b = new Float64Array(3);

	function noise(x, z, gradient)
	{
		const ix = Math.floor(x), iz = Math.floor(z);
		x -= ix;
		z -= iz;

		const left = p[ix & 255] + (iz & 255), right = p[(ix+1) & 255] + (iz & 255);
		const g00 = p[left]&7, g10 = p[right]&7, g01 = p[left+1]&7, g11 = p[right+1]&7;
		const n00 = gx[g00]*x + gz[g00]*z, n10 = gx[g10]*(x-1) + gz[g10]*z;
		const n01 = gx[g01]*x + gz[g01]*(z-1), n11 = gx[g11]*(x-1) + gz[g11]*(z-1);
		const u = fade(x), v = fade(z), lo = n00 + u*(n10-n00), hi = n01 + u*(n11-n01);
		const value = lo + v*(hi-lo);

		if(gradient)
		{
			const du = fadeSlope(x);
			const dx0 = gx[g00] + u*(gx[g10]-gx[g00]) + du*(n10-n00);
			const dx1 = gx[g01] + u*(gx[g11]-gx[g01]) + du*(n11-n01);
			const dz0 = gz[g00] + u*(gz[g10]-gz[g00]), dz1 = gz[g01] + u*(gz[g11]-gz[g01]);
			gradient[0] = value;
			gradient[1] = dx0 + v*(dx1-dx0);
			gradient[2] = dz0 + v*(dz1-dz0) + fadeSlope(z)*(hi-lo);
		}

		return value;
	}

	return (x, z) => {
		let height = 0, sx = 0, sz = 0;

		if(r.heightScale)
		{
			let wx = x, wz = z;
			const wf = r.warpFrequency, wa = r.warpAmplitude, f = r.baseFrequency;

			if(wa)
			{
				wx += wa * noise(x*wf+271.13, z*wf-197.41, warpX);
				wz += wa * noise(x*wf-419.27, z*wf+113.71, warpZ);
			}

			noise(wx*f+13.17, wz*f-9.31, a);
			noise(wx*f*2+71.43, wz*f*2+53.11, b);
			let dx, dz;


			if(r.preset === 'coast')
			{
				const coast = Math.tanh(wx/4500);
				height = .6*a[0] + .35*coast + .15*b[0];

				dx = .6*a[1]*f + .35*(1-coast*coast)/4500 + .3*b[1]*f;
				dz = .6*a[2]*f + .3*b[2]*f;
			}
			else if(r.preset === 'canyon')
			{
				const wall = Math.max(0, Math.min(1, (Math.abs(a[0])-.055)/.006));
				const slope = (a[0]<0 ? -1 : 1)*fadeSlope(wall)/.006;

				height = -.55 + fade(wall) + .15*b[0];
				dx = slope*a[1]*f + .3*b[1]*f;
				dz = slope*a[2]*f + .3*b[2]*f;
			}
			else
			{
				const rounded = Math.sqrt(a[0]*a[0]+.04*.04), slope = -a[0]/rounded;

				height = .35-rounded+.24*b[0];
				dx = slope*a[1]*f + .48*b[1]*f;
				dz = slope*a[2]*f + .48*b[2]*f;
			}

			height *= r.heightScale;
			sx = r.heightScale*(dx*(1+wa*wf*warpX[1]) + dz*wa*wf*warpZ[1]);
			sz = r.heightScale*(dx*wa*wf*warpX[2] + dz*(1+wa*wf*warpZ[2]));

			if(r.terraceSpacing)
			{
				const terrace = height/r.terraceSpacing, level = Math.floor(terrace), t = terrace-level;
				height += r.terraceStrength*(r.terraceSpacing*(level+fade(t))-height);
				const slope = 1-r.terraceStrength+r.terraceStrength*fadeSlope(t);
				sx *= slope;
				sz *= slope;
			}
		}

		const slope = (sx*sx+sz*sz)/(r.slopeScale*r.slopeScale);
		let f = r.baseFrequency*8, amplitude = r.detailAmplitude;

		for(let i = 0; amplitude && i<r.detailOctaves; i++)
		{
			const weight = 1/(1+r.damping*(i+1)*(i+1)*slope);
			height += amplitude*weight*noise(x*f+91.73+i*19.17, z*f-23.49-i*31.29);
			f *= r.lacunarity;
			amplitude *= r.persistence;
		}

		return height;
	};
}

/** Pack the same vertex layout, parent triangle interpolation and LOD error as Scene's incremental builder. */
export function patchMesher(recipe, permutation)
{
	const heightAt = heightSampler(recipe, permutation), half = recipe.worldSize/2;
	const halo = new Float64Array(haloSide*haloSide);

	return (node, parentBytes) => {
		const step = node.size/cells;
		const parent = parentBytes && new Float64Array(parentBytes.buffer, parentBytes.byteOffset, count);
		const parentNormals = parentBytes
			&& new Float32Array(parentBytes.buffer, parentBytes.byteOffset+8*count, 3*count);

		const buffer = new ArrayBuffer(24+count*(8+12+44));
		const bounds = new Float64Array(buffer, 0, 3), heights = new Float64Array(buffer, 24, count);
		const normals = new Float32Array(buffer, 24+count*8, count*3);
		const vertices = new Float32Array(buffer, 24+count*20, count*11);
		const parentX = (node.ix%2)*16, parentZ = (node.iz%2)*16;

		for(let z = -1; z<=side; z++)
		for(let x = -1; x<=side; x++)
		{
			const inner = x>=0 && z>=0 && x<side && z<side;
			const wx = Math.max(-half, Math.min(half, node.x+x*step));
			const wz = Math.max(-half, Math.min(half, node.z+z*step));
			const h = inner && parent && !(x%2) && !(z%2) ? parent[(parentZ+z/2)*side+parentX+x/2] : heightAt(wx, wz);

			halo[(z+1)*haloSide+x+1] = h;

			if(inner) heights[z*side+x] = h;
		}

		let min = Infinity, max = -Infinity, error = 0;

		for(let z = 0; z<side; z++)
		for(let x = 0; x<side; x++)
		{
			const i = z*side+x, j = (z+1)*haloSide+x+1, h = heights[i], v = i*11;
			const wx = node.x+x*step, wz = node.z+z*step;
			const dx = (halo[j+1]-halo[j-1])/(Math.min(half, wx+step)-Math.max(-half, wx-step));
			const dz = (halo[j+haloSide]-halo[j-haloSide])/(Math.min(half, wz+step)-Math.max(-half, wz-step));
			const length = Math.sqrt(1+dx*dx+dz*dz);

			vertices[v] = x*step;
			vertices[v+1] = h;
			vertices[v+2] = z*step;

			normals[i*3] = vertices[v+3] = -dx/length;
			normals[i*3+1] = vertices[v+4] = 1/length;
			normals[i*3+2] = vertices[v+5] = -dz/length;
			vertices[v+7] = -1;
			let coarse = h;

			if(parent)
			{
				const px = parentX+x/2, pz = parentZ+z/2;
				const cx = Math.min(31, Math.floor(px)), cz = Math.min(31, Math.floor(pz));
				const fx = px-cx, fz = pz-cz, k = cz*side+cx, lower = fx+fz<=1;
				const a = lower ? k : k+side+1, b = lower ? k+1 : k+side, c = lower ? k+side : k+1;
				const wb = lower ? fx : 1-fx, wc = lower ? fz : 1-fz, wa = 1-wb-wc;
				coarse = parent[a]+(parent[b]-parent[a])*wb+(parent[c]-parent[a])*wc;

				for(let axis = 0; axis < 3; axis++)
				{
					const normal = wa * parentNormals[a*3+axis] + wb * parentNormals[b*3+axis];
					vertices[v+8+axis] = normal + wc * parentNormals[c*3+axis];
				}
			}
			else for(let axis = 0; axis<3; axis++) vertices[v+8+axis] = normals[i*3+axis];

			vertices[v+6] = coarse;
			min = Math.min(min, h, coarse);
			max = Math.max(max, h, coarse);
			error = Math.max(error, Math.abs(h-coarse));

			if(x>0 && x<cells && z>0 && z<cells)
			{
				const axial = Math.abs(halo[j-1]-2*h+halo[j+1])+Math.abs(halo[j-haloSide]-2*h+halo[j+haloSide]);
				error = Math.max(error, .5*axial+Math.abs(h-halo[j+1]-halo[j+haloSide]+heights[i+side+1]));
			}
		}

		bounds.set([min,max,error]);

		return new Uint8Array(buffer);
	};
}

function siblingGroup(node)
{
	if(node.level === 0) return [node];

	const ix = node.ix - node.ix%2, iz = node.iz - node.iz%2;
	const x = node.x - (node.ix-ix)*node.size, z = node.z - (node.iz-iz)*node.size;
	const nodes = [];

	for(let dz = 0; dz<2; dz++)
	for(let dx = 0; dx<2; dx++)
		nodes.push({
			...node
			, ix: ix+dx
			, iz: iz+dz
			, x: x+dx*node.size
			, z: z+dz*node.size
			, key: [node.level, ix+dx, iz+dz].join(':')
		});

	return nodes;
}

// The verified module is also the worker entry. Only binary render data crosses back to the application.
if(typeof WorkerGlobalScope !== 'undefined' && globalThis instanceof WorkerGlobalScope)
{
	let build;

	globalThis.onmessage = ({data}) => {
		if(data.recipe)
		{
			build = patchMesher(data.recipe, data.permutation);

			return;
		}

		for(const node of siblingGroup(data.node))
		{
			const bytes = build(node, data.parent);
			globalThis.postMessage({group: data.group, key: node.key, bytes}, [bytes.buffer]);
		}
	};
}

export function activate({module})
{
	const sessions = new Map();

	const groupKey = key => {
		const [level,x,z] = key.split(':').map(Number);

		return level === 0 ? 'root' : [level-1,Math.floor(x/2),Math.floor(z/2)].join(':');
	};

	const read = encoded => {
		if(Uint8Array.fromBase64) return Uint8Array.fromBase64(encoded);

		return Uint8Array.from(atob(encoded), c => c.charCodeAt(0));
	};

	const close = path => {
		sessions.get(path)?.worker.terminate();
		sessions.delete(path);
	};

	module.terrainPatchBatch = (action, path, key, payload) => {
		try
		{
			if(action === 'close')
			{
				close(path);

				return '';
			}

			if(action === 'configure')
			{
				close(path);

				if(!module.FS || typeof Worker === 'undefined') return 'unavailable';

				const worker = new Worker(import.meta.url, {type: 'module', name: 'terrain-patch-mesher'});
				const groups = new Map();
				sessions.set(path, {worker, groups});

				worker.onmessage = ({data}) => groups.get(data.group)?.set(data.key, data.bytes);

				worker.onerror = () => { if(sessions.get(path)?.worker === worker) close(path); };

				worker.postMessage(JSON.parse(payload));

				return '';
			}

			const session = sessions.get(path);

			if(!session) return 'unavailable';

			const {groups, worker} = session;
			const id = groupKey(key);

			if(action === 'check') return groups.has(id) ? '' : 'request';

			if(action === 'take')
			{
				const group = groups.get(id);

				if(!group) return 'request';

				const bytes = group.get(key);

				if(!bytes) return '';

				module.FS.writeFile(path, bytes);

				return 'ready';
			}

			const {node, parent} = JSON.parse(payload);
			const bytes = parent ? read(parent) : null;

			if(groups.size >= 8) groups.delete(groups.keys().next().value);

			groups.set(id, new Map());
			worker.postMessage({group: id,node,parent: bytes}, bytes ? [bytes.buffer] : []);

			return '';
		}
		catch
		{
			close(path);

			return 'unavailable';
		}
	};

	return () => {
		for(const path of sessions.keys()) close(path);
		delete module.terrainPatchBatch;
	};
}
