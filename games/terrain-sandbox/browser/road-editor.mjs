/** Canvas authoring controls over a north-up snapshot from the terrain chart shader. Coordinates are in meters. */
const colors = {route: '#ffd17f', selected: '#fff6db', handle: '#a5efdd', secondary: '#c2cfc5'};

const clamp = (value, low, high) => Math.max(low, Math.min(high, value));

const add = (a, b) => a.map((v, i) => v + b[i]);

const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);

const distance = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);

function handles(road, i)
{
	const points = road.points, last = points.length - 1;
	const prev = points[Math.max(0, i - 1)], next = points[Math.min(last, i + 1)];
	const out = prev.map((v, axis) => (next[axis] - v) / 6);

	return {in: road.handles?.[i]?.in ?? out.map(v => -v), out: road.handles?.[i]?.out ?? out};
}

function span(road, i)
{
	const a = road.points[i], b = road.points[i + 1];

	return [a, add(a, handles(road, i).out), add(b, handles(road, i + 1).in), b];
}

function sample([a, b, c, d], t)
{
	const ab = mix(a, b, t), bc = mix(b, c, t), cd = mix(c, d, t);

	return mix(mix(ab, bc, t), mix(bc, cd, t), t);
}

function markup()
{

	const number = (name, label, min, max) => `<label class="terrain-field">${label}
		<input type="number" step="any" min="${min}" max="${max}" data-road-field="${name}" data-unit="ft"></label>`;

	return `<style>
	[data-tab-panel=roads] .road-tools{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
	[data-tab-panel=roads] .road-tools button{min-height:30px;padding:3px 9px}
	[data-tab-panel=roads] .road-tools button[aria-pressed=true]{color:#183328;background:#e5bf79;border-color:#e5bf79}
	[data-tab-panel=roads] .road-map-wrap{overflow:hidden;border:1px solid #739285;border-radius:7px;background:#142927}
	[data-tab-panel=roads] .road-map-surface{position:relative;overflow:hidden}
	[data-tab-panel=roads] .road-camera{position:absolute;top:0;left:0;width:9px;height:9px;box-sizing:border-box;pointer-events:none;
		border:1.5px solid #10242a;border-radius:50%;background:#8ff5ff;filter:drop-shadow(0 0 1px #07151b)}
	[data-tab-panel=roads] .road-camera::before{content:"";position:absolute;bottom:100%;left:50%;transform:translateX(-50%);
		border:4px solid transparent;border-top:0;border-bottom:8px solid #8ff5ff}
	[data-tab-panel=roads] .road-map{display:block;width:100%;height:clamp(210px,46cqh,400px);touch-action:none}
	[data-tab-panel=roads] .road-map:focus-visible{outline-offset:-3px}
	[data-tab-panel=roads] .road-readout{display:flex;justify-content:space-between;gap:6px;align-items:center;
		border-top:1px solid #527368;padding:5px 9px;color:#d6e8de;font:12px/1.4 ui-monospace,monospace}
	[data-tab-panel=roads] .road-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
	[data-tab-panel=roads] .road-fields input{padding-inline:5px;font-variant-numeric:tabular-nums}
	[data-tab-panel=roads] .road-fields label{font-size:12px}
	[data-tab-panel=roads] .road-profile{width:100%;height:64px;display:block;background:#112720;border-radius:5px}
	[data-tab-panel=roads] .road-message:empty{display:none}
	[data-tab-panel=roads] [data-road-apply]{background:#dfbd79;color:#18271e;border-color:#e9cd94;font-weight:700}
	</style>
	<label class="terrain-field terrain-wide">Road<select data-road-picker><option>New road</option></select></label>
	<div class="road-tools"><button type="button" data-mode="select" aria-pressed="true">Select / pan</button>
	<button type="button" data-mode="add" aria-pressed="false">Place points</button>
	<button type="button" data-map="out" aria-label="Zoom out">−</button>
	<button type="button" data-map="in" aria-label="Zoom in">+</button>
	<button type="button" data-map="fit">Fit road</button><button type="button" data-map="circuit" hidden>Fit circuit</button><button type="button" data-map="world">Whole map</button></div>
	<div class="road-map-wrap"><div class="road-map-surface"><canvas class="road-map" data-road-map tabindex="0" aria-label="Road editing map"
		aria-description="The cyan marker shows the camera and its heading. Drag points or curve handles. Drag empty ground to pan. Use the wheel or plus and minus to zoom."></canvas>
	<span class="road-camera" data-road-camera hidden aria-hidden="true"></span></div>
	<div class="road-readout"><span data-map-scale></span><span data-road-length></span></div></div>
	<p class="terrain-note" data-road-help>Drag a point or its square curve handles. Drag empty ground to pan.</p>
	<div class="road-tools"><label>Control point <select data-road-point aria-label="Control point"></select></label>
	<button type="button" data-point-action="insert">Split curve</button>
	<button type="button" data-point-action="remove">Remove point</button></div>
	<div class="road-fields">${number(
		'x'
		, 'X (ft)'
		, -1e6
		, 1e6
	)}${number(
		'z'
		, 'Z (ft)'
		, -1e6
		, 1e6
	)}${number(
		'y'
		, 'Elevation (ft)'
		, -1e6
		, 1e6
	)}</div>
	<div class="road-tools"><button type="button" data-point-action="ground">Set to ground</button>
	<label class="terrain-field">Bank (%) <input style="max-width:7em" data-road-field="bank" type="number" step="any" min="-35" max="35"></label></div>
	<details><summary>Curve handles</summary><div class="terrain-grid">
	<label class="terrain-field">Handle<select data-road-side><option value="out">Outgoing</option><option value="in">Incoming</option></select></label>
	<p class="terrain-note">Offsets are measured from the selected point. A handle’s height changes the vertical grade.</p>
	<div class="road-fields">${number(
		'dx'
		, 'ΔX (ft)'
		, -1e6
		, 1e6
	)}${number(
		'dz'
		, 'ΔZ (ft)'
		, -1e6
		, 1e6
	)}${number(
		'dy'
		, 'Δ height (ft)'
		, -1e6
		, 1e6
	)}</div>
	<div class="road-tools"><button type="button" data-point-action="auto">Use automatic handle</button></div></div></details>
	<div><h2>Elevation profile</h2><canvas class="road-profile" data-road-profile role="img" aria-label="Road elevation profile"></canvas>
	<p class="terrain-note" data-road-grade></p></div>
	<details><summary>Road dimensions</summary><div class="terrain-grid"><div class="road-fields">
	${number(
		'width'
		, 'Width (ft)'
		, .5
		, 1024
	)}${number(
		'shoulder'
		, 'Shoulder (ft)'
		, .01
		, 1024
	)}${number(
		'crown'
		, 'Crown (ft)'
		, -1024
		, 1024
	)}
	</div></div></details>
	<div class="terrain-row"><button type="button" data-road-circuit>Close loop and create circuit</button><button type="button" data-road-apply>Apply road</button>
	<button type="button" data-road-revert>Discard edits</button></div>
	<p class="terrain-note road-message" role="status" aria-live="polite" data-road-message></p>`;
}

