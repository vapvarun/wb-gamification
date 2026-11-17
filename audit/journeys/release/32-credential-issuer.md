---
journey: credential-issuer
plugin: wb-gamification
priority: medium
roles: [guest]
covers: [issuer-route, credential-issuer-link]
prerequisites:
  - "Site reachable at $SITE_URL; one credential badge a member has published"
estimated_runtime_minutes: 3
---

# The credential's issuer link resolves (card 10459672858)

## Steps

### 1. Issuer page
- **Action**: logged out, `curl -i $SITE_URL/wp-json/wb-gamification/v1/issuer`.
- **Expect**: 200, `Content-Type: application/ld+json`, body `type: Profile` with the site name and URL and
  `id` equal to the request URL. No member data.
- **On fail**: `CredentialController::get_issuer()`.

### 2. Credential points at it
- **Action**: fetch a published credential; open its `issuer.id`.
- **Expect**: the same Profile as step 1 (same object in `issuer` and `achievement.issuer`).
