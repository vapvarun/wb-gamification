# Kudos Feed Block

The Kudos Feed block shows a live stream of recent peer recognition: who gave kudos, who received it, and the message. It can also include a built-in form so members can send kudos right from the feed.

## Add it to a page

In the block editor, click `+`, search for "Kudos Feed", and insert the **Kudos Feed** block.

Prefer a shortcode? Use:

```text
[wb_gam_kudos_feed]
[wb_gam_kudos_feed limit="5" show_messages="0"]
```

## Settings

| Setting | What it does | Default |
|---|---|---|
| Limit | How many recent kudos to show (1-50). | 10 |
| Show messages | Show the kudos message text under each entry. | on |
| Show give form | Show a built-in form so members can send kudos. | on |
| Give form to | Pre-fill the form to send kudos to a specific member. | (empty, member chooses) |
| Give form label | Custom label for the send button. | (empty, default text) |

## Tips

- Place this on your community homepage so recognition is visible and contagious.
- Turn off Show give form if you only want a read-only feed and are using the Give Kudos block elsewhere.

## See also

- [Kudos feature](../features/09-kudos.md)
- [Give Kudos block](11-give-kudos.md)
- [Blocks overview](01-blocks-overview.md)
