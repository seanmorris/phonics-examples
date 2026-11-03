const PRESENCE = 'org.seanmorris.phonics.presence.v1';
const INVITATION = 'org.seanmorris.phonics.invitation.v1';
const INVITATION_STATE = 'org.seanmorris.phonics.invitation_state.v1';
const SIGNAL = 'org.seanmorris.phonics.signal.v1';
const CHAT = 'org.seanmorris.phonics.chat.v1';
const GAME = 'org.seanmorris.phonics.game.v1';
const ROOM_MESSAGE = 'm.room.message';
const CONFIGURATION_KEY = 'phonics.matrix.configuration';
const OAUTH_PENDING_PREFIX = 'phonics.matrix.oauth:';

const event = (type, detail) => new CustomEvent(type, {detail});

class MatrixLobbyError extends Error
{

	constructor(code, message, {cause, status = null, details = {}} = {})
	{
		super(message, {cause});
		this.name = 'MatrixLobbyError';
		this.code = code;
		this.status = status;
		this.details = details;
	}
}

const cleanServer = value => {
	const url = new URL(value ?? 'https://matrix.org');

	if(!['https:', 'http:'].includes(url.protocol) || url.username || url.password)
	throw new MatrixLobbyError('matrix_config', 'Matrix homeserver must be HTTP(S) without credentials');

	return url.origin;
};

const peer = value => {
	if(typeof value !== 'string' || !/^@[^:]+:.+$/.test(value))
	throw new MatrixLobbyError('matrix_user', `Invalid Matrix user ID: ${value}`);

	return value;
};

const readStorage = (storage, key) => {
	try { return JSON.parse(storage?.getItem(key) ?? 'null'); } catch { return null; }
};

const base64Url = bytes => {
	let binary = '';

	for(const byte of bytes) binary += String.fromCharCode(byte);

	return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/, '');
};

const randomValue = size => {
	const bytes = new Uint8Array(size);
	crypto.getRandomValues(bytes);

	return base64Url(bytes);
};

const pkceChallenge = async verifier => base64Url(
	new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier)))
);

const callbackParameters = value => {
	const url = new URL(value);
	const fragment = new URLSearchParams(url.hash.startsWith('#') ? url.hash.slice(1) : '');
	const source = fragment.has('code') || fragment.has('error') || fragment.has('state')
		? fragment
		: url.searchParams;

	return {url, source};
};

const PRIVATE_EVENT = 'org.seanmorris.phonics.private.v1';
const CLAIM_EVENT = 'org.seanmorris.phonics.claim.v1';
const ADMISSION_EVENT = 'org.seanmorris.phonics.admission.v1';
const INVITE_DATA = 'org.seanmorris.phonics.invite_data.v1';

const SYNC_ROOM_EVENTS = Object.freeze([
	PRESENCE, INVITATION, INVITATION_STATE, SIGNAL, CHAT, GAME, ROOM_MESSAGE
	, PRIVATE_EVENT, INVITE_DATA, 'm.room.member'
]);

const SYNC_DEVICE_EVENTS = Object.freeze([CLAIM_EVENT, ADMISSION_EVENT]);

const INVITE_LIMIT = 128 * 1024;
const utf8 = new TextEncoder;
// Matrix canonicalizes JSON objects; authenticate a fixed field order, not wire key order.

const authenticatedHeader = h => utf8.encode(JSON.stringify([
	h.v, h.id, h.purpose, h.from, h.device, h.to, h.at, h.nonce
]));

const decode64 = value => {
	if(typeof value !== 'string' || !/^[\w-]+$/.test(value)) throw new Error('Invalid invitation encoding');

	return Uint8Array.from(atob(value.replaceAll('-', '+').replaceAll('_', '/')), c => c.charCodeAt(0));
};

const pause = (ms, signal) => new Promise((resolve, reject) => {
	signal?.throwIfAborted();

	const abort = () => { clearTimeout(timer); reject(signal.reason); };

	const timer = setTimeout(() => { signal?.removeEventListener('abort', abort); resolve(); }, ms);
	signal?.addEventListener('abort', abort, {once: true});
});

/** A link is a capability, never an executable URL or an account credential. */
export function parseMatrixInvite(value)
{
	const encoded = typeof value === 'string'
		? new URL(value, globalThis.location?.href ?? 'https://localhost/').hash.slice(1)
		: null;

	if(encoded !== null && (!encoded.startsWith('phonics-invite=') || encoded.length > 4096))
	throw new MatrixLobbyError('invite_link', 'Invalid invitation link');

	let data;

	try
	{
		data = encoded === null
			? structuredClone(value)
			: JSON.parse(new TextDecoder().decode(decode64(encoded.slice(15))));
	}
	catch
	{
		throw new MatrixLobbyError('invite_link', 'Invalid invitation link');
	}

	if(!data || Object.keys(data).sort().join(',') !== 'device,expiresAt,host,id,provider,secret,server,v'
		|| data.v !== 1 || data.provider !== 'matrix' || !/^[\w-]{36}$/.test(data.id)
		|| typeof data.device !== 'string' || !data.device || data.device.length > 255
		|| typeof data.secret !== 'string' || decode64(data.secret).length !== 32
		|| !Number.isSafeInteger(data.expiresAt)
	) throw new MatrixLobbyError('invite_link', 'Invalid invitation link');

	data.server = cleanServer(data.server);
	peer(data.host);

	return Object.freeze(data);
}

/** Purpose-separated authenticated encryption for the private Matrix signaling protocol. */
export class MatrixInviteCipher
{

	constructor(invitation)
	{
		this.invitation = parseMatrixInvite(invitation);
		this.keys = new Map;
		this.seen = new Map;
	}

	async key(purpose, from)
	{
		const label = `${purpose}:${from}`;

		if(this.keys.size >= 64 && !this.keys.has(label)) this.keys.delete(this.keys.keys().next().value);

		if(!this.keys.has(label))
		this.keys.set(label, (async () => {
			const material = await crypto.subtle.importKey('raw', decode64(this.invitation.secret), 'HKDF', false, [
				'deriveKey'
			]);

			return crypto.subtle.deriveKey({
				name: 'HKDF'
				, hash: 'SHA-256'
				, salt: utf8.encode(this.invitation.id)
				, info: utf8.encode(`phonics.matrix.v1:${label}`)
			}, material, {name: 'AES-GCM', length: 256}, false, [
				'encrypt', 'decrypt'
			]
			);
		})());

		return this.keys.get(label);
	}

	async seal(purpose, from, device, to, value)
	{
		const bytes = utf8.encode(JSON.stringify(value));

		if(bytes.length > INVITE_LIMIT) throw new MatrixLobbyError('invite_size', 'Invitation message is too large');

		const header = {
			v: 1
			, id: this.invitation.id
			, purpose
			, from
			, device
			, to
			, at: Date.now()
			, nonce: randomValue(12)
		};

		const ciphertext = await crypto.subtle.encrypt({
			name: 'AES-GCM'
			, iv: decode64(header.nonce)
			, additionalData: authenticatedHeader(header)
		}, await this.key(purpose, from), bytes
		);

		return {header, ciphertext: base64Url(new Uint8Array(ciphertext))};
	}

	async open(envelope, {purpose, sender, device, to, stored = false})
	{
		if(JSON.stringify(envelope).length > INVITE_LIMIT * 2) throw new Error('Oversized invitation message');

		const h = envelope?.header;

		if(!h || Object.keys(h).sort().join(',') !== 'at,device,from,id,nonce,purpose,to,v'
			|| h.v !== 1 || h.id !== this.invitation.id || h.purpose !== purpose || h.from !== sender
			|| (device !== undefined && h.device !== device) || h.to !== to
			|| !Number.isSafeInteger(h.at) || h.at > Date.now() + 60_000
			|| (!stored && h.at < Date.now() - 120_000) || decode64(h.nonce).length !== 12
		)
		throw new Error('Invalid invitation sender or context');

		const replay = `${sender}:${h.nonce}`;

		if(this.seen.has(replay)) return null;

		const plaintext = await crypto.subtle.decrypt({
			name: 'AES-GCM'
			, iv: decode64(h.nonce)
			, additionalData: authenticatedHeader(h)
		}, await this.key(purpose, sender), decode64(envelope.ciphertext)
		);

		const result = JSON.parse(new TextDecoder().decode(plaintext));

		if(this.seen.has(replay)) return null;

		this.seen.set(replay, h.at);

		for(const [key, at] of this.seen) if(at < Date.now() - 120_000) this.seen.delete(key);

		if(this.seen.size > 4096) throw new Error('Invitation replay window exhausted');

		return result;
	}
}

/** Matrix Client-Server API implementation of the Phonics lobby provider contract. */
export class MatrixLobbyProvider extends EventTarget
{

