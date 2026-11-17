---
journey: reward-save-keeps-owner-choices
plugin: wb-gamification
priority: high
roles: [administrator]
covers: [create-inactive, unlimited-clears-stock, partial-update, sold-out-zero, inactive-hidden]
prerequisites:
  - "Site reachable at $SITE_URL, admin ?autologin=1"
estimated_runtime_minutes: 5
---

# Saving a reward keeps what the owner chose (cards 10483129430, 10483238276)

## Steps

### 1. Create inactive
- **Action**: Gamification > Redemption Store > Add, Status Inactive, Publish.
- **Expect**: the list shows Inactive; the member store does not show it; `POST /redemptions/items` with
  `is_active=false` returns `is_active: false`.
- **On fail**: `RedemptionController::create_item()`.

### 2. Unlimited clears the count
- **Action**: edit a reward with 15 left, pick Unlimited, save.
- **Expect**: reload shows Unlimited; `stock` is null. Limited with 0 shows sold out.
- **On fail**: `RedemptionController::stock_from()`.

### 3. A partial update changes only what it sends
- **Action**: `PUT /redemptions/items/<id>` with only `title`.
- **Expect**: stock, status, description, point type and reward config unchanged. `stock_mode=sometimes`
  returns 400 `rest_invalid_param`.
- **On fail**: `RedemptionController::item_args()` (no defaults on update).
