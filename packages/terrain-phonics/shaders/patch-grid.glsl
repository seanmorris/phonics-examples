// Indexed 32-cell patches share integer grid coordinates even across two resolutions.
// uPatch contains the signed grid origin in cells, cell spacing in metres and parent blend.
uniform vec4 uPatch, uEdges, uCorners;
ivec2 patchCell()
{
	return ivec2(gl_VertexID % 33, gl_VertexID / 33);
}
vec2 patchWorld(ivec2 cell)
{
	return (uPatch.xy + vec2(cell)) * uPatch.z;
}
float patchMorph(ivec2 cell)
{
	if(cell.y == 0)
	{
		if(cell.x == 0) return uCorners.x;
		if(cell.x == 32) return uCorners.y;
		return uEdges.z;
	}
	if(cell.y == 32)
	{
		if(cell.x == 0) return uCorners.z;
		if(cell.x == 32) return uCorners.w;
		return uEdges.w;
	}
	if(cell.x == 0) return uEdges.x;
	if(cell.x == 32) return uEdges.y;
	return uPatch.w;
}
