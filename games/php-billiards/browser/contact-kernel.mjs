/**
 * Synchronous arithmetic backend for ContactModel and PocketModel. PHP owns authoritative Ball objects and
 * exact cloth-boundary resolution; a conservative guard yields before a possible boundary event.
 * Keep operation order identical to the reference. Only basic binary64 operations and square roots run here;
 * PHP supplies material coefficients and table geometry. Packed-double and complete-shot tests guard parity.
 */
/** Reuse RK buffers throughout a contact episode so soft contacts do not create a GC pause. */
function integrator(input) {
	const [, hertz, dampingCoefficient, mass, radius, gravity] = input;
	const count = (input.length - 6) / 15,
		diameter = 2 * radius,
		inertia = 0.4 * mass * (radius * radius);
	const state = new Float64Array(count * 9),
		work = new Float64Array(count * 9);
	const stages = Array.from({ length: 4 }, () => new Float64Array(count * 9));
	const active = new Uint8Array(count),
		free = new Uint8Array(count);
	function derivative(s, result) {
		result.fill(0);
		for (let i = 0; i < count; i++)
			if (active[i]) {
				const a = i * 9;
				result[a] = s[a + 3];
				result[a + 1] = free[i] ? s[a + 4] : 0;
				result[a + 2] = s[a + 5];
				result[a + 4] = free[i] ? -gravity : 0;
			}
		for (let i = 0; i < count; i++)
			if (active[i])
				for (let j = i + 1; j < count; j++)
					if (active[j]) {
						const a = i * 9,
							b = j * 9,
							x = s[b] - s[a],
							y = s[b + 1] - s[a + 1],
							z = s[b + 2] - s[a + 2];
						if (Math.abs(x) >= diameter || Math.abs(y) >= diameter || Math.abs(z) >= diameter) continue;
						const distance = Math.sqrt(x * x + y * y + z * z),
							compression = diameter - distance;
						if (compression <= 0) continue;
						const nx = distance < 1e-15 ? 1 : x / distance,
							ny = distance < 1e-15 ? 0 : y / distance,
							nz = distance < 1e-15 ? 0 : z / distance;
						const lever = distance / 2,
							wx = s[a + 6] + s[b + 6],
							wy = s[a + 7] + s[b + 7],
							wz = s[a + 8] + s[b + 8];
						const vx = s[a + 3] - s[b + 3] + lever * (wy * nz - wz * ny),
							vy = s[a + 4] - s[b + 4] + lever * (wz * nx - wx * nz),
							vz = s[a + 5] - s[b + 5] + lever * (wx * ny - wy * nx);
						const approach = vx * nx + vy * ny + vz * nz,
							root = Math.sqrt(compression),
							damping = dampingCoefficient * Math.sqrt(root);
						const normal = Math.max(0, hertz * compression * root + damping * approach);
						let tx = vx - approach * nx,
							ty = vy - approach * ny,
							tz = vz - approach * nz;
						const slip = Math.sqrt(tx * tx + ty * ty + tz * tz),
							friction = slip > 0 ? Math.min(damping, (0.06 * normal) / slip) : 0;
						tx *= -friction;
						ty *= -friction;
						tz *= -friction;
						const fx = (-normal * nx + tx) / mass,
							fy = (-normal * ny + ty) / mass,
							fz = (-normal * nz + tz) / mass;
						result[a + 3] += fx;
						result[b + 3] -= fx;
						if (free[i]) result[a + 4] += fy;
						if (free[j]) result[b + 4] -= fy;
						result[a + 5] += fz;
						result[b + 5] -= fz;
						const ax = (lever * (ny * tz - nz * ty)) / inertia,
							ay = (lever * (nz * tx - nx * tz)) / inertia,
							az = (lever * (nx * ty - ny * tx)) / inertia;
						result[a + 6] += ax;
						result[b + 6] += ax;
						result[a + 7] += ay;
						result[b + 7] += ay;
						result[a + 8] += az;
						result[b + 8] += az;
					}
	}
	function offset(stage, dt) {
		for (let k = 0; k < state.length; k++) work[k] = state[k] + dt * stage[k];
		return work;
	}
	return (data) => {
		const seconds = data[0];
		for (let i = 0; i < count; i++) {
			const b = 6 + i * 15,
				a = i * 9;
			active[i] = data[b + 14];
			free[i] = data[b + 13] >= 0;
			for (let k = 0; k < 9; k++) state[a + k] = data[b + k];
			state[a + 1] = radius - data[b + 1];
			state[a + 4] = -data[b + 4];
		}
		const [a, b, c, d] = stages;
		derivative(state, a);
		derivative(offset(a, seconds / 2), b);
		derivative(offset(b, seconds / 2), c);
		derivative(offset(c, seconds), d);
		for (let i = 0; i < count; i++)
			if (active[i]) {
				const base = i * 9,
					row = 6 + i * 15;
				for (let k = base; k < base + 9; k++)
					work[k] = state[k] + (seconds / 6) * (a[k] + 2 * b[k] + 2 * c[k] + d[k]);
				const sx = (state[base + 6] + work[base + 6]) / 2,
					sy = (state[base + 7] + work[base + 7]) / 2,
					sz = (state[base + 8] + work[base + 8]) / 2;
				if (seconds > 0 && (sx !== 0 || sy !== 0 || sz !== 0)) {
					const x = data[row + 9],
						y = data[row + 10],
						z = data[row + 11],
						w = data[row + 12],
						h = 0.5 * seconds;
					let qx = x + h * (sx * w + sy * z - sz * y),
						qy = y + h * (-sx * z + sy * w + sz * x),
						qz = z + h * (sx * y - sy * x + sz * w),
						qw = w + h * (-sx * x - sy * y - sz * z);
					const l = Math.sqrt(qx * qx + qy * qy + qz * qz + qw * qw);
					if (l > 0) {
						qx /= l;
						qy /= l;
						qz /= l;
						qw /= l;
					}
					data[row + 9] = qx;
					data[row + 10] = qy;
					data[row + 11] = qz;
					data[row + 12] = qw;
				}
				work[base + 1] = free[i] ? radius - work[base + 1] : data[row + 1];
				work[base + 4] = free[i] ? -work[base + 4] : data[row + 4];
				for (let k = 0; k < 9; k++) data[row + k] = work[base + k];
			}
	};
}

