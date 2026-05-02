/**
 * WB Gamification — REST events ingestion via fetch()
 *
 * Vanilla JS examples. Works in browser, Node 18+, React Native, Deno.
 */

const SITE = process.env.SITE || 'http://wb-gamification.local';

// ────────────────────────────────────────────────────────────────────────
// 1. Cookie + wp_rest nonce (in-browser, logged-in user)
// ────────────────────────────────────────────────────────────────────────

async function ingestViaCookie() {
  // wp_localize_script provides this on every WB Gamification block page
  const nonce = window.wbGamSettings?.nonce;
  if ( ! nonce ) throw new Error( 'No wp_rest nonce — are we on a logged-in page?' );

  const res = await fetch( `${SITE}/wp-json/wb-gamification/v1/events`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce':   nonce,
    },
    body: JSON.stringify( {
      action_id: 'yourplugin_button_clicked',
      metadata:  { campaign: 'spring-2026' }
    } ),
  } );

  return res.json();
}

// ────────────────────────────────────────────────────────────────────────
// 2. Application Password (server-to-server)
// ────────────────────────────────────────────────────────────────────────

async function ingestViaAppPassword( userLogin, appPassword, userId, actionId, metadata = {} ) {
  const auth = Buffer.from( `${userLogin}:${appPassword}` ).toString( 'base64' );

  const res = await fetch( `${SITE}/wp-json/wb-gamification/v1/events`, {
    method: 'POST',
    headers: {
      'Authorization': `Basic ${auth}`,
      'Content-Type':  'application/json',
    },
    body: JSON.stringify( {
      action_id: actionId,
      user_id:   userId,
      metadata,
    } ),
  } );

  if ( ! res.ok ) {
    const err = await res.json();
    throw new Error( `${err.code}: ${err.message}` );
  }
  return res.json();
}

// ────────────────────────────────────────────────────────────────────────
// 3. Plugin-issued API key (mobile / external service)
// ────────────────────────────────────────────────────────────────────────

class WbGamClient {
  constructor( { site, apiKey, abortSignal = null, timeoutMs = 5000 } ) {
    this.site = site;
    this.apiKey = apiKey;
    this.abortSignal = abortSignal;
    this.timeoutMs = timeoutMs;
  }

  async ingest( actionId, { userId = null, objectId = null, metadata = {} } = {} ) {
    const body = { action_id: actionId, metadata };
    if ( userId )   body.user_id   = userId;
    if ( objectId ) body.object_id = objectId;

    // Always set a timeout — REST hang risk is real if the site is slow
    const ctrl = new AbortController();
    const timeoutId = setTimeout( () => ctrl.abort(), this.timeoutMs );
    if ( this.abortSignal ) {
      this.abortSignal.addEventListener( 'abort', () => ctrl.abort() );
    }

    try {
      const res = await fetch( `${this.site}/wp-json/wb-gamification/v1/events`, {
        method: 'POST',
        signal: ctrl.signal,
        headers: {
          'X-WB-Gam-Key':  this.apiKey,
          'Content-Type':  'application/json',
        },
        body: JSON.stringify( body ),
      } );

      const data = await res.json();
      if ( ! res.ok ) {
        throw new Error( `${data.code || res.status}: ${data.message || 'request failed'}` );
      }
      return data;
    } finally {
      clearTimeout( timeoutId );
    }
  }

  async memberPoints( userId ) {
    const res = await fetch(
      `${this.site}/wp-json/wb-gamification/v1/members/${userId}/points`,
      {
        headers: { 'X-WB-Gam-Key': this.apiKey },
      }
    );
    return res.json();
  }

  async leaderboard( { period = 'all_time', limit = 10 } = {} ) {
    // Public endpoint, no auth needed
    const res = await fetch(
      `${this.site}/wp-json/wb-gamification/v1/leaderboard?period=${period}&limit=${limit}`
    );
    return res.json();
  }
}

// ────────────────────────────────────────────────────────────────────────
// Usage
// ────────────────────────────────────────────────────────────────────────

const client = new WbGamClient( {
  site:   process.env.SITE,
  apiKey: process.env.WBGAM_API_KEY,
  timeoutMs: 3000,
} );

const result = await client.ingest( 'yourplugin_lesson_complete', {
  userId: 42,
  metadata: { lesson_id: 'js-101', completed_at: new Date().toISOString() },
} );
console.log( 'event_id:', result.event_id );

const points = await client.memberPoints( 42 );
console.log( 'total points:', points.total );

const leaderboard = await client.leaderboard( { period: 'weekly', limit: 5 } );
console.log( 'top 5 this week:', leaderboard.rows );
