const dialAngle = (speed, maximum) => Math.PI*(.75 + 1.5*speed/maximum);

/** Cache the trim, carbon weave and lettering so a moving needle only repaints the composite. */
function paintFace(ctx, size, maximum)
{
	ctx.setTransform(size/160, 0, 0, size/160, size/2, size/2);
	const rim = ctx.createLinearGradient(0, -78, 0, 78);
	rim.addColorStop(0, '#6b7075');
	rim.addColorStop(.42, '#292d31');
	rim.addColorStop(1, '#141719');
	ctx.fillStyle = rim;
	ctx.beginPath();
	ctx.arc(0, 0, 78, 0, 2*Math.PI);
	ctx.fill();

	ctx.save();
	ctx.beginPath();
	ctx.arc(0, 0, 73, 0, 2*Math.PI);
	ctx.clip();
	ctx.fillStyle = '#292b2d';
	ctx.fillRect(-74, -74, 148, 148);

	for(let y = -76; y < 76; y += 4)
	for(let x = -76; x < 76; x += 4)
	{
		ctx.fillStyle = (x+y)%8 === 0 ? '#333537' : '#2d2f31';
		ctx.fillRect(x, y, 3, 3);
	}

	const shade = ctx.createRadialGradient(0, -12, 14, 0, 0, 73);
	shade.addColorStop(0, '#10121300');
	shade.addColorStop(1, '#101213b3');
	ctx.fillStyle = shade;
	ctx.fillRect(-74, -74, 148, 148);
	ctx.restore();

	ctx.textAlign = 'center';
	ctx.textBaseline = 'middle';
	ctx.font = '700 12px system-ui, sans-serif';
	ctx.fillStyle = '#fff';

	for(let tick = 0; tick <= 24; tick++)
	{
		const speed = tick * maximum / 24;
		const angle = dialAngle(speed, maximum), x = Math.cos(angle), y = Math.sin(angle);
		const major = tick%3 === 0, inner = major ? 61 : 64;
		ctx.strokeStyle = major ? '#f8f9fa' : '#a9afb4';
		ctx.lineWidth = major ? 1.5 : 1;
		ctx.beginPath();
		ctx.moveTo(x*inner, y*inner);
		ctx.lineTo(x*69, y*69);
		ctx.stroke();

		if(major) ctx.fillText(speed, x*49, y*49);
	}

	ctx.font = '600 10px system-ui, sans-serif';
	ctx.fillText('MPH', 0, 33);
}

export function activate({module, canvas})
{
	const dial = document.createElement('canvas');
	const ctx = dial.getContext('2d');
	const face = document.createElement('canvas');
	const faceCtx = face.getContext('2d');
	dial.className = 'terrain-speedometer';
	dial.setAttribute('role', 'img');
	dial.hidden = true;
	canvas.parentElement.append(dial);
	const sandbox = dial.closest('.terrain-sandbox');
	let lastAngle, lastValue, lastMaximum;

	module.drawTerrainSpeedometer = frame => {
		if(!frame?.speedometer)
		{
			if(!dial.hidden) dial.hidden = true;

			return;
		}

		const {mph, maximum, size} = frame.speedometer;

		if(dial.width !== size || dial.height !== size || lastMaximum !== maximum)
		{
			dial.width = dial.height = face.width = face.height = size;
			paintFace(faceCtx, size, maximum);
			lastMaximum = maximum;
			sandbox.style.setProperty('--speedometer-size', size + 'px');
			lastAngle = undefined;
		}

		dial.hidden = false;
		const value = Math.round(mph);

		if(lastValue !== value)
		{
			dial.setAttribute('aria-label', `Speedometer, ${value} miles per hour`);
			lastValue = value;
		}

		const angle = dialAngle(Math.min(mph, maximum), maximum);

		if(lastAngle === angle) return;

		lastAngle = angle;
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.clearRect(0, 0, size, size);
		ctx.drawImage(face, 0, 0);
		ctx.setTransform(size/160, 0, 0, size/160, size/2, size/2);
		ctx.save();
		ctx.rotate(angle);
		ctx.beginPath();
		ctx.moveTo(-11, -3);
		ctx.lineTo(53, 0);
		ctx.lineTo(-11, 3);
		ctx.closePath();
		ctx.fillStyle = '#ff483f';
		ctx.fill();
		ctx.restore();
		ctx.beginPath();
		ctx.arc(0, 0, 5, 0, 2*Math.PI);
		ctx.fillStyle = '#d9352e';
		ctx.fill();
	};

	return () => {
		delete module.drawTerrainSpeedometer;
		dial.remove();
	};
}