	constructor(
		{
			homeserver = 'https://matrix.org'
			, room = null
			, accessToken = null
			, userId = null
			, deviceId = null
			, displayName = null
			, fetch: fetcher = globalThis.fetch
			, storage = globalThis.localStorage
			, oauthStorage = globalThis.sessionStorage ?? storage
			, location: browserLocation = globalThis.location
			, history: browserHistory = globalThis.history
			, clientName = 'Phonics'
			, clientUri = null
			, authorizeHomeserver = cleanServer
			, signal
			, presenceTtl = 45_000
			, heartbeat = 15_000
			, syncTimeout = 10_000
			, syncMinInterval = 2_000
			, syncEmptyDelay = 2_000
			, syncEmptyMaxDelay = 60_000
			, invitationTtl = 90_000
			, signalBatch = 250
			, guest = false
			, locks = globalThis.navigator?.locks
			, inviteLifetime = 15 * 60_000
			, configurationKey = CONFIGURATION_KEY
		} = {}
	) {
		super();

		if(guest) storage = oauthStorage = null;

		this.guest = guest;
		this.locks = locks;
		this.inviteLifetime = inviteLifetime;
		this.inviteRooms = new Map;
		this.cleanupTasks = new Map;
		this.inviteLinkCapabilityResult = null;
		this.inviteLinkCapabilityTask = null;

		this.fetch = (...args) => fetcher(...args);
		this.storage = storage;
		this.oauthStorage = oauthStorage;
		this.location = browserLocation;
		this.history = browserHistory;
		this.clientName = String(clientName);
		this.clientUri = clientUri;
		this.configurationKey = String(configurationKey || CONFIGURATION_KEY);

		this.authorizeHomeserver = value => cleanServer(authorizeHomeserver(value));

		this.defaults = Object.freeze({
			homeserver: this.authorizeHomeserver(homeserver)
			, room: room === null ? null : String(room)
		});

		const configured = readStorage(storage, this.configurationKey) ?? {};
		this.homeserver = this.authorizeHomeserver(configured.homeserver ?? homeserver);
		this.room = configured.room ?? room;
		this.room = this.room === null ? null : String(this.room);
		this.storageKey = `phonics.matrix.session:${this.homeserver}`;
		const saved = readStorage(storage, this.storageKey) ?? {};

		this.accessToken = accessToken ?? saved.accessToken ?? null;
		this.userId = userId ?? saved.userId ?? null;
		this.deviceId = deviceId ?? saved.deviceId ?? null;
		this.displayName = displayName ?? saved.displayName ?? this.userId;
		this.refreshToken = saved.refreshToken ?? null;
		this.expiresAt = saved.expiresAt ?? 0;
		this.clientId = saved.clientId ?? null;
		this.tokenEndpoint = saved.tokenEndpoint ?? null;
		this.revocationEndpoint = saved.revocationEndpoint ?? null;
		this.authApi = saved.authApi ?? (this.refreshToken ? 'oauth' : 'legacy');
		this.presenceTtl = presenceTtl;
		this.heartbeat = heartbeat;
		this.syncTimeout = Math.max(1, Number(syncTimeout) || 10_000);
		this.syncMinInterval = Math.max(1, Number(syncMinInterval) || 2_000);
		this.syncEmptyDelay = Math.max(this.syncMinInterval, Number(syncEmptyDelay) || 2_000);
		this.syncEmptyMaxDelay = Math.max(this.syncEmptyDelay, Number(syncEmptyMaxDelay) || 60_000);
		this.invitationTtl = invitationTtl;
		this.signalBatch = Math.max(0, Number(signalBatch) || 0);
		this.signalQueues = new Map;
		this.externalSignal = signal ?? null;
		this.resetConnectionSignal();
		this.roomId = null;
		this.since = null;
		this.connected = false;
		this.closed = false;
		this.peers = new Map;
		this.invitations = new Map;
		this.lastInvite = new Map;
		this.transaction = 0;
		this.activity = null;
	}

	/** Create a fresh connection lifetime while retaining the provider's external lifetime. */
	resetConnectionSignal()
	{
		this.controller = new AbortController;
		this.signal = this.externalSignal
			? AbortSignal.any([this.externalSignal, this.controller.signal])
			: this.controller.signal;
	}

	profile()
	{
		return Object.freeze(
			{
				id: this.userId
				, displayName: this.displayName ?? this.userId
				, homeserver: this.homeserver
				, activity: this.activity
			}
		);
	}

	configuration()
	{
		return Object.freeze({homeserver: this.homeserver, room: this.room});
	}

	/** Persist user-selected lobby defaults without accepting credentials. */
	configure({homeserver = this.homeserver, room = this.room} = {})
	{
		const nextHomeserver = this.authorizeHomeserver(homeserver);
		const nextRoom = typeof room === 'string' ? room.trim() : '';
		const homeserverChanged = nextHomeserver !== this.homeserver;

		if(!nextRoom) throw new MatrixLobbyError('matrix_config', 'Matrix lobby channel is required');

		try
		{
			this.storage?.setItem(this.configurationKey, JSON.stringify({
				homeserver: nextHomeserver
				, room: nextRoom
			}));
		}
		catch
		{
		}

		if(this.connected)
		return Object.freeze({homeserver: nextHomeserver, room: nextRoom, pending: true});

		this.homeserver = nextHomeserver;
		this.room = nextRoom;

		if(homeserverChanged)
		{
			this.inviteLinkCapabilityResult = null;
			this.inviteLinkCapabilityTask = null;
		}

		this.storageKey = `phonics.matrix.session:${this.homeserver}`;
		const saved = readStorage(this.storage, this.storageKey) ?? {};
		this.accessToken = saved.accessToken ?? null;
		this.refreshToken = saved.refreshToken ?? null;
		this.expiresAt = saved.expiresAt ?? 0;
		this.clientId = saved.clientId ?? null;
		this.tokenEndpoint = saved.tokenEndpoint ?? null;
		this.revocationEndpoint = saved.revocationEndpoint ?? null;
		this.authApi = saved.authApi ?? (this.refreshToken ? 'oauth' : 'legacy');
		this.userId = saved.userId ?? null;
		this.deviceId = saved.deviceId ?? null;
		this.displayName = saved.displayName ?? this.userId;

		return this.configuration();
	}

	async loginFlows()
	{
		const result = await this.request('GET', '/_matrix/client/v3/login', {auth: false});

		return result.flows ?? [];
	}

	async loginToken(token, deviceName = 'Phonics browser')
	{
		const result = await this.request('POST', '/_matrix/client/v3/login', {
			auth: false
			, body: {
				type: 'm.login.token'
				, token: String(token)
				, initial_device_display_name: deviceName
			}
		});

		return this.useSession(result);
	}

	async oauthMetadata()
	{
		const metadata = await this.request('GET', '/_matrix/client/v1/auth_metadata', {auth: false});
		const required = [
			'authorization_endpoint'
			, 'registration_endpoint'
			, 'token_endpoint'
			, 'revocation_endpoint'
		];

		for(const name of required)
		if(typeof metadata[name] !== 'string')
		throw new MatrixLobbyError('matrix_oauth_metadata', `Matrix OAuth metadata has no ${name}`);

		if(!metadata.code_challenge_methods_supported?.includes('S256'))
		throw new MatrixLobbyError('matrix_oauth_metadata', 'Matrix OAuth server does not support PKCE S256');

		return metadata;
	}

	/** Begin Matrix OAuth 2.0 authorization-code login with dynamic registration and PKCE. */
	async beginOAuth(returnUrl = this.location?.href)
	{
		if(!returnUrl) throw new MatrixLobbyError('matrix_oauth', 'Matrix OAuth needs a browser return URL');

		const metadata = await this.oauthMetadata();
		const callback = new URL(returnUrl);
		callback.hash = '';

		for(const name of ['code','state','error','error_description','error_uri']) callback.searchParams.delete(name);

		const local = callback.protocol === 'http:'
			&& ['localhost','127.0.0.1','[::1]'].includes(callback.hostname);

		if(callback.protocol !== 'https:' && !local)
		throw new MatrixLobbyError('matrix_oauth', 'Matrix OAuth requires HTTPS or an HTTP loopback address');

		const applicationType = local ? 'native' : 'web';
		const registeredRedirect = new URL(callback);

		if(local) registeredRedirect.port = '';

		const clientUri = this.clientUri ?? (callback.protocol === 'https:'
			? new URL('/', callback).href
			: 'https://seanmorris.github.io/phonics/'
		);

		const registration = await this.requestUrl('POST', metadata.registration_endpoint, {
			auth: false
			, body: {
				application_type: applicationType
				, client_name: this.clientName
				, client_uri: clientUri
				, redirect_uris: [registeredRedirect.href]
				, token_endpoint_auth_method: 'none'
				, response_types: ['code']
				, grant_types: ['authorization_code','refresh_token']
			}
		});

		if(typeof registration.client_id !== 'string' || !registration.client_id)
		throw new MatrixLobbyError('matrix_oauth_registration', 'Matrix OAuth registration returned no client ID');

		const state = randomValue(32);
		const verifier = randomValue(64);
		const deviceId = (this.deviceId ?? crypto.randomUUID()).replaceAll('-', '').toUpperCase();
		const pending = {
			homeserver: this.homeserver
			, room: this.room
			, state
			, verifier
			, deviceId
			, clientId: registration.client_id
			, redirectUri: callback.href
			, tokenEndpoint: metadata.token_endpoint
			, revocationEndpoint: metadata.revocation_endpoint
			, createdAt: Date.now()
		};

		try
		{
			this.oauthStorage?.setItem(OAUTH_PENDING_PREFIX + state, JSON.stringify(pending));
		}
		catch(error)
		{
			throw new MatrixLobbyError(
				'matrix_oauth_storage'
				, 'Cannot retain Matrix OAuth state for the redirect'
				, {cause: error}
			);
		}

		const authorization = new URL(metadata.authorization_endpoint);
		authorization.searchParams.set('client_id', registration.client_id);
		authorization.searchParams.set('response_type', 'code');
		authorization.searchParams.set('response_mode', 'fragment');
		authorization.searchParams.set('redirect_uri', callback.href);
		authorization.searchParams.set('scope', `urn:matrix:client:api:* urn:matrix:client:device:${deviceId}`);
		authorization.searchParams.set('state', state);
		authorization.searchParams.set('code_challenge', await pkceChallenge(verifier));
		authorization.searchParams.set('code_challenge_method', 'S256');

		return authorization.href;
	}

