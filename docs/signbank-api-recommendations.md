# Signbank API — recommendations

A list of API improvements that would make `signbank.cls.ru.nl` (and any
deployment of `Signbank/Global-signbank`) materially safer and more useful
for upstream consumers. Based on hands-on integration work building
`signCollect-v2` against the live API on 2026-05-08.

The two HTTP-500 bugs we already filed a fix for are tracked in
[`docs/signbank-api-500-fixes.patch`](signbank-api-500-fixes.patch). The
items below are larger design / contract questions.

---

## 1. Video upload should return a receipt

### Problem
`POST /dictionary/api_update_gloss/{glossid}/video` currently replies with
just:
```json
{"message": "Uploaded 1 videos to dataset NGT."}
```
There is no way for the client to know:
- which physical file landed on disk,
- whether it overwrote a prior video,
- when the upload was committed,
- what size or checksum the server stored.

In production this means: **videos disappear or silently overwrite each
other and the only forensic artifact is "I uploaded something at some
point."** We've hit this multiple times.

### Proposed response
```json
{
  "status":           "ok",
  "gloss_id":         50412,
  "video_id":         358487,
  "uploaded_at":      "2026-05-08T15:03:17Z",
  "uploaded_by":      "gomer",
  "filename":         "NGT-50412.mp4",
  "stored_path":      "glossvideo/NGT/F/FOO-50412.mp4",
  "size_bytes":       284732,
  "sha256":           "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824",
  "duration_seconds": 1.04,
  "replaced": {
      "previous_sha256":     "9d5ed678fe57bcca610140957afab571…",
      "previous_uploaded_at":"2026-04-12T11:24:09Z",
      "previous_size_bytes": 281992
  }
}
```
The `replaced` block is `null` on a fresh upload, populated when the
write was an overwrite. That single field would have prevented every
"who clobbered which file" incident we've had this year.

### Apply the same shape to
- `POST /dictionary/api_update_gloss/{glossid}/image`
- `POST /dictionary/api_create_gloss_nmevideo/{datasetid}/{glossid}/`
- `PUT  /dictionary/api_update_gloss_nmevideo/{datasetid}/{glossid}/{videoid}/`

---

## 2. Idempotency / overwrite-protection

### Problem
There is no way to say "*only* upload if no current video exists" or
"*only* replace if the current sha256 == X". Two clients racing each
other will silently clobber.

### Proposed
Honor two optional request headers on the video/image endpoints:

| Header | Behavior |
|---|---|
| `If-None-Match: *` | Refuse with 412 if a video already exists for this gloss. |
| `If-Match: <sha256>` | Refuse with 412 if the current video's sha256 doesn't match. |

A 412 should return JSON identifying the existing object so the client
can decide what to do.

---

## 3. Per-gloss media history endpoint

### Problem
There's no way to list a gloss's video upload history — when, by whom,
how big, what checksum. Recovering an accidentally-overwritten video
requires asking the Signbank operator to look at filesystem backups.

### Proposed
```
GET /dictionary/api_gloss_media_history/{datasetid}/{glossid}/
```
Returns an array of past + current video/image versions:
```json
[
  {"kind":"video", "version":3, "uploaded_at":"…", "uploaded_by":"…",
   "sha256":"…", "size_bytes":…, "is_current":true,  "available":true},
  {"kind":"video", "version":2, "uploaded_at":"…", "uploaded_by":"…",
   "sha256":"…", "size_bytes":…, "is_current":false, "available":true},
  {"kind":"video", "version":1, "uploaded_at":"…", "uploaded_by":"…",
   "sha256":"…", "size_bytes":…, "is_current":false, "available":false,
   "available_reason":"pruned 2025-09-01"}
]
```
Even read-only would be a major win. Bonus: a `?restore_version=2`
mutation against the existing update endpoint to rotate back.

---

## 4. Consistent error response shape

### Problem
Three different error shapes are returned by the dictionary/api_* surface
right now — clients have to sniff and branch:

| Endpoint | Error shape |
|---|---|
| `api_create_gloss` | `{"errors": ["msg1", "msg2"], "createstatus": "Failed", "glossid": ""}` (errors is a *list*) |
| `api_update_gloss` | `{"errors": {"FieldA": ["msg"]}, "updatestatus": "Failed", "glossid": "..."}` (errors is a *dict-of-lists*) |
| `api_create_annotated_sentence` | `{"error": "Dataset with given acronym does not exist."}` (singular `error`, plain string) |

### Proposed
One shape, used everywhere:
```json
{
  "ok": false,
  "status_code": 400,
  "errors": [
    {"field": "Senses (Dutch)", "code": "length_mismatch",
     "message": "Sense arrays are not the same length."},
    {"field": null, "code": "permission_denied",
     "message": "No permission to change dataset."}
  ]
}
```
On success:
```json
{ "ok": true, "data": { … endpoint-specific payload … } }
```
This also gives clients a stable `code` to switch on (vs. matching
translated user-facing strings).

---

## 5. Don't render the global navbar+JS+CSS on JSON 500s

### Problem
Today, `dictionary/api_create_gloss/...` returning a 500 sends back
**8 081 bytes of HTML** with the entire Signbank navbar, search bar, and
inline JS. For an `Accept: application/json` client it's noise.

