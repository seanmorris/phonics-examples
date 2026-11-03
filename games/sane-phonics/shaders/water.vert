#version 300 es
precision highp float;
uniform vec4 screenRect;
uniform vec2 viewport;
out vec2 screenUv;
const vec2 corners[6]=vec2[6](vec2(0,0),vec2(1,0),vec2(0,1),vec2(0,1),vec2(1,0),vec2(1,1));
void main() {
    vec2 corner=corners[gl_VertexID];
    vec2 pixel=screenRect.xy+corner*screenRect.zw;
    vec2 position=pixel/viewport;
    gl_Position=vec4(position.x*2.-1.,1.-position.y*2.,0,1);
    screenUv=position;
}