	/** Exchange an OAuth callback, optionally cleaning it from the current browser history entry. */
	async completeOAuth(value = this.location?.href, {replaceHistory = true} = {})
	{
		if(!value) return false;

		const {url, source} = callbackParameters(value);
		const state = source.get('state');

		if(!state || (!source.has('code') && !source.has('error'))) return false;

		const key = OAUTH_PENDING_PREFIX + state;
		const pending = readStorage(this.oauthStorage, key);

		try { this.oauthStorage?.removeItem(key); } catch {}

		url.hash = '';

		for(const name of ['code','state','error','error_description','error_uri']) url.searchParams.delete(name);

		if(replaceHistory)
		try { this.history?.replaceState?.(this.history.state ?? null, '', url.href); } catch {}

		if(!pending || pending.state !== state || pending.homeserver !== this.homeserver
			|| Date.now() - pending.createdAt > 10 * 60_000
		)
		throw new MatrixLobbyError('matrix_oauth_state', 'Matrix OAuth callback state is missing or expired');

		if(source.has('error'))
		throw new MatrixLobbyError(
			'matrix_oauth_denied'
			, source.get('error_description') || `Matrix OAuth was denied: ${source.get('error')}`
		);

		const result = await this.requestUrl('POST', pending.tokenEndpoint, {
			auth: false
			, form: {
				grant_type: 'authorization_code'
				, code: source.get('code')
				, redirect_uri: pending.redirectUri
				, client_id: pending.clientId
				, code_verifier: pending.verifier
			}
		});

		this.useOAuthSession(result, pending);

		return true;
	}

	async connect()
	{
		if(this.closed) throw new MatrixLobbyError('lobby_closed', 'Matrix lobby is closed');

		if(this.connected) return this.profile();

		if(!this.accessToken) await this.completeOAuth();

		if(!this.accessToken)
		throw new MatrixLobbyError('matrix_auth_required', 'Continue with Matrix to enter the lobby');

		if(!this.room)
		throw new MatrixLobbyError('matrix_config', 'This Matrix-enabled game must declare its own lobby chat room.');

		const who = await this.request('GET', '/_matrix/client/v3/account/whoami');
		this.userId = peer(who.user_id);
		this.deviceId = who.device_id ?? this.deviceId;
		this.displayName ??= this.userId;
		this.save();

		let joined;

		try
		{
			joined = await this.request('POST', `/_matrix/client/v3/join/${encodeURIComponent(this.room)}`, {body: {}});
		}
		catch(error)
		{
			if(error.code !== 'M_NOT_FOUND') throw error;

			throw new MatrixLobbyError(
				'matrix_lobby_missing'
				, `Matrix lobby ${this.room} does not exist. Create it as a public chat room, not a Space.`
				, {cause: error, status: error.status, details: error.details}
			);
		}

		const creation = await this.request(
			'GET'
			, `/_matrix/client/v3/rooms/${encodeURIComponent(joined.room_id)}/state/m.room.create/`
		);

		if(creation.type === 'm.space')
		throw new MatrixLobbyError(
			'matrix_lobby_space'
			, `Matrix lobby ${this.room} is a Space. Configure a chat room instead.`
		);

		this.roomId = joined.room_id;
		this.connected = true;
		await this.announcePresence();

		this.heartbeatTimer = setInterval(() => {
			this.announcePresence().catch(
				error => this.dispatchEvent(event(this.recoverable(error) ? 'signalingstatus' : 'error', error))
			);
		}, this.heartbeat
		);

		this.startSync();
		void this.retryCleanup();
		this.cleanupTimer = setInterval(() => void this.retryCleanup(), 30_000);

		return this.profile();
	}

	/**
	 * Verify that this homeserver can create the isolated guest session required by private links.
	 * Matrix does not advertise guest registration as a read-only capability, so the probe registers
	 * one disposable guest and immediately revokes its token. The result is retained for this provider
	 * session so opening and rendering the lobby never repeats the mutation.
	 */
	async inviteLinkCapability({refresh = false} = {})
	{
		this.requireConnected();

		if(this.guest || !this.deviceId)
		return Object.freeze({supported: false, reason: 'host-session-required'});

		if(!refresh && this.inviteLinkCapabilityResult) return this.inviteLinkCapabilityResult;

		if(!refresh && this.inviteLinkCapabilityTask) return this.inviteLinkCapabilityTask;

		const homeserver = this.homeserver;

		this.inviteLinkCapabilityTask = (async () => {
			let registration;

			try
			{
				registration = await this.request(
					'POST'
					, '/_matrix/client/v3/register?kind=guest'
					, {
						auth: false
						, body: {initial_device_display_name: `${this.clientName} invite capability check`}
						, signal: AbortSignal.any([this.signal, AbortSignal.timeout(15_000)])
					}
				);
			}
			catch(error)
			{
				return Object.freeze({
					supported: false
					, reason: error?.status === 403 ? 'guest-registration-disabled' : 'guest-registration-unverified'
				});
			}

			if(!registration.access_token || !registration.user_id || !registration.device_id)
			return Object.freeze({supported: false, reason: 'guest-onboarding-required'});

			try
			{
				await this.revokeGuestProbe(registration.access_token);
			}
			catch
			{
				return Object.freeze({supported: false, reason: 'guest-probe-cleanup-failed'});
			}

			return Object.freeze({supported: true, reason: null});
		})();

		try
		{
			const result = await this.inviteLinkCapabilityTask;

			if(this.homeserver === homeserver) this.inviteLinkCapabilityResult = result;

			return result;
		}
		finally
		{
			this.inviteLinkCapabilityTask = null;
		}
	}

	/** Revoke the disposable guest token without replacing or exposing the host session. */
	async revokeGuestProbe(accessToken)
	{
		let response;

		try
		{
			response = await this.fetch(new URL('/_matrix/client/v3/logout', this.homeserver), {
				method: 'POST'
				, headers: {
					'Accept': 'application/json'
					, 'Content-Type': 'application/json'
					, 'Authorization': `Bearer ${accessToken}`
				}
				, body: '{}'
				, signal: AbortSignal.any([this.signal, AbortSignal.timeout(15_000)])
				, redirect: 'error'
			});
		}
		catch(error)
		{
			throw new MatrixLobbyError('matrix_transport', 'Matrix guest capability cleanup failed', {cause: error});
		}

		if(!response.ok)
		throw new MatrixLobbyError(
			'matrix_guest_cleanup'
			, `Matrix guest capability cleanup returned HTTP ${response.status}`
			, {status: response.status}
		);
	}

	/** Freeze an encrypted invitation in a dedicated room. No SDP is generated here. */
	async createInviteLink(payload, {url = this.location?.href} = {})
	{
		this.requireConnected();

		if(this.guest || !this.deviceId)
		throw new MatrixLobbyError('invite_host', 'Sign in as a host to create an invitation');

		const capability = await this.inviteLinkCapability();

		if(!capability.supported)
		throw new MatrixLobbyError(
			'matrix_guest_unavailable'
			, 'This homeserver does not support anonymous invitation links.'
			, {details: {reason: capability.reason}}
		);

		await this.cancelInviteLink();

		const invitation = parseMatrixInvite({
			v: 1
			, provider: 'matrix'
			, server: this.homeserver
			, host: this.userId
			, device: this.deviceId
			, id: crypto.randomUUID()
			, secret: randomValue(32)
			, expiresAt: Date.now() + this.inviteLifetime
		});

		const powers = this.invitePowers();

		const created = await this.request('POST', '/_matrix/client/v3/createRoom', {
			body: {
				visibility: 'private'
				, preset: 'private_chat'
				, room_version: '11'
				, creation_content: {'m.federate': false}
				, power_level_content_override: powers
				, initial_state: [
					{type: 'm.room.join_rules', state_key: '', content: {join_rule: 'invite'}}
					, {type: 'm.room.guest_access', state_key: '', content: {guest_access: 'can_join'}}
					, {type: 'm.room.history_visibility', state_key: '', content: {history_visibility: 'shared'}}
					, {type: 'm.room.retention', state_key: '', content: {max_lifetime: 1_800_000}}
				]
			}
		});

		const state = {
			invitation
			, abort: new AbortController
			, roomId: created.room_id
			, cipher: new MatrixInviteCipher(invitation)
			, host: true
			, payload: structuredClone(payload)
			, closed: false
			, events: new Set
		};

		this.linkState = state;
		this.inviteRooms.set(state.roomId, state);

		try
		{
			await this.ownRoom(state);
			this.journalRoom(state.roomId, 'active');
			const content = await state.cipher.seal('invitation', this.userId, this.deviceId, '*', payload);
			state.eventId = (await this.roomSend(state, INVITE_DATA, content)).event_id;
			const link = new URL(url);
			link.hash = `phonics-invite=${base64Url(utf8.encode(JSON.stringify(invitation)))}`;

			state.timer = setTimeout(
				() => { if(!state.claim) void this.cancelInviteLink('Invitation expired'); }
				, this.inviteLifetime
			);

			this.dispatchEvent(event('invitestatus', {state: 'waiting', expiresAt: invitation.expiresAt}));

			return {
				id: invitation.id
				, from: this.userId
				, to: null
				, payload
				, expiresAt: invitation.expiresAt
				, url: link.href
				, privateLink: true
			};
		}
		catch(error)
		{
			await this.cancelInviteLink();
			throw error;
		}
	}

