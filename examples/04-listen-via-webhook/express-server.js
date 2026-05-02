/**
 * WB Gamification — Webhook listener (Express.js)
 *
 * Receive notifications when something happens in WB Gamification —
 * points awarded, badges unlocked, level changes, kudos given, etc. —
 * and forward to Slack / Discord / your CRM / Zapier / database.
 *
 * Setup:
 *   1. Run this server somewhere reachable by the WP install.
 *      $ npm install express
 *      $ SITE=https://your-site.com node express-server.js
 *
 *   2. In WP Admin → Gamification → Webhooks, create a webhook
 *      pointing at this server's URL (e.g. http://localhost:3000/wb-gam-webhook)
 *      and select the events you want to receive.
 *
 *   3. WB Gamification's WebhookDispatcher will POST to this endpoint
 *      asynchronously (via Action Scheduler) every time a subscribed
 *      event fires. Failures are retried up to 3 times.
 */

import express from 'express';
import crypto from 'crypto';

const PORT = process.env.PORT || 3000;
const WEBHOOK_SECRET = process.env.WBGAM_WEBHOOK_SECRET || 'change-me';

const app = express();

// Capture raw body for signature verification BEFORE express.json parses it
app.use(
  '/wb-gam-webhook',
  express.raw( { type: 'application/json' } )
);

app.post( '/wb-gam-webhook', ( req, res ) => {
  // ────────────────────────────────────────────────────────────────────
  // 1. Verify signature (if your webhook is configured with a secret)
  // ────────────────────────────────────────────────────────────────────
  const signature = req.headers['x-wb-gam-signature'];
  if ( WEBHOOK_SECRET && signature ) {
    const expected = crypto
      .createHmac( 'sha256', WEBHOOK_SECRET )
      .update( req.body )
      .digest( 'hex' );
    const ok = crypto.timingSafeEqual(
      Buffer.from( signature ),
      Buffer.from( `sha256=${expected}` )
    );
    if ( ! ok ) {
      console.warn( 'Bad webhook signature, rejecting' );
      return res.status( 401 ).send( 'invalid signature' );
    }
  }

  // ────────────────────────────────────────────────────────────────────
  // 2. Parse the payload
  // ────────────────────────────────────────────────────────────────────
  let payload;
  try {
    payload = JSON.parse( req.body.toString( 'utf-8' ) );
  } catch ( err ) {
    return res.status( 400 ).send( 'invalid JSON' );
  }

  // The payload shape is documented in
  // src/Engine/WebhookDispatcher.php — typical fields:
  //   event:       'points_awarded' | 'badge_awarded' | 'level_changed' | ...
  //   timestamp:   ISO 8601
  //   user_id:     int
  //   data:        per-event payload (points, badge_id, levels, etc.)
  //   site_id:     string (multi-site reporter)
  //   delivery_id: UUID (use for dedup)

  const { event, user_id, data, delivery_id } = payload;
  console.log( `[${event}] user=${user_id} delivery=${delivery_id}`, data );

  // ────────────────────────────────────────────────────────────────────
  // 3. Route to your destination
  // ────────────────────────────────────────────────────────────────────
  switch ( event ) {
    case 'points_awarded':
      forwardToSlack( payload );
      break;

    case 'badge_awarded':
      forwardToCrm( payload );
      celebrateInDiscord( payload );
      break;

    case 'level_changed':
      sendCongratEmail( payload );
      break;

    case 'streak_milestone':
      grantInternalReward( payload );
      break;

    default:
      console.log( 'Unhandled event:', event );
  }

  // ────────────────────────────────────────────────────────────────────
  // 4. Respond with 200 to acknowledge receipt
  //    Do NOT block on slow downstream calls — return fast and process
  //    asynchronously, otherwise WebhookDispatcher will retry on timeout.
  // ────────────────────────────────────────────────────────────────────
  res.status( 200 ).json( { received: true } );
} );

// ──────────────────────────────────────────────────────────────────────
// Stubbed downstream destinations — replace with real implementations
// ──────────────────────────────────────────────────────────────────────

async function forwardToSlack( { user_id, data } ) {
  await fetch( process.env.SLACK_WEBHOOK_URL, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify( {
      text: `:trophy: User ${user_id} just earned ${data.points} points (${data.reason}).`,
    } ),
  } );
}

async function forwardToCrm( payload ) {
  // POST to your CRM's API
}

async function celebrateInDiscord( payload ) {
  // POST to your Discord webhook
}

async function sendCongratEmail( { user_id, data } ) {
  // Use your transactional email provider's API to send a level-up email
}

async function grantInternalReward( { user_id, data } ) {
  // Hit your internal rewards system
}

app.listen( PORT, () => {
  console.log( `WB Gamification webhook listener on :${PORT}` );
} );
