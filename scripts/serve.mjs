import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../site/dist/', import.meta.url));
const argument = process.argv.indexOf('--port');
const port = argument >= 0 ? Number(process.argv[argument + 1]) : 4173;
const types = {'.html':'text/html; charset=utf-8','.mjs':'text/javascript; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.json':'application/json; charset=utf-8','.wasm':'application/wasm','.so':'application/wasm','.phar':'application/octet-stream','.svg':'image/svg+xml','.png':'image/png'};

const server = http.createServer((request,response) => {
	let pathname;

	try { pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname); }
	catch { response.writeHead(400).end(); return; }

	let file = path.join(root, pathname);

	if(!file.startsWith(root)){response.writeHead(403).end();return;}
	if(pathname.endsWith('/'))file=path.join(file,'index.html');
	fs.stat(file,(error,stat)=>{
		if(error||!stat.isFile()){response.writeHead(404).end('Not found');return;}
		response.writeHead(200,{'Content-Type':types[path.extname(file)]??'application/octet-stream','Cache-Control':'no-store','Access-Control-Allow-Origin':'*'});
		fs.createReadStream(file).pipe(response);
	});
});
server.listen(port,'127.0.0.1',()=>console.log(`Serving ${root} at http://127.0.0.1:${port}/`));