	invitePowers(closed = false)
	{
		return {
			users: {[this.userId]: 100}
			, users_default: 0
			, events_default: 100
			, state_default: 100
			, invite: 100
			, kick: 100
			, ban: 100
			, redact: 100
			, events: {[PRIVATE_EVENT]: closed ? 100 : 0, [INVITE_DATA]: 100}
		};
	}

	/** A fresh guest provider must be constructed without persistent storage. */
	async joinInviteLink(value)
	{
		if(!this.guest || this.accessToken)
		throw new MatrixLobbyError('invite_guest', 'Invitation needs an isolated guest session');

		const invitation = parseMatrixInvite(value);

		if(invitation.expiresAt <= Date.now())
		throw new MatrixLobbyError('invitation_expired', 'This invitation has expired. Ask the host for a new link.');

		if(this.authorizeHomeserver(invitation.server) !== this.homeserver)
		throw new Error('Invitation homeserver differs');

		const state = {
			invitation
			, abort: new AbortController
			, cipher: new MatrixInviteCipher(invitation)
			, host: false
			, closed: false
			, peerId: invitation.host
			, peerDevice: invitation.device
			, claimId: crypto.randomUUID()
			, events: new Set
		};

		this.linkState = state;
		const pending = Promise.withResolvers();
		state.admission = pending;
		pending.promise.catch(() => {});

		state.timer = setTimeout(
			() => pending.reject(
				new MatrixLobbyError(
					'invite_unavailable'
					, 'The host did not admit this invitation. It may be cancelled or already claimed.'
				)
			)
			, 120_000
		);

		try
		{
			let registration;

			try
			{
				registration = await this.request(
					'POST'
					, '/_matrix/client/v3/register?kind=guest'
					, {
						auth: false
						, body: {}
						, signal: AbortSignal.any([this.signal, state.abort.signal, AbortSignal.timeout(120_000)])
					}
				);
			}
			catch(error)
			{
				throw new MatrixLobbyError(
					'matrix_guest_unavailable'
					, 'This homeserver does not allow automatic guest registration. The host must select a guest-enabled homeserver.'
					, {cause: error}
				);
			}

			if(!registration.access_token || !registration.device_id)
			throw new MatrixLobbyError(
				'matrix_guest_unavailable'
				, 'Guest registration requires extra onboarding on this homeserver'
			);

			if(state.closed) throw new Error('Invitation cancelled');

			this.accessToken = registration.access_token;
			this.userId = peer(registration.user_id);
			this.deviceId = registration.device_id;
			this.displayName = 'Guest';
			this.connected = true;
			this.startSync();

			const claim = async () => {
				if(state.closed || state.admissionComplete) return;

				const content = await state.cipher.seal(
					'claim'
					, this.userId
					, this.deviceId
					, invitation.host
					, {claimId: state.claimId}
				);

				await this.deviceSend(invitation.host, invitation.device, CLAIM_EVENT, content);
			};

			await claim();
			state.claimTimer = setInterval(() => claim().catch(() => {}), 5000);
			const payload = await pending.promise;
			clearTimeout(state.timer);
			clearInterval(state.claimTimer);
			state.timer = setTimeout(() => void this.cancelInviteLink('Resource setup timed out'), 15 * 60_000);

			return {
				id: invitation.id
				, from: invitation.host
				, to: this.userId
				, payload
				, expiresAt: Date.now() + 15 * 60_000
				, privateLink: true
			};
		}
		catch(error)
		{
			await this.cancelInviteLink(error.message);
			throw error;
		}
	}

	async deviceSend(user, device, type, content)
	{
		return this.request('PUT', `/_matrix/client/v3/sendToDevice/${type}/${crypto.randomUUID()}`, {
			body: {messages: {[user]: {[device]: content}}}
		});
	}

	async consumeDevice(item)
	{
		const state = this.linkState;

		if(!state || state.closed || ![CLAIM_EVENT, ADMISSION_EVENT].includes(item.type)) return;

		try
		{
			if(state.host && item.type === CLAIM_EVENT)
			{
				if(state.invitation.expiresAt <= Date.now() && !state.claim) return;

				const content = await state.cipher.open(
					item.content
					, {purpose: 'claim', sender: item.sender, to: this.userId}
				);

				if(state.closed || !content || typeof content.claimId !== 'string' || content.claimId.length > 100)
				return;

				const device = item.content.header.device;

				if(typeof device !== 'string' || !device || device.length > 255) return;

				if(state.claim && (state.peerId !== item.sender || state.peerDevice !== device
					|| state.claim !== content.claimId
				))
				return;

				// Reserve synchronously after authentication, before the first Matrix mutation.
				if(!state.claim)
				{
					clearTimeout(state.timer);
					state.timer = setTimeout(() => void this.cancelInviteLink('Resource setup timed out'), 15 * 60_000);
				}

				state.claim = content.claimId;
				state.peerId = item.sender;
				state.peerDevice = device;

				if(!state.admitted)
				{
					state.admitting ??= this.request(
						'POST'
						, `/_matrix/client/v3/rooms/${encodeURIComponent(state.roomId)}/invite`
						, {
							body: {user_id: item.sender}
							, signal: AbortSignal.any([this.signal, state.abort.signal, AbortSignal.timeout(30_000)])
						}
					).finally(() => { state.admitting = null; });

					await state.admitting;

					if(state.closed) return;

					state.admitted = true;
				}

				const reply = await state.cipher.seal(
					'admission'
					, this.userId
					, this.deviceId
					, state.peerId
					, {claimId: state.claim, roomId: state.roomId, eventId: state.eventId}
				);

				if(state.closed) return;

				await this.deviceSend(state.peerId, state.peerDevice, ADMISSION_EVENT, reply);
				this.dispatchEvent(event('invitestatus', {state: 'admitted'}));
			}
			else if(!state.host && item.type === ADMISSION_EVENT && !state.admissionComplete
				&& item.sender === state.peerId
			){
				const reply = await state.cipher.open(
					item.content
					, {purpose: 'admission', sender: state.peerId, device: state.peerDevice, to: this.userId}
				);

				if(state.closed || item.sender !== state.peerId || !reply || reply.claimId !== state.claimId) return;

				state.roomId = reply.roomId;

				const joined = await this.request(
					'POST'
					, `/_matrix/client/v3/join/${encodeURIComponent(reply.roomId)}`
					, {
						body: {}
						, signal: AbortSignal.any([this.signal, state.abort.signal, AbortSignal.timeout(30_000)])
					}
				);

				if(state.closed) return;

				if(joined.room_id !== reply.roomId) throw new Error('Invitation room differs');

				state.roomId = reply.roomId;
				this.inviteRooms.set(state.roomId, state);

				const stored = await this.request(
					'GET'
					, `/_matrix/client/v3/rooms/${encodeURIComponent(
						state.roomId
					)}/event/${encodeURIComponent(
						reply.eventId
					)}`
				);

				if(stored.sender !== state.peerId || stored.type !== INVITE_DATA)
				throw new Error('Invalid invitation event');

				const payload = await state.cipher.open(
					stored.content
					, {purpose: 'invitation', sender: state.peerId, device: state.peerDevice, to: '*', stored: true}
				);

				if(state.closed) return;

				if(!payload) throw new Error('Missing invitation');

				state.admissionComplete = true;
				state.admission.resolve(payload);
			}
		}
		catch(error)
		{
			// Unauthenticated ciphertext must never stop the sync loop or reserve admission.
			if(error instanceof MatrixLobbyError && error.status)
			this.dispatchEvent(event('signalingstatus', {state: 'retrying', code: error.code}));
		}
	}

	async roomSend(state, type, content, transaction = crypto.randomUUID())
	{
		if(state.closed) throw new MatrixLobbyError('invite_closed', 'Invitation is closed');

		const result = await this.request(
			'PUT'
			, `/_matrix/client/v3/rooms/${encodeURIComponent(state.roomId)}/send/${type}/${transaction}`
			, {
				body: content
				, signal: AbortSignal.any([this.signal, state.abort.signal, AbortSignal.timeout(30_000)])
			}
		);

		if(result.event_id) this.recordEvent(state, result.event_id);

		return result;
	}