export function contactStep(input) {
	const output = new Float64Array(input);
	integrator(output)(output);
	return output;
}

let geometry;
export function configureGeometry(json) {
	geometry = JSON.parse(json);
}
const length = (x, y) => Math.sqrt(x * x + y * y);

/** Mirrors PocketModel: constraints are applied only after an accepted RK step. */
function pocket(state, offset, index, events) {
	const b = Array.from(state.slice(offset, offset + 15)),
		p = b[13];
	const [px, py, ox, oy, mx, my] = geometry.pockets[p];
	const { radius, throat, liner, draft, restitution, maxSpeed, sinkDepth } = geometry;
	function clearFacings() {
		const faces = geometry.facings.slice(p * 2, p * 2 + 2);
		function normal([sx, sy, ex, ey]) {
			const dx = ex - sx,
				dy = ey - sy,
				l = length(dx, dy);
			let nx = -dy / l,
				ny = dx / l;
			if ((px - sx) * nx + (py - sy) * ny < 0) {
				nx = -nx;
				ny = -ny;
			}
			return [nx, ny];
		}
		for (let pass = 0; pass < 3; pass++) {
			let corrected = false;
			for (const face of faces) {
				const [nx, ny] = normal(face),
					distance = (b[0] - face[0]) * nx + (b[2] - face[1]) * ny;
				if (distance >= radius) continue;
				const correction = radius - distance + 1e-7;
				b[0] += nx * correction;
				b[2] += ny * correction;
				const speed = b[3] * nx + b[5] * ny;
				if (speed < 0) {
					const impulse = (1 + restitution) * speed;
					b[3] -= impulse * nx;
					b[5] -= impulse * ny;
				}
				corrected = true;
			}
			if (!corrected) return true;
		}
		for (const face of faces) {
			const [nx, ny] = normal(face);
			if ((b[0] - face[0]) * nx + (b[2] - face[1]) * ny < radius - 1e-8) return false;
		}
		return true;
	}
	let rx = b[0] - px,
		ry = b[2] - py,
		radial = length(rx, ry);
	const [ux, uy] = radial < 1e-9 ? [1, 0] : [rx / radial, ry / radial];
	let normal = null,
		onRim = b[1] < radius;
	if (onRim && rx * ox + ry * oy > 0 && radial > liner) {
		radial = liner;
		normal = [ux, uy, 0];
		onRim = false;
	} else if (onRim) {
		const inward = throat - radial,
			above = radius - b[1],
			distance = length(Math.max(0, inward), above);
		if (inward <= 0 && b[1] > 0) {
			b[1] = 0;
			normal = [0, 0, 1];
		} else if (inward > 0 && distance < radius) {
			radial = throat - (inward * radius) / distance;
			b[1] = radius - (above * radius) / distance;
			normal = [(ux * inward) / distance, (uy * inward) / distance, above / distance];
		}
	} else {
		const allowed = Math.max(0.001, throat - radius - draft * (b[1] - radius));
		if (radial > allowed) {
			radial = allowed;
			const l = length(1, draft);
			normal = [ux / l, uy / l, draft / l];
		}
	}
	if (normal !== null) {
		const [nx, ny, nz] = normal;
		b[0] = px + ux * radial;
		b[2] = py + uy * radial;
		const speed = b[3] * nx + b[5] * ny + b[4] * nz;
		if (speed > 0) {
			const e = onRim && speed < 0.3 ? 0 : restitution,
				impulse = (1 + e) * speed;
			b[3] -= impulse * nx;
			b[5] -= impulse * ny;
			b[4] -= impulse * nz;
			if (speed >= 0.3) {
				b[3] *= 0.985;
				b[5] *= 0.985;
				b[7] *= 0.92;
			}
			if (e > 0) events.push(2, index, p, Math.min(1, speed / maxSpeed), b[1] / radius);
		}
	}
	if (b[1] < radius) clearFacings();
	const crossed = (b[0] - mx) * ox + (b[2] - my) * oy <= 0,
		leaving = b[3] * ox + b[5] * oy < -1e-6;
	rx = b[0] - px;
	ry = b[2] - py;
	const onShelf = onRim && b[1] <= 1e-8 && length(rx, ry) > throat && rx * ox + ry * oy <= 0;
	if (!onShelf && (!crossed || !leaving) && b[1] < 0) {
		b[1] = 0;
		b[4] = Math.max(0, b[4]);
	} else if (onShelf || (crossed && leaving && b[1] < 0)) {
		if (!clearFacings() || length(b[0] - px, b[2] - py) <= throat) {
			b[1] = 0;
			b[4] = Math.max(0, b[4]);
			state.set(b, offset);
			return false;
		}
		b[1] = 0;
		b[4] = 0;
		b[13] = -1;
		events.push(3, index, p, 0, 0);
		state.set(b, offset);
		return true;
	}
	if (b[1] >= sinkDepth) {
		b[1] = sinkDepth;
		b[14] = 0;
		for (let k = 3; k < 9; k++) b[k] = 0;
		events.push(4, index, p, 0, 0);
	}
	state.set(b, offset);
	return false;
}

