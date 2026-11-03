#version 300 es
precision highp float;
uniform sampler2D scene;
uniform sampler2D displacementMap;
uniform vec2 viewport;
uniform vec2 camera;
uniform vec4 tint;
uniform float zoom;
uniform float time;
uniform float displacement;
uniform float displacementScale;
uniform float displacementSpeed;
uniform int waterEffect;
in vec2 screenUv;
out vec4 outputColor;

vec4 scenePixel(vec2 point) {
    return texture(scene,vec2(point.x,1.-point.y));
}

void main() {
    if(waterEffect==0) {
        outputColor=scenePixel(screenUv);
        return;
    }

    vec2 world=camera+screenUv*viewport/zoom;
    vec2 mapUv=world/displacementScale+vec2(0,time*displacementSpeed/displacementScale);
    vec2 field=texture(displacementMap,mapUv).rg*2.-1.;
    vec2 offset=field*displacement/viewport;
    vec4 center=scenePixel(screenUv+offset);
    vec4 left=scenePixel(screenUv+offset-vec2(.5/viewport.x,0));
    vec4 right=scenePixel(screenUv+offset+vec2(.5/viewport.x,0));
    vec4 blurred=(center*2.+left+right)*.25;
    outputColor=vec4(mix(blurred.rgb,tint.rgb,tint.a),blurred.a);
}
