/** Transparent navigation symbols over the GPU-rendered terrain chart. */
const headings=['N','NE','E','SE','S','SW','W','NW'];
export function activate({module, canvas, session})
{
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

		const {span, bearing, position} = chart;
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

		ctx.clearRect(0,0,size,size);
		const state = session.terrainSandbox?.state;
		const circuit = state?.circuit;
		const route = circuit?.preview ? circuit.activePoints : circuit?.active && circuit.points;
		if(route)
		{
			ctx.beginPath();
			for(const [i,[x,z]] of route.entries())
			{
				const [px,py] = offset(x,z);
				if(i) ctx.lineTo(mid+px,mid+py);
				else ctx.moveTo(mid+px,mid+py);
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
		ctx.lineWidth = 1;
		ctx.strokeStyle = '#bad5b5';
		ctx.strokeRect(.5, .5, size-1, size-1);

		const meters = span >= 20000 ? 5000 : span >= 4000 ? 1000 : span >= 1000 ? 250 : span >= 400 ? 100 : 25;
		const label = meters >= 1000 ? (meters/1000) + ' km' : meters + ' m';
		const bar = meters*scale;
		ctx.font = 'bold 10px monospace';
		ctx.fillStyle = '#10201edc';
		ctx.fillRect(1, 1, 20, 15);
		ctx.fillRect(size-bar-11, size-19, bar+10, 18);

		ctx.fillStyle = '#edf8db';
		ctx.textAlign = 'center';
		const octant = Math.round(bearing/(Math.PI/4));
		ctx.fillText(headings[(octant%8+8)%8], 11, 12);
		ctx.fillText(label, size-bar/2-6, size-8);
		ctx.fillRect(size-bar-6, size-5, bar, 1);
		ctx.fillRect(size-bar-6, size-7, 1, 3);
		ctx.fillRect(size-7, size-7, 1, 3);

		ctx.lineJoin = 'round';

		const objective = data.navigation;
		if(objective)
		{
			const [dx,dy] = offset(objective.x,objective.z);
			const fit = Math.min(1,(mid-8)/Math.max(1,Math.abs(dx),Math.abs(dy)));
			const x = mid+dx*fit, y = mid+dy*fit;
			ctx.beginPath();
			if(objective.kind === 'pickup') ctx.rect(x-4,y-4,8,8);
			else ctx.arc(x,y,objective.kind === 'drop' ? 5 : 4,0,2*Math.PI);
			ctx.fillStyle = objective.color;
			ctx.strokeStyle = '#17241b';
			ctx.lineWidth = 2;
			ctx.fill();
			ctx.stroke();
			if(objective.kind === 'drop')
			{
				ctx.beginPath();
				ctx.arc(x,y,2,0,2*Math.PI);
				ctx.fillStyle = '#331613';
				ctx.fill();
			}
		}
		for(const [id,pos] of Object.entries(data.targets ?? {}))
		{
			const [x,y] = offset(pos[0], pos[2]);
			const edge = (mid-7)/Math.max(1, Math.abs(x), Math.abs(y));

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
	};

	return () => {
		hide();
		delete module.drawTerrainMap;
		map.remove();
	};
}
