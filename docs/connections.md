# Connections

**Ghostwriter → Connections** is where a site sets up every outside service Ghostwriter uses. Only admins see it, as for the plugin's settings.

The cards come in three groups:

- **Writing:** Anthropic, OpenAI, Gemini and OpenRouter. OpenAI, Gemini and OpenRouter also make images; there are no separate image cards.
- **Images:** Unsplash, Pexels and Pixabay (free photo libraries), and Openverse, which needs no key.
- **Stock photos:** Shutterstock (consumer key and secret, then **Connect account** to license).

## Setting one up

1. Click **Set up** on the card.
2. **Open {Service}** opens the page where its key is made, in a new tab. Follow the two or three steps on the card.
3. Paste the key (and the secret, for Shutterstock) and click **Check & save**. Ghostwriter makes one small call to the service with it (a list of one model, or a search for one photo). If the service refuses it, the card says why, plainly, and nothing is kept.

The card then says **Connected · key ending ••a1b2**. Ghostwriter never shows more of a key than its last four characters. **Replace key** swaps it; **Disconnect** (after a confirm) forgets it. The key still works at the service until you delete it there.

OpenRouter can also **Connect with OpenRouter** from its card: sign in, and OpenRouter makes the key for you.

If a service starts refusing a key that was working, the card says **Key stopped working** the next time Ghostwriter uses it. Replace the key, and it clears.

## .env always wins

Every key can still be set in `.env`: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`, `OPENROUTER_API_KEY`, `UNSPLASH_ACCESS_KEY`, `PEXELS_API_KEY`, `PIXABAY_API_KEY`, `SHUTTERSTOCK_API_KEY` and `SHUTTERSTOCK_API_SECRET`. When one is set, it is used, its card says **Set in .env**, and **Set up** and **Replace key** aren't offered. Take it out of `.env` to manage it on the page instead.

The page says which environment you're on ("You're on local."), from `CRAFT_ENVIRONMENT`. What is set up in Connections lives in that environment's database, so your local, staging and live sites each keep their own keys, and what's connected can differ between them. Use `.env` to give every environment the same keys.

## Where keys are kept

In Ghostwriter's own `ghostwriter_credentials` table, each value encrypted with Craft's security component and your site's security key (`CRAFT_SECURITY_KEY`), so the database alone gives nothing away. **Never in project config**, so nothing reaches your repository. Changing the security key makes kept keys unreadable: their cards go back to **Not set up**; set them up again.

Keys from before Connections (Connect with OpenRouter's key, Shutterstock's connected account) are moved into the table by the plugin's migration (`php craft up`), and their old rows removed.

## Keys never reach us

A key goes only to the service it belongs to: in the check, and in every call afterwards. Ghostwriter has no servers of its own that a key could be sent to.
