# API errors

Every failure under `/api` is an RFC 9457 problem document with content type
`application/problem+json` and at least `type`, `title`, `status` and
`detail` (spec `api-error-format`). The `type` is `/errors/<status>`: a URI
reference that identifies the kind of problem, not a page to fetch — this
file is what it points at.

A client can read `status` for the class of failure and `detail` for what
happened. `detail` is written for a person; do not branch on its wording.

```json
{
  "type": "/errors/404",
  "title": "Not Found",
  "status": 404,
  "detail": "No such link."
}
```

## The catalogue

| `type` | Meaning | Raised by | What a client does |
|---|---|---|---|
| `/errors/400` | The body is not valid JSON, or a **collection's** query parameter carries a value its type or range does not admit — `page=abc`, `isActive=maybe`. A report's parameters are refused with 422 instead. A wrong media type is 415 on the operations API Platform serves, and 404 on `POST /api/v1/auth/token`, which is not one of them. | Any operation with a request body, the paginated collections, and `POST /api/v1/auth/token` for a credential payload missing a member. | Fix the payload or the parameter. Retrying the same request cannot succeed. |
| `/errors/401` | No credential was presented, or it is unknown, expired or revoked; on `POST /api/v1/auth/token` it means the credentials are not those of an account that can sign in. | Every operation except `POST /api/v1/auth/register`. | Obtain a token (`POST /api/v1/auth/token`) or present a valid API key, then retry. A blocked account is refused with 403, not 401. |
| `/errors/403` | The credential is valid but may not do this: another user's resource, or an operation that needs `ROLE_ADMIN`. A blocked account is refused here too, including when it presents correct credentials to `POST /api/v1/auth/token`. | Every authenticated operation, and the token endpoint for a blocked account. | Do not retry with the same credential. |
| `/errors/404` | No resource of that identifier exists, or the caller may not know that it does — another owner's API key answers the same as an unknown one. `POST /api/v1/auth/token` answers this for a body it cannot read as JSON: its authenticator declines the request and no controller runs. | Every item operation, any unknown route under `/api`, and the token endpoint for an unreadable body. | Check the identifier, or send `application/json`. |
| `/errors/405` | The route exists but not for that method. | Any path. | Use a method the document lists for the path. |
| `/errors/406` | The `Accept` header admits none of the media types the operation produces. | Every operation except `POST /api/v1/auth/token`, which answers before content negotiation runs and so never refuses on `Accept`. | Ask for `application/json` — or, for `GET /api/v1/links/{id}/qr`, `image/svg+xml` or `image/png`. |
| `/errors/409` | The account already holds the maximum number of active API keys. Nothing was created. | `POST /api/v1/api-keys`. | Revoke a key (`DELETE /api/v1/api-keys/{id}`) and retry. |
| `/errors/415` | The request body's `Content-Type` is not one the operation accepts. This is about the *media type*, not the bytes: a body announced as `application/json` that is not valid JSON is 400. `PATCH` requires `application/merge-patch+json`; the operations that take a body otherwise accept `application/json`. | Every operation with a request body except `POST /api/v1/auth/token`, which is not an API Platform operation and answers 404 for a body it cannot read. | Send the media type the document lists for the operation's body. |
| `/errors/422` | A value is rejected: a member of a well-formed JSON payload, or a report's query parameter — a malformed bound, an inverted or over-long period, an hourly granularity over too long a period, a limit outside 1–50, a bots flag that is not a boolean. The document carries a `violations` array; each element has `propertyPath` and `message`, and `code` when the constraint provides one. | Every operation that validates a body, and every report operation for its query parameters. | Correct the values the `violations` name. |
| `/errors/429` | A rate limit is exhausted — per credential for the authenticated operations, per client address for `POST /api/v1/auth/*`. | Every operation. | Wait the number of seconds in the `Retry-After` header, then retry. On the authenticated operations `X-RateLimit-Limit` and `X-RateLimit-Remaining` report the window on every response, so a client can slow down before being refused. |
| `/errors/500` | An unexpected failure. Outside `dev` the body carries a generic `detail` and never a stack trace, a file path or an exception message. | Any operation. | Retry a safe request; report the failure if it persists. |

### A validation failure

```json
{
  "type": "/errors/422",
  "title": "Unprocessable Content",
  "status": 422,
  "detail": "targetUrl: The target must be an absolute http(s) URL.",
  "violations": [
    {"propertyPath": "targetUrl", "message": "The target must be an absolute http(s) URL."}
  ]
}
```

### A rate-limit refusal

```
HTTP/1.1 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 30
X-RateLimit-Limit: 600
X-RateLimit-Remaining: 0
```

## Keeping this file honest

`tests/Api/ErrorCatalogueTest.php` enumerates the error types the application
can produce — the statuses the OpenAPI document declares for any operation,
and the statuses `src/Shared/Api/ProblemDetails.php` is called with — and
fails naming any that this table does not list. A new refusal therefore
cannot ship undocumented.

Where the document and this file disagree with the code, the code is right
and the two of them are the defect.
