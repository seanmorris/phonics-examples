/** Synchronized aircraft instruments. PHP supplies the same interpolated eye used to draw this frame. */
export function activate({module, canvas})
{
	const screen = document.createElement('canvas');
	screen.className = 'terrain-instruments';
	screen.setAttribute('aria-hidden', 'true');
	screen.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none';
	screen.hidden = true;
	canvas.parentElement.append(screen);

	const ctx = screen.getContext('2d');
	const green = '#59ff86', white = '#f1fff4', red = '#ff5148';

	function draw(frame)
	{
		module.drawTerrainFlightMap(frame);

		if(!frame || !frame.cockpit && !Object.keys(frame.targets).length && !frame.range.hit)
		{
			screen.hidden = true;

			return;
		}

		const {width, height, eye, basis, jet, targets, range, cockpit} = frame;
		screen.hidden = false;

		if(screen.width !== width || screen.height !== height)
		{
			screen.width = width;
			screen.height = height;
		}

		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.clearRect(0, 0, width, height);
		// A uniform layout unit keeps the ring circular on tall and ultrawide viewports.
		const unit = Math.min(width/800, height/720), w = width/unit, h = height/unit;
		ctx.setTransform(unit, 0, 0, unit, width/2, height/2);
		ctx.lineJoin = 'round';
		ctx.shadowColor = '#0a2219';
		ctx.shadowBlur = 2*unit;
		const focal = h/(2*Math.tan(frame.fov/2));

		const dot = (a, b) => a[0]*b[0]+a[1]*b[1]+a[2]*b[2];
		const [nose,right,up] = basis;

		const point = p => {
			const v = p.map((n, i) => n-eye[i]);
			const z = dot(v, nose);

			return z > .25 ? [focal*dot(v, right)/z, -focal*dot(v, up)/z] : null;
		};

		function line(x, y, x2, y2, color = green)
		{
			ctx.strokeStyle = color;
			ctx.beginPath();
			ctx.moveTo(x, y);
			ctx.lineTo(x2, y2);
			ctx.stroke();
		}

		function circle(x, y, r, color = green)
		{
			ctx.strokeStyle = color;
			ctx.beginPath();
			ctx.arc(x, y, r, 0, 2*Math.PI);
			ctx.stroke();
		}

		function text(value, x, y, size = 16, color = green, align = 'center')
		{
			ctx.textAlign = align;
			ctx.textBaseline = 'middle';
			ctx.font = 'bold ' + size + 'px monospace';
			ctx.fillStyle = color;
			ctx.fillText(String(value), x, y);
		}

		function label(value, x, y, width, fill = '#17c652', ink = '#071d0d')
		{
			ctx.fillStyle = fill;
			ctx.fillRect(x-width/2, y-10, width, 20);
			text(value, x, y, 16, ink);
		}

		const target = targets[range.selected];
		const dx = target ? target[0]-jet.x : 0, dz = target ? target[2]-jet.z : 0;
		const distance = target ? Math.hypot(dx, target[1]-jet.y, dz) : 0;
		ctx.lineWidth = 1.8;

		if(cockpit)
		{
			const ring = h*frame.ring, deg = Math.PI/180;
			const pitch = Math.asin(Math.max(-1, Math.min(1, nose[1])))/deg;
			const roll = Math.atan2(right[1], up[1]);
			const heading = (Math.atan2(nose[0], -nose[2])/deg+360)%360;
			const top = -h/2+36, bottom = h/2-37, left = -w/2+22, end = w/2-22;

			circle(0, 0, ring);
			ctx.save();
			ctx.beginPath();
			ctx.arc(0, 0, ring-2, 0, 2*Math.PI);
			ctx.clip();
			ctx.rotate(roll);

			for(let level = -90; level<=90; level += 5)
			{
				if(Math.abs(level-pitch)>32) continue;

				const y = focal*Math.tan((pitch-level)*deg);
				const length = level === 0 ? ring*.91 : ring*.49;
				ctx.setLineDash(level < 0 ? [9,6] : []);

				for(const side of [-1,1])
				{
					line(side*26, y, side*length, y);
					line(side*length, y, side*length, y+(level>0 ? 5 : -5));
					text(level, side*(length+15), y, 13);
				}
			}

			ctx.restore();
			line(-25, 0, -6, 0);
			line(6, 0, 25, 0);
			ctx.beginPath();
			ctx.moveTo(-6, 0);
			ctx.lineTo(0, -5);
			ctx.lineTo(6, 0);
			ctx.lineTo(0, 5);
			ctx.closePath();
			ctx.stroke();

			const headings = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];

			for(let tick = Math.ceil((heading-31)/4.5); tick*4.5<=heading+31; tick++)
			{
				const x = (tick*4.5-heading)*5;
				line(x, top+28, x, top+(tick%5===0 ? 15 : 23));

				if(tick%5===0) text(headings[((tick/5)%16+16)%16], x, top, 18);
			}

			function tape(value, step, side, title)
			{
				const x = side*Math.min(w*.35, ring+120);

				for(let n = Math.floor(value/step)-4; n<=Math.floor(value/step)+4; n++)
				{
					if(n<0) continue;

					const y = (value/step-n)*33;
					line(x, y, x+side*(n%2===0 ? 20 : 10), y);

					if(n%2===0 && Math.abs(y)>16) text(n*step, x+side*47, y, 14);
				}

				text(title, x, -161, 13);
				text(Math.round(value), x+side*56, 0, 24, white);
				line(x-side*12, 0, x, 0, white);
			}

			tape(jet.speed*3.6, 25, -1, 'KM/H');
			tape(Math.max(0, jet.y-range.ground), 50, 1, 'M AGL');

			const percent = frame.thrust, hot = Math.max(0, (percent-100)/40);
			const hue = Math.round(141*(1-hot));
			const rim = 'hsl(' + hue + ' 88% 55%)';
			const cx = left+38, cy = top+23, dialRadius = 31, start = Math.PI/2;
			const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, dialRadius);
			gradient.addColorStop(0, 'hsl(' + (95-39*hot) + ' 100% 63%)');
			gradient.addColorStop(.54, 'hsl(' + (127-101*hot) + ' 94% 53%)');
			gradient.addColorStop(1, rim);

			// The scale sweeps clockwise from six o'clock to three. Its free quadrant holds the readout.
			ctx.beginPath();
			ctx.moveTo(cx, cy);
			ctx.arc(cx, cy, dialRadius, start, start+1.5*Math.PI*percent/140);
			ctx.closePath();

			ctx.fillStyle = gradient;
			ctx.fill();

			ctx.strokeStyle = rim;
			ctx.beginPath();
			ctx.arc(cx, cy, dialRadius, start, 2*Math.PI);
			ctx.stroke();
			line(cx-dialRadius-4, cy, cx-dialRadius+4, cy, rim);
			line(cx, cy-dialRadius-4, cx, cy-dialRadius+4, rim);
			line(cx, cy+dialRadius-3, cx, cy+dialRadius+3, rim);

			label('ENG', cx+23, cy+5, 44, rim, '#171c14');
			text(percent + '%', cx+3, cy+27, 19, rim, 'left');

			const seconds = Math.floor(range.elapsed);

			text(
				String(Math.floor(seconds/60)).padStart(2, '0') + "' " + String(seconds%60).padStart(2, '0') + '"'
				, end
				, top
				, 27
				, green
				, 'right'
			);

			text('FLIGHT TIME', end, top+22, 12, green, 'right');

			for(let i = 0; i<2; i++)
			{
				ctx.fillStyle = i<range.ready ? green : '#28573b';
				ctx.fillRect(end-177+i*23, bottom-39, 11, 24);
			}

			text(range.ready, end-160, bottom-5, 27);
			label('MISSILE', end-160, bottom+18, 86);

			const radius = 57, rx = end-radius, ry = bottom-radius+12;
			ctx.fillStyle = 'rgba(8,27,22,.48)';
			ctx.beginPath();
			ctx.arc(rx, ry, radius, 0, 2*Math.PI);
			ctx.fill();
			circle(rx, ry, radius);
			line(rx-radius, ry, rx+radius, ry);
			line(rx, ry-radius, rx, ry+radius);
			line(rx, ry, rx-35, ry-44);
			line(rx, ry, rx+35, ry-44);

			const scale = distance>2500 ? 5000 : distance>1000 ? 2500 : 1000;
			text(scale===1000 ? 'S' : scale===2500 ? 'M' : 'L', rx-radius-12, ry, 15);

			for(const [id,pos] of Object.entries(targets))
			{
				const x = pos[0]-jet.x,z = pos[2]-jet.z;
				const factor = radius/Math.max(scale, Math.hypot(x, z)*1.09);
				const bx = rx-(x*Math.cos(jet.yaw)-z*Math.sin(jet.yaw))*factor;
				const by = ry-(x*Math.sin(jet.yaw)+z*Math.cos(jet.yaw))*factor;
				ctx.fillStyle = Number(id)===range.selected ? red : '#ffca51';
				ctx.fillRect(bx-3, by-3, 6, 6);
			}
		}

		if(target && range.visible)
		{
			const xy = point(target);

			if(xy)
			{
				const [x,y] = xy, c = range.locked ? red : green;
				ctx.strokeStyle = c;
				ctx.setLineDash(distance>1200 ? [5,4] : []);
				ctx.strokeRect(x-13, y-13, 26, 26);
				ctx.setLineDash([]);

				if(range.locked)
				for(const side of [-1,1])
				{
					line(x+side*10, y, x+side*19, y, c);
					line(x, y+side*10, x, y+side*19, c);
				}

				text('BAL', x+21, y-8, 14, c, 'left');
				text(Math.round(distance) + ' m', x+21, y+9, 14, c, 'left');
			}
		}

		if(range.locked && Math.floor(range.elapsed*4)%2===0) text('LOCK ON', 0, h*.42, 20);

		if(range.hit) label('HIT', 0, h*.34+8, 52);
	}

	module.terrainJetFrame = json => draw(json ? JSON.parse(json) : null);

	return () => {
		delete module.terrainJetFrame;
		screen.remove();
	};
}
