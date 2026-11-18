#version 300 es
precision highp float;

layout(location=0) in vec3 aPosition;
layout(location=1) in vec3 aNormal;
layout(location=2) in vec2 aUv;
layout(location=3) in vec4 aPaint;      // sRGB base color and linear roughness
layout(location=4) in vec2 aSurface;    // metallic factor and dashboard texture flag

uniform mat4 uViewProjection;
uniform mat3 uRotation;
uniform vec3 uOrigin;
out vec3 vRelative, vNormal;
out vec2 vUv;
flat out vec4 vPaint;
flat out vec2 vSurface;

void main()
{
	vRelative = uOrigin + uRotation * aPosition;
	vNormal = uRotation * aNormal;
	vUv = aUv;
	vPaint = aPaint;
	vSurface = aSurface;
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
