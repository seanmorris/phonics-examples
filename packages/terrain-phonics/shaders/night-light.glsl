// Broad blue fill from above and to one side. Retaining some material luminance
// lets a green hillside or red hull keep its shape under the saturated light.
vec3 terrainNightMaterial(vec3 albedo, vec3 normal, vec3 fill)
{
	float luminance = dot(albedo, vec3(0.2126,0.7152,0.0722));
	vec3 pigment = mix(albedo,vec3(luminance),0.65);
	float facing = max(0.0,dot(normal,vec3(-0.48,0.80,-0.36)));
	return pigment * fill * (1.0 + 1.9 * facing);
}
