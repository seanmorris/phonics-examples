/** Transparent navigation symbols over the GPU-rendered terrain chart. */
const headings = ['N','NE','E','SE','S','SW','W','NW'];

export function activate({module, canvas, session})
{
	const {FOOT} = module.terrainUnits;
	const map = document.createElement('canvas');
	map.className = 'terrain-flight-map';
	map.setAttribute('role', 'img');
	map.setAttribute('aria-label', 'Heading-up terrain elevation map');
	map.hidden = true;

	canvas.parentElement.append(map);
	const frame = map.closest('.terrain-sandbox'), ctx = map.getContext('2d');

	function hide()
	{
		if(map.hidden) return;

		map.hidden = true;
		frame.classList.remove('has-flight-map');
	}

	module.drawTerrainMap = data => {
		const chart = data?.map;

		if(!chart)
		{
			hide();

			return;
		}

		const {span, bearing, position, circular} = chart;
		const size = chart.size ?? Math.round(Math.min(184, data.width*.235, data.height*.245));

		if(map.hidden || map.width !== size)
		{
			map.hidden = false;
			map.width = map.height = size;
			frame.style.setProperty('--flight-map-size', size + 'px');
			frame.classList.add('has-flight-map');
		}

		const mid = size/2;
		const c = Math.cos(bearing), s = Math.sin(bearing);
		const scale = size/span;
		const [centerX, centerZ] = position;

		function offset(x, z)
		{
			const dx = (x-centerX)*scale, dz = (z-centerZ)*scale;

			return [c*dx+s*dz, -s*dx+c*dz];
		}

		const edgeDistance = (x, y) => circular ? Math.hypot(x, y) : Math.max(Math.abs(x), Math.abs(y));

		ctx.clearRect(0, 0, size, size);

		if(circular)
		{
			ctx.save();
			ctx.beginPath();
			ctx.arc(mid, mid, mid, 0, 2*Math.PI);
			ctx.clip();
		}

		const state = session.terrainSandbox?.state;
		const circuit = state?.circuit;
		const route = circuit?.preview ? circuit.activePoints : circuit?.active && circuit.points;

		if(state?.recipe?.join && state.mode === 'car' && module.journeyMap?.generation === state.generation)
		{
			ctx.save();
			ctx.translate(mid, mid);
			ctx.transform(c*scale, -s*scale, s*scale, c*scale, 0, 0);
			ctx.translate(-centerX, -centerZ);
			ctx.lineJoin = 'round';
			for(const [width, color] of [[4, '#162a23'], [2, '#ffd272']]) {
				ctx.strokeStyle = color;
				ctx.lineWidth = width/scale;
				ctx.stroke(module.journeyMap.path);
			}
			ctx.restore();
			for(const [x, z] of state.journeyViews ?? []) {
				const [px, py] = offset(x, z);
				if(edgeDistance(px, py) > mid-5) continue;
				ctx.beginPath();
				ctx.moveTo(mid+px, mid+py-4);
				ctx.lineTo(mid+px+4, mid+py);
				ctx.lineTo(mid+px, mid+py+4);
				ctx.lineTo(mid+px-4, mid+py);
				ctx.closePath();
				ctx.fillStyle = '#eff8e6'; ctx.fill();
				ctx.lineWidth = 1; ctx.strokeStyle = '#162a23'; ctx.stroke();
			}
		}

		if(route && chart.showRoute !== false)
		{
			ctx.beginPath();

			for(const [i,[x,z]] of route.entries())
			{
				const [px,py] = offset(x, z);

				if(i) ctx.lineTo(mid+px, mid+py);
				else ctx.moveTo(mid+px, mid+py);
			}

			ctx.closePath();
			ctx.lineJoin = 'round';
			ctx.lineWidth = 4;
			ctx.strokeStyle = '#162a23';
			ctx.stroke();
			ctx.lineWidth = 2;
			ctx.strokeStyle = '#ffd272';
			ctx.stroke();
		}

		for(const [x, z] of chart.mileMarkers ?? [])
		{
			const [dx, dy] = offset(x, z);
			if(edgeDistance(dx, dy) > mid-5) continue;
			ctx.beginPath();
			ctx.arc(mid+dx, mid+dy, 3.5, 0, 2*Math.PI);
			ctx.fillStyle = '#80edaf';
			ctx.fill();
			ctx.lineWidth = 1.5;
			ctx.strokeStyle = '#153a2a';
			ctx.stroke();
		}

		ctx.lineWidth = 1;
		ctx.strokeStyle = '#bad5b5';

		if(circular)
		{
			ctx.beginPath();
			ctx.arc(mid, mid, mid-1.5, 0, 2*Math.PI);
			ctx.lineWidth = 3;
			ctx.strokeStyle = '#26372f';
			ctx.stroke();
			ctx.lineWidth = 1;
			ctx.strokeStyle = '#bad5b5';
			ctx.stroke();
		}
		else ctx.strokeRect(.5, .5, size-1, size-1);

		// Use the longest labeled interval that fits in a quarter of this chart.
		const feet = [10560, 5280, 1320, 500, 100, 50, 10, 1].find(length => length*FOOT <= span/4) ?? 1;
		const label = feet >= 1320 ? (feet/5280) + ' mi' : feet + ' ft';
		const bar = feet*FOOT*scale;
		const headingX = circular ? mid : 11;
		const headingTop = circular ? Math.round(size*.06) : 1;
		const barCenter = circular ? mid : size-6-bar/2;
		const barY = circular ? size-Math.ceil(size*.10) : size-5;
		ctx.font = 'bold 10px monospace';
		const scaleWidth = circular ? Math.max(bar, ctx.measureText(label).width)+10 : bar+10;
		ctx.fillStyle = '#10201edc';
		ctx.fillRect(headingX-10, headingTop, 20, 15);
		ctx.fillRect(barCenter-scaleWidth/2, barY-14, scaleWidth, 18);

		ctx.fillStyle = '#edf8db';
		ctx.textAlign = 'center';
		const octant = Math.round(bearing/(Math.PI/4));
		ctx.fillText(headings[(octant%8+8)%8], headingX, headingTop+11);
		ctx.fillText(label, barCenter, barY-3);
		ctx.fillRect(barCenter-bar/2, barY, bar, 1);
		ctx.fillRect(barCenter-bar/2, barY-2, 1, 3);
		ctx.fillRect(barCenter+bar/2-1, barY-2, 1, 3);

		ctx.lineJoin = 'round';

		for(const objective of chart.markers)
		{
			const [dx,dy] = offset(objective.x, objective.z);
			const fit = Math.min(1, (mid-10)/Math.max(1, edgeDistance(dx, dy)));
			const x = mid+dx*fit, y = mid+dy*fit;
			ctx.beginPath();

			if(objective.kind === 'pickup') ctx.rect(x-4, y-4, 8, 8);
			else ctx.arc(x, y, objective.kind === 'drop' ? 7 : 4, 0, 2*Math.PI);

			ctx.strokeStyle = '#17241b';

			if(objective.kind === 'drop')
			{
				// The ring remains visible around the car at the depot or a box on the same map edge.
				ctx.lineWidth = 4;
				ctx.stroke();
				ctx.lineWidth = 2;
				ctx.strokeStyle = objective.color;
				ctx.stroke();
			}
			else
			{
				ctx.fillStyle = objective.color;
				ctx.lineWidth = 2;
				ctx.fill();
				ctx.stroke();
			}
		}

		for(const [id,pos] of Object.entries(data.targets ?? {}))
		{
			const [x,y] = offset(pos[0], pos[2]);
			const edge = (mid-7)/Math.max(1, edgeDistance(x, y));

			ctx.save();
			ctx.translate(mid+x*Math.min(1, edge), mid+y*Math.min(1, edge));

			ctx.fillStyle = Number(id) === data.range.selected ? '#ff6357' : '#ffdc69';
			ctx.strokeStyle = '#1e1d17';
			ctx.lineWidth = 1.5;
			ctx.beginPath();

			if(edge < 1)
			{
				ctx.rotate(Math.atan2(y, x));

				ctx.moveTo(4, 0);
				ctx.lineTo(-3, -3.5);
				ctx.lineTo(-1, 0);
				ctx.lineTo(-3, 3.5);
			}
			else
			{
				ctx.moveTo(0, -4.5);
				ctx.lineTo(4, 0);
				ctx.lineTo(0, 4.5);
				ctx.lineTo(-4, 0);
			}

			ctx.closePath();
			ctx.fill();
			ctx.stroke();
			ctx.restore();
		}

		ctx.save();
		ctx.translate(mid, mid);

		ctx.beginPath();
		ctx.moveTo(0, -7);
		ctx.lineTo(4.5, 5);
		ctx.lineTo(0, 3);
		ctx.lineTo(-4.5, 5);
		ctx.closePath();
		ctx.fillStyle = '#fffce4';
		ctx.strokeStyle = '#1b2824';

		ctx.lineWidth = 1.5;
		ctx.fill();
		ctx.stroke();
		ctx.restore();

		if(circular) ctx.restore();
	};

	return () => {
		hide();
		delete module.drawTerrainMap;
		map.remove();
	};
}
