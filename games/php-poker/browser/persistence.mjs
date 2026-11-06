/** Local-only atomic saves. This module never opens a network connection or publishes a hand. */
export function activate({module, session})
{
	const key='php-poker.local.v1';
	const manualKey='php-poker.manual.v1';
	let error='',writes=0,manual=false,closed=false;
	try{manual=localStorage.getItem(manualKey)!==null;}catch{}
	module.pokerLoad=()=>{
		try { return localStorage.getItem(key) ?? ''; }
		catch { error='Saved games are unavailable in this browser session.'; return ''; }
	};
	module.pokerSave=value=>{
		if(closed) return false;
		try
		{
			if(typeof value!=='string' || value.length>1048576) throw Error('Invalid save size');
			// One setItem is atomic. On quota/security failure the last complete save remains intact.
			localStorage.setItem(key,value); error=''; writes++; return true;
		}
		catch { error='This game could not be saved. Free some browser storage to save your next turn.'; return false; }
	};
	module.pokerManualSave=value=>{
		if(closed)return false;
		try
		{
			if(typeof value!=='string'||value.length>1048576)throw Error('Invalid save size');
			localStorage.setItem(manualKey,value);manual=true;error='';writes++;return true;
		}
		catch{error='This game could not be saved. Free some browser storage and try again.';return false;}
	};
	module.pokerManualLoad=()=>{
		try{return localStorage.getItem(manualKey)??'';}
		catch{error='Saved games are unavailable in this browser session.';return '';}
	};
	module.pokerStorageStatus=()=>JSON.stringify({error,writes,manual});
	session.phpPokerStorage={get status(){return {error,writes,manual};}};
	return ()=>{
		closed=true;
		for(const name of ['pokerLoad','pokerSave','pokerManualSave','pokerManualLoad','pokerStorageStatus']) delete module[name];
		delete session.phpPokerStorage;
	};
}
