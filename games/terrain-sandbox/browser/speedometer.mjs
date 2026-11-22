const dialAngle = (speed, maximum) => Math.PI*(.75 + 1.5*speed/maximum);

/** The bottle spans 120 degrees around the upper-left rim. A zero charge leaves only the track. */
function nitrousArc(ctx, fraction, width, color)
{
	if(fraction <= 0) return;

	const start = Math.PI * 11/12;
	ctx.lineCap = 'round';
	ctx.lineWidth = width;
	ctx.strokeStyle = color;
	ctx.beginPath();
	ctx.arc(0, 0, 85, start, start + fraction * Math.PI * 2/3);
	ctx.stroke();
}

/** Cache the trim, carbon weave and lettering so a moving needle only repaints the composite. */
function paintFace(ctx, size, maximum, padding)
{
	ctx.setTransform(size/160, 0, 0, size/160, padding + size/2, padding + size/2);
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

	if(padding) nitrousArc(ctx, 1, 6, '#202629');
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
	let lastAngle, lastValue, lastMaximum, lastCharge;

	module.drawTerrainSpeedometer = frame => {
		if(!frame?.speedometer)
		{
			if(!dial.hidden) dial.hidden = true;

			return;
		}

		const {mph, maximum, size, nitrous} = frame.speedometer;
		const charge = nitrous === undefined ? null : Math.round(100 * nitrous);
		// Add space to the top and left only, keeping the dial's size and lower-right anchor.
		const padding = charge === null ? 0 : Math.ceil(size * 9/160);
		const extent = size + padding;

		if(dial.width !== extent || dial.height !== extent || lastMaximum !== maximum)
		{
			dial.width = dial.height = face.width = face.height = extent;
			paintFace(faceCtx, size, maximum, padding);
			lastMaximum = maximum;
			sandbox.style.setProperty('--speedometer-size', extent + 'px');
			lastAngle = undefined;
		}

		dial.hidden = false;
		const value = Math.round(mph);

		if(lastValue !== value || lastCharge !== charge)
		{
			const bottle = charge === null ? '' : `, nitrous ${charge}%`;
			dial.setAttribute('aria-label', `Speedometer, ${value} miles per hour${bottle}`);
			lastValue = value;
		}

		const angle = dialAngle(Math.min(mph, maximum), maximum);

		if(lastAngle === angle && lastCharge === charge) return;

		lastAngle = angle;
		lastCharge = charge;
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		ctx.clearRect(0, 0, extent, extent);
		ctx.drawImage(face, 0, 0);
		ctx.setTransform(size/160, 0, 0, size/160, padding + size/2, padding + size/2);

		if(charge !== null) nitrousArc(ctx, charge/100, 4, '#caff18');

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
