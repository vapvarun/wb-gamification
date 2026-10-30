# Badge Credentials

Go to **WB Gamification > Badges** in your admin menu.

A credential is a badge that carries proof. Anyone with its link can check that your site issued it, to which member and when. Use it for course certificates, professional status or any badge a member would put on a CV.

This page is the owner's step by step. For how sharing works for members, see [Badge Sharing](../features/30-badge-sharing.md). For badges in general, see [Badges](../features/20-badges.md).

## Make a Badge a Credential

1. Go to **WB Gamification > Badges**.
2. Click the badge card to open it.
3. Tick **Is Credential**. The label reads "Mark as shareable credential (LinkedIn, OpenBadges)".
4. Optional: enter **Valid for (days)** if the credential should expire. Leave it blank to keep it valid for ever.
5. Click **Save Changes**.

The setting belongs to the badge, so members who already hold it are covered straight away. Nothing is re-issued.

Any badge can be a credential, but pick ones that mean something. A "First Comment" badge makes a weak certificate. A course completion makes a strong one.

## What Changes for the Member

A badge is private until its owner shares it. This applies to every badge, credential or not, and it protects your members: nobody can find out which badges a member holds by guessing a link.

1. The member opens their own badge board. This is the Badge Showcase block, wherever you placed it, or the **Badges** tab under **Achievements** on a BuddyPress profile.
2. Under a badge they have earned, they click **Share**.
3. The button changes to **Shared - click to make private**. Clicking it again withdraws the badge.

The Share button shows only on a member's own board, never on anyone else's.

Once shared, the badge has a public share page. A badge that is not shared sends visitors to the member's profile instead.

## The Share Page

The share page lives at:

```text
/gamification/badge/{badge-id}/{member-id}/share/
```

The plugin does not show this link after a member clicks **Share**. Developers can build it with `wb_gam_get_badge_share_url()`. See [Helper Functions](../developer-guide/40-helper-functions.md).

The page shows the badge, its description, who earned it and the date, with these actions:

- **Copy link**, plus share icons for X, Facebook and LinkedIn.
- **Add to LinkedIn profile**, for credential badges only.
- **View verifiable credential**, for credential badges only.
- **View *member's* achievements** and **Explore *your site***.

A link shared on social media gets a generated preview card, 1200 by 630 pixels. If the server cannot draw it, the badge image is used instead.

## The LinkedIn Button

**Add to LinkedIn profile** opens LinkedIn's "Add certification" form with the fields filled in:

| LinkedIn field | Filled with |
|---|---|
| Name | The badge name |
| Issuing organization | Your site title |
| Issue date | The month and year the member earned the badge |
| Credential URL | The credential address below |
| Credential ID | The badge ID, an underscore, then the member ID |

The member checks the form and clicks save on LinkedIn.

## The Credential

The credential is an OpenBadges 3.0 document in JSON-LD form. It lives at:

```text
/wp-json/wb-gamification/v1/badges/{badge-id}/credential/{member-id}
```

It holds the badge name and description, the badge image, the member's display name, the date it was issued and the expiry date, if any. It names your site as the issuer.

## How Someone Verifies It

A recruiter or wallet app needs no login and no plugin.

1. Open the credential address from the member's LinkedIn entry or share page.
2. Check that the address is on your domain. The page is served by your site, so the claim stands behind your site.
3. Read the name, the badge and the dates.

| What they see | Meaning |
|---|---|
| The JSON document | The badge was earned and the member has shared it. |
| 404 | The member has not shared it, never earned it, or it does not exist. |
| 403 | The badge is not a credential. |
| 410 | The credential has expired. |

Keep this limit in mind: the document carries no digital signature. Verification means it resolves from your site. A developer can add a signature or extra fields with the `wb_gam_credential_document` filter. See [Filters Reference](../developer-guide/130-filters-reference.md).

## Expiry and Renewal

If you set **Valid for (days)**, each member's credential expires that many days after they earned it. An expired credential answers 410. A weekly check notifies the member through BuddyPress, when it is active, and gives developers the `wb_gam_credential_expired` action to start a renewal. Leave the field blank for a credential that never expires.

## Preview Before You Announce

Administrators can open any member's share page and credential without the member sharing first. Use your own account:

1. Open the badge and use **Award this badge to a user** to give it to your own account. Click **Award Badge**.
2. Open your share page and credential address as above, using your own member ID.
3. Check the LinkedIn form opens with the right values.

## Turn Sharing Off

Go to **WB Gamification > Settings > Modules**, find **Background features**, untick **Badge sharing - public share cards for LinkedIn and similar**, and click **Save Modules**. The share page stops working. Nothing is deleted.

## Who Can Do This

Administrators, and staff given the **Manage badges** permission. See [Staff Permissions](140-staff-permissions.md).

## See Also

- [Badge Sharing](../features/30-badge-sharing.md) for the member's side.
- [Badges](../features/20-badges.md) and [Badge Management](20-badge-management.md) for every badge field.
- [Badges and Levels REST](../developer-guide/160-rest-badges-levels.md) for the credential endpoint.