export function activate({canvas, module, session})
{
	const frame = canvas.closest('.terrain-sandbox'), panel = frame.querySelector('.terrain-panel');
	const tab = frame.querySelector('[data-tab-panel="roads"]');
	tab.querySelector('[data-road-workspace]').innerHTML = markup();

	const {FOOT, distanceLabel, configureUnits, setFieldValue, metricValue} = module.terrainUnits;
	configureUnits(tab);

	const el = selector => tab.querySelector(selector);
	const map = el('[data-road-map]'), ctx = map.getContext('2d');
	const marker = el('[data-road-camera]');
	const profile = el('[data-road-profile]'), chart = document.createElement('canvas');
	const picker = el('[data-road-picker]'), pointPicker = el('[data-road-point]'), sidePicker = el('[data-road-side]');

	const fields = Object.fromEntries(
		[...tab.querySelectorAll('[data-road-field]')].map(i => [i.dataset.roadField, i])
	);

	const dispose = new AbortController(), {signal} = dispose;
	let state, roads = [], current, chartView, view, active = false, drag = null, requestTimer, paintFrame;
	const overlays = new Map();
	let overlayView, cameraPose, wholeMap = false;
	let selected = -1, selectedSpan = -1, splitAt = .5, side = 'out', mode = 'select';

	const api = () => session.terrainSandbox;

	const send = (op, extra = {}) => api()?.action({
		type: 'road-editor'
		, op
		, generation: state?.generation
		, revision: state?.revision
		, id: state?.id
		, ...extra
	});

	const width = () => Math.max(1, map.clientWidth), height = () => Math.max(1, map.clientHeight);

	const scale = () => width() / view.span;

	const project = ([x,,z]) => [width()/2 + (x-view.x)*scale(), height()/2 + (z-view.z)*scale()];

	const world = ([x,y]) => [view.x + (x-width()/2)/scale(), view.z + (y-height()/2)/scale()];

	const location = event => {
		const rect = map.getBoundingClientRect();

		return [event.clientX - rect.left, event.clientY - rect.top];
	};

	function resize(target)
	{
		const ratio = Math.min(2, window.devicePixelRatio || 1);

		const w = Math.max(1, Math.round(target.clientWidth*ratio));
		const h = Math.max(1, Math.round(target.clientHeight*ratio));

		if(target.width !== w || target.height !== h)
		{
			target.width = w;
			target.height = h;
		}

		const c = target.getContext('2d');
		c.setTransform(ratio, 0, 0, ratio, 0, 0);

		return c;
	}

	function placeCamera()
	{
		if(!active || !view || !cameraPose) return;

		const [x, z, heading] = cameraPose;
		const [px, py] = project([x, 0, z]);
		marker.hidden = px < 0 || py < 0 || px > width() || py > height();

		if(!marker.hidden)
		marker.style.transform = `translate(${px}px, ${py}px) translate(-50%, -50%) rotate(${heading}rad)`;
	}

	// Span is the width of the editor in meters. At full zoom both axes must fit the editable square.

	const maxSpan = () => state.extent * Math.max(1, width()/height());

	function setView(x, z, meters)
	{
		const extent = state.extent, max = maxSpan();
		const span = clamp(meters, Math.min(8, extent), max);
		const tall = span*height()/width();
		const xRoom = Math.max(0, (extent-span)/2), zRoom = Math.max(0, (extent-tall)/2);
		view = {x: clamp(x, -xRoom, xRoom), z: clamp(z, -zRoom, zRoom), span};
		wholeMap = span >= max;
		placeCamera();

		// The GPU capture is square. Keep it entirely on the editable terrain, then draw
		// it using its own world bounds so the handles and the image agree at every zoom.
		const capture = Math.min(extent, Math.max(span, tall));
		const room = (extent-capture)/2;
		const request = {x: clamp(view.x, -room, room), z: clamp(view.z, -room, room), span: capture};
		schedulePaint();
		clearTimeout(requestTimer);
		requestTimer = setTimeout(() => { if(active) send('view', request); }, 100);
	}

	function fit(routes = [current])
	{
		const corners = [];
		let roadWidth = 0;

		for(const road of routes)
		{
			corners.push(...road.points);
			roadWidth = Math.max(roadWidth, road.width);

			for(let i = 0; i+1<road.points.length; i++) corners.push(...span(road, i).slice(1, 3));
		}

		if(!corners.length)
		{
			setView(...state.camera, Math.min(800, state.extent));

			return;
		}

		const xs = corners.map(p => p[0]), zs = corners.map(p => p[2]);
		const l = Math.min(...xs), r = Math.max(...xs), t = Math.min(...zs), b = Math.max(...zs);
		setView((l+r)/2, (t+b)/2, Math.max(40, 1.35*(r-l+roadWidth), 1.35*(b-t+roadWidth)*width()/height()));
	}

	const circuitRoads = () => roads.filter(record => record.id<0).map(record => record.road);

	function zoom(factor, at = [width()/2,height()/2])
	{
		if(!view) return;

		const [x,z] = world(at);
		const next = clamp(view.span*factor, Math.min(8, state.extent), maxSpan());
		setView(x+(view.x-x)*next/view.span, z+(view.z-z)*next/view.span, next);
	}

	function schedulePaint()
	{
		if(!active || paintFrame) return;

		paintFrame = requestAnimationFrame(() => { paintFrame = 0; paint(); });
	}

	function drawCurve(road, color, lineWidth, chosen = -1, brush = ctx)
	{
		if(road.points.length < 2) return;

		brush.strokeStyle = color;
		brush.lineWidth = lineWidth;
		brush.lineCap = 'round';
		brush.lineJoin = 'round';
		brush.beginPath();
		const start = chosen < 0 ? 0 : chosen, end = chosen < 0 ? road.points.length-1 : chosen+1;
		brush.moveTo(...project(road.points[start]));

		for(let i = start; i<end; i++)
		{
			const [,a,b,c] = span(road, i);
			brush.bezierCurveTo(...project(a), ...project(b), ...project(c));
		}

		brush.stroke();
	}

	// Rasterize the visible bounds of each unselected road once at the editor's current scale.

	function drawOverlay(record)
	{
		let entry = overlays.get(record.id);

		if(entry?.key !== record.geometry)
		{
			const corners = record.road.points.slice();

			for(let i = 0; i+1<record.road.points.length; i++) corners.push(...span(record.road, i).slice(1, 3));
			const pixels = corners.map(project);
			const left = Math.max(0, Math.floor(Math.min(...pixels.map(p => p[0]))-2));
			const top = Math.max(0, Math.floor(Math.min(...pixels.map(p => p[1]))-2));
			const right = Math.min(width(), Math.ceil(Math.max(...pixels.map(p => p[0]))+2));
			const bottom = Math.min(height(), Math.ceil(Math.max(...pixels.map(p => p[1]))+2));
			entry = {key: record.geometry, left, top, w: right-left, h: bottom-top};

			if(entry.w>0 && entry.h>0)
			{
				const sheet = document.createElement('canvas'), ratio = map.width/width();
				sheet.width = Math.ceil(entry.w*ratio);
				sheet.height = Math.ceil(entry.h*ratio);
				const ink = sheet.getContext('2d');

				ink.setTransform(
					sheet.width/entry.w
					, 0
					, 0
					, sheet.height/entry.h
					, -left*sheet.width/entry.w
					, -top*sheet.height/entry.h
				);

				drawCurve(record.road, '#e8ecdaae', 2, -1, ink);
				entry.sheet = sheet;
			}

			overlays.set(record.id, entry);
		}

		if(entry.sheet) ctx.drawImage(entry.sheet, entry.left, entry.top, entry.w, entry.h);
	}

	function handlePoints()
	{
		const p = current?.points[selected];

		if(!p) return [];

		const pair = handles(current, selected), result = [];

		if(selected > 0) result.push(['in',project(add(p, pair.in))]);

		if(selected+1 < current.points.length) result.push(['out',project(add(p, pair.out))]);

		return result;
	}

	function paint()
	{
		if(!view || !current || !active) return;

		resize(map);
		const w = width(), h = height();
		ctx.fillStyle = '#142927';
		ctx.fillRect(0, 0, w, h);

		if(chartView)
		{
			const [x,y] = project([chartView.x-chartView.span/2,0,chartView.z-chartView.span/2]);
			const size = chartView.span*scale();
			ctx.drawImage(chart, x, y, size, size);
			ctx.fillStyle = '#13252330';
			ctx.fillRect(0, 0, w, h);
		}

		const spacing = 10 ** Math.floor(Math.log10(view.span/FOOT/4));
		const grid = spacing * FOOT * (view.span/(spacing*FOOT) > 12 ? 5 : 1);
		const [left,top] = world([0,0]);
		ctx.beginPath();

		for(let x = Math.ceil(left/grid)*grid; x<=left+view.span; x += grid)
		{
			const px = project([x,0,0])[0];
			ctx.moveTo(px, 0);
			ctx.lineTo(px, h);
		}

		for(let z = Math.ceil(top/grid)*grid; z<=top+h/scale(); z += grid)
		{
			const py = project([0,0,z])[1];
			ctx.moveTo(0, py);
			ctx.lineTo(w, py);
		}

		ctx.lineWidth = 1;
		ctx.strokeStyle = '#e1efe529';
		ctx.stroke();

		const base = [view.x,view.z,view.span,w,h,map.width,map.height].join(',');

		if(base !== overlayView)
		{
			overlays.clear();
			overlayView = base;
		}

		for(const record of roads) if(record.id !== state.id) drawOverlay(record);
		drawCurve(current, '#182a2ce6', clamp(current.width*scale(), 4, 100));
		drawCurve(current, colors.route, 2);

		if(selectedSpan >= 0 && selectedSpan+1 < current.points.length)
		drawCurve(current, colors.selected, 3, selectedSpan);

		const p = current.points[selected];

		if(p)
		for(const [key,pos] of handlePoints())
		{
			ctx.strokeStyle = colors.handle;
			ctx.lineWidth = 1;
			ctx.setLineDash([3,3]);
			ctx.beginPath();
			ctx.moveTo(...project(p));
			ctx.lineTo(...pos);
			ctx.stroke();

			ctx.setLineDash([]);
			ctx.fillStyle = key===side ? '#a5efdd' : '#204d47';
			ctx.fillRect(pos[0]-3.5, pos[1]-3.5, 7, 7);
			ctx.strokeRect(pos[0]-3.5, pos[1]-3.5, 7, 7);
		}

		for(const [i,point] of current.points.entries())
		{
			const [x,y] = project(point);
			ctx.beginPath();
			ctx.arc(x, y, i===selected ? 5.5 : 4, 0, Math.PI*2);
			ctx.fillStyle = i===selected ? colors.selected : '#413728';
			ctx.strokeStyle = colors.route;
			ctx.fill();
			ctx.lineWidth = 1.5;
			ctx.stroke();

			if(i===selected)
			{
				ctx.font = 'bold 12px system-ui';
				ctx.fillStyle = '#12231f';
				ctx.fillRect(x+7, y-15, 28, 17);
				ctx.fillStyle = colors.selected;
				ctx.fillText(String(i+1), x+12, y-2);
			}
		}

		ctx.fillStyle = '#10231ed9';
		ctx.fillRect(6, 6, 25, 35);
		ctx.fillStyle = '#e5f2e9';
		ctx.font = 'bold 11px system-ui';
		ctx.fillText('N', 14, 20);

		ctx.beginPath();
		ctx.moveTo(18, 23);
		ctx.lineTo(13.5, 34);
		ctx.lineTo(18, 31);
		ctx.lineTo(22.5, 34);
		ctx.fill();

		const meters = grid/(grid*scale()>w*.24 ? 2 : 1), bar = meters*scale();
		ctx.fillStyle = '#112720d9';
		ctx.fillRect(6, h-13, bar+12, 9);
		ctx.fillStyle = '#dfecdf';
		ctx.fillRect(12, h-9, bar, 2);
		el('[data-map-scale]').textContent = Math.round(meters/FOOT) + ' ft';
	}

	function inspect()
	{
		if(!current) return;

		const points = current.points, p = points[selected], n = points.length;
		selectedSpan = clamp(selectedSpan, -1, n-2);
		pointPicker.replaceChildren(...points.map((_, i) => new Option(String(i+1), String(i))));
		pointPicker.selectedIndex = selected;

		if(selected===0) side = 'out';
		else if(selected===n-1) side = 'in';

		sidePicker.value = side;
		sidePicker.options[0].disabled = !p || selected===n-1;
		sidePicker.options[1].disabled = !p || selected===0;

		const vector = p && n>1 ? handles(current, selected)[side] : [];
		const values = {
			x: p?.[0]
			, y: p?.[1]
			, z: p?.[2]
			, bank: p ? 100*(current.banks?.[selected]??0) : null

			, dx: vector[0]
			, dy: vector[1]
			, dz: vector[2]
			, width: current.width
			, shoulder: current.shoulder
			, crown: current.crown
		};

		for(const [key,input] of Object.entries(fields))
		{
			input.disabled = values[key]==null;

			if(input!==document.activeElement) setFieldValue(input, values[key]);
		}

		el('[data-point-action=insert]').disabled = selectedSpan<0 || n>=128;
		el('[data-point-action=remove]').disabled = !p;
		el('[data-point-action=ground]').disabled = !p;
		el('[data-point-action=auto]').disabled = !current.handles?.[selected]?.[side];

		const busy = api()?.state?.preparing;
		el('[data-road-circuit]').disabled = busy || state.proposed || n<3;
		el('[data-road-apply]').disabled = busy || (!state.changed && !state.proposed) || n<2;
		el('[data-road-revert]').disabled = busy || !state.changed;
		const c = resize(profile), w = profile.clientWidth, h = profile.clientHeight;
		c.clearRect(0, 0, w, h);
		let run = 0, maxGrade = 0, previous = points[0];
		const samples = n ? [[0,previous[1]]] : [];

		for(let i = 0; i<n-1; i++)
		{
			const curve = span(current, i);

			for(let j = 1; j<=32; j++)
			{
				const next = sample(curve, j/32), step = Math.hypot(next[0]-previous[0], next[2]-previous[2]);

				if(step>.00001) maxGrade = Math.max(maxGrade, Math.abs(next[1]-previous[1])/step);

				run += step;
				samples.push([run,next[1],i]);
				previous = next;
			}
		}

		el('[data-road-length]').textContent = distanceLabel(run, 2);
		el('[data-road-grade]').textContent = n>1
			? 'Maximum sampled grade ' + (100*maxGrade).toFixed(1) + '%'
			: 'Place two points to inspect the grade.';

		if(samples.length>1)
		{
			const ys = samples.map(v => v[1]), low = Math.min(...ys), high = Math.max(...ys);

			const y = v => high===low ? h/2 : h-8-(v-low)*(h-16)/(high-low);

			for(let i = 1; i<samples.length; i++)
			{
				const [x1,y1] = samples[i-1],[x2,y2,index] = samples[i];
				c.strokeStyle = index===selectedSpan ? colors.route : '#8cae9e';
				c.lineWidth = 2;
				c.beginPath();
				c.moveTo(4+x1/Math.max(.001, run)*(w-8), y(y1));
				c.lineTo(4+x2/Math.max(.001, run)*(w-8), y(y2));
				c.stroke();
			}

			el(
				'[data-road-grade]'
			).textContent += ' · ' + (low/FOOT).toFixed(1) + ' to ' + (high/FOOT).toFixed(1) + ' ft elevation';

			if(state.fitOnApply) el('[data-road-grade]').textContent += ' · Ground spans are fitted on Apply.';
		}

		schedulePaint();
	}

	function choose(i, segment = Math.min(i, current.points.length-2), t = .5)
	{
		selected = i;
		selectedSpan = segment;
		splitAt = t;
		inspect();
	}

	function hit(position)
	{
		let result = null, best = 10;

		for(const [i,p] of current.points.entries())
		{
			const d = distance(position, project(p));

			if(d<best)
			{
				result = {kind: 'point',index: i};
				best = d;
			}
		}

		for(const [key,p] of handlePoints())
		{
			const d = distance(position, p);

			if(d<best)
			{
				result = {kind: 'handle',index: selected,side: key};
				best = d;
			}
		}

		if(result) return result;

		function nearest(i, [a,b,c,d], start, end)
		{
			for(let axis = 0; axis<2; axis++)
			{
				const coords = [a[axis], b[axis], c[axis], d[axis]];

				if(position[axis] < Math.min(...coords)-best || position[axis] > Math.max(...coords)+best) return;
			}

			// Bound screen-space error for position AND parameter, even for a straight cubic with unequal handles.
			if(distance(b, mix(a, d, 1/3))>.5 || distance(c, mix(a, d, 2/3))>.5)
			{
				const ab = mix(a, b, .5), bc = mix(b, c, .5), cd = mix(c, d, .5);
				const abc = mix(ab, bc, .5), bcd = mix(bc, cd, .5), mid = mix(abc, bcd, .5);
				const half = (start+end)/2;
				nearest(i, [a,ab,abc,mid], start, half);
				nearest(i, [mid,bcd,cd,d], half, end);

				return;
			}

			const dx = d[0]-a[0], dy = d[1]-a[1];
			const t = clamp(((position[0]-a[0])*dx+(position[1]-a[1])*dy)/Math.max(.000001, dx*dx+dy*dy), 0, 1);
			const gap = distance(position, mix(a, d, t));

			if(gap<best)
			{
				result = {kind: 'span',index: i,t: clamp(start+(end-start)*t, .000001, .999999)};
				best = gap;
			}
		}

		for(let i = 0; i+1<current.points.length; i++) nearest(i, span(current, i).map(project), 0, 1);

		return result;
	}

	map.addEventListener('pointerdown', event => {
		if(!current || !view || drag || event.button!==0) return;

		const at = location(event), target = hit(at);
		map.focus({preventScroll: true});
		map.setPointerCapture(event.pointerId);

		if(target?.kind==='point') choose(target.index);

		if(target?.kind==='handle')
		{
			side = target.side;
			inspect();
		}

		drag = {target,at,last: at,id: event.pointerId,view: {...view},original: structuredClone(current),moved: false};
	}, {signal});

	map.addEventListener('pointermove', event => {
		if(!drag || drag.id!==event.pointerId) return;

		const at = location(event), dx = at[0]-drag.at[0], dy = at[1]-drag.at[1];
		drag.last = at;

		if(!drag.moved && Math.hypot(dx, dy)<3) return;

		drag.moved = true;
		const target = drag.target, unit = drag.view.span/width();

		if(target?.kind==='point')
		{
			const p = drag.original.points[selected], half = state.extent/2;
			current.points[selected] = [clamp(p[0]+dx*unit, -half, half),p[1],clamp(p[2]+dy*unit, -half, half)];
		}
		else if(target?.kind==='handle')
		{
			current.handles ??= Array(current.points.length).fill(null);
			current.handles[selected] ??= {};

			const v = handles(drag.original, selected)[side];
			current.handles[selected][side] = [v[0]+dx*unit,v[1],v[2]+dy*unit];
		}
		else setView(drag.view.x-dx*unit, drag.view.z-dy*unit, drag.view.span);

		schedulePaint();
	}, {signal});

	function release(event)
	{
		if(!drag || drag.id!==event.pointerId) return;

		const {target,moved,original} = drag;
		drag = null;

		if(map.hasPointerCapture(event.pointerId)) map.releasePointerCapture(event.pointerId);

		if(event.type==='pointercancel')
		{
			current = original;
			inspect();

			return;
		}

		if(moved && target?.kind==='point')
		{
			const [x,y,z] = current.points[selected];
			send('point', {index: selected,x,y,z});
		}
		else if(moved && target?.kind==='handle')
		send('handle', {index: selected,side,vector: handles(current, selected)[side]});

		else if(!moved && target?.kind==='span')
		{
			if(mode==='add') send('insert', {index: target.index,t: target.t});
			else choose(target.index, target.index, target.t);
		}
		else if(!moved && !target && mode==='add')
		{
			const [x,z] = world(location(event));
			send('add', {x,z});
		}

		inspect();
	}

	map.addEventListener('pointerup', release, {signal});
	map.addEventListener('pointercancel', release, {signal});

	map.addEventListener('wheel', event => {
		if(!view) return;

		event.preventDefault();
		zoom(Math.exp(clamp(event.deltaY*(event.deltaMode===1 ? .05 : .0025), -1, 1)), location(event));
	}, {passive: false,signal});

	map.addEventListener('keydown', event => {
		if(!view) return;

		const shifts = {ArrowLeft: [-1,0],ArrowRight: [1,0],ArrowUp: [0,-1],ArrowDown: [0,1]};

		if(shifts[event.key])
		{
			const [x,z] = shifts[event.key];
			setView(view.x+x*view.span/8, view.z+z*view.span/8, view.span);
		}
		else if(['+','=','-'].includes(event.key)) zoom(event.key==='-' ? 2 : .5);
		else return;

		event.preventDefault();
		event.stopPropagation();
	}, {signal});

	tab.addEventListener('click', event => {
		const button = event.target.closest('button');

		if(!button || !state) return;

		const {map: command,mode: newMode,pointAction} = button.dataset;

		if(newMode)
		{
			mode = newMode;

			for(const item of tab.querySelectorAll('[data-mode]'))
			item.setAttribute('aria-pressed', String(item===button));

			map.style.cursor = mode==='add' ? 'crosshair' : 'grab';
			el('[data-road-help]').textContent = mode==='add'
				? 'Click the road to insert a point. Click open ground to append one at terrain height.'
				: 'Drag a point or its square curve handles. Drag empty ground to pan.';

			return;
		}

		if(command)
		{
			if(command==='fit') fit();
			else if(command==='circuit') fit(circuitRoads());
			else if(command==='world') setView(0, 0, maxSpan());
			else zoom(command==='in' ? .5 : 2);

			return;
		}

		if(pointAction==='insert') send('insert', {index: selectedSpan,t: splitAt});
		else if(pointAction) send(pointAction, {index: selected,side});
		else if(button.hasAttribute('data-road-circuit')) send('circuit');
		else if(button.hasAttribute('data-road-apply')) send('apply');
		else if(button.hasAttribute('data-road-revert')) send('revert');
	}, {signal});

	tab.addEventListener('change', event => {
		const input = event.target;

		if(!state) return;

		if(input===picker)
		{
			if(state.id!==null && state.id>=0 && state.changed && !window.confirm('Discard your edits to this road?'))
			{
				picker.value = String(state.id);

				return;
			}

			send('select', {id: picker.value==='' ? null : Number(picker.value)});

			return;
		}

		if(input===pointPicker)
		{
			choose(pointPicker.selectedIndex);

			return;
		}

		if(input===sidePicker)
		{
			side = sidePicker.value;
			inspect();

			return;
		}

		const key = input.dataset.roadField;

		if(!key || !Number.isFinite(input.valueAsNumber) || !input.reportValidity()) return;

		if(['width','shoulder','crown'].includes(key)) send('section', {key,value: metricValue(input)});
		else if(key==='bank') send('bank', {index: selected,value: metricValue(input)});
		else if(key.startsWith('d'))
		send(
			'handle'
			, {
				index: selected
				, side
				, vertical: key==='dy'
				, vector: [metricValue(fields.dx), metricValue(fields.dy), metricValue(fields.dz)]
			}
		);
		else
		send(
			'point'
			, {
				index: selected
				, vertical: key==='y'
				, x: metricValue(fields.x)
				, y: metricValue(fields.y)
				, z: metricValue(fields.z)
			}
		);
	}, {signal});

	module.terrainRoadEditor = json => {
		const next = JSON.parse(json);
		const replaced = !state || state.generation!==next.generation;
		const switched = replaced || state.id!==next.id;

		if(replaced) chartView = null;

		const count = current?.points.length;
		const hadCircuit = roads.some(record => record.id<0);

		if(next.roads)
		{
			roads = next.roads;
			el('[data-map=circuit]').hidden = !roads.some(record => record.id<0);
			const activeIds = new Set(roads.map(record => record.id));

			for(const id of overlays.keys()) if(!activeIds.has(id)) overlays.delete(id);

			for(const record of roads) record.geometry = JSON.stringify([record.road.points,record.road.handles]);

			picker.replaceChildren(
				new Option('New road', '')
				, ...roads.map(
					({id,road}, i) => new Option(
						(id<0 ? 'Circuit section ' + (-id) : 'Road ' + (i+1)) + ' · ' + road.points.length + ' points'
						, String(id)
					)
				)
			);
		}

		state = next;
		picker.value = next.id===null ? '' : String(next.id);

		if(drag && (switched || !active)) drag = null;

		if(!drag) current = structuredClone(next.road);

		if(switched || count!==current.points.length)
		{
			selected = clamp(next.selection, 0, current.points.length-1);

			if(!current.points.length) selected = -1;

			selectedSpan = Math.min(selected, current.points.length-2);
			splitAt = .5;
		}

		el('[data-road-message]').textContent = next.message;

		if(next.proposed && !hadCircuit) fit(circuitRoads());
		else if(switched || !view) fit();
		else if(next.roads) setView(view.x, view.z, view.span);

		inspect();
	};

	module.terrainRoadCamera = (x, z, heading) => {
		cameraPose = [x, z, heading];
		placeCamera();
	};

	module.terrainRoadCapture = (x, z, meters, size) => {
		if(!active) return;

		chart.width = chart.height = size;
		chart.getContext('2d').drawImage(canvas, 10, canvas.height-10-size, size, size, 0, 0, size, size);
		chartView = {x,z,span: meters};
		map.dataset.chart = 'ready';
		map.dataset.chartSpan = String(meters);
		schedulePaint();
	};

	function visibility()
	{
		const visible = panel.open && !tab.hidden;

		if(active===visible) return;

		active = visible;
		clearTimeout(requestTimer);
		send(visible ? 'open' : 'close');

		if(visible && state && view) setView(view.x, view.z, view.span);
	}

	const observer = new MutationObserver(visibility);
	observer.observe(tab, {attributes: true,attributeFilter: ['hidden']});
	panel.addEventListener('toggle', visibility, {signal});

	const roadTabButton = frame.querySelector('[data-tab=roads]');
	frame.querySelector('[data-open-roads]').addEventListener('click', () => roadTabButton.click(), {signal});

	const resizeObserver = new ResizeObserver(() => {
		if(!active || !current || !view) return;

		setView(view.x, view.z, wholeMap ? maxSpan() : view.span);
		inspect();
	});

	resizeObserver.observe(map);
	resizeObserver.observe(profile);
	visibility();

	return () => {
		dispose.abort();
		observer.disconnect();
		resizeObserver.disconnect();
		clearTimeout(requestTimer);
		cancelAnimationFrame(paintFrame);
		delete module.terrainRoadEditor;
		delete module.terrainRoadCapture;
		delete module.terrainRoadCamera;
	};
}
