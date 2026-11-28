#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
uniform vec3 uAerialCacheGrid;
uniform float uAerialCacheMaxDistance;
uniform float uAerialCacheAltitude;
layout(location=0) out vec4 outTransmittance;
layout(location=1) out vec4 outRadiance;
void main()
{
	if(int(gl_FragCoord.y)==int(uAerialCacheGrid.y)) {
		// One meter per texel around the eye. Store high and low bytes in the existing pair of atlases.
		float count=uAerialCacheGrid.x*uAerialCacheGrid.z;
		float height=floor(uAerialCacheAltitude)+floor(gl_FragCoord.x)-floor(.5*count);
		vec3 t=terrainTransmittance(height,uSunDirection.y);
		vec3 next=terrainTransmittance(height+1.,uSunDirection.y);
		vec3 delta=abs(next-t);
		// Near the horizon a ray can start or stop intersecting the planet. Steep transfers and
		// thin custom layers also keep the analytic path, including a band wholly inside one cell.
		bool safe=uSunDirection.y>.05 && max(delta.x,max(delta.y,delta.z))<1./2048.;
		for(int i=0;i<TERRAIN_AIR_LAYERS;i++) {
			if(float(i)>=uAirLayerCount) break;
			vec2 band=uAirBand[i].xy;
			if(band.y-band.x<4. && height+1.>=band.x && height<=band.y) safe=false;
		}
		vec3 code=floor(clamp(t,0.,1.)*65535.+.5);
		outTransmittance=vec4(floor(code/256.)/255.,float(safe));
		outRadiance=vec4(mod(code,256.)/255.,1.);
		return;
	}
	float slice = floor(gl_FragCoord.x / uAerialCacheGrid.x);
	vec2 uv = vec2(mod(gl_FragCoord.x, uAerialCacheGrid.x), gl_FragCoord.y) / uAerialCacheGrid.xy;
	float longitude = (uv.x - 0.5) * 2.0 * TERRAIN_PI;
	float latitudeCoordinate = uv.y * 2.0 - 1.0;
	// A cubic latitude warp preserves the very narrow horizon band even with sixteen rows.
	float latitude = latitudeCoordinate * latitudeCoordinate * latitudeCoordinate * TERRAIN_PI * 0.5;
	vec3 ray = vec3(cos(latitude) * cos(longitude), sin(latitude), cos(latitude) * sin(longitude));
	// A 100-meter scale keeps samples useful across terrain viewing distances while preserving distance zero.
	float distanceMeters = 100.0 * (exp(slice / (uAerialCacheGrid.z - 1.0)
		* log(1.0 + uAerialCacheMaxDistance / 100.0)) - 1.0);
	vec3 transmittance;
	vec3 radiance;
	terrainIntegrateAtmosphere(ray, uAerialCacheAltitude, distanceMeters, true, transmittance, radiance);
	outTransmittance = vec4(transmittance,1.0);
	vec3 encoded = clamp(log2(1.0 + max(vec3(0.0), radiance) * 16.0) / 16.0, 0.0, 1.0);
	outRadiance = vec4(encoded, 1.0);
}