### Proposed
Install a tiny middleware:
```python
def json_500_for_api(get_response):
    def m(req):
        try: return get_response(req)
        except Exception:
            if req.path.startswith('/dictionary/') and 'json' in (req.META.get('HTTP_ACCEPT','') or ''):
                logger.exception("api 500")
                return JsonResponse({"ok": False, "status_code": 500,
                                     "errors": [{"code":"internal","message":"…"}]},
                                    status=500)
            raise
    return m
```
In `DEBUG=True` environments the body should also include the file/line
of the offending frame (Django already does this for HTML — make it
parity for JSON).

---

## 6. Field-name canonicalization on update endpoints

### Problem
`POST /dictionary/api_update_gloss/{datasetid}/{glossid}/nl/` rejects
`{"Annotation ID Gloss (Nederlands)": "X"}` and demands
`{"Annotatie-ID-Glos (Nederlands)": "X"}` — the keys flip with the URL
language. That makes building an idempotent client painful: I have to
keep two mirror payloads of every field name.

### Proposed
Accept both the language-specific *and* the canonical English name on
every variant of the endpoint. The router knows which language path was
requested; the body shouldn't have to.

A `?fields=help` query parameter that lists every accepted key per
endpoint would also be cheap to add and would obsolete most "what's
the field called now" Slack messages.

---

## 7. List/search endpoint scoped to a dataset

### Problem
There's no way to ask "*does Signbank already have an NGT gloss with
this annotation text?*" without downloading the entire ~12 MB
`/dictionary/package/?dataset_name=NGT&since_timestamp=0` ZIP and
grepping it locally (which is exactly what `/web/signbank_data/glosses_transformed.json`
is).

### Proposed
```
GET /dictionary/api_search_glosses/{datasetid}/?annotation=APPLE&lang=nl&limit=20
```
Returning `[{glossid, annotation_dutch, annotation_english, sha256, in_web_dictionary, …}]`.

This collapses the client logic for "is this a duplicate?" /
"do I need to create or update?" from "download a 12 MB ZIP" to one
HTTP call.

---

## 8. Auth scheme clarity

### Problem
The OpenAPI spec (`signbank-api.json`) declares only `bearerAuth`
(`Authorization: Bearer <token>`). On the live server, however,
`X-API-Key: <token>` *also* works for at least `/dictionary/info/`. A
naive reader of the spec would never try X-API-Key, and a naive reader
of the production server logs would never know Bearer is the canonical
scheme.

### Proposed
- Pick one. Bearer is the cleaner choice (matches OpenAPI / RFC 6750);
  the codebase already calls `removeprefix('Bearer')` on it.
- Update the OpenAPI spec on `signbank.github.io/Global-signbank/` so
  the "Authorize" button matches reality.
- Document the actual error code returned for an invalid token
  (currently `200` with `{"errors":["Your Authorization Token does not
  match anything."]}` — should be `401`, especially for write endpoints).

---

## 9. Document required confirmation field on delete endpoints

### Problem
`DELETE /dictionary/api_delete_gloss/{datasetid}/{glossid}/` requires the
body to contain `{"confirmed":"true"}`. There's no hint of this in the
OpenAPI spec — not as a `requestBody` schema, not in the description.
Result: every first-time integrator hits this as a 500 (because the
empty body crashes `json.loads`, see `signbank-api-500-fixes.patch`).

### Proposed
- Document the body in the OpenAPI spec.
- Return 400 with `{"errors":[{"field":"confirmed","code":"required",…}]}`
  on a missing confirmation, *not* 500.
- Optionally: replace the body field with a header (`X-Confirm-Delete: 1`)
  so the body can stay empty.

---

## 10. Standardised timestamp format on all returned datetimes

`/dictionary/info/` returns `["NGT", …]` — bare strings.
`/dictionary/get_gloss_data/.../{glossid}/` returns dates like
`"creationDate": "2024-07-09"` (date only) and others mixing in
ISO-8601 strings. Some endpoints omit timezone entirely.

### Proposed
Always RFC 3339 / ISO-8601 with explicit `Z` UTC offset, e.g.
`"2024-07-09T11:24:09Z"`. Document the format in one place.

---

## 11. Webhook for video processing pipeline

### Problem
After uploading a raw video, there are background steps (transcoding,
thumbnailing, EAF extraction). Today the only way to know they finished
is to poll `get_gloss_data` until fields populate.

### Proposed
A simple subscribe endpoint:
```
POST /dictionary/api_subscribe/{datasetid}/?event=gloss.video.processed
{ "url": "https://signcollect.nl/menu_beta/php_api/signbank_callback.php",
  "secret": "..." }
```
Signbank then POSTs `{event, gloss_id, video_id, sha256, …}` to that URL
with `X-Signbank-Signature: hmac-sha256=…`. Not necessary for v1, but
it's the cleanest fix for "is the postprocess pipeline done yet."

---

## Priority for our integration

If only a few of these are picked up, the order that helps us most is:

1. **§1 + §2** — *upload receipts and idempotency.* Solves the immediate
   "videos disappear / overwrite" pain.
2. **§4** — *consistent error shape.* Removes a class of client bugs.
3. **§9** — *document the confirmation body on delete* (and stop 500s
   on missing body — covered by `signbank-api-500-fixes.patch`).
4. **§7** — *gloss search endpoint.* Lets us drop the 12 MB ZIP cache.
5. The rest are quality-of-life.

---

## How we found these

While building [`signCollect-v2`](https://github.com/rem0g/signCollect-v2)
(`menu_beta` interface + `signbank_sync` server-side proxy) we hit each
of the above as a real blocker over the course of a single day. Happy
to provide repro requests / response captures for any of them.
