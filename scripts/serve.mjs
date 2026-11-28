import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../site/dist/', import.meta.url));
const argument = process.argv.indexOf('--port');
const port = argument >= 0 ? Number(process.argv[argument + 1]) : 4173;
const types = {'.html':'text/html; charset=utf-8','.mjs':'text/javascript; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.json':'application/json; charset=utf-8','.wasm':'application/wasm','.so':'application/wasm','.phar':'application/octet-stream','.svg':'image/svg+xml','.png':'image/png'};
Object.assign(types, {'.mp3':'audio/mpeg', '.wav':'audio/wav', '.jpg':'image/jpeg'});

const server = http.createServer((request,response) => {
	let pathname;

	try { pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname); }
	catch { response.writeHead(400).end(); return; }

	let file = path.join(root, pathname);

	if(!file.startsWith(root)){response.writeHead(403).end();return;}
	if(pathname.endsWith('/'))file=path.join(file,'index.html');
	fs.stat(file,(error,stat)=>{
		if(error||!stat.isFile()){response.writeHead(404).end('Not found');return;}
		const type = types[path.extname(file)] ?? 'application/octet-stream';
		const headers = {'Content-Type':type,'Content-Length':stat.size,'Cache-Control':'no-store','Access-Control-Allow-Origin':'*'};
		let range;
		if(type.startsWith('audio/')) {
			headers['Accept-Ranges'] = 'bytes';
			const match = /^bytes=(\d*)-(\d*)$/.exec(request.headers.range ?? '');
			if(match && (match[1] || match[2])) {
				const start = match[1] ? Number(match[1]) : Math.max(0,stat.size-Number(match[2]));
				const end = match[1] && match[2] ? Math.min(stat.size-1,Number(match[2])) : stat.size-1;
				if(start > end) {
					response.writeHead(416,{'Content-Range':'bytes */'+stat.size}).end();
					return;
				}
				range = {start,end};
				headers['Content-Range'] = 'bytes '+start+'-'+end+'/'+stat.size;
				headers['Content-Length'] = end-start+1;
			}
		}
		response.writeHead(range ? 206 : 200,headers);
		if(request.method === 'HEAD') return response.end();
		const stream = fs.createReadStream(file,range);
		response.on('close',() => stream.destroy());
		stream.on('error',error => response.destroy(error)).pipe(response);
	});
});
server.listen(port,'127.0.0.1',()=>console.log(`Serving ${root} at http://127.0.0.1:${port}/`));