/**
 * Conservative copy of Simulation::earliest's cloth-only sweep. It never resolves a boundary: returning true
 * yields the unchanged microstep to PHP, which selects and resolves the exact earliest event. The extra time
 * margin can only yield early; it covers rounding differences in PHP's integer pow versus JS multiplication.
 */
function clothBoundary(state, limit) {
	const { radius, halfX, halfY, cushions, jaws, swept, throat } = geometry;
	limit += 1e-10;
	for (let a = 6; a < state.length; a += 15) {
		if (!state[a + 14] || state[a + 13] >= 0) continue;
		const x = state[a],
			y = state[a + 2],
			vx = state[a + 3],
			vy = state[a + 5];
		const speed = vx * vx + vy * vy,
			reach = Math.sqrt(speed) * limit + 1e-6;
		if (Math.abs(x) + reach < halfX && Math.abs(y) + reach < halfY) continue;
		for (const [px, py, r] of swept[0]) {
			if (Math.abs(x - px) > r + reach || Math.abs(y - py) > r + reach) continue;
			const rx = x - px,
				ry = y - py,
				separation = rx * rx + ry * ry - r * r,
				approach = 2 * (rx * vx + ry * vy);
			if (separation <= 1e-14) return true;
			if (speed <= 1e-12 || approach >= 0) continue;
			const disc = approach * approach - 4 * speed * separation;
			if (disc < 0) continue;
			const t = (-approach - Math.sqrt(disc)) / (2 * speed);
			if (t >= -1.0001e-9 && t <= limit) return true;
		}
		if (vx === 0 && vy === 0) continue;
		for (const [px, py, clearance, ox, oy] of swept[0]) {
			const rx = x - px,
				ry = y - py,
				distance = rx * ox + ry * oy,
				outward = vx * ox + vy * oy;
			if (outward <= 1e-12) continue;
			const time = distance >= 0 ? 0 : -distance / outward;
			if (time < -1.0001e-9 || time > limit) continue;
			const lateral = (rx + vx * Math.max(0, time)) * -oy + (ry + vy * Math.max(0, time)) * ox;
			if (Math.abs(lateral) >= clearance - 1e-9 && Math.abs(lateral) <= throat + radius + 1e-12) return true;
		}
		for (const [axis, edge, position, velocity, along] of [
			[0, vx > 0 ? halfX : -halfX, x, vx, y],
			[1, vy > 0 ? halfY : -halfY, y, vy, x]
		]) {
			if (Math.abs(velocity) < 1e-12) continue;
			const time = (edge - position) / velocity;
			if (time < -1.0001e-9 || time > limit) continue;
			const coordinate = Math.abs(along + (axis === 0 ? vy : vx) * time);
			if (
				axis === 0
					? coordinate < cushions[0] + 1e-12
					: coordinate < cushions[1] + 1e-12 && coordinate > cushions[2] - 1e-12
			)
				return true;
		}
		for (const [jx, jy] of jaws) {
			if (Math.abs(x - jx) > radius + reach || Math.abs(y - jy) > radius + reach) continue;
			const rx = x - jx,
				ry = y - jy,
				approach = 2 * (rx * vx + ry * vy),
				separation = rx * rx + ry * ry - radius * radius;
			let time;
			if (separation <= 1e-14 && approach < 0) time = 0;
			else {
				if (speed <= 1e-12 || approach >= 0) continue;
				const disc = approach * approach - 4 * speed * separation;
				if (disc < 0) continue;
				time = (-approach - Math.sqrt(disc)) / (2 * speed);
			}
			if (time >= -1.0001e-9 && time <= limit) return true;
		}
		for (const [sx, sy, segment, tx, ty, lx, ly, minX, maxX, minY, maxY] of swept[1]) {
			const margin = radius + reach;
			if (x < minX - margin || x > maxX + margin || y < minY - margin || y > maxY + margin) continue;
			const distance = (x - sx) * lx + (y - sy) * ly,
				normalVelocity = vx * lx + vy * ly;
			if (Math.abs(normalVelocity) < 1e-12) continue;
			for (const side of [-1, 1]) {
				const nx = lx * side,
					ny = ly * side,
					time = (side * radius - distance) / normalVelocity;
				if (time < -1.0001e-9 || time > limit || vx * nx + vy * ny >= -1e-9) continue;
				const cx = x + vx * Math.max(0, time),
					cy = y + vy * Math.max(0, time),
					along = (cx - sx) * tx + (cy - sy) * ty;
				if (along > 1e-8 - 1e-12 && along < segment - 1e-8 + 1e-12) return true;
			}
		}
	}
	return false;
}

