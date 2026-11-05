import test from 'node:test';
import assert from 'node:assert/strict';
import {MatrixLobbyProvider} from '../packages/phonics-matrix/browser/matrix.mjs';

test('Matrix profiles expose display names and authenticated cropped avatar thumbnails',async()=>{
	const requests=[];
	const provider=new MatrixLobbyProvider({
		homeserver:'https://matrix.example',room:'#games:matrix.example',accessToken:'secret'
		,userId:'@alice:matrix.example',storage:null,oauthStorage:null
		,fetch:async(input,options={})=>{
			const url=new URL(input);requests.push({url,options});
			if(url.pathname.includes('/profile/'))return new Response(JSON.stringify({
				displayname:'Alice',avatar_url:'mxc://media.example/avatar-id'
			}),{headers:{'content-type':'application/json'}});
			if(url.pathname.includes('/media/thumbnail/'))return new Response(new Blob(['avatar'],{type:'image/png'}),{
				headers:{'content-type':'image/png'}
			});
			throw new Error(`Unexpected request ${url}`);
		}
	});
	provider.connected=true;
	const profile=await provider.userProfile('@alice:matrix.example');
	assert.deepEqual(profile,{id:'@alice:matrix.example',displayName:'Alice',avatarUrl:'mxc://media.example/avatar-id'});
	assert.equal(provider.profile().avatarUrl,'mxc://media.example/avatar-id');
	const image=await provider.avatarThumbnail(profile.avatarUrl,{size:64});
	assert.equal(image.type,'image/png');
	const media=requests.at(-1);
	assert.equal(media.url.pathname,'/_matrix/client/v1/media/thumbnail/media.example/avatar-id');
	assert.equal(media.url.searchParams.get('width'),'64');
	assert.equal(media.url.searchParams.get('height'),'64');
	assert.equal(media.url.searchParams.get('method'),'crop');
	assert.equal(media.options.headers.Authorization,'Bearer secret');
});

test('Matrix avatar thumbnails reject non-MXC URLs before fetching',async()=>{
	let fetched=false;
	const provider=new MatrixLobbyProvider({
		homeserver:'https://matrix.example',room:'#games:matrix.example',accessToken:'secret'
		,userId:'@alice:matrix.example',storage:null,oauthStorage:null,fetch:async()=>{fetched=true;return new Response;}
	});
	provider.connected=true;
	await assert.rejects(()=>provider.avatarThumbnail('https://example/avatar.png'),/avatar URL is invalid/);
	assert.equal(fetched,false);
});
