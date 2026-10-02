# Troubleshooting

## "No API key yet"

The chosen provider's key isn't in the environment. Add it to `.env` (see [API keys](api-keys.md)) and reload the page. On a server, check the variable is set where your host keeps environment variables, then redeploy or restart PHP if your host needs it.

## Nothing happens after asking for something

Model calls run in Craft's queue.

- **Using a queue worker?** If `runQueueAutomatically` is `false`, make sure the worker is running (`php craft queue/listen`, or your host's daemon).
- **Look at the queue.** **Utilities → Queue Manager** shows the job, and any error.
- **Hidden browser tab.** Craft only runs the queue from the control panel while a page is open. Keep the tab open, or use a worker.

## A model call times out

Long drafts can take several minutes on the larger models. Raise `timeout` in [`config/ghostwriter.php`](configuration.md), for example to `600`. If you run a queue worker with its own time limit, raise that too.

## "This stopped before it finished"

The job doing the work was stopped before it could report back: usually a time limit on the server (the queue's, PHP's `max_execution_time`, or a web request running the queue), or a restart. Anything still marked as working long after a job's time limit is reported this way, so you can try again. If it keeps happening, raise the limits as above, or run a queue worker.

## Busy, overloaded or rate limited

When a provider is rate limited, overloaded or briefly down, Ghostwriter tries the request again, up to three times, waiting as long as the provider asks or a little longer each time. Only if every try fails is the error shown. Wait a minute and try again, or raise your plan's rate limits with the provider.

## "The answer ran past its length limit and was cut off"

A reply that stops at the model's length limit is asked for once more with twice the room. If it is cut off again, the error is shown rather than half a draft. Ask for something shorter, or split the piece in two.

## "The analysis came back in a form that could not be read. Try again."

When learning a kind, the model's answer couldn't be read, even after one automatic retry. Try again; it usually works the second time. If it keeps happening for one section, teach the kind by hand and choose fewer, more typical example entries.

## The draft is put in, but something is missing

Read the notes above the form after **Use this draft**. They list:

- blocks the draft used that this section doesn't allow (left out)
- choices, related entries and links still for you to set
- links pointing to `https://example.com` for now
- image fields with a striped placeholder

## No Ghostwriter button on an image field

The button only appears:

- in sections Ghostwriter writes for
- for people with **Use Ghostwriter** who can save the entry
- on fields that accept images
- on fields with a valid upload location (check **Default Upload Location** in the field's settings)

## No "Make one" option

Making images needs an OpenAI or Gemini key (Gemini needs billing turned on for image models). OpenAI may ask you to verify your organisation first.

## Photo search finds little

- Add an Unsplash, Pexels or Pixabay key: Openverse on its own has a smaller, more archival collection.
- Type simple, concrete searches: "stone wall garden", not "sustainable landscaping".
- Unsplash's demo mode allows 50 searches an hour.

## Gemini: "quota exceeded" or "model not found" on the free tier

The free tier covers Flash models only, with daily limits. Ghostwriter's default Gemini model, `gemini-3.8-flash`, is on the free tier: leave **Model** blank, or set it to another Flash model, or turn on billing. See [API keys](api-keys.md#google-gemini).

## Seeing what went wrong

Ghostwriter logs errors to Craft's logs under the `ghostwriter` category: `storage/logs/web.log` and `storage/logs/queue.log`.
