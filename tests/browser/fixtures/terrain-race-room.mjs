export class MatrixFixture
{
	room='!race:example.test'; events=[]; blobs=new Map(); transactions=new Map();
	async attach(context,id)
	{
		await context.addInitScript(user=>{
			localStorage.setItem('phonics.matrix.session:https://matrix.org',JSON.stringify({accessToken:user,userId:user,
				deviceId:'TEST',displayName:user.slice(1).split(':')[0]}));
		},id);
		await context.route('https://matrix.org/**',async route=>{
			const req=route.request(), u=new URL(req.url()), path=decodeURIComponent(u.pathname);
			const method=req.method(),sender=req.headers().authorization?.slice(7);
			const json=(v,status=200)=>route.fulfill({status,json:v});
			try {
				if(method==='OPTIONS') return await route.fulfill({status:200,headers:{'Access-Control-Allow-Origin':'*',
					'Access-Control-Allow-Methods':'GET,POST,PUT,OPTIONS','Access-Control-Allow-Headers':'*'}});
				if(path.endsWith('/whoami')) return await json({user_id:id,device_id:'TEST'});
				if(path.includes('/join/'))return await json({room_id:this.room});
				if(path.endsWith('/m.room.create/'))return await json({});
				if(path.includes('/profile/')) return await json({displayname:id});
				if(path.endsWith('/turnServer'))return await json({uris:[],ttl:600});
				if(path.endsWith('/config'))return await json({'m.upload.size':1048576});
				if(path.endsWith('/upload')){const key=String(this.blobs.size);this.blobs.set(key,req.postDataBuffer());return await json({content_uri:'mxc://example.test/'+key});}
				if(path.includes('/media/download/'))return await route.fulfill({status:200,body:this.blobs.get(path.split('/').at(-1))});
				if(path.includes('/register'))return await json({errcode:'M_FORBIDDEN',error:'Guests disabled'},403);
				if(path.includes('/send/')) {
					const [type,...txn]=path.split('/send/')[1].split('/'),key=id+'/'+txn.join('/');
					let e=this.transactions.get(key);
					if(!e) {
						e={event_id:'$'+(this.events.length+1),room_id:this.room,origin_server_ts:Date.now(),sender:sender??id,
							type,content:req.postDataJSON()};this.events.push(e);this.transactions.set(key,e);
					}
					return await json({event_id:e.event_id});
				}
				if(path.endsWith('/messages')) {
					const types=JSON.parse(u.searchParams.get('filter')??'{}').types??[];
					return await json({chunk:this.events.filter(e=>!types.length||types.includes(e.type)).toReversed()});
				}
				if(path.includes('/relations/')) {
					const root=path.split('/relations/')[1].split('/')[0];return await json({chunk:this.events.filter(e=>e.content['m.relates_to']?.event_id===root)});
				}
				if(path.includes('/event/')) {const e=this.events.find(e=>e.event_id===path.split('/').at(-1));return await json(e??{},e?200:404);}
				if(path.endsWith('/sync')) {
					await new Promise(r=>setTimeout(r,200));
					const since=Number(u.searchParams.get('since')??0);
					return await json({next_batch:String(this.events.length),rooms:{join:{[this.room]:{timeline:{events:this.events.slice(since),limited:false}}}}});
				}
				return await json({});
			} catch(e) {if(!String(e).includes('closed'))console.log('fixture',path,e.message);}
		});
	}
}