	async privateSend(kind, payload)
	{
		const state = this.linkState;

		if(!state || state.closed || !state.peerId)
		throw new MatrixLobbyError('invite_closed', 'Private invitation is unavailable');

		if((state.queued ?? 0) >= 128) throw new MatrixLobbyError('invite_queue', 'Private signaling queue is full');

		state.queued = (state.queued ?? 0) + 1;
		const task = (state.writes ?? Promise.resolve()).then(() => this.writePrivate(state, kind, payload));
		state.writes = task.catch(() => {});

		try { return await task; } finally { state.queued--; }
	}

	async writePrivate(state, kind, payload)
	{
		if(state.closed) throw new MatrixLobbyError('invite_closed', 'Private invitation is unavailable');

		const content = await state.cipher.seal('message', this.userId, this.deviceId, state.peerId, {kind, payload});
		const transaction = crypto.randomUUID();
		const deadline = Date.now() + 30_000;

		for(; ;)
		{
			try
			{
				return await this.roomSend(state, PRIVATE_EVENT, content, transaction);
			}
			catch(error)
			{
				if(state.closed || this.signal.aborted || Date.now() >= deadline || !this.recoverable(error))
				throw error;

				await pause(
					Math.min(5000, error.details?.retry_after_ms ?? 1000)
					, AbortSignal.any([this.signal, state.abort.signal])
				);
			}
		}
	}

	async consumePrivate(state, item)
	{
		if(!state.closed && state.host && item.type === 'm.room.member' && item.state_key === state.peerId
			&& ['leave', 'ban'].includes(item.content?.membership)
		){
			this.dispatchEvent(event('inviteclosed', {reason: 'Guest left'}));
			void this.cancelInviteLink();

			return;
		}

		if(item.type !== PRIVATE_EVENT || !item.content?.ciphertext || item.sender !== state.peerId || state.closed)
		return;

		let message;

		try
		{
			message = await state.cipher.open(
				item.content
				, {purpose: 'message', sender: state.peerId, device: state.peerDevice, to: this.userId}
			);
		}
		catch
		{
			return;
		}

		if(!message || state.closed) return;

		this.recordEvent(state, item.event_id);

		if(message.kind === 'ready' && state.host && !state.ready)
		{
			state.ready = true;
			clearTimeout(state.timer);

			this.dispatchEvent(
				event('invitationstate', {id: state.invitation.id, state: 'accepted', peerId: state.peerId})
			);
		}
		else if(message.kind === 'signal')
		this.dispatchEvent(event('signal', {from: item.sender, payload: message.payload}));
		else if(message.kind === 'signals' && Array.isArray(message.payload))
		for(const payload of message.payload.slice(0, 64))
		this.dispatchEvent(event('signal', {from: item.sender, payload}));
		else if(message.kind === 'close')
		{
			this.dispatchEvent(event('inviteclosed', {reason: 'Peer left the invitation'}));
			void this.cancelInviteLink('Peer left');
		}
	}

	async readyInviteLink()
	{
		clearTimeout(this.linkState?.timer);
		await this.privateSend('ready', {});
	}

	async cancelInviteLink(reason = 'Invitation cancelled')
	{
		const state = this.linkState;

		if(!state) return;

		this.linkState = null;
		clearTimeout(state.timer);
		clearInterval(state.claimTimer);
		state.closed = true;
		state.abort.abort(new MatrixLobbyError('invite_cancelled', reason));
		state.admission?.reject(new MatrixLobbyError('invite_cancelled', reason));
		this.inviteRooms.delete(state.roomId);
		this.dispatchEvent(event('invitestatus', {state: 'closed', reason}));

		if(state.host)
		{
			this.journalRoom(state.roomId, 'pending');

			return this.cleanupRoom(state.roomId, state);
		}

		if(state.roomId)
		{
			try
			{
				await this.request(
					'POST'
					, `/_matrix/client/v3/rooms/${encodeURIComponent(state.roomId)}/leave`
					, {body: {}, cleanup: true, signal: AbortSignal.timeout(10_000)}
				);
			}
			catch
			{
			}
		}

		this.connected = false;
		this.controller.abort(new MatrixLobbyError('invite_closed', reason));
		this.accessToken = this.userId = this.deviceId = null;
	}

	journalKey() { return `phonics.matrix.cleanup:${this.homeserver}:${this.userId}`; }

	readJournal()
	{
		let stored = this.cleanupJournal ?? {};

		try
		{
			if(this.storage && typeof this.storage.key === 'function')
			{
				stored = {};
				const prefix = this.journalKey() + ':room:';

				for(let index = 0; index < this.storage.length; index++)
				{
					const key = this.storage.key(index);

					if(key?.startsWith(prefix)) stored[key.slice(prefix.length)] = readStorage(this.storage, key);
				}
			}
			else stored = readStorage(this.storage, this.journalKey()) ?? stored;
		}
		catch
		{
		}

		for(const [room, record] of this.journalFallback ?? [])
		{
			if(record) stored[room] = record;
			else delete stored[room];
		}

		return Object.fromEntries(
			Object.entries(stored).filter(
				([room, record]) => room.startsWith('!') && ['active', 'pending', 'redacted'].includes(record?.status)
			)
		);
	}

	persistRoom(roomId, record)
	{
		try
		{
			// Separate keys prevent two live tabs from overwriting each other's obligations.
			if(this.storage && typeof this.storage.key === 'function')
			{
				const key = this.journalKey() + ':room:' + roomId;

				if(record) this.storage.setItem(key, JSON.stringify(record));
				else this.storage.removeItem(key);
			}
			else this.storage?.setItem(this.journalKey(), JSON.stringify(this.cleanupJournal));

			this.journalFallback?.delete(roomId);
		}
		catch
		{
			(this.journalFallback ??= new Map).set(roomId, record);
		}
	}

	recordEvent(state, eventId)
	{
		if(!state.host || typeof eventId !== 'string' || state.events.has(eventId)) return;

		state.events.add(eventId);

		if(state.events.size > 512) state.events.delete(state.events.values().next().value);

		const journal = this.readJournal();
		const record = journal[state.roomId];

		if(!record) return;

		record.events = [...new Set([...(record.events ?? []), eventId])].slice(-512);
		this.cleanupJournal = journal;
		this.persistRoom(state.roomId, record);
	}

	journalRoom(roomId, status)
	{
		const journal = this.cleanupJournal = this.readJournal();

		if(status === 'done') delete journal[roomId];
		else journal[roomId] = {...journal[roomId], status, updatedAt: Date.now()};

		this.persistRoom(roomId, journal[roomId]);
	}

	async ownRoom(state)
	{
		if(!this.locks?.request) return;

		const acquired = Promise.withResolvers(), release = Promise.withResolvers();
		state.releaseLock = release.resolve;

		state.lockTask = this.locks.request(`${this.journalKey()}:${state.roomId}`, async () => {
			acquired.resolve();
			await release.promise;
		});

		state.lockTask.catch(acquired.reject);
		await acquired.promise;
	}

	async cleanupRoom(roomId, owned = null)
	{
		if(this.cleanupTasks.has(roomId)) return this.cleanupTasks.get(roomId);

		const run = async () => {
			const signal = AbortSignal.timeout(10_000);

			const request = (method, suffix, body) => this.request(
				method
				, `/_matrix/client/v3/rooms/${encodeURIComponent(roomId)}/${suffix}`
				, {body, signal, cleanup: true}
			);

			this.dispatchEvent(event('cleanupstatus', {state: 'cleaning'}));

			try
			{
				this.journalRoom(roomId, 'pending');
				await request('PUT', 'state/m.room.power_levels', this.invitePowers(true));
				await request('PUT', 'state/m.room.guest_access', {guest_access: 'forbidden'});

				for(const id of this.readJournal()[roomId]?.events ?? [])
				{
					try
					{
						await request('PUT', `redact/${encodeURIComponent(id)}/${crypto.randomUUID()}`, {});
					}
					catch(error)
					{
						if(error.code !== 'M_NOT_FOUND') throw error;
					}
				}

				let from, count = 0;
				const seen = new Set;

				do
				{
					const query = new URLSearchParams({dir: 'b', limit: '100'});

					if(from) query.set('from', from);

					const page = await request('GET', `messages?${query}`);

					for(const item of page.chunk ?? [])
					if([PRIVATE_EVENT, INVITE_DATA].includes(item.type) && Object.keys(item.content ?? {}).length)
					await request('PUT', `redact/${encodeURIComponent(item.event_id)}/${crypto.randomUUID()}`, {});

					if(!page.end || page.end === from || seen.has(page.end) || !(page.chunk?.length)) break;

					seen.add(page.end);
					from = page.end;

					if(++count > 1000) throw new Error('Cleanup history limit exceeded');
				}
				while(!signal.aborted);

				// Persist progress so a crash after leaving can retry forget without room access.
				this.journalRoom(roomId, 'redacted');
				await request('POST', 'leave', {});
				await request('POST', 'forget', {});
				this.journalRoom(roomId, 'done');
				this.dispatchEvent(event('cleanupstatus', {state: 'complete'}));
			}
			catch
			{
				this.dispatchEvent(event('cleanupstatus', {state: 'pending'}));
			}
			finally
			{
				owned?.releaseLock?.();
			}
		};

		const task = (async () => {
			if(owned || !this.locks?.request) return run();

			await this.locks.request(
				`${this.journalKey()}:${roomId}`
				, {ifAvailable: true}
				, async lock => { if(lock) await run(); }
			);
		})();

		this.cleanupTasks.set(roomId, task);

		try { await task; } finally { this.cleanupTasks.delete(roomId); }
	}

