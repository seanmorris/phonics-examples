/** Keep a visible sandbox awake, including during gamepad input and paused editing. */
export function activate({canvas, signal})
{
	const doc = canvas.ownerDocument, page = doc.defaultView;
	const wakeLock = page.navigator.wakeLock;

	if(!wakeLock || signal.aborted) return;

	let held, pending, retryAfter = 0;

	async function acquire()
	{
		if(signal.aborted || doc.visibilityState !== 'visible' || pending || (held && !held.released)) return;

		const attempt = {};
		pending = attempt;

		try
		{
			const lock = await wakeLock.request('screen');

			if(pending === attempt && !signal.aborted && doc.visibilityState === 'visible') held = lock;
			else await lock.release();
		}
		catch
		{
			// A browser or power policy can deny a request. Retry on later activity without polling.
			if(pending === attempt) retryAfter = Date.now() + 15000;
		}
		finally
		{
			if(pending === attempt) pending = null;
		}
	}

	function release()
	{
		pending = null;
		const lock = held;
		held = null;

		if(lock && !lock.released) return lock.release().catch(() => {});
	}

	const onActivity = () => { if(Date.now() >= retryAfter) void acquire(); };

	doc.addEventListener('visibilitychange', () => {
		if(doc.visibilityState === 'visible') void acquire();
		else void release();
	}, {signal});

	signal.addEventListener('abort', release, {once: true});
	page.addEventListener('pagehide', release, {signal});
	page.addEventListener('pageshow', acquire, {signal});
	page.addEventListener('focus', acquire, {signal});
	page.addEventListener('gamepadconnected', acquire, {signal});
	doc.addEventListener('pointerdown', onActivity, {signal});
	doc.addEventListener('keydown', onActivity, {signal});
	void acquire();

	return release;
}