const decode = (encoded) => new Float64Array(Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0)).buffer);
const encode = (values) => btoa(String.fromCharCode(...new Uint8Array(values.buffer)));
export function contactBase64(encoded) {
	return encode(contactStep(decode(encoded)));
}

export function contactBatch(input) {
	let remaining = input[6],
		masks = Array.from(input.slice(8, 24)),
		state = new Float64Array([...input.slice(0, 6), ...input.slice(24)]);
	const advance = integrator(state);
	const n = (state.length - 6) / 15,
		diameter = input[4] * 2,
		events = [],
		maxSteps = input[7];
	function record() {
		const next = Array(16).fill(0);
		let strength = 0;
		for (let i = 0; i < n; i++)
			for (let j = i + 1; j < n; j++) {
				const a = 6 + i * 15,
					b = 6 + j * 15;
				if (!state[a + 14] || !state[b + 14]) continue;
				const dx = state[b] - state[a],
					dy = state[b + 2] - state[a + 2],
					dz = state[b + 1] - state[a + 1];
				if (Math.abs(dx) > diameter || Math.abs(dy) > diameter || Math.abs(dz) > diameter) continue;
				const distance = Math.sqrt(dx * dx + dy * dy + dz * dz);
				if (distance > diameter + 1e-12 || distance < 1e-15) continue;
				next[i] |= 1 << j;
				if (masks[i] & (1 << j)) continue;
				const approach =
					((state[a + 3] - state[b + 3]) * dx +
						(state[a + 5] - state[b + 5]) * dy +
						(state[a + 4] - state[b + 4]) * dz) /
					distance;
				strength = Math.max(strength, approach / geometry.maxSpeed);
			}
		masks = next;
		if (strength > 0) events.push(1, 0, 0, Math.min(1, strength), 0);
	}
	function compressed() {
		for (let i = 0; i < n; i++)
			for (let j = i + 1; j < n; j++) {
				const a = 6 + i * 15,
					b = 6 + j * 15;
				if (!state[a + 14] || !state[b + 14]) continue;
				const dx = state[b] - state[a],
					dy = state[b + 2] - state[a + 2],
					dz = state[b + 1] - state[a + 1];
				if (Math.abs(dx) >= diameter || Math.abs(dy) >= diameter || Math.abs(dz) >= diameter) continue;
				if (diameter - Math.sqrt(dx * dx + dy * dy + dz * dz) > 0) return true;
			}
		return false;
	}
	function resolveSink(index) {
		const offset = 6 + index * 15;
		if (state[offset + 14] && state[offset + 1] >= geometry.sinkDepth - 1e-10) {
			state[offset + 1] = geometry.sinkDepth;
			pocket(state, offset, index, events);
		}
	}
	let steps = 0,
		rejected = false;
	do {
		let dt = Math.min(remaining, input[0]),
			sink = -1,
			sinkTime = Infinity;
		if (maxSteps > 1 && clothBoundary(state, dt)) break;
		// A single step has already been split by PHP. A batch mirrors each subsequent sink crossing here.
		if (maxSteps > 1)
			for (let i = 0; i < n; i++) {
				const offset = 6 + i * 15;
				if (!state[offset + 14] || state[offset + 13] < 0) continue;
				const v = state[offset + 4],
					depth = Math.max(0, geometry.sinkDepth - state[offset + 1]);
				const time = (-v + Math.sqrt(v * v + 2 * input[5] * depth)) / input[5];
				if (time <= dt && time < sinkTime) {
					sink = i;
					sinkTime = time;
				}
			}
		if (sink !== -1) {
			if (sinkTime <= 1e-12) {
				resolveSink(sink);
				sink = -1;
			} else dt = sinkTime;
		}
		state[0] = dt;
		record();
		advance(state);
		record();
		for (let i = 0; i < n; i++) {
			const offset = 6 + i * 15;
			if (state[offset + 14] && state[offset + 13] >= 0) rejected = pocket(state, offset, i, events) || rejected;
		}
		remaining -= dt;
		if (sink !== -1) resolveSink(sink);
	} while (++steps < maxSteps && remaining > 1e-12 && !rejected && compressed());
	return new Float64Array([remaining, ...masks, events.length / 5, ...events, ...state.slice(6)]);
}
export function contactBatchBase64(encoded) {
	return encode(contactBatch(decode(encoded)));
}

export function activate({ module }) {
	module.billiardsContactGeometry = configureGeometry;
	module.billiardsContactBatch = contactBatchBase64;
	return () => {
		delete module.billiardsContactGeometry;
		delete module.billiardsContactBatch;
		geometry = undefined;
	};
}