	async retryCleanup()
	{
		if(this.guest || !this.userId) return;

		const journal = this.cleanupJournal = this.readJournal();

		for(const [room, value] of Object.entries(journal))
		{
			if(this.closed || this.inviteRooms.has(room)) continue;

			if(value.status === 'redacted')
			{
				try
				{
					const options = {body: {}, cleanup: true, signal: AbortSignal.timeout(10_000)};
					await this.request('POST', `/_matrix/client/v3/rooms/${encodeURIComponent(room)}/leave`, options);
					await this.request('POST', `/_matrix/client/v3/rooms/${encodeURIComponent(room)}/forget`, options);
					this.journalRoom(room, 'done');
				}
				catch
				{
				}
			}
			else if(value.status === 'pending' || this.locks?.request) await this.cleanupRoom(room);
		}
	}

	recoverable(error)
	{
		return error.code === 'matrix_sync' || error.code === 'matrix_transport'
			|| error.status === 429 || error.status >= 500;
	}

	startSync()
	{
		this.syncTask ??= this.syncLoop();

		this.syncTask.catch(
			error => { if(!this.closed && !this.signal.aborted) this.dispatchEvent(event('error', error)); }
		);
	}

	async iceServers({refresh = false} = {})
	{
		if(!refresh && this.turn && this.turn.expiresAt > Date.now() + 60_000) return this.turn.servers;

		try
		{
			const value = await this.request(
				'GET'
				, '/_matrix/client/v3/voip/turnServer'
				, {signal: AbortSignal.any([this.signal, AbortSignal.timeout(10_000)])}
			);

			const uris = (value.uris ?? []).filter(uri => typeof uri === 'string' && /^turns?:/.test(uri));

			if(!uris.length) throw new Error('No relay configured');

			const servers = [{urls: uris, username: value.username, credential: value.password}];
			this.turn = {servers, expiresAt: Date.now() + Math.max(0, Number(value.ttl) || 0) * 1000};

			return servers;
		}
		catch
		{
			this.dispatchEvent(
				event(
					'signalingstatus'
					, {state: 'no-relay', message: 'No TURN relay is available; some networks cannot connect.'}
				)
			);

			return [];
		}
	}

	async invite(peerId, payload = {})
	{
		this.requireConnected();
		peerId = peer(peerId);
		const now = Date.now();

		if(now - (this.lastInvite.get(peerId) ?? 0) < 15_000)
		throw new MatrixLobbyError('invite_rate', `Please wait before inviting ${peerId} again`);

		this.lastInvite.set(peerId, now);
		const invitation = {
			id: crypto.randomUUID()
			, from: this.userId
			, to: peerId
			, payload: structuredClone(payload)
			, expiresAt: now + this.invitationTtl
		};

		this.invitations.set(invitation.id, {...invitation, state: 'pending'});
		await this.send(INVITATION, invitation);

		return Object.freeze(invitation);
	}

	async respond(invitation, answer)
	{
		this.requireConnected();

		if(invitation.privateLink) return answer === 'accepted' ? this.readyInviteLink() : this.cancelInviteLink();

		if(!['accepted','rejected'].includes(answer)) throw new TypeError('Invalid invitation response');

		if(invitation.to !== this.userId)
		throw new MatrixLobbyError('invite_recipient', 'Invitation is for another user');

		if(invitation.expiresAt <= Date.now()) throw new MatrixLobbyError('invitation_expired', 'Invitation expired');

		await this.send(INVITATION_STATE, {
			id: invitation.id
			, from: this.userId
			, to: invitation.from
			, state: answer
			, expiresAt: invitation.expiresAt
		});

		this.dispatchEvent(event('invitationstate', {id: invitation.id, state: answer}));
	}

	async cancel(invitationId, peerId)
	{
		this.requireConnected();
		const invitation = this.invitations.get(invitationId);

		if(!invitation || invitation.state !== 'pending') return;

		invitation.state = 'cancelled';

		await this.send(INVITATION_STATE, {
			id: invitationId
			, from: this.userId
			, to: peer(peerId)
			, state: 'cancelled'
			, expiresAt: invitation.expiresAt
		});
	}

	/**
	 * Queue one WebRTC signal for a peer. Signals queued within `signalBatch` milliseconds share one room event, so
	 * trickle ICE costs a few sends instead of one per candidate, which homeserver message limits allow.
	 */
	async signalPeer(peerId, payload)
	{
		this.requireConnected();
		const to = peer(peerId);
		const key = this.linkState?.peerId === to ? 'private' : to;
		const queue = this.signalQueues.get(key) ?? {to, items: [], writing: false, timer: null};
		this.signalQueues.set(key, queue);

		if(queue.items.length >= 128) throw new MatrixLobbyError('signal_queue', 'Signaling queue is full');

		const sent = Promise.withResolvers();
		queue.items.push({payload: structuredClone(payload), sent});
		queue.timer ??= setTimeout(() => void this.flushSignals(key), this.signalBatch);

		await sent.promise;
	}

	/** Send a peer's queued signals as one event, one batch at a time so they arrive in order. */
	async flushSignals(key)
	{
		const queue = this.signalQueues.get(key);

		if(!queue) return;

		queue.timer = null;

		if(queue.writing || !queue.items.length) return;

		queue.writing = true;
		const items = queue.items.splice(0, 64);

		try
		{
			await this.writeSignals(key, queue.to, items.map(item => item.payload));

			for(const item of items) item.sent.resolve();
		}
		catch(error)
		{
			for(const item of items) item.sent.reject(error);
		}
		finally
		{
			queue.writing = false;

			// Signals that arrived during the write have already waited; send them as the next batch.
			if(queue.items.length) queue.timer ??= setTimeout(() => void this.flushSignals(key), 0);
			else if(this.signalQueues.get(key) === queue) this.signalQueues.delete(key);
		}
	}

	/** Deliver one batch, retrying rate limits and transient failures with the same transaction for 30 seconds. */
	async writeSignals(key, to, payloads)
	{
		if(key === 'private')
		return this.privateSend(
			payloads.length === 1 ? 'signal' : 'signals'
			, payloads.length === 1 ? payloads[0] : payloads
		);

		const content = {id: crypto.randomUUID(), from: this.userId, to, sentAt: Date.now()};

		if(payloads.length === 1) content.payload = payloads[0];
		else content.payloads = payloads;

		const transaction = crypto.randomUUID();
		const deadline = Date.now() + 30_000;

		for(; ;)
		{
			try
			{
				return await this.send(SIGNAL, content, transaction);
			}
			catch(error)
			{
				if(this.closed || this.signal.aborted || Date.now() >= deadline || !this.recoverable(error))
				throw error;

				this.dispatchEvent(event('signalingstatus', {state: 'retrying', code: error.code}));
				await pause(Math.min(5000, error.details?.retry_after_ms ?? 1000), this.signal);
			}
		}
	}

	async chat(text)
	{
		this.requireConnected();
		text = String(text).trim();

		if(!text || text.length > 1000) throw new MatrixLobbyError('chat_message', 'Chat message is empty or too long');

		const sentAt = Date.now();

		await this.send(ROOM_MESSAGE, {
			msgtype: 'm.text'
			, body: text
			, 'org.seanmorris.phonics.sent_at': sentAt
		});

		this.dispatchEvent(event('chat', {from: this.userId, text, sentAt}));
	}

	/** Publish a readable, clickable table offer with namespaced machine-readable metadata. */
	async gameOffer(content, transaction = crypto.randomUUID())
	{
		this.requireConnected();
		const body = structuredClone(content);
		const result = await this.send(ROOM_MESSAGE, body, String(transaction));
		const item = {
			event_id: result.event_id
			, type: ROOM_MESSAGE
			, sender: this.userId
			, origin_server_ts: Date.now()
			, content: body
		};

		this.dispatchEvent(event('gameevent', {event: item}));

		return result;
	}

	/** Send one durable deterministic-game event and apply the accepted local echo. */
	async gameEvent(content, transaction = crypto.randomUUID())
	{
		this.requireConnected();
		const body = structuredClone(content);
		const result = await this.send(GAME, body, String(transaction));
		const item = {
			event_id: result.event_id
			, type: GAME
			, sender: this.userId
			, origin_server_ts: Date.now()
			, content: body
		};

		this.dispatchEvent(event('gameevent', {event: item}));

		return result;
	}

	/** Read one stored event from the connected public lobby room. */
	async roomEvent(eventId)
	{
		this.requireConnected();

		return this.request(
			'GET'
			, `/_matrix/client/v3/rooms/${encodeURIComponent(this.roomId)}/event/${encodeURIComponent(eventId)}`
		);
	}

	/** Read every game child related to a root, following Matrix pagination with a fixed safety bound. */
	async relations(rootId)
	{
		this.requireConnected();
		const values = [], seen = new Set;
		let from = null;

		for(let pageNumber = 0; pageNumber < 100; pageNumber++)
		{
			const query = new URLSearchParams({dir: 'f', limit: '100'});

			if(from) query.set('from', from);

			const page = await this.request(
				'GET'
				, `/_matrix/client/v1/rooms/${encodeURIComponent(this.roomId)}/relations/${encodeURIComponent(
					rootId
				)}/${encodeURIComponent('org.seanmorris.phonics.game.v1')}/${encodeURIComponent(GAME)}?${query}`
			);

			for(const item of page.chunk ?? [])
			if(!seen.has(item.event_id))
			{
				seen.add(item.event_id);
				values.push(item);
			}

			if(!page.next_batch || page.next_batch === from || !(page.chunk?.length)) break;

			from = page.next_batch;
		}

		return values;
	}

