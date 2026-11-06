/** Local-only atomic autosaves and explicit save-file dialogs. No hand is published or sent over a network. */
export function activate({module, session})
{
	const key='php-poker.local.v1',limit=1048576;
	let error='',writes=0,closed=false,pendingLoad='';
	let file={busy:false,operation:'',notice:'',error:'',revision:0};
	const publish=patch=>{file={...file,...patch,revision:file.revision+1};};
	const cancelled=value=>value?.name==='AbortError';
	const valid=value=>typeof value==='string'&&value.length<=limit;
	const finish=notice=>publish({busy:false,operation:'',notice,error:''});
	const fail=(operation,value)=>{
		if(cancelled(value)){publish({busy:false,operation:'',notice:'',error:''});return;}
		const action=operation==='save'?'saved':'opened';
		publish({busy:false,operation:'',notice:'',error:`The game could not be ${action}.`});
	};

	module.pokerLoad=()=>{
		try { return localStorage.getItem(key) ?? ''; }
		catch { error='Saved games are unavailable in this browser session.'; return ''; }
	};
	module.pokerSave=value=>{
		if(closed) return false;
		try
		{
			if(!valid(value)) throw Error('Invalid save size');
			// One setItem is atomic. On quota/security failure the last complete autosave remains intact.
			localStorage.setItem(key,value); error=''; writes++; return true;
		}
		catch { error='This game could not be saved. Free some browser storage to save your next turn.'; return false; }
	};

	module.pokerFileSave=value=>{
		if(closed||file.busy||!valid(value)) return false;
		publish({busy:true,operation:'save',notice:'Choose where to save the game.',error:''});
		void (async()=>{
			try
			{
				if(typeof globalThis.showSaveFilePicker==='function')
				{
					const handle=await globalThis.showSaveFilePicker({suggestedName:'php-poker-save.json',types:[{
						description:'PHP Poker saved game',accept:{'application/json':['.json']}
					}]});
					const writable=await handle.createWritable();
					await writable.write(value); await writable.close();
				}
				else
				{
					const url=URL.createObjectURL(new Blob([value],{type:'application/json'}));
					const link=document.createElement('a');link.href=url;link.download='php-poker-save.json';link.hidden=true;
					document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),0);
				}
				if(!closed) finish('Game saved.');
			}
			catch(value){if(!closed)fail('save',value);}
		})();
		return true;
	};

	const inputFile=()=>new Promise(resolve=>{
		const input=document.createElement('input');input.type='file';input.accept='.json,application/json';input.hidden=true;
		let settled=false;
		const done=value=>{if(settled)return;settled=true;input.remove();resolve(value);};
		input.addEventListener('change',()=>done(input.files?.[0]??null),{once:true});
		input.addEventListener('cancel',()=>done(null),{once:true});
		document.body.appendChild(input);input.click();
	});
	module.pokerFileLoad=()=>{
		if(closed||file.busy) return false;
		publish({busy:true,operation:'load',notice:'Choose a saved game to open.',error:''});
		void (async()=>{
			try
			{
				let chosen;
				if(typeof globalThis.showOpenFilePicker==='function')
				{
					const handles=await globalThis.showOpenFilePicker({multiple:false,types:[{
						description:'PHP Poker saved game',accept:{'application/json':['.json']}
					}]});
					chosen=await handles[0]?.getFile();
				}
				else chosen=await inputFile();
				if(!chosen){if(!closed)publish({busy:false,operation:'',notice:'',error:''});return;}
				if(chosen.size>limit)throw Error('Save is too large');
				const value=await chosen.text();
				if(!valid(value))throw Error('Invalid save size');
				pendingLoad=value;
				if(!closed) finish('Checking saved game.');
			}
			catch(value){if(!closed)fail('load',value);}
		})();
		return true;
	};
	module.pokerFileTakeLoad=()=>{const value=pendingLoad;pendingLoad='';return value;};
	module.pokerFileCompleteLoad=success=>{
		if(closed)return;
		publish({busy:false,operation:'',notice:success?'Game loaded.':'',error:''});
	};
	module.pokerFileStatus=()=>JSON.stringify(file);
	module.pokerStorageStatus=()=>JSON.stringify({error,writes});
	session.phpPokerStorage={get status(){return {error,writes,file:{...file}};}};
	return ()=>{
		closed=true;pendingLoad='';
		for(const name of ['pokerLoad','pokerSave','pokerFileSave','pokerFileLoad','pokerFileTakeLoad','pokerFileCompleteLoad','pokerFileStatus','pokerStorageStatus']) delete module[name];
		delete session.phpPokerStorage;
	};
}