	/** Read recent public-room events in chronological order. */
	async roomHistory({type, types, limit = 200} = {})
	{
		this.requireConnected();
		limit = Math.max(1, Math.min(1000, Number(limit) || 200));
		const selected = new Set(Array.isArray(types) ? types : type ? [type] : []);
		const values = [], seen = new Set;
		let from = null, pages = 0;

		while(values.length < limit && pages++ < 20)
		{
			const query = new URLSearchParams({dir: 'b', limit: String(Math.min(100, limit - values.length))});

			if(from) query.set('from', from);

			if(selected.size) query.set('filter', JSON.stringify({types: [...selected]}));

			const page = await this.request(
				'GET'
				, `/_matrix/client/v3/rooms/${encodeURIComponent(this.roomId)}/messages?${query}`
			);

			for(const item of page.chunk ?? [])
			if((!selected.size || selected.has(item.type)) && !seen.has(item.event_id))
			{
				seen.add(item.event_id);
				values.push(item);
			}

			if(!page.end || page.end === from || !(page.chunk?.length)) break;

			from = page.end;
		}

		return values.slice(0, limit).reverse();
	}

	/** Advertise this browser's current waiting, active, or spectating table. */
	async setActivity(activity)
	{
		this.requireConnected();
		this.activity = activity === null ? null : structuredClone(activity);

		return this.announcePresence();
	}

	async announcePresence()
	{
		this.requireConnected();

		await this.send(PRESENCE, {
			peer: this.userId
			, displayName: this.displayName
			, expiresAt: Date.now() + this.presenceTtl
			, protocol: '1.0.0'
			, activity: this.activity
		});
	}

	async syncOnce()
	{
		this.requireConnected();
		const query = new URLSearchParams({timeout: String(this.syncTimeout)});
		const rooms = [this.roomId, ...this.inviteRooms.keys()].filter(Boolean);

		query.set('filter', JSON.stringify({
			presence: {types: []}
			, account_data: {types: []}
			, to_device: {types: SYNC_DEVICE_EVENTS}
			, room: {
				rooms
				, account_data: {types: []}
				, ephemeral: {types: []}
				, state: {types: ['m.room.member']}
				, timeline: {types: SYNC_ROOM_EVENTS, limit: 100}
			}
		}));

		if(this.since) query.set('since', this.since);

		const result = await this.request('GET', `/_matrix/client/v3/sync?${query}`);

		if(typeof result.next_batch !== 'string' || !result.next_batch)
		throw new MatrixLobbyError(
			'matrix_sync'
			, 'Matrix sync response did not include a continuation token'
			, {details: {retry_after_ms: this.syncMinInterval}}
		);

		for(const item of result.to_device?.events ?? []) await this.consumeDevice(item);

		for(const [roomId, state] of this.inviteRooms)
		{
			if(result.rooms?.leave?.[roomId])
			{
				this.dispatchEvent(event('inviteclosed', {reason: 'The private invitation was closed'}));
				void this.cancelInviteLink();
				continue;
			}

			const joined = result.rooms?.join?.[roomId];
			const timeline = joined?.timeline;

			for(const item of joined?.state?.events ?? []) await this.consumePrivate(state, item);

			if(!timeline) continue;

			if(timeline.limited)
			{
				let from = timeline.prev_batch, pages = 0;
				const history = [];

				while(from && pages++ < 40)
				{
					const page = await this.request(
						'GET'
						, `/_matrix/client/v3/rooms/${encodeURIComponent(
							roomId
						)}/messages?dir=b&limit=100&from=${encodeURIComponent(
							from
						)}`
					);

					let found = false;

					for(const item of page.chunk ?? [])
					{
						if(item.event_id === state.lastEvent)
						{
							found = true;
							break;
						}

						history.push(item);
					}

					if(found || !page.chunk?.length || !page.end || page.end === from)
					{
						from = null;
						break;
					}

					from = page.end;
				}

				if(from)
				{
					this.dispatchEvent(
						event('inviteclosed', {reason: 'Signaling history exceeded the recovery limit'})
					);

					void this.cancelInviteLink();
					continue;
				}

				for(const item of history.reverse()) await this.consumePrivate(state, item);
			}

			for(const item of timeline.events ?? [])
			{
				await this.consumePrivate(state, item);
				state.lastEvent = item.event_id;
			}
		}

		this.since = result.next_batch ?? this.since;
		const timeline = result.rooms?.join?.[this.roomId]?.timeline?.events ?? [];

		for(const item of timeline) this.consume(item);

		this.expirePresence();

		return result;
	}

	/** Whether a sync response contains work for one of the rooms this provider owns. */
	syncResponseHasEvents(result)
	{
		if(result.to_device?.events?.length) return true;

		for(const roomId of [this.roomId, ...this.inviteRooms.keys()])
		{
			const room = result.rooms?.join?.[roomId];

			if(room?.timeline?.events?.length || room?.state?.events?.length || result.rooms?.leave?.[roomId])
			return true;
		}

		return false;
	}

	async syncLoop()
	{
		let failures = 0, rapidEmptyResponses = 0;

		while(!this.closed && !this.signal.aborted)
		{
			try
			{
				const started = Date.now();
				const result = await this.syncOnce();
				failures = 0;
				const elapsed = Date.now() - started;
				const rapid = elapsed < Math.max(1_000, this.syncTimeout * .8);
				let delay = Math.max(0, this.syncMinInterval - elapsed);

				if(!rapid || this.syncResponseHasEvents(result)) rapidEmptyResponses = 0;
				else
				{
					delay = Math.max(
						delay
						, Math.min(
							this.syncEmptyMaxDelay
							, this.syncEmptyDelay * 2 ** Math.min(rapidEmptyResponses++, 6)
						)
					);
				}

				if(delay > 0) await pause(delay, this.signal);
			}
			catch(error)
			{
				if(this.closed || this.signal.aborted) return;

				if(!this.recoverable(error)) throw error;

				this.dispatchEvent(event('signalingstatus', {state: 'retrying'}));

				const exponential = 500 * 2 ** Math.min(++failures, 6);
				const backoff = Math.min(30_000, error.details?.retry_after_ms ?? exponential);

				await pause(Math.max(this.syncMinInterval, backoff), this.signal);
			}
		}
	}

	consume(item)
	{
		if(!item || typeof item.content !== 'object') return;

		if(item.sender === this.userId) return;

		const content = item.content;

		if(item.type === PRESENCE)
		{
			if(content.peer !== item.sender || !Number.isFinite(content.expiresAt)) return;

			this.peers.set(item.sender, {
				id: item.sender
				, displayName: String(content.displayName ?? item.sender)
				, expiresAt: content.expiresAt
				, activity: content.activity ?? null
			});

			this.publishPresence();
		}
		else if(item.type === INVITATION)
		{
			if(content.from !== item.sender || content.to !== this.userId || content.expiresAt <= Date.now()) return;

			this.dispatchEvent(event('invitation', {invitation: Object.freeze(structuredClone(content))}));
		}
		else if(item.type === INVITATION_STATE)
		{
			if(content.from !== item.sender || content.to !== this.userId) return;

			const invitation = this.invitations.get(content.id);

			if(!invitation || invitation.state !== 'pending' || content.expiresAt !== invitation.expiresAt) return;

			invitation.state = content.state;
			this.dispatchEvent(event('invitationstate', {id: content.id, state: content.state}));
		}
		else if(item.type === SIGNAL)
		{
			if(content.from !== item.sender || content.to !== this.userId) return;

			const payloads = Array.isArray(content.payloads) ? content.payloads.slice(0, 64) : [content.payload];

			for(const payload of payloads)
			this.dispatchEvent(event('signal', {from: item.sender, payload: structuredClone(payload)}));
		}
		else if(item.type === CHAT)
		{
			if(typeof content.text !== 'string' || content.text.length > 1000) return;

			this.dispatchEvent(event('chat', {from: item.sender, text: content.text, sentAt: content.sentAt}));
		}
		else if(item.type === ROOM_MESSAGE)
		{
			if(content[GAME]) this.dispatchEvent(event('gameevent', {event: structuredClone(item)}));
			else if(content.msgtype === 'm.text' && typeof content.body === 'string' && content.body.length <= 1000)
			this.dispatchEvent(event('chat', {
				from: item.sender
				, text: content.body
				, sentAt: content['org.seanmorris.phonics.sent_at'] ?? item.origin_server_ts
			}));
		}
		else if(item.type === GAME)
		this.dispatchEvent(event('gameevent', {event: structuredClone(item)}));
	}

	expirePresence(now = Date.now())
	{
		let changed = false;

		for(const [id, value] of this.peers)
		if(value.expiresAt <= now)
		{
			this.peers.delete(id);
			changed = true;
		}

		if(changed) this.publishPresence();
	}

	publishPresence()
	{
		this.dispatchEvent(event('presence', {
			peers: [this.profile(), ...[...this.peers.values()].map(({expiresAt, ...value}) => value)]
		}));
	}

	async send(type, content, transaction = `${Date.now()}-${++this.transaction}`)
	{
		return this.request(
			'PUT'
			, `/_matrix/client/v3/rooms/${encodeURIComponent(
				this.roomId
			)}/send/${encodeURIComponent(
				type
			)}/${transaction}`
			, {body: content}
		);
	}

	async request(method, path, options = {})
	{
		return this.requestUrl(method, new URL(path, this.homeserver), options);
	}

	async requestUrl(
		method
		, target
		, {body, form, auth = true, retry = true, signal = this.signal, cleanup = false} = {}
	){
		signal.throwIfAborted();
		const headers = {'Accept': 'application/json'};

		if(body !== undefined) headers['Content-Type'] = 'application/json';
		else if(form !== undefined) headers['Content-Type'] = 'application/x-www-form-urlencoded';

		if(auth)
		{
			if(!cleanup) await this.ensureAccessToken();

			if(!this.accessToken) throw new MatrixLobbyError('matrix_auth_required', 'Matrix login is required');

			headers.Authorization = `Bearer ${this.accessToken}`;
		}

		let response;

		try
		{
			response = await this.fetch(new URL(target, this.homeserver), {
				method
				, headers
				, body: body !== undefined
					? JSON.stringify(body)
					: form === undefined ? undefined : new URLSearchParams(form)
				, signal
				, redirect: 'error'
			});
		}
		catch(error)
		{
			throw new MatrixLobbyError(
				'matrix_transport'
				, `Matrix request failed: ${method} ${target}`
				, {cause: error}
			);
		}

		let value = {};

		try { value = await response.json(); } catch {}

		if(response.status === 401 && auth && retry && this.refreshToken && !cleanup)
		{
			await this.refreshAccessToken();

			return this.requestUrl(method, target, {body, form, auth, retry: false, signal, cleanup});
		}

		if(!response.ok)
		throw new MatrixLobbyError(
			value.errcode ?? value.error ?? 'matrix_http'
			, value.error_description ?? value.error ?? `Matrix HTTP ${response.status}`
			, {status: response.status, details: value}
		);

		return value;
	}

	async ensureAccessToken()
	{
		if(this.authApi === 'oauth' && this.refreshToken && this.expiresAt <= Date.now() + 30_000)
		await this.refreshAccessToken();
	}

	async refreshAccessToken()
	{
		if(this.refreshTask) return this.refreshTask;

		if(!this.refreshToken || !this.clientId)
		throw new MatrixLobbyError('matrix_auth_required', 'Matrix OAuth session must be renewed');

		this.refreshTask = (async () => {
			let endpoint = this.tokenEndpoint;

			if(!endpoint)
			{
				const metadata = await this.oauthMetadata();
				endpoint = metadata.token_endpoint;
				this.revocationEndpoint = metadata.revocation_endpoint;
			}

			const result = await this.requestUrl('POST', endpoint, {
				auth: false
				, form: {
					grant_type: 'refresh_token'
					, refresh_token: this.refreshToken
					, client_id: this.clientId
				}
			});

			if(!result.access_token)
			throw new MatrixLobbyError('matrix_oauth_refresh', 'Matrix OAuth refresh returned no access token');

			this.accessToken = result.access_token;
			this.refreshToken = result.refresh_token ?? this.refreshToken;
			this.expiresAt = Date.now() + Math.max(1, Number(result.expires_in ?? 300)) * 1000;
			this.tokenEndpoint = endpoint;
			this.save();
		})();

		try
		{
			await this.refreshTask;
		}
		finally
		{
			this.refreshTask = null;
		}
	}

	useSession(result)
	{
		if(!result.access_token || !result.user_id)
		throw new MatrixLobbyError('matrix_login', 'Matrix login returned no session');

		this.accessToken = result.access_token;
		this.userId = peer(result.user_id);
		this.deviceId = result.device_id ?? null;
		this.displayName ??= this.userId;
		this.save();

		return this.profile();
	}

	useOAuthSession(result, pending)
	{
		if(!result.access_token || !result.refresh_token)
		throw new MatrixLobbyError('matrix_oauth_login', 'Matrix OAuth returned no renewable session');

		this.accessToken = result.access_token;
		this.refreshToken = result.refresh_token;
		this.expiresAt = Date.now() + Math.max(1, Number(result.expires_in ?? 300)) * 1000;
		this.clientId = pending.clientId;
		this.deviceId = pending.deviceId;
		this.tokenEndpoint = pending.tokenEndpoint;
		this.revocationEndpoint = pending.revocationEndpoint;
		this.authApi = 'oauth';
		this.save();

		return this.profile();
	}

	save()
	{
		try
		{
			this.storage?.setItem(this.storageKey, JSON.stringify({
				accessToken: this.accessToken
				, refreshToken: this.refreshToken
				, expiresAt: this.expiresAt
				, clientId: this.clientId
				, tokenEndpoint: this.tokenEndpoint
				, revocationEndpoint: this.revocationEndpoint
				, authApi: this.authApi
				, userId: this.userId
				, deviceId: this.deviceId
				, displayName: this.displayName
			}));
		}
		catch
		{
		}
	}

	logoutLocal()
	{
		this.accessToken = this.refreshToken = this.userId = this.deviceId = this.displayName = null;
		this.expiresAt = 0;
		this.clientId = this.tokenEndpoint = this.revocationEndpoint = null;
		this.authApi = 'legacy';

		try { this.storage?.removeItem(this.storageKey); } catch {}
	}

	/**
	 * Revoke the current Matrix/OAuth session, stop its live connection, and retain a reusable provider.
	 * Local credentials are always removed even when a remote endpoint is unavailable.
	 * @returns {Promise<{remote: boolean, failures: Error[]}>} Remote cleanup status.
	 */
	async logout()
	{
		if(this.logoutTask) return this.logoutTask;

		this.logoutTask = (async () => {
			const accessToken = this.accessToken;
			const refreshToken = this.refreshToken;
			const clientId = this.clientId;
			const revocationEndpoint = this.revocationEndpoint;
			const failures = [];

			clearInterval(this.cleanupTimer);
			clearInterval(this.heartbeatTimer);

			await Promise.allSettled([
				this.cancelInviteLink('Signed out')
				, ...this.cleanupTasks.values()
			]);

			this.connected = false;
			this.controller.abort(new MatrixLobbyError('matrix_logout', 'Matrix session signed out'));
			await Promise.allSettled([this.syncTask]);

			const remote = [];

			if(accessToken)
			remote.push(this.request('POST', '/_matrix/client/v3/logout', {
				body: {}
				, cleanup: true
				, retry: false
				, signal: AbortSignal.timeout(15_000)
			}));

			if(revocationEndpoint && clientId)
			{
				const tokens = [
					[refreshToken, 'refresh_token']
					, [accessToken, 'access_token']
				];

				for(const [token, tokenTypeHint] of tokens)
				{
					if(!token) continue;

					remote.push(this.requestUrl('POST', revocationEndpoint, {
						auth: false
						, retry: false
						, signal: AbortSignal.timeout(15_000)
						, form: {token, token_type_hint: tokenTypeHint, client_id: clientId}
					}));
				}
			}

			for(const result of await Promise.allSettled(remote))
			if(result.status === 'rejected') failures.push(result.reason);

			this.logoutLocal();
			this.roomId = this.since = this.turn = null;
			this.syncTask = this.refreshTask = null;
			this.peers.clear();
			this.invitations.clear();
			this.lastInvite.clear();
			this.inviteLinkCapabilityResult = null;
			this.inviteLinkCapabilityTask = null;

			if(!this.closed) this.resetConnectionSignal();

			return Object.freeze({remote: failures.length === 0, failures: Object.freeze(failures)});
		})();

		try
		{
			return await this.logoutTask;
		}
		finally
		{
			this.logoutTask = null;
		}
	}

	close()
	{
		if(this.closed) return this.closeTask;

		const cancelled = this.cancelInviteLink('Session closed');
		this.closeTask = Promise.allSettled([cancelled, ...this.cleanupTasks.values()]);
		this.closed = true;
		this.connected = false;
		clearInterval(this.cleanupTimer);
		clearInterval(this.heartbeatTimer);

		for(const queue of this.signalQueues.values())
		{
			clearTimeout(queue.timer);

			for(const item of queue.items.splice(0))
			item.sent.reject(new MatrixLobbyError('lobby_closed', 'Matrix lobby closed'));
		}

		this.signalQueues.clear();
		this.controller.abort(new MatrixLobbyError('lobby_closed', 'Matrix lobby closed'));
		this.peers.clear();
		this.invitations.clear();

		return this.closeTask;
	}

	requireConnected()
	{
		if(!this.connected || this.closed) throw new MatrixLobbyError('lobby_state', 'Matrix lobby is not connected');
	}
}

/** Register the provider when loaded as a verified Phonics browser export. */
export function activate({services, exportId})
{
	if(!services?.register) throw new Error('Phonics Matrix requires the browser service registry');

	services.register('lobby-provider', exportId, options => new MatrixLobbyProvider(options));

	return () => services.unregister('lobby-provider', exportId);
}
